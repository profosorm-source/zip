# Chortke API v1 — مستندات کامل

**Base URL:** `https://api.chortke.com/api/v1`  
**Version:** 1.0  
**تاریخ:** ۱۴۰۴/۰۴/۱۸  
**تعداد Endpoint:** 102

---

## احراز هویت (Authentication)

تمام endpoint های محافظت‌شده نیازمند Bearer Token هستند:

```
Authorization: Bearer <token>
```

توکن‌ها از طریق `POST /auth/token` دریافت می‌شوند.

### Scopes
| Scope | دسترسی |
|-------|---------|
| `auth.manage` | مدیریت توکن‌ها |
| `user.read` | خواندن پروفایل و اطلاعات کاربر |
| `user.write` | ویرایش اطلاعات کاربر |
| `wallet.read` | خواندن موجودی و تراکنش‌ها |
| `wallet.write` | برداشت، واریز، سرمایه‌گذاری |
| `influencer.read` | خواندن پروفایل اینفلوئنسر |
| `influencer.write` | ایجاد و مدیریت سفارش |
| `social.read` | خواندن تسک‌های سوشال |
| `social.write` | ایجاد و مدیریت تسک |
| `realtime` | دسترسی به WebSocket/Polling |
| `verification.read` | وضعیت تأیید هویت |
| `verification.write` | ارسال مدارک KYC |

---

## فرمت پاسخ‌ها

### موفقیت
```json
{
    "success": true,
    "data": { ... }
}
```

### خطا
```json
{
    "success": false,
    "error": "پیام خطا",
    "code": "ERROR_CODE"
}
```

### خطای اعتبارسنجی
```json
{
    "success": false,
    "errors": {
        "field_name": ["پیام خطا"]
    }
}
```

---

## ۱. Health & System

### `GET /ping`
بررسی دسترس‌پذیری API.

**Auth:** ندارد

**Response:**
```json
{ "pong": true }
```

---

### `GET /health/live`
بررسی liveness سرور.

**Auth:** ندارد  
**Response:** `200 OK` یا `503 Service Unavailable`

---

### `GET /health/ready`
بررسی readiness (database، redis، queue).

**Auth:** ندارد  
**Response:** `200 OK` یا `503 Service Unavailable`

---

### `GET /config`
تنظیمات عمومی اپلیکیشن (برای client).

**Auth:** ندارد  

**Response:**
```json
{
    "success": true,
    "data": {
        "app_name": "Chortke",
        "currency": "IRT",
        "features": { "tasks": true, "influencer": true }
    }
}
```

---

### `GET /health/distributed`
وضعیت کامل distributed services.

**Auth:** ندارد

---

### `GET /metrics/distributed`
متریک‌های distributed.

**Auth:** ندارد

---

## ۲. احراز هویت (Auth)

### `POST /auth/token`
دریافت API Token با credentials.

**Auth:** ندارد | **Rate Limit:** ۸ تلاش / ۱۰ دقیقه

**Request:**
```json
{
    "email": "user@example.com",
    "password": "secret",
    "token_name": "my-app",
    "scopes": "user.read wallet.read",
    "otp": "123456"
}
```

| فیلد | نوع | اجباری | توضیح |
|------|-----|--------|-------|
| `email` | string | ✅ | ایمیل کاربر |
| `password` | string | ✅ | رمز عبور |
| `token_name` | string | ❌ | نام دستگاه/اپلیکیشن |
| `scopes` | string | ❌ | فهرست scope ها (space-separated) |
| `otp` | string | شرطی | کد OTP اگر ۲FA فعال باشد |

**Response:**
```json
{
    "success": true,
    "data": {
        "token": "ct_xxxxxxxxxx",
        "scopes": ["user.read", "wallet.read"],
        "expires_at": "2026-08-09T12:00:00Z"
    }
}
```

---

### `POST /auth/refresh`
تجدید توکن.

**Auth:** ندارد | **Rate Limit:** فعال

