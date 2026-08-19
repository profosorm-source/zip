# گزارش تست فلو اجرای تسک‌ها — ۲۰۲۶/۰۶/۱۹

## دامنه تست
سه جریان داخل بازار متمرکز تسک‌ها بررسی شد:

- Social Task
- SEO Task
- Custom Task

هدف اصلی این تست این بود که دکمه «شروع» در بازار تسک‌ها دیگر ad_id را مستقیم به صفحه execute نفرستد، بلکه ابتدا execution/submission واقعی ساخته شود و سپس کاربر به صفحه اجرای درست برود.

## نتیجه کلی
✅ تست مرورگر واقعی Playwright روی prototype تعاملی پاس شد.

خروجی تست:

```json
{
  "ok": true,
  "errors": []
}
```

## فلوهای بررسی‌شده

### ۱) Social Task
مسیر تست‌شده:

```text
POST /social-tasks/start
GET  /social-tasks/{execution_id}/execute
POST /social-tasks/{execution_id}/submit
```

Payload شروع:

```json
{
  "ad_id": "101",
  "task_id": "101",
  "_csrf_token": "TEST_CSRF"
}
```

Payload ارسال مدرک:

```json
{
  "idempotency_key": "SOC_...",
  "active_time": "2",
  "proof_url": "",
  "proof_text": "پیج را فالو کردم و نام کاربری من test_user است."
}
```

### ۲) SEO Task
مسیر تست‌شده:

```text
POST /seo/start
GET  /seo/{execution_id}/execute
POST /seo/{execution_id}/complete
```

Payload شروع:

```json
{
  "ad_id": "202",
  "task_id": "202",
  "_csrf_token": "TEST_CSRF"
}
```

Payload تکمیل:

```json
{
  "duration": 1,
  "scroll_depth": 60,
  "interactions": 3,
  "scroll_speed": 0,
  "mouse_pattern": "normal",
  "pause_count": 1,
  "interaction_types": ["external_open", "return_to_task"],
  "behavior": {
    "scroll_speed": 0,
    "mouse_pattern": "normal",
    "pause_count": 1,
    "interaction_types": ["external_open", "return_to_task"]
  },
  "_csrf_token": "TEST_CSRF"
}
```

نکته مهم: برای جلوگیری از رد شدن بی‌دلیل تسک‌های SEO در سایت‌هایی که داخل iframe اجازه ردیابی نمی‌دهند، صفحه اجرا دکمه «باز کردن سایت هدف» دارد و سیگنال fallback را با tracker ادغام می‌کند.

### ۳) Custom Task
مسیر تست‌شده:

```text
GET  /custom-tasks/{id}
POST /custom-tasks/{id}/start-execution
GET  /custom-tasks/submissions/{submission_id}/proof
POST /custom-tasks/submissions/{submission_id}/submit-proof-action
```

Payload شروع:

```json
{
  "task_id": "303"
}
```

Payload ارسال مدرک:

```json
{
  "task_execution_id": "9100",
  "idempotency_key": "CUSTOM_TEST",
  "proof_text": "ثبت نام با نام کاربری test_user انجام شد.",
  "proof_file": "[object File]"
}
```

## فایل‌های Screenshot

```text
tools/browser-preview/screenshots/task-flow-market-initial.png
tools/browser-preview/screenshots/task-flow-after-seo-custom.png
```

## محدودیت محیط
در این sandbox سرویس MySQL/PDO MySQL در دسترس نیست، بنابراین تست با دیتابیس واقعی اجرا نشد. تست‌های انجام‌شده شامل این موارد بود:

- lint فایل‌های PHP مرتبط
- syntax check فایل‌های JS مرتبط
- route audit بدون MISSING
- تست مرورگر واقعی Chromium/Playwright روی فلو تعاملی و payloadهای واقعی frontend

## وضعیت route audit

```text
python3 /tmp/audit_routes.py | grep MISSING
```

خروجی: خالی.

## وضعیت legacy searchAdTasks

```text
grep -RIn "searchAdTasks" app config routes tests
```

خروجی: خالی.
