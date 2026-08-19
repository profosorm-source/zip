# چک‌پوینت PHPStan تست‌ها — صفر خطا

تاریخ: ۲۰۲۶-۰۸-۱۹

## نتیجه قطعی

```text
PHPStan 1.12.33  Level 9  بدون ignore / baseline / @phpstan-ignore
مسیر: tests/
[OK] No errors
```

بدون معماری DTO، بدون هلپر سراسری تازه، بدون API تست در production.

## خط مبنا تا این سشن

```text
اولین اسکن Level 9 روی tests/     4,189 خطا در 196 فایل
پس از حذف برخورد کلاس Database    160 خطا در 26 فایل
موجودی ابتدای این سشن             ~118 خطا در 21 فایل
پس از اصلاح ریشه‌ای این سشن       0 خطا
```

## علت خطاها و الگوی رفع

تبدیل در مرز شکل‌گیری داده، نه در هر مصرف‌کننده:

- `$db->query()->fetch()` → `$db->fetch(): ?stdClass` سپس null-guard و `int_value` / `str_value` / `float_value`
- `UserService::register(): array|false` → `is_array` سپس استخراج id
- `NotificationService::sendBulk(): int` → شمارندهٔ int، نه `$res['sent']`
- پول به‌صورت رشتهٔ BCMath (`bcadd`) نه `string + 200000`
- Reflection `getValue(): mixed` در همان نقطه به `array`/`string` باریک شد
- شمارنده‌های assert از `global` به `array{pass:int,fail:int}` با ارجاع تبدیل شد تا شاخهٔ مرده ساخته نشود
- `Cache::flush()` قرارداد واقعی است، نه `flushAll`

PHPDoc در مرز تولیدکننده (نه DTO):

- `TicketService::create` / `TicketCommandService::create`  
  `array{success:false,message:string}|array{success:true,message:string,ticket_id:int}`
- `LotteryCommandService::createRound`  
  `array{success:false,message:string}|array{success:true,message:string,round_id:int}`
- `ManualDepositService::create`  
  `array{success:bool,message?:string,deposit_id?:int}`
- `AdTubeAdapter::create` شکل پاسخ adapter

تست واحد بعد از شاخهٔ شکست (`fail($message)`) به `ticket_id` / `round_id` دسترسی دارد.

## فایل‌های این سشن

تست: `OperationalEndToEndVerification`, `SecurityAndPerformanceGrandBenchmark`, `UltraExhaustive360PlatformMasterSaga`, `end_to_end_system_test`, `notification_audit_test`, `phase3_revenue_test`, `phase4_gamification_test`, `phase4_payouts_test`, `phase5_infra_heartbeat_test`, `phase5_security_audit_test`, `phase6_manual_deposit_audit_test`, `querybuilder_integration_test`, `sentry_activation_test`, `sentry_monitoring_audit_test`, `ultra_comprehensive_test`, `TicketServiceTest`, `LotteryCommandServiceBehaviorTest`.

قرارداد production: `TicketService`, `TicketCommandService`, `ManualDepositService`, `LotteryCommandService`, `AdTubeAdapter`.

## چیزهایی که عمداً انجام نشد

- `ignoreErrors` / `@phpstan-ignore` / baseline
- هلپر جدید فقط برای ساکت‌کردن آنالایزر
- فایل سورس production جدید
- تغییر `Container::make` یا جعل متد روی `Database`