**Request:**
```json
{ "token": "ct_old_token" }
```

**Response:** مشابه `POST /auth/token`

---

### `POST /auth/revoke`
باطل کردن توکن جاری.

**Auth:** ✅ (`auth.manage`)

**Response:**
```json
{ "success": true, "message": "توکن باطل شد" }
```

---

### `GET /auth/tokens`
لیست توکن‌های فعال کاربر.

**Auth:** ✅ (`auth.manage`)

**Response:**
```json
{
    "success": true,
    "data": [
        {
            "id": 1,
            "name": "my-app",
            "scopes": ["user.read"],
            "last_used_at": "2026-07-09T10:00:00Z",
            "expires_at": "2026-08-09T10:00:00Z"
        }
    ]
}
```

---

### `POST /auth/tokens/{id}/revoke`
باطل کردن توکن مشخص.

**Auth:** ✅ (`auth.manage`)  
**Params:** `id` — شناسه توکن

---

## ۳. کاربر (User)

### `GET /user/profile`
اطلاعات پروفایل کاربر جاری.

**Auth:** ✅ (`user.read`)

**Response:**
```json
{
    "success": true,
    "data": {
        "id": 123,
        "name": "علی محمدی",
        "email": "ali@example.com",
        "mobile": "09121234567",
        "level": 3,
        "referral_code": "ABCD1234",
        "kyc_status": "verified",
        "created_at": "2025-01-01T00:00:00Z"
    }
}
```

---

### `GET /user/notifications`
لیست اعلان‌های کاربر.

**Auth:** ✅ (`user.read`)

**Query Params:** `page`, `per_page` (پیش‌فرض ۲۰)

---

### `POST /user/notifications/read`
علامت‌گذاری اعلان‌ها به عنوان خوانده‌شده.

**Auth:** ✅ (`user.write`)

**Request:**
```json
{ "ids": [1, 2, 3] }
```

---

### `GET /user/tickets`
لیست تیکت‌های پشتیبانی.

**Auth:** ✅ (`user.read`)

---

### `GET /user/tickets/categories`
دسته‌بندی‌های تیکت.

**Auth:** ✅ (`user.read`)

---

### `GET /user/tickets/{id}`
جزئیات یک تیکت.

**Auth:** ✅ (`user.read`)

---

### `POST /user/tickets`
ایجاد تیکت جدید.

**Auth:** ✅ (`user.write`)

**Request:**
```json
{
    "category_id": 1,
    "subject": "مشکل در برداشت",
    "message": "توضیحات مشکل",
    "priority": "high"
}
```

---

### `POST /user/tickets/{id}/reply`
پاسخ به تیکت.

**Auth:** ✅ (`user.write`)

**Request:**
```json
{ "message": "پیام پاسخ" }
```

---

### `POST /user/tickets/{id}/close`
بستن تیکت.

**Auth:** ✅ (`user.write`)

---

### `GET /user/2fa/status`
وضعیت احراز هویت دو مرحله‌ای.

**Auth:** ✅ (`user.read`)

**Response:**
```json
{ "enabled": true, "method": "totp" }
```

---

### `POST /user/2fa/enable`
فعال‌سازی ۲FA.

**Auth:** ✅ (`user.write`)

**Request:**
```json
{ "method": "totp", "otp": "123456" }
```

---

### `POST /user/2fa/disable`
غیرفعال‌سازی ۲FA.

**Auth:** ✅ (`user.write`)

**Request:**
```json
{ "password": "current_password", "otp": "123456" }
```

---

### `GET /user/sessions`
لیست جلسه‌های فعال.

**Auth:** ✅ (`user.read`)

---

### `POST /user/sessions/{id}/revoke`
پایان دادن به یک جلسه.

**Auth:** ✅ (`user.write`)

---

### `GET /user/settings`
تنظیمات کاربر.

**Auth:** ✅ (`user.read`)

---

### `POST /user/settings/general`
ویرایش تنظیمات عمومی.

