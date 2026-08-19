# نصب‌کننده گرافیکی فوق قوی چرتکه (نسخه ۳.۰)

## ویژگی‌های کلیدی
- رابط کاربری زیبا و کاملاً فارسی (۷ مرحله)
- بررسی واقعی پیش‌نیازها
- تست زنده اتصال دیتابیس و Redis
- تولید خودکار APP_KEY و SECURITY_API_TOKEN_SECRET
- ورودی کلیدهای API خارجی (Zarinpal, Kavenegar, Tronscan و ...)
- انتخاب Driver صف (redis / database)
- انتخاب روش راه‌اندازی workerها (Supervisor / systemd / Docker / دستی)
- اجرای خودکار مایگریشن + ساخت کاربران ادمین/تستی
- قفل امنیتی قوی (installed.lock)
- چک‌لیست نهایی با دستورات دقیق CLI و Supervisor
- هشدار واضح برای حذف/تغییر نام پوشه install بعد از موفقیت

## نحوه استفاده
1. پروژه را روی سرور آپلود کنید.
2. به این آدرس بروید:
   ```
   http://your-domain.com/install/
   ```
3. مراحل را پر کنید.
4. **حتماً** بعد از موفقیت، پوشه `install` را حذف کنید یا نام آن را عوض کنید (مثلاً `install-done`).

## امنیت
- اگر فایل `storage/installed.lock` وجود داشته باشد، installer غیرفعال می‌شود.
- تمام مقادیر حساس فقط در `.env` نوشته می‌شوند.

## بعد از نصب
- workerها را طبق راهنمایی مرحله آخر راه‌اندازی کنید (Supervisor توصیه می‌شود).
- تست کنید:
  ```bash
  php cli.php distributed:health
  php cli.php simulate:traceable-event --type=install.test --user=1
  ```
