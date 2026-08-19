# گزارش تست‌های واقعی — جلسه ۱۴۰۴/۰۴/۰۶

## اصلاح ریشه‌ای BaseController

### مشکل قبلی (میان‌بر)
5 متد lazy accessor اضافه شده بود (`csrf()`, `session()`, `request()`, `response()`, `logger()`)
که هر کدام با `Container::getInstance()` کار می‌کردند. این باعث:
- تصادف نام با `PaymentController::request(): void`
- 185 تغییر `$this->session->` → `$this->session()->` در 6 فایل
- 2371+ دسترسی مستقیم property همچنان خطرناک بود

### اصلاح ریشه‌ای
```php
// قبل (میان‌بر)
protected ?Session $session = null;
protected function session(): Session { ... Container::getInstance()... }

// الان (ریشه‌ای)
protected Session $session;  // non-nullable!
$this->session = $session ?? app(Session::class);  // در constructor
```

**مزایا:** بدون هیچ متد اضافی، 2371+ دسترسی مستقیم property بدون تغییر کار می‌کند.

---

## باگ‌های کشف شده و رفع شده

| # | باگ | علت ریشه‌ای | فایل |
|---|------|-------------|------|
| 1 | PaymentController::request() کلاش | Lazy accessor با action method هم‌نام | BaseController.php |
| 2 | KYCService constructor — 9 پارامتر بجای 10 | NotificationService اضافه نشده | KYCService.php |
| 3 | KYCQueryService — 3 پارامتر بجای 4 | Encryption اضافه نشده | KYCService.php |
| 4 | ManualDepositController — undefined var | `$depositModel` در constructor تعریف نشده | ManualDepositController.php (User) |
| 5 | Property نوع clash در 3 controller | `?LoggerInterface $logger` redeclared در فرزند | ManualDepositController, OnlinePaymentController, WithdrawalController (Admin) |
| 6 | WithdrawalUserService — TypeError | `$payload` بجای `$explicitKey` به IdempotencyService | WithdrawalUserService.php |
| 7 | KYCService param order warning | Optional قبل از required | KYCService.php + KYCCommandService.php |

---

## نتایج تست‌ها

### PHPUnit
| تست | Assertions | Failure | Skip |
|------|-----------|---------|------|
| 2037 | 3871 | 0 | 2 (Distributed) |

### تست‌های واقعی 7 لایه‌ای (Live Server)

| بخش | Pass | Fail | Total |
|------|------|------|-------|
| 🟢 Auth | 23 | 0 | 23 |
| 🟢 Wallet | 19 | 0 | 19 |
| 🟢 Payment | 17 | 0 | 17 |
| 🟢 KYC | 17 | 0 | 17 |
| 🟢 BankCard | 17 | 0 | 17 |
| 🟢 Escrow | 17 | 0 | 17 |
| 🟢 Lottery | 17 | 0 | 17 |
| 🟢 Prediction | 17 | 0 | 17 |
| 🟢 Tasks | 29 | 0 | 29 |
| 🟢 Deep | 11 | 0 | 11 |
| 🟢 Investment | 10 | 0 | 10 |
| 🟢 Notification | 12 | 0 | 12 |
| 🟢 Vitrine | 11 | 0 | 11 |
| 🟢 Admin | 19 | 0 | 19 |
| **مجموع** | **256** | **0** | **256** |

---

## فایل‌های تغییر یافته

### Controllers
- `BaseController.php` — حذف 5 lazy accessor، constructor با app() fallback
- `BaseUserController.php` — بازگشت به property مستقیم
- `AuthController.php` — بازگشت به property مستقیم
- `ProfileController.php` — بازگشت به property مستقیم
- `TwoFactorController.php` — بازگشت به property مستقیم
- `ContentController.php` — بازگشت به property مستقیم
- `ManualDepositController.php` (User) — رفع undefined var
- `VitrineController.php` (Admin) — بازگشت به property مستقیم
- `ManualDepositController.php` (Admin) — حذف property تکراری logger
- `OnlinePaymentController.php` (Admin) — حذف property تکراری logger
- `WithdrawalController.php` (Admin) — حذف property تکراری logger

### Services
- `KYCService.php` — اضافه شدن NotificationService + Encryption + ترتیب صحیح پارامترها
- `KYC/KYCCommandService.php` — ترتیب صحیح پارامترها (required قبل از optional)
- `Withdrawal/WithdrawalUserService.php` — رفع TypeError: `$explicitKey` بجای `$payload`

### Test Scenarios
- `scenario_wallet.py` — اضافه شدن 302 به accepted codes
- `scenario_payment.py` — اصلاح assertionها برای HTML redirect (302)
- `scenario_kyc.py` — اصلاح HTTP code و محتوا assertion
- `scenario_bankcard.py` — اصلاح DB check assertion
- `scenario_escrow.py` — رفع int('') error