**Auth:** ✅ (`user.write`)

**Request:**
```json
{
    "full_name": "علی محمدی",
    "language": "fa",
    "timezone": "Asia/Tehran"
}
```

---

### `POST /user/settings/privacy`
ویرایش تنظیمات حریم خصوصی.

**Auth:** ✅ (`user.write`)

---

### `GET /user/kyc/status`
وضعیت تأیید هویت.

**Auth:** ✅ (`user.read`)

**Response:**
```json
{
    "status": "verified",
    "level": 2,
    "verified_at": "2026-01-15T10:00:00Z"
}
```

---

### `POST /user/kyc/submit`
ارسال مدارک KYC.

**Auth:** ✅ (`user.write`)

**Request:** `multipart/form-data`
```
national_id: 0012345678
selfie_image: <file>
id_card_image: <file>
```

---

### `GET /user/messages`
لیست پیام‌های مستقیم.

**Auth:** ✅ (`user.read`)

---

### `POST /user/messages/send`
ارسال پیام مستقیم.

**Auth:** ✅ (`user.write`)

**Request:**
```json
{
    "recipient_id": 456,
    "message": "سلام"
}
```

---

### `POST /user/account-deletion`
درخواست حذف حساب.

**Auth:** ✅ (`user.write`)

**Request:**
```json
{ "reason": "دلیل حذف حساب", "password": "current_password" }
```

---

### `POST /user/bug-report`
گزارش باگ.

**Auth:** ✅ (`user.write`)

**Request:**
```json
{
    "title": "خطا در صفحه برداشت",
    "description": "توضیحات"
}
```

---

## ۴. کیف پول (Wallet)

### `GET /wallet`
موجودی کیف پول.

**Auth:** ✅ (`wallet.read`)

**Response:**
```json
{
    "success": true,
    "data": {
        "balance": "150000.00",
        "frozen_balance": "0.00",
        "currency": "IRT",
        "updated_at": "2026-07-09T10:00:00Z"
    }
}
```

---

### `GET /wallet/transactions`
تاریخچه تراکنش‌ها.

**Auth:** ✅ (`wallet.read`)

**Query Params:**
| پارامتر | نوع | توضیح |
|---------|-----|-------|
| `page` | int | شماره صفحه (پیش‌فرض ۱) |
| `per_page` | int | تعداد (پیش‌فرض ۲۰، حداکثر ۱۰۰) |
| `type` | string | فیلتر: deposit, withdrawal, reward |
| `date_from` | date | از تاریخ (Y-m-d) |
| `date_to` | date | تا تاریخ (Y-m-d) |

---

### `GET /wallet/bank-cards`
کارت‌های بانکی.

**Auth:** ✅ (`wallet.read`)

---

### `POST /wallet/bank-cards`
افزودن کارت بانکی.

**Auth:** ✅ (`wallet.write`)

**Request:**
```json
{
    "card_number": "6037991234567890",
    "account_number": "1234567890",
    "sheba": "IR120570028780010872000101"
}
```

---

### `POST /wallet/bank-cards/{id}/delete`
حذف کارت بانکی.

**Auth:** ✅ (`wallet.write`)

---

### `POST /wallet/bank-cards/{id}/primary`
تنظیم کارت بانکی اصلی.

**Auth:** ✅ (`wallet.write`)

---

### `GET /wallet/withdraw/limits`
سقف برداشت.

**Auth:** ✅ (`wallet.read`)

**Response:**
```json
{
    "daily_limit": "5000000",
    "used_today": "1000000",
    "remaining": "4000000",
    "min_amount": "50000",
    "currency": "IRT"
}
```

---

### `POST /wallet/withdraw`
درخواست برداشت.

**Auth:** ✅ (`wallet.write`) | **Idempotency:** سرصفحه `X-Idempotency-Key` پشتیبانی می‌شود

**Request:**
```json
{
    "amount": "500000",
    "bank_card_id": 1,
    "idempotency_key": "unique-key-123"
}
```

