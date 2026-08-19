# Distributed Systems Patterns در چرتکه

این سند الگوهای Distributed Systems پیاده‌سازی شده در پروژه چرتکه را توضیح می‌دهد.

## 1. Transactional Outbox Pattern

### هدف
اطمینان از اینکه eventها حتماً منتشر می‌شوند حتی اگر تراکنش اصلی commit شود.

### پیاده‌سازی
- جدول: `outbox_events`
- Worker: `php cli.php outbox:publish`
- ستون‌های کلیدی: `aggregate_type`, `aggregate_id`, `event_type`, `payload`, `status`, `attempts`

### استفاده
```php
// در Listener یا Service
$db->query("INSERT INTO outbox_events (...) VALUES (...)");
```

## 2. Idempotency

### هدف
جلوگیری از پردازش تکراری درخواست‌ها (مخصوصاً در payment, transfer, task execution).

### پیاده‌سازی
- جدول: `idempotency_keys`
- سرویس: `App\Services\Shared\IdempotencyService`
- استفاده در آداپترها: AdSocialAdapter, AdTubeAdapter, BannerAdapter

### استفاده
```php
// در Adapter
$this->idempotencyService->checkAndStore($key, $operation);
```

## 3. Circuit Breaker

### هدف
جلوگیری از cascade failure هنگام ارتباط با سرویس‌های خارجی (Crypto, Bank, KYC).

### پیاده‌سازی
- کلاس: `Core\CircuitBreaker`
- استفاده در: `CryptoApiAdapter`, `CryptoExplorerAdapter`

## 4. DLQ / Failed Jobs

### هدف
مدیریت jobهای ناموفق و poison messageها.

### پیاده‌سازی
- جدول: `failed_jobs`
- Worker: `php cli.php dlq:work`
- دستورات کمکی:
  - `queue:failed:stats`
  - `queue:failed:retry`
  - `queue:failed:forget`
  - `alert:bootstrap-dlq`

## 5. Saga / Event-Driven

### هدف
مدیریت تراکنش‌های بلندمدت و distributed.

### پیاده‌سازی
- جدول: `saga_executions`
- Listenerهای متعدد (EscrowListener, FraudGuardListener, ReferralCommissionListener و ...)

## دستورات CLI مهم

```bash
# Queue
php cli.php queue:work --daemon

# Outbox
php cli.php outbox:publish

# DLQ
php cli.php dlq:work

# Idempotency
php cli.php idempotency:stats
php cli.php idempotency:cleanup

# تست Integration
php8.4 vendor/bin/phpunit tests/Integration/Distributed/
```

## نکات مهم برای Production

1. حتماً `QUEUE_CONNECTION=redis` را در `.env` production تنظیم کنید.
2. workerها را به صورت daemon اجرا کنید (supervisor/systemd).
3. `outbox:publish` و `dlq:work` را به صورت cron یا daemon اجرا کنید.
4. از `correlation_id` در لاگ‌ها برای tracing استفاده کنید.