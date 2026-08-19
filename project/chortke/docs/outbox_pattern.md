## الگوی Transactional Outbox — مستندات و چک‌لیست

هدف این سند: توضیح اینکه چرا Outbox استفاده شده، چگونه کار می‌کند و چه مواردی باید برای پایداری و مشاهده‌پذیری تقویت شود.

1) چرا Outbox؟
- وقتی همزمان باید تغییر در دیتابیس و ارسال پیام به queue/سیستم پیام‌رسان انجام شود، ممکن است یکی موفق و دیگری شکست بخورد.
- Outbox این مشکل را با ذخیرهٔ پیام در همان تراکنش دیتابیس حل می‌کند، سپس انتشار واقعی را از پردازش جداگانه انجام می‌دهد.

2) پیاده‌سازی فعلی در پروژه
- `app/Services/OutboxService.php`:
  - ثبت رویداد در جدول `outbox_events` داخل تراکنش.
  - پیوست کردن payload و متادیتا.
- `app/Services/OutboxPublisher.php`:
  - خواندن رویدادهای pending/failed و انتشارشان.
  - retry/backoff و انتقال به DLQ پس از شکست‌های متعدد.
- `app/Commands/OutboxPublishCommand.php`:
  - ورودی CLI برای اجرای پردازش outbox.
- `config/outbox.php`:
  - تنظیمات زمان‌بندی اجرای publisher.

3) چه مواردی باید قوی‌تر شود
- تست‌های واحد و یکپارچه برای پوشش سناریوهای retry، DLQ و انتشار موفق.
- مانیتورینگ متریک‌ها:
  - تعداد pending/outbox events
  - تعداد منتشرشده
  - تعداد منتقل‌شده به DLQ
- بررسی schema جدول `outbox_events` برای نشانگرهای TTL یا retention.
- اطمینان از اینکه تمام تولیدکننده‌های domain از `OutboxService` استفاده می‌کنند، نه درج مستقیم در queue.

4) چک‌لیست فاز 2
- [ ] افزودن تست واحد برای `OutboxService::record` (insert و audit logging).
- [ ] افزودن تست واحد برای `OutboxPublisher` در حالت بدون event و با نرخ retry.
- [ ] مستندسازی جریان `outbox_events` و مسیر CLI.
- [ ] بررسی و مستندسازی schema و retention policy برای `outbox_events`.

5) پیشنهاد عملی فوری
- با افزودن تست `OutboxService` شروع کنیم.
- سپس مستندات را به عنوان بخشی از PR بفرستیم.
