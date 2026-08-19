# قواعد و استانداردهای پروژه «چورته» (Chortke) — مرجع الزامآور برای هر اصلاح

> این فایل، مرجعِ تثبیتشدهی قواعد کاربر است. **قبل از هر اصلاح، باید به این فایل مراجعه شود**
> و پس از هر اصلاح، کد تولیدشده باید با این قواعد بازبینی (review) شود.
> هر اصلاحی که با این قواعد ناسازگار باشد، ممنوع است.

---

## ۱. قواعد عمومی / محوری (اصلیترین خواستههای کاربر)

1. **اصلاح ریشهای و استاندارد** — هدف فقط «رفع خطا» نیست؛ هر خطا باید به شکلِ اصولی و استاندارد حل شود.
   - ممنوع: راهمیانبر، پچ پینهای، «نرمالیزهکردن برای جور شدن با کد فعلی»، یا «سازگار کردن با کد موجود» فقط برای بستن چشمِ تحلیلگر.
2. **ممنوعیت معماری DTO** — در این پروژه از معماری/الگوی DTO استفاده نشده و **نباید** استفاده شود.
3. **تا حد ممکن فایل سورس جدید ساخته نشود** — ساخت فایلهای سورس جدید ممنوع است، مگر در مواردی که واقعاً ضروری و مستدل باشد.
   - استثنا: فایلهای تست در `tests/` مجازند.
4. **«تکمیل PHPDoc» خودِ «رفع» است** — پرکردن نوعداک و تایپدهیِ درست، نه مستندسازی زائد؛ بخشی از اصلاحِ اصولی است.
5. **تمرکز تبدیل در مرزِ شکلگیری داده** — اگر نوع یک مقدار در چند نقطهی مصرف غلط است، تبدیل را در **همان جایی که مقدار شکل میگیرد** انجام بده، نه در هر محل مصرف.
   - (مثال عینی: از `array_map(...)` داخل `fputcsv` بهعنوان وصله پینهای استفاده نشود؛ تبدیل باید در ساختار اصلی داده صورت گیرد.)
6. **بعد از هر اصلاح، چرخهی همان بخش بازبینی شود** — مطمئن شو هیچ خطا/مشکل جدیدی وارد نشده است (بدون رگرسیون).
7. **تستهای حرفهای** — تستها باید رفتاری، لبهها (edge cases) و قراردادها (contracts) را بپوشانند؛ **صرفاً «تستِ کارِ ساده» ممنوع**.
8. **ممنوعیت کامل `ignore`** — نباید به کانفیگ PHPStan `ignoreErrors` اضافه شود و نباید `@phpstan-ignore` در کد استفاده شود.
   - سطح هدف: **PHPStan Level 9 بدون ignore**.
9. **رفع نویزها و افزایش خوانایی** — اینها بخشی از کار هستند (ادامه دارد)، نه کارِ درجهی دوم.
10. **هیچ‌وقت کیفیت و دقت را فدای سرعت نکن** — مهم‌ترین قانون. هیچ اصلاحی، حتی اگر با عجله یا تحت فشارِ حجم کار باشد، نباید به قیمت کاهش کیفیت/دقت انجام شود. اگر لازم باشد سرعتِ انجام را کاهش می‌دهیم ولی هرگز از عمقِ اصلاحِ ریشه‌ای، دقتِ تایپ‌دهی، پوششِ تست و بازبینیِ رگرسیون کم نمی‌کنیم.

---

## ۲. قواعد روش انجام اصلاح (فرایند)

1. **هر بار فقط یک فایل/بخش** اصلاح شود؛ بعد از هر فایل:
   - `php -l <file>` (بررسی سینتکس)
   - اجرای PHPStan بدون-ignore روی همان فایل (یا کل)
   - اجرای تستِ مرتبط (در صورت وجود / ساخت تست جدید)
