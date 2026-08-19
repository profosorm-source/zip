# ارزیابی معماری Webhook پروژه Chortke

**تاریخ:** 2026-07-15  
**وضعیت:** فقط تحلیل؛ هیچ اصلاح Webhook اعمال نشده است  
**قید معماری:** بدون DTO و با حداقل فایل جدید

---

## نتیجه کوتاه

پروژه بخش مهمی از منطق امن نهایی‌سازی پرداخت را دارد، اما زیرساخت Webhook ورودی یکپارچه و production-grade کامل نیست.

- **Payment processing core:** نسبتاً کامل
- **Browser payment callback:** موجود
- **واقعی S2S payment webhook ingress:** مستقل و کامل نیست
- **Generic webhook middleware/config:** موجود ولی ناسازگار و عملاً جدا از payment callback
- **Durable webhook inbox / replay ledger:** وجود ندارد
- **Queue/retry/reconciliation:** اجزا موجودند، اما مسیر ورودی Webhook به آن‌ها یکپارچه نشده است

برآورد بلوغ:

| بخش | وضعیت تقریبی |
|---|---:|
| منطق نهایی‌سازی و قفل مالی | 75–85% |
| امنیت callback پرداخت فعلی | 60–70% |
| زیرساخت عمومی inbound webhook | 30–40% |
| durability/replay/retry وب‌هوک | 25–35% |

---

## زیرساخت‌های موجود

### 1. مسیر callback پرداخت

```text
POST /payment/callback/{gateway}
GET  /payment/callback/{gateway}  (عمداً مسدود)
```

مسیر POST به `PaymentController::callback()` و سپس `PaymentCommandService::callback()` می‌رود.

### 2. کنترل‌های موجود در PaymentCommandService

- sanitize کردن payload
- rate limit
- IP allowlist از DB/config
- اعتبارسنجی فرمت authority
- پیدا کردن payment log
- nonce validation
- user/payment integrity check
- `PaymentGatewayInterface::verifyCallback()`
- verify مستقیم تراکنش از gateway
- idempotency key بر اساس gateway+authority
- transaction root
- `SELECT ... FOR UPDATE`
- Saga برای نهایی‌سازی مالی
- Outbox برای رویدادهای بعد از پرداخت
- logging و Sentry

این بخش ارزشمند است و نباید بازنویسی یا دور زده شود.

### 3. Job و reconciliation

- `ProcessPaymentCallbackJob`
- `ReconcilePaymentsJob`
- مسیر admin/manual verification
- retry محدود برای pending payments
- outbox notification در شکست‌های نهایی

### 4. Generic webhook security

`CSRFMiddleware` برای `/webhooks/*` شامل:

- signature check
- timestamp window
- raw-body HMAC
- fail-closed در نبود secret

### 5. Webhookهای تبلیغات ویدیویی

```text
POST /webhooks/video-reward/{network}
```

Adapterهای چند شبکه HMAC اختصاصی دارند.

### 6. ReconciliationService

`ReconciliationService::reconcilePayment()` شامل:

- HMAC
- transaction/row locks
- amount/currency checks
- ledger idempotency
- wallet/ledger consistency check
- audit trail

ولی یک route عمومی production که payload خارجی را مستقیم و استاندارد به این متد متصل کند پیدا نشد.

---

## مشکلات معماری فعلی

### 1. Callback مرورگر و Webhook سروربه‌سرور تفکیک نشده‌اند

`PaymentController::callback()` برای UX مرورگر طراحی شده است:

- session و flash message دارد؛
- در پایان redirect می‌کند؛
- mobile deep-link تولید می‌کند؛
- `sessionUserId` را به منطق پرداخت می‌دهد.

Webhook واقعی باید:

- بدون session کار کند؛
- JSON و status code استاندارد بدهد؛
- سریع ACK کند؛
- retry و duplicate را تحمل کند.

### 2. S2S callback ناشناس ممکن است به user mismatch بخورد

