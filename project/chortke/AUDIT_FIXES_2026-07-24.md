# ممیزی مستقل فایل `FIXES_2026-07-23.md`

**تاریخ ممیزی:** ۲۴ ژوئیهٔ ۲۰۲۶  
**دامنه:** درخت فعلی پروژه در `/home/user/project/app`، نه صرفاً ادعاهای سند پیوست.  
**قاعدهٔ نتیجه‌گیری:** «تأیید» یعنی از روی کد/اسکیما/اجرای واقعی قابل اثبات است؛ «اثبات‌نشده» یعنی هارنس یا commit متناظر در این درخت وجود ندارد.

## نتیجهٔ کلیدی

سند پیوست را نباید به‌عنوان مدرکِ اعمال‌شدن فیکس‌ها روی این سورس پذیرفت. درخت فعلی با وضعیت ادعاشده در سند همسان نیست:

- اکنون **۱۲۲** migration وجود دارد؛ migration ادعایی `2026_07_24_0001_coupons_updated_at_compat.sql` وجود ندارد.
- `migrate.php` هنوز `MigrationService` مستقل را فراخوانی می‌کند، نه `MigrationManager`.
- `MigrationManager` هنوز کل SQL را با یک `PDO::exec()` اجرا می‌کند.
- `phpstan-baseline.neon` وجود ندارد، `reportUnmatchedIgnoredErrors: false` است و ignoreهای گسترده/کامل برای چند فایل core باقی مانده‌اند.
- هارنس ادعایی `tests-verify/dispute-e2e.php` و هارنس HTTP اختلاف در درخت وجود ندارند.

بنابراین، این سند بیشتر **برنامه/نتیجهٔ یک snapshot دیگر** است تا توصیف وضعیت فعلی این پروژه.

---

## شواهد اجراشده

| بررسی | نتیجه |
|---|---|
| اجرای migration canonical (`php cli.php migration`) روی DB محلی تازه | ۱۲۲ migration ثبت‌شده، ۲۳۷ جدول |
| PHPUnit کامل | ۲۲۱۲ تست، ۴۲۵۴ assertion، **۱ failure** و ۵ skip |
| failure PHPUnit | `ComprehensiveSecurityTest::http_only_cookie_flag_is_configured`: فایل `.env` داخل سورس/آرشیو وجود دارد |
| PHPStan Level 9 | **exit 1**؛ خطای واقعی `SecurityHeadersMiddleware.php:138` |
| تست کنترل‌شدهٔ `PDO::exec()` چنددستوری | exception رخ داد، اما جدولِ دستور اول ساخته شد؛ migration نیمه‌اعمال‌شده ممکن است |
| اسکیما DB تازه | `coupons.updated_at=0`، `disputes.peer_deadline=1` |

### اثبات مهم Migration

روی database disposable این SQL اجرا شد:

```sql
CREATE TABLE migration_audit_first (id INT);
THIS IS INTENTIONALLY INVALID SQL;
CREATE TABLE migration_audit_after (id INT);
```

خروجی: `PDOException`، ولی `migration_audit_first` در DB باقی ماند. پس مدل فعلی `MigrationManager` که SQL چنددستوری را با یک `PDO::exec()` اجرا می‌کند، در صورت خطا می‌تواند DB را **نیمه‌اعمال‌شده** بگذارد. حتی پس از split کردن هم DDL در MySQL/MariaDB عموماً transactional نیست؛ هدف واقع‌بینانه باید **تشخیص قطعی، توقف، ثبت‌نشدن migration و migrationهای idempotent** باشد، نه ادعای rollback کامل DDL.

---

## یافته‌ها و وضعیت آن‌ها

