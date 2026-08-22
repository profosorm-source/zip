# گزارش معماری تست و اصلاحات اعمال‌شده

وضعیت: همهٔ مجموعه‌ها سبز، بدون هیچ تست تضعیف‌شده، skip‌شده یا حذف‌شده.

## ۱) نتایج واقعی اجرا

| مجموعه | پیکربندی | نتیجه |
|---|---|---|
| واحد + یکپارچگی | `phpunit.xml` | ۲۱۱۸ تست / ۷۳۹۹ ادعا |
| زمان اجرا | `phpunit.runtime.xml` | OK ۱۲۹۹ / ۴۲۵۸ |
| معماری | `phpunit.architecture.xml` | OK ۸۱۶ / ۳۱۴۱ |
| E2E (HTTP واقعی) | `phpunit.e2e.xml` | OK ۴۲ / ۱۶۸۹ |
| آشوب | `phpunit.chaos.xml` | OK ۸ / ۹۷ |
| قرارداد | `run-contract-suite.sh` | OK ۲۹ / ۳۰۶ |
| Redis خاموش | `phpunit.redis-unavailable.xml` | OK ۳ / ۲۳ |
| تحلیل ایستا | `phpstan.neon` + `phpstan-core-honest.neon` | صفر خطا |

سه تست «skipped» در `phpunit.xml` همان تست‌های `RedisGracefulTest` هستند که
ذاتاً به Redis **خاموش** نیاز دارند و جای درستشان
`phpunit.redis-unavailable.xml` است؛ آنجا هر سه سبز اجرا می‌شوند.

## ۲) اصلاح باگ‌های واقعی سورس

### QueueWorker — فضای‌نام اشتباه `BusinessException` (مهم‌ترین مورد)
`QueueWorker.php` خطوط ۲۹۷/۳۲۹ فقط `\App\Exceptions\BusinessException` را
تطبیق می‌داد، اما ۹۲ مورد از ۱۰۸ پرتاب کسب‌وکاری از **والد**
`\Core\Exceptions\BusinessException` استفاده می‌کنند و والد در `instanceof`
فرزند رد می‌شود. نتیجه: خطاهای قطعیِ کسب‌وکاری به
`['class'=>'unknown','status'=>'pending_analysis']` می‌افتادند و تا
`maxAttempts=5` بی‌فایده retry می‌شدند. `Core\DomainException`،
`InsufficientBalanceException` و `InvalidStateException` هم از قلم افتاده بودند.

اثبات قرمز/سبز: با برگرداندن اصلاح ۵ از ۷ تست شکست می‌خورد و **هیچ ردیفی در
`failed_jobs` ثبت نمی‌شد**؛ با اصلاح، ۷/۷ و ۴۹ ادعا.

### سایر اصلاحات تثبیت‌شده
- **`ExceptionHandler` / `error_logs`**: هر دو مسیر درج، ستون‌های ناموجود
  `level`/`context` را می‌نوشتند → صفر ردیف. به `url`, `method`, `ip_address`,
  `user_agent`, `status='unresolved'` نگاشت شد.
- **نگاشت کد وضعیت**: افزودن PayloadTooLarge→۴۱۳، CircuitBreakerOpen→۵۰۳،
  RateLimitedFailure→۴۲۹، ProviderUnavailable/Transient→۵۰۳،
  ExternalService/PermanentFailure→۵۰۲. ترتیب این شرط‌ها باربر است.
- **`Money`**: رد صریح ورودی float در ۵ نقطه.
- **`ReconciliationService`**: نرمال‌سازی فیلدهای اختیاری `TransactionRow`.
- **`AdvancedFraudMiddleware`**: اصلاح ۵۰۳ نادرست.

## ۳) حذف هاردکد از تست‌ها

اصل راهنما: تست باید از **ابزار، helper و پیکربندی واقعی خود پروژه** بخواند،
نه از مقدار جعلی.

- **آشوب**: پورت‌های ثابت `8093`/`8094` و `usleep` های ثابت جای خود را به
  تخصیص پویای پورت آزاد از سوی سیستم‌عامل + poll تا «شنونده‌شدن/آزادشدن» دادند.
  فلیک برطرف شد و ۵ اجرای پیاپی سبز ماند.
