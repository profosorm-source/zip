# ممیزی عمیق هستهٔ مالی و Escrow — فاز کشف و اثبات

**تاریخ:** ۲۴ ژوئیهٔ ۲۰۲۶  
**دامنه:** Wallet، Transactions، Ledger، EscrowService، FinancialEscrowService، payout/refund/partial settlement، و call siteهای مستقیم.  
**اصل ایمنی:** تا زمانی که invariantهای مالی و ownership هر flow اثبات نشده‌اند، هیچ «فیکس تک‌خطی» برای Escrow به production پیشنهاد نمی‌شود.

## وضعیت فاز فعلی

- فیکس مستقل `SecurityHeadersMiddleware` با test و PHPStan Strict کامل شد.
- برای finance هنوز تغییر رفتاری اعمال نشده است؛ ابتدا reproduction و نقشهٔ جریان‌ها ساخته شد.
- DB محلی ایزوله با MariaDB/Redis و ۱۲۲ migration برای reproduction آماده شد.

---

## شواهد runtime قطعی

### FIN-P0-01 — Full release واقعاً پول را منتقل نمی‌کند

یک scenario واقعی روی DB محلی، داخل outer transaction و با rollback کامل اجرا شد:

1. balance خریدار: `50,000,000`؛ فروشنده: `0`؛
2. `wallet->withdraw(20,000)` باعث شد balance خریدار `49,980,000` و `locked=20,000` شود؛
3. escrow `in_escrow` با buyer=1 و seller=2 ساخته شد؛
4. `EscrowService::releaseFunds()` اجرا شد و `ok=true` برگرداند؛
5. پس از release:
   - balance خریدار: `49,980,000`، locked: `20,000`؛
   - balance فروشنده: `0`؛
   - یعنی **هیچ wallet mutation رخ نداده است**، با وجود success/status/ledger.

Artifact قابل تکرار:

- script: `test-results/audit/repro-current-escrow-full-release.php`
- output: `test-results/audit/repro-current-escrow-full-release.json`

**ریشه:** `EscrowService::releaseFunds()` فقط status، audit و ledger logical ثبت می‌کند؛ `$walletService` نیز inject شده ولی assign نشده است.

---

## یافته‌های تأییدشده

| ID | شدت | یافته | شواهد |
|---|---|---|---|
| FIN-P0-01 | P0 | Full release بدون debit locked خریدار و بدون credit فروشنده | reproduction واقعی بالا؛ `EscrowService::releaseFunds` |
| FIN-P0-02 | P0 | WalletService در EscrowService assign نشده است | constructor پارامتر را می‌گیرد ولی `$this->walletService = $walletService` ندارد |
| FIN-P0-03 | P0، latent | `partialRelease()` در شاخهٔ WalletService از `releaseLockedFunds` سپس `deposit` استفاده می‌کند | اولی locked→buyer balance است؛ دومی credit seller؛ پس بعد از اصلاح FIN-P0-02، اگر این دو باهم بمانند خلق پول رخ می‌دهد |
| FIN-P0-04 | P0 | `FinancialEscrowService::consumeHeldBudget()` به‌جای مصرف locked، `releaseLockedFunds()` می‌زند | نام/هدف consume است، اما این primitive مبلغ را به balance خریدار refund می‌کند |
| FIN-P0-05 | P0 | `holdSocialTaskFunds()` buyer/seller escrow را معکوس می‌سازد | wallet advertiser را lock می‌کند ولی `holdFunds(executorId, advertiserId, ...)` را صدا می‌زند؛ refund/release روی owner اشتباه اجرا می‌شود |
| FIN-P0-06 | P0 | `resolveDisputePartial()` status و ledger را تغییر می‌دهد ولی wallet locked/payout/refund را mutation نمی‌کند | عدم تطابق DB state و موجودی واقعی؛ payer/payee پول دریافت/پرداخت نمی‌کنند |
| FIN-P1-01 | P1 | partial release idempotency ندارد | تکرار همان فراخوان می‌تواند چند payout ایجاد کند؛ signature کلید idempotency ندارد |
| FIN-P1-02 | P1 | نتیجهٔ `walletService->deposit()` در partial release بررسی نمی‌شود | status/locked ممکن است تغییر کند، اما seller credit ناموفق بماند |
| FIN-P1-03 | P1 | قرارداد refund یکسان نیست | Core `refundFunds` فقط state/ledger را تغییر می‌دهد، بعضی callerها جداگانه wallet refund می‌کنند و برخی مسیرهای مستقیم باید موردبه‌مورد ممیزی شوند |
| FIN-P1-04 | P1 | event payload ناسازگار است | producer `seller_id` یا event `user_id` می‌فرستد ولی `EscrowListener` دنبال `recipient_id` می‌گردد؛ asynchronous payout قابل اتکا نیست |
| FIN-P1-05 | P1 | wrapperهای FinancialEscrowService بعد از core release خودشان deposit می‌کنند | اگر core release درست شود و wrapperها همزمان تغییر نکنند، double payout رخ می‌دهد |
| FIN-P1-06 | P1 | Wallet `processWithdraw` balance→locked را بدون ledger hold entry ثبت می‌کند | reconciliation مسیر lock → spend/refund/withdrawal را قابل اتکا نمی‌بیند |
| FIN-P1-07 | P1 | mutationهای مستقیم `wallets` خارج از WalletMutationService وجود دارند | `AdsBudgetSettlementService` و Escrow fallback؛ bypass از idempotency/ledger/lock contract |

