# 🔍 تحلیل عمیق Mixed Sync/Async Processing

**تاریخ:** 2026-07-02
**بررسی‌کننده:** Software Architect
**متدولوژی:** بررسی کد واقعی پروژه — نه حدسی

---

## 📊 وضعیت فعلی: ۳ لایه پردازش رویداد

لایه ۱: `EventDispatcher::dispatch()` → **SYNC** (همان thread/process)
لایه ۲: `EventDispatcher::dispatchAsync()` → **QUEUE** (push به صف، اجرا بعداً)
لایه ۳: `OutboxService::record()` → **OUTBOX** (ذخیره در DB، OutboxPublisher بعداً منتشر می‌کند)

---

## ✅ الگوهای صحیح موجود در پروژه

### ۱. Outbox Pattern (✅ ایمن)
```php
// EscrowListener.php:74
$outbox->record('escrow', $escrowId, 'wallet.deposit.requested', $payload);
// → فقط در جدول outbox_events INSERT می‌کند (داخل همان transaction)
// → OutboxPublisher بعداً در background منتشر می‌کند
```

### ۲. Idempotency Key (✅ ایمن)
```php
// FinancialEscrowService.php
->executeWithIdempotency($idempotencyKey, $userId, "hold_social_task_{$executionId}", ...)
```

### ۳. Saga Pattern (✅ ایمن)
```php
// FinancialEscrowService.php:95
$this->saga->setSaga('hold_social_task_escrow', $payload)
    ->addStep('verify_and_lock_balance', ...)
    ->addStep('create_escrow_record', ...)
    ->addStep('deduct_wallet_balance', ...)
```

---

## 🔴 مشکلات واقعی شناسایی‌شده

### مشکل ۱: Sync dispatch در عملیات مالی (۴ مورد)

| فایل | رویداد | ریسک |
|------|--------|------|
| `Jobs/CustomTask/PayRewardJob.php:92` | `dispatch('referral.commission.process', ...)` | 🟡 Medium |
| `Jobs/EscrowTimeoutJob.php:33` | `dispatch('escrow.auto_released', ...)` | 🟠 High |
| `Jobs/Vitrine/ReleaseVitrineFundsJob.php:104` | `dispatch('vitrine.escrow.released', ...)` | 🟠 High |
| `Jobs/Vitrine/ReleaseVitrineFundsJob.php:73` | `dispatch('vitrine.release_funds_requested', ...)` | 🟡 Medium |

**تحلیل:** این dispatch‌ها درون **Jobs** (background processes) هستند — نه HTTP requests.
- `EscrowTimeoutJob` یک cron job است که escrow‌های منقضی‌شده را آزاد می‌کند
- `dispatch('escrow.auto_released')` → **SYNC** → `EscrowListener` مستقیم اجرا می‌شود
- `EscrowListener.handle()` → عملیات wallet deposit + notification + audit → **همه sync**
- اگر notification service کند باشد، cron job بلاک می‌شود
- اگر wallet deposit خطا دهد، کل escrow release rollback نمی‌شود

### مشکل ۲: Listener‌هایی که از `app()->request` استفاده می‌کنند

| فایل | خط | کد | ریسک |
|------|-----|-----|------|
| `Listeners/EscrowListener.php:48` | `$correlationId = app()->request->header('x-request-id');` | 🔴 وقتی از Job صدا زده شود، `app()->request` null یا stale است |
| `Listeners/ReferralCommissionListener.php:43` | `$correlationId = app()->request->header('x-request-id');` | 🔴 همان مشکل |
| `Listeners/NotificationRequestListener.php:24` | `$correlationId = app()->request->header('x-request-id');` | 🔴 همان مشکل |

**تحلیل:** Listener‌ها هم در HTTP context و هم در CLI/Job context اجرا می‌شوند.
- وقتی از HTTP dispatch شوند → `app()->request` موجود است ✅
- وقتی از Job/CLI dispatch شوند → `app()->request` ممکن است null/stale باشد ❌
- پروژه قبلاً این را فهمیده و fallback گذاشته: `?? 'cli'`

### مشکل ۳: Outbox → Event Dispatch بدون Transaction Boundary

