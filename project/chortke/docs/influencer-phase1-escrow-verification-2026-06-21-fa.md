# فاز ۱ اینفلوئنسر — استقلال ماژول، Escrow واقعی، Badge و تأیید با اسکرین‌شات

تاریخ: ۱۴۰۵/۰۳/۳۱

## تصمیم معماری
ماژول اینفلوئنسر مستقل از Ads باقی می‌ماند:

```text
Influencer = Independent Marketplace Module / PRIMARY
Ads = Campaign Delivery Module
```

بنابراین فعلاً هیچ Adapter جدیدی به `AdSystemManager` اضافه نشد و Wizard ثبت تبلیغ Ads تغییر نکرد. اتصال ظاهری آینده می‌تواند فقط به‌صورت لینک/کارت راهنما باشد، نه ادغام چرخه مالی و سفارش.

## ۱. Escrow واقعی برای سفارش اینفلوئنسر
مسیر مالی سفارش از برداشت/واریز مستقیم به امانت واقعی تغییر کرد.

### جریان جدید سفارش

```text
تبلیغ‌دهنده سفارش می‌سازد
→ story_orders.status = pending_acceptance
→ FinancialEscrowService::holdInfluencerOrderFunds
→ کیف پول تبلیغ‌دهنده: balance کم، locked زیاد
→ escrow_transactions.status = in_escrow
```

### پذیرش/انجام/تسویه

```text
اینفلوئنسر سفارش را قبول می‌کند
→ accepted

اینفلوئنسر مدرک می‌فرستد
→ awaiting_buyer_check

تبلیغ‌دهنده تأیید می‌کند
→ FinancialEscrowService::releaseInfluencerOrderFunds
→ hold کامل تبلیغ‌دهنده complete می‌شود
→ فقط سهم اینفلوئنسر بعد از کسر کارمزد سایت به کیف پول او واریز می‌شود
→ story_orders.status = completed
→ escrow_transactions.status = released
```

### رد/بازگشت وجه

```text
اینفلوئنسر سفارش را رد می‌کند
→ FinancialEscrowService::refundInfluencerOrderFunds
→ hold تبلیغ‌دهنده cancel می‌شود
→ story_orders.status = refunded
→ escrow_transactions.status = refunded
```

### اختلاف/داوری ادمین
برای اختلاف‌های `influencer_order`، مسیر `DisputeCommandService::adminResolve` اکنون قبل از مسیر عمومی، escrow سفارش اینفلوئنسر را تعیین تکلیف می‌کند:

- `favor_influencer` → release escrow + پرداخت سهم اینفلوئنسر
- `favor_customer` → refund escrow + بازگشت وجه کامل به تبلیغ‌دهنده
- `partial` → استفاده از `FinancialEscrowService::resolveDisputedEscrow`

## ۲. Badge سفارش‌های جدید در Sidebar
نام لینک سایدبار کاربر به فارسی و طبق تصمیم محصول شد:

```text
اینفلوئنسر
```

Badge کنار همین لینک تعداد سفارش‌های نیازمند پاسخ اینفلوئنسر را نشان می‌دهد:

```sql
status IN ('pending', 'paid', 'pending_acceptance')
```

زیرمنوی جداگانه‌ی `Influencer Marketing` از سایدبار حذف شد تا فقط یک لینک برای این بخش داشته باشیم.

## ۳. تأیید پروفایل با اسکرین‌شات و fallback به مدیر
بدون استفاده از API خارجی، مسیر تأیید مالکیت پروفایل با اسکرین‌شات اضافه شد.

### جریان جدید

```text
کاربر کد تأیید را در پست/استوری قرار می‌دهد
→ لینک پست/استوری را وارد می‌کند
→ اسکرین‌شات واضح بارگذاری می‌کند
→ کدی که در تصویر دیده می‌شود را وارد می‌کند
→ سیستم امتیاز تأیید خودکار می‌دهد
```

### منطق امتیازدهی فعلی

