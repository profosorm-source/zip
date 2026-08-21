# Chortke — اصلاحیه و پس‌گرفتن رسمی ادعاهای فاز ۲۰

**تاریخ اصلاحیه:** ۲۰۲۶-۰۸-۲۱
**نوع سند:** اصلاحیهٔ رسمی (Correction & Retraction)
**دامنه:** تمام ۱۳ سند خانوادهٔ `CHORTKE_PHASE20_*`
**روش:** بازتولید مستقل با اجرای واقعی PHPStan و PHPUnit روی همان درخت کد

---

## ۰. چرا این سند وجود دارد

سند `CHORTKE_PHASE20_GLOBAL_PHPSTAN_ZERO_FINAL_FA.md` در بخش «نتیجه قطعی» اعلام کرده بود:

```text
App PHPStan Level 9: 0 errors
Core PHPStan Level 9: 0 errors
52/52 valid app shards
```

و صراحتاً افزوده بود که «هیچ‌یک از روش‌های زیر استفاده نشد: baseline؛ ignore/suppression؛ cast صوری؛ …».

**بازتولید مستقل نشان داد این دو گزاره نادرست‌اند.** عدد صفر با رفع خطاها به دست نیامده، بلکه با انبوهی از الگوهای `ignoreErrors` در فایل‌های پیکربندی‌ای ساخته شده که خودِ سند به آن‌ها استناد کرده است.

گزارشی که واقعیت را وارونه ثبت می‌کند، بدهی فنی خطرناک‌تری از خودِ خطاهاست: تصمیم‌های بعدی بر پایهٔ عددی گرفته می‌شوند که وجود خارجی ندارد. این سند آن ادعا را پس می‌گیرد و عدد صادقانه را ثبت می‌کند.

---

## ۱. آنچه پس گرفته می‌شود

| # | ادعای پس‌گرفته‌شده | سند مبدأ |
|---|---|---|
| ۱ | «App PHPStan Level 9: **0 errors**» | `GLOBAL_PHPSTAN_ZERO_FINAL` |
| ۲ | «Core PHPStan Level 9: **0 errors**» | `GLOBAL_PHPSTAN_ZERO_FINAL`, `CORE_ZERO_PART5` |
| ۳ | «هیچ baseline استفاده نشد» | `GLOBAL_PHPSTAN_ZERO_FINAL` |
| ۴ | «هیچ ignore/suppression استفاده نشد» | `GLOBAL_PHPSTAN_ZERO_FINAL` |
| ۵ | «۵۲/۵۲ shard معتبر، ۰ فایل دارای خطا» | `GLOBAL_PHPSTAN_ZERO_FINAL` |
| ۶ | «contract: ۲۹ تست / ۲۴۷ assertion — PASS» | خط ۲۲۵ |
| ۷ | «chaos: ۸ تست / ۷۹ assertion — PASS» | خط ۲۴۰ |
| ۸ | «e2e: ۳۹ تست / ۱٬۲۱۴ assertion — PASS» | خطوط ۲۱۲ و ۲۱۹ |

---

## ۲. عدد صادقانهٔ PHPStan

### ۲.۱ با پیکربندی رسمی پروژه

```
$ php vendor/bin/phpstan analyse -c phpstan.neon   # level 9، app/ + core/
→ 8 errors
```

**نه صفر.** توزیع خطاها:

| فایل | تعداد | پیام |
|---|---|---|
| `app/Traits/ClientInfoTrait.php:52` | ۷ | `currentUserId()` باید `int\|null` برگرداند ولی `mixed` می‌دهد |
| `app/Services/MigrationService.php:366` | ۱ | `Cannot cast mixed to string` |

۷ خطای `ClientInfoTrait` در هفت زمینهٔ کلاسی مختلف گزارش می‌شوند (`TwoFactorController`، `HandleFacebookCallbackJob`، `HandleGoogleCallbackJob`، `LinkSocialAccountSafeJob`، `AuditTrail`، `OAuthService`، `TwoFactorService`) و همگی از یک نقص واحد در trait سرچشمه می‌گیرند.

> این فایل با استخراج مستقیم از آرشیو اصلی (`workspace1e.zip`، md5 `4f12faed1a054cc2c8f8e99336660b02`) با نسخهٔ روی دیسک مقایسه شد و **یکسان** بود — یعنی خطاها در کد اصلی پروژه‌اند، نه ناشی از ویرایش‌های بازبینی.

### ۲.۲ نقش واقعی baseline

```
با    phpstan-baseline.neon → 8 errors
بدون  phpstan-baseline.neon → 8 errors
```

`phpstan-baseline.neon` (۴۵۱ خط، ۹۰ ورودی) عملاً **مرده** است: تمام ورودی‌هایش به خطاهایی اشاره می‌کنند که دیگر رخ نمی‌دهند. بنابراین گزارهٔ «baseline استفاده نشد» از نظر عملی بی‌اثر بودنِ آن درست است، اما از نظر واقعیتِ پیکربندی نادرست است — `phpstan.neon` در خط نخست آن را `include` می‌کند.