## قرارداد مالی هدف (Target Invariants)

برای هر currency و هر settlement جدید باید برقرار باشد:

1. `release`: مقدار buyer.balance تغییر نمی‌کند، buyer.locked دقیقاً به‌اندازهٔ amount کم می‌شود، seller.balance دقیقاً amount زیاد می‌شود.
2. `partial release`: همان invariant برای amount جزئی برقرار است و escrow remaining دقیقاً کم می‌شود.
3. `refund`: buyer.locked کم و buyer.balance همان مقدار زیاد می‌شود؛ seller تغییری نمی‌کند.
4. retry با همان idempotency key هیچ balance/locked/ledger جدیدی ایجاد نمی‌کند.
5. هر release/refund/dispute settlement در تمام نقاط خطا atomic است: wallet، escrow status، transaction و ledger همگی commit یا همگی rollback.
6. برای هر transaction ledger، `SUM(debit) = SUM(credit)`؛ برای زنجیرهٔ escrow account نیز هیچ ماندهٔ phantom بعد از settlement کامل باقی نمی‌ماند.
7. owner escrow باید همان user باشد که balance او در hold قفل شده است.

## طراحی اصلاح پیشنهادی — بدون تغییر نصفه

### 1. Primitiveهای Wallet

- `releaseLockedFunds()` فقط برای **refund** باقی می‌ماند: `locked -= amount`, `balance += amount`.
- primitive جدید `spendLockedFunds()` اضافه می‌شود: `locked -= amount` و **بدون** credit buyer.
- `spendLockedFunds()` باید row lock، transaction، idempotency key، transaction record و ledger داشته باشد.
- از account flow زیر استفاده می‌شود:

```text
wallet:buyer -> locked_reserve        (hold)
locked_reserve -> escrow_payout       (spend)
escrow_payout -> wallet:seller        (payout)
locked_reserve -> wallet:buyer        (refund)
```

### 2. Escrow settlement

- `releaseFunds()` و `partialRelease()` در یک DB transaction:
  1. lock escrow + wallet source؛
  2. validate amount/status/parties;
  3. `spendLockedFunds` buyer؛
  4. payout seller با idempotency key مستقل؛
  5. update escrow state/remaining/audit; 
  6. transactional outbox بعد از commit.
