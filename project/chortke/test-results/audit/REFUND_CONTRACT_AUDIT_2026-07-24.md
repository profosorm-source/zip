# ممیزی عمیق Refund Contract — پیش از یکپارچه‌سازی

## نتیجهٔ کلیدی

در وضعیت فعلی `EscrowService::refundFunds()` یک **state transition primitive** است، نه wallet refund primitive: status/audit/outbox/ledger منطقی را تغییر می‌دهد، اما `locked -> balance` انجام نمی‌دهد. این قرارداد در بعضی callerها درست تکمیل شده و در چند caller دیگر ناقص یا حتی خطرناک است. بنابراین تبدیل کورکورانهٔ Core refund به wallet refund باعث **double credit** در FinancialEscrowService می‌شود.

## قرارداد هدف

هیچ controller/job/service نباید مستقیم `EscrowService::refundFunds()` را برای refund مالی فراخوانی کند. دو لایهٔ روشن لازم است:

1. `markRefunded` داخلی/state-only، فقط برای orchestrationهای atomic؛
2. `refundEscrowToBuyer` عمومی/financial که در یک transaction انجام دهد:
   - lock escrow و verify buyer/owner/status؛
   - `releaseLockedFunds` با idempotency؛
   - finalise/cancel hold transaction مناسب؛
   - escrow status/audit/outbox؛
   - return یک contract واحد: `ok`, `amount`, `currency`, `transaction_id`, `idempotent`.

## یافته‌های تأییدشده

| ID | شدت | مسیر | مشکل |
|---|---|---|---|
| REF-P0-01 | P0 | `VitrineService::cancelListing` | order type `vitrine_purchase` را می‌خواند، در حالی‌که Financial flow از `vitrine_listing` استفاده می‌کند؛ سپس خروجی `success` را بررسی می‌کند ولی Core `ok` برمی‌گرداند. ممکن است status/hold ناسازگار بماند. |
| REF-P0-02 | P0 | `AdSystemManager` compensation | ابتدا `deposit` مستقیم پس از wallet hold انجام می‌دهد، سپس Core state-only refund را صدا می‌زند. این مسیر می‌تواند balance را credit و locked را باقی بگذارد؛ compensation financial-safe نیست. |
| REF-P0-03 | P0 | direct Vitrine/Jobs/Lottery callers | چند caller Core refund را بدون settlement wallet یا contract بررسی‌شده صدا می‌زنند؛ هرکدام باید به API مالی واحد migrate شوند. |
| REF-P1-01 | P1 | `FinancialEscrowService` | Social/Influencer/Vitrine/CustomDeal هرکدام refund را با الگوی جداگانه انجام می‌دهند؛ contractهای return متفاوت و بعضاً Saga compensationهای wallet دارند. |
| REF-P1-02 | P1 | Ads/SEO fallback | mutation مستقیم `wallets` در fallbackها idempotency، ledger و standard wallet lock را bypass می‌کند. |
| REF-P1-03 | P1 | `refundHeldBudget` | state + wallet refund درست‌تر است، اما idempotency explicit و lifecycle hold transaction را یکدست نمی‌کند. |
| REF-P2-01 | P2 | Core logical ledger | Core refund یک ledger logical ثبت می‌کند در حالی‌که caller ممکن است ledger wallet جدا بسازد؛ reconciliation باید ownership ledger entry را یکپارچه کند. |

## طبقه‌بندی callerها

### مسیرهایی که در مرحله‌های قبل اصلاح شده‌اند

- Custom deal: hold cancel در یک transaction؛
- Social task: hold cancel در یک transaction؛
- Influencer refund: cancel buyer hold دارد، اما باید به contract واحد منتقل شود؛
- Vitrine refund FinancialEscrow: cancel hold دارد، اما باید به contract واحد منتقل شود؛
- budget refund: `refundHeldBudget` از `releaseLockedFunds` استفاده می‌کند.

### مسیرهایی که نباید مستقیم بمانند

- `VitrineService::cancelListing`؛
- `AdSystemManager` saga compensation / cancel ad؛
- Vitrine jobs با Core refund؛
- Lottery cancellation با Core refund؛
- هر مسیر legacy direct-SQL wallet fallback.

## اصلاح ریشه‌ای اعمال‌شده در فایل موجود

بدون ایجاد service/file production جدید، `FinancialEscrowService.php` به مرز واحد refund تبدیل شد:

- `refundEscrowToBuyer()` تنها orchestration مالی refund است؛
- `refundOrderEscrow()` private helper برای lookup سفارش است؛
- wrapperهای موجود زیر اکنون delegate می‌کنند و منطق wallet/refund تکراری ندارند:
  - `refundCustomDealFunds()`
  - `refundSocialTaskFunds()`
  - `refundInfluencerOrderFunds()`
  - `refundVitrineFunds()`
- `VitrineService::cancelListing` به contract جدید منتقل و order type/return-contract آن اصلاح شد.
- `finalizeLockedRefund()` به WalletMutationService اضافه شد تا پس از releaseLockedFunds، hold بدون unlock دوباره به cancelled برود.

اعتبارسنجی پس از refactor:

```text
Financial integration: 10 tests / 144 assertions / PASS
PHPStan Level 9 configured: PASS
```

## برنامهٔ ایمن اجرا

1. ابتدا test matrix واقعی برای هر caller ایجاد می‌شود؛ state refund بدون wallet refund باید قرمز شود.
2. `FinancialEscrowService::refundEscrowToBuyer` با `order_type`/hold-type mapping و contract واحد ساخته می‌شود.
3. callerها یکی‌یکی migrate می‌شوند؛ بعد از هر migration test اختصاصی و full financial suite اجرا می‌شود.
4. در پایان، direct Core refund فقط internal می‌ماند یا visibility آن کاهش می‌یابد؛ static guard/grep test از direct callerهای ممنوع جلوگیری می‌کند.
5. fallback SQL حذف یا به WalletService منتقل می‌شود؛ هر fallback با fail-closed behavior جایگزین می‌شود.

## تست‌های لازم

- buyer refund برای custom/social/influencer/vitrine/budget؛
- duplicate same idempotency key؛
- refund در status نامعتبر؛
- refund پس از partial settlement؛
- failure wallet mutation باعث rollback status escrow؛
- ownership mismatch؛
- reconciliation balance/locked/escrow/ledger؛
- static architecture test: ممنوعیت direct `->refundFunds()` خارج از allowlist orchestration.
