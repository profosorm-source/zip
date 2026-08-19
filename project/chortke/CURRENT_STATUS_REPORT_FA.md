# گزارش وضعیت فعلی بازطراحی مالی و کیفیت پروژه

**تاریخ:** ۲۴ ژوئیهٔ ۲۰۲۶  
**دامنه:** سورس فعلی workspace، DB محلی ایزوله، migrationهای موجود و testهای اجراشده.  
**قید مهم:** این گزارش جایگزین audit دادهٔ production نیست؛ هیچ credential یا دادهٔ production در دسترس/استفاده نشده است.

## خلاصهٔ مدیریتی

| حوزه | وضعیت | توضیح |
|---|---|---|
| Wallet locked-fund primitives | سبز | `releaseLockedFunds` و `spendLockedFunds` از هم تفکیک شدند |
| Partial escrow settlement | سبز | anti-money-creation، idempotency و payout واقعی تست شده |
| Custom Deal | سبز | hold/release/refund اتمیک و مالکیت buyer/seller اصلاح شده |
| SocialTask escrow | سبز | buyer/seller ownership و Saga context bug اصلاح شده |
| Influencer/Vitrine commission | سبز برای flow جدید | seller payout و platform revenue ledger شده‌اند |
| Dispute split | سبز | refund+payout واقعی و atomic شده است |
| Refund contract | زرد-سبز | contract مرکزی ایجاد و callerهای اصلی migrate شدند؛ legacy migration باقی است |
| Direct SQL wallet updates در AdsBudgetSettlement | سبز در مسیرهای active | reward به WalletService منتقل و architecture guard اضافه شد |
| Escrow async listener | سبز | فقط notification/audit outbox؛ هیچ payout async ندارد |
| Legacy data | زرد | فقط audit/migration plan؛ هنوز روی production اجرا نشده |
| Full production readiness | زرد | نیازمند audit read-only، row approval، canary و reconciliation production |

## اصلاح‌های تکمیل‌شده

### Wallet و Ledger

- `spendLockedFunds()` اضافه شد: `locked -= amount` بدون credit کردن balance مالک.
- `releaseLockedFunds()` فقط refund است: `locked -= amount` و `balance += amount`.
- lifecycle transactionهای hold تکمیل شد:
  - `finalizeLockedSpend()` برای settlement؛
  - `finalizeLockedRefund()` برای refund.
- ledger lifecycle استاندارد شد:

```text
wallet:user -> locked_reserve
locked_reserve -> withdrawal_payout
locked_reserve -> wallet:user
locked_reserve -> escrow_payout -> wallet:seller
locked_reserve -> platform_revenue
```

- duplicate detection `LedgerEntry` اصلاح شد: leg کاملاً تکراری رد می‌شود، اما debit/credit مخالف روی همان account در lifecycle معتبر مجاز است.

### Escrow و Financial flows

- `partialRelease()` از refund primitive استفاده نمی‌کند و payout تکراری ندارد.
- `resolveDisputePartial()` اکنون wallet refund و seller payout واقعی انجام می‌دهد.
- CustomDeal به financial flow واقعی منتقل شد؛ row escrow بدون hold ایجاد نمی‌شود.
- SocialTask buyer/seller inversion و Saga context bug رفع شد.
- Influencer و Vitrine commission settlement به payout/fee ledger صریح منتقل شدند.
- Vitrine جدید از `withdraw + vitrine_listing + confirmHold` استفاده می‌کند، نه `pay + vitrine_purchase`.

### Refund و Async

- `FinancialEscrowService::refundEscrowToBuyer()` contract مرکزی refund است.
- wrapperهای CustomDeal/SocialTask/Influencer/Vitrine به contract مرکزی delegate می‌کنند.
- Vitrine cancel/expiry/dispute jobها به canonical order type/refund flow منتقل شدند.
- `EscrowListener` دیگر wallet deposit یا payout outbox نمی‌سازد؛ تنها audit و `notification.requested` outbox ثبت می‌کند.

### Ads و Lottery

