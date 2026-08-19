# فاز ۱ Prediction — منطق مالی شفاف و قوانین استخر

تاریخ: ۱۴۰۵/۰۴/۰۱

## تصمیم محصول
مدل نهایی پیش‌بینی طبق تصمیم تأییدشده:

1. کمیسیون سایت فقط از پول بازنده‌ها کسر می‌شود، نه از اصل پول برنده‌ها.
2. برنده‌ها اصل مبلغ خود را پس می‌گیرند و سودشان از پول بازنده‌ها محاسبه می‌شود.
3. اگر همه درست پیش‌بینی کنند، همه فقط اصل مبلغ خود را پس می‌گیرند؛ سود و کمیسیون صفر است.
4. اگر هیچ‌کس درست پیش‌بینی نکند، همه پیش‌بینی‌ها بازنده محسوب می‌شوند؛ ۵۰٪ استخر به چرخه بازی‌های بعدی منتقل و ۵۰٪ برای هزینه‌های سایت ثبت می‌شود.
5. لغو بازی با «بدون برنده» فرق دارد: در لغو بازی همه مبالغ به کاربران برگشت داده می‌شود.

## فرمول تسویه

### حالت برنده وجود دارد

```text
winner_pool = مجموع مبلغ پیش‌بینی‌های درست
loser_pool  = total_pool - winner_pool
site_fee    = loser_pool × commission_percent
profit_pool = loser_pool - site_fee + bonus_pool

payout_winner = own_stake + (own_stake / winner_pool) × profit_pool
```

### همه درست گفته‌اند

```text
loser_pool = 0
site_fee = 0
profit_pool = bonus_pool
payout = own_stake + سهم از bonus_pool
```

اگر bonus_pool صفر باشد، همه فقط اصل مبلغ را پس می‌گیرند.

### هیچ برنده‌ای نیست

```text
winner_pool = 0
all bets = lost
site_fee = total_pool × 50%
rollover_amount = total_pool × 50% + bonus_pool
```

`rollover_amount` در `system_settings.prediction_rollover_reserve_usdt` ذخیره می‌شود تا در بازی بعدی به عنوان `bonus_pool_usdt` مصرف شود.

## تغییرات فنی

### Migration

```text
database/migrations/2026_06_21_0001_prediction_phase1_finance_rules.sql
```

افزوده‌ها:

```text
prediction_bets.payment_transaction_id
prediction_bets.payout_transaction_id
prediction_bets.refund_transaction_id
prediction_games.description
prediction_games.created_by
prediction_games.bonus_pool_usdt
prediction_games.site_fee_usdt
prediction_games.rollover_amount_usdt
prediction_games.settlement_policy
prediction_games.settlement_summary
system_settings.prediction_rollover_reserve_usdt
system_settings.prediction_no_winner_rollover_percent
system_settings.prediction_no_winner_site_percent
```

### Betting Hold
در `PlaceBetJob`، تراکنش برداشت/نگهداری شرط اکنون با کلید deterministic ثبت می‌شود:

```text
prediction_bet_hold_{userId}_{gameId}
```

و `payment_transaction_id` روی `prediction_bets` ذخیره می‌شود.

### Settlement
در `SettleGameJob`:

- hold اصلی هر bet تعیین تکلیف می‌شود.
- برنده: hold کامل می‌شود + payout واریز می‌شود.
- بازنده: hold کامل می‌شود و دریافتی ندارد.
- لغو بازی: hold cancel می‌شود و مبلغ به balance برمی‌گردد.
- no-winner: همه lost می‌شوند، refund انجام نمی‌شود، ۵۰٪ reserve و ۵۰٪ site fee ثبت می‌شود.
- idempotency payout/refund deterministic شد.

### Cancel
در `CancelGameJob`:

- دیگر deposit خام انجام نمی‌شود.
- hold اصلی شرط cancel می‌شود.
- bet با وضعیت `refunded` علامت می‌خورد.

## شفاف‌سازی قوانین در UI
قوانین در صفحات کاربر نمایش داده شد:

```text
views/user/prediction/index.php
views/user/prediction/show.php
```

و متن قدیمی ادمین که می‌گفت کمیسیون از کل استخر کسر می‌شود اصلاح شد:

```text
views/admin/prediction/create.php
views/admin/prediction/show.php
```

## رفع خطاهای فوری

- parse error صفحه `views/user/prediction/show.php` رفع شد.
- فایل خراب `public/assets/js/views/userpredictionshow.js` بازنویسی شد.
- placeholderهای خراب زیر اصلاح شدند:
  - `public/assets/js/views/adminpredictionindex.js`
  - `public/assets/js/views/adminpredictionshow.js`
- `Admin\PredictionController` در مسیرهای JSON، `HttpResponseException` را دیگر به خطای سیستمی تبدیل نمی‌کند.

## تست‌ها

### Syntax

```bash
php -l app/Jobs/Prediction/SettleGameJob.php
php -l app/Jobs/Prediction/CancelGameJob.php
php -l app/Jobs/Prediction/PlaceBetJob.php
php -l app/Models/PredictionBet.php
php -l app/Models/PredictionGame.php
php -l app/Controllers/Admin/PredictionController.php
php -l views/user/prediction/show.php
php -l views/user/prediction/index.php
node --check public/assets/js/views/userpredictionshow.js
node --check public/assets/js/views/adminpredictionindex.js
node --check public/assets/js/views/adminpredictionshow.js
```

### DB

```bash
php tools/prediction-phase1-finance-rules-db-test.php
```

سناریوهای تست‌شده:

- یک برنده و یک بازنده: برنده اصل مبلغ + سود از loser pool می‌گیرد، بازنده پولش را از دست می‌دهد، locked صفر می‌شود.
- همه برنده: همه اصل پول را پس می‌گیرند، کمیسیون صفر.
- هیچ برنده: همه lost، ۵۰٪ site fee و ۵۰٪ rollover reserve.
- لغو بازی: همه refunded، hold cancel می‌شود.
- double settle: پرداخت دوباره انجام نمی‌شود.

### Browser

```bash
node /home/user/browser-test/prediction-phase1-preview.js
```

نتیجه:

```json
{ "ok": true }
```

اسکرین‌شات:

```text
tools/browser-preview/screenshots/prediction-phase1-show-rules.png
```
