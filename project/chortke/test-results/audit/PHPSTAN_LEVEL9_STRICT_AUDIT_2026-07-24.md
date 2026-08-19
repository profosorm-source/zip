# گزارش PHPStan سطح ۹ — اجرای Strict بدون Ignore

**تاریخ:** ۲۴ ژوئیهٔ ۲۰۲۶  
**هدف:** آشکار کردن خطاهایی که config فعلی PHPStan آن‌ها را suppress می‌کند؛ نه ساخت baseline و نه اضافه‌کردن ignore.

## نحوهٔ اجرا

یک config موقت و مستقل در `test-results/audit/phpstan-level9-strict.neon` ساخته شد که دقیقاً همان سطح و scope اصلی را دارد، اما:

- هیچ `ignoreErrors` ندارد؛
- هیچ baseline ندارد؛
- `reportUnmatchedIgnoredErrors: true` دارد؛
- فقط `app/` و `core/` را تحلیل می‌کند؛ همان exclusionهای موجود برای `app/Views/`، `app/Cache/` و `core/Cache/` حفظ شده‌اند.

فرمان:

```bash
php vendor/bin/phpstan analyse \
  -c test-results/audit/phpstan-level9-strict.neon \
  --no-progress --memory-limit=2G --error-format=json
```

## نتیجهٔ قطعی

| حالت | نتیجه |
|---|---:|
| PHPStan با config فعلی repository | ۱ خطا: `SecurityHeadersMiddleware.php:138` |
| PHPStan Strict سطح ۹ بدون ignore | **۹٬۴۸۱ خطا در ۷۹۳ فایل** |
| مدت اجرای Strict | ۲۹۹ ثانیه |

بنابراین، config فعلی نتیجهٔ «تقریباً سبز» می‌دهد اما واقعاً مقدار بزرگی از خطاها را پنهان می‌کند. این به‌معنی آن نیست که هر ۹٬۴۸۱ مورد باگ runtime هستند؛ اما تا زمان triage نباید آن‌ها را false-positive یا «غیرمهم» فرض کرد.

## چرا config فعلی قابل اعتماد نیست

در `phpstan.neon` فعلی:

- **۱۰۰** entry در `ignoreErrors` وجود دارد؛
- **۷۹** مورد regex-based هستند؛
- ignore کامل برای `core/EventDispatcher.php`، `core/Database.php`، `core/Session.php`، `core/Response.php`، `core/Router.php` و `core/ExceptionHandler.php` وجود دارد؛
- ignoreهای broad شامل undefined-property روی `object`، property روی `mixed` و cast از `mixed` هستند؛
- `reportUnmatchedIgnoredErrors: false` است، بنابراین حتی ignoreهای قدیمی/بی‌اثر نیز زنگ هشدار نمی‌دهند.

این الگو دقیقاً دسته‌هایی را پنهان می‌کند که در پروژه‌های مالی/distributed می‌توانند به null dereference، schema mismatch، پرداخت غلط یا bypass منطق منجر شوند.

## توزیع خطاهای Strict

| Identifier | تعداد | تفسیر اولیه |
|---|---:|---|
| `missingType.iterableValue` | ۳٬۴۴۳ | debt type برای آرایه‌ها؛ اولویت پایین‌تر، ولی مانع تحلیل قابل‌اعتماد است |
| `property.notFound` | ۱٬۶۳۴ | پرخطر؛ بخشی ناشی از contract مبهم DB (`object`) و بخشی احتمالاً property واقعیِ اشتباه |
| `cast.int` | ۵۸۱ | مرز ورودی/DB/API باید validate شود |
| `argument.type` | ۵۷۶ | بالقوه runtime/security bug، مخصوصاً crypto/payment/HMAC |
| `cast.string` | ۳۴۷ | مرز دادهٔ خارجی و serialization |
| `return.type` | ۳۳۵ | قراردادهای نادرست یا خروجی mixed |
| `offsetAccess.nonOffsetAccessible` | ۳۱۲ | پاسخ API/JSON بدون schema/guard |
| `method.notFound` | ۲۲۰ | پرخطر؛ ممکن است runtime fatal یا API mismatch باشد |
| `property.nonObject` | ۱۸۳ | null/mixed dereference بالقوه |
| `method.nonObject` | ۱۳۵ | null/mixed method call بالقوه |
| `deadCode.unreachable` | ۱۱۵ | کد مرده یا contract اشتباه |

