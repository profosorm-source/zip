# SRP Roadmap — SentryModel Decomposition

**تاریخ تحلیل:** ۱۴۰۴/۰۴/۱۸  
**وضعیت فعلی:** ✅ Interface استخراج شده | 📋 شکستن فیزیکی در فاز بعد

---

## وضعیت فعلی

`SentryModel` یک کلاس ۱۱۸۷ خطی با **۸۰ متد** است که **۷ مسئولیت مجزا** را مدیریت می‌کند.

### مشکل SRP

| مسئولیت | تعداد متد | Interface استخراج‌شده |
|----------|-----------|----------------------|
| Error Monitoring (issues, events, stats) | ۲۳ متد | `SentryErrorRepositoryInterface` ✅ |
| Performance Monitoring (transactions, P95) | ۱۰ متد | `SentryPerformanceRepositoryInterface` ✅ |
| Alerting (rules, channels, alert store) | ۱۳ متد | `SentryAlertRepositoryInterface` ✅ |
| Escalation Management | ۶ متد | `SentryEscalationRepositoryInterface` ✅ |
| Queue/DLQ (failed jobs, outbox) | ۹ متد | `SentryQueueRepositoryInterface` ✅ |
| Audit Trail queries | ۱۲ متد | `SentryAuditRepositoryInterface` ✅ |
| Error Logs (LogController) | ۸ متد | `SentryLogRepositoryInterface` ✅ |

---

## گام‌های انجام‌شده (فاز ۱ — بدون ریسک)

### ✅ Interface استخراج شد
همه ۷ Interface در `app/Contracts/Sentry/` ساخته شدند.

### ✅ SentryModel همه Interface ها را implement می‌کند
```php
class SentryModel extends Model implements
    SentryErrorRepositoryInterface,
    SentryPerformanceRepositoryInterface,
    SentryAlertRepositoryInterface,
    SentryEscalationRepositoryInterface,
    SentryQueueRepositoryInterface,
    SentryAuditRepositoryInterface,
    SentryLogRepositoryInterface
```

**فایده فوری:** Consumers می‌توانند به Interface type-hint کنند — نه به concrete SentryModel.

---

## گام بعدی (فاز ۲ — شکستن فیزیکی)

### پیش‌شرط‌ها
- [ ] Test coverage برای هر Interface به ≥۸۰٪ برسد
- [ ] هر Consumer به Interface inject شود (نه SentryModel مستقیم)
- [ ] Migration plan برای AppServiceProvider آماده شود

### نقشه شکستن

```
SentryModel (کنونی)
│
├── app/Repositories/Sentry/
│   ├── SentryErrorRepository.php         ← implements SentryErrorRepositoryInterface
│   ├── SentryPerformanceRepository.php   ← implements SentryPerformanceRepositoryInterface
│   ├── SentryAlertRepository.php         ← implements SentryAlertRepositoryInterface
│   ├── SentryEscalationRepository.php    ← implements SentryEscalationRepositoryInterface
│   ├── SentryQueueRepository.php         ← implements SentryQueueRepositoryInterface
│   ├── SentryAuditRepository.php         ← implements SentryAuditRepositoryInterface
│   └── SentryLogRepository.php           ← implements SentryLogRepositoryInterface
│
└── app/Models/SentryModel.php
    └── (Legacy Facade — deprecate پس از migration)
```

### تغییر در AppServiceProvider

```php
// فاز ۲ — bind Interface → Repository مجزا
$container->bind(SentryErrorRepositoryInterface::class, fn($c) =>
    new SentryErrorRepository($c->make(Database::class), $c->make(Logger::class))
);
// ... سایر bind ها
```

### تغییر در Consumers

```php
// ❌ قبل (به concrete وابسته)
class SentryErrorMonitor {
    public function __construct(private SentryModel $model) {}
}

// ✅ بعد (به Interface وابسته — testable)
class SentryErrorMonitor {
    public function __construct(private SentryErrorRepositoryInterface $repo) {}
}
```

---

## تأثیر بر ۱۲ Consumer فعلی

| Consumer | Interface مورد نیاز |
|----------|---------------------|
| `SentryErrorMonitor` | `SentryErrorRepositoryInterface` |
| `SentryPerformanceMonitor` | `SentryPerformanceRepositoryInterface` |
| `AlertDispatcher` | `SentryAlertRepositoryInterface` |
| `AlertRulesEngine` | `SentryAlertRepositoryInterface` |
| `EscalationManager` | `SentryEscalationRepositoryInterface` |
| `DashboardService` | `SentryErrorRepositoryInterface` + `SentryQueueRepositoryInterface` |
| `TrendAnalyzer` | `SentryErrorRepositoryInterface` + `SentryPerformanceRepositoryInterface` |
| `AdvancedAuditTrail` | `SentryAuditRepositoryInterface` |
| `LogController` | `SentryLogRepositoryInterface` |
| `SentryAdminController` | `SentryErrorRepositoryInterface` + `SentryAlertRepositoryInterface` |
| `AlertRulesBootstrapCommand` | `SentryAlertRepositoryInterface` |

---

## دلیل به تعویق انداختن شکستن فیزیکی

1. **۱۲ وابستگی** به SentryModel — refactor همزمان همه آن‌ها ریسک regression بالا دارد
2. **Test coverage ناکافی** برای Repository ها (در حال حاضر فقط reflection-based tests)
3. **تست‌های واقعی** (live server) باید قبل از هر refactor بزرگ اجرا شوند
4. **Interface استخراج** بدون شکستن — ریسک صفر، فایده فوری (DI contract)

---

## متریک‌های موفقیت فاز ۲

- [ ] هر Repository فایل مستقل دارد (max 200 خط)
- [ ] تمام Consumers به Interface inject می‌شوند
- [ ] SentryModel به عنوان Legacy Facade با `@deprecated` tag باقی می‌ماند
- [ ] تست‌های PHPUnit برای هر Repository: ≥۱۵ تست
- [ ] صفر regression در تست‌های live server
