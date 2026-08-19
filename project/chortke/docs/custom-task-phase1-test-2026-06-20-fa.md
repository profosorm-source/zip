# گزارش اصلاح فاز ۱ CustomTask — ۲۰۲۶/۰۶/۲۰

## هدف فاز ۱
تثبیت مسیر اصلی CustomTask و رفع ناسازگاری‌های پایه:

- ایجاد تسک سفارشی از مسیر Unified Ads Wizard
- یکسان‌سازی `total_count` / `total_quantity`
- یکسان‌سازی currency به `irt/usdt`
- ثبت درست ظرفیت و بودجه
- اتصال escrow به `custom_task_budget` با `task_id`
- اصلاح start/proof/review/payment/counters
- حذف double-payment در approve
- اصلاح state machine پایه و cron propertyهای ناقص

## اصلاحات انجام‌شده

### ۱) مسیر ایجاد تسک از `/ads/create` و `/ads/store`
فایل خراب:

```text
public/assets/js/views/useradscreate.js
```

قبلاً فقط این محتوا را داشت و syntax error می‌داد:

```js
" defer>
```

اصلاح شد و wizard واقعی در این فایل قرار گرفت.

### ۲) CustomTaskAdapter
فایل:

```text
app/Adapters/CustomTaskAdapter.php
```

اکنون فیلدهای اصلی را درست ذخیره می‌کند:

```text
task_type
proof_type
proof_description
proof_schema
price_per_task
total_budget
remaining_budget
total_count
remaining_count
pending_count
completed_count
deadline_hours
auto_approve_hours
restrictions
```

### ۳) AdSystemManager
فایل:

```text
app/Services/AdSystemManager.php
```

اگر `total_budget` ارسال نشده باشد، برای taskها از این فرمول استفاده می‌کند:

```text
price_per_task × total_count
```

برای CustomTask بعد از ایجاد ad، escrow از حالت saga id به این تغییر می‌کند:

```text
order_id   = task_id
order_type = custom_task_budget
```

### ۴) Escrow
فایل‌ها:

```text
app/Models/Escrow.php
app/Services/EscrowService.php
```

مشکل FK مربوط به `seller_id = 0` اصلاح شد. برای custom_task_budget، seller_id فعلاً advertiser است، ولی partial release می‌تواند به worker انجام شود.

### ۵) Review/Payment/Counters
فایل:

```text
app/Services/CustomTask/CustomTaskModerationService.php
```

approve اکنون این‌ها را درست انجام می‌دهد:

```text
submission.status = approved
reward_paid = 1
reward_transaction_id ثبت می‌شود
ads.pending_count کم می‌شود
ads.completed_count زیاد می‌شود
ads.remaining_budget کم می‌شود
escrow partial_release انجام می‌شود یا fallback wallet deposit
```

double-payment از outbox wallet.deposit حذف شد.

### ۶) State Machine / Cron / Admin bugs
فایل‌ها:

```text
app/Services/StateMachineService.php
app/Jobs/CustomTask/CronSubmissionsJob.php
app/Services/CustomTask/AdminCustomTaskService.php
```

اصلاح شد:

```text
in_progress -> submitted / expired / cancelled
submitted -> approved / rejected / disputed / expired
rejected -> disputed
```

همچنین propertyهای گم‌شده در Cron و `$this->adModel` اشتباه در AdminCustomTaskService اصلاح شد.

### ۷) Migration جدید

```text
database/migrations/2026_06_19_0003_custom_task_reconciliation.sql
```

اضافه/اصلاح می‌کند:

```text
custom_task_submissions.status به VARCHAR(50)
proof_code
proof_data
reviewed_by
auto_approved_at
dispute_id
ads.proof_schema
ads.auto_approve_hours
ads.reject_rules
indexهای کاربردی
```

## تست‌های واقعی DB

### تست worker flow بدون escrow
اسکریپت:

```text
tools/custom-task-flow-db-test.php
```

نتیجه مهم:

```json
{
  "start": { "success": true },
  "proof": { "success": true },
  "review": { "success": true },
  "submission_after_review": {
    "status": "approved",
    "reward_paid": 1,
    "reward_transaction_id": "..."
  },
  "task_after": {
    "remaining_count": 9,
    "pending_count": 0,
    "completed_count": 1,
    "remaining_budget": "90000.0000"
  }
}
```

### تست ایجاد از AdSystemManager + escrow + worker flow
اسکریپت:

```text
tools/custom-task-admanager-db-test.php
```

نتیجه مهم:

```json
{
  "ok": true,
  "ad": {
    "total_count": 3,
    "remaining_count": 3,
    "proof_type": "code",
    "status": "active"
  },
  "escrow": {
    "order_type": "custom_task_budget",
    "amount": "33000.00000000"
  },
  "review": {
    "success": true
  },
  "submission_after": {
    "status": "approved",
    "reward_paid": 1,
    "reward_transaction_id": "escrow_partial_release_..."
  },
  "ad_after": {
    "remaining_count": 2,
    "pending_count": 0,
    "completed_count": 1,
    "remaining_budget": "20000.0000"
  },
  "escrow_after": {
    "amount": "23000.00000000",
    "partial_released": "10000.00000000",
    "status": "partial"
  },
  "worker_wallet": {
    "balance_irt": "10000.00000000"
  }
}
```

## وضعیت باقی‌مانده برای فازهای بعد

فاز ۲ باید روی Proof Schema کامل‌تر متمرکز شود:

- نمایش فرم proof دقیقاً بر اساس proof_type
- اجباری کردن دقیق `proof_code`, `proof_url`, `proof_file`, `proof_text` بر اساس schema
- duplicate check برای proof_code/url/file

فاز ۳ باید روی auto-approve/expire/dispute کامل‌تر شود.

# تکمیل فاز ۲ — Proof Schema سخت‌گیرانه

## هدف
در فاز ۲، CustomTask بر اساس `proof_type` اعتبارسنجی می‌شود، نه فقط «حداقل یکی از متن/فایل».

## Migration جدید

```text
database/migrations/2026_06_20_0001_custom_task_proof_schema_indexes.sql
```

این migration indexهای duplicate-check را اضافه می‌کند:

```text
idx_cts_task_proof_code
idx_cts_task_proof_url
```

## قوانین جدید proof_type

```text
text       → proof_text حداقل ۱۰ کاراکتر الزامی
code       → proof_code الزامی و برای همان task غیرتکراری
url        → proof_url معتبر الزامی و برای همان task غیرتکراری
screenshot → proof_file تصویر JPG/PNG/WEBP الزامی
file       → proof_file تصویر یا PDF خصوصی تا ۵MB الزامی
video      → فعلاً proof_url ویدیو الزامی؛ آپلود ویدیو در این فاز فعال نیست
```

## فایل‌های اصلاح‌شده

```text
app/Controllers/User/CustomTaskController.php
app/Jobs/CustomTask/SubmitProofJob.php
views/user/custom-tasks/proof.php
public/assets/js/views/usercustomtaskproof.js
app/Models/CustomTaskSubmissionModel.php
```

## تست DB واقعی Proof Schema

اسکریپت:

```text
tools/custom-task-proof-schema-db-test.php
```

سناریو:

1. تسک با `proof_type=code` ایجاد شد.
2. ارسال بدون `proof_code` رد شد.
3. ارسال با کد یکتا قبول شد.
4. worker دوم همان کد را ارسال کرد و به خاطر duplicate رد شد.
5. worker دوم با کد متفاوت قبول شد.

خروجی مهم:

```json
{
  "ok": true,
  "missing_code": {
    "success": false,
    "message": "برای این تسک، کد یا شناسه مدرک الزامی است."
  },
  "first_code": {
    "success": true
  },
  "duplicate_code": {
    "success": false,
    "message": "این کد/شناسه قبلاً برای این تسک ارسال شده است."
  },
  "second_code": {
    "success": true
  }
}
```

## تست مرورگر
بعد از این تغییرات، تست Playwright فلو taskها دوباره اجرا شد:

```text
node /home/user/browser-test/task-flow-test.js
```

نتیجه:

```json
{
  "ok": true,
  "errors": []
}
```

# تکمیل فاز ۳ — Auto Approve / Expire / Dispute

## هدف
فاز ۳ برای production-safe شدن lifecycle تسک سفارشی انجام شد:

- auto approve ارسال‌های قدیمی بعد از SLA
- expire اجراهای شروع‌شده که proof نفرستاده‌اند
- ایجاد dispute بعد از rejection
- resolve اختلاف توسط ادمین به نفع worker یا advertiser
- اطمینان از اینکه شمارنده‌ها و پرداخت‌ها بعد از dispute درست می‌مانند

## Migration جدید

```text
database/migrations/2026_06_20_0002_custom_task_phase3_auto_dispute.sql
```

اضافه/تثبیت می‌کند:

```text
custom_task_submissions.auto_approved_at
custom_task_submissions.dispute_id
idx_disputes_ref_status
idx_disputes_user_status
idx_cts_auto_approve
idx_cts_expire
```

## اصلاحات اصلی

### ۱) Auto approve
فایل‌ها:

```text
app/Jobs/CustomTask/CronSubmissionsJob.php
app/Models/CustomTaskSubmissionModel.php
```

- Query قبلی auto approve به ستون ناموجود `disputes.submission_id` وابسته بود؛ اصلاح شد به:

```text
disputes.ref_type = 'custom_task_submission'
disputes.ref_id = submission_id
```

- بعد از auto approve مقدار `auto_approved_at` ثبت می‌شود.

### ۲) Expire اجراهای قدیمی
فایل:

```text
app/Jobs/CustomTask/CronSubmissionsJob.php
```

- propertyهای `db` و `taskModel` اضافه شدند.
- rollback امن شد.
- با expire شدن submission، `pending_count` کم و ظرفیت آزاد می‌شود.

### ۳) Dispute سمت کاربر
فایل‌ها:

```text
app/Controllers/User/CustomTaskController.php
views/user/custom-tasks/executor/my-submissions.php
views/user/custom-tasks/executor/disputes.php
```

- کاربر فقط بعد از رد شدن submission می‌تواند اختلاف ثبت کند.
- اختلاف با این ref ثبت می‌شود:

```text
ref_type = custom_task_submission
ref_id   = submission_id
```

- status submission به `disputed` تغییر می‌کند.

### ۴) Dispute سمت ادمین
فایل‌ها:

```text
routes/admin.php
app/Controllers/Admin/ExecutorTaskController.php
app/Services/Shared/DisputeService.php
app/Services/Dispute/DisputeQueryService.php
views/admin/custom-tasks/disputes.php
public/assets/js/admin/customtasks.js
```

- مسیر لیست اختلافات اضافه شد:

```text
GET /admin/custom-tasks/disputes
```

- resolve اختلاف:

```text
POST /admin/custom-tasks/disputes/resolve
```

- تصمیم‌ها:

```text
executor   → تأیید submission، پرداخت پاداش، حل به نفع worker
advertiser → رد/حفظ رد submission، حل به نفع advertiser
```

## تست DB واقعی

اسکریپت:

```text
tools/custom-task-phase3-db-test.php
```

سناریوهای تست‌شده:

1. rejection → dispute → admin resolve به نفع executor → approve + reward
2. submitted قدیمی → auto approve
3. in_progress با deadline گذشته → expire

خروجی مهم:

```json
{
  "ok": true,
  "resolve": {
    "ok": true,
    "message": "اختلاف با موفقیت حل شد."
  },
  "sub1_after": {
    "status": "approved",
    "reward_paid": 1
  },
  "dispute_after": {
    "status": "resolved_for_executor",
    "admin_decision": "worker_wins"
  },
  "auto_count": 1,
  "sub2_after": {
    "status": "approved",
    "reward_paid": 1,
    "auto_approved_at": "..."
  },
  "expired_count": 1,
  "sub3_after": {
    "status": "expired"
  },
  "task3_after": {
    "pending_count": 0
  }
}
```

## وضعیت بعد از فاز ۳
CustomTask اکنون lifecycle اصلی زیر را دارد:

```text
create → start → submit proof → advertiser review
                    ↘ reject → dispute → admin resolve
submitted old → auto approve
in_progress overdue → expire
```

## باقی‌مانده احتمالی برای فاز بعد
- UI کامل‌تر برای صفحه جزئیات dispute و پیام‌های دوطرفه
- resolve split/refund درصدی برای سناریوهای پیچیده‌تر
- policy پیشرفته برای video upload در proof_type=video

# تکمیل تکمیلی فاز ۳ — UI جزئیات اختلاف و چت

## هدف
بعد از فعال شدن dispute lifecycle، صفحه جزئیات اختلاف و پیام‌های دوطرفه اضافه شد تا کاربر/ادمین فقط لیست خام اختلاف‌ها را نبینند.

## مسیرهای جدید کاربر

```text
GET  /custom-tasks/disputes/{id}
POST /custom-tasks/disputes/{id}/reply
```

## مسیرهای جدید ادمین

```text
GET  /admin/custom-tasks/disputes/{id}
POST /admin/custom-tasks/disputes/{id}/reply
```

## فایل‌های اضافه/اصلاح‌شده

```text
views/user/custom-tasks/executor/dispute-detail.php
views/user/custom-tasks/executor/disputes.php
views/admin/custom-tasks/dispute-detail.php
views/admin/custom-tasks/disputes.php
app/Controllers/User/CustomTaskController.php
app/Controllers/Admin/ExecutorTaskController.php
routes/user.php
routes/admin.php
public/assets/js/admin/customtasks.js
```

## قابلیت‌ها

- مشاهده جزئیات اختلاف شامل task، submission، reward، طرفین، دلیل رد و دلیل اختلاف.
- چت دوطرفه worker/advertiser در صفحه کاربر.
- پیام ادمین در صفحه admin detail.
- لینک از لیست اختلاف‌ها به صفحه جزئیات.
- resolve سریع ادمین به نفع executor یا advertiser.

## تست مرورگر

Preview:

```text
tools/browser-preview/custom-task-dispute-detail-preview.html
```

Playwright:

```text
/home/user/browser-test/custom-task-dispute-detail-preview.js
```

Screenshot:

```text
tools/browser-preview/screenshots/custom-task-dispute-detail.png
```

خروجی:

```json
{
  "ok": true,
  "errors": []
}
```

# تکمیل فاز ۴ — Split Resolve / Video Proof / Advertiser Review Dashboard

## هدف
بعد از کامل شدن فازهای ۱ تا ۳، سه قابلیت باقی‌مانده تکمیل شد:

- حل اختلاف به صورت split / پرداخت درصدی به مجری
- policy مدرک ویدیویی
- داشبورد تبلیغ‌دهنده برای بررسی submissionها و مدارک

## Split / پرداخت درصدی
اکنون ادمین در resolve اختلاف می‌تواند decision زیر را ارسال کند:

```json
{
  "decision": "split",
  "executor_percent": 40,
  "admin_note": "پرداخت سهمی به مجری"
}
```

در این حالت:

```text
custom_task_submissions.status = resolved_split
reward_paid = 1
paid_amount = reward_amount × executor_percent / 100
resolution_type = split
resolution_note ثبت می‌شود
```

اگر escrow وجود داشته باشد، partialRelease انجام می‌شود؛ در غیر این صورت fallback به wallet deposit انجام می‌شود.

## Video proof policy
برای `proof_type=video` اکنون یکی از این دو مورد لازم است:

```text
proof_url معتبر
یا proof_file ویدیویی
```

فرمت‌های فایل ویدیویی مجاز:

```text
mp4
webm
mov
```

حداکثر حجم:

```text
30MB
```

ویدیوها مثل proofهای خصوصی در مسیر private storage ذخیره می‌شوند و public نیستند.

## Advertiser Review Dashboard
در صفحه جزئیات آگهی/تسک تبلیغ‌دهنده:

```text
/ads/{id}
```

برای custom_task اکنون کارفرما می‌تواند:

- مدرک submission را باز کند
- proof_text / proof_code / proof_url / proof_file را ببیند
- submission با وضعیت submitted را approve کند
- submission را با reason رد کند
- اگر اختلاف باز است، لینک اختلاف را ببیند

## فایل‌های اصلاح‌شده

```text
app/Services/Shared/DisputeService.php
app/Controllers/Admin/ExecutorTaskController.php
app/Controllers/User/CustomTaskController.php
app/Jobs/CustomTask/SubmitProofJob.php
views/user/custom-tasks/proof.php
views/admin/custom-tasks/dispute-detail.php
views/user/ads/show.php
public/assets/js/admin/customtasks.js
public/assets/js/views/usercustomtaskproof.js
```

## تست DB واقعی فاز ۴

اسکریپت:

```text
tools/custom-task-phase4-db-test.php
```

سناریوهای تست‌شده:

1. dispute split با ۴۰٪ سهم مجری
2. proof_type=video بدون لینک/فایل → reject
3. proof_type=video با proof_url معتبر → accept

خروجی مهم:

```json
{
  "ok": true,
  "sub_split": {
    "status": "resolved_split",
    "reward_paid": 1,
    "paid_amount": "4000.0000",
    "resolution_type": "split"
  },
  "worker_wallet": {
    "balance_irt": "4000.00000000"
  },
  "missing_video": {
    "success": false
  },
  "video_ok": {
    "success": true
  }
}
```

## تست مرورگر
تست‌های قبلی UI اختلاف دوباره بعد از اضافه شدن split اجرا شدند:

```text
custom-task-phase3-preview.js
custom-task-dispute-detail-preview.js
```

هر دو بدون console/page error پاس شدند.

## تست تکمیلی Split با Escrow واقعی
برای اطمینان از اینکه split فقط در fallback wallet کار نمی‌کند، سناریوی split روی تسکی که از AdSystemManager ساخته شده و escrow واقعی دارد نیز تست شد.

اسکریپت:

```text
tools/custom-task-split-escrow-db-test.php
```

سناریو:

1. ایجاد CustomTask از AdSystemManager
2. ایجاد escrow با `order_type=custom_task_budget`
3. شروع توسط worker
4. ارسال proof
5. reject توسط advertiser
6. ثبت dispute
7. resolve split با ۴۰٪ سهم worker

خروجی مهم:

```json
{
  "ok": true,
  "submission": {
    "status": "resolved_split",
    "paid_amount": "4000.0000",
    "resolution_type": "split"
  },
  "escrow_after": {
    "amount": "29000.00000000",
    "partial_released": "4000.00000000",
    "status": "partial"
  },
  "wallets": {
    "worker_balance": "4000.00000000",
    "adv_locked": "29000.00000000"
  }
}
```