فایل‌های با بیشترین تراکم: `FeatureFlagController` (۱۱۴)، `EscrowService` (۱۱۳)، `QRCode` (۱۰۴)، `FinancialEscrowService` (۹۲)، `AdsBudgetSettlementService` (۸۷)، `VitrineService` (۸۳)، `SocialTaskService` (۷۹) و `InfluencerCommandService` (۷۸).

## موارد با اولویت بررسی دستی (بدون ignore)

### P0 — EscrowService

PHPStan صریحاً گزارش می‌دهد:

- property `EscrowService::$walletService` فقط read می‌شود و هرگز write نمی‌شود؛
- constructor parameter `$walletService` استفاده نشده است.

این یافته با بررسی دستی منطبق است: dependency در constructor assign نشده است. همچنین مسیرهای `releaseFunds` و `partialRelease` از لحاظ semantic مالی مشکل دارند. این مورد نباید با type hint یا ignore «سبز» شود؛ اصلاح باید همراه با invariant test مالی باشد.

### P1 — SecurityHeadersMiddleware

خطای configured PHPStan هم واقعی است:

```php
array_map('trim', $configCdn)
```

`$configCdn` از config با آیتم `mixed` می‌آید ولی `trim` فقط string می‌پذیرد. راه‌حل کم‌ریسک:

```php
$sources = array_filter(
    array_map(static fn ($value): string => trim((string) $value), $configCdn)
);
```

سپس باید برای configهای string، null، int و array test اضافه شود.

### P1 — AdVideoRewardManager / HMAC

در مسیرهای verify S2S، PHPStan `hash_hmac()` را با data از نوع `string|false` و secret از نوع `mixed` گزارش می‌کند. این موضوع امنیتی است: قبل از محاسبه یا مقایسهٔ HMAC باید failure `json_encode`/payload و نبودن یا غیررشته‌ای بودن secret با fail-closed handling شود.

### P1 — CryptoApiAdapter

چندین warning از نوع offset روی `mixed`، `urlencode(mixed)`، `normalizeAddress(string)` با mixed و cast روی دادهٔ API خارجی وجود دارد. این‌ها را نباید با cast کور رفع کرد؛ باید response DTO/schema validator و fail-closed policy در تأیید تراکنش ایجاد شود. این مسیرها به‌دلیل crypto/payment در دستهٔ حساس قرار می‌گیرند.

### P1 — RateSubmissionJob

علاوه بر خروجی static، بررسی کد نشان می‌دهد نتیجهٔ `taskModel->find()` بدون guard با `$task->user_id` خوانده می‌شود. تسک حذف‌شده/یتیم می‌تواند worker/cron را fatal کند. پیش از دسترسی باید null guard و test task-orphan اضافه شود.

### P1 — Migration / Dispute / Coupon

این سه مورد در گزارش معماری مستقل ثبت شده‌اند و broad ignoreهای کنونی می‌توانند علائم آن‌ها را پنهان کنند:

- MigrationManager با multi-statement `PDO::exec()`؛
- Dispute create که status/deadline را persist نمی‌کند؛
- Coupon update که هم guard شکسته دارد و هم جدولش `updated_at` ندارد.

### P2 — Core contracts

`core/Database.php` به‌تنهایی ۷۰ finding دارد: PDO/mixed contract، return type، array generic و method call روی mixed. این‌ها غالباً علت آبشار ۱٬۶۳۴ warning property روی `object` هستند. راه درست، ignore نیست؛ باید contractهای Database/Model/QueryBuilder به‌صورت مرحله‌ای و با migration call-siteها دقیق شوند.

`core/Response.php` نیز به‌دلیل `send(): void` با وجود throw همیشگی، returnهای void-used و `view()` دارای کد پس از send، contract ناسازگار دارد.

