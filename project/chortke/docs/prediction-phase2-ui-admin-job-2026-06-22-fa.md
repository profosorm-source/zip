# Prediction Phase 2 — Hub کاربر، پنل ادمین و Job Audit

تاریخ: ۱۴۰۵/۰۴/۰۱ — 2026-06-22

## خروجی فاز

### ۱) Hub تک‌صفحه‌ای کاربر
مسیر اصلی کاربر:

```text
/prediction
```

به Hub تک‌صفحه‌ای تبدیل شد و بخش‌های زیر بدون خروج از صفحه مدیریت می‌شوند:

- بازی‌های باز
- پیش‌بینی‌های من
- نتایج و تسویه‌ها
- قوانین شفاف

مسیر قدیمی زیر دیگر صفحه جداگانه اصلی نیست و برای سازگاری به Hub هدایت می‌شود:

```text
/prediction/my-bets → /prediction?section=my-bets
```

مسیر جزئیات مستقیم برای لینک‌های قدیمی و اشتراک‌گذاری حفظ شد:

```text
/prediction/{id}
```

### ۲) شفاف‌سازی قوانین در UI
در Hub کاربر و پنل ادمین، قوانین مالی با متن ساده اعلام شد:

1. مبلغ پیش‌بینی بعد از ثبت در مسیر امن مالی نگهداری می‌شود.
2. کمیسیون فقط از پول بازنده‌ها کسر می‌شود.
3. اصل مبلغ برنده‌ها مشمول کمیسیون نیست.
4. اگر همه درست پیش‌بینی کنند، اصل مبلغ‌ها برمی‌گردد و سود/کمیسیون صفر است.
5. اگر هیچ برنده‌ای نباشد، ۵۰٪ استخر به چرخه بعدی منتقل و ۵۰٪ سهم سایت ثبت می‌شود.
6. اگر بازی لغو شود، برگشت کامل انجام می‌شود.
7. عدد پیش‌نمایش قطعی نیست و با تغییر ترکیب پیش‌بینی‌ها تغییر می‌کند.

### ۳) پنل ادمین مدرن
مسیرهای ادمین بازطراحی شدند:

```text
/admin/prediction
/admin/prediction/create
/admin/prediction/{id}
```

پنل جدید شامل موارد زیر است:

- کارت‌های آماری دقیق برای کل بازی‌ها، بازی‌های باز، کل استخر، سهم سایت، انتقالی ثبت‌شده و ذخیره بازی بعدی
- لیست بازی‌ها با توزیع استخر
- عملیات بستن ثبت پیش‌بینی، ثبت نتیجه و تسویه، لغو و برگشت کامل
- صفحه جزئیات با وضعیت، محدوده مبلغ، کمیسیون، پاداش انتقالی، توزیع و لیست پیش‌بینی‌ها

### ۴) Route Labeling
Routeهای Prediction در `routes/missing.php` برچسب‌گذاری شدند:

- `PRIMARY / INDEPENDENT_MODULE / SINGLE_PAGE_HUB`
- `COMPATIBILITY_REDIRECT`
- `PRIMARY_DETAIL`
- `PRIMARY_ACTION / ESCROW_HOLD`
- `PRIMARY_ACTION / ADMIN_SETTLEMENT`
- `PRIMARY_ACTION / ADMIN_REFUND`

### ۵) Job Audit
`PredictionGameSettlementJob` بررسی شد. نتیجه:

- حذف نشد، چون هنوز در `app/Console/Kernel.php` هر ۱۵ دقیقه queue می‌شود.
- اما به عنوان `COMPATIBILITY_JOB / SCHEDULED_GUARD` مشخص شد.
- Job هیچ پرداخت مستقیمی انجام نمی‌دهد.
- فقط سناریوی legacy/internal را پوشش می‌دهد که بازی `closed` و دارای `result` است اما هنوز `winners_paid=0` دارد.
- مسیر واقعی تسویه همچنان فقط از `PredictionService::settleGame()` و Job اصلی settlement عبور می‌کند.

## تست‌های انجام‌شده

```text
php tools/prediction-phase1-finance-rules-db-test.php                  PASS
php tools/prediction-phase2-job-route-flow-db-test.php                 PASS
node /home/user/browser-test/prediction-phase2-user-hub-preview.js     PASS
node /home/user/browser-test/prediction-phase2-admin-preview.js        PASS
```

اسکرین‌شات‌ها:

```text
tools/browser-preview/screenshots/prediction-phase2-user-hub-open.png
tools/browser-preview/screenshots/prediction-phase2-user-hub-rules.png
tools/browser-preview/screenshots/prediction-phase2-admin-index.png
tools/browser-preview/screenshots/prediction-phase2-admin-show.png
```