```
OutboxPublisher.publishEvent() 
  → events->dispatch(event_type, payload)  // SYNC dispatch
    → Listener.handle()
      → wallet.deposit()  // مالی
      → notification.send()  // I/O سنگین
      → audit.log()  // I/O سنگین
```

**مشکل:** Outbox باید event را به **صف** بفرستد، نه sync dispatch کند.
الان اگر notification fail شود، event در outbox status='processing' می‌ماند.

### مشکل ۴: FinancialEscrowService — Saga بدون Transaction Root

```php
// [SAGA REFACTOR] Tractions (beginTransaction) removed to allow true distributed Sagas
$this->saga->setSaga('hold_social_task_escrow', $payload)
    ->addStep('verify_and_lock_balance', function() {
        $this->db->query("SELECT ... FOR UPDATE", [...]);  // lock می‌کند
    })
    ->addStep('create_escrow_record', function() { ... })
    ->addStep('deduct_wallet_balance', function() { ... });  // withdraw می‌کند
```

**تحلیل:** کامنت می‌گوید "beginTransaction removed to allow true distributed Sagas".
اما این **خطرناک** است چون:
- Step 1: balance lock می‌کند (SELECT FOR UPDATE) → connection را نگه می‌دارد
- Step 2: escrow record می‌سازد → بدون transaction، commit نمی‌شود
- Step 3: wallet withdraw می‌کند → بدون transaction، partial state ممکن است
- اگر Step 3 fail کند → compensation اجرا می‌شود، ولی Step 2 قبلاً commit شده!

---

## 🎯 پیشنهادات واقعی (بر اساس کد پروژه)

### اولویت ۱: Listener‌ها → app()->request fallback (فوری — ۵ دقیقه)

```php
// در هر ۳ Listener مشکل‌دار:
$correlationId = null;
if (PHP_SAPI !== 'cli' && function_exists('app') && app()->request) {
    $correlationId = app()->request->header('x-request-id');
}
$correlationId = $correlationId ?? 'cli-' . uniqid();
```

### اولویت ۲: Outbox → dispatchAsync به جای dispatch (فوری — ۳۰ دقیقه)

در `OutboxPublisher.publishEvent()`:
```php
// ❌ قبلاً
$this->events->dispatch($event->event_type, $payload);

// ✅ باید
$this->events->dispatchAsync($event->event_type, $payload, 'events');
```

### اولویت ۳: Financial Operations → Outbox pattern (مهم — ۲ ساعت)

در Jobs مالی (EscrowTimeoutJob, ReleaseVitrineFundsJob):
```php
// ❌ قبلاً
$this->eventDispatcher->dispatch('escrow.auto_released', [...]);

// ✅ باید
$outbox->record('escrow', $orderId, 'escrow.auto_released', [...]);
```

### اولویت ۴: Saga — Transaction Root برگردانده شود (معماری — ۴ ساعت)

بازنگری در `FinancialEscrowService.executeWithIdempotency()`:
```php
// ✅ باید یک transaction root برای کل Saga داشته باشد
$this->db->transactional(function($db) use ($payload) {
    $this->saga->setSaga('...', $payload)
        ->addStep(...)->addStep(...)->addStep(...)
        ->execute();
});
```

---

## 📊 خلاصه وضعیت

| معیار | وضعیت فعلی | ریسک | اولویت |
|-------|-----------|------|--------|
| Outbox موجود | ✅ بله | — | — |
| Saga موجود | ✅ بله | 🟡 بدون tx root | بالا |
| Idempotency موجود | ✅ بله | — | — |
| Listener → app()->request | ⚠️ ۳ مورد | 🔴 null در CLI | فوری |
| Outbox → dispatchSync | ❌ باید async باشد | 🟡 partial state | بالا |
| Jobs → dispatchSync مالی | ❌ ۴ مورد | 🟠 blocking + no retry | بالا |

## 🛠️ نقشه اجرایی

1. **فوری (۵ دقیقه):** Fix `app()->request` fallback در ۳ Listener
2. **فوری (۳۰ دقیقه):** OutboxPublisher → dispatchAsync
3. **مهم (۲ ساعت):** Jobs مالی → Outbox pattern
4. **معماری (۴ ساعت):** Saga → Transaction Root
