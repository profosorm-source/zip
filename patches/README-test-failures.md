# رفع کامل خطاهای سوئیت تست — چرتکه

وضعیت پیش از اصلاح: **۲۰۷۶ تست / ۷۰۴۸ ادعا / ۸ خطا / ۳ skip**
وضعیت پس از اصلاح: **۲۰۷۶ تست / ۷۰۵۰ ادعا / ۰ خطا / ۳ skip** ✅

سه skip باقی‌مانده (`RedisGracefulTest`) رفتار درست است، نه خطا: آن‌ها مسیرِ
«Redis در دسترس نیست» را می‌آزمایند و چون Redis واقعاً بالاست، خودشان را
skip می‌کنند.

---

## ۱) باگ واقعی مالی — `Core\ValueObjects\Money` (۳ خطا)

**تست:** `MoneyBehaviorTest::test_float_arguments_are_rejected_at_the_public_boundary`
(سه دیتاست: constructor / multiply / divide)

**علت ریشه‌ای.** داکبلاک Money صراحتاً می‌گوید «تمام ورودی‌های float بلافاصله رد
شده و استثنا پرتاب می‌کنند»، اما تنها مکانیزمِ اجراکننده، تایپ نیتیو `string|int`
بود. این تایپ فقط وقتی TypeError می‌دهد که **فراخوان** در فایلی با
`declare(strict_types=1)` باشد. از هر مسیر دیگری — Reflection، `call_user_func_array`،
کانتینر DI، یا هر فایل legacy بدون strict_types — PHP در حالت coercive مقدار را
بی‌سروصدا cast می‌کرد:

| فراخوان | رفتار قبلی | نتیجه |
|---|---|---|
| `new Money(0.1)` از مسیر Reflection | cast خاموش به `0` | **از دست رفتن پول** |
| `$m->multiply(0.1)` | cast خاموش به `0` | **صفر شدن مبلغ** |
| `$m->divide(0.1)` | cast خاموش به `0` | `InvalidArgumentException: Division by zero` (خطای گمراه‌کننده) |

یعنی تست یک نقص امنیتی-مالی واقعی را نشان می‌داد، نه یک تست بد.

**اصلاح.** تایپ نیتیو عمداً به `string|int|float` گسترش یافت تا مقدار float به
جای cast خاموش وارد بدنه شود و نگهبان صریح `Money::rejectFloat()` همان TypeError
مورد انتظارِ قرارداد عمومی را — مستقل از حالت strict/coercive فراخوان — پرتاب کند.
قرارداد واقعی همچنان `string|int` است و در `@param` مستند شده (PHPStan آن را
اعمال می‌کند). نگهبان روی `__construct`، `multiply`، `divide` و `percentage` نصب شد.

## ۲) گارد ناسازگار — `ReconciliationService::requireTransactionRow()` (۱ خطا)

**تست:** `ReconciliationServiceTest::test_reconcile_payment_returns_success_if_already_completed`

**علت ریشه‌ای.** گارد، وجودِ کلیدهای `user_id`/`transaction_id`/`metadata` را در
ردیف الزامی می‌کرد و در غیر این صورت `UnexpectedValueException` می‌داد. اما همه‌ی
مصرف‌کننده‌های همان کلاس این فیلدها را اختیاری فرض می‌کنند
(`(string)($transaction->transaction_id ?? null)` در خطوط ۴۰۴/۴۸۶/۵۳۲/۸۵۷).
نتیجه: هر ردیفی که از یک `SELECT` ستون‌محدود یا از تراکنش orphan می‌آمد، کل فرآیند
تطبیق را با «خطای سیستمی در فرآیند تطبیق تراکنش» می‌ترکاند — یعنی webhook یک
تراکنش نهایی‌شده هم به‌جای پاسخ موفق، خطا می‌گرفت.

**اصلاح.** گارد به‌جای رد کردن ردیف، آن را **نرمال** می‌کند: فیلدهای غایب با
`null` پر می‌شوند تا شکل کامل `TransactionRow` تضمین شود. اعتبارسنجی سخت‌گیرانه
برای فیلدهای اجباری (`id`، `type`، `amount`، `currency`، `status`) و برای مقادیر
حاضر ولی غیراسکالر دست‌نخورده باقی ماند.

## ۳) پیش‌نیازهای محیطی تست‌های Distributed (۴ خطا)

این چهار مورد باگ کد نبودند؛ دستورها و endpointها همگی سالم پیاده‌سازی شده بودند:

| تست | علت واقعی |
|---|---|
| `DistributedPatternsTest::test_simulate_traceable_event_command` | تست `shell_exec("php cli.php ...")` می‌زند و باینری `php` در PATH سراسری نبود |
| `WorkerLifecycleTest::test_distributed_health_command_runs` | همان علت |
| `HealthEndpointsTest::test_health_distributed_endpoint_exists_or_skips` | تست به وب‌سرور روی `127.0.0.1:8090` نیاز دارد (سرور ما روی 8080 بود) |
| `HealthEndpointsTest::test_metrics_distributed_returns_prometheus_or_json` | همان علت |

**اصلاح (بدون دست زدن به کد پروژه):** در `scripts/provision.sh`:
* `link_php()` — ساخت symlink‏ `/usr/local/bin/php`
* `start_test_server()` — بالا آوردن وب‌سرور تست روی `0.0.0.0:8090`
* `--test` — اجرای PHPUnit با تمام پیش‌نیازها یکجا
* `--start` و `--status` نیز این دو پیش‌نیاز را پوشش می‌دهند

تأیید دستی پس از اصلاح: `php cli.php distributed:health` و
`php cli.php simulate:traceable-event` خروجی درست می‌دهند و
`/health/distributed` و `/metrics/distributed` هر دو `200` برمی‌گردانند.

---

## اعتبارسنجی

```
php vendor/bin/phpunit --no-coverage
→ Tests: 2076, Assertions: 7050, Skipped: 3   (۰ خطا)

php vendor/bin/phpstan analyse core/ValueObjects/Money.php \
    app/Services/ReconciliationService.php -c phpstan.neon
→ [OK] No errors
```

هیچ تستی تضعیف، حذف یا skip نشد؛ هر دو اصلاح کد، تصحیح واقعیِ نقص هستند.

## فایل‌های تغییر‌یافته پروژه

* `core/ValueObjects/Money.php` — نگهبان ضد float
* `app/Services/ReconciliationService.php` — نرمال‌سازی TransactionRow
* `app/Services/MigrationService.php` — سازگاری MySQL (اصلاح قبلی)

نسخه‌های کامل در همین پوشه: `Money.php`، `ReconciliationService.php`، `MigrationService.php`