2. **ویرایش دستی، نه با اسکریپت/Python** — اصلاح فایلها باید بهصورت دستی با `edit_file` انجام شود؛ استفاده از Python برای بازنویسی/باتچِ فایلها ممنوع است.
3. **واحدهای ساخت (files) در یک نوبت کم باشد** و هر بار تأیید شود.
4. **بررسی تستِ فایلِ اصلاحشده (قانون دائمی):** برای **هر فایلی که اصلاح میشود**، تستِ مرتبط با آن (اگر وجود دارد) را باز کن و ببین ساده/سطحی است یا حرفهای:
   - اگر ساده است (فقط `instantiation`/`method_exists`/`assertTrue(true)`/چکِ معماریِ سطحی) → **در همان قدم، به تست رفتار/لبه/قرارداد حرفهای ارتقا بده** (با Mockery).
   - اگر تستِ مرتبط وجود ندارد → در صورتِ منطقِ معنادار (رفتار/لبه/قرارداد)، تست حرفهای **ایجاد کن**.
   - این کار را همیشه و بهموازات اصلاحِ کد انجام بده، نه بعداً.

---

## ۳. الگوهای رفعِ تأییدشده (که جواب دادهاند)

- **`toObject()` در سرویسها** → خروجی را `?\stdClass` کن و روی خروجی `@var \stdClass` بگذار تا propertyهای dynamic شناخته شوند.
- **`Model::find`** → `?\stdClass` (چون `normalizeToObject` stdClass میسازد).
- **`Model::create`** → `@return int|false`.
- **`Cache::getInstance` / `EventDispatcher::getInstance`** → تایپ singleton درست شود.
- **cast های `mixed`** → با `is_scalar` / `is_numeric` امن کن، نه cast کور.
- **`run` / `runWithRetry` در `core/TransactionWrapper.php`** → با `@template T` جنریک شود (به همه call-sites کمک میکند).
- **`DistributedLockService::synchronized`** → با `@template T` جنریک شود.
- **dead-code `stmt instanceof \PDOStatement`** → حذف شود (چون `Database::query()` همیشه `\PDOStatement` برمیگرداند).
- **متد `create()` در مدلهای مشتق** که بهجای `int|false` (قرارداد `Core\Model::create`) آبجکت برمیگرداند → **تغییر نام به نامِ دامنهای** (مثلاً `createDispute`، `createStoryOrder`) + بهروزرسانی callers. این override ها از نظر معنایی با والد ناسازگارند و نباید همان نام را حفظ کنند (نمونهٔ تثبیتشده: حذف override مرده در `InfluencerModel`).
- **نوع بازگشتی متدهای مدل** که `normalizeToObject`/`FETCH_OBJ` برمیگردانند → `?\stdClass` (نه `?object`)؛ چون دادهٔ واقعی stdClass است. این تایپِ دقیقتر، null-dereference های واقعی را در callers آشکار میکند که باید با guard مناسب رفع شوند.
- **`Request::param()`** → `?string` (مسیرهای route همیشه رشتهاند)؛ فقط باید guard شود که مقدار غیررشتهای به default برگردد.

---

## ۴. دستهبندی خطاهای PHPStan لول-۹ (برای اولویتبندی)

- **باگ واقعی** (undefined method کلاس واقعی، `resource|false` در fopen/fputcsv/fwrite، `json_decode string|false`، `date int|false`، ترتیب catch، dead catch، خطای پارامتر نادیدهگرفتهشده و…) → **بالاترین اولویت**، باید ریشهای رفع شوند.
- **no-value-type** (انواع بدون مقادیرِ جنریک) → دستهی بزرگ (در ابتدا 2187 مورد).
- **نویزها** (unused، never-read، always-true، Unreachable، `Redis|null`) → عمداً در دستههای جداگانه نگهداشته شده و جداگانه بررسی میشوند.

---

## ۵. نکات محیطی (برای از سرگیری کار پس از reset)

