# گزارش نهایی یکپارچه‌سازی بازار تسک‌ها — ۲۰۲۶/۰۶/۲۰

## هدف
بعد از تکمیل منطق سه ماژول:

- SocialTask: امتیازی و بدون proof دستی
- SeoTask: engagement scoring + escrow payout + reject/cancel/fraud
- CustomTask: contract/proof_schema + auto-approve/expire/dispute/split/video

صفحه بازار یکپارچه `/tasks` باید با منطق جدید هماهنگ می‌شد.

## اصلاحات انجام‌شده

### ۱) منطق نمایش کارت‌ها
فایل:

```text
views/user/tasks/feed.php
```

اکنون هر نوع task، flow مستقل خودش را دارد:

#### Social

```text
Badge: بدون مدرک دستی
CTA: شروع اجرای امتیازی
منطق: behavior/time/interaction + mobile camera signal در حالت مشکوک
```

#### SEO

```text
Badge: Engagement scoring
CTA: شروع اجرای SEO
منطق: target_opened، active_time، scroll، interaction، fraud_score، payout از escrow
```

#### CustomTask

```text
Badge: Proof: {proof_type}
CTA: مشاهده قرارداد
منطق: proof_schema، duplicate check، auto-approve، dispute، split resolve
```

### ۲) پنل جزئیات هوشمند شد
فایل:

```text
public/assets/js/views/usertasksfeed.js
```

پنل جزئیات اکنون به‌جای متن ثابت، مراحل و توضیح متناسب با نوع تسک را نشان می‌دهد:

```text
data-step-1..4
data-flow-note
data-start-label
data-flow-badge
```

### ۳) اصلاح منطق فیلتر feed
فایل:

```text
app/Services/UnifiedTaskService.php
```

- Social rejected دوباره قابل نمایش است.
- SEO اگر امروز برای همان user اجرا شده باشد دوباره نمایش داده نمی‌شود، حتی اگر rejected باشد.
- CustomTask فقط در حالت‌های `expired/cancelled/rejected` دوباره قابل دریافت است.
- self task همچنان از بازار حذف می‌شود.

## تست DB واقعی

اسکریپت:

```text
tools/task-marketplace-unified-db-test.php
```

سناریوها:

1. نمایش همزمان social/seo/custom در بازار.
2. حذف task متعلق به خود کاربر.
3. فیلتر `type=social`.
4. حذف CustomTask در صورت submission فعال.
5. حذف SEO در صورت execution امروز.
6. نمایش مجدد Social در صورت rejected.

خروجی:

```json
{
  "ok": true
}
```

## تست مرورگر واقعی

Preview:

```text
tools/browser-preview/task-marketplace-unified-final.html
```

Playwright:

```text
/home/user/browser-test/task-marketplace-unified-final.js
```

Screenshot:

```text
tools/browser-preview/screenshots/task-marketplace-unified-final.png
```

خروجی:

```json
{
  "ok": true,
  "errors": []
}
```

## وضعیت نهایی
بازار تسک‌ها اکنون با منطق نهایی سه ماژول هماهنگ است:

```text
/tasks
/tasks?type=social
/tasks?type=seo
/tasks?type=custom_task
```

و هر ماژول مسیر اجرای واقعی خودش را دارد:

```text
Social → start → execute score-based بدون proof دستی
SEO → start → execute engagement scoring + escrow payout
Custom → detail contract → start → proof schema → review/dispute
```
