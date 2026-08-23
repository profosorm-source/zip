# فاز ۲۰ — صفر واقعی در shard `Services/Search`

تاریخ راستی‌آزمایی: ۲۰۲۶-۰۸-۲۳

## نتیجهٔ shard

| مورد | پیش از اصلاح | پس از اصلاح |
|---|---:|---:|
| خطاهای PHPStan Level 9 | ۲۴ | **۰** |
| فایل‌های دارای خطا | ۸ | **۰** |

عدد اولیه از handoff جلسهٔ قبل آمده است. inventory قابل جایگزینی در این مسیر
ثبت شده است:

```text
phase20-app-models-zero-shards/services-search.json
```

این نتیجه فقط مربوط به `app/Services/Search` است و ادعای صفر شدن کل پروژه
نیست.

## اصلاحات اصلی

- قرارداد `stdClass` برای تمام ردیف‌های `fetchAll()` در Gatewayها، Schema و
  Projection به‌صورت runtime بررسی می‌شود؛ آرایه یا scalar به‌طور صوری به row
  تبدیل نمی‌شود.
- مقدار cache ابتدا به‌عنوان map با کلیدهای string بررسی می‌شود و سپس هر
  provider قرارداد دقیق `items`, `total` و `metadata` را validate می‌کند.
- `SchemaInspector` برای boolean cache، list رشته‌ای ستون‌ها، rowهای schema و
  identifierهای SQL مرز صریح دارد.
- `SearchProjectionRepository` فیلترهای `scope`، `module(s)`، `entity_type` و
  `owner_id` را بدون coercion اعتبارسنجی می‌کند و خطای driver را از malformed
  result جدا نگه می‌دارد.
- `SearchResult` و `SearchIndexer` قرارداد list/map را در producer/boundary
  واقعی enforce می‌کنند.
- آداپترهای legacy در `SearchOrchestrator` اکنون term، filter، limit و offset
  واقعی `SearchQuery` را پاس می‌دهند؛ cast صوری `SearchQuery` به array حذف شد.

هیچ baseline، `ignoreErrors`، suppression یا cast صوری برای سبز کردن تحلیلگر
اضافه نشده است.

## شواهد اجرا

### PHPStan

کانفیگ مستقل و honest در پروژه نصب می‌شود:

```bash
php vendor/bin/phpstan analyse -c phpstan-search.neon --no-progress
```

این کانفیگ فقط `app/Services/Search` را target می‌کند، وابستگی‌های واقعی
`app`, `core`, `helpers` را scan می‌کند و baseline/ignore ندارد.

خروجی راستی‌آزمایی:

```text
[OK] No errors
```

### تست رفتاری و syntax

```text
SearchEcosystemTest:             8 tests, 29 assertions — PASS
SearchCacheContractBehaviorTest: 1 test, 3 assertions — PASS
SearchRuntimeBehaviorTest:       2 tests, 4 assertions — PASS
SearchSystemFixTest:             4 tests, 12 assertions — PASS
SplitServicesTest:               8 tests, 14 assertions — PASS
```

مجموع تست‌های اجراشده: **۲۳ تست، ۶۲ assertion — PASS**.

تمام ۱۵ فایل `app/Services/Search/*.php` نیز با syntax probe PHP 8.4 بررسی
شدند: `syntax-ok`.

تست Integration مربوط به MariaDB در runner PHP-WASM قابل اجرا نبود؛ بنابراین
برای آن نتیجهٔ ساختگی PASS ثبت نشده است.