- `AdsBudgetSettlementService` برای FinancialEscrowService و WalletService dependency اجباری دارد.
- AdTube reward از direct SQL به `WalletService::deposit()` منتقل شد.
- `ensureWallet()` از WalletService استفاده می‌کند، نه SQL مستقیم.
- Lottery refund به جای escrow فرضی مشترک، hold واقعی هر participant را با `cancelWithdrawal()` refund می‌کند.
- AdSystemManager compensation مالی دستی حذف شد؛ outer transaction root مسئول rollback است.

## تست و شواهد اجراشده

| آزمون | آخرین نتیجهٔ معتبر |
|---|---|
| Financial integration با MariaDB/Redis واقعی | **10 tests / 144 assertions / PASS** |
| Financial architecture guard | **18 tests / 37 assertions / PASS** |
| EventListenersBehaviorTest | **14 tests / 14 assertions / PASS** |
| PHPStan Level 9 configured | PASS در آخرین اجرای runtime آماده |
| DB محلی read-only legacy audit | 0 finding در DB تازه/تستی |
| DB محلی reconciliation | 0 legacy Vitrine، 0 pending hold، 0 ledger imbalance |

### وضعیت Full PHPUnit

آخرین اجرای کامل پیش از آخرین اصلاح listener، دو failure داشت:

1. `.env` در source/archive وجود دارد؛
2. EscrowListener outbox contract.

مورد دوم بعداً اصلاح و test اختصاصی آن سبز شد. اجرای دوبارهٔ full suite پس از آخرین تغییر باید در runtime دارای PHP/DB/Redis انجام شود؛ محیط tool در turn اخیر reset شد و PHP در آن لحظه موجود نبود، بنابراین نتیجهٔ جدید full suite جعل/حدس زده نمی‌شود.

## موارد باقی‌مانده

### P0 / Production gate

1. اجرای `legacy_financial_audit.sql` روی read replica یا snapshot production.
2. review و approval برای هر legacy row.
3. migration جبرانی فقط برای rowهای تأییدشده.
4. reconciliation بعد از canary و هر batch.
5. backup/restore rehearsal و production monitoring.

### P1 / معماری و بدهی فنی مالی

1. **Core state-only API**: `EscrowService::releaseFunds()` و `refundFunds()` هنوز public هستند. قراردادشان باید با `@internal`/documentation و guard قوی‌تر محدود شود تا caller جدید فقط از FinancialEscrowService استفاده کند.
2. **Legacy `vitrine_purchase` rows**: کد جدید آن‌ها را تولید نمی‌کند؛ conversion خودکار ممنوع است تا audit production انجام شود.
3. **Legacy campaign بدون escrow**: direct refund خودکار fail-safe شده/باید fail-safe بماند؛ فقط migration تأییدشده مجاز است.
4. **`refundHeldBudget` lifecycle**: باید برای hold transactionهای legacy mapping کامل و deterministic داشته باشد تا pending transaction قدیمی باقی نماند.
5. **Ad/SEO legacy paths**: نیازمند audit row-level و migration به Wallet/Financial primitives هستند.

### P2 / Quality

1. PHPStan strict بدون ignore قبلاً هزاران finding آشکار کرد؛ configured PHPStan سبز است اما strict debt هنوز جداگانه باید به‌صورت batchهای type-contract اصلاح شود.
2. `.env` باید از archive/repository/history خارج و تمام secretهای احتمالی rotate شوند.

## فایل‌های عملیاتی آماده

```text
CURRENT_STATUS_REPORT_FA.md
FINANCIAL_CORE_AUDIT_2026-07-24.md
REFUND_CONTRACT_AUDIT_2026-07-24.md
legacy_financial_audit.sql
production_financial_reconciliation.sql
LEGACY_FINANCIAL_MIGRATION_PLAN.md
PRODUCTION_FINANCIAL_CUTOVER_RUNBOOK.md
```

## تصمیم پیشنهادی

کد application-level برای flowهای جدید به مرحلهٔ تثبیت رسیده است. از این نقطه، هر تغییر مالی جدید باید تنها پس از audit دادهٔ واقعی، approval row-level، canary و reconciliation production انجام شود.