- OS: Debian 13، PHP 8.4.23، Composer 2.8.8، PHPStan 1.12.33، MariaDB 11.8.6، Redis 8.0.2.
- بعد از reset: `sudo -n apt-get install -y --no-install-recommends php-cli php-bcmath php-mbstring php-xml php-curl php-zip php-sqlite3 php-mysql php-redis composer mariadb-server mariadb-client redis-server`
- `sudo -n service mariadb start` ، `sudo -n service redis-server start`
- `/etc/hosts` شامل `127.0.0.1 redis` باشد.
- دیتابیس: `CREATE DATABASE IF NOT EXISTS chortk ...` و root برای TCP باز شود؛ سپس `php migrate.php` و `php database/migrations/2026_06_16_0005_seed_initial_data.php`.
- کانفیگ بدون-ignore در `/tmp/phpstan-noignore.neon` (بعد از reset دوباره ساخته شود — محتوای آن در انتهای همین فایل).
- پروژه در `/home/user/chortke`.

### ۵-الف) بازیابی سریع محیط پس از reset (راهکار بکاپ/بازگردانی)
برای اینکه بعد از هر reset محیط خیلی سریع به حالت آماده برگردد، از **اسکریپت آمادهسازی یکباره** استفاده میشود:
- **اسکریپت**: `/home/user/chortke/scripts/env_backup.sh` (ساخت و نگهداری).
- **وظیفه**: نصب برنامهها (php-cli، bcmath، mbstring، xml، curl، zip، sqlite3، mysql، redis، composer، mariadb، redis-server) → بالا آوردن سرویسها → تنظیم root/DB → migrate + seed → ساخت کانفیگ `/tmp/phpstan-noignore.neon` → ذخیرهی snapshot از دیتابیس (`scripts/db_backup.sql.gz`) و لیست بستههای نصبشده (`scripts/apt-packages.txt`).
- **بازگردانی سریع** (پس از reset): اجرای همین اسکریپت با پرچم `--restore` که بهجای migrate/seed دوباره، snapshot دیتابیس را برمیگرداند و از `apt-packages.txt` برای نصب نرمافزارها استفاده میکند. این کار مدتِ «آمادهسازی + migrate + seed» را به چند ثانیه کاهش میدهد.
- نکته: دایرکتوری `scripts/` جزو workspace ماندگار است؛ اسکریپت باید `idempotent` باشد (اجرای چندباره خطا ندهد).

---

## ۶. کانفیگ PHPStan بدون-ignore (الگو)

```neon
parameters:
    level: 9
    paths: [/home/user/chortke/app/, /home/user/chortke/core/]
    excludePaths: [/home/user/chortke/app/Views/*, /home/user/chortke/app/Cache/*, /home/user/chortke/core/Cache/*]
    bootstrapFiles: [/home/user/chortke/phpstan-bootstrap.php]
    stubFiles: [/home/user/chortke/phpstan-stubs.php]
    reportUnmatchedIgnoredErrors: false
    treatPhpDocTypesAsCertain: false
    universalObjectCratesClasses: [stdClass]
    parallel: {maximumNumberOfProcesses: 1, processTimeout: 300.0}
```

---

## ۷. سیاست `.env` و امنیت پایگاه
- **فایل `.env` حقیقی هرگز در repo نمی‌ماند** — تست `ComprehensiveSecurityTest::http_only_cookie_flag_is_configured` آن را الزام می‌کند.
- کانفیگِ توسعه‌دهنده در **`.env.local`** (که `bootstrap/app.php` ترجیح می‌دهد) قرار می‌گیرد؛ `.env` و `.env.production.example` فقط به‌عنوان نمونه/مستند می‌مانند.
- تست‌ها خودکفا هستند و کانفیگ را از `bootstrap/testing.php` (از طریق `$GLOBALS['env']`) می‌گیرند، نه از `.env`.