- `partialRelease()` کلید idempotency اختیاری می‌گیرد؛ همهٔ callerهای job/controller key پایدار می‌فرستند.
- ledger فعلی duplicate در `EscrowService` با ledger mutationهای واقعی جایگزین می‌شود؛ event نباید payout دوم ایجاد کند.

### 3. Contract یکپارچهٔ callerها

- قبل از فعال‌سازی core settlement، تمام wrapperهای `FinancialEscrowService`، Vitrine jobs و controllerها تغییر می‌کنند تا پس از core release/deposit مجدد نزنند.
- social-task hold ownership اصلاح می‌شود. دادهٔ legacy خودکار rewrite نمی‌شود؛ ابتدا audit query و migration/manual remediation با تأیید مالک داده انجام می‌شود.
- `consumeHeldBudget()` از `spendLockedFunds()` استفاده می‌کند؛ `refundHeldBudget()` از `releaseLockedFunds()`.
- `resolveDisputePartial()` از همان settlement primitives برای refund + payout استفاده می‌کند، نه فقط status/ledger.

## تست‌های اجباری پیش از merge

1. full release با DB/Redis واقعی؛
2. partial release و remaining balance؛
3. refund؛
4. split dispute: refund+payout در یک transaction؛
5. duplicate request و duplicate outbox delivery؛
6. دو worker هم‌زمان روی یک escrow؛
7. insufficient locked balance؛
8. seller wallet ایجادنشده؛
9. failure مصنوعی در payout/ledger/outbox و rollback کامل؛
10. SQL reconciliation برای wallet/locked/escrow/ledger؛
11. regression برای social-task ownership؛
12. scanner برای ممنوعیت SQL مستقیم `UPDATE wallets` خارج از allowlist core.

## اجرای مرحلهٔ ۱ — Wallet settlement primitive ✅

برای جلوگیری از patch خطرناک در Escrow، primitive پایه ابتدا به‌صورت جداگانه پیاده شد:

- `WalletServiceInterface::spendLockedFunds()` اضافه شد.
- `WalletService` آن را از مسیر lock + transaction + idempotency استاندارد اجرا می‌کند.
- `WalletMutationService::spendLockedFunds()` فقط `locked -= amount` می‌کند؛ balance مبدا را credit نمی‌کند.
- حساب‌های دفتر کل آن `locked_reserve -> escrow_payout` هستند.
- فقط `escrow_payout` و `platform_revenue` به‌عنوان account تسویه مجازند؛ metadata نمی‌تواند account دلخواه inject کند.
- `releaseLockedFunds()` صریحاً primitive refund باقی مانده است.

### تست واقعی MariaDB

تست جدید `tests/Integration/Financial/WalletLockedFundsInvariantTest.php` با outer transaction و rollback اجرا شد:

- hold مبلغ ۲۰٬۰۰۰؛
- spend مبلغ ۲۰٬۰۰۰؛
- balance خریدار بعد از hold و spend ثابت ماند؛
- locked از ۲۰٬۰۰۰ به صفر رسید؛
- دو ledger leg درست `locked_reserve` و `escrow_payout` ساخته شد؛
- replay با همان idempotency key transaction دوم یا debit دوم نساخت.

**نتیجه:** ۱ تست / ۱۹ assertion / PASS.

این primitive هنوز به `EscrowService::releaseFunds()` وصل نشده است؛ این عمدی است. اتصال آن پیش از migration callerهای دارای commission می‌تواند double payout ایجاد کند.

## اجرای مرحلهٔ ۲ — Partial settlement و مصرف budget ✅

### Partial release

`EscrowService::partialRelease()` بازنویسی شد:

- WalletService اکنون واقعاً assign می‌شود، اما مسیر قدیمیِ خطرناک `releaseLockedFunds + deposit` حذف شد.
- fallback SQL مستقیم حذف شد؛ نبود WalletService اکنون fail-closed است.
- هر settlement در یک DB transaction شامل lock escrow، `spendLockedFunds`، payout seller، update escrow/audit/outbox است.
- optional idempotency key اضافه شد؛ برای spend و payout دو کلید مستقل مشتق می‌شود.
- نتیجهٔ payout بررسی می‌شود؛ در failure کل transaction rollback می‌شود.

