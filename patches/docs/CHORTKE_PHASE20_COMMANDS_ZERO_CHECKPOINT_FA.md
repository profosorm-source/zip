# فاز ۲۰ — صفر واقعی در shard `Commands`

تاریخ راستی‌آزمایی: ۲۰۲۶-۰۸-۲۳

## نتیجهٔ shard

یک اسکن تازه و مستقل با PHPStan Level 9 روی `app/Commands` انجام شد؛ عدد
قدیمی handoff مبنا قرار نگرفت، چون با وضعیت فعلی checkout منطبق نبود.

| مورد | پیش از اصلاح | پس از اصلاح |
|---|---:|---:|
| خطاهای PHPStan Level 9 | ۴۳ | **۰** |
| فایل‌های دارای خطا | ۳ | **۰** |

inventory قابل استفاده:

```text
phase20-app-models-zero-shards/commands.json
```

این نتیجه فقط مربوط به `app/Commands` است و ادعای صفر شدن کل پروژه نیست.

## اصلاحات

- آرایهٔ ورودی CLI در `FeatureFlagCommand` و `IdempotencyCommand` به‌عنوان
  positional list مستند و بررسی می‌شود؛ offsetهای عددی روی `array<string,mixed>`
  پنهان نشده‌اند.
- نام، توضیح، درصد rollout، تاریخچه و تعداد روزهای cleanup در مرز command
  با نوع واقعی بررسی می‌شوند؛ تبدیل صوری `(int)` برای ورودی خارجی حذف شد.
- فلگ `--dry-run` در `IdempotencyCommand` به‌صورت صریح به boolean معتبر parse
  می‌شود و مقدارهایی مانند `false` دیگر به‌طور اشتباه true نمی‌شوند.
- `QueueFailedCommand` اکنون شکل آرایه‌ای را که `CliDispatcher` واقعاً به
  پارامتر `$args` می‌دهد مصرف می‌کند؛ `retry` و `forget` شناسهٔ مثبت integer
  معتبر می‌خواهند.
- ثبت CLI برای `feature:*` و `idempotency:*` با subcommandهای مستند هم‌خوان
  شد؛ ثبت قبلی فقط نام پایه را match می‌کرد.

هیچ baseline، `ignoreErrors`، suppression یا cast صوری برای سبز کردن تحلیلگر
اضافه نشده است.

## شواهد اجرا

کانفیگ مستقل:

```text
patches/configs/phpstan-commands.neon
```

gate بازاعمال:

```text
patches/apply-phpstan-fixes.sh
```

نتیجهٔ PHPStan با `treatPhpDocTypesAsCertain: true`:

```text
[OK] No errors
```

تست commandهای موجود:

```text
AllCommandsVerificationTest: 18 tests, 18 assertions — PASS
```

syntax probe برای درخت پروژه نیز `syntax-ok` شد.
