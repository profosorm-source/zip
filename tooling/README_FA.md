# محیط بازیابی و اجرای Chortke در Arena

این پوشه برای بازیابی سریع محیط پس از reset ساخته شده است.

## منبع سورس

شاخهٔ ثابت این سشن فقط فایل `chortke.zip` را دارد. جدیدترین سورس تکمیل‌شده در remote branch زیر قرار دارد:

```text
origin/arena/01a01b16-zip
commit: 4482c75486fe4440e4b14f8f2cfacb30627c39a8
```

فایل درخواستی `CHORTKE_NEW_SESSION_HANDOFF_FA.md` در هیچ‌یک از شاخه‌های remote یا داخل ZIP پیدا نشد. نزدیک‌ترین handoff موجود، فایل `CHORTKE_TEST_PHPSTAN_CHECKPOINT_FA.md` با تاریخ ۲۰۲۶-۰۸-۱۹ است که نتیجهٔ PHPStan Level 9 روی `tests/` را صفر خطا ثبت کرده است.

## بازیابی کامل

```bash
./tooling/bootstrap.sh
PORT=8000 node tooling/php-server.mjs
```

اسکریپت bootstrap این کارها را انجام می‌دهد:

1. دریافت جدیدترین سورس سشن قبلی؛
2. بازیابی dependencyهای Composer از snapshot داخل ZIP؛
3. نصب PHP 8.4 WASM و Playwright؛
4. ساخت `.env` محلی با کلیدهای تصادفی؛
5. ساخت SQLite واقعی و seed حداقلی برای preview؛
6. آماده‌سازی صفحهٔ اصلی، login، register و health endpoint.

## علت استفاده از SQLite/PHP WASM

در این sandbox دسترسی به Debian mirror، Docker Hub، MySQL CDN و GitHub release assets مسدود بود؛ بنابراین نصب native PHP/MariaDB/Redis ممکن نشد. برای اینکه سایت واقعاً اجرا شود، PHP 8.4.23 از بستهٔ رسمی `@php-wasm/node` و پایگاه دادهٔ persistent SQLite استفاده شده است. این adapter فقط در پوشهٔ disposable `runtime/` اعمال می‌شود و سورس اصلی را تغییر نمی‌دهد.

برای تست production-grade مالی و distributed همچنان MariaDB و Redis واقعی مطابق `docker-compose.yml` لازم است.

## کنترل سلامت

```bash
curl -f http://127.0.0.1:8000/
curl -f http://127.0.0.1:8000/login
curl -f http://127.0.0.1:8000/register
curl -f http://127.0.0.1:8000/api/health
node tooling/php.mjs -v
```

نکته: PHPStan در WASM باید با `--debug` اجرا شود چون `proc_open`/پردازش موازی در WASM پشتیبانی نمی‌شود. dependencyهای Composer داخل ZIP قدیمی‌تر از lock جدید هستند؛ بنابراین نتیجهٔ معتبر صفرخطای سشن قبلی در checkpoint ثبت شده و اجرای کامل فعلی تا نصب vendor دقیق lock، gate قطعی محسوب نمی‌شود.