---

### `POST /wallet/manual-deposit`
درخواست واریز دستی.

**Auth:** ✅ (`wallet.write`)

**Request:** `multipart/form-data`
```
amount: 1000000
bank_account_id: 1
receipt_image: <file>
```

---

### `GET /wallet/crypto/wallets`
آدرس‌های کیف پول ارز دیجیتال.

**Auth:** ✅ (`wallet.read`)

---

### `POST /wallet/crypto/intent`
ایجاد قصد واریز ارز دیجیتال.

**Auth:** ✅ (`wallet.write`)

**Request:**
```json
{ "currency": "USDT", "network": "TRC20" }
```

---

### `GET /wallet/investments`
لیست سرمایه‌گذاری‌ها.

**Auth:** ✅ (`wallet.read`)

---

### `POST /wallet/investments`
ایجاد سرمایه‌گذاری جدید.

**Auth:** ✅ (`wallet.write`)

**Request:**
```json
{
    "plan_id": 1,
    "amount": "1000000"
}
```

---

### `POST /wallet/investments/withdraw`
برداشت از سرمایه‌گذاری.

**Auth:** ✅ (`wallet.write`)

**Request:**
```json
{ "investment_id": 5 }
```

---

### `GET /wallet/referrals/stats`
آمار معرفی.

**Auth:** ✅ (`wallet.read`)

---

### `GET /wallet/referrals/users`
لیست کاربران معرفی‌شده.

**Auth:** ✅ (`wallet.read`)

---

### `GET /wallet/lottery/rounds`
دوره‌های قرعه‌کشی.

**Auth:** ✅ (`wallet.read`)

---

### `POST /wallet/lottery/join`
شرکت در قرعه‌کشی.

**Auth:** ✅ (`wallet.write`)

**Request:**
```json
{ "round_id": 3 }
```

---

## ۵. اینفلوئنسر مارکت‌پلیس

### `GET /influencer/profile`
پروفایل اینفلوئنسر خودم.

**Auth:** ✅ (`influencer.read`)

---

### `GET /influencer/list`
لیست اینفلوئنسرها.

**Auth:** ✅ (`influencer.read`)

**Query Params:** `category`, `min_followers`, `page`, `per_page`

---

### `GET /influencer/{id}`
پروفایل اینفلوئنسر مشخص.

**Auth:** ✅ (`influencer.read`)

---

### `POST /influencer/profile`
ایجاد/ویرایش پروفایل اینفلوئنسر.

**Auth:** ✅ (`influencer.write`)

**Request:**
```json
{
    "platform": "instagram",
    "username": "@myusername",
    "followers": 50000,
    "category": "lifestyle",
    "bio": "توضیحات"
}
```

---

### `POST /influencer/profile/verify`
ارسال درخواست تأیید پروفایل.

**Auth:** ✅ (`influencer.write`)

---

### `GET /influencer/orders/placed`
سفارش‌هایی که من دادم.

**Auth:** ✅ (`influencer.read`)

---

### `GET /influencer/orders/received`
سفارش‌هایی که دریافت کردم.

**Auth:** ✅ (`influencer.read`)

---

### `POST /influencer/orders`
ایجاد سفارش جدید.

**Auth:** ✅ (`influencer.write`)

**Request:**
```json
{
    "influencer_id": 10,
    "service_type": "story",
    "description": "معرفی محصول",
    "budget": "2000000"
}
```

---

### `POST /influencer/orders/{id}/confirm`
تأیید تحویل توسط خریدار.

**Auth:** ✅ (`influencer.write`)

---

### `POST /influencer/orders/{id}/dispute`
ایجاد اختلاف.

**Auth:** ✅ (`influencer.write`)

**Request:**
```json
{ "reason": "محتوا مطابق توافق نبود" }
```

---

### `GET /influencer/orders/{id}/dispute`
اطلاعات اختلاف.

**Auth:** ✅ (`influencer.read`)

---

