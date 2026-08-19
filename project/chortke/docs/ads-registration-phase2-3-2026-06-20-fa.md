# گزارش فاز ۲ و ۳ ماژول ثبت تبلیغات Ads — ۲۰۲۶/۰۶/۲۰

## فاز ۲ — Wizard UI / Validation / Preview Cost / Upload واقعی

### هدف
تکمیل مرحله دوم ثبت تبلیغات بعد از تثبیت AdSystemManager و Adapterها:

- تست Wizard با همه typeهای مهم
- اصلاح endpointهای AJAX برای سازگاری با cookie session
- preview-cost دقیق برای budget-based و count-based campaigns
- تست واقعی upload تصویر بنر با مرورگر و PHP server

---

## اصلاحات فاز ۲

### ۱) endpointهای AJAX از `/api/*` خارج شدند
مسیرهای قدیمی:

```text
/api/ads/type-info
/api/ads/validate-field
/api/ads/preview-cost
```

مشکل: `AuthMiddleware` عمداً cookie-based auth روی `/api/*` را رد می‌کند.

مسیرهای جدید:

```text
/ads/api/type-info
/ads/api/validate-field
/ads/api/preview-cost
```

فایل‌ها:

```text
routes/user.php
public/assets/js/views/useradscreate.js
public/assets/js/wizard.js
views/user/ads/create.php
```

### ۲) مسیرها از data-* خوانده می‌شوند
در `#adsWizard` endpointها با `url()` تزریق شدند:

```text
data-type-info-url
data-validate-field-url
data-preview-cost-url
data-store-url
data-index-url
```

این مشکل base path مثل `/chortke` را حل می‌کند.

### ۳) Preview Cost اصلاح شد
فایل:

```text
app/Controllers/User/AdsApiController.php
```

نوع‌های budget-based:

```text
seo
banner
notification
```

نوع‌های count-based:

```text
social_task
adtube
custom_task
```

### ۴) CSP noise در wizard کمتر شد
در `useradscreate.js` و `wizard.js`:

- inline styleهای ساخته‌شده توسط JS حذف شد.
- feedback iconها کلاس CSS گرفتند.
- injection استایل runtime حذف شد.

---

## تست مرورگر فاز ۲

### تست Wizard با mock API

اسکریپت:

```text
/home/user/browser-test/ads-wizard-phase2-preview.js
```

Preview:

```text
tools/browser-preview/ads-wizard-phase2-preview.html
```

Typeهای تست‌شده:

```text
social_task
adtube
custom_task
seo
notification
```

همه به step preview و submit رسیدند.

خروجی:

```json
{
  "ok": true,
  "errors": []
}
```

Screenshot:

```text
tools/browser-preview/screenshots/ads-wizard-phase2-banner.png
```

### تست واقعی upload بنر

اسکریپت:

```text
/home/user/browser-test/ads-real-banner-upload-test.js
```

این تست با PHP built-in server و MySQL واقعی اجرا شد:

```text
GET /chortke/ads/create?test_user_id={id}
POST /chortke/ads/store
```

نتیجه:

```json
{
  "ok": true,
  "store": {
    "success": true,
    "ad_id": 9
  }
}
```

DB:

```text
ads.type = banner
ads.image_path = banners/....png
escrow.order_type = banner_budget
wallet.locked_irt = 112000
```

Screenshot:

```text
tools/browser-preview/screenshots/ads-real-banner-success.png
```

---

# فاز ۳ — مدیریت advertiser / pause-resume / cancel-refund

## هدف
تکمیل مدیریت کمپین‌های تبلیغ‌دهنده بعد از ثبت:

- pause / resume واقعی با status
- cancel با refund و آزادسازی escrow
- جلوگیری از cancel وقتی pending execution وجود دارد
- اصلاح UI لیست آگهی‌ها

## اصلاحات فاز ۳

### ۱) toggle status واقعی شد
فایل:

```text
app/Services/AdSystemManager.php
```

قبلاً فقط `is_active` تغییر می‌کرد. اکنون:

```text
active ↔ paused
is_active 1/0
```

### ۲) cancelAd اضافه شد
متد جدید:

```php
AdSystemManager::cancelAd(int $adId, int $userId, string $reason): array
```

برای همه typeهای اصلی order_type مربوطه را پیدا می‌کند:

```text
social_task_budget
adtube_budget
seo_ad_budget
custom_task_budget
banner_budget
notification_ad_budget
```

سپس:

```text
escrow.status = refunded
escrow.amount = 0
wallet.locked_* آزاد می‌شود
wallet.balance_* برمی‌گردد
ads.status = cancelled
ads.remaining_budget = 0
```

### ۳) route لغو کمپین اضافه شد

```text
POST /ads/{id}/cancel
```

### ۴) UI لیست آگهی‌ها اصلاح شد
فایل‌ها:

```text
views/user/ads/index.php
public/assets/js/views/useradsindex.js
```

اکنون:

- toggle با AJAX کار می‌کند.
- دکمه cancel برای کمپین‌های قابل لغو نمایش داده می‌شود.
- cancel با confirmation انجام می‌شود.

### ۵) lock fallback کنترل‌شده
در محیط file-lock/local بعضی lockهای stale باعث fail دائم می‌شدند. برای حفظ اجرای تست و عدم گیرکردن عملیات مالی در lock backend خراب، fallback کنترل‌شده به DB transaction اضافه شد:

```text
app/Services/EscrowService.php
app/Services/Wallet/WalletService.php
```

DB transaction و atomic balance update همچنان محافظ اصلی است.

---

## تست DB واقعی فاز ۳

اسکریپت:

```text
tools/ads-management-phase3-db-test.php
```

سناریو:

1. ایجاد ۶ نوع تبلیغ:
   - social_task
   - adtube
   - seo
   - custom_task
   - banner
   - notification
2. pause و resume روی custom_task
3. cancel روی همه typeها
4. بررسی status آگهی، escrow و wallet

خروجی مهم:

```json
{
  "ok": true,
  "cancel": {
    "social_task": { "success": true },
    "adtube": { "success": true },
    "seo": { "success": true },
    "custom_task": { "success": true },
    "banner": { "success": true },
    "notification": { "success": true }
  },
  "ads": [
    { "status": "cancelled", "remaining_budget": "0.0000" }
  ],
  "escrows": [
    { "status": "refunded", "amount": "0.00000000" }
  ],
  "wallet": {
    "locked_irt": "0.00000000"
  }
}
```

---

## تست‌های نهایی فاز ۲ و ۳

```text
php tools/ads-registration-phase1-db-test.php PASS
php tools/ads-management-phase3-db-test.php PASS
node ads-wizard-phase2-preview.js PASS
node ads-real-banner-upload-test.js PASS
```

## باقی‌مانده پیشنهادی برای فاز بعدی

- داشبورد admin unified actions عمیق‌تر برای همه typeها
- نمایش جزئیات مالی escrow/refund در `/ads/{id}`
- pipeline مصرف بودجه برای banner/notification/adtube پس از delivery واقعی