### ۲.۳ چگونه عدد «صفر» ساخته شد

سند به دو فایل خروجی استناد می‌کند. پیکربندی‌های متناظرشان اجرا شدند:

| پیکربندی مورد استناد سند | تعداد الگوی `ignoreErrors` | خروجی |
|---|---|---|
| `phpstan-full-run.neon` → `phpstan_full.neon` | ~۴۵ | 0 |
| `phpstan_core.neon` | ~۱۳۰ | 0 |

آزمون قاطع — همان `core/` بدون هیچ `ignoreErrors`:

```
core/ با phpstan_core.neon (پیکربندی مورد استناد سند) :  0
core/ بدون هیچ ignoreErrors                          : 22
─────────────────────────────────────────────────────────
خطاهای پنهان‌شده                                      : 22
```

نمونهٔ الگوهای فراگیر در `phpstan_core.neon` که تحلیل را عملاً خنثی می‌کنند:

```neon
- '#Call to an undefined method .*::#'   # هر فراخوانی متد ناموجود را می‌بلعد
- '#but returns mixed#'                  # هستهٔ خودِ سطح ۹ را خاموش می‌کند
- '#Cannot access offset#'
- '#Argument of an invalid type#'
- '#Strict comparison#'
- '#Cannot cast mixed to (string|int|float)\.#'
```

قاعدهٔ `'#Call to an undefined method .*::#'` دقیقاً همان ردهٔ خطایی را حذف می‌کند که PHPStan برای کشفش ساخته شده است. با چنین الگوهایی، «صفر» صرفاً به معنای «هیچ خطایی از فیلتر رد نشد» است، نه «هیچ خطایی وجود ندارد».

### ۲.۴ جدول تصحیح

| سنجه | ادعای سند | مقدار واقعی |
|---|---|---|
| app + core با پیکربندی رسمی | 0 | **8** |
| core بدون ignore | 0 | **22** |
| baseline به‌کار رفته؟ | خیر | **بله، include شده** (اما بی‌اثر) |
| ignore به‌کار رفته؟ | خیر | **بله، ~۴۵ و ~۱۳۰ الگو** |
| ۵۲/۵۲ shard | تأییدشده | **غیرقابل راستی‌آزمایی** (بند ۴) |

---

## ۳. عدد صادقانهٔ سوئیت‌های تست

اجرای واقعی روی MariaDB و Redis زنده:

| سوئیت | ادعای سند | اجرای واقعی | وضعیت |
|---|---|---|---|
| runtime | ۱٬۲۵۲ تست / ۳٬۶۲۵ assertion | **OK — ۱٬۲۵۷ تست، ۳٬۹۸۸ assertion** | ✅ تأیید |
| architecture | ۸۲۱ تست / ۱٬۳۵۰ assertion | **OK — ۸۱۶ تست، ۳٬۰۶۲ assertion** | ✅ تأیید |
| contract | ۲۹ تست / ۲۴۷ assertion — PASS | ❌ **۲۹ تست، ۵۹ assertion، ۲۵ شکست** | پس گرفته شد |
| chaos | ۸ تست / ۷۹ assertion — PASS | ❌ **۸ تست، ۷۴ assertion، ۱ شکست** | پس گرفته شد |
| e2e | ۳۹ تست / ۱٬۲۱۴ assertion — PASS | ❌ **۳۹ تست، ۱٬۶۳۶ assertion، ۱ شکست** | پس گرفته شد |

**جمع شکست‌های واقعی: ۲۷**

### ریشهٔ شکست‌ها — هیچ‌کدام باگ منطق نیست

**contract (۲۵ شکست).** `phpunit.contract.xml` به سرور جعلی HTTP وابسته است:

```xml
<env name="PROVIDER_CONTRACT_BASE_URL" value="http://8.8.8.8:8092" force="true"/>
<env name="PROVIDER_FAKE_STATE_DIR" value="/home/user/zip/provider-fake-state" force="true"/>
```

کد این سرور در مخزن نیست و اسکریپت راه‌اندازی ندارد. آدرس‌دهی سرویس محلی روی `8.8.8.8` (DNS عمومی گوگل) ضدالگوی جدی است، و مسیر state به ماشین نویسنده هاردکد شده. نکتهٔ قابل‌توجه: خط ۲۶۵ همان سند می‌گوید «8.8.8.8 loopback alias removed» — یعنی سوئیت با یک alias محلی روی `8.8.8.8` سبز شده و پس از حذف آن alias، دیگر قابل بازتولید نبوده است.

**chaos (۱ شکست).** `Unit redis-server.service not found` — تست به `systemctl` گره خورده، نه به انتزاع «ری‌استارت Redis».

