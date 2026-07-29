# ارزیابی ریسک 112 خطای PHPStan

**تاریخ:** 2026-07-15  
**PHPStan:** 1.12.33 — Level 9  
**Scope:** `app/` و `core/`  
**Baseline:** 112 پیام در 43 فایل  
**وضعیت:** دو باگ قطعی بحرانی و چند ریسک شرطی مهم شناسایی شد

> درصدهای این گزارش برآورد مهندسیِ شرطی بر اساس مسیر کد هستند، نه آمار production. برای احتمال آماری واقعی به telemetry، سهم استفاده از gatewayها، feature flags و لاگ‌های runtime نیاز است.

---

## خلاصه مدیریتی

همه 112 پیام PHPStan معادل 112 باگ نیستند. چند پیام ممکن است از یک expression خراب تولید شده باشند و تعداد زیادی نیز صرفاً PHPDoc نادرست‌اند. نتیجه بررسی line-by-line:

| سطح | تعداد پیام | سهم | برداشت |
|---|---:|---:|---|
| 🔴 باگ قطعی بحرانی | 7 | 6.3% | دو root cause واقعی در Payment و Anti-Fraud |
| 🟠 ریسک بالا ولی شرطی | 6 | 5.4% | DI nullable، webhook shape و upload endpoint |
| 🟡 ریسک متوسط وابسته به ورودی | 26 | 23.2% | Request/API/upload/event payloadهای malformed |
| 🔵 قرارداد/PHPDoc نادرست با ریسک runtime پایین | 34 | 30.4% | تفاوت list/map، return shape و type inference |
| ⚪ cast روی config/DB/internal payload | 39 | 34.8% | در داده سالم کم‌ریسک؛ در misconfiguration مشکل‌ساز |
| **جمع** | **112** | **100%** | — |

### نتیجه کلی احتمال

- اگر **Anti-Fraud مرکزی ورود** روی همین سورس فعال باشد، احتمال manifest شدن خطا **بسیار زیاد و نزدیک به قطعی** است.
- اگر **ZarinPal** فعال باشد، احتمال شکست مسیر verify برای پاسخ موفق **عملاً 100%** است.
- اگر این دو feature غیرفعال باشند، احتمال بروز یکی از خطاهای باقی‌مانده در استفاده عادی **متوسط** است، اما با ورودی مخرب یا پاسخ غیرمنتظره provider افزایش می‌یابد.
- احتمال خرابی سراسری کل سیستم از تمام 112 پیام به‌طور هم‌زمان پایین است؛ ولی همان دو root cause بحرانی برای مختل‌کردن Login یا Payment کافی‌اند.

---

## 1. باگ قطعی بحرانی: ZarinPal verification

**فایل:** `app/Services/Payment/ZarinPalGateway.php`  
**خطوط:** 202، 211 و 212  
**پیام‌های PHPStan:** 6

کد فعلی:

```php
$code = isset($result['data']['code'])
    ? intval($result['data'])['code']
    : 0;

'ref_id' => strval($result['data'])['ref_id'],
'amount' => strval($result['data'])['amount'],
```

Cast روی کل آرایه انجام شده و سپس offset از `int|string` خوانده می‌شود. شکل منطقی مورد انتظار باید cast خود فیلد باشد، نه cast آرایه والد.

### اثر runtime

پروژه Warningها را توسط `Core\ExceptionHandler::handleError()` به `ErrorException` تبدیل می‌کند. خطا در catch عمومی gateway گرفته شده و خروجی `success=false` می‌شود. بنابراین:

- verify پاسخ موفق زرین‌پال شکست می‌خورد؛
- پرداخت ممکن است در gateway موفق ولی در سیستم local تأییدنشده باقی بماند؛
- کاربر خطای تأیید پرداخت می‌بیند؛
- reconciliation/manual review لازم می‌شود؛
- ریسک تیکت مالی و مغایرت settlement ایجاد می‌شود.

### احتمال

| شرط | احتمال شکست |
|---|---:|
| ZarinPal غیرفعال | 0% برای این مسیر |
| ZarinPal فعال ولی verify فراخوانی نشود | 0% |
| پاسخ verify دارای `data.code` باشد | **حدود 100%** |

**Severity:** Critical  
**اولویت اصلاح:** P0 فوری

---

## 2. باگ قطعی بحرانی: Anti-Fraud IP quality

**فایل:** `app/Services/AntiFraud/FraudGuardService.php`  
**خط:** 172  
**پیام PHPStan:** 1

کد فعلی:

```php
if (
    isset($results['ip_quality']['risk_score']) &&
    intval($results['ip_quality'])['risk_score'] >= 90
) {
```

`IPQualityService::check()` همیشه آرایه‌ای شامل `risk_score` برمی‌گرداند؛ ولی کد کل آرایه را به int تبدیل و سپس offset می‌خواند.

### اثر runtime

- برای `auth.login`، `ip_quality` در `IdentityFraudStrategy` همیشه ساخته می‌شود.
- Warning به `ErrorException` تبدیل می‌شود.
- `FraudGuardService` خطا را داخل `handleSystemFailure()` می‌گیرد.
- `auth.login` در فهرست sensitive actions است، بنابراین سیستم **fail-closed** می‌شود و `allowed=false` برمی‌گرداند.
- `AuthController` نتیجه را به‌عنوان تشخیص تقلب تفسیر و ورود را مسدود می‌کند.
- برای `auth.register` رفتار fail-open است؛ ثبت‌نام ممکن است ادامه یابد ولی بررسی ضدتقلب عملاً bypass می‌شود.

### احتمال

| مسیر | احتمال manifest شدن روی همین سورس |
|---|---:|
| Login با FraudGuard فعال | **90–100%** |
| Register با FraudGuard فعال | **90–100% خطای داخلی**؛ معمولاً ادامه به‌صورت fail-open |
| FraudGuard یا Identity strategy غیرفعال/جایگزین | پایین |

اگر محیط موجود واقعاً اجازه Login می‌دهد، یکی از این شرایط محتمل است: سورس deployشده متفاوت است، error handler در entrypoint مربوط ثبت نشده، یا binding/feature مسیر Anti-Fraud متفاوت است.

**Severity:** Critical  
**اولویت اصلاح:** P0 فوری

---

## 3. ریسک‌های بالا ولی شرطی — 6 پیام

### 3.1 آپلود تصویر تنظیمات سیستم

**فایل:** `app/Controllers/Admin/SystemSettingController.php:126`

PHPStan نوع خروجی `Request::file()` را `mixed` می‌بیند. بررسی semantic یک مشکل مهم‌تر هم نشان داد: آرگومان سوم `UploadService::upload()` باید لیست MIME باشد، اما options map ارسال شده است:

```php
[
    'allowed_types' => [...],
    'max_size' => 2 * 1024 * 1024,
]
```

`UploadService` روی value اول cast string انجام می‌دهد؛ value اول آرایه است و Array-to-string warning می‌دهد که در این پروژه exception می‌شود. علاوه بر آن، فایل قبلی **قبل از موفقیت آپلود جدید حذف می‌شود**.

**اثر:** شکست endpoint و احتمال حذف تصویر قبلی بدون جایگزین.  
**احتمال در هر بار استفاده از endpoint:** 90–100%  
**Severity:** High/Critical for that endpoint

### 3.2 Vitrine Saga nullable

**فایل:** `app/Services/VitrineService.php:257,339` — 2 پیام

`SagaOrchestrator` nullable تعریف شده ولی بدون guard فراخوانی می‌شود. Container استاندارد معمولاً آن را resolve می‌کند؛ بنابراین در bootstrap کامل احتمال پایین است. در manual construction، تست، worker ناقص یا misconfiguration، عملیات escrow و cancel شکست می‌خورند.

- احتمال در DI استاندارد: حدود 1–5%
- در صورت ساخته‌شدن بدون Saga: 100% هنگام ورود به دو مسیر
- اثر: شکست lock/cancel escrow؛ به‌دلیل شروع نشدن Saga معمولاً corruption مستقیم ایجاد نمی‌شود، ولی عملیات مالی متوقف می‌شود.

### 3.3 ManualDeposit UploadService nullable

**فایل:** `app/Controllers/User/ManualDepositController.php:29`

constructor مقدار `?UploadService` را به property غیرnullable می‌دهد. Container معمولاً کلاس را resolve می‌کند، اما هر manual construction با null همان constructor را با TypeError متوقف می‌کند.

- احتمال در DI استاندارد: پایین، حدود 1–5%
- در ساخت مستقیم یا misconfiguration: 100%
- اثر: route واریز دستی بالا نمی‌آید.

### 3.4 Reconciliation webhook shape

**فایل:** `app/Services/ReconciliationService.php:84,85` — 2 پیام

`amount` و `currency` پیش از ورود به `try` با `strval()` تبدیل می‌شوند. اگر webhook آرایه/آبجکت بفرستد، Warning می‌تواند خارج از catch به exception تبدیل شود.

