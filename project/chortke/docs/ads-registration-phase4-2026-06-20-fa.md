# گزارش فاز ۴ ثبت تبلیغات — اصلاح معماری مالی Ads

تاریخ: ۱۴۰۵/۰۳/۳۰ — 2026-06-20

## تصمیم معماری

بعد از audit مشخص شد سرویس مالی موجود پروژه این است:

```text
app/Domain/Financial/Services/FinancialEscrowService.php
```

این سرویس برای منطق عمومی escrow و دامنه‌هایی مثل SocialTask execution، Influencer، Vitrine، dispute و expired holds استفاده می‌شود. بنابراین سرویس جدید عمومی با نام `AdFinanceService` مناسب نبود و اصلاح شد.

## اصلاح انجام‌شده

فایل موقت/جدید قبلی:

```text
app/Services/Ads/AdFinanceService.php
```

به نام تخصصی‌تر زیر تغییر کرد:

```text
app/Services/Ads/AdsBudgetSettlementService.php
```

طبقه‌بندی:

```text
INTERNAL_API
```

این سرویس جایگزین `FinancialEscrowService` نیست؛ فقط coordinator اختصاصی بودجه و delivery تبلیغات است.

## مرزبندی جدید

### FinancialEscrowService
مسئول منطق عمومی escrow/wallet:

- مصرف بخشی از held budget بدون seller user
- refund موجودی remaining escrow به buyer
- نگه‌داشتن قواعد عمومی wallet/escrow خارج از دامنه Ads

متدهای عمومی اضافه‌شده:

```php
consumeHeldBudget(...)
refundHeldBudget(...)
```

### AdsBudgetSettlementService
مسئول منطق اختصاصی Ads:

- finance snapshot برای `/ads/{id}` و `/admin/ads/{id}`
- admin unified actions: approve/reject/pause/resume/cancel/delete
- delivery settlement برای:
  - banner impression/click
  - notification delivery/click
  - adtube completed_view + worker reward

## Binding اصلاح‌شده

برای قابل resolve شدن `FinancialEscrowService`، binding زیر اضافه شد:

```php
App\Contracts\AntiFraud\FraudGuardInterface::class
    => App\Services\AntiFraud\FraudGuardService::class
```

## فایل‌های کلیدی تغییرکرده

```text
bootstrap/app.php
app/Domain/Financial/Services/FinancialEscrowService.php
app/Services/Ads/AdsBudgetSettlementService.php
app/Controllers/Admin/AdminAdsController.php
app/Controllers/User/AdsController.php
app/Controllers/User/AdtubeController.php
app/Controllers/User/NotificationController.php
app/Services/AdNotificationDispatcher.php
app/Services/BannerService.php
app/Adapters/BannerAdapter.php
app/Adapters/NotificationAdAdapter.php
views/user/ads/show.php
views/admin/ads/show.php
public/assets/js/admin/adsindex.js
public/assets/js/views/useradsshow.js
```

## تست‌ها

### DB

```bash
php tools/ads-phase4-finance-delivery-db-test.php
php tools/ads-management-phase3-db-test.php
```

نتیجه:

```json
{ "ok": true }
```

سناریوهای تست‌شده:

- Banner: approve → impression/click delivery → spend budget → admin reject → refund remaining
- Notification: approve → delivery واقعی → consume budget
- AdTube: completed view → worker reward → consume advertiser escrow
- Phase 3 cancel/refund برای تمام typeها همچنان PASS

### Browser real

```bash
node /home/user/browser-test/ads-phase4-real-preview.js
```

نتیجه:

```json
{
  "ok": true,
  "errors": [],
  "failedRequests": []
}
```

اسکرین‌شات‌ها:

```text
tools/browser-preview/screenshots/ads-phase4-user-finance.png
tools/browser-preview/screenshots/ads-phase4-admin-actions.png
```

## نکته باقی‌مانده

منطق مالی Ads الان از حالت سرویس عمومی اشتباه خارج شد، اما اگر در آینده بزرگ‌تر شود، بهتر است به دو سرویس کوچک‌تر تقسیم شود:

```text
AdsFinanceQueryService
AdminAdActionService
```

فعلاً برای جلوگیری از over-engineering این تقسیم انجام نشد.
