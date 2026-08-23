# فاز ۲۰ — صفر واقعی در shard `Models`

تاریخ راستی‌آزمایی: ۲۰۲۶-۰۸-۲۳

## نتیجهٔ shard

یک اسکن مستقل و بدون suppression روی هر ۹۶ فایل `app/Models` انجام شد:

| مورد | پیش از اصلاح | پس از اصلاح |
|---|---:|---:|
| خطاهای PHPStan Level 9 | ۱۹ | **۰** |
| فایل‌های دارای خطا | ۱۱ | **۰** |

inventory قابل استفاده:

```text
phase20-app-models-zero-shards/models.json
```

این نتیجه فقط مربوط به `app/Models` است و ادعای صفر شدن کل پروژه نیست.

## اصلاحات کلیدی

- `ContentSubmission` پس از گاردهای required، دادهٔ فیلد را از همان boundary معتبر
  مصرف می‌کند؛ دسترسی‌های `??` که روی row map صوری بودند حذف شد.
- قرارداد `stdClass` مدل پایه در `Coupon` و `SentryModel` رعایت شد. ردیف duration
  در P95 قبل از مصرف به مقدار عددی معتبر narrow می‌شود و row ساختاری ناقص با
  `UnexpectedValueException` رد می‌شود.
- وضعیت intent در `CryptoDepositIntent` با `array_key_exists` و بررسی string
  خوانده می‌شود؛ نتیجهٔ malformed به‌طور silent به وضعیت معتبر تبدیل نمی‌شود.
- فیلترهای عددی `InfluencerModel` در helperهای واقعی parse می‌شوند: حداقل follower
  عدد صحیح غیرمنفی و قیمت عددی finite غیرمنفی است؛ ورودی نامعتبر fail-fast است.
- محاسبهٔ expiry در `Escrow` و پایان vacation در `UserVacation` fallback خاموش به
  `time()` ندارند و در صورت شکست parser خطای صریح می‌دهند.
- `SystemTelemetryModel` برای بازه‌های زمانی از helper واحد fail-fast استفاده می‌کند؛
  `TransactionQuery` نتیجهٔ `FETCH_ASSOC` را به list واقعی normalize می‌کند.
- `Transaction` فقط پس از `isset` به idempotency key دسترسی مستقیم دارد و
  `revokeRememberToken` موفقیت واقعی update را گزارش می‌کند.

هیچ baseline، `ignoreErrors`، suppression یا cast صوری برای سبز کردن تحلیلگر
اضافه نشده است.

## شواهد اجرا

کانفیگ مستقل:

```text
patches/configs/phpstan-models.neon
```

نتیجهٔ PHPStan با `treatPhpDocTypesAsCertain: true`:

```text
[OK] No errors
```

تست‌های رفتاری مرتبط:

```text
InfluencerModelTest:  5 tests, 13 assertions — PASS
SentryModelSRPTest:  94 tests, 191 assertions — PASS
UserVacationTest:     3 tests, 5 assertions — PASS
CouponServiceTest:   15 tests, 29 assertions — PASS
```

مجموع: **۱۱۷ تست، ۲۳۸ assertion — PASS**.

syntax probe کل درخت نیز `syntax-ok` شد. تست Integration وابسته به MariaDB در
runner PHP-WASM اجرا نشده و به‌عنوان PASS ثبت نشده است.