## ۸. اجرای کامل تست + ارتقای تست‌های ساده
- **بعد از هر اصلاح، کل `phpunit` اجرا شود** (نه فقط تست مرتبط) تا تمام خطاها/رگرسیون‌ها شناسایی شوند.
- پیش‌نیازِ اجرای Integration: دیتابیس MariaDB + seed (migrate) + سرویس‌ها بالایند؛ وگرنه ۴۴+ خطای «Connection refused» ظاهر می‌شود.
- **تست‌های موجودِ ساده** (مثل فقط «instantiation» یا «method_exists») **به‌تدریج همراه اصلاحات ارتقا یابند** به تست‌های حرفه‌ایِ رفتار/لبه/قرارداد (با Mockery).
- الگو: mock کردن وابستگی‌ها (Model/DB)، آزمودن delegate، تست لبه‌ها (لیست خالی، null، شکست)، و قراردادهای امنیتی (مثل whitelist ستون‌ها).

## ۹. قابلیت «تبلیغ نوتیفیکیشنی + آمار engagement» (معماری پیاده‌شده)
- **ارسال:** `AdNotificationDispatcher::processAdNotifications()` کرونِ `ad_notification_push`؛ با `data[ad_id]` به `sendBulk`. `NotificationService::processSinglePersist` مقدار `ad_id`/`campaign_id` را از `data` استخراج و در ستون‌های جدول `notifications` ذخیره می‌کند.
- **مشتری/هدف:** نوتیف با `ad_id` در جدول `notifications` ثبت می‌شود.
- **رویدادهای engagement از سمت اپ:** اندپوینت‌های `POST /notifications/events/{shown|opened|closed|dismissed}` ستون‌های `shown_at/opened_at/closed_at/dismissed_at/read_duration_sec/engagement_source` را ثبت می‌کنند. اگر اپ رویداد نفرستد، `recordClosed` مدت را از `opened_at` محاسبه می‌کند (fallback امن).
- **آمار per-ad تبلیغ‌دهنده:** `Notification::getAdAnalytics($adId,$days)` — شامل sent/read/clicked/dismissed، نرخ‌ها، میانگین/حداکثر مدت خواندن، و **دسته‌بندیِ زود(<5s)/متوسط(5–30s)/دیر(>30s) بستن** + نرخ‌های تفسیری. اندپوینت `GET /ads/{id}/notification-stats` و پنل در `views/user/ads/show.php`.
- **aggregation ادمین:** کرون `notification_analytics` (ساعتی) → `NotificationService::runBatchAggregation()` → `Notification::aggregateDailyAnalytics()` برای دیروز، در جدول `notification_analytics` (migration: `2026_08_03_0001_notification_ad_analytics.sql`).

## ۱۰. واریز پاداش به بیننده‌ی نوتیفیکیشن تبلیغاتی (شکاف بسته‌شده)
- **شکافِ مالی (که بررسی شد):** پیش از این، مسیر `notification` فقط بودجه‌ی تبلیغ‌دهنده را مصرف می‌کرد (consumeDeliveryBudget) و **هیچ پاداشی به بیننده واریز نمی‌شد** — برخلاف `adtube` که reward دارد.
- **رفع:** `AdsBudgetSettlementService::settleNotificationView($adId, $viewerUserId, $eventType, $notificationId)` — در یک تراکنش: بودجه‌ی تبلیغ‌دهنده را مصرف و پاداش (= هزینه‌ی delivery/click) را با `walletService->deposit` به بیننده واریز می‌کند. **ایدمپوتنت** است (بر اساس `notification_reward:<notifId>`).
- **اتصال:** در `User\NotificationController`، `trackAdNotificationClick` (برای کلیک) و `settleAdNotificationReward` (برای shown/opened) → `settleNotificationView`.
- **تست‌ها:** `AdsBudgetSettlementNotificationTest` (واریز پاداش، مصرف بودجه، ایدمپوتنسی، لبه‌ها).
- نکته: خطای محیطی «سیستم در حال حاضر شلوغ است» از `IdempotencyService` (وابسته به Redis/قفل) می‌آید و نقصِ منطق نیست.

