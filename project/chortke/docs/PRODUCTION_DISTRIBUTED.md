# Production Readiness for Distributed Systems Patterns (چرتکه)

این سند راهنمای عملیاتی برای اجرای پایدار الگوهای Distributed در محیط Production است.

## ۱. Workerهای ضروری که باید همیشه در حال اجرا باشند

| Worker / Command              | هدف                                      | تعداد پیشنهادی | Restart policy |
|-------------------------------|------------------------------------------|------------------|----------------|
| `queue:work --daemon`         | پردازش jobهای معمولی (از جمله eventها)   | 2–4              | always         |
| `outbox:publish`              | انتشار eventهای Outbox                   | 1 (هر 10-30 ثانیه) | always (یا cron) |
| `dlq:work`                    | پردازش پیام‌های ناموفق (poison)         | 1                | always         |

### پیشنهاد اجرا با Supervisor (Ubuntu/Debian)

فایل‌های نمونه در `deploy/supervisor/` قرار دارند.

مثال `chortke-queue.conf`:
```ini
[program:chortke-queue]
command=php /var/www/chortke/cli.php queue:work --daemon --sleep=1 --tries=3
directory=/var/www/chortke
user=www-data
numprocs=3
autostart=true
autorestart=true
stdout_logfile=/var/log/chortke/queue.log
stderr_logfile=/var/log/chortke/queue-error.log
```

مثال `chortke-outbox.conf`:
```ini
[program:chortke-outbox]
command=php /var/www/chortke/cli.php outbox:publish
directory=/var/www/chortke
user=www-data
autostart=true
autorestart=true
stdout_logfile=/var/log/chortke/outbox.log
stderr_logfile=/var/log/chortke/outbox-error.log
```

سپس:
```bash
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl start chortke-queue:*
sudo supervisorctl start chortke-outbox
```

## ۲. Cron / Timer برای کارهای دوره‌ای

اگر نمی‌خواهید `outbox:publish` را همیشه به صورت daemon اجرا کنید، می‌توانید از cron استفاده کنید:

```bash
* * * * * cd /var/www/chortke && php cli.php outbox:publish >> /var/log/chortke/outbox-cron.log 2>&1
* * * * * cd /var/www/chortke && php cli.php dlq:work >> /var/log/chortke/dlq-cron.log 2>&1
```

## ۳. Health & Readiness Checks (برای Kubernetes / Load Balancer)

- **Liveness**: `GET /health/live` یا `GET /system/distributed-health`
- **Readiness**: `GET /health/ready` (اگر موجود باشد) یا همان health با چک عمیق‌تر

دستور `distributed:health` و endpointهای آن طوری طراحی شده‌اند که:
- اگر تعداد pending outbox خیلی بالا باشد → می‌توانید alert بزنید
- اگر DLQ بزرگ باشد → می‌توانید به صورت خودکار scale worker اضافه کنید یا هشدار دهید

## ۴. Monitoring و Alerting پیشنهادی

متریک‌های کلیدی که باید مانیتور کنید:

- `outbox_pending` > 100 → Warning
- `outbox_failed` + `failed_jobs_total` > 20 → Critical
- `idempotency_pending` خیلی بالا → ممکن است bottleneck باشد
- تعداد workerهای در حال اجرا (از طریق supervisor یا `ps`)

می‌توانید از endpoint `/metrics/distributed` برای Prometheus scrape استفاده کنید.

## ۵. تست در Production-like Environment

قبل از deploy واقعی:
1. `php cli.php simulate:traceable-event --type=wallet.transfer --user=5`
2. صبر کنید یا `php cli.php outbox:publish` را اجرا کنید
3. `php cli.php distributed:health` را چک کنید
4. در لاگ‌ها `correlation_id` را جستجو کنید

## ۶. نکات مهم امنیتی و عملیاتی

- workerها را با کاربر `www-data` یا کاربر محدود اجرا کنید.
- لاگ workerها را rotate کنید (logrotate).
- `QUEUE_CONNECTION=redis` را در production حتماً ست کنید (نه database یا sync).
- برای مقیاس بالا، تعداد `queue:work` را افزایش دهید و از multiple queues استفاده کنید.

## ۷. بازیابی از مشکل

- اگر outbox خیلی عقب افتاد → `php cli.php outbox:publish` را چند بار دستی اجرا کنید.
- اگر DLQ پر شد → از `queue:failed:retry-batch` یا `dlq:work` استفاده کنید.
- برای پاکسازی idempotency قدیمی → `php cli.php idempotency:cleanup`

این سند را با تیم Ops به اشتراک بگذارید.