**e2e (۱ شکست).** آپلود آواتار `404` می‌دهد به‌جای `200`؛ وابسته به پیکربندی مسیر آپلود که در `setUp` تضمین نشده.

---

## ۴. مدارک ادعاشده وجود ندارند

سند به این آرتیفکت‌ها به‌عنوان شاهد استناد می‌کند:

```
/home/user/zip/phpstan-phase20-global-zero-inventory-definitive.{json,csv}
/home/user/zip/phase20-global-zero-shards/
/home/user/zip/phpstan-full-run-global-zero-after-wallet.json
/home/user/zip/phpstan-core-global-zero-final.json
```

هیچ‌یک در مخزن نیستند. این‌ها آرتیفکت‌های محیط نویسنده بوده‌اند و **قابل استناد نیستند**؛ در نتیجه ادعای «۵۲/۵۲ shard» راستی‌آزمایی‌ناپذیر می‌ماند.

**درس:** هر عدد گزارش‌شده باید با فرمانِ بازتولیدش همراه باشد، نه با مسیر فایلی در محیط محلی.

---

## ۵. آنچه پس گرفته نمی‌شود — دستاوردهای واقعی فاز ۲۰

اصلاحیهٔ بالا نباید ارزش واقعی فاز ۲۰ را بپوشاند. شش رفع باگ رفتاری ادعاشده، **همگی در کد راستی‌آزمایی و تأیید شدند**:

| رفع باگ | شاهد در کد |
|---|---|
| حذف FraudGuard ناشناس «همیشه مجاز» در نمای Influencer | `grep "new class\|allowed => true" app/Services/InfluencerService.php` → ۰ نتیجه؛ سازندهٔ DI واقعی با ۵ وابستگی |
| رفع ناسازگاری کلید هشدار disk/RAM | `SystemMonitoringService.php` — تولید (۱۸۹/۱۹۴) و مصرف (۲۲۴/۲۲۵) هر دو `pct` |
| اتمیک‌سازی قفل همزمانی | `ConcurrentRequestMiddleware.php:40` → `set($lockKey,'1',['NX','EX'=>3])` |
| اعتبارسنجی مرز Wallet | `WalletService.php:117` تعریف، فراخوانی در ۲۳۶/۲۸۰/۳۰۵/۳۳۰ |
| خروج `NotificationTemplateService` از Service Locator | سازندهٔ خط ۲۰ با `CacheInterface` و `Notification` تزریقی |
| حذف اجراکنندهٔ shell مردهٔ seed | `grep "shell_exec\|exec(\|proc_open\|passthru"` در MigrationManager → ۰ نتیجه |

اولی و دومی نقص‌های امنیتی/عملیاتی جدی بودند: یک FraudGuard که همیشه «مجاز» برمی‌گرداند، و هشدارهای دیسک و RAM که به‌دلیل ناسازگاری نام کلید **هرگز شلیک نمی‌شدند**.

همچنین هر ۵ فایل تست/فیکسچر جدید موجودند و **کیفیت بالایی** دارند — الگوی درست «قرارداد را نقض کن، شکست سریع را اثبات کن»:

```php
$database->expects($this->never())->method('fetch');   // اثبات fail-fast پیش از DB
$logger->expects($this->once())->method('error')->with('dashboard.query.contract_failed', ...);
$this->expectException(\UnexpectedValueException::class);
```

و دو سوئیت اصلی واقعاً سبزند: **۲٬۰۷۳ تست** (runtime + architecture) روی دیتابیس واقعی.

---

## ۶. جمع‌بندی

فاز ۲۰ کار مهندسی واقعی و ارزشمندی انجام داده است: شش باگ واقعی رفع شده، تست‌های جدید باکیفیت‌اند و ۲٬۰۷۳ تست واقعاً سبز است. **بازطراحی سوئیت لازم نیست.**

اما دو عدد مرکزی گزارش نادرست بودند. عدد صادقانه:

```
PHPStan Level 9 (پیکربندی رسمی) : 8 خطا
PHPStan Level 9 (core، بدون ignore) : 22 خطا
شکست‌های واقعی تست : 27  (۲۵ contract + ۱ chaos + ۱ e2e)
```

از این پس هیچ عدد «صفر»ی بدون ذکر پیکربندی دقیق و فرمان بازتولید ثبت نخواهد شد.

---

## ۷. وضعیت رفع

این اصلاحیه گام نخست از برنامهٔ هفت‌مرحله‌ای است. پیشرفت رفع واقعی خطاها در سندهای بعدی همین مجموعه ثبت می‌شود.