Controller مقدار زیر را می‌فرستد:

```php
(int)$this->userId()
```

برای درخواست بدون session این مقدار معمولاً `0` است، نه `null`. در integrity check، صفر به‌عنوان user موجود تلقی شده و با user واقعی payment مغایرت پیدا می‌کند.

### 3. route مخصوص payment webhook وجود ندارد

Generic middleware فقط URIهای `/webhooks/*` را می‌بیند، ولی payment callback فعلی `/payment/callback/*` است. بنابراین دو زیرساخت امنیتی عملاً روی یک مسیر واحد جمع نشده‌اند.

### 4. ناسازگاری نام config

فایل موجود:

```text
config/webhook.php  -> webhook.secret
```

ولی `CSRFMiddleware` از کلیدهای زیر استفاده می‌کند:

```text
webhooks.secret
webhooks.secrets.{provider}
```

جمع/مفرد ناسازگار است و می‌تواند باعث fail-closed دائمی شود.

### 5. الگوریتم امضا generic است، نه provider-specific

Middleware امضای `timestamp.rawBody` را انتظار دارد. `BasePaymentGateway` نیز HMAC عمومی از callback fields می‌سازد. اما هر provider قرارداد امضای خودش را دارد و بعضی درگاه‌ها اصلاً custom HMAC روی browser return ارسال نمی‌کنند و باید با IP allowlist + server-side verify احراز شوند.

در حال حاضر تمام gatewayهای واقعی، به‌جز Mock، implementation اختصاصی `verifyCallback()` ندارند و رفتار generic Base را به ارث می‌برند.

### 6. Webhook inbox پایدار وجود ندارد

جدولی برای ثبت این موارد پیدا نشد:

- provider event id
- payload hash/raw payload
- received_at
- processing status
- attempts
- last error
- processed_at
- unique provider+event id

Idempotency پرداخت با authority وجود دارد، ولی برای webhook عمومی، replay audit و durable retry کافی نیست.

### 7. Job فعلی عملاً wrapper هم‌زمان است

`ProcessPaymentCallbackJob` وجود دارد، ولی route callback مستقیماً PaymentService را صدا می‌زند و یک مسیر durable «persist → enqueue → ACK → process» کامل دیده نشد.

### 8. Reconciliation عمومی به ingress متصل نیست

`ReconciliationService` از نظر مالی قابلیت‌های زیادی دارد، اما generic HMAC آن با route/middleware/provider adapters یک قرارداد واحد ندارد.

### 9. GET callback به‌طور کامل مسدود است

برخی providerها browser return را با GET انجام می‌دهند. قبل از فعال‌سازی نهایی باید قرارداد رسمی هر gateway بررسی شود. GET browser return و POST S2S webhook نباید یک endpoint یا policy یکسان داشته باشند.

---

## آیا استفاده از Webhook لازم است؟

### پرداخت آنلاین

اگر provider از S2S webhook پشتیبانی کند، استفاده از آن **به‌شدت توصیه می‌شود**؛ اما نباید تنها منبع حقیقت باشد.

معماری صحیح پرداخت:

```text
Browser Return -> نمایش وضعیت و trigger امن verify
S2S Webhook    -> اعلان مستقل provider
Scheduled Reconciliation -> جبران callback/webhook گم‌شده
Server Verify API -> منبع حقیقت نهایی
```

### اگر provider Webhook واقعی ندارد

نباید یک Webhook مصنوعی ساخته شود. Browser callback + verify مستقیم + reconciliation زمان‌بندی‌شده کافی است.

### سایر حوزه‌ها

Webhook برای سرویس‌های asynchronous مانند delivery status، KYC provider، crypto confirmations و ad rewards فقط در صورت پشتیبانی رسمی provider مفید/لازم است.

---

## طرح اصلاح پیشنهادی بدون DTO

### اصل

از typed associative arrays و shapeهای PHPDoc استفاده می‌شود؛ DTO جدید ساخته نمی‌شود.