## ۱۱. تأییدِ جداول و کرون/جاب‌ها (بازبینی مجدد)
- **جداول (schema دقیق تأیید شد):**
  - `notifications` + ستون‌های `ad_id, campaign_id, shown_at, opened_at, closed_at, dismissed_at, read_duration_sec, engagement_source` + ایندکس‌های `idx_notif_ad_eng(ad_id,is_read)` و `idx_notif_created(created_at)` ✓
  - `notification_analytics` با PK `(date,type,channel)` و ستون‌های `ad_sent/ad_read/ad_click` ✓
  - جداول پشتیبان `ad_delivery_events` و `escrow_transactions` موجودند ✓
- **کرون/جاب‌ها (همه به متدهای موجود متصل):**
  - `ad_notification_push` → `AdNotificationDispatcher::processAdNotifications()` ✓
  - `notification_scheduled` → `NotificationModel::getPendingScheduled()` + `markAsSent()` ✓
  - `notification_expire` → `NotificationModel::archiveExpired()` ✓
  - `notification_analytics` → `NotificationService::runBatchAggregation()` ✓
  - `notification_cleanup` → `NotificationCleanupJob` ✓
- **زنجیره‌ی پاداش:**
  - ارسال اولیه: `consumeDeliveryBudget(actor=null)` فقط بودجه کسر (کاربر هنوز ندیده — درست).
  - دیده‌شدن/بازکردن/کلیک: `settleNotificationView(actorUserId)` → بودجه + **واریز پاداش به بیننده** ✓
- **تأیید زنده:** دسته‌بندی زود(<5s)/متوسط(5–30s)/دیر(>30s) با داده‌ی واقعی (مدت ۲/۱۵/۶۰ → fast=1,medium=1,deep=1) ✓

## ۱۲. یکپارچه‌سازی Dispute (تصمیم معماری کاربر)
- **تصمیم:** همه ماژول‌ها باید از سرویس/جدول یکپارچه‌ی `DisputeService` (جدول `disputes` با `ref_type` polymorphic) استفاده کنند — نه جدول‌های موازی.
- **SocialTask:** مکانیزمِ چرخشِ خودکار دارد (یا انجام می‌شود یا رد) و بخش داوری اعتراض ندارد → **کل بخش dispute آن حذف شد** (`task_disputes` جدول + کدها). migration: `2026_08_03_0002_remove_task_disputes.sql`.
- **Vitrine:** جریان open + resolve مالی کامل خودش را دارد؛ اکنون به `DisputeService` وصل شد:
  - `VitrineService::openDispute` → `DisputeService::openCase()` با `ref_type='vitrine_listing'` (رکورد در جدول `disputes`).
  - `VitrineService::resolveDispute` → بعد از settlement، `Dispute::resolveByRef('vitrine_listing', ...)` پرونده را `resolved_admin` می‌کند.
- `Dispute::resolveByRef()` متد جدید برای بستن پرونده بر اساس ref_type+ref_id (idempotent).
- ref_typeهای استفاده‌شده در جدول `disputes`: `order`/`story_order`/`influencer_order` (اینفلوئنسر)، `custom_task_submission` (تسک سفارشی)، `vitrine_listing` (ویترین).
- **`DisputeService::listForAdmin()` یکپارچه شد:** حالا همه‌ی ref_typeها را نشان می‌دهد (LEFT JOIN به story_orders/vitrine_listings/custom_task_submissions) و فیلتر `ref_type`/`status` را پشتیبانی می‌کند؛ پنل ادمین (`admin/custom-tasks/disputes`) ستون «ماژول» و دراپ‌داون فیلتر ماژول دارد.
- جدول‌های موازیِ مرده حذف شدند: `task_disputes` و `vitrine_disputes` (migration های `0002` و `0003`). `VitrineListing::getUserDisputesCount` به جدول unified `disputes` وصل شد.