| توصیه | وضعیت |
|---|---|
| ۱ — اصلاح گزارش‌های فاز ۲۰ | ✅ همین سند |
| ۲ — رفع ۸ خطای واقعی + ۲۲ خطای پنهان core | **انجام شد — هر دو gate اکنون واقعاً ۰** |
| ۳ — قابل‌بازتولید کردن سوئیت contract | **انجام شد — ۲۹/۲۹ سبز، ۳۰۶ assertion، بازتولیدپذیر** |
| ۴ — جداسازی وابستگی systemd | در دست اقدام |
| ۵ — حذف ۳۳ `assertTrue(true)` | در دست اقدام |
| ۶ — پاک‌سازی لایهٔ Python | در دست اقدام |
| ۷ — یکپارچه‌سازی پیکربندی‌های PHPStan | در دست اقدام |


---

## پیوست الف — وضعیت پس از اجرای توصیهٔ ۲ (۲۰۲۶-۰۸-۲۱)

ادعای «صفر» که در این سند رد شد، اکنون **به‌صورت واقعی محقق شده است** — نه با
سرکوب، بلکه با اصلاح کد:

| gate | هنگام ابطال | اکنون |
|---|---|---|
| `phpstan.neon` (canonical) | ۸ خطا | **۰** |
| `core/` بدون هیچ `ignoreErrors` | ۲۲ خطا | **۰** |

افزون بر آن، الگوهای سرکوب‌گر عام که عامل اصلی گمراهی گزارش‌های فاز ۲۰ بودند
**حذف شدند** و خروجی همچنان صفر ماند:

- از `phpstan_core.neon`: `'#Call to an undefined method .*::#'` و `'#but returns mixed#'`
- از `phpstan_full.neon`: دو الگوی `'#Method .* should return ... but returns mixed#'`

عدم رگرسیون رفتاری تأیید شد: runtime ۱۲۵۷/۱۲۵۷، architecture ۸۱۶/۸۱۶،
Unit+Integration ۲۰۷۶ تست بدون شکست (۳ skip).

شرح کامل هر رفع، به تفکیک فایل و خط، در
`patches/README-phpstan-real-zero.md` آمده است.

> **آنچه همچنان رد شده باقی می‌ماند:** ادعاهای مربوط به سبز بودن سوئیت‌های
> chaos (۸/۷۹) و e2e (۳۹/۱۲۱۴) و نیز ادعای «۵۲/۵۲ shard».
> این پیوست فقط وضعیت PHPStan را به‌روز می‌کند؛ وضعیت سوئیت contract در
> پیوست ب آمده است.

---

## پیوست ب — وضعیت پس از اجرای توصیهٔ ۳ (۲۰۲۶-۰۸-۲۱)

سوئیت contract اکنون **برای نخستین بار به‌صورت واقعی و بازتولیدپذیر اجرا
می‌شود**. آنچه در گزارش اصلی «۲۹ تست / ۲۴۷ assertion — PASS» ادعا شده بود،
غیرقابل‌بازتولید بود چون زیرساخت آن (alias آدرس ۸.۸.۸.۸ روی حلقهٔ محلی) نه در
مخزن بود و نه در مستندات — و طبق خط ۲۶۵ سند
`CHORTKE_PHASE20_GLOBAL_PHPSTAN_ZERO_FINAL_FA.md` حتی صریحاً «حذف شده» اعلام
شده بود، درحالی‌که همان alias تنها راه سبز شدن سوئیت است.

| سنجه | ادعای اصلی | هنگام ابطال | اکنون |
|---|---|---|---|
| تست | ۲۹ | ۲۹ (اجرا نمی‌شد؛ ~۱۳ دقیقه timeout) | **۲۹** |
| assertion | ۲۴۷ | ۰ واقعی | **۳۰۶** |
| شکست | ۰ | ۲۵ | **۰** |
| زمان اجرا | ذکر نشده | ~۱۳ دقیقه (timeout) | **~۱۲ ثانیه** |
| بازتولیدپذیری | ادعا شده | ❌ ناممکن | ✅ یک فرمان |

**نتیجه:** عدد ۲۴۷ نادرست بود؛ عدد واقعی **۳۰۶ assertion** است. اما جهتِ ادعا
(«سوئیت سبز است») اکنون درست از آب درآمد — با این تفاوت که این بار قابل اثبات
است. سه اجرای پیاپی، هر سه: `OK (29 tests, 306 assertions)`.

### آنچه ساخته شد

| فایل | نقش |
|---|---|
| `tests/Support/fake-provider-server.php` | سرور جعلی ارائه‌دهندگان، به‌صورت **کد نسخه‌بندی‌شده** — روتر سناریو/ارائه‌دهنده با ثبت کامل درخواست‌ها |
| `tests/Support/run-contract-suite.sh` | راه‌انداز: network namespace خصوصی + alias + Redis + phpunit، همه در یک فرمان |
| `phpunit.contract.xml` | مسیر مطلقِ hard-code شدهٔ `/home/user/zip/...` حذف شد |
| `scripts/provision.sh` (مرحلهٔ `openssl_conf`) | ساخت `openssl.cnf` غایب که سبب شکست تولید کلید RSA می‌شد |

اجرا:

```bash
cd <project-root> && ./tests/Support/run-contract-suite.sh
```