### `POST /influencer/orders/{id}/respond`
پاسخ فروشنده به سفارش.

**Auth:** ✅ (`influencer.write`)

---

### `POST /influencer/orders/{id}/proof`
ارسال مدرک تحویل.

**Auth:** ✅ (`influencer.write`)

**Request:** `multipart/form-data`

---

### `POST /influencer/orders/{id}/dispute/message`
ارسال پیام در اختلاف.

**Auth:** ✅ (`influencer.write`)

---

### `POST /influencer/orders/{id}/dispute/escalate`
ارجاع اختلاف به ادمین.

**Auth:** ✅ (`influencer.write`)

---

### `POST /influencer/orders/{id}/dispute/resolve`
حل اختلاف (ادمین).

**Auth:** ✅ (`influencer.write`)

---

## ۶. سیستم تسک سوشال (`/social`)

### `GET /social/accounts`
حساب‌های سوشال متصل.

**Auth:** ✅ (`social.read`) | **Feature Flag:** `tasks`

---

### `POST /social/accounts`
اتصال حساب سوشال.

**Auth:** ✅ (`social.write`)

**Request:**
```json
{
    "platform": "instagram",
    "username": "@myaccount",
    "access_token": "..."
}
```

---

### `PUT /social/accounts/{id}`
ویرایش حساب سوشال.

**Auth:** ✅ (`social.write`)

---

### `DELETE /social/accounts/{id}`
حذف حساب سوشال.

**Auth:** ✅ (`social.write`)

---

### `GET /social/ads`
آگهی‌های من.

**Auth:** ✅ (`social.read`)

---

### `GET /social/ads/{id}`
جزئیات آگهی.

**Auth:** ✅ (`social.read`)

---

### `POST /social/ads`
ایجاد آگهی جدید.

**Auth:** ✅ (`social.write`)

**Request:**
```json
{
    "type": "instagram_story",
    "title": "تبلیغ محصول",
    "description": "توضیحات",
    "budget": "500000",
    "target_count": 100
}
```

---

### `POST /social/ads/{id}/pause`
توقف آگهی.

**Auth:** ✅ (`social.write`)

---

### `POST /social/ads/{id}/resume`
ادامه آگهی.

**Auth:** ✅ (`social.write`)

---

### `POST /social/ads/{id}/cancel`
لغو آگهی.

**Auth:** ✅ (`social.write`)

---

### `GET /social/tasks`
تسک‌های موجود.

**Auth:** ✅ (`social.read`)

---

### `GET /social/tasks/history`
تاریخچه تسک‌های من.

**Auth:** ✅ (`social.read`)

---

### `POST /social/tasks/{id}/start`
شروع تسک.

**Auth:** ✅ (`social.write`)

---

### `POST /social/tasks/{id}/submit`
ارسال مدرک انجام تسک.

**Auth:** ✅ (`social.write`)

**Request:** `multipart/form-data` یا JSON با URL اسکرین‌شات

---

### `GET /social/disputes`
اختلاف‌های تسک.

**Auth:** ✅ (`social.read`)

---

### `POST /social/executions/{id}/dispute`
ایجاد اختلاف برای تسک.

**Auth:** ✅ (`social.write`)

---

## ۷. Real-Time (`/real-time`)

### `POST /real-time/poll`
Long-polling برای رویدادها.

**Auth:** ✅ (`realtime`)

---

### `POST /real-time/rooms/join`
ورود به اتاق.

**Auth:** ✅ (`realtime`)

---

### `POST /real-time/rooms/leave`
خروج از اتاق.

**Auth:** ✅ (`realtime`)

---

### `GET /real-time/rooms/{room}/members`
اعضای اتاق.

**Auth:** ✅ (`realtime`)

---

### `GET /real-time/presence/online`
کاربران آنلاین.

**Auth:** ✅ (`realtime`)

---

### `GET /real-time/presence/online/{room}`
کاربران آنلاین در اتاق.

**Auth:** ✅ (`realtime`)

---

