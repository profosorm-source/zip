# گزارش فاز ۵ ثبت تبلیغات — Lifecycle Reconciliation و Hardening بودجه

تاریخ: ۱۴۰۵/۰۳/۳۰ — 2026-06-20

## هدف فاز ۵

بعد از تکمیل فاز ۴، تمرکز فاز ۵ روی بستن امن کمپین‌ها و جلوگیری از قفل‌ماندن پول بود:

- جلوگیری از delivery وقتی بودجه باقی‌مانده از هزینه هر واحد کمتر است
- آزادسازی خودکار مانده escrow در این حالت
- reconcile کمپین‌های منقضی‌شده یا تمام‌شده
- جایگزینی مسیرهای cron قدیمی که فقط status را عوض می‌کردند و escrow/refund را نادیده می‌گرفتند
- تست idempotency برای delivery events

## اصلاحات اصلی

### 1. جلوگیری از قفل‌ماندن بودجه کمتر از unit cost

در `AdsBudgetSettlementService::consumeDeliveryBudget()` اگر بودجه باقی‌مانده برای delivery بعدی کافی نباشد:

```text
campaign status = completed
is_active = 0
remaining_budget = 0
active escrow = refunded
wallet locked = آزاد
ad_refund transaction = ثبت
```

این مشکل قبلاً می‌توانست باعث شود کمپین بسته شود اما مقدار اندک escrow/locked باقی بماند.

### 2. AdTube insufficient budget

در settlement آدتوب، اگر مانده بودجه کمتر از reward هر view باشد:

```text
execution = rejected
campaign = completed
remaining escrow = refunded
```

### 3. Lifecycle reconciliation

متد جدید:

```php
AdsBudgetSettlementService::reconcileLifecycle(int $limit = 100): array
```

این متد کمپین‌های زیر را پیدا و به صورت مالی درست می‌بندد:

```text
status IN active/approved/paused
pending_count = 0
remaining_budget <= 0
یا end_date/deadline گذشته
یا remaining_count <= 0 برای social_task/custom_task/adtube
```

خروجی شامل counts و نتیجه هر item است.

### 4. اصلاح cron legacy

در `app/Console/Kernel.php` مسیر قدیمی زیر حذف شد:

```sql
UPDATE advertisements SET status = 'completed' ...
```

این query هم روی جدول legacy بود، هم escrow/refund را پوشش نمی‌داد.

جایگزین شد با:

```php
AdsBudgetSettlementService::reconcileLifecycle(200)
```

نام job جدید:

```text
ads_lifecycle_reconcile
```

### 5. حذف استفاده از expireOldAdvertisements خام در BannerService/CronService

مسیرهای زیر دیگر مستقیم `Ads::expireOldAdvertisements()` را صدا نمی‌زنند:

```text
BannerService::deactivateExpired()
CronService::expireOldAdvertisements()
```

هر دو از reconciliation مالی جدید استفاده می‌کنند.

## تست‌ها

### DB Test فاز ۵

```bash
php tools/ads-phase5-lifecycle-reconciliation-db-test.php
```

نتیجه:

```json
{ "ok": true }
```

سناریوهای تست‌شده:

1. **Idempotency delivery**
   - دو بار consume با یک idempotency key
   - فقط یک `ad_delivery_events` ساخته شد
   - بودجه فقط یک بار مصرف شد

2. **Tiny remaining budget**
   - بودجه باقی‌مانده کمتر از هزینه impression
   - delivery انجام نشد
   - کمپین completed شد
   - escrow refunded شد
   - wallet locked صفر شد

3. **Expired campaign reconciliation**
   - notification فعال با end_date گذشته
   - reconcile اجرا شد
   - status = expired
   - escrow refunded
   - locked آزاد شد

### Regression

```bash
php tools/ads-phase4-finance-delivery-db-test.php
php tools/ads-management-phase3-db-test.php
```

نتیجه هر دو:

```json
{ "ok": true }
```

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

اسکرین‌شات‌های به‌روزشده:

```text
tools/browser-preview/screenshots/ads-phase4-user-finance.png
tools/browser-preview/screenshots/ads-phase4-admin-actions.png
```

## فایل‌های تغییرکرده

```text
app/Services/Ads/AdsBudgetSettlementService.php
app/Console/Kernel.php
app/Services/BannerService.php
app/Services/Cron/CronService.php
tools/ads-phase5-lifecycle-reconciliation-db-test.php
docs/ads-registration-phase5-2026-06-20-fa.md
```