| اولویت | موضوع سند | وضعیت در سورس فعلی | شواهد |
|---|---|---|---|
| P0 | افشای `.env` | **تأیید شد** | `.env` داخل آرشیو وجود دارد و PHPUnit هم آن را failure می‌داند. باید فرض شود credentialهای آن در GitHub/تاریخچه منتشر شده‌اند. |
| P0/P1 | Migration runner دوگانه و unsafe | **تأیید شد** | `migrate.php` → `MigrationService` فقط مسیر SQL؛ `cli.php migration` → `MigrationManager` برای SQL+PHP. `MigrationManager:91` از `PDO::exec($sql)` استفاده می‌کند. |
| P0 | Escrow wallet injection | **تأیید شد** | `EscrowService` پارامتر `$walletService` را می‌گیرد، اما در constructor به `$this->walletService` assign نمی‌کند. |
| P0 | تسویهٔ کامل escrow بدون جابه‌جایی کیف‌پول | **تأیید شد** | `releaseFunds()` وضعیت/ledger/outbox را تغییر می‌دهد ولی seller را credit نمی‌کند. payload آن `seller_id` دارد؛ listener به `recipient_id` وابسته است، در حالی که `EscrowReleasedEvent` نیز `user_id` می‌سازد. |
| P0 (latent) | خلق پول در partial release | **تأیید شد** | شاخهٔ WalletService در `partialRelease()` ابتدا `releaseLockedFunds` (unlock + credit buyer) و سپس `deposit` به seller را صدا می‌زند. این دو عملیات با هم خلق پول می‌کنند. اکنون به علت injection-bug این شاخه عموماً null/fallback است؛ **assign کردن injection بدون جایگزینی هم‌زمان با spendLockedFunds باگ را فعال می‌کند**. |
| P1 | `open_peer` / deadline اختلاف کاستوم‌تسک | **تأیید شد** | `DisputeCommandService` status/deadline می‌فرستد، اما `Dispute::create()` همیشه status را `'open'` INSERT می‌کند و `peer_deadline` را ذخیره نمی‌کند. Cron فقط `open_peer`های منقضی را می‌بیند؛ در نتیجه مسیر auto-escalation مرده است. |
| P1 | توافق/ارجاع اختلاف کاستوم‌تسک | **تأیید شد (غایب)** | `agreeCustomTaskDispute`، `escalateCustomTaskDispute`، کنترلر/routeهای `/agree` و `/escalate`، سقف مبلغ و UIهای ادعاشده در درخت وجود ندارند. |
| P1 | دو resolver اختلاف کاستوم‌تسک | **تأیید شد** | `DisputeCommandService::adminResolve` برای `custom_task_submission` به resolver اختصاصی هدایت نمی‌شود؛ مسیر Shared resolver جداگانه هم وجود دارد. امکان divergence باقی است. |
| P1 | Concurrent request lock دو بار | **تأیید شد** | middleware یک‌بار در global stack `Router` و یک‌بار در `$secure` در `routes/user.php` است. priority registry صرفاً sorting است؛ اجرای واقعی global+route دو بار اتفاق می‌افتد. |
| P1 | guardهای حذف‌شده | **تأیید شد** | حداقل ۹ الگوی قطعی `assignment; { return... }` پیدا شد: ۱ مورد Dispute، ۵ مورد Influencer و ۳ مورد Coupon. این‌ها syntax-error نیستند، اما بدنه را بی‌قید اجرا و flow را می‌شکنند. |
| P1 | Coupon update | **تأیید شد** | `Core\Model::update()` همیشه `updated_at` می‌نویسد؛ ستون `coupons.updated_at` در DB نیست. افزون بر آن، guardهای CouponService فعلاً update/delete/toggle را پیش از عملیات برمی‌گردانند. |
| P2 | PHPStan SecurityHeaders | **تأیید شد** | `array_map('trim', $configCdn)` در line 138؛ PHPStan فعلی دقیقاً همان خطا را گزارش می‌کند. |
| P2 | PHPStan baseline conversion | **اعمال نشده** | baseline وجود ندارد؛ `reportUnmatchedIgnoredErrors` false و ignoreهای broad/path-wide باقی‌اند. |
| P2 | DB type contracts | **اعمال نشده/ناقص** | `Database::fetch(): ?object`، `Model::find(): ?object` و QueryBuilder همچنان contract `stdClass` ادعاشده را ندارند. |
| P2 | Response `never` cleanup | **اعمال نشده** | `Response::send(): void` با وجود throw همیشگی؛ `view()` پس از `send()` return مرده دارد. |
| P2 | RateSubmissionJob null guard | **تأیید شد** | پس از `$taskModel->find()` بی‌درنگ `$task->user_id` خوانده می‌شود؛ task یتیم fatal می‌دهد. |
| P3 | ghost tables/views | **وجودشان تأیید شد؛ حذف‌شدن توصیه نمی‌شود** | migrationها `custom_tasks`، `ratings` و `task_disputes` را می‌سازند؛ view root هم وجود دارد. قبل از حذف باید inventory query/route/job و telemetry تهیه شود. |

