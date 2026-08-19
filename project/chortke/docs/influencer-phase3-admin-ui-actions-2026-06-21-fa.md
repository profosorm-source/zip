# فاز ۳ اینفلوئنسر — پنل ادمین و اکشن‌های مدیریتی

تاریخ: ۱۴۰۵/۰۳/۳۱

## هدف
بعد از مستقل‌سازی ماژول اینفلوئنسر و اتصال واقعی به escrow، پنل مدیریت اینفلوئنسر بازطراحی و اکشن‌های مدیریتی آن تست شد.

## صفحات بازطراحی‌شده

```text
/admin/influencer/orders
/admin/influencer/profiles
/admin/influencer/verifications
/admin/influencer/disputes
/admin/influencer/disputes/{id}
```

فایل‌های UI:

```text
views/admin/influencer/_admin-nav.php
views/admin/influencer/orders.php
views/admin/influencer/profiles.php
views/admin/influencer/verifications.php
views/admin/influencer/disputes.php
views/admin/influencer/dispute-detail.php
public/assets/css/views/admininfluencer.css
public/assets/js/admin/influencer.js
```

## اصلاحات Backend

### Admin\InfluencerController
- جستجوی سفارش‌ها و پروفایل‌ها از مسیر مدل‌های خود ماژول انجام می‌شود، نه `SearchOrchestrator` عمومی که برای این surface دقیق نبود.
- `profiles` به متدهای واقعی مدل وصل شد:
  - `adminListProfiles`
  - `adminCountProfiles`
- پاسخ‌های verification به فرم `success/message` نرمال شد تا JS ادمین درست واکنش بدهد.
- `disputeDetail` از `ref_id` برای پیدا کردن سفارش استفاده می‌کند، نه ستون نامعتبر/غیرقطعی `order_id` روی جدول disputes.
- داوری ادمین همچنان از `DisputeService::adminResolve` عبور می‌کند که در فاز قبل به escrow اینفلوئنسر وصل شد.

## اکشن‌های ادمین تست‌شده

```text
approve profile
reject profile
suspend profile
approve verification
reject verification
resolve influencer dispute via escrow
```

تست مرورگری HTTP:

```bash
node /home/user/browser-test/influencer-phase3-admin-actions.js
```

نتیجه:

```json
{ "ok": true }
```

## تست Preview مرورگر

```bash
node /home/user/browser-test/influencer-phase3-admin-preview.js
```

نتیجه:

```json
{ "ok": true }
```

اسکرین‌شات‌ها:

```text
tools/browser-preview/screenshots/influencer-phase3-admin-orders.png
tools/browser-preview/screenshots/influencer-phase3-admin-profiles.png
tools/browser-preview/screenshots/influencer-phase3-admin-verifications.png
tools/browser-preview/screenshots/influencer-phase3-admin-disputes.png
tools/browser-preview/screenshots/influencer-phase3-admin-dispute-detail.png
```

## تست‌های Regression مرتبط

```bash
php tools/influencer-phase1-escrow-flow-db-test.php
php tools/influencer-phase1-screenshot-verification-db-test.php
php tools/influencer-phase1-dispute-escrow-db-test.php
node /home/user/browser-test/influencer-phase2-hub-preview.js
node /home/user/browser-test/influencer-phase2-hub-actions.js
```

همه پاس شدند.

## نتیجه
پنل ادمین اینفلوئنسر اکنون با معماری مستقل ماژول، escrow واقعی، تأیید اسکرین‌شات، و داوری مالی هماهنگ است.
