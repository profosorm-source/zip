# Chortke — Horizontal Scaling Guide

## معماری فعلی

```
                   ┌──────────────┐
                   │   Nginx LB   │  ← Reverse Proxy / Load Balancer
                   └──────┬───────┘
                          │
              ┌───────────┼───────────┐
              ▼           ▼           ▼
        ┌──────────┐┌──────────┐┌──────────┐
        │  app-1   ││  app-2   ││  app-N   │  ← PHP-FPM (Stateless)
        └──────────┘└──────────┘└──────────┘
              │           │           │
              └───────────┼───────────┘
                          │
              ┌───────────┼───────────┐
              ▼           ▼           ▼
        ┌──────────┐┌──────────┐┌──────────┐
        │ worker-1 ││ worker-2 ││ worker-N │  ← Queue Workers (Scalable)
        └──────────┘└──────────┘└──────────┘
                          │
         ┌────────────────┼────────────────┐
         ▼                ▼                ▼
   ┌───────────┐   ┌────────────┐   ┌──────────┐
   │ MariaDB   │   │   Redis    │   │ Storage  │
   │ (Primary) │   │ (Cluster)  │   │ (NFS/S3) │
   └───────────┘   └────────────┘   └──────────┘
```

## قواعد کلیدی

### ۱. Stateless بودن App
هر `app` container **stateless** هست. Session در Redis و فایل‌ها در shared storage ذخیره میشن. هر ریکوئست میتونه روی هر node پردازش بشه.

### ۲. Scheduler = فقط ۱ نمونه
⚠️ **scheduler فقط روی یک node اجرا بشه!** `cron.php` از distributed lock استفاده میکنه ولی اجرای همزمان روی چند node اتلاف منبع هست.

```yaml
# docker-compose.scale.yml
services:
  app:
    deploy:
      replicas: 3

  worker-queue:
    deploy:
      replicas: 5    # بسته به حجم صف

  scheduler:
    deploy:
      replicas: 1    # ← فقط ۱ نمونه!
```

### ۳. Queue Worker مقیاس‌پذیر
```bash
# Scale up workers
docker compose up -d --scale worker-queue=5

# Scale down
docker compose up -d --scale worker-queue=2
```

### ۴. Session در Redis
فایل `config/session.php`:
```php
return [
    'driver' => 'redis',  // NOT 'file'
];
```

### ۵. Shared Storage
فایل‌های آپلودی باید روی storage مشترک باشن:

| راه‌حل | مناسب برای |
|---|---|
| NFS volume | تک‌سرور، ساده |
| MinIO / S3 | Multi-server، production |
| GlusterFS | On-premise cluster |

```yaml
volumes:
  chortke_storage:
    driver: local
    driver_opts:
      type: nfs
      o: addr=10.0.0.1,rw,nolock
      device: ":/data/chortke/storage"
```

### ۶. MariaDB Replication
برای خواندن از replica:

```php
// config/database.php
'read' => [
    'host' => ['mariadb-replica-1', 'mariadb-replica-2'],
],
'write' => [
    'host' => 'mariadb-primary',
],
```

### ۷. Redis Sentinel یا Cluster
برای HA:

```yaml
redis:
  image: redis:7-alpine
  command: redis-server --sentinel /etc/redis/sentinel.conf
```

## چک‌لیست Scaling

- [ ] Session driver = redis
- [ ] Cache driver = redis
- [ ] فایل‌ها روی shared storage
- [ ] Scheduler فقط ۱ نمونه
- [ ] Health check روی همه سرویس‌ها
- [ ] Resource limits تعریف شده
- [ ] Log aggregation (ELK/Loki)
- [ ] Monitoring (Prometheus + Grafana)
- [ ] DB read replicas فعال