```text
اسکرین‌شات آپلود شده باشد: +40
لینک با پلتفرم پروفایل هماهنگ باشد: +25
کد واردشده با کد تأیید تولیدشده یکی باشد: +35
```

اگر امتیاز حداقل ۸۵ باشد:

```text
auto_verified
profile.status = verified
verification.status = approved
```

اگر امتیاز کافی نباشد:

```text
profile.status = pending_admin_review
verification.status = submitted
```

یعنی اگر سیستم نتواند با اطمینان تأیید کند، fallback به مدیر انجام می‌شود.

## ۴. پاک‌سازی JSهای شکسته اینفلوئنسر
چند فایل JS اینفلوئنسر دارای تکه‌های PHP/HTML خام بودند و در مرورگر خطای Syntax می‌دادند. این فایل‌ها اصلاح شدند:

```text
public/assets/js/views/userinfluenceradvertise.js
public/assets/js/views/userinfluencercreateorder.js
public/assets/js/views/userinfluencerdisputepanel.js
public/assets/js/views/userinfluencermyorders.js
public/assets/js/views/userinfluencermyplacedorders.js
public/assets/js/views/userinfluencermyprofile.js
public/assets/js/views/admininfluencerdisputedetail.js
public/assets/js/views/admininfluencerprofiles.js
public/assets/js/views/admininfluencerverifications.js
```

CSSهای مفقود لازم برای صفحات فعلی هم اضافه شدند:

```text
public/assets/css/views/userinfluencermyprofile.css
public/assets/css/views/userinfluencercreateorder.css
public/assets/css/views/userinfluencerdisputepanel.css
```

## تست‌ها

### Syntax

```bash
php -l app/Domain/Financial/Services/FinancialEscrowService.php
php -l app/Services/Influencer/InfluencerCommandService.php
php -l app/Services/Dispute/DisputeCommandService.php
php -l app/Services/VerificationService.php
php -l app/Controllers/User/InfluencerController.php
php -l app/Models/StoryOrder.php
php -l views/partials/user/sidebar.php
php -l views/user/influencer/my-profile.php
node --check public/assets/js/views/userinfluencer*.js
node --check public/assets/js/views/admininfluencer*.js
```

### DB

```bash
php tools/influencer-phase1-escrow-flow-db-test.php
php tools/influencer-phase1-screenshot-verification-db-test.php
php tools/influencer-phase1-dispute-escrow-db-test.php
```

نتایج اصلی:

- سفارش موفق: `pending_acceptance → accepted → awaiting_buyer_check → completed`
- escrow: `in_escrow → released`
- کیف پول تبلیغ‌دهنده: locked بعد از تسویه صفر می‌شود
- کیف پول اینفلوئنسر: فقط سهم بعد از کارمزد سایت واریز می‌شود
- رد سفارش: escrow refunded و locked تبلیغ‌دهنده صفر می‌شود
- double confirm: پرداخت تکراری انجام نمی‌شود
- تأیید اسکرین‌شات موفق: auto approved
- تأیید نامطمئن: fallback به مدیر
- داوری ادمین به نفع تبلیغ‌دهنده: refund از escrow

### Browser

```bash
node /home/user/browser-test/influencer-phase1-sidebar-preview.js
```

نتیجه:

```json
{ "ok": true }
```

اسکرین‌شات:

```text
tools/browser-preview/screenshots/influencer-phase1-sidebar.png
```

## نکته‌های باقی‌مانده برای فاز بعد
فاز ۱ منطق مالی پایه، badge و verification را امن‌تر کرد؛ اما UI اصلی اینفلوئنسر هنوز چند صفحه‌ای و قدیمی است. فاز بعدی باید UI را به یک Hub تک‌صفحه‌ای مستقل تبدیل کند:

```text
/influencer
  داشبورد
  پیج من
  سفارش‌های دریافتی
  سفارش تبلیغ به اینفلوئنسر
  سفارش‌های من
  اختلاف‌ها
```