تست واقعی `EscrowPartialSettlementInvariantTest` ابتدا با typed-property failure قرمز شد و بعد از فیکس:

- partial 8k از escrow 20k: buyer balance ثابت، locked=12k، seller=8k؛
- replay همان key: هیچ payout یا debit دوم ندارد؛
- final 12k: buyer locked=0، seller=20k، escrow=`released` و amount=0؛
- ledgerهای تولیدشده balanced هستند.

**نتیجه: ۱ تست / ۳۵ assertion / PASS.**

### Consume held budget

`FinancialEscrowService::consumeHeldBudget()` از refund primitive به `spendLockedFunds()` منتقل شد:

- پیش از فیکس، مصرف 8k باعث balance خریدار `49,980,000 → 49,988,000` می‌شد؛ یعنی بودجه مصرف‌شده به خریدار برمی‌گشت.
- اکنون balance ثابت می‌ماند، locked و escrow amount کم می‌شوند و target ledger برابر `platform_revenue` است.
- `AdsBudgetSettlementService` اکنون idempotency key delivery را به این flow پاس می‌دهد.

**تست: ۱ تست / ۹ assertion / PASS.**

## اجرای مرحلهٔ ۳ — مالکیت و settlement Social Task ✅

در SocialTask دو باگ مستقل پیدا و اصلاح شد:

1. hold قبلاً advertiser را lock می‌کرد ولی escrow را با executor به‌عنوان buyer ثبت می‌کرد. اکنون `buyer=advertiser` و `seller=executor` است.
2. Saga این flow scalar/object را به step بعدی پاس می‌داد، در حالی که SagaOrchestrator فقط resultهای array را به context merge می‌کند. نتیجه release با `array` به جای escrow ID و refund با `array->id` شکست می‌خورد.

release/refund SocialTask به sequence صریح و atomic تبدیل شد:

- release: validate ownership → core state transition → complete advertiser hold → credit executor؛
- refund: validate ownership → core refund state → cancel advertiser hold؛
- refund دیگر deposit جداگانه ندارد، بنابراین double credit رخ نمی‌دهد.

**تست واقعی `FinancialSocialTaskEscrowInvariantTest`: ۲ تست / ۱۸ assertion / PASS.**

## اجرای مرحلهٔ ۴ — Custom Deal Generic Flow ✅

`EscrowController` برای `custom_deal` پیش‌تر فقط row escrow می‌ساخت، هیچ wallet hold نداشت و release هم actor اشتباه را به model می‌داد. این مسیر به‌صورت کامل به `FinancialEscrowService` منتقل شد:

- `holdCustomDealFunds`: validate balance → create escrow → wallet hold → confirm escrow، همگی در یک transaction؛
- `releaseCustomDealFunds`: verify buyer ownership → state transition → complete buyer hold → payout seller؛
- `refundCustomDealFunds`: verify buyer ownership → state refund → cancel original buyer hold؛
- controller دیگر مستقیم به wallet deposit یا `EscrowService::releaseFunds` تکیه نمی‌کند.

**تست واقعی `FinancialCustomDealEscrowInvariantTest`: ۲ تست / ۲۹ assertion / PASS.**

## وضعیت تست‌های مالی جدید

اجرای کامل directory `tests/Integration/Financial` با MariaDB/Redis واقعی:

```text
7 tests / 110 assertions / PASS
```

همهٔ fixtureها در outer transaction اجرا و rollback می‌شوند؛ هیچ رکورد مالی تستی باقی نمی‌ماند. برای جلوگیری از آلودگی singleton container توسط mockهای سایر testها، این integration testها در process مستقل اجرا می‌شوند.

### Regression suite پس از مرحلهٔ ۴