### نکات دقیق دربارهٔ اختلافات

- `Dispute::countOpenByUser()` وضعیت‌های جدید مانند `resolved_peer`، `resolved_admin`، `resolved_for_executor` و `resolved_for_advertiser` را از شمارش باز خارج نمی‌کند.
- `Dispute::statusLabel()` هم statusهای جدید را label نمی‌کند.
- transitionهای فعلی نیز تمام گذرهای ادعاشده در سند را ندارند.
- cron/job برای expiry وجود دارد، اما با create فعلی trigger نمی‌شود؛ پس وجود job به معنی سالم‌بودن feature نیست.

### نکات دقیق دربارهٔ Escrow

اصلاح این سه مورد باید **یک atomic change-set** باشد:

1. assignment وابستگی WalletService؛
2. primitive جدید `spendLockedFunds` (فقط `locked -= amount`، بدون credit buyer) در Interface، facade، mutation service و ledger؛
3. استفاده از آن در `releaseFunds` و `partialRelease`، همراه با credit seller در همان تراکنش و idempotency key.

تغییر شمارهٔ ۱ به‌تنهایی خطرناک است، چون شاخهٔ فعلیِ `releaseLockedFunds + seller deposit` را فعال می‌کند.

---

## برنامهٔ پیشنهادی اصلاح

### فاز ۰ — مهار ریسک و خط مبنا (قبل از merge)

1. production freeze برای release/partial-release escrow تا نتیجهٔ audit مالی؛ هرگز با دادهٔ واقعی تست نکنید.
2. از DB production backup رمزنگاری‌شده و restore rehearsal بگیرید؛ سپس inventory escrow/ledger/reconciliation بسازید.
3. همهٔ secretهای موجود در `.env` را **rotate** کنید (DB، Redis، APP_KEY، mail، captcha، OAuth، payment/webhook/API keys). حذف فایل از commit آینده کافی نیست؛ اگر GitHub عمومی بوده، history/archive نیز آلوده است.
4. `.env` را از archive/repository حذف، `.env.example` را نگه‌داری و secret-scanning را در CI فعال کنید (Gitleaks/TruffleHog + pre-commit).
5. branch مستقل، PRهای کوچک و یک staging با MariaDB/Redis واقعی ایجاد شود؛ هیچ فیکس مستقیمی روی production اعمال نشود.

### فاز ۱ — correctness مالی و workflowهای P0/P1

1. **Escrow**: `spendLockedFunds`، idempotency، transaction، ledger double-entry و invariantهای مالی را پیاده‌سازی کنید؛ listener/event را فقط برای notification/outbox پس از commit نگه دارید یا payload را یکپارچه (`recipient_id`) کنید. مسیر refund جداگانه و با matrix مصرف‌کنندگان ممیزی شود.
2. **Custom-task dispute**: persistence واقعی status/role/deadline، transition table واحد، deadline escalation، مسیر role-based agreement/escalation، cap مبلغ، authorization و outbox notification برای دو طرف.
3. resolverهای custom task را به یک service source-of-truth تبدیل کنید؛ resolver عمومی باید آن ref_type را delegate کند یا صریحاً رد کند.
4. ConcurrentRequestMiddleware را از `$secure` بردارید و در global stack نگه دارید؛ release lock در `finally` و رفتار retry/idempotency را تست کنید.
5. ۹+ guard خراب را موردبه‌مورد بازگردانید؛ با regex کور replace نشود. هر مورد باید behavior test داشته باشد.
6. migration سازگار `coupons.updated_at` اضافه و guards CouponService را پیش از migration به‌صورت صحیح اصلاح کنید.

### فاز ۲ — migration و کیفیت type/static

