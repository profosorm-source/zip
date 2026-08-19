# فاز ۲ اینفلوئنسر — Hub تک‌صفحه‌ای مستقل

تاریخ: ۱۴۰۵/۰۳/۳۱

## هدف
پس از فاز ۱ که منطق مالی را escrow-based کرد، فاز ۲ تجربه اصلی کاربر را به یک صفحه مستقل تبدیل کرد:

```text
/influencer
```

این صفحه هویت بصری مستقل دارد و از Wizard تبلیغات Ads جداست.

## تصمیم‌های اجراشده

### 1. Hub تک‌صفحه‌ای
صفحه `/influencer` اکنون تب‌های داخلی دارد:

- داشبورد
- پیج من
- سفارش‌های دریافتی
- سفارش تبلیغ
- سفارش‌های من
- اختلاف‌ها

جابجایی بین تب‌ها بدون reload انجام می‌شود.

### 2. مسیرهای قدیمی به Hub هدایت شدند
GETهای قدیمی که قبلاً صفحه‌های جداگانه بودند، حالا compatibility redirect هستند:

```text
/influencer/register        → /influencer?section=profile
/influencer/orders          → /influencer?section=incoming
/influencer/ads             → /influencer?section=market
/influencer/ads/create      → /influencer?section=market&influencer_id={id}
/influencer/ads/my-orders   → /influencer?section=placed
```

POSTها برای سازگاری و عملیات واقعی حفظ شدند.

### 3. فرم سفارش داخل Hub
فرم ثبت سفارش اینفلوئنسر داخل همان صفحه قرار گرفت و با AJAX ارسال می‌شود:

```text
POST /influencer/ads/store
```

بعد از ثبت موفق، سفارش در تب «سفارش‌های من» دیده می‌شود.

### 4. فرم ثبت/ویرایش پیج داخل Hub
اگر کاربر هنوز پروفایل نداشته باشد، فرم ثبت پیج در تب «پیج من» نمایش داده می‌شود. برای پروفایل‌های موجود، وضعیت و روند تأیید با اسکرین‌شات در همان تب مدیریت می‌شود.

### 5. حفظ استقلال از Ads
هیچ تغییری در `AdSystemManager` یا Wizard تبلیغات اعمال نشد. Influencer همچنان یک ماژول مستقل است.

## فایل‌های تغییرکرده

```text
app/Controllers/User/InfluencerController.php
views/user/influencer/my-profile.php
public/assets/css/views/userinfluencerhub.css
public/assets/js/views/userinfluencerhub.js
```

## تست‌ها

### Syntax

```bash
php -l app/Controllers/User/InfluencerController.php
php -l views/user/influencer/my-profile.php
node --check public/assets/js/views/userinfluencerhub.js
```

### DB Regression

```bash
php tools/influencer-phase1-escrow-flow-db-test.php
php tools/influencer-phase1-screenshot-verification-db-test.php
php tools/influencer-phase1-dispute-escrow-db-test.php
```

### Browser

```bash
node /home/user/browser-test/influencer-phase2-hub-preview.js
node /home/user/browser-test/influencer-phase2-hub-actions.js
```

نتیجه:

```json
{ "ok": true }
```

سناریوهای مرورگری تست‌شده:

- انتخاب اینفلوئنسر و ثبت سفارش از داخل Hub
- نمایش سفارش ثبت‌شده در تب «سفارش‌های من»
- قبول سفارش توسط اینفلوئنسر از تب «سفارش‌های دریافتی»
- ثبت مدرک انتشار از داخل Hub
- تأیید سفارش توسط تبلیغ‌دهنده و تسویه escrow

اسکرین‌شات‌ها:

```text
tools/browser-preview/screenshots/influencer-phase2-hub-market.png
tools/browser-preview/screenshots/influencer-phase2-hub-placed.png
```

## نکته باقی‌مانده برای فاز بعد
صفحه dispute detail هنوز مسیر اختصاصی خودش را دارد:

```text
/influencer/orders/{id}/dispute
```

برای فاز بعد می‌توان آن را نیز داخل Hub یا modal/section مستقل ادغام کرد. همچنین پنل ادمین اینفلوئنسر هنوز UI قدیمی دارد و باید در فاز جداگانه بازطراحی شود.