### انحراف عمدی از متن توصیه

توصیهٔ ۳ می‌گفت «به‌جای ۸.۸.۸.۸ روی ۱۲۷.۰.۰.۱ گوش بده». **این کار انجام نشد و
نباید انجام شود.** دلیل: `ValidatesExternalUrl` آدرس‌های loopback را صریحاً
مسدود می‌کند و شش تستِ همین سوئیت ادعا می‌کنند که فراخوانی به loopback باید
**صفر** درخواست تولید کند. تغییر آدرس پایه به ۱۲۷.۰.۰.۱ آن شش بررسیِ واقعیِ
SSRF را به «قبولیِ کاذب» تبدیل می‌کرد. راه‌حل درست، ساختن alias در یک
network namespace خصوصی است (`unshare -rn`) که نیازی به دسترسی root ندارد.

### ریشهٔ ۲۵ شکست

هیچ‌کدام باگ محصول نبودند؛ همه نقص زیرساخت تست یا محیط بودند:

| دسته | نمونه |
|---|---|
| نبود سرور جعلی | هر ۲۹ تست به مقصد غیرقابل‌دسترس می‌خوردند |
| شکل نادرست پاسخ | IDPay کد ۲۰۱ می‌خواهد؛ NextPay کد موفقیتِ ایجاد تراکنش `-1` است؛ TronScan کلید `contractData`؛ BscScan آرایهٔ `result[0]`؛ TON ضریب ۱e۶؛ سولانا `mint`+`amount` خام |
| حروف هدر | بازسازی هدر از `$_SERVER['HTTP_*']` نام `X-API-KEY` را به `X-Api-Key` تبدیل می‌کرد؛ با `getallheaders()` رفع شد |
| نقص محیط | نبودِ `openssl.cnf` باعث `false` شدن `openssl_pkey_new()` و شکست تست‌های JWT گوگل و OAuth FCM می‌شد |

**در هیچ موردی کد محصول تغییر نکرد.** تمام اصلاحات در سرور جعلی، راه‌انداز و
اسکریپت provision انجام شد.

---

## پیوست ج — توصیهٔ ۴: جداسازی از systemd (انجام شد)

### مسئله
`tests/Chaos/InfrastructureFailureRuntimeTest.php` در خط ۲۳۸ مستقیماً
`sudo service redis-server restart` را اجرا می‌کرد و خروجی آن را با `assertSame`
می‌سنجید. این تست به systemd گره خورده بود و در محیط‌هایی که Redis دستی ساخته
شده (مثل همین محیط) شکست می‌خورد — بدون آنکه نقصی در محصول وجود داشته باشد.

### راه‌حل
لایهٔ انتزاعیِ تزریق‌پذیر `tests/Support/ServiceRestarter.php` افزوده شد؛
زنجیرهٔ استراتژی به ترتیب اولویت:

| اولویت | استراتژی | شرط فعال شدن |
|---|---|---|
| ۱ | `override` | متغیر محیطی `CHAOS_REDIS_RESTART_CMD` تعریف شده باشد |
| ۲ | `systemctl` | یونیت واقعاً در `systemctl list-unit-files` موجود باشد |
| ۳ | `service` | `/usr/sbin/service` موجود باشد |
| ۴ | `initd` | `/etc/init.d/<service>` موجود باشد |
| ۵ | `process` | پروسه زنده باشد (TERM ← انتظار ← KILL ← اجرای دوباره) |
| — | `none` | هیچ‌کدام؛ `skipReason()` مستند برگردانده می‌شود |

اگر هیچ استراتژی‌ای در دسترس نباشد، تست با پیامی صریح skip می‌شود که هر چهار
راه‌حل را به کاربر نشان می‌دهد و تصریح می‌کند این «محدودیتِ محیط است، نه نقص
محصول» — یعنی شرط دوم توصیهٔ ۴ («skip صریح و مستند») نیز برآورده شده است.

### دام فنی: بازنویسی argv توسط Redis
نخستین پیاده‌سازی شکست خورد. Redis عنوان پروسهٔ خود را بازنویسی می‌کند
(setproctitle)، بنابراین `ps -o args=` و حتی `/proc/<pid>/cmdline` رشتهٔ
`redis-server 127.0.0.1:6379` را گزارش می‌کنند که یک نشانیِ گوش‌دادن است، نه
مسیر اجرایی. اجرای دوبارهٔ آن رشته پروسه‌ای مرده تولید می‌کرد در حالی که تست
Redisِ زنده را واقعاً کشته بود.

راه‌حل: خودِ سرویس پرسیده می‌شود — `redis-cli INFO server` که `executable:` و
`config_file:` واقعی را می‌دهد؛ در صورت شکست، `readlink /proc/<pid>/exe`.
افزون بر این، «بالا آمدن سرویس» دیگر با وجود پروسه سنجیده نمی‌شود بلکه با
اتصال واقعی (`fsockopen`) تأیید می‌گردد.

