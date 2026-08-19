# گزارش فاز ۱ ماژول ثبت تبلیغات Ads — ۲۰۲۶/۰۶/۲۰

## هدف
شروع اصلاح ماژول حساس ثبت تبلیغات با تمرکز روی مسیر مرکزی:

```text
/ads/create
/ads/store
/api/ads/type-info
/api/ads/preview-cost
AdSystemManager
Adapters
```

## مشکلات پیدا شده

### ۱) Double withdrawal در adapterها
در مسیر استاندارد `AdSystemManager` ابتدا بودجه + کارمزد در escrow و wallet lock می‌شود. اما بعضی adapterها خودشان دوباره wallet withdraw انجام می‌دادند:

```text
AdSocialAdapter
AdTubeAdapter
NotificationAdAdapter
```

این باعث خطر double charge بود.

### ۲) Bind نبودن escrow برای همه typeها
قبلاً فقط `custom_task` و `seo` به order_id واقعی ad وصل می‌شدند. بقیه escrowها با saga_execution_id باقی می‌ماندند.

### ۳) useradscreate.js قبلاً اصلاح شده بود اما registration برای همه typeها تست یکپارچه نداشت.

### ۴) AdsController و AdminAdsController با view() ممکن بود خروجی duplicate بدهند، چون helper `view()` خودش echo می‌کند.

### ۵) Banner upload در AdsController مدیریت نمی‌شد.

### ۶) Notification در Type Grid کاربر نبود، در حالی که adapter آن وجود داشت.

---

## اصلاحات انجام‌شده

### ۱) Adapterها فقط record ایجاد می‌کنند
فایل‌ها:

```text
app/Adapters/AdSocialAdapter.php
app/Adapters/AdTubeAdapter.php
app/Adapters/NotificationAdAdapter.php
```

اکنون این adapterها دیگر withdraw/escrow انجام نمی‌دهند و فقط record استاندارد در `ads` می‌سازند.

### ۲) AdSystemManager همه escrowها را bind می‌کند
فایل:

```text
app/Services/AdSystemManager.php
```

اکنون پس از ایجاد ad، escrowها به این order_typeها منتقل می‌شوند:

```text
social_task → social_task_budget
adtube → adtube_budget
seo → seo_ad_budget
custom_task → custom_task_budget
banner → banner_budget
notification → notification_ad_budget
```

### ۳) AdsController normalize و upload گرفت
فایل:

```text
app/Controllers/User/AdsController.php
```

- `target_link` به `link/target_url` normalize می‌شود.
- `total_count/quantity` normalize می‌شود.
- `budget/total_budget` normalize می‌شود.
- برای banner، اگر فایل image ارسال شود، با `UploadService` در پوشه `banners` ذخیره می‌شود.
- متدهای `index/create/show` دیگر `return view()` ندارند تا duplicate output ایجاد نشود.

### ۴) AdminAdsController duplicate view echo اصلاح شد
فایل:

```text
app/Controllers/Admin/AdminAdsController.php
```

`echo view(...)` به `view(...)` تبدیل شد.

### ۵) Notification به Wizard اضافه شد
فایل‌ها:

```text
views/user/ads/create.php
app/Controllers/User/AdsApiController.php
```

Type جدید در wizard:

```text
notification
```

با فیلدهای:

```text
title
body
target_link
budget
scheduled_time
```

### ۶) Social platform list اصلاح شد
گزینه YouTube از SocialTask حذف شد، چون YouTube باید از AdTube ثبت شود.

---

## تست DB واقعی

اسکریپت:

```text
tools/ads-registration-phase1-db-test.php
```

سناریو:

ایجاد همه typeهای اصلی از `AdSystemManager`:

```text
social_task
adtube
seo
custom_task
banner
notification
```

بررسی شد:

- هر ۶ ad ساخته شدند.
- برای هر کدام escrow با order_id=ad_id ساخته شد.
- order_type درست بود.
- wallet فقط یکبار lock شد و adapterها withdraw دوباره نکردند.

خروجی:

```json
{
  "ok": true,
  "assertions": {
    "types": ["adtube", "banner", "custom_task", "notification", "seo", "social_task"],
    "escrow_types": ["adtube_budget", "banner_budget", "custom_task_budget", "notification_ad_budget", "seo_ad_budget", "social_task_budget"]
  }
}
```

## تست‌های فنی

```text
php -l Controllers/Services/Adapters/Views
node --check public/assets/js/views/useradscreate.js
route audit بدون MISSING
searchAdTasks بدون occurrence
```

## باقی‌مانده فازهای بعدی Ads

### فاز ۲ پیشنهادی
UI/UX و Validation واقعی Wizard:

- تست مرورگر واقعی روی wizard با typeهای مختلف
- نمایش خطاهای field-level بهتر
- preview-cost دقیق برای همه typeها
- بررسی file upload واقعی بنر در مرورگر

### فاز ۳ پیشنهادی
مدیریت advertiser و admin:

- `/ads` list و `/ads/{id}` برای همه نوع‌ها
- toggle/pause/resume/cancel با refund درست برای هر type
- admin unified actions و type-specific delegation

### فاز ۴ پیشنهادی
Financial cleanup همه ad typeها:

- refund/cancel برای social_task، adtube، banner، notification
- مصرف بودجه و escrow release برای AdTube/Banner/Notification
