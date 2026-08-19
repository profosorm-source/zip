# گزارش فاز ۳ Content — UI/UX مستقل کسب درآمد از محتوا

تاریخ: ۱۴۰۵/۰۳/۳۰ — 2026-06-20

## هدف

بعد از تثبیت route/parse/schema در فاز ۱ و منطق درآمد/پرداخت در فاز ۲، این فاز روی ظاهر و تجربه کاربری ماژول مستقل Content متمرکز بود.

هدف‌ها:

```text
/content          Hub مستقل محتوا
/content/create   ارسال محتوا
/content/revenues درآمدها
/content/{id}     جزئیات محتوا
```

با این اصول:

- Content مستقل از بازار تسک‌ها است.
- صفحه‌ها بدون sidebar سراسری باشند.
- ناوبری داخلی Hub & Spoke داشته باشند.
- متن‌ها ساده و فارسی باشند.
- روز/شب و Material Icons درست کار کنند.
- ظاهر شبیه بقیه ماژول‌های بازطراحی‌شده، اما مخصوص محتوا باشد.

## فایل‌های تغییرکرده

```text
views/user/content/_content-nav.php
views/user/content/index.php
views/user/content/create.php
views/user/content/revenues.php
views/user/content/show.php
public/assets/css/views/usercontenthub.css
```

## اصلاحات UI

### 1. Hub مستقل محتوا

صفحه `/content` حالا یک Hub مستقل است:

- Hero بالای صفحه
- CTA ارسال محتوای جدید
- CTA مشاهده درآمدها
- کارت‌های آماری:
  - کل محتواها
  - در انتظار
  - منتشر شده
  - درآمد دریافتی
- کارت‌های محتوای ثبت‌شده با وضعیت و لینک جزئیات

### 2. ناوبری داخلی ماژول

Partial جدید:

```text
views/user/content/_content-nav.php
```

شامل سه spoke:

```text
محتواهای من
ارسال محتوا
درآمدها
```

همراه با کارت قوانین ساده:

```text
شروع محاسبه درآمد: از ماه سوم
سهم پایه سایت: از تنظیمات
نیازمندی اصلی: تأیید و انتشار
```

### 3. صفحه ارسال محتوا

صفحه `/content/create` بازطراحی شد:

- Hero اختصاصی
- توضیح ساده مسیر درآمد
- فرم ثبت ویدیو با همان IDهای قبلی برای حفظ JS:
  - platform
  - video_url
  - title
  - description
  - agreement_accepted
- تعهدنامه داخل کارت خوانا
- متن‌ها ساده‌تر و کمتر فنی شدند.

### 4. صفحه درآمدها

صفحه `/content/revenues` بازطراحی شد:

- کارت‌های پرداخت‌شده و در انتظار
- کارت درآمدها با:
  - دوره
  - درآمد کل
  - خالص سهم کاربر
  - بازدید
  - وضعیت
- لینک به جزئیات محتوا

### 5. صفحه جزئیات محتوا

صفحه `/content/{id}` بازطراحی شد:

- وضعیت محتوا
- اطلاعات پلتفرم، دسته‌بندی، تاریخ‌ها
- لینک ویدیو و لینک انتشار
- توضیحات و دلیل رد در صورت وجود
- بخش درآمدهای همان محتوا

## Dark/Light

CSS جدید با متغیرهای داخلی `cnt-*` نوشته شد و برای dark mode هم حالت جدا دارد:

```text
[data-theme="dark"] .content-hub-page ...
```

## تست‌ها

### Lint

```bash
php -l views/user/content/index.php
php -l views/user/content/create.php
php -l views/user/content/revenues.php
php -l views/user/content/show.php
php -l views/user/content/_content-nav.php
node --check public/assets/js/views/usercontentcreate.js
node --check public/assets/js/views/usercontentindex.js
```

نتیجه: PASS.

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
  "failedRequests": [],
  "doctypeCount": 1,
  "iconsFontLoaded": true
}
```

اسکرین‌شات‌ها:

```text
tools/browser-preview/screenshots/content-phase1-index.png
tools/browser-preview/screenshots/content-phase1-create.png
tools/browser-preview/screenshots/content-phase1-revenues.png
```

### Regression ثبت محتوا

```bash
node /home/user/browser-test/content-phase1-submit-test.js
```

نتیجه:

```json
{
  "ok": true,
  "success": true,
  "message": "محتوای شما با موفقیت ثبت شد و در صف بررسی قرار گرفت."
}
```

### Regression منطق درآمد و پرداخت

```bash
php tools/content-phase2-revenue-flow-db-test.php
```

نتیجه:

```json
{
  "ok": true,
  "transaction_count": 1
}
```

## وضعیت بعد از فاز ۳

بخش کاربری Content اکنون:

```text
route پایدار دارد
parse error ندارد
ثبت واقعی محتوا کار می‌کند
درآمد/پرداخت DB تست دارد
Hub مستقل و UI بازطراحی‌شده دارد
```

## موارد پیشنهادی بعدی

```text
1. بازطراحی admin content UI
2. پاک‌سازی ContentEventListeners و outboxهای legacy
3. تصمیم نهایی درباره مبنای دقیق دو ماه فعالیت و نمایش progress آن به کاربر
4. گزارش مالی کامل‌تر برای ادمین محتوا
```