1. یک runner واحد: هر دو entrypoint باید همان `MigrationManager`/shared runner را صدا بزنند؛ pending SQL+PHP را نشان دهند، SQL را statement-by-statement parse کنند و فقط پس از موفقیت کامل در `schema_migrations` ثبت کنند.
2. `getPendingMigrations()`، verification و exit codes قابل‌اعتماد اضافه شود. `getNextBatch()` null-safe گردد.
3. SecurityHeaders closure type-safe شود:
   ```php
   array_filter(array_map(static fn ($v): string => trim((string) $v), $configCdn));
   ```
4. broad ignoreها در PHPStan با baseline استاندارد و reviewable جایگزین شوند؛ `reportUnmatchedIgnoredErrors: true`. baseline نباید جای تست/رفع bug شود.
5. type-contractهای DB را در یک PR مستقل اصلاح کنید. قبل از `?stdClass` شدن parent، مدل‌های `SeoExecution` و `SocialAccount` باید به `findModel()` تغییر نام دهند و تمام call siteها migrate شوند.
6. Response `never` و RateSubmissionJob null guard در PR جدا و کم‌ریسک انجام شوند.

### فاز ۳ — cleanup کنترل‌شده

1. برای ghost tables/views، query log و static call-graph تهیه کنید؛ تا زمان اثبات صفر مصرف، حذف نکنید.
2. debtهای baseline را دسته‌بندی و در PRهای کوچک رفع کنید؛ هر baseline diff باید review شود.

---

## دروازه‌های اثبات عدم‌رگرسیون

«تست‌های سبز فعلی» کافی نیستند: suite فعلی با وجود باگ‌های تأییدشده ۲۲۱۲ تست را تقریباً سبز می‌کند، چون مسیرهای واقعی موردنظر را پوشش نمی‌دهد.

### برای migration

- DB کاملاً خالی: هر دو entrypoint، SQL و PHP migration، count و schema verification یکسان.
- DB نسخهٔ قبل: upgrade idempotent و دادهٔ موجود حفظ شود.
- fixture چند statement با خطای وسط: failure قطعی، migration ثبت نشود، pending باقی بماند و cleanup/recovery procedure مستند باشد.
- backup/restore rehearsal برای migrationهای production.

### برای escrow (اجباری قبل از release)

- invariant: `buyer.balance + buyer.locked + seller.balance + escrow liability` قبل و بعد از هر عملیات طبق مدل حسابداری برابر بماند.
- full release، partial release، refund، retry با همان idempotency key، duplicate outbox delivery و دو درخواست هم‌زمان.
- هر ledger transaction: مجموع debit = مجموع credit؛ reconciliation SQL در CI/staging اجرا شود.
- testها باید real MariaDB transaction و Redis lock داشته باشند، نه فقط mock.

### برای dispute

- E2E واقعی با executor، advertiser و شخص ثالث: open → open_peer → agreement/escalation → admin resolve.
- deadline job، notification/outbox، money cap، authorization، idempotency و transitions نامعتبر پوشش داده شوند.
- HTTP test: یک POST امن باید موفق شود؛ دو POST هم‌زمان باید رفتار تعریف‌شده و غیرتکراری داشته باشند.

### gate عمومی CI/CD

1. `php -l` برای همهٔ فایل‌های تغییرکرده.
2. PHPUnit: صفر failure؛ skipها دلیل‌دار و ثابت، health endpoint با port مشخص بالا آورده شود.
3. PHPStan level 9: exit 0، unmatched ignore = 0، بدون wildcard جدید.
4. migration integration suite با DB تازه و upgrade fixture.
5. secret scanner + check قطعی نبودن `.env` در tracked/archive paths.
6. staging canary، metrics برای queue/outbox/DLQ، alert برای ledger imbalance و rollback plan آزموده‌شده.

## تصمیم پیشنهادی

**پچ‌های سند را کورکورانه apply نکنید.** ابتدا فاز ۰، سپس یک PR مستقلِ financial correctness، سپس dispute/migration و در پایان type/static cleanup. برای هر PR باید testهای بالا قبل/بعد اجرا و artifact خروجی ضمیمه شود.
