# رسیدن به صفر واقعی در PHPStan L9 (توصیهٔ ۲)

تاریخ: ۲۰۲۶-۰۸-۲۱ — شاخه: `arena/01a020bc-zip`

## خلاصهٔ نتیجه

| gate | قبل | بعد |
|---|---|---|
| `phpstan.neon` (canonical، app+core، L9) | ۸ خطا | **۰** |
| `core/` بدون هیچ `ignoreErrors` (`phpstan-core-honest.neon`) | ۲۲ خطا | **۰** |
| `phpstan_core.neon` (پس از حذف الگوهای پتویی) | ۰ کاذب | **۰ واقعی** |
| `phpstan_full.neon` (پس از حذف الگوهای پتویی) | ۰ کاذب | **۰ واقعی** |

تفاوت «۰ کاذب» و «۰ واقعی»: پیش از این کار، صفرِ گزارش‌شده حاصل الگوهای سرکوب‌گر
عام بود. اکنون آن الگوها **حذف شده‌اند** و خروجی همچنان صفر است.

## الگوهای پتویی حذف‌شده

`phpstan_core.neon`:
- `'#Call to an undefined method .*::#'` — هر فراخوانی متد ناموجود در کل هسته را می‌بلعید.
- `'#but returns mixed#'` — هر ناسازگاری نوع بازگشتی را پنهان می‌کرد.

`phpstan_full.neon`:
- `'#Method .* should return string but returns mixed#'`
- `'#Method .* should return .* but returns mixed#'`

## فهرست رفع‌های واقعی

| فایل:خط | ماهیت خطا | اصلاح انجام‌شده |
|---|---|---|
| `app/Traits/ClientInfoTrait.php:52` | `mixed` به‌جای `?int` | باریک‌سازی با `is_int($v) ? $v : null` |
| `app/Services/MigrationService.php:366` | cast مستقیم `(string)` روی `mixed` | باریک‌سازی با `is_scalar()` |
| `core/Sql/SafeExpression.php:510,513` | **مثبت کاذب** | hoist کردن `peek()` در متغیر محلی `$after` + جداسازی `$isIdent`/`$isStar` |
| `core/GenericEvent.php:38` | گارد زائد | `(array) $this->getData()` |
| `core/Request.php:261` | `is_array()` زائد در `array_replace` | حذف گارد |
| `core/Request.php:361` | `func_get_args()` مرده در `only()` | حذف شاخهٔ دست‌نیافتنی |
| `core/Request.php:451` | PHPDoc نادرست `json()` | `@return array<array-key, mixed>\|null` (امضای `?array` **حفظ شد**) |
| `core/EventDispatcher.php:244` | `?? 'handle'` روی offset همیشه‌موجود | `$listener[1]` |
| `core/EventDispatcher.php:309` | `is_array()` زائد | حذف گارد |
| `core/EventDispatcher.php:398` | `=== ''` روی `non-empty-string` | فقط `=== null` |
| `core/ExceptionHandler.php:289,327` | `?? null` روی کلید `function` که همیشه هست | دسترسی مستقیم |
| `core/ExceptionHandler.php:294,330` | `is_string()` روی `string` خالص | انتساب مستقیم |
| `core/QueryBuilder.php:761` | `is_object()` زائد روی `list<\stdClass>` | دسترسی مستقیم به پراپرتی |
| `core/Scheduler.php:154,276` | `?? 600` روی کلید همیشه‌موجود | `(int) $job['interval']` |
| `core/IdempotencyKey.php:667` | `?: time()` روی `strtotime` غیرکاذب | حذف fallback |
| `core/Session.php:127` | `\|\| $headersSent` اثبات‌پذیرْ زائد | `if ($isCli)` + کامنت ارجاع به گارد خط ۵۸ |
| `core/Event.php:18`, `core/IdempotencyKey.php:583` | PHPDoc دروغ‌گو | اصلاح **فقط PHPDoc**؛ گاردهای زمان اجرا حفظ شدند |
| `app/Events/{NotificationChannelRequested,NotificationRequested,Withdrawal}Event.php` | `array` بدون نوع generic | افزودن `@param array<string, mixed>` و `@var` |

## دو درس فنی

1. **`@phpstan-impure` باریک‌سازی فراخوانی‌های تکراری را خنثی نمی‌کند.** در
   `SafeExpression` افزودن آن هیچ اثری نداشت (۲۲ → ۲۲). راه‌حل مؤثر، ذخیرهٔ نتیجهٔ
   `peek()` در یک متغیر محلی بود.
2. **امضای عمومی را برای خوشحال‌کردن تحلیلگر تغییر ندهید.** تبدیل
   `Request::json(): ?array` به `array` تحلیلگر را راضی می‌کرد، اما ۲۰+ فراخوان‌کننده
   با `?? []` وجود دارد؛ امضا بازگردانده شد و فقط PHPDoc دقیق گردید.

## عدم رگرسیون رفتاری

- `phpunit.runtime.xml` → **OK (۱۲۵۷ تست، ۳۹۸۸ assertion)**
- `phpunit.architecture.xml` → **OK (۸۱۶ تست، ۳۰۶۲ assertion)**
- `phpunit.xml` (Unit+Integration) → **۲۰۷۶ تست، ~۷۰۵۰ assertion، ۰ شکست، ۳ skip**

> نکته: در یکی از اجراها `RedisGracefulTest` یک شکست نشان داد. سه اجرای پیاپی بعدی
> کاملاً سبز بودند؛ این تست به در دسترس بودن لحظه‌ای Redis وابسته است و شکست آن
> **فلیک محیطی** است، نه رگرسیون ناشی از این تغییرات.

## بازاعمال پس از ریست sandbox

```bash
/home/user/zip/patches/apply-phpstan-fixes.sh [مسیر_درخت_پروژه]
```
پیش‌فرض مسیر: `/home/user/extract/workspace1e/chortke`. اسکریپت در پایان هر دو
gate را اجرا و نتیجه را چاپ می‌کند.
