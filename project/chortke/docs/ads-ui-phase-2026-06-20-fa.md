# گزارش تکمیل UI/UX تبلیغات — مدیریت و ثبت آگهی

تاریخ: ۱۴۰۵/۰۳/۳۰ — 2026-06-20

## هدف

بعد از تکمیل backend/finance/settlement بخش Ads، این مرحله برای تکمیل ظاهر تبلیغ‌دهنده انجام شد:

```text
/ads
/ads/create
sidebar/menu labels
```

با تأکید روی:

- فونت‌ها نه خیلی بزرگ و نه بیش‌ازحد bold
- کارت‌های آماری در جای درست
- Binance-like UI با رنگ زرد/مشکی اما تمیز و خوانا
- صفحات بدون sidebar سراسری، با navbar و subnav داخلی
- دکمه روز/شب پایین چپ visible و فعال
- Material Icons بدون نمایش text fallback

## اصلاحات انجام‌شده

### 1. صفحه مدیریت تبلیغات `/ads`

فایل‌ها:

```text
views/user/ads/index.php
public/assets/css/views/useradsindex.css
public/assets/js/views/useradsindex.js
```

تغییرات:

- ساختار قدیمی جدول به کارت‌های کمپین تبدیل شد.
- Hero بالای صفحه اضافه شد:
  - عنوان مدیریت کمپین‌ها
  - CTA ثبت کمپین جدید
  - CTA مشاهده بازار تسک‌ها
- کارت بودجه در Hero اضافه شد:
  - بودجه کل
  - مصرف‌شده
  - باقی‌مانده
  - progress bar
- کارت‌های آماری زیر subnav قرار گرفتند:
  - کل کمپین‌ها
  - کمپین فعال
  - کل نمایش‌ها
  - کل کلیک‌ها
- فیلتر status و search client-side اضافه شد.
- کارت‌های کمپین شامل:
  - type icon
  - status badge
  - budget remaining
  - impressions/clicks
  - progress line
  - toggle pause/resume
  - جزئیات
  - لغو

### 2. صفحه ثبت تبلیغ `/ads/create`

فایل‌ها:

```text
views/user/ads/create.php
public/assets/css/wizard.css
```

تغییرات:

- Hero مستقل برای ثبت کمپین اضافه شد.
- subnav داخلی اضافه شد.
- ستون نکات قبل از ثبت اضافه شد:
  - escrow بودجه کمپین‌ها
  - مصرف budget بعد از delivery واقعی
  - SocialTask بدون proof دستی
  - CustomTask طبق proof schema
- Wizard card و stepper با پالت Binance-like بازطراحی شد.
- فونت‌ها و وزن‌ها کنترل شدند:
  - hero h1 حدود 24px
  - card title حدود 14px
  - body text حدود 12.5–13.5px
- مشکل مهم اصلاح شد:
  - قبلاً همه stepهای wizard همزمان دیده می‌شدند.
  - حالا فقط `.wizard-panel.active` نمایش داده می‌شود.
- دکمه مرحله قبل در مرحله اول با `vis-hidden` پنهان شد.

### 3. Sidebar/Menu labels

فایل‌ها:

```text
views/partials/user/sidebar.php
views/partials/admin/sidebar.php
```

تغییرات کاربری:

```text
پنل تبلیغ‌دهنده → تبلیغات و کمپین‌ها
آگهی‌های من → مرکز تبلیغات
لیست و مدیریت → مدیریت کمپین‌ها
ثبت آگهی جدید → ثبت کمپین جدید
```

تغییرات ادمین:

```text
تسک‌ها و تبلیغات → تبلیغات، تسک‌ها و کمپین‌ها
```

## تست مرورگر واقعی

اسکریپت:

```bash
node /home/user/browser-test/ads-ui-advertiser-preview.js
```

نتیجه:

```json
{
  "ok": true,
  "errors": [],
  "failedRequests": [],
  "management": {
    "hasHero": true,
    "hasSubnav": true,
    "statCards": 4,
    "campaignCards": 6,
    "h1Font": 25,
    "h1Weight": "700",
    "noSidebar": true,
    "themeFab": true,
    "iconsFontLoaded": true,
    "hasPhpError": false
  },
  "create": {
    "hasHero": true,
    "hasTips": true,
    "hasSubnav": true,
    "typeCards": 6,
    "h1Font": 24,
    "h1Weight": "700",
    "noSidebar": true,
    "themeFab": true,
    "iconsFontLoaded": true,
    "hasPhpError": false
  }
}
```

اسکرین‌شات‌ها:

```text
tools/browser-preview/screenshots/ads-ui-management.png
tools/browser-preview/screenshots/ads-ui-create-wizard.png
```

## تست syntax

```bash
php -l views/user/ads/index.php
php -l views/user/ads/create.php
php -l views/partials/user/sidebar.php
php -l views/partials/admin/sidebar.php
php -l app/Services/AdSystemManager.php
node --check public/assets/js/views/useradsindex.js
node --check public/assets/js/views/useradscreate.js
```

