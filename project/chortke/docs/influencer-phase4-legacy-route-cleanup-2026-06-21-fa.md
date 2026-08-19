# فاز ۴ اینفلوئنسر — پاک‌سازی Route Legacy و تثبیت Surfaceها

تاریخ: ۱۴۰۵/۰۳/۳۱

## هدف
بعد از تبدیل تجربه کاربر به Hub تک‌صفحه‌ای و بازطراحی پنل ادمین، مسیرهای قدیمی اینفلوئنسر طبقه‌بندی شدند تا معلوم باشد کدام مسیر منبع اصلی است و کدام فقط سازگاری با URLهای قبلی را حفظ می‌کند.

## طبقه‌بندی مسیرهای کاربر

### PRIMARY

```text
GET /influencer
```

تجربه اصلی همه بخش‌های اینفلوئنسر داخل همین Hub است.

### COMPATIBILITY_REDIRECT

```text
GET /influencer/register      → /influencer?section=profile
GET /influencer/orders        → /influencer?section=incoming
GET /influencer/ads           → /influencer?section=market
GET /influencer/ads/create    → /influencer?section=market
GET /influencer/ads/my-orders → /influencer?section=placed
```

این مسیرها حذف نشدند تا لینک‌های قدیمی نشکنند، اما تجربه اصلی دیگر از آن viewهای جداگانه نیست.

### PRIMARY ACTIONS

```text
POST /influencer/register
POST /influencer/verify
POST /influencer/orders/{id}/respond
POST /influencer/orders/{id}/proof
GET  /influencer/orders/{id}/dispute
POST /influencer/orders/{id}/dispute/message
POST /influencer/orders/{id}/dispute/escalate
POST /influencer/orders/{id}/dispute/resolve
POST /influencer/ads/store
POST /influencer/ads/orders/{id}/confirm
POST /influencer/ads/orders/{id}/dispute
```

این endpointها عملیات واقعی Hub، فرم‌ها و AJAX را انجام می‌دهند.

## طبقه‌بندی مسیرهای ادمین
همه مسیرهای ادمین اینفلوئنسر با برچسب `PRIMARY / ADMIN_ONLY` یا `PRIMARY_ACTION / ADMIN_ONLY` مشخص شدند:

```text
/admin/influencer/orders
/admin/influencer/profiles
/admin/influencer/profiles/approve
/admin/influencer/verifications
/admin/influencer/verifications/approve
/admin/influencer/verifications/reject
/admin/influencer/disputes
/admin/influencer/disputes/{id}
/admin/influencer/disputes/{id}/resolve
```

## اصلاحات تکمیلی
- فایل‌های view قدیمی مثل `ads.php`, `create-order.php`, `my-orders.php`, `my-placed-orders.php`, `register.php` فعلاً حذف نشدند؛ چون ممکن است هنوز در patchهای قبلی یا لینک‌های قدیمی reference شوند. اما routeهای GET مربوط به آن‌ها اکنون redirect سازگار هستند.
- JSهای legacy که قبلاً خطای parse داشتند در فازهای قبل placeholder یا JS معتبر شدند تا اگر asset به هر دلیل load شد، خطای مرورگر ایجاد نکند.
- `routes/missing.php` و `routes/admin.php` با کامنت‌های معماری به‌روزرسانی شدند.

## Audit جاب‌های اینفلوئنسر
طبق بررسی، جاب اصلی فعال و مجاز در صف فعلی این است:

```text
App\Jobs\InfluencerOrderTimeoutJob
```

این job در QueueWorker allowed list وجود دارد و برای timeout سفارش‌های اینفلوئنسر استفاده می‌شود، پس حفظ شد.

اما جاب‌های داخل مسیر زیر از refactor ناقص/legacy باقی مانده بودند و بعضی از آن‌ها به مدل ناموجود یا مسیر مالی قدیمی اشاره داشتند:

```text
app/Jobs/Influencer/CreateInfluencerOrderJob.php
app/Jobs/Influencer/CompleteInfluencerOrderJob.php
app/Jobs/Influencer/ConfirmInfluencerOrderJob.php
app/Jobs/Influencer/DisputeInfluencerOrderJob.php
app/Jobs/Influencer/SubmitInfluencerProofJob.php
```

تصمیم: حذف نشدند، چون ممکن است رکورد queue/outbox قدیمی با نام کلاس آن‌ها وجود داشته باشد؛ ولی همه به `COMPATIBILITY_WRAPPER` تبدیل شدند و فقط به مسیر canonical فعلی delegate می‌کنند:

```text
InfluencerService → InfluencerCommandService → FinancialEscrowService
```

یعنی هیچ جاب legacy دیگر مسیر مالی موازی، مدل ناموجود `InfluencerOrder` یا پرداخت مستقیم قدیمی ندارد.

## تست‌ها

### Syntax

```bash
php -l routes/missing.php
php -l routes/admin.php
php -l app/Controllers/Admin/InfluencerController.php
php -l views/admin/influencer/*.php
node --check public/assets/js/admin/influencer.js
node --check /home/user/browser-test/influencer-phase4-compat-redirects.js
```

### Browser/Regression موجود
فازهای قبلی برای Hub و ادمین قبلاً با مرورگر پاس شده‌اند:

```text
influencer-phase2-hub-preview.js
influencer-phase2-hub-actions.js
influencer-phase3-admin-preview.js
influencer-phase3-admin-actions.js
```

## نتیجه
Surfaceهای اصلی اینفلوئنسر اکنون مشخص هستند:

```text
User PRIMARY:  /influencer
Admin PRIMARY: /admin/influencer/*
```

و مسیرهای قدیمی GET فقط نقش سازگاری دارند، نه تجربه اصلی.
