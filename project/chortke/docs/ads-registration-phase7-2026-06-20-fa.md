# گزارش فاز ۷ ثبت تبلیغات — SocialTask Admin Internals و SEO Payout Reconciliation

تاریخ: ۱۴۰۵/۰۳/۳۰ — 2026-06-20

## هدف فاز ۷

بعد از پاک‌سازی سطح‌های فعال admin در فاز ۶، این فاز روی internals باقی‌مانده تمرکز داشت:

```text
SocialTask admin reject/cancel/status
SocialTask approve payout از escrow مرکزی
SEO payout direct decrement و lifecycle reconciliation
```

## اصلاحات انجام‌شده

### 1. SocialTask admin internals

فایل:

```text
app/Services/SocialTask/SocialTaskService.php
```

متدهای زیر قبلاً مستقیم status را عوض می‌کردند یا مسیر refund ناقص داشتند:

```php
adminRejectAd()
adminCancelAd()
adminChangeAdStatus()
toggleAdStatus()
```

اصلاح شدند تا به settlement واحد تبلیغات delegate کنند:

```php
AdsBudgetSettlementService::applyAdminAction(...)
```

نتیجه:

- reject/cancel مانده escrow کمپین را refund می‌کند.
- pause/resume وضعیت `status` و `is_active` را هماهنگ نگه می‌دارد.
- مسیر قدیمی `adminCancelAd` که به context ناموجود وصل بود حذف شد.

طبقه‌بندی:

```text
PRIMARY برای routeهای admin SocialTask
ADVERTISER_ONLY compatibility برای toggleAdStatus مالک تبلیغ
```

### 2. SocialTask approve payout از escrow کمپین

فایل‌ها:

```text
app/Jobs/SocialTask/ApproveSocialTaskExecutionJob.php
app/Models/Escrow.php
```

مشکل قبلی:

```text
ApproveSocialTaskExecutionJob مستقیماً به worker deposit می‌کرد و فقط ads.remaining_budget را کم می‌کرد.
در نتیجه escrow/locked تبلیغ‌دهنده آزاد نمی‌شد.
```

اصلاح:

- اگر escrow از نوع `social_task_budget` وجود داشته باشد:

```php
EscrowService::partialRelease(...)
```

استفاده می‌شود.

- اگر task قدیمی بدون escrow باشد، fallback قبلی حفظ شد.

`Escrow::findReleasable()` هم برای `social_task_budget` باز شد.

### 3. SEO lifecycle reconciliation برای مانده کمتر از min_payout

فایل:

```text
app/Services/Ads/AdsBudgetSettlementService.php
```

مشکل:

`SeoPayoutService::deductFromBudget()` می‌تواند remaining_budget را به مقداری کمتر از `min_payout` برساند یا status را `exhausted` کند. اگر lifecycle این وضعیت را نگیرد، مانده escrow می‌تواند قفل بماند.

اصلاح:

`reconcileLifecycle()` حالا این موارد را هم می‌گیرد:

```text
status = exhausted
یا type = seo و remaining_budget < min_payout
```

و سپس:

```text
campaign = completed
remaining_budget = 0
remaining escrow = refunded
locked wallet = آزاد
```

### 4. تکمیل اصلاحات فاز ۶ در پنل بنر/SEO

در ادامه تست مرورگر، چند leftover runtime پیدا شد و اصلاح شد:

- `SearchOrchestrator::searchBanners` به `BannerService::searchBanners` تغییر کرد.
- viewهای بنر parse error داشتند؛ اصلاح شدند.
- helperهای بنر load و null-safe شدند.
- migration فاز ۶ برای ستون‌های پنل بنر اجرا شد.
- SEO admin JS از `data-base` استفاده می‌کند و با base path `/chortke` درست است.

## تست‌ها

### DB Tests

```bash
php tools/ads-phase7-admin-social-seo-db-test.php
php tools/ads-phase6-social-escrow-db-test.php
php tools/ads-phase5-lifecycle-reconciliation-db-test.php
php tools/ads-phase4-finance-delivery-db-test.php
php tools/ads-management-phase3-db-test.php
```

نتیجه همه:

```json
{ "ok": true }
```

سناریوهای فاز ۷:

1. SocialTask admin reject
   - status = rejected
   - remaining_budget = 0
   - social_task_budget escrow = refunded
   - advertiser locked = 0

2. SocialTask pause/resume
   - pause: status = paused, is_active = 0
   - resume: status = active, is_active = 1

3. SocialTask admin cancel
   - status = cancelled
   - escrow refunded
   - locked آزاد

4. SEO min_payout remainder
   - remaining_budget < min_payout
   - reconcileLifecycle اجرا شد
   - campaign = completed
   - escrow refunded
   - locked آزاد

### Browser real

```bash
node /home/user/browser-test/ads-phase6-admin-specialized-preview.js
```

نتیجه:

```json
{
  "ok": true,
  "errors": [],
  "failedRequests": []
}
```

صفحات تست‌شده:

```text
/admin/banners
/admin/banners/placements
/admin/seo-ad
```

اسکرین‌شات‌ها:

```text
tools/browser-preview/screenshots/ads-phase6-admin-banners.png
tools/browser-preview/screenshots/ads-phase6-admin-banner-placements.png
tools/browser-preview/screenshots/ads-phase6-admin-seo.png
```

### Route/legacy audit

برای محدوده Ads/Banner/SEO/SocialTask admin:

```bash
python3 /tmp/audit_routes_chortke.py | grep -E "Admin\\Banner|Admin\\SeoAd|Admin\\SocialTask|Admin\\AdminAds"
```

خروجی: خالی.

جستجوی executable legacy:

```bash
grep -RIn "FROM advertisements|UPDATE advertisements|searchService->searchBanners" app routes views public tools
```

خروجی: خالی.

## وضعیت بعد از فاز ۷

Core Ads + Admin specialized surfaces + SocialTask admin finance + SEO lifecycle reconciliation اکنون یکپارچه‌تر شده‌اند.

مواردی که هنوز می‌توانند در فاز بعد بررسی شوند:

```text
1. User\BannerController: route فعال ندارد؛ باید DEPRECATED_REMOVE یا compatibility redirect نهایی شود.
2. SeoPayoutService هنوز عملیات deduct/refund بودجه را مستقیم انجام می‌دهد؛ با reconcile امن شده ولی اگر بخواهیم کاملاً ایدئال شود، می‌توان آن را به AdsBudgetSettlementService نزدیک‌تر کرد.
3. Content module parse error قدیمی هنوز باقی است:
   views/user/content/revenues.php line 103
```