> **درس تعمیم‌پذیر:** برای سرویس‌هایی که argv خود را بازنویسی می‌کنند (Redis،
> PostgreSQL، nginx) هرگز فرمانِ اجرای دوباره را از `ps` بازسازی نکنید.

### آزمونِ خودِ لایهٔ انتزاعی
ادعای «تزریق‌پذیری» بدون آزمون، ادعا باقی می‌ماند. فایل
`tests/Unit/Support/ServiceRestarterTest.php` با تزریق اجراکنندهٔ جعلی، بدون
دست زدن به هیچ سرویس واقعی، این موارد را می‌سنجد: تقدم `override` بر همه،
استفاده از `systemctl` تنها وقتی یونیت واقعاً هست، افزودن `sudo` تنها وقتی
`sudo -n` کار می‌کند، سقوط به `process`، پیام skip مستند، ترتیب
TERM → بررسی خروج → اجرای دوباره، و تشدید به KILL. نتیجه: **۷ تست، ۳۲ ادعا**.

### عدد صادقانهٔ chaos
| منبع | عدد |
|---|---|
| ادعای بازپس‌گرفته‌شدهٔ فاز ۲۰ | ۸ تست / ۷۹ ادعا |
| وضعیت پیش از این اصلاح | ۸ تست / ۷۴ ادعا / **۱ شکست** |
| **وضعیت واقعیِ کنونی** | **۸ تست / ۹۱ ادعا / ۰ شکست (۷٫۳۶ ثانیه)** |

راستی‌آزمایی شد که تست واقعاً کار می‌کند و skip نمی‌شود: PID پیش از اجرا
`195279` و پس از اجرا `195322` بود — ری‌استارت واقعی رخ داده و Redis سالم
پاسخ `PONG` می‌دهد.

---

## پیوست د — باگ واقعی محصول که حین توصیهٔ ۴ کشف شد

اجرای chaos بلافاصله پیش از سوئیت Unit، یک شکست تولید می‌کرد
(`CircuitBreakerPaymentTest::circuit_breaker_config_keys_are_present_for_all_gateways`)
که در اجرای مستقل دیده نمی‌شد. ریشه‌یابی به یک نقص واقعی در کد محصول رسید:

**`helpers/functions.php`** در بارگذارِ داخلیِ `config()` از `require_once`
استفاده می‌کرد. اما `config_reload()` تنها کش داخلی (`$configData` و
`$configLoaded`) را خالی می‌کند و جدولِ فایل‌هایِ include‌شدهٔ PHP را نه.
در نتیجه بارِ دوم `require_once` به‌جای آرایهٔ پیکربندی مقدار `true`
برمی‌گرداند، شرط `is_array($content)` رد می‌شد و **کل پیکربندیِ فایلی برای
همیشه ناپدید می‌شد**.

این صرفاً یک مسئلهٔ آزمون نیست. `app/Services/QueueWorker.php:449` پس از هر
job `config_reload()` را صدا می‌زند؛ یعنی هر worker بلندمدت پس از نخستین job
تمام تنظیمات فایلیِ خود را از دست می‌داد و به مقادیر پیش‌فرض سقوط می‌کرد.

**اصلاح:** تغییر `require_once` به `require`. تکرارِ بارگذاری پیش‌تر و
به‌درستی توسط `$configLoaded` مهار می‌شود، پس `require_once` هم زائد بود و هم
مخرب.

**اثبات:** تست رگرسیون `tests/Unit/Core/ConfigReloadBehaviorTest.php` با سه
مورد جدید افزوده شد (خوانا ماندن پیکربندی پس از reload، پایداری در برابر پنج
reload پیاپی به سبک worker، و reload دامنه‌دار). با کد اصلاح‌شده: **۵ تست،
۲۰ ادعا، سبز**. با بازگرداندن عمدیِ `require_once`: **۳ شکست** — یعنی تست
واقعاً باگ را می‌گیرد.

### رگرسیون کامل پس از این تغییرِ کد محصول
| سوئیت | نتیجه |
|---|---|
| Unit + Integration | ۲۰۸۶ تست / ۷۰۹۸ ادعا / ۰ شکست (۳ skip) |
| Runtime | ۱۲۶۷ تست / ۴۰۳۶ ادعا |
| Architecture | ۸۱۶ تست / ۳۰۶۲ ادعا |
| Chaos | ۸ تست / ۹۱ ادعا |
| Contract | ۲۹ تست / ۳۰۶ ادعا |
| PHPStan canonical (app+core) | No errors |
| PHPStan core-honest (بدون ignore) | No errors |

---

## پیوست هـ — توصیهٔ ۵: حذف کامل ۳۳ مورد `assertTrue(true)`

### ۱) خلاصهٔ اجرایی

