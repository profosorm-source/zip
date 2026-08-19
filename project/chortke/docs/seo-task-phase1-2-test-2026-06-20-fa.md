# گزارش فاز ۱ و ۲ SEO Task — ۲۰۲۶/۰۶/۲۰

## هدف
شروع اصلاح SEO Task با تمرکز روی دو بخش پایه:

1. فاز ۱: Schema + Creation
2. فاز ۲: Execution Safety + Reject/Cancel

همچنین بخشی از Finance consistency برای پرداخت از escrow واقعی SEO اصلاح شد، چون بدون آن ایجاد کمپین از AdSystemManager با اجرای واقعی ناسازگار بود.

---

## وضعیت قبل از اصلاح

### مشکل‌های مهم

- ایجاد SEO از `/ads/create` و `AdSystemManager` کار نمی‌کرد، چون `SeoAdAdapter` فیلد `site_url` و `price_per_click` می‌خواست ولی UI فیلدهای جدید مثل `target_link`, `min_payout`, `max_payout` می‌فرستاد.
- `SeoAdAdapter` فیلدهای مهم اجرای SEO را ذخیره نمی‌کرد:
  - `min_payout`
  - `max_payout`
  - `target_duration`
  - `min_score`
  - `max_per_day`
  - `target_url`
  - `currency`
- `seo_executions` ستون‌های لازم برای lifecycle کامل نداشت:
  - `session_id`
  - `target_keyword`
  - `rejection_reason`
  - `cancel_reason`
  - `score_breakdown`
- Reject با خطای DB می‌شکست، چون ستون `rejection_reason` وجود نداشت.
- AntiFraud روی `user_sessions.device_fingerprint` خطا می‌داد.
- کاربر می‌توانست مستقیماً آگهی SEO خودش را start کند.
- ایجاد SEO از AdSystemManager بودجه را escrow/lock می‌کرد، ولی اجرای SEO پاداش را مستقیم از سیستم deposit می‌کرد و از escrow آزاد نمی‌کرد.

---

## Migration جدید

```text
database/migrations/2026_06_20_0004_seo_task_reconciliation.sql
```

اضافه می‌کند:

```text
seo_executions.session_id
seo_executions.target_keyword
seo_executions.rejection_reason
seo_executions.cancel_reason
seo_executions.fraud_score
seo_executions.client_mode
seo_executions.score_breakdown
user_sessions.device_fingerprint
idx_seo_exec_ad_user_date
idx_seo_exec_status_date
idx_user_sessions_device_fp
```

---

## اصلاحات فاز ۱ — Creation

### فایل‌ها

```text
app/Adapters/SeoAdAdapter.php
app/Controllers/User/AdsController.php
app/Services/AdSystemManager.php
app/Models/Escrow.php
```

### تغییرات

- `target_link`, `target_url`, `site_url`, `link` normalize شدند.
- `SeoAdAdapter` دیگر `price_per_click` اجباری نمی‌خواهد.
- SEO اکنون با مدل min/max payout ساخته می‌شود:

```text
budget
min_payout
max_payout
target_duration
min_score
max_per_day
currency
```

- آگهی SEO بعد از ایجاد، اگر تنظیم review ادمین فعال نباشد، `active` می‌شود.
- escrow ایجادشده توسط AdSystemManager برای SEO به ساختار زیر bind می‌شود:

```text
order_id   = ad_id
order_type = seo_ad_budget
```

---

## اصلاحات فاز ۲ — Execution Safety

### فایل‌ها

```text
app/Jobs/Seo/StartSeoTaskJob.php
app/Jobs/Seo/CancelSeoTaskJob.php
app/Jobs/Seo/ProcessSeoTaskAsyncJob.php
app/Models/SeoExecution.php
app/Services/Seo/AdsSeoService.php
app/Services/SeoPayoutService.php
```

### تغییرات

- self-execution ممنوع شد:

```text
امکان اجرای تسک SEO خودتان وجود ندارد.
```