### Batch W1 — تفکیک endpointها

- حفظ `/payment/callback/{gateway}` برای browser UX
- افزودن `/webhooks/payment/{gateway}` برای S2S
- افزودن متد `webhook()` به همان `PaymentController`؛ Controller جدید ساخته نشود
- S2S مسیر `sessionUserId=null` داشته باشد
- پاسخ فقط JSON و HTTP status استاندارد باشد

### Batch W2 — provider-specific authenticity

- اصلاح config از یک namespace واحد `webhook.*`
- حذف HMAC فرضی generic برای gatewayهایی که چنین قراردادی ندارند
- پیاده‌سازی `verifyCallback()` متناسب با قرارداد واقعی هر gateway
- IP allowlist فقط defense-in-depth باشد
- server-side `verifyPayment()` همچنان منبع حقیقت نهایی باقی بماند

### Batch W3 — durable inbox و replay protection

حداقل یک migration جدید لازم است. استفاده از Outbox برای inbound message صحیح نیست؛ Outbox جهت رویداد خروجی است.

پیشنهاد جدول:

```text
webhook_inbox
- id
- provider
- event_id
- payload_hash
- payload_json
- headers_json (فقط allowlist، بدون secret)
- status
- attempts
- last_error
- received_at
- processed_at
UNIQUE(provider, event_id)
```

برای رعایت حداقل فایل، منطق persistence می‌تواند ابتدا داخل Model موجود مرتبط با Payment قرار گیرد؛ اگر مسئولیت آن بیش از حد شود، فقط یک Model اختصاصی اضافه شود.

### Batch W4 — queue و retry

- تطبیق `ProcessPaymentCallbackJob` با قرارداد worker موجود
- persist قبل از ACK
- `pushUnique()` با provider+event_id
- retry با backoff
- وضعیت failed و امکان replay ادمین
- duplicate باید پاسخ 200 بدهد و دوباره credit نکند

### Batch W5 — reconciliation

- ReconcilePaymentsJob باید از verify API واقعی provider استفاده کند
- callback ساختگی با `status=OK` نباید جای verify provider را بگیرد
- pending/failed نهایی باید قابل audit باشد

### Batch W6 — tests

- valid signature
- invalid/missing signature
- stale timestamp/replay
- duplicate delivery
- concurrent delivery
- webhook بدون session
- amount/currency mismatch
- provider timeout + retry
- payment already completed
- queue failure after inbox insert
- browser callback و webhook هم‌زمان

---

## فایل‌های احتمالی تغییر

حداقل تغییر پیشنهادی:

1. `routes/wallet.php` یا `routes/public.php`
2. `app/Controllers/PaymentController.php`
3. `app/Services/Payment/PaymentCommandService.php`
4. `app/Services/Payment/BasePaymentGateway.php`
5. gateway adapterهای فعال، فقط بر اساس قرارداد رسمی
6. `app/Middleware/CSRFMiddleware.php`
7. `config/webhook.php`
8. `app/Jobs/Payment/ProcessPaymentCallbackJob.php`
9. `app/Jobs/Payment/ReconcilePaymentsJob.php`
10. یک migration برای inbox
11. تست‌های موجود Payment/Reconciliation

هیچ DTO جدیدی در این طرح وجود ندارد.

---

## تصمیم لازم قبل از اصلاح

پیش از Batch Webhook باید این موارد مشخص شوند:

1. کدام gatewayها واقعاً در production فعال‌اند؟
2. هر gateway browser callback، S2S webhook یا هر دو را پشتیبانی می‌کند؟
3. قرارداد رسمی signature/header/IP هر provider چیست؟
4. آیا ACK سریع + queue مورد نیاز است یا پردازش sync پذیرفته می‌شود؟
5. مدت نگهداری raw payload و محدودیت privacy چقدر است؟

تا دریافت تأیید، Webhook و شش خطای باقی‌مانده مرتبط با آن تغییر داده نمی‌شوند.