- payload استاندارد provider: احتمال پایین، حدود 1–5%
- malformed/adversarial payload: قابل تحریک با احتمال بالا
- اثر: HTTP 500/log noise؛ پردازش مالی انجام نمی‌شود، پس عمدتاً fail-closed است.

---

## 4. ریسک متوسط وابسته به ورودی — 26 پیام

### 4.1 مرز Upload و `$_FILES`

فایل‌های اصلی:

- `UploadService.php`
- `BannerController.php`
- `BugReportController.php`
- `ManualDepositController.php`

PHPStan نشان می‌دهد shape فایل در مرز Request تضمین نشده است. در آپلود عادی مقادیر `error`, `tmp_name`, `size`, `name` scalar هستند و مشکلی رخ نمی‌دهد. nested file arrays یا payloadهای غیرعادی می‌توانند Warning/Exception تولید کنند.

**احتمال عادی:** پایین  
**احتمال با ورودی مخرب:** متوسط تا زیاد  
**اثر:** معمولاً رد درخواست و log noise؛ کنترل‌های MIME/magic-byte باعث fail-closed شدن مسیر امنیتی می‌شوند.

### 4.2 پاسخ gatewayهای IDPay، DgPay و NextPay

Castهای `intval/strval` روی response fields از نظر PHPStan `mixed` هستند. با API مطابق قرارداد مشکلی نیست؛ تغییر schema، پاسخ error متفاوت یا object/array غیرمنتظره می‌تواند verify/create را fail کند.

**احتمال:** 5–15% در طول زمان و هنگام تغییر/اختلال provider، کمتر در حالت پایدار.  
**اثر:** شکست پرداخت، ولی عمدتاً بدون credit اشتباه؛ نیازمند logging و reconciliation.

دو خطای headers در IDPay/DgPay صرفاً PHPDoc اشتباه‌اند: headers عملاً `list<string>` هستند ولی base class آن‌ها را `array<string,mixed>` مستند کرده است.

### 4.3 Event payloadهای CacheInvalidation

پنج cast روی `payment_id/user_id` از event data وجود دارد. Eventهای داخلی فعلی معمولاً scalar دارند. producer جدید یا event ناسازگار می‌تواند listener را fail کند و cache stale باقی بماند.

**اثر:** داده قدیمی در UI، نه corruption منبع اصلی.  
**احتمال:** پایین تا متوسط.

### 4.4 Social-task behavioral signals

چهار cast روی سیگنال‌های client-controlled وجود دارد. آرایه به int/float ممکن است مقدار 0 یا 1 تولید کند و scoring را غیرقابل اتکا کند.

**اثر:** کاهش کیفیت anti-bot score؛ بیشتر integrity/security است تا crash.  
**احتمال در حمله هدفمند:** متوسط.

### 4.5 JSON خام در CustomTaskController

`json_decode(..., true) ?? []` تضمین نمی‌کند نتیجه array باشد؛ JSON معتبر scalar مانند `1` یا `"x"` می‌تواند به `Validator::create(array ...)` برسد و TypeError ایجاد کند.

**اثر:** 500 به‌جای 422؛ قابل استفاده برای log flooding.  
**احتمال عادی:** پایین؛ در ورودی مخرب بالا.

### 4.6 Coupon cache validation

چهار cast روی payload cache توکن کوپن وجود دارد. داده فعلی داخلی است، اما cache corruption یا version mismatch می‌تواند validation مالی را fail کند. مسیر غالباً fail-closed است و کوپن رد می‌شود.

---

## 5. خطاهای قرارداد/PHPDoc با ریسک runtime پایین — 34 پیام

این گروه بیشتر debt نوعی است و معمولاً باگ عملیاتی فعلی نیست:

- `array<string,mixed>` در جاهایی که واقعاً `list<int>` یا `list<string>` است:
  - `BulkOperationsService`
  - `ContentController`
  - `BasePaymentGateway` headers
  - `ApiTokenService` scopes
  - `Core\Model::loadRelations`
- return shapeهای list/tuple که به اشتباه map مستند شده‌اند:
  - `VideoFingerprintService::findSimilarFingerprints()`
  - `SearchProjectionListener::normalize()`