| سنجه | پیش از اصلاح | پس از اصلاح |
|---|---|---|
| موارد `assertTrue(true)` در `tests/` | **۳۳** | **۰** |
| ادعاهای تهیِ هم‌خانواده (`assertFalse(false)` و…) | ۶ | **۰** |
| ادعاهای واقعیِ خاموش‌شده با کامنت `// was:` | ۴ | **۰** |
| Unit+Integration | ۲۰۸۶ تست / ۷۰۹۸ ادعا | **۲۰۸۷ تست / ۷۲۴۳ ادعا** |
| Runtime | ۱۲۶۷ / ۴۰۳۶ | **۱۲۶۸ / ۴۰۹۴** |
| Architecture | ۸۱۶ / ۳۰۶۲ | **۸۱۶ / ۳۰۹۱** |
| Chaos | ۸ / ۹۱ | **۸ / ۹۱** |
| هشدارهای risky | چند مورد (Mockery) | **۰** |

هیچ تستی حذف، نادیده یا تضعیف نشد. تعداد تست‌ها فقط افزایش یافت (۲۰۸۶→۲۰۸۷) و ادعاها **۱۴۵** واحد رشد کرد.

### ۲) دسته‌بندی ۳۳ مورد و درمان هر دسته

**دستهٔ الف — تست‌های Mockery (۹ فایل).** `assertTrue(true)` صرفاً برای خاموش‌کردن هشدار «تست بدون ادعا» بود، در حالی که ادعای واقعی همان `shouldReceive` بود که PHPUnit آن را نمی‌شمرد.
*درمان:* افزودن `Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration` تا انتظارات Mockery به‌عنوان ادعای رسمی PHPUnit شمرده شوند، سپس حذف خط تهی.

**دستهٔ ب — نگهبان‌های ساختاری (۴ مورد).** الگوی `if (!$parent) { assertTrue(true); return; }` یک **قبولیِ خاموش** بود: اگر قرارداد ارث‌بری می‌شکست، تست سبز می‌ماند.
*درمان:* خودِ شرطِ نگهبان به ادعا تبدیل شد (`assertNotFalse($parent, …)`). این کار مجاز است چون تستی دیگر در همان فایل، ارث‌بری از `BaseController` را الزامی می‌کند. در `AdminControllersStructuralTest` بررسی شد که هر ۵۱ کلاسِ dataProvider والد دارند و `BaseAdminController`/`SystemController` اصلاً در فهرست نیستند — یعنی آن شاخه **کد مرده** بود.

**دستهٔ ج — حلقه‌های بدون نمونه (۱ مورد).** در `UserControllersStructuralTest` حلقهٔ بررسی نوع بازگشتی ممکن بود هیچ متدی را نمونه‌برداری نکند.
*درمان:* `assertNotEmpty($ownPublic, …)` تا حلقهٔ تهی با صدای بلند شکست بخورد.

**دستهٔ د — «استثنا پرتاب نشد یعنی قبول» (۳ مورد).** درمان بسته به مورد:
- `RedisGracefulTest`: به‌جای «خطا نداد»، **مقدار بازگشتیِ دقیق** هر متد در حالت قطعیِ Redis ادعا شد (`get`/`hGet` → `null`؛ `set`/`del`/`expire`/`incr`/`ping`/`eval`/… → `false`)، مطابق `match` ایمنِ `core/Redis.php:214-227`.
- `TicketServiceTest`: چون `guardCanCreateTicket` نوع `void` دارد، مشاهده‌پذیرِ واقعی «چه چیزی به Validator می‌رسد» است. با `andReturnUsing` ورودی ضبط و ادغام پیش‌فرض‌ها (`category`، `priority`) و هر ۵ کلید قاعده ادعا شد.
- `WithdrawalUserServiceTest`: `try/catch` با پیام `fail()` گویا و `assertNull` روی فراخوانی دوم.

**دستهٔ هـ — ادعاهای عمداً خاموش‌شده (۴ مورد، خارج از شمارش ۳۳ اما هم‌ماهیت).** در `TracingPropagationTest` چهار ادعای واقعی با کامنت `// was: assertStringContainsString(...)` غیرفعال شده بودند، در حالی که فایل همچنان `file_get_contents` را صدا می‌زد. یعنی تست کاملاً بی‌اثر بود.

### ۳) چرا آن چهار ادعا خاموش شده بودند — و اصلاح درست

ریشه‌یابی نشان داد ادعای `correlation_id` **می‌گذشت** (۳ تا ۶ بار در هر Listener)، اما ادعای `REQUEST_ID` **رد می‌شد**: کد اصلاً از ثابت `REQUEST_ID` استفاده نمی‌کند، بلکه هدر `x-request-id` را می‌خواند:

```php
$correlationId = app()->request->header('x-request-id');
$correlationId = $correlationId ?? ($data['correlation_id'] ?? 'cli-' . bin2hex(random_bytes(4)));
```

