# گزارش فاز ۲ Content — منطق درآمد و پرداخت

تاریخ: ۱۴۰۵/۰۳/۳۰ — 2026-06-20

## هدف

بعد از تثبیت route/parse/schema در فاز ۱، این فاز روی منطق درآمد و پرداخت تمرکز داشت:

```text
submit → approve → publish → create revenue → approve revenue → pay revenue
```

هدف اصلی:

- محاسبه صحیح سهم سایت/کاربر/مالیات
- پرداخت واقعی به کیف پول کاربر
- جلوگیری از double pay
- سازگاری controller/admin revenue form با JSON و route فعلی

## اصلاحات انجام‌شده

### 1. payRevenue اتمیک و idempotent شد

فایل:

```text
app/Services/ContentService.php
```

مشکل قبلی:

اگر `OutboxService` تزریق شده بود، `payRevenue()` فقط event ثبت می‌کرد و revenue را paid می‌کرد؛ اما واریز واقعی کیف پول وابسته به پردازش outbox بود. این باعث می‌شد کاربر در UI revenue را paid ببیند اما کیف پول هنوز شارژ نشده باشد.

اصلاح:

`payRevenue()` الان مستقیماً و با idempotency key به کیف پول کاربر واریز می‌کند:

```text
content_revenue_payment_{revenueId}
```

بعد از موفقیت واریز:

```text
content_revenues.status = paid
paid_at = now
paid_by_admin = adminId
transaction_id = wallet transaction id
```

اگر دوباره پرداخت همان revenue صدا زده شود:

```json
{
  "success": true,
  "already_paid": true
}
```

و واریز دوباره انجام نمی‌شود.

### 2. outbox پرداخت تغییر نقش داد

بعد از پرداخت موفق، فقط یک event اطلاع‌رسانی/ردیابی ثبت می‌شود:

```text
content.revenue.payment_recorded
```

دیگر از outbox برای انجام خود پرداخت استفاده نمی‌کنیم تا paid شدن revenue و شارژ کیف پول از هم جدا نمانند.

### 3. فرم admin ثبت درآمد اصلاح شد

فایل‌ها:

```text
views/admin/content/revenue-create.php
app/Controllers/Admin/ContentController.php
```

مشکلات:

- یک `">` اضافی در view وجود داشت.
- hidden `submission_id` در فرم نبود.
- controller فقط `$_POST` می‌خواند اما JS صفحه JSON ارسال می‌کرد.
- controller فیلدهای `user_share_percent` و `tax_percent` را required کرده بود، در حالی که سرویس خودش محاسبه می‌کند.

اصلاح:

- `submission_id` به فرم اضافه شد.
- `storeRevenue()` هم JSON و هم فرم را می‌خواند.
- validation فقط فیلدهای لازم را می‌خواهد:

```text
submission_id
period
total_revenue
views اختیاری
idempotency_key اختیاری
```

- پاسخ JSON برمی‌گرداند تا JS ادمین درست کار کند.

### 4. محاسبه درآمد تست شد

برای `total_revenue = 100000` با تنظیمات پیش‌فرض:

```text
site_share_percent = 40%
user_share_percent = 60%
tax_percent = 9%
```

نتیجه:

```text
site_share_amount = 40000
user_share_amount = 60000
tax_amount = 5400
net_user_amount = 54600
```

## تست‌ها

### DB Test کامل فاز ۲

اسکریپت:

```bash
php tools/content-phase2-revenue-flow-db-test.php
```

سناریو:

```text
submit content
approve content
backdate approved_at برای عبور از شرط دو ماه
publish content
create revenue
approve revenue
pay revenue
pay revenue again
```

نتیجه:

```json
{
  "ok": true,
  "wallet_after_pay": {
    "balance_irt": "54600.00000000"
  },
  "wallet_after_second_pay": {
    "balance_irt": "54600.00000000"
  },
  "transaction_count": 1
}
```

یعنی:

- پرداخت اول موفق بود.
- پرداخت دوم دوباره پول اضافه نکرد.
- فقط یک تراکنش wallet برای revenue ساخته شد.

### Browser routes regression

اسکریپت:

```bash
node /home/user/browser-test/content-phase1-preview.js
```

صفحات:

```text
/content
/content/create
/content/revenues
```

نتیجه:

```json
{
  "ok": true,
  "errors": [],
  "failedRequests": []
}
```

### Browser submit content

اسکریپت:

```bash
node /home/user/browser-test/content-phase1-submit-test.js
```

نتیجه:

```json
{
  "ok": true,
  "responses": [
    {
      "http": 200,
      "success": true,
      "message": "محتوای شما با موفقیت ثبت شد و در صف بررسی قرار گرفت."
    }
  ]
}
```

## وضعیت بعد از فاز ۲

منطق درآمد و پرداخت Content اکنون پایدارتر است:

```text
ثبت محتوا واقعی کار می‌کند
چرخه approve/publish/revenue/pay تست DB دارد
پرداخت revenue مستقیم به کیف پول انجام می‌شود
double pay کنترل شده است
```

## موارد باقی‌مانده برای فاز بعد

```text
1. بازطراحی UI مستقل Content Hub
2. دقیق‌تر کردن مبنای دو ماه فعالیت و نمایش آن در UI
3. گزارش مالی admin برای content revenues
4. بررسی outbox/listenerهای ContentEventListeners که هنوز بخش‌هایی از منطق قدیمی دارند
5. تمیزکاری متن‌های technical/انگلیسی در UI Content
```
