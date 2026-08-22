# تریاژ ۱۵۹ throw با پیام فارسی — تحلیل ایستا (پیش از اجرای تست)

> وضعیت: تحلیل ایستای مبتنی بر کد واقعی. اثبات نهاییِ هر مورد نیازمند اجرای تست روی
> سرور واقعی است (زنجیره ابزار در حال بازسازی است). هیچ کدی هنوز تغییر نکرده.

## ۱) اصلاح عدد ۱۵۹

عدد «۱۵۹ throw که همه به ۵۰۰ تبدیل می‌شوند» که قبلاً گزارش کردم **بیش‌برآورد بود**.
آن عدد صرفاً شمارش throwهای SPL با پیام فارسی بود و بررسی نکرده بود که آیا
جایی در مسیر فراخوانی، آن‌ها catch می‌شوند یا نه.

پالایش مرحله‌به‌مرحله (تحلیل گراف فراخوانی درون‌فایلی + بین‌فایلی):

| مرحله | باقی‌مانده |
|---|---|
| throwهای SPL با پیام فارسی در `app/` و `core/` | ۱۵۹ |
| منهای مواردی که در همان بلوک `try` گرفته می‌شوند | ۹۰ |
| منهای `core/` (نگهبان‌های امنیتی/برنامه‌نویسی — رفتار فعلی درست است) | ۶۴ |
| منهای پوشش‌دهی توسط فراخوان‌های درون‌فایلی (به‌صورت گذرا) | ۳۵ |
| منهای پوشش‌دهی توسط فراخوان‌های بین‌فایلی | ۳۰ |
| منهای مسیرهای فقط-صف (توسط `QueueWorker` گرفته می‌شوند) | **۲۲** |

توزیع لایه‌ای ۱۵۹ مورد اولیه: Service 72، Job 37، core 26، Controller 20، سایر 4.

### چرا ۲۶ مورد `core/` اشکال نیستند
همگی نگهبان‌های امنیتی/صحت‌اند و نباید پیامشان به کاربر برسد:
`QueryBuilder` (نام جدول/ستون/عملگر غیرمجاز، UPDATE/DELETE بدون WHERE)،
`Response` (تزریق CRLF در هدر، ریدایرکت خارجی، path traversal)، `Container`، `Schema`، `Router`.
تبدیل این‌ها به ۵۰۰ عمومی **رفتار درست** است.

### چرا ۱۵ مورد `CustomTaskController` اشکال نیستند
`submitProof()` خط ۲۷۱–۲۸۱ فراخوانی `storeProofFile()` را در
`catch (BusinessException|RuntimeException)` گرفته و **۴۲۲ به‌همراه همان پیام فارسی**
برمی‌گرداند. متدهای `storePrivatePdfProof`/`storePrivateVideoProof` فقط از همین مسیر
فراخوانی می‌شوند.

## ۲) یافته اصلی و جدید: طبقه‌بندی اشتباه خطا در QueueWorker

`app/Services/QueueWorker.php` خطوط ۲۹۴ و ۳۲۴ به کلاس
`\App\Exceptions\BusinessException` اشاره می‌کنند، در حالی که کد واقعی تقریباً همه‌جا
`\Core\Exceptions\BusinessException` را پرتاب می‌کند.

شواهد از کد:
- ارجاع به `Core\Exceptions\BusinessException` در `app/`: **۹۲ مورد**
- ارجاع به `App\Exceptions\BusinessException` در `app/`: **۱۶ مورد**
- `App\Exceptions\BusinessException extends \Core\Exceptions\BusinessException`
  (یعنی رابطه ارث‌بری **معکوس** است: نوع پایه، نمونه‌ی نوع فرزند نیست)
- تنها throw در `app/Jobs/`: `RequestWithdrawalJob.php:55` از نوع **Core**
- زیرکلاس‌های `Core\...\BusinessException`: `DomainException`،
  `InsufficientBalanceException`، `InvalidStateException` — هیچ‌کدام مشمول بررسی نمی‌شوند

پیامد:
1. `isFatalException()` برای خطای تجاری `false` برمی‌گرداند → کار تا
   `maxAttempts = 5` (`core/Queue.php:26`) **بی‌جهت retry می‌شود**.
2. `classifyError()` شاخهٔ `business/quarantined` را رد کرده و به
   `['class' => 'unknown', 'status' => 'pending_analysis']` می‌رسد — مگر آن‌که
   پیام فارسی تصادفاً شامل `timeout`/`connection`/`network`/`not found` باشد.

نمونهٔ ملموس: `RequestWithdrawalJob` وقتی «سودی برای برداشت وجود ندارد» پرتاب می‌کند،
یک خطای قطعیِ تجاری است اما ۵ بار تکرار می‌شود و سپس به‌جای `quarantined`
با برچسب `pending_analysis` ثبت می‌گردد.