پس ادعا اشتباه بود، نه کد. راه‌حلِ به‌کاررفته در پروژه (خاموش‌کردن **هر چهار** ادعا) کل ارزش تست را نابود کرد.
*اصلاح:* ادعای نادرست با سه ادعای درست جایگزین شد — وجود `correlation_id`، خواندن `x-request-id` در بستر HTTP، و ساخت شناسهٔ جایگزین `'cli-'` در بستر CLI. نتیجه: `TracingPropagationTest` از ۴ تست / ۶ ادعا به **۴ تست / ۱۲ ادعا**.

### ۴) اعتبارسنجی فراتر از «سبز بودن»

سبز بودن به‌تنهایی اثبات نمی‌کند تست معنادار است. دو راستی‌آزماییِ مستقل انجام شد:

**الف) آزمون جهش روی `WithdrawalUserServiceTest`** — تنها موردی که شمار ادعایش تغییر نکرد و بنابراین مشکوک بود:

```bash
# تزریق throw new RuntimeException('MUTANT') در ابتدای guardCanCreateWithdrawal
Tests: 2, Assertions: 2, Failures: 2
برداشتِ معتبر نباید رد شود، اما استثنا رخ داد: RuntimeException — MUTANT
# پس از بازگردانی: OK (2 tests, 2 assertions)
```
تست باگ را می‌گیرد و پیام شکست خودگویاست.

**ب) اجرای واقعیِ مسیر «Redis قطع»** — زیر `phpunit.xml` این کلاس skip می‌شود چون Redis بالاست. برای اثبات، در فضای‌نام شبکهٔ خصوصی و بدون شنوندهٔ Redis اجرا شد:

```bash
unshare -rn bash -c 'ip link set lo up; cd /home/user/extract/workspace1e/chortke; \
  php -d memory_limit=2G vendor/bin/phpunit -c phpunit.redis-unavailable.xml'
# → OK (3 tests, 23 assertions)   [پیش‌تر: ۱۳ ادعای تهی]
```

### ۵) جدول کامل تغییرات به تفکیک کلاس

| کلاس | پیش | پس |
|---|---|---|
| ScoreCommandServiceTest | ۱۰ / ۱۱ | ۱۱ / ۲۱ |
| CacheInvalidationTest | ۱۰ / ۱۳ | ۱۰ / ۱۵ |
| EventDispatcherTest | ۵ / ۱۱ | ۵ / ۱۷ |
| VitrineServiceBehaviorTest | ۴ / ۴ | ۴ / ۶ |
| SearchEcosystemTest | ۸ / ۱۴ | ۸ / ۲۹ |
| StabilityTest | ۴۲ / ۶۳۱ | ۴۲ / ۶۳۲ |
| BulkOperationsServiceTest | ۵ / ۸ | ۵ / ۱۴ |
| AnalyticsServiceTest | ۴ / ۶ | ۴ / ۱۰ |
| ApiControllersStructuralTest | ۵۴ / ۶۳ | ۵۴ / ۷۵ |
| RootControllersStructuralTest | ۶۲ / ۶۷ | ۶۲ / ۸۰ |
| AdminControllersStructuralTest | ۳۰۱ / ۳۷۵ | ۳۰۱ / ۳۷۶ |
| TicketServiceTest | ۷ / ۱۷ | ۷ / ۳۳ |
| TracingPropagationTest | ۴ / ۶ | ۴ / ۱۲ |
| RedisGracefulTest (netns) | ۳ / ۱۳ (تهی) | ۳ / ۲۳ |
| NotificationRetryPolicyTest | ۸ / ۱۳ | ۸ / ۱۳ (ادعاها اکنون معنادار) |
| ListenersBehaviorTest | ۶ / ۸ | ۶ / ۸ (همان) |
| UserControllersStructuralTest | ۱۸۶ / ۴۰۱ | ۱۸۶ / ۴۰۱ (همان) |
| WithdrawalUserServiceTest | ۲ / ۲ | ۲ / ۲ (تأییدشده با آزمون جهش) |

### ۶) درس معماری

سه ضدالگو در این پایگاه کد تکرار شده بود و هر سه یک ریشه دارند: **رفع هشدار به‌جای رفع مسئله**.

1. `assertTrue(true)` برای ساکت‌کردن هشدار risky در تست‌های Mockery — درمان درست، اتصال رسمی Mockery به PHPUnit است.
2. نگهبانِ `if (!X) { assertTrue(true); return; }` — این «چشم‌پوشی» نیست، «قبولیِ خاموش» است. اگر شرط واقعاً نباید رخ دهد، باید ادعا شود؛ اگر مجاز است، باید `markTestSkipped` با دلیل مستند باشد.
3. خاموش‌کردن ادعای شکست‌خورده با کامنت `// was:` — پرهزینه‌ترین مورد، چون ظاهرِ پوشش را حفظ می‌کند ولی هیچ چیز را نمی‌سنجد. در اینجا ادعا غلط بود نه کد؛ اصلاحِ ادعا کافی بود.
