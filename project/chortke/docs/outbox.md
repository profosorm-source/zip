## Transactional Outbox — مستندات

هدف: توضیح کارکرد `OutboxService` و نکات پایدارسازی انتشار پیام‌ها.

1) خلاصه
- `OutboxService` (فایل: `app/Services/OutboxService.php`) رویدادها را در جدول `outbox_events` داخل همان تراکنش ثبت می‌کند. سپس یک فرایند جدا (`OutboxPublisher`) آن‌ها را ارسال می‌کند.

2) مزایا
- جلوگیری از ناهماهنگی بین دیتابیس و سیستم پیام‌رسان (Transactional publish).
- امکان retry و DLQ routing برای پیام‌های شکست‌خورده.

3) نکات تقویت
- تمام تولیدکننده‌های رویداد باید از `OutboxService::record()` استفاده کنند.
- OutboxPublisher باید متریک‌های `published`, `failed`, `dlq` را به مانیتورینگ ارسال کند.
- تست‌های واحد و integration برای حالت‌های خطا/retry باید اضافه شوند.

4) فایل‌ها
- اجرا: `app/Services/OutboxService.php`
- پخش: `app/Services/OutboxPublisher.php`
- پیکربندی: `config/outbox.php`