```text
PHPUnit: 2222 tests / 4367 assertions
فقط 1 failure شناخته‌شده: وجود .env داخل سورس/آرشیو
5 skip محیطی: Redis-unavailable branch و HTTP health server
PHPStan configured Level 9: exit 0
```

## اجرای مرحلهٔ ۵ — Influencer Commission Settlement ✅

مسیر Influencer پیش‌تر full hold را با `completeWithdrawal` مصرف و سهم فروشنده را با deposit خارجی ثبت می‌کرد؛ سهم پلتفرم هیچ entry صریحی در ledger نداشت.

اکنون:

- سهم فروشنده از locked خریدار به `escrow_payout` منتقل و سپس به wallet فروشنده credit می‌شود؛
- commission باقی‌مانده از locked خریدار مستقیم به `platform_revenue` منتقل می‌شود؛
- transaction hold اولیه بدون deduct دوباره با `finalizeLockedSpend()` completed می‌شود؛
- payout و platform fee هرکدام idempotency key مستقل دارند؛
- retry نمی‌تواند locked balance را دوباره مصرف کند.

تست واقعی `InfluencerEscrowCommissionInvariantTest`:

```text
buyer hold: 20,000
seller payout: 15,000
platform revenue: 5,000
buyer locked after settlement: 0
hold transaction: completed
```

**نتیجه: ۱ test / ۱۲ assertion / PASS.**

## اجرای مرحلهٔ ۶ — Vitrine Commission Settlement ✅

Vitrine نیز از الگوی قدیمی `completeWithdrawal + deposit خارجی` به settlement صریح منتقل شد:

- سهم خالص فروشنده: `locked_reserve -> escrow_payout -> wallet:seller`؛
- commission ویترین: `locked_reserve -> platform_revenue`؛
- hold اولیه بدون deduction دوباره completed می‌شود؛
- commission از `vitrine_commission_percent` خوانده می‌شود و هم payout و هم fee idempotent هستند.

تست واقعی `VitrineEscrowCommissionInvariantTest` با ۲۰ USDT hold، commission پیکربندی‌شده و ledger clearing اجرا شد:

**نتیجه: ۱ test / ۱۲ assertion / PASS.**

## اجرای مرحلهٔ ۷ — Atomic Dispute Split ✅

`EscrowService::resolveDisputePartial()` پیش‌تر فقط escrow status و ledger صوری را عوض می‌کرد؛ هیچ refund یا payout واقعی به walletها انجام نمی‌شد.

اکنون قرارداد اجباری است:

```text
refund_amount + release_amount == escrow.amount
```

و در یک transaction:

- refund: `locked_reserve -> wallet:buyer`؛
- seller payout: `locked_reserve -> escrow_payout -> wallet:seller`؛
- escrow amount صفر و status نهایی `released` یا `refunded`؛
- idempotency keyهای مجزا برای refund، spend و payout؛
- owner/seller/status validation و outbox state event.

تست واقعی `EscrowDisputeSplitInvariantTest`:

```text
hold 20,000
buyer refund 5,000
seller payout 15,000
buyer locked = 0
escrow amount = 0
```

**نتیجه: ۱ test / ۱۰ assertion / PASS.**

## وضعیت integration suite

```text
10 tests / 144 assertions / PASS
PHPStan Level 9 configured: PASS
```

## تصمیم اجرایی ادامه

اصلاح finance به چند PR کوچک ولی atomic تقسیم می‌شود:

1. Wallet primitives + unit/integration invariant tests **(انجام شد)**؛
2. Partial settlement و budget consumption **(انجام شد)**؛
3. SocialTask ownership/release/refund **(انجام شد)**؛
4. Generic custom-deal، Influencer/Vitrine commission settlement و dispute split؛
5. refund contract و حذف mutationهای مستقیم wallet؛
6. reconciliation/observability + legacy-data audit.

هیچ PR در میانه نباید injection `walletService` را به‌تنهایی فعال کند.