## ۱۴. داوری یکپارچه ادمین + پیام ویترین + حذف dead code
- **داوری یکپارچه:** `DisputeService::resolveForAdmin($adminId, $disputeId, $meta)` بر اساس ref_type dispatch می‌کند: اینفلوئنسر/سفارش → `adminResolve`، ویترین → `VitrineService::resolveDispute`، تسک سفارشی → `resolveByAdmin`. `ExecutorTaskController::resolveDispute` از آن استفاده می‌کند؛ JS پنل برای ویترین گزینه‌ی seller/buyer نشان می‌دهد.
- **پیام ویترین:** `VitrineService::sendDisputeMessage`/`getDisputeMessages`/`findListingDispute` (از جدول unified `disputes`/`dispute_messages`). اندپوینت‌های `GET/POST /vitrine/{id}/dispute/messages` و `.../message` در `VitrineController` و `routes/missing.php`.
- **حذف dead code:** `Dispute::hasOpenTaskDispute` (ref_type='task' بدون caller) و `DisputeQueryService::rawCustomTaskDisputeList/Count` (بعد از یکپارچه‌سازی listForAdmin بدون caller) حذف شدند.

## ۱۵. سیستم رفرال — بونوس و میلستون (تکمیل شد)
- **`checkAndAwardBonus`:** رفع شد — حالا بونوسِ ثابت کامل را (نه درصدی) با idempotency و واریز مستقیم به کیف پول referrer می‌دهد. تنظیمات `referral_content_approval_amount/currency` seed شدند (migration 0004).
- **`checkAndAwardMilestones`:** بازنویسی شد — میلستون‌های تازه‌رسیده (بر اساس `countReferredUsers` و threshold) را شناسایی و با idempotency (جدول `referral_user_milestones`) پاداش می‌دهد.
- **`getUserAchievedMilestones`:** رفع شد — فقط میلستون‌های واقعاً دست‌یافته را برمی‌گرداند (نه همه‌ی فعال).
- **seed میلستون:** ۴ میلستون واقعی (۱/۵/۱۰/۵۰ معرف موفق) + تنظیمات referral در migration 0004 اضافه شد.
- جدول `referral_user_milestones` (ردیابی idempotent) ساخته شد.
- تست‌ها: `ReferralBonusMilestoneTest` (بدون معرف، پیکربندی‌نشده، duplicate، دست‌یافته).

## ۱۶. چرخه‌ی رفرال — بازبینی کامل و رفع نقص‌ها
- **بونوس ثبت‌نام:** `ProcessRegistrationJob` قبلاً از `processCommission` (درصدی → فقط ۵٪ از ۱۰۰۰ = ۵۰) و کلیدِ ناهماهنگِ `referral_signup_commission_base` استفاده می‌کرد. اصلاح شد:
  - متد جدید `ReferralService::awardSignupBonus()` — بونوس ثابت کامل با idempotency و واریز مستقیم.
  - از کلید صحیح پنل ادمین `referral_signup_bonus`/`referral_signup_bonus_usdt` می‌خواند.
  - seed تنظیمات signup (migration 0005).
- **میلستون خودکار:** `maybeAwardMilestones()` بعد از هر `processCommission`/`checkAndAwardBonus`/`awardSignupBonus` صدا می‌شود تا میلستون‌های معرف خودکار اهدا شوند (نه فقط هنگام بازکردن صفحه).
- **تست زنده:** ۵ معرف → ۵ بونوس ۱۰۰۰ → میلستون‌های threshold ۱ و ۵ خودکار اهدا شدند (idempotent).

## ۱۷. گزارش / زبان