هیچ تستی این رفتار را پوشش نمی‌دهد (`classifyError`/`isFatalException`/`quarantined`
در `tests/` بدون نتیجهٔ مرتبط).

اصلاح پیشنهادی: در هر دو متد، نوع `\Core\Exceptions\BusinessException` بررسی شود
(که به‌صورت خودکار نوع `App\...` و هر سه زیرکلاس را نیز پوشش می‌دهد) — به‌همراه تست
برای هر چهار کلاس.

## ۳) ۲۲ موردِ باقی‌مانده که واقعاً باید بررسی/اصلاح شوند

مسیرهای پول (بالاترین اولویت):
- `Services/Wallet/WalletMutationService.php:71, 144, 876, 882`
- `Services/Wallet/WalletService.php:155, 245`

نکته: `WalletService::transfer()` (خطوط ۳۴۳/۳۴۸/۳۵۳) **مشکلی ندارد** —
`TransferController.php:84-93` آن را می‌گیرد و ۴۲۲ با پیام واقعی برمی‌گرداند.

سایر موارد: `BaseController:262`، `Admin/AdminAnalyticsController:267`،
`Admin/ContentController:285`، `UserVacation:78`، `CaptchaService:233,245`،
`TicketCommandService:150,162`، `UploadService:208`،
`TwoFactorService:118`، `DisputeCommandService:117,219,613`،
`AdVideoRewardManager:348`. (مورد `UserService:85,93` در بخش تصحیح زیر رد شد.)

### تصحیح: `UserService:85,93` رد شد (اثبات با HTTP واقعی)

پیش‌تر این مورد را «بهترین شاهد» نامیدم: `UserService::register()` خطوط ۸۵/۹۳
(«کد معرف وارد شده معتبر نیست») در `ProcessRegistrationJob.php:55` گرفته می‌شود
اما `Admin/UserController.php:139` بدون try/catch صدایش می‌زند → ادعا: ۵۰۰.
**این ادعا نادرست بود و با آزمون واقعی روی ۸۰۹۰ رد شد.**

۱. `Admin/UserController.php:139` آرایه‌ای با کلیدهای ثابت
   (`full_name, email, password, role, status, email_verified_at`) می‌سازد و
   **هرگز `referral_code_used` را پاس نمی‌دهد**. بدون آن، شرط خط ۸۲
   (`$referralCodeUsed !== ''`) هرگز درست نمی‌شود، پس خطوط ۸۵/۹۳
   از این مسیر **دست‌نیافتنی‌اند**.
۲. تنها فراخوانی که کد معرف می‌فرستد `AuthController.php:231-237` است و آن هم
   از مسیر `AuthService::register()` → `ProcessRegistrationJob::handle()` می‌گذرد
   که `\InvalidArgumentException` را می‌گیرد.
۳. اثبات عملی (ثبت‌نام واقعی با `referral_code=NOSUCHCODE9Z` روی ۸۰۹۰، با
   `_csrf_token` و کپچای ریاضی واقعی): پاسخ **۳۰۲** به `/register` و پیام فارسی
   «کد معرف وارد شده معتبر نیست.» در فلش — **نه ۵۰۰**. همچنین
   `SELECT COUNT(*) FROM users WHERE email LIKE 'refprobe_%'` برابر **۰** بود،
   یعنی رکورد ناقصی هم به‌جا نمی‌ماند.

درس روش‌شناختی: تحلیل ایستا «تابع پرتاب می‌کند + فراخوان try/catch ندارد» کافی
نیست؛ باید بررسی شود که آیا **ورودی لازم برای رسیدن به آن پرتاب** اصلاً از آن
مسیر عبور می‌کند یا نه. ۲۲ مورد باقی‌مانده هم تا وقتی با HTTP واقعی بازتولید
نشوند، «کاندید» می‌مانند نه «باگ».

## ۴) علت ریشه‌ای فلیکِ Chaos (مستقل از موارد بالا)

در `tests/Chaos/InfrastructureFailureRuntimeTest.php`:
- پورت‌های ثابت `8093` (خط ۷۲) و `8094` (خط ۲۸۳)
- انتظارهای ثابت به‌جای آماده‌سنجی: `usleep(150_000)` خط ۸۶،
  `usleep(120000)` خط ۲۸۹، و `startPhpServer()` خط ۳۷۹ با `usleep(100000)` بدون هیچ probe
- رقابت SIGKILL→bind مجدد روی همان پورت ۸۰۹۴

جهت اصلاح: تخصیص پویای پورت آزاد + poll تا شنونده‌شدن، بدون هیچ تضعیفی در assertها.