- `session_id` و `target_keyword` در execution ذخیره می‌شود.
- reject low-score دیگر خطای DB نمی‌دهد و `rejection_reason` ثبت می‌شود.
- cancel اکنون وضعیت را `cancelled` می‌کند و `cancel_reason` ثبت می‌شود.
- `SeoExecution::getByUser()` به جای `seo_ads` به جدول unified `ads` وصل شد.
- score breakdown در execution ذخیره می‌شود.
- اگر SEO escrow داشته باشد، پرداخت worker از escrow با `partialRelease` انجام می‌شود.
- fallback قدیمی wallet deposit فقط وقتی escrow وجود ندارد اجرا می‌شود.

---

## تست DB واقعی

### تست happy path دستی

اسکریپت:

```text
tools/seo-task-flow-db-test.php
```

نتیجه:

```json
{
  "ok": true,
  "complete": {
    "success": true,
    "payout": 4680,
    "score": 92
  },
  "execution": {
    "status": "completed",
    "final_score": "92.00",
    "payout_amount": "4680.00000000"
  },
  "worker_wallet": {
    "balance_irt": "4680.00000000"
  }
}
```

### تست کامل creation + escrow + execution + reject + cancel

اسکریپت:

```text
tools/seo-task-reconciliation-db-test.php
```

سناریوهای تست‌شده:

1. ایجاد SEO از `AdSystemManager` با `target_link`
2. bind شدن escrow به `seo_ad_budget`
3. self-start توسط advertiser رد شد
4. start و complete توسط worker
5. پرداخت از escrow با partial release
6. low score → rejected و ثبت rejection_reason
7. cancel → cancelled و ثبت cancel_reason

خروجی مهم:

```json
{
  "ok": true,
  "escrow": {
    "order_type": "seo_ad_budget",
    "amount": "115000.00000000"
  },
  "self_start": {
    "success": false,
    "message": "امکان اجرای تسک SEO خودتان وجود ندارد."
  },
  "complete": {
    "success": true,
    "payout": 4680,
    "score": 92
  },
  "escrow_after": {
    "partial_released": "4680.00000000",
    "status": "partial"
  },
  "low_execution": {
    "status": "rejected",
    "rejection_reason": "امتیاز کمتر از حد مجاز (40)"
  },
  "cancel_execution": {
    "status": "cancelled",
    "cancel_reason": "لغو شده توسط کاربر"
  }
}
```

---

## تست مرورگر

بعد از اصلاحات، تست عمومی فلو taskها دوباره اجرا شد:

```text
node /home/user/browser-test/task-flow-test.js
```

نتیجه:

```json
{
  "ok": true
}
```

---

## وضعیت فعلی SEO پس از فاز ۱ و ۲

اصلاح شده:

```text
Creation از AdSystemManager
Schema پایه execution
Reject low-score
Cancel
Self-execution guard
Session/keyword tracking
Escrow partial release برای payout
History join به ads
```

باقی‌مانده برای فازهای بعدی:

```text
فاز ۳: scoring/anti-fraud دقیق‌تر و UI پیام‌های وضعیت
فاز ۴: cleanup نهایی escrow/fee/refund برای بودجه‌های باقی‌مانده
فاز ۵: بهبود UI اجرای SEO و history/detail
```

# فاز ۳ — Scoring / Anti-Fraud / UI Signals

## هدف
پس از اصلاح creation و lifecycle، scoring و anti-fraud SEO دقیق‌تر شد تا فقط بر اساس زمان خام عمل نکند و سیگنال‌های واقعی‌تر UI را نیز لحاظ کند.

## تغییرات کلیدی

### ۱) سیگنال‌های UI اجرای SEO
فایل:

```text
public/assets/js/views/userseoexecute.js
```

اکنون هنگام complete این سیگنال‌ها نیز ارسال می‌شود:

```text
target_opened
focus_blur_count
active_time
client_mode
interaction_types شامل external_open و return_to_task
```

برای سایت‌های cross-origin که iframe اجازه tracking نمی‌دهد، fallback همچنان فعال است اما اکنون مشخص می‌کند که کاربر واقعاً target را با دکمه رسمی باز کرده است یا نه.

### ۲) Scoring نسبی به target_duration
فایل:

```text
app/Jobs/Seo/ProcessSeoTaskAsyncJob.php
```

امتیاز زمان دیگر ثابت نیست؛ نسبت به `target_duration` کمپین محاسبه می‌شود:

```text
time_score = min(duration / target_duration, 1) × 30
```

### ۳) Quality و Fraud flags
به scoring اضافه شد:

