# فاز ۵ Content — پاک‌سازی Event/Outboxهای Legacy پرداخت محتوا

تاریخ: ۱۴۰۵/۰۳/۳۰

## مسئله
قبل از فاز ۲، مسیر پرداخت درآمد محتوا در بعضی حالت‌ها به event/outbox واگذار می‌شد و listener قدیمی `ContentEventListeners::handleContentRevenuePaid` خودش واریز کیف پول را انجام می‌داد یا `wallet.deposit.requested` می‌ساخت.

بعد از فاز ۲، منبع حقیقت پرداخت تغییر کرده است:

```text
ContentService::payRevenue
→ lock رکورد درآمد
→ deposit مستقیم و idempotent به کیف پول
→ update content_revenues.status = paid
→ ثبت transaction_id
→ ثبت outbox فقط برای notification/audit
```

بنابراین هر listener قدیمی که دوباره پول واریز کند می‌تواند ریسک double deposit ایجاد کند.

## تغییرات انجام‌شده

### 1. ContentEventListeners مالی نیست
`app/Listeners/ContentEventListeners.php` پاک‌سازی شد:

- وابستگی‌های مالی legacy حذف شدند:
  - `WalletServiceInterface`
  - `IdempotencyService`
  - `OutboxService`
- متد قدیمی `handleContentRevenuePaid` با برچسب:

```text
DEPRECATED_REMOVE / LEGACY_EVENT / NO_WALLET_MUTATION
```

حفظ شد، اما دیگر هیچ واریزی انجام نمی‌دهد.

- handler جدید اضافه شد:

```php
handleContentRevenuePaymentRecorded(Event $event)
```

این handler فقط notification می‌فرستد و هیچ تغییر مالی انجام نمی‌دهد.

### 2. ثبت listenerهای امن در bootstrap
در `bootstrap/app.php` فقط eventهای امن و notification-only ثبت شدند:

```text
content.revenue.payment_recorded → handleContentRevenuePaymentRecorded
content.revenue_paid             → handleContentRevenuePaid  (legacy, بدون واریز)
```

هیچ listener مالی برای content revenue ثبت نشده است.

### 3. محافظ در WalletDepositRequestListener
در `app/Listeners/WalletDepositRequestListener.php` guard اضافه شد تا اگر یک `wallet.deposit.requested` قدیمی مربوط به `content_revenue` بعد از پرداخت مستقیم پردازش شود، واریز دوباره انجام نشود.

منطق guard:

- اگر metadata از نوع `content_revenue` باشد و رکورد `content_revenues.transaction_id` داشته باشد → skip
- اگر transaction مستقیم با idempotency key جدید `content_revenue_payment_{revenueId}` وجود داشته باشد → skip
- اگر event قدیمی هنوز هیچ transaction مستقیم ندارد، کورکورانه بلاک نمی‌شود تا داده قدیمی پرداخت‌نشده از بین نرود.

### 4. شفاف‌سازی قانون دو ماه درآمد
در `ContentSubmission::getActiveMonths` مبنا صریح شد:

```text
مبنای قانون دو ماه، اولین approved_at محتواهای approved/published کاربر است.
```

این با متن تعهدنامه فعلی هماهنگ است: «دو ماه اول پس از تأیید، هیچ سودی تعلق نمی‌گیرد.»

در فرم ادمین ثبت درآمد هم سابقه فعلی کاربر و حداقل ماه لازم نمایش داده می‌شود.

## تست‌ها

### Syntax

```bash
php -l bootstrap/app.php
php -l app/Listeners/ContentEventListeners.php
php -l app/Listeners/WalletDepositRequestListener.php
php -l app/Controllers/Admin/ContentController.php
php -l app/Models/ContentSubmission.php
php -l views/admin/content/revenue-create.php
```

### DB / Legacy Guard

```bash
php tools/content-phase5-legacy-event-guard-db-test.php
```

نتیجه:

```json
{
  "ok": true,
  "wallet_after_legacy_content_event": { "balance_irt": "0.00000000" },
  "wallet_after_payment_recorded": { "balance_irt": "0.00000000" },
  "wallet_after_legacy_wallet_deposit": { "balance_irt": "0.00000000" }
}
```

### Regression

- `php tools/content-phase2-revenue-flow-db-test.php` همچنان PASS است.
- تست‌های admin phase4 همچنان PASS هستند.

## تکمیل Regression UI کاربر
صفحه جزئیات محتوای کاربر نیز با یک رکورد واقعی مرورگر تست و screenshot شد:

```text
/content/{id}
tools/browser-preview/screenshots/content-phase5-user-show.png
```

در این صفحه نمایش نام کانال منتشرشده و دلیل رد/تعلیق نیز کامل‌تر شد.

## نتیجه
مسیر مالی Content اکنون یک منبع حقیقت دارد:

```text
ContentService::payRevenue
```

و event/outboxهای Content فقط برای notification/audit استفاده می‌شوند، نه واریز کیف پول.