- **هارنس‌های Python/JS**: به‌جای رشته‌های ثابت، از `.env` پروژه می‌خوانند با
  تقدم «متغیر محیطی واقعی → `.env` → پیش‌فرض»، و همگی
  `CHORTKE_E2E_BASE_URL` را محترم می‌شمارند. اتصال دیتابیس هم کاربر واقعی
  `.env` را می‌گیرد (تأیید عملی: ۲۶۱ جدول خوانده شد).
  نکته: `.env.local` مقادیر متناقض `root`/رمز خالی دارد و **نباید** ادغام شود.
- **تست‌های صف**: به `failed_jobs.retry_count` ادعا نمی‌زنیم چون
  `persistFailedJob()` همیشه صفر می‌نویسد؛ تمایزدهندهٔ درست `status` است
  (`quarantined` / `pending_analysis` / `retrying` / `dead_letter`).

## ۴) اصلاح چیدمان `patches/` — ریشهٔ گم‌شدن اصلاحات

`patches/Money.php` و `patches/ReconciliationService.php` **تخت** در ریشه
نوشته شده بودند، در حالی که دستور بازیابی فقط پوشه‌ها را با حفظ مسیر نسبی کپی
می‌کرد؛ پس این دو در هر بازسازی بی‌صدا نادیده گرفته می‌شدند. این — نه
«ترتیب‌وابستگی تست‌ها» — علت آن ۴ شکست به‌ظاهر ناپایدار بود.
یک `patches/MigrationService.php` کهنه هم پیدا شد که هش متفاوتی با نسخهٔ درست
`patches/app/Services/MigrationService.php` داشت و در بازیابی نسخهٔ سالم را
خراب می‌کرد.

اقدام: هر دو مشکل رفع شد و `scripts/provision.sh` تابع `apply_patches()` گرفت
که اصلاحات را **خودکار و با حفظ مسیر نسبی** اعمال می‌کند و اگر فایل PHP تختی
در ریشهٔ `patches/` ببیند اجرا را متوقف می‌کند. آزمون واقعی: از یک unzip تمیز،
هر ۱۲۳ فایل تغییریافته به‌علاوهٔ کانفیگ‌ها و روتر **بایت‌به‌بایت** بازسازی شد،
و نگهبان با یک فایل تخت آزمایشی درست fail کرد.

## ۵) دو تصحیح در ادعاهای خودم

**الف) شکست E2E آواتار، باگ محصول نبود — خطای محیط من بود.**
`/file/view/avatars/<f>` کد ۴۰۴ می‌داد. علت: من سرورها را با
`php -S ... -t public` و **بدون `dev-router.php`** بالا آورده بودم. سرور داخلی
PHP هر URI با پسوند فایل را ایستا تلقی می‌کند و درخواست هرگز به
`public/index.php` نمی‌رسد. شاهد قاطع: بدنهٔ ۴۰۴ صفحهٔ پیش‌فرض خود سرور بود، و
`FileController::deny()` اصلاً ۴۰۳ برمی‌گرداند نه ۴۰۴. با روتر درست، ۲۰۰ شد.

دستور درست (در `provision.sh` هم هست):
`php -d post_max_size=64M -d upload_max_filesize=64M -S 0.0.0.0:<port> -t public dev-router.php`

**ب) ادعای `UserService:85,93` رد شد.**
گفته بودم «بهترین شاهد» است: پرتاب «کد معرف نامعتبر» در
`Admin/UserController.php:139` بدون try/catch → ۵۰۰. اما آن کنترلر
`referral_code_used` را **اصلاً پاس نمی‌دهد**، پس آن پرتاب از این مسیر
دست‌نیافتنی است. تنها مسیری که کد معرف می‌فرستد (`AuthController`) از
`ProcessRegistrationJob` می‌گذرد که استثنا را می‌گیرد. آزمون واقعی روی ۸۰۹۰ با
CSRF و کپچای واقعی: **۳۰۲** با پیام فارسی، نه ۵۰۰؛ و هیچ رکورد ناقصی هم ساخته
نشد. جزئیات در `patches/docs/TRIAGE-159-persian-throws-fa.md`.

درس: «تابع پرتاب می‌کند + فراخوان try/catch ندارد» برای اثبات باگ کافی نیست؛
باید ثابت شود ورودیِ لازم برای فعال‌شدن آن پرتاب واقعاً از آن مسیر می‌گذرد.
به همین دلیل ۲۲ مورد باقی‌مانده تا بازتولید با HTTP واقعی «کاندید» می‌مانند،
نه «باگ» — و سرشمارِ اولیهٔ من (۱۵۹) سه بار تا ۲۲ اصلاح شد.
