# فاز ۴ Content — پنل مدیریت و پاک‌سازی اکشن‌های ادمین

تاریخ: ۱۴۰۵/۰۳/۳۰

## هدف
تکمیل سطح مدیریت ماژول «کسب درآمد از محتوا» بر اساس معماری Hub & Spoke، بدون ساخت متد یا Route عجولانه و با تفکیک سطح مسیرها.

## Routeهای مدیریت
همه مسیرهای زیر به‌عنوان `PRIMARY / ADMIN_ONLY` تثبیت شدند:

- `GET /admin/content`
- `GET /admin/content/revenues`
- `GET /admin/content/export`
- `POST /admin/content/bulk-approve`
- `POST /admin/content/bulk-reject`
- `GET /admin/content/{id}`
- `POST /admin/content/{id}/approve`
- `POST /admin/content/{id}/reject`
- `POST /admin/content/{id}/publish`
- `POST /admin/content/{id}/suspend`
- `GET /admin/content/{id}/revenue/create`
- `POST /admin/content/{id}/revenue/store`
- `POST /admin/content/revenue/{rid}/approve`
- `POST /admin/content/revenue/{rid}/pay`

## تغییرات Backend

### `Admin\ContentController`
- پاسخ اکشن‌های مدیریت به JSON پایدار تبدیل شد.
- خطاهای BusinessException دیگر به پیام عمومی «خطا در ...» تبدیل نمی‌شوند و پیام دقیق کسب‌وکاری به UI برمی‌گردد.
- `publish` اکنون `channel_name` را هم به سرویس منتقل می‌کند.
- `storeRevenue` همچنان از `ContentService::createRevenue` و idempotency استفاده می‌کند.
- `revenues` آمار مالی را از `ContentRevenue::getFinancialStats` به صفحه منتقل می‌کند.
- `auth()->user()` در حالت تست/بک‌دور null-safe شد.

### `ContentService`
- `publishSubmission` پارامتر اختیاری `channelName` گرفت و در `content_submissions.channel_name` ذخیره می‌شود.
- catchهای BusinessException برای `approve/reject/publish/createRevenue/suspend` حفظ شدند تا پیام دقیق از بین نرود.
- `suspendSubmission` فقط برای محتوای `approved` یا `published` مجاز است.

### مدل‌ها
- `ContentSubmission::findWithUser/getAll` وابستگی به ستون غیرقطعی `users.phone` را حذف کرد.
- `ContentSubmission::update` استفاده از `\PDOStatement` را namespace-safe کرد.
- `ContentRevenue::getAll` ستون `title` را برای سازگاری UI درآمدها expose می‌کند.
- `ContentRevenue::countAll` فیلتر `period` را مثل `getAll` اعمال می‌کند.

### Navbar ادمین
- در `views/partials/admin/navbar.php` چند attribute تکراری `class` اصلاح شد.
- تگ `</header>` که باعث قرار گرفتن محتوای صفحه داخل topbar و جابه‌جایی شدید UI می‌شد اضافه شد.

## تغییرات UI

### صفحات بازطراحی‌شده
- `views/admin/content/index.php`
- `views/admin/content/show.php`
- `views/admin/content/revenues.php`
- `views/admin/content/revenue-create.php`
- `views/admin/content/_admin-nav.php`
- `public/assets/css/views/admincontent.css`
- `public/assets/js/admin/content.js`

### ویژگی‌ها
- Hub داخلی ادمین برای:
  - بررسی محتواها
  - درآمدها
- کارت‌های آماری دقیق و کم‌حجم.
- متن‌های فارسی و قابل‌فهم به‌جای اصطلاحات فنی خام.
- اکشن‌های اصلی:
  - تأیید محتوا
  - رد محتوا
  - ثبت انتشار
  - تعلیق
  - ثبت درآمد
  - تأیید درآمد
  - پرداخت درآمد
- فرم ثبت درآمد با پیش‌نمایش سهم سایت، سهم کاربر، مالیات و خالص پرداختی.
- JS مقاوم در برابر خطای شبکه، timeout و پاسخ غیر JSON.

## تست‌ها

### Syntax
- `php -l app/Controllers/Admin/ContentController.php`
- `php -l app/Services/ContentService.php`
- `php -l app/Models/ContentSubmission.php`
- `php -l app/Models/ContentRevenue.php`
- `php -l views/admin/content/*.php`
- `php -l views/partials/admin/navbar.php`
- `node --check public/assets/js/admin/content.js`

### DB Regression
- `php tools/content-phase2-revenue-flow-db-test.php` → PASS

### Browser / HTTP
- `content-phase4-admin-preview.js` → PASS
- `content-phase4-admin-actions-http-test.js` → PASS
- `content-phase1-submit-test.js` با user جدید → PASS

### Screenshotها
- `tools/browser-preview/screenshots/content-phase4-admin-index.png`
- `tools/browser-preview/screenshots/content-phase4-admin-show.png`
- `tools/browser-preview/screenshots/content-phase4-admin-revenue-create.png`
- `tools/browser-preview/screenshots/content-phase4-admin-revenues.png`

## نکته باقی‌مانده پیشنهادی
`ContentEventListeners` هنوز منطق legacy برای واریز کیف پول در handler قدیمی `handleContentRevenuePaid` دارد. در حال حاضر مسیر فعال پرداخت از `ContentService::payRevenue` مستقیم، اتمیک و idempotent است و event جدید `content.revenue.payment_recorded` ثبت می‌کند؛ اما برای جلوگیری از فعال شدن تصادفی مسیر قدیمی در آینده، فاز بعدی بهتر است پاک‌سازی/غیرفعال‌سازی صریح listener پرداخت legacy باشد.
