# گزارش فاز ۱ Content — Route / Parse / Stabilization

تاریخ: ۱۴۰۵/۰۳/۳۰ — 2026-06-20

## هدف

شروع تکمیل ماژول مستقل «کسب درآمد از محتوا» با تمرکز روی بالا آمدن مسیرهای اصلی و تثبیت اولیه قبل از ورود به منطق کامل مالی/UX.

## منطق فعلی ماژول Content

جریان کلی فعلی:

```text
کاربر محتوای ویدیویی ثبت می‌کند
→ تعهدنامه را قبول می‌کند
→ محتوا pending می‌شود
→ ادمین approve/reject می‌کند
→ ادمین publish می‌کند
→ بعد از حداقل ۲ ماه فعالیت، درآمد دوره‌ای ثبت می‌شود
→ درآمد pending/approved/paid می‌شود
→ پرداخت به کیف پول کاربر یا outbox انجام می‌شود
```

پلتفرم‌های فعلی:

```text
aparat
youtube
upload_center
```

## اصلاحات انجام‌شده

### 1. فعال‌سازی routeهای کاربر

در `routes/user.php` routeهای زیر اضافه شدند:

```text
GET  /content
GET  /content/create
POST /content/store
GET  /content/revenues
GET  /content/{id}
```

این routeها به `App\Controllers\User\ContentController` وصل شدند.

### 2. رفع parse error شناخته‌شده

فایل:

```text
views/user/content/revenues.php
```

خطای قبلی:

```text
PHP Parse error: syntax error, unexpected identifier "assets"
```

علت: استفاده از PHP tag داخل string برای `$styles`.

اصلاح شد.

### 3. رفع double render / duplicate DOCTYPE

مشکل:

`ContentController` با `return view(...)` خروجی را دوباره توسط router render می‌کرد؛ چون helper `view()` خودش echo می‌کند.

نتیجه قبلی:

```text
DOCTYPE دوبار
layout دوبار
```

اصلاح:

در `User\ContentController` و `Admin\ContentController` viewها echo می‌شوند و سپس `return ''` انجام می‌شود تا خروجی دوباره چاپ نشود.

### 4. routeهای admin با متدهای واقعی هماهنگ شدند

در `routes/admin.php` این موارد اصلاح شد:

```text
revenueCreate → createRevenue
revenueStore  → storeRevenue
```

برای مسیرهای زیر wrapper اضافه شد:

```text
suspend
revenueApprove
revenuePay
```

### 5. Schema compatibility برای Content

Migration جدید:

```text
database/migrations/2026_06_20_0008_content_phase1_schema_route_compat.sql
```

به دلیل اینکه جدول‌های قدیمی Content فقط ستون‌های حداقلی داشتند، ستون‌های موردنیاز سرویس/ویوها اضافه شدند.

برای `content_submissions`:

```text
video_url
description
category
agreement_accepted
agreement_accepted_at
agreement_ip
agreement_fingerprint
approved_at / approved_by
reviewed_at / reviewed_by
rejection_reason / rejected_by / rejected_at
published_at / published_url / published_by / channel_name
suspended_at / suspended_by
admin_notes
is_deleted
updated_at
```

برای `content_agreements`:

```text
submission_id
agreement_text
accepted_at
ip_address
user_agent
device_fingerprint
is_deleted
updated_at
```

برای `content_revenues`:

```text
submission_id
period
views
total_revenue
gross_amount
site_share_percent
site_share_amount
platform_fee
user_share_percent
user_share_amount
tax_percent
tax_amount
net_user_amount
currency
metadata
reviewed_by / reviewed_at
paid_at / paid_by_admin
transaction_id
admin_notes
created_by
is_deleted
updated_at
```

### 6. اصلاح crashهای فوری ContentService

فایل:

```text
app/Services/ContentService.php
```

اصلاحات:

- `idempotencyService` مقداردهی nullable شد.
- `walletService` property اضافه شد.
- fallback برای نبود idempotency service اضافه شد.
- متغیرهای undefined مثل `$id` و `$contentId` در outbox اصلاح شدند.
- outbox calls با null-safe operator امن شدند.

### 7. اصلاح مدل‌ها

فایل‌ها:

```text
app/Models/ContentRevenue.php
app/Models/ContentSubmission.php
app/Models/ContentAgreement.php
```

اصلاحات:

- whitelist ستون‌های درآمد کامل شد.
- mapping سازگار برای `content_id/submission_id` اضافه شد.
- `ContentAgreement::create()` که قبلاً `Database::insert()` را اشتباه صدا می‌زد، به insert SQL امن تبدیل شد.
- `ContentSubmission::update()` ستون‌های publish/suspend/reject را پشتیبانی می‌کند.

### 8. اصلاح JS صفحه create

فایل:

```text
public/assets/js/views/usercontentcreate.js
```

مشکل قبلی:

فایل JS خارجی شامل PHP tag بود:

```js
fetch('<?= url('/content/store') ?>')
```

و باعث خطای مرورگر می‌شد:

```text
missing ) after argument list
```

اصلاح:

- URL و CSRF از data attributes فرم خوانده می‌شوند.

## تست‌ها

### Lint

```bash
php -l routes/user.php
php -l routes/admin.php
php -l app/Controllers/User/ContentController.php
php -l app/Controllers/Admin/ContentController.php
php -l app/Services/ContentService.php
php -l app/Models/ContentSubmission.php
php -l app/Models/ContentRevenue.php
php -l app/Models/ContentAgreement.php
php -l views/user/content/*.php
php -l views/admin/content/*.php
node --check public/assets/js/views/usercontentcreate.js
node --check public/assets/js/views/usercontentindex.js
```

نتیجه: PASS.

### Route audit محدوده Content

برای routeهای user/admin Content، missing method نداریم.

### Browser real

اسکریپت:

```bash
node /home/user/browser-test/content-phase1-preview.js
```

صفحات تست‌شده:

```text
/content
/content/create
/content/revenues
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
tools/browser-preview/screenshots/content-phase1-index.png
tools/browser-preview/screenshots/content-phase1-create.png
tools/browser-preview/screenshots/content-phase1-revenues.png
```

### تست ثبت واقعی محتوا

اسکریپت:

```bash
node /home/user/browser-test/content-phase1-submit-test.js
```

نتیجه:

```json
{
  "ok": true,
  "responses": [
    {
      "http": 200,
      "success": true,
      "message": "محتوای شما با موفقیت ثبت شد و در صف بررسی قرار گرفت.",
      "data": {
        "submission_id": 1
      }
    }
  ]
}
```

## وضعیت بعد از فاز ۱

Content اکنون از نظر route/parse/ثبت اولیه پایدار شده است.

موارد باقی‌مانده برای فاز بعد:

```text
1. بازطراحی UI مستقل Content Hub
2. اصلاح کامل منطق درآمد/پرداخت و جلوگیری از double pay
3. تعیین دقیق مبنای دو ماه فعالیت
4. یکپارچه‌سازی admin revenue approve/pay با wallet/outbox
5. تست DB کامل چرخه: submit → approve → publish → revenue → approve revenue → pay
```
