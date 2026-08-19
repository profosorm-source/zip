# گزارش فاز ۸ تکمیلی Ads — Deprecated User Banner و SEO Payout Hardening

تاریخ: ۱۴۰۵/۰۳/۳۰ — 2026-06-20

## هدف

بعد از فاز ۷، دو مورد کوچک اما مهم باقی مانده بود:

1. `User\BannerController` دیگر route فعال ندارد و باید تعیین تکلیف می‌شد.
2. `SeoPayoutService::deductFromBudget()` هنوز بعد از پرداخت می‌توانست کمپین را active نگه دارد حتی وقتی remaining_budget کمتر از min_payout شده بود.

## اصلاحات

### 1. User BannerController

فایل:

```text
app/Controllers/User/BannerController.php
routes/user.php
```

نتیجه audit:

```text
User\BannerController route فعال ندارد.
advertiser banner flow تحت /ads و AdsController یکپارچه شده است.
```

اصلاح:

- import بی‌استفاده از `routes/user.php` حذف شد.
- کلاس حذف فیزیکی نشد چون تست‌های ساختاری هنوز وجود کلاس را بررسی می‌کنند.
- docblock کلاس با برچسب زیر مشخص شد:

```text
DEPRECATED_REMOVE
```

یادداشت:

```text
Do not add new routes here; banner advertiser management must go through /ads.
```

### 2. SEO Payout hardening

فایل:

```text
app/Services/SeoPayoutService.php
```

اصلاح:

اگر بعد از payout، بودجه کمپین:

```text
remaining_budget <= 0
یا remaining_budget < min_payout
```

شود، همان لحظه:

```text
status = exhausted
is_active = 0
```

می‌شود.

سپس job ساعتی/فاز ۵:

```php
AdsBudgetSettlementService::reconcileLifecycle()
```

آن را می‌گیرد و مانده escrow را refund می‌کند.

این کار جلوی نمایش/انتخاب کمپینی را می‌گیرد که از نظر مبلغ دیگر قابل پرداخت نیست.

## تست‌ها

### Lint

```bash
php -l app/Services/SeoPayoutService.php
php -l app/Controllers/User/BannerController.php
php -l routes/user.php
```

همه PASS.

### DB Regression

بعد از نصب مجدد PHP/MariaDB در sandbox و اجرای migrationها:

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

### Browser real

بعد از نصب مجدد Playwright/Chromium deps:

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

## وضعیت Ads بعد از فاز ۸

بخش Ads از نظر این روند کامل‌تر شده است:

```text
create/store unified
escrow bind all types
pause/resume/cancel/refund
admin unified actions
admin specialized banners/seo/social cleanup
banner/notification/adtube delivery budget pipeline
lifecycle reconciliation
SocialTask escrow payout
SEO min-payout reconciliation
UserBannerController deprecated
```

## گزینه بعدی پیشنهادی

با توجه به parse error شناخته‌شده، قدم منطقی بعدی خارج از Ads:

```text
Content module cleanup
views/user/content/revenues.php line 103
```