```text
target_not_opened
too_short_duration
unnatural_scroll_speed
high_scroll_speed
linear_mouse_pattern
no_pause
no_interaction_type
excessive_focus_switch
```

خروجی scoring حالا شامل این موارد است:

```text
fraud_score
fraud_flags
target_duration
score_breakdown
```

### ۴) Fraud hard-fail
اگر `fraud_score >= 85` باشد، execution به `fraud` می‌رود و payout انجام نمی‌شود.

### ۵) ذخیره اطلاعات تحلیلی
در `seo_executions` ذخیره می‌شود:

```text
score_breakdown
fraud_score
client_mode
```

## تست DB واقعی

اسکریپت‌ها:

```text
tools/seo-task-flow-db-test.php
tools/seo-task-reconciliation-db-test.php
```

نتایج:

```text
seo-task-flow-db-test PASS
seo-task-reconciliation-db-test PASS
```

در تست reconciliation سناریوهای زیر پاس شدند:

- ایجاد SEO از AdSystemManager
- escrow با order_type=seo_ad_budget
- رد self-execution
- complete موفق با payout از escrow
- anomaly شدید → status=fraud یا rejected بدون payout
- cancel → status=cancelled و cancel_reason ثبت شد

## تست مرورگر

```text
node /home/user/browser-test/task-flow-test.js
```

نتیجه:

```json
{
  "ok": true
}
```

# فاز ۴ — Cleanup مالی و Escrow/Refund

## هدف
وقتی کمپین SEO با AdSystemManager ساخته می‌شود، بودجه + کارمزد در wallet قفل و escrow می‌شود. پس بعد از پرداخت‌های موفق، اگر کمپین رد/لغو/منقضی/تکمیل شود، نباید باقی‌مانده escrow در locked balance گیر کند.

## اصلاحات

فایل:

```text
app/Services/Seo/AdsSeoService.php
```

متدهای جدید:

```php
closeAndRefundBudget(int $adId, string $status, string $reason, int $actorId = 0): array
closeExhaustedCampaigns(int $limit = 100): int
```

رفتار:

- اگر escrow با `order_type=seo_ad_budget` وجود داشته باشد، باقی‌مانده escrow به advertiser برمی‌گردد و locked balance آزاد می‌شود.
- اگر کمپین legacy بدون escrow باشد، باقی‌مانده `remaining_budget` به advertiser برمی‌گردد.
- ad با وضعیت نهایی مثل `cancelled`, `rejected`, `expired`, `completed` بسته می‌شود.
- `remaining_budget = 0` می‌شود.

## تست DB واقعی

اسکریپت:

```text
tools/seo-task-finance-cleanup-db-test.php
```

سناریو:

1. ایجاد SEO از AdSystemManager
2. اجرای موفق یک تسک و پرداخت از escrow
3. بستن کمپین با refund باقی‌مانده
4. بررسی آزاد شدن locked balance و صفر شدن remaining_budget

خروجی مهم:

```json
{
  "ok": true,
  "before_close": {
    "ad": { "remaining_budget": "95000.0000" },
    "escrow": { "amount": "110000.00000000" },
    "adv_wallet": { "locked_irt": "110000.00000000" }
  },
  "close": {
    "success": true,
    "refund_amount": 110000,
    "status": "cancelled"
  },
  "after_close": {
    "ad": { "status": "cancelled", "remaining_budget": "0.0000" },
    "escrow": { "amount": "0.00000000", "status": "refunded" },
    "adv_wallet": { "locked_irt": "0.00000000" }
  }
}
```

# فاز ۵ — UI History / Detail

## اصلاحات

فایل‌ها:

```text
views/user/seo/history.php
views/user/seo/show-execution.php
```

- صفحه جزئیات اجرای SEO اضافه شد.
- تاریخچه به صفحه جزئیات لینک می‌دهد.
- جزئیات شامل امتیاز نهایی، پاداش، fraud_score، breakdown، وضعیت، reason/cancel_reason و سیگنال‌های تعامل است.

## تست Regression

همه تست‌های SEO پس از فاز ۴ و ۵ پاس شدند:

```text
seo-task-flow-db-test PASS
seo-task-reconciliation-db-test PASS
seo-task-finance-cleanup-db-test PASS
browser task-flow-test PASS
```
