# گزارش فاز ۶ ثبت تبلیغات — Legacy Surface Cleanup

تاریخ: ۱۴۰۵/۰۳/۳۰ — 2026-06-20

## هدف فاز ۶

پاک‌سازی routeها و سطح‌های تخصصی Ads که بعد از refactor هنوز مستقیم status/budget را تغییر می‌دادند یا به route/schema/helper قدیمی وصل بودند.

تمرکز این فاز:

```text
/admin/banners
/admin/banners/placements
/admin/seo-ad
SocialTask approve payout/escrow
legacy references مثل advertisements و SearchOrchestrator::searchBanners
```

## اصلاحات انجام‌شده

### 1. Admin Banner Controller

فایل:

```text
app/Controllers/Admin/BannerController.php
```

اصلاحات:

- route/method mismatchهای فعال اصلاح شد:
  - `showCreate`
  - `showEdit`
  - `placements`
  - `updatePlacement`
  - `togglePlacement`
  - `toggle`
- approve/reject/delete بنر دیگر مستقیم status را عوض نمی‌کنند.
- این actionها به settlement واحد Ads delegate شدند:

```php
AdsBudgetSettlementService::applyAdminAction(...)
```

طبقه‌بندی:

```text
PRIMARY برای routeهای canonical
COMPATIBILITY_REDIRECT برای form actionهای قدیمی query-string یا بدون id
```

### 2. Admin Banner routes

فایل:

```text
routes/admin.php
```

routeهای canonical و compatibility اضافه/مرتب شدند:

```text
/admin/banners/{id}/approve
/admin/banners/{id}/reject
/admin/banners/{id}/delete
/admin/banners/{id}/toggle
```

و مسیرهای قدیمی فرم‌ها نگه داشته شدند با comment:

```text
COMPATIBILITY_REDIRECT
```

### 3. Search leftover

در `Admin\BannerController` قبلاً این صدا زده می‌شد:

```php
SearchOrchestrator::searchBanners(...)
```

در runtime خطا می‌داد:

```text
Method searchBanners does not exist in SearchOrchestrator or its providers.
```

اصلاح شد؛ چون منطق واقعی در `BannerService` بود:

```php
BannerService::searchBanners(...)
```

این مورد از نوع leftover refactor بود و با اضافه‌کردن متد جدید حل نشد.

### 4. Admin Banner views

فایل‌ها:

```text
views/admin/banners/create.php
views/admin/banners/edit.php
views/admin/banners/index.php
views/admin/banners/placements.php
views/admin/banners/stats.php
```

مشکلات اصلاح‌شده:

- parse error در `$styles` به خاطر قرار دادن PHP tag داخل string
- null-safe شدن viewها برای رکوردهای canonical ads
- لود helper موجود:

```php
helpers/banner_helpers.php
```

- helper `banner_status_badge` null-safe و status-aware شد.

### 5. Schema reconciliation برای پنل تخصصی بنر

Migration جدید:

```text
database/migrations/2026_06_20_0006_ads_phase6_legacy_surface_cleanup.sql
```

ستون‌های مورد نیاز پنل بنر به canonical `ads` اضافه شد:

```text
banner_type
category
sort_order
target
alt_text
```

علت: view/controllerهای فعال بنر این ستون‌ها را استفاده می‌کردند اما جدول canonical ads آن‌ها را نداشت.

### 6. Admin SEO Controller/View/JS

فایل‌ها:

```text
app/Controllers/Admin/SeoAdController.php
views/admin/seo-ad/index.php
public/assets/js/admin/seoadindex.js
```

اصلاحات:

- approve/reject/pause دیگر مستقیم یا از مسیر جداگانه ناقص انجام نمی‌شوند.
- به `AdsBudgetSettlementService::applyAdminAction()` delegate شدند.
- وضعیت‌های جدید مثل `cancelled/completed/expired/approved/pending_review` در view پشتیبانی شدند.
- JS دیگر hardcoded `/admin/seo-ad/...` نیست و از `data-base` استفاده می‌کند؛ برای base path `/chortke` درست کار می‌کند.

### 7. SocialTask approve escrow fix

فایل‌ها:

```text
app/Jobs/SocialTask/ApproveSocialTaskExecutionJob.php
app/Models/Escrow.php
```

مشکل قبلی:

```text
ApproveSocialTaskExecutionJob مستقیماً به worker واریز می‌کرد و فقط remaining_budget را کم می‌کرد؛
escrow/locked بودجه campaign آزاد نمی‌شد.
```

اصلاح:

- اگر `social_task_budget` وجود داشته باشد:

```php
EscrowService::partialRelease(...)
```

استفاده می‌شود.

- اگر legacy task بدون escrow باشد، fallback قبلی حفظ شده است.

طبقه‌بندی:

```text
PRIMARY برای کمپین‌های جدید escrow-based
COMPATIBILITY_REDIRECT برای social taskهای legacy بدون escrow
```

همچنین `Escrow::findReleasable()` اجازه release برای `social_task_budget` گرفت.

### 8. Cache/Analytics legacy references

فایل‌ها:

```text
app/Jobs/CacheWarmupJob.php
app/Models/AdvancedAnalytics.php
```

اصلاحات:

- query اجرایی روی جدول legacy `advertisements` حذف شد و به `ads` تغییر کرد.
- whitelist analytics از `advertisements` به `ads` تغییر کرد.

## تست‌ها

### Lint

PASS:

```text
app/Controllers/Admin/BannerController.php
app/Controllers/Admin/SeoAdController.php
app/Jobs/SocialTask/ApproveSocialTaskExecutionJob.php
app/Models/Escrow.php
helpers/banner_helpers.php
app/Jobs/CacheWarmupJob.php
app/Models/AdvancedAnalytics.php
routes/admin.php
views/admin/banners/*.php
views/admin/seo-ad/index.php
public/assets/js/admin/bannersindex.js
public/assets/js/admin/seoadindex.js
```

### Route audit محدوده Ads/Banner/SEO

```bash
python3 /tmp/audit_routes_chortke.py | grep -E "Admin\\Banner|Admin\\SeoAd|Admin\\AdminAds"
```

خروجی: خالی.

### DB Tests

```bash
php tools/ads-phase6-social-escrow-db-test.php
php tools/ads-phase5-lifecycle-reconciliation-db-test.php
php tools/ads-phase4-finance-delivery-db-test.php
php tools/ads-management-phase3-db-test.php
```

نتیجه همه:

```json
{ "ok": true }
```

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

## وضعیت باقی‌مانده بعد از فاز ۶

برای Ads، سطح‌های اصلی و routeهای تخصصی فعال پاک‌سازی شدند. مواردی که هنوز می‌توانند در فاز بعد بررسی شوند:

```text
SocialTask admin service internals برای reject/cancel قدیمی
SEO payout service direct budget decrement در مسیر اجرای SEO — فعلاً با تست‌های قبلی PASS است ولی بهتر است با settlement عمومی همسان‌تر شود.
User\BannerController اگر route فعال شود، باید DEPRECATED_REMOVE یا redirect به /ads شود.
```