- `Validator::validateOrFail()` در runtime حتماً array می‌دهد، چون property اصلی `array` است؛ PHPStan فقط shape متد `result()` را نمی‌داند.
- `Request::body()` هنگام key=null در runtime array برمی‌گرداند ولی signature آن `mixed` است؛ چند Controller به همین دلیل اخطار گرفته‌اند.
- `StuckWithdrawalReviewCommand` خروجی دارای counterهای int است، ولی PHPDoc سرویس از syntax نامناسب shape استفاده کرده و inference از بین رفته است.
- `NotificationTemplateService::$cache` type ندارد؛ در مسیر DI استاندارد fallback کار می‌کند، اما قرارداد constructor بیش از حد باز است.

**احتمال مشکل runtime مستقیم:** عموماً زیر 5%  
**ریسک اصلی:** refactor ناامن، از دست رفتن autocomplete، پنهان شدن باگ‌های آینده و سخت‌شدن تحلیل static.

---

## 6. castهای config/DB/internal payload — 39 پیام

فایل‌های شاخص:

- `core/Cache.php`, `core/Redis.php`, `core/RetryPolicy.php`, `core/RateLimiter.php`
- `DatabaseService.php`, `BackupService.php`
- `MessageModerationService.php`, `DashboardStatsService.php`, `RatingService.php`
- `Queue.php`, `IdempotencyKey.php`, `PaymentCommandService.php`

مقادیر config یا ستون‌های `COUNT()/AVG()` در شرایط عادی scalar هستند. دلیل خطا این است که helper `config()` یا لایه DB خروجی دقیق typed ندارد.

- با config/schema صحیح: احتمال runtime بسیار پایین، حدود 1–3%
- با deployment misconfiguration یا تغییر driver/schema: احتمال همان feature می‌تواند 100% شود.
- اثر محتمل: عدم اتصال Redis، شکست backup، rate-limit نادرست، آمار اشتباه یا شکست worker.

این موارد بهتر است در مرز `ConfigRepository` و DB mapper حل شوند، نه با cast پراکنده در ده‌ها سرویس.

---

## 7. برآورد اثر بر پروژه

| نوع اثر | ارزیابی |
|---|---|
| Availability کل سیستم | متوسط؛ اما Login در صورت فعال بودن FraudGuard می‌تواند بحرانی شود |
| Payment correctness | **بالا/بحرانی** برای ZarinPal verify |
| Data corruption | پایین تا متوسط؛ بیشتر مسیرها fail-closed هستند |
| Financial reconciliation | بالا برای ZarinPal؛ احتمال pending/stuck local transaction |
| Security bypass | متوسط؛ register fail-open و behavioral input shapeها |
| Cache consistency | متوسط در صورت event payload ناسازگار |
| Maintainability/refactor safety | بالا؛ 73 پیام کم‌ریسک runtime همچنان debt مهم هستند |

### برآورد نهایی

- **احتمال اینکه حداقل یکی از این خطاها در production اثر واقعی بگذارد:** بالا، حدود **70–90%** اگر Login Anti-Fraud و یکی از gatewayهای بررسی‌شده فعال باشند.
- **احتمال مشکل شدید در مسیر ZarinPal پس از پاسخ موفق:** نزدیک **100%**.
- **احتمال مشکل سراسری اگر ZarinPal و FraudGuard غیرفعال باشند:** پایین تا متوسط، حدود **15–35% در طول زمان**؛ بیشتر وابسته به ورودی malformed، provider change یا misconfiguration.
- **احتمال اینکه تمام 112 پیام باگ واقعی باشند:** بسیار پایین؛ حدود 65% پیام‌ها contract/config typing debt هستند.

---

## 8. ترتیب پیشنهادی اصلاح

1. **P0:** اصلاح سه expression در `ZarinPalGateway` و تست پاسخ‌های code=100/101/error.
2. **P0:** اصلاح expression مربوط به `ip_quality.risk_score` و تست `auth.login`, `auth.register`, Tor/VPN/clean IP و fail-mode.
3. **P0/P1:** اصلاح قرارداد `SystemSettingController -> UploadService` و انتقال حذف فایل قبلی به بعد از موفقیت کامل update.
4. **P1:** حذف nullable بودن dependencyهای اجباری Vitrine/ManualDeposit یا افزودن guard صریح قبل از transaction.
5. **P1:** افزودن DTO/normalizer برای gateway/webhook responseها.
6. **P2:** اصلاح boundaryهای Request/file/event و JSON scalar handling.
7. **P3:** اصلاح PHPDocهای list/map/tuple؛ این مرحله چندین پیام را بدون تغییر runtime حذف می‌کند.
8. **P3:** typed Config repository و typed DB result mappers برای حذف castهای پراکنده.

در این ارزیابی هیچ فایل production تغییر نکرده است.