## روش رفع یک‌به‌یک

۱. ابتدا فقط findingهای P0/P1 بالا را در PRهای جدا اصلاح می‌کنیم؛ هیچ broad ignore جدیدی پذیرفته نمی‌شود.

۲. برای هر finding این چرخه الزامی است:

- reproduce یا یک test قرمز کوچک؛
- اصلاح حداقلی در یک concern؛
- unit/integration test سبز؛
- اجرای PHPStan Strict روی فایل یا module مربوط؛
- اجرای PHPUnit هدفمند؛
- ثبت دلیل/نتیجه در این گزارش یا ADR.

۳. سپس root contracts (`Database`, `Model`, `QueryBuilder`) را در یک epic مستقل می‌سازیم. تا آن زمان هیچ‌کدام از ۱٬۶۳۴ property finding صرفاً suppress نمی‌شوند؛ هرکدام یا با DTO/shape/docblock قابل‌اثبات می‌شوند یا به bug ticket تبدیل می‌شوند.

۴. تنها پس از تثبیت قراردادها، baseline استاندارد PHPStan می‌تواند برای debt باقیمانده تولید شود. baseline باید path+message+count داشته باشد، `reportUnmatchedIgnoredErrors: true` فعال باشد و هر تغییرش review شود. baseline هرگز جای fix برای financial/security/runtime path نیست.

## Artifactهای قابل بررسی

- خروجی کامل machine-readable همهٔ ۹٬۴۸۱ finding:
  `test-results/audit/phpstan-level9-strict.json`
- config موقت بدون ignore:
  `test-results/audit/phpstan-level9-strict.neon`
- گزارش معماری/مالی قبلی:
  `AUDIT_FIXES_2026-07-24.md`

## اجرای مورد اول — SecurityHeadersMiddleware ✅

این مورد به‌صورت مستقل و test-first اصلاح شد؛ هیچ ignore جدیدی اضافه نشد.

### reproduction پیش از اصلاح

دو تست جدید با config شامل `null`، عدد، array، object و رشتهٔ خالی اجرا شدند. نسخهٔ قبلی در `array_map('trim', $configCdn)` با این خطا شکست می‌خورد:

```text
TypeError: trim(): Argument #1 ($string) must be of type string, array given
```

### تغییر اعمال‌شده

- `normaliseCspSources()` اضافه شد؛ فقط stringهای non-empty را trim و نگه می‌دارد و هر مقدار non-string را **discard** می‌کند، نه cast. این رفتار برای CSP امن‌تر از تبدیل عدد/object به token نامعتبر است.
- configهای `app.env` و `app.asset_url` به string امن normalise شدند.
- headerهای request-id/correlation-id تنها اگر string بدون CR/LF باشند reflect می‌شوند.
- `json_encode()` مربوط به `Report-To` تنها در صورت خروجی string به Response داده می‌شود.
- dependency و helperهای بدون مصرف Session/generateNonce حذف شدند.

### اثبات پس از اصلاح

| آزمون | نتیجه |
|---|---|
| lint دو فایل تغییرکرده | PASS |
| PHPUnit هدفمند SecurityHeaders | **3 tests / 3 assertions / PASS** |
| PHPStan Level 9 Strict روی middleware + test | **0 error** |
| PHPStan با config اصلی repository | **exit 0** |
| PHPUnit کامل با MariaDB/Redis و HTTP server | 2215 tests / 4262 assertions / فقط 1 failure شناخته‌شدهٔ `.env` / 3 skip Redis-unavailable |

failure نهایی PHPUnit ناشی از وجود `.env` داخل سورس/آرشیو است، نه این change-set؛ همان P0 security debt گزارش قبلی است.

## ترتیب ادامهٔ امن

1. **Escrow epic** به‌صورت یک change-set کامل (نه فقط assignment walletService)؛
2. `RateSubmissionJob` null guard؛
3. HMAC/crypto API boundary validation؛
4. Migration runner؛
5. Dispute/Coupon/concurrency؛
6. type-contract foundation و کاهش گروهی خطاهای PHPStan.