- همهی گزارشها و پاسخها **فارسی**.
- نقش: Software Architect ارشد، متخصص Enterprise و Distributed Systems.
- یادآوری برای مصحح: تکمیل PHPDoc = رفع (نه مستندسازی). هر وصلهی «پینهای» در محل مصرف، با تبدیل در مرزِ شکلگیری داده جایگزین شود.

## ۱۸. استاندارد اعتبارسنجی: FormRequest (تصمیم تأییدشده)
- **تصمیم:** تمام کنترلرها باید اعتبارسنجی ورودی را به کلاس FormRequest (زیرکلاس `App\Validators\BaseFormRequest`) منتقل کنند. استفادهٔ inline از `\Core\Validator::create(...)` داخل کنترلر **ممنوع** است.
- **چرایی:** تفکیک مسئولیت (کنترلر = هماهنگی، نه اعتبارسنجی)، قواعد قابل تست و قابل بازاستفاده، پیام‌های خطای یکدست. این الگو از قبل زیرساخت داشت (`BaseFormRequest` + `ValidatorFactory` + `validateOrFail()`) پس الگوی جدیدی نیست. FormRequest یعنی اعتبارسنجی، نه DTO — با منع DTO (§۱) تعارضی ندارد.
- **الگوی مصرف:** `$req = new XxxRequest($input); $req->validate();` سپس `$req->fails()` / `$req->errors()` / `$req->validated()`. (دقت: `validated()` جایگزین `data()`ی قدیمی Core\Validator است.)
- **انجام‌شده (۹ کنترلر × ۱۳ اندپوینت):** BankCard(store)، Investment(createInvestment/withdraw)، Lottery(join/vote)، Prediction(placeBet)، SocialAccount(store/update)، Influencer(storeOrder)، Content(store)، Payment(deposit)، InteractionApi(toggleFavorite/rate/report). ۱۳ کلاس Request جدید زیر `app/Validators/Requests/` ساخته شد. متد مردهٔ `ContentController::validateStoreInput` حذف شد.

## ۱۹. استاندارد داده‌دسترسی: Model/QueryBuilder در برابر SQL خام (تصمیم تأییدشده)
- **تصمیم:** هیچ ORM سنگینی (Eloquent/Doctrine) اضافه نمی‌شود — با فلسفهٔ پروژه و منع DTO (§۱) در تعارض است و در composer.json هم نیست. استاندارد = `Core\Model` (۹۵ زیرکلاس) + `Core\QueryBuilder`.
- **حالت اصولی (بدون توجه به میزان کار):**
  1. منطق در Service، داده‌دسترسی در Model. کنترلرها هرگز SQL ندارند (وضعیت فعلی: ✅ صفر کنترلر با SQL خام).
  2. `QueryBuilder` پیش‌فرض برای CRUD و پرس‌وجوهای متوسط.
  3. SQL خام فقط برای پرس‌وجوهای واقعاً پیچیده (گزارش/aggregation/JOIN‌های سنگین)، و حتماً **پارامتری** (`?`/named)، کپسوله‌شده درون یک متد Model/گیت‌وی.
  4. ورودی کاربر هرگز داخل رشتهٔ SQL درون‌یابی (interpolate) نمی‌شود. شناسهٔ جدول/ستونِ پویا فقط از whitelist. `whereRawUnsafe` فقط با ورودی کاملاً کنترل‌شده.
- **وضعیت فعلی و کار باقی‌مانده (Big Root-Fix — پرچم‌دار):** SQL خام در ~۹۰ سرویس و ~۸۷ مدل پخش است (همه پارامتری، از انتزاع `Core\Database`). جمع‌وجورکردن داده‌دسترسیِ سرویس‌ها به درون Model‌ها یک برنامهٔ بزرگ است که باید **ماژول-به-ماژول و با اجرای تست** انجام شود (نه در محیطِ بدون اجرای PHP). به فاز باگ موکول می‌شود؛ مواردِ درون‌یابیِ مشکوک جداگانه به‌عنوان باگ امنیتی بررسی می‌شوند.