### `GET /real-time/stats`
آمار real-time.

**Auth:** ✅ (`realtime`)

---

## ۸. تأیید هویت (`/verification`)

### `GET /verification/status`
وضعیت تأیید.

**Auth:** ✅ (`verification.read`)

---

### `GET /verification/history`
تاریخچه تأیید.

**Auth:** ✅ (`verification.read`)

---

### `POST /verification/generate-code`
دریافت کد تأیید.

**Auth:** ✅ (`verification.write`)

**Request:**
```json
{ "type": "phone", "phone": "09121234567" }
```

---

### `POST /verification/submit-proof`
ارسال مدرک تأیید.

**Auth:** ✅ (`verification.write`)

---

## ۹. ویترین (Vitrine) و تعاملات

### `GET /vitrine/list`
لیست کالاها.

**Auth:** ✅ (`user.read`)

**Query Params:** `category`, `min_price`, `max_price`, `page`

---

### `GET /vitrine/{id}`
جزئیات کالا.

**Auth:** ✅ (`user.read`)

---

### `POST /vitrine/{id}/trade`
درخواست معامله.

**Auth:** ✅ (`user.write`)

---

### `POST /interactions/favorite/toggle`
افزودن/حذف از علاقه‌مندی.

**Auth:** ✅ (`user.write`)

**Request:**
```json
{ "type": "influencer", "id": 10 }
```

---

### `POST /interactions/rate`
ثبت امتیاز.

**Auth:** ✅ (`user.write`)

**Request:**
```json
{
    "type": "influencer_order",
    "id": 5,
    "score": 4,
    "comment": "عالی بود"
}
```

---

### `POST /interactions/report`
گزارش تخلف.

**Auth:** ✅ (`user.write`)

**Request:**
```json
{
    "type": "user",
    "target_id": 20,
    "reason": "محتوای نامناسب"
}
```

---

## ۱۰. امنیت

### `POST /security/csp-report`
دریافت گزارش‌های CSP.

**Auth:** ندارد | **Rate Limit:** فعال

**Request:** `application/csp-report`

---

## کدهای خطا

| کد | معنی |
|----|------|
| `MISSING_TOKEN` | توکن ارائه نشده |
| `INVALID_TOKEN` | توکن نامعتبر یا منقضی |
| `FORBIDDEN` | دسترسی ممنوع (scope ناکافی) |
| `OWNERSHIP_VIOLATION` | تلاش برای دسترسی به منبع دیگران |
| `RATE_LIMITED` | تعداد تلاش بیش از حد |
| `VALIDATION_ERROR` | داده ورودی نامعتبر |
| `NOT_FOUND` | منبع پیدا نشد |
| `INSUFFICIENT_BALANCE` | موجودی ناکافی |
| `FRAUD_DETECTED` | تراکنش مشکوک |
| `KYC_REQUIRED` | نیاز به تأیید هویت |
| `FEATURE_DISABLED` | ویژگی غیرفعال است |

---

## Rate Limiting

- **پیش‌فرض:** ۶۰ request / دقیقه
- **Auth endpoints:** ۸ تلاش / ۱۰ دقیقه
- **سرصفحه‌های پاسخ:**
  - `X-RateLimit-Limit`: سقف
  - `X-RateLimit-Remaining`: باقی‌مانده
  - `X-RateLimit-Reset`: زمان reset (Unix timestamp)

---

## نکات امنیتی

- تمام ارتباطات **HTTPS** اجباری است
- توکن‌ها با **HMAC-SHA256** در دیتابیس ذخیره می‌شوند
- **CSRF** برای API endpoints معاف است (Bearer Token جایگزین)
- **Idempotency Key** در endpoint های مالی پشتیبانی می‌شود (`X-Idempotency-Key`)
- در صورت شناسایی تراکنش مشکوک، کد `FRAUD_DETECTED` برمی‌گردد

---

*آخرین بروزرسانی: ۱۴۰۴/۰۴/۱۸*