همه PASS شدند.

## وضعیت بعد از این مرحله

اکنون Ads از نظر backend، finance، admin cleanup و UI تبلیغ‌دهنده تکمیل شده‌تر است:

```text
/ads        مدیریت کارت‌محور کمپین‌ها
/ads/create Wizard تمیز و فقط active-step
/ads/{id}   جزئیات مالی/escrow/refund
admin ads   unified actions + specialized cleanup
```

اگر بخواهیم بعداً UI را بیشتر polish کنیم، می‌توانیم نمودار کوچک performance یا grouped filters پیشرفته‌تر اضافه کنیم، اما نسخه فعلی از نظر layout/typography و تست مرورگر قابل قبول است.


## اصلاحات تکمیلی بعد از بازبینی کاربر

### 1. رفع خطای `spent_budget`

روی دیتابیس‌هایی که migration فاز ۴ هنوز اجرا نشده باشد، ستون زیر ممکن است وجود نداشته باشد:

```text
ads.spent_budget
```

قبلاً summary صفحه `/ads` مستقیم این ستون را در SELECT می‌خواند و خطا می‌داد:

```text
SQLSTATE[42S22]: Unknown column spent_budget
```

اصلاح شد:

```php
AdSystemManager::getAdSummary()
```

حالا قبل از استفاده از ستون، وجود آن را از `information_schema.COLUMNS` بررسی می‌کند و اگر وجود نداشت، از fallback زیر استفاده می‌کند:

```sql
total_budget - remaining_budget
```

### 2. رفع مشکل نمایش فرم جزئیات Wizard

مشکل:

بعد از انتخاب نوع تبلیغ، مرحله «جزئیات تبلیغ» فعال می‌شد ولی فرم dynamic به خاطر CSS/JS مخفی می‌ماند.

اصلاح شد:

- در `useradscreate.js` هنگام فعال‌شدن step 2، کلاس `active` روی `#dynamicForm` اعمال می‌شود.
- در `wizard.css` فقط wizard panel فعال نمایش داده می‌شود و فرم dynamic داخل step فعال visible است.

تست مرورگر بعد از انتخاب نوع `banner` نشان داد فیلدهای زیر واقعاً visible هستند:

```json
[
  "title",
  "placement",
  "target_link",
  "image",
  "budget",
  "start_date",
  "end_date"
]
```

اسکرین‌شات اضافه‌شده:

```text
tools/browser-preview/screenshots/ads-ui-create-details.png
```

## اصلاح Hub طبق بازبینی نهایی کاربر

### Sidebar تک‌لینک شد

در sidebar کاربر، بخش تبلیغات دیگر زیرمنوی جدا برای مدیریت/ثبت ندارد. فقط یک لینک دارد:

```text
تبلیغات → /ads
```

داخل خود صفحه `/ads` دو بخش وجود دارد:

```text
مدیریت تبلیغات
ثبت تبلیغ جدید
```

### سوییچ داخلی بدون reload

در `/ads`، دکمه‌های داخلی زیر با JavaScript بین panelها جابه‌جا می‌شوند:

```text
[data-ads-panel="manage"]
[data-ads-panel="create"]
```

و تست مرورگر تأیید کرد:

```json
"samePageNoReload": true
```

یعنی کاربر از همان صفحه `/ads` وارد مدیریت یا ثبت می‌شود و reload/route جدا لازم نیست. `/ads/create` برای سازگاری باقی است، ولی سناریوی اصلی از Hub انجام می‌شود.

### اصلاح رد پای sidebar و فاصله footer

برای layout بدون sidebar این قواعد اضافه شد:

```css
body.layout-no-sidebar .main-content { margin-right: 0 !important; }
body.layout-no-sidebar .content-wrapper { padding-inline: 24px; }
body.layout-no-sidebar .bn-navbar { padding-inline: 24px; }
```

این باعث شد فضای خالی سمت sidebar و فاصله اشتباه footer در صفحات Ads حذف شود.

### متن‌های ساده فارسی

عبارت‌های فنی/انگلیسی برای کاربر عادی حذف یا ساده شدند:

```text
customtask → تسک‌های سفارشی
SocialTask → تبلیغات شبکه‌های اجتماعی
proof schema → نوع مدرک موردنیاز
escrow → نگهداری امن بودجه
```

### تست نهایی دقیق

اسکریپت:

```bash
node /home/user/browser-test/ads-ui-advertiser-preview.js
```

موارد تست‌شده:

- ورود به `/ads`
- وجود فقط لینک فارسی «تبلیغات» در منوی کاربر
- سوییچ از مدیریت به ثبت بدون reload صفحه
- فعال شدن panel ثبت در همان صفحه
- انتخاب نوع `banner`
- نمایش واقعی فیلدهای جزئیات:

```json
[
  "title",
  "placement",
  "target_link",
  "image",
  "budget",
  "start_date",
  "end_date"
]
```

نتیجه:

```json
{
  "ok": true,
  "samePageNoReload": true,
  "failedRequests": [],
  "errors": []
}
```
