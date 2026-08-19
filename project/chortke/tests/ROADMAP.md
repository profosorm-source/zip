# نقشه راه تست سناریوهای حالت‌دار — پروژه چرتکه
**روش:** ترکیبی Python (API/DB) + Playwright (بصری/AJAX)
**زیرساخت:** DB snapshot/restore، کاربر تستی قابل‌ساخت، assertion روی HTTP + DB

---

## فازها (به ترتیب بحرانیت)

### فاز ۱: احراز هویت + کیف پول (Critical) — `scenario_auth.py` + `scenario_wallet.py`

#### ۱.۱ احراز هویت
| # | سناریو | مراحل | Assert |
|---|---|---|---|
| A1 | ثبت‌نام موفق | GET /register → POST با پسورد قوی + CAPTCHA | redirect به /email/verify-code + کاربر در DB |
| A2 | ثبت‌نام با پسورد ضعیف | POST با پسورد کوتاه | 422 + پیام خطا |
| A3 | ثبت‌نام با ایمیل تکراری | POST با ایمیل موجود | 422 |
| A4 | ورود موفق | POST /login با کاربر تأییدشده | redirect به /dashboard |
| A5 | ورود کاربر تأییدنشده | POST /login | redirect به /email/verify-code |
| A6 | ورود با پسورد غلط | POST /login | پیام خطا + نمایش CAPTCHA |
| A7 | logout | POST /logout با CSRF | redirect به /login + سشن پاک شده |
| A8 | guest guard | GET /dashboard بدون login | redirect به /login |
| A9 | auth guard | GET /login با login شده | redirect به /dashboard |
| A10 | تأیید ایمیل | POST /email/verify-code با کد صحیح | email_verified_at ست شده |

#### ۱.۲ کیف پول
| # | سناریو | مراحل | Assert |
|---|---|---|---|
| W1 | نمایش موجودی | کاربر با wallet → GET /wallet | موجودی صحیح نمایش داده شده |
| W2 | واریز دستی | POST /wallet/deposit/manual | درخواست در manual_deposits ثبت شد |
| W3 | برداشت موفق | کاربر با موجودی → POST /wallet/withdraw | رکورد در withdrawals + موجودی کسر شد |
| W4 | برداشت بیش از موجودی | کاربر با موجودی کم → POST | 422 + موجودی تغییر نکرد |
| W5 | انتقال P2P | POST /wallet/transfer | موجودی فرستنده کم، گیرنده زیاد شد |
| W6 | تاریخچه تراکنش | GET /wallet/history | تراکنش‌های قبلی نمایش داده شد |
| W7 | برداشت با حساب یخ‌شده | کاربر frozen → POST | 403 |

### فاز ۲: تسک‌ها — درآمدزایی اصلی — `scenario_tasks.py`

#### ۲.۱ تسک‌های اجتماعی
| # | سناریو | مراحل | Assert |
|---|---|---|---|
| T1 | لیست تسک‌های فعال | GET /tasks | حداقل یک تسک فعال |
| T2 | شروع تسک | POST /tasks/{id}/start | رکورد execution ایجاد شد |
| T3 | ارسال مدرک | POST /tasks/{id}/submit | submission در pending |
| T4 | تأیید خودکار (اگر configured) | بررسی | payout به wallet واریز شد |

#### ۲.۲ تسک‌های سفارشی (Custom Tasks)
| # | سناریو | مراحل | Assert |
|---|---|---|---|
| C1 | شروع custom task | POST /custom-tasks/start | execution ایجاد شد |
| C2 | ارسال مدرک | POST proof | submission pending |
| C3 | تأیید توسط آگهی‌دهنده | POST approve | payout واریز شد |
| C4 | رد توسط آگهی‌دهنده | POST reject + reason | submission rejected |

### فاز ۳: ویترین (بازار) — تراکنش‌های پیچیده — `scenario_vitrine.py`

| # | سناریو | مراحل | Assert |
|---|---|---|---|
| V1 | ساخت آگهی فروش | POST /vitrine/store | listing در pending |
| V2 | خرید آگهی | POST /vitrine/{id}/buy | escrow ایجاد + موجودی قفل شد |
| V3 | تأیید تحویل | POST /vitrine/{id}/confirm | پول به فروشنده واریز شد |
| V4 | باز کردن اختلاف | POST /vitrine/{id}/dispute | وضعیت disputed |
| V5 | ارسال درخواست قیمت | POST /vitrine/{id}/request | request در pending |

### فاز ۴: سرمایه‌گذاری — `scenario_investment.py`

| # | سناریو | مراحل | Assert |
|---|---|---|---|
| I1 | ایجاد سرمایه‌گذاری | POST /investment/store | رکورد investment ایجاد شد |
| I2 | برداشت سود | POST /investment/withdraw | تراکنش ثبت شد |

### فاز ۵: عملیات ادمین — `scenario_admin.py`

| # | سناریو | مراحل | Assert |
|---|---|---|---|
| AD1 | تأیید KYC | POST /admin/kyc/verify/{id} | kyc_status = approved |
| AD2 | رد KYC | POST /admin/kyc/reject/{id} | kyc_status = rejected |
| AD3 | تأیید برداشت | POST /admin/withdrawals/process | وضعیت = approved + تراکنش |
| AD4 | رد برداشت | POST /admin/withdrawals/reject | وضعیت = rejected |
| AD5 | مسدودکردن کاربر | POST /admin/users/{id}/ban | user status = banned |

### فاز ۶: امنیت و تقلب — `scenario_security.py`

| # | سناریو | مراحل | Assert |
|---|---|---|---|
| S1 | CSRF محافظت | POST بدون token | 403 |
| S2 | CSRF محافظت | POST با token غلط | 403 |
| S3 | rate limiting ورود | ۵ تلاش غلط | lockout |
| S4 | دسترسی غیرمجاز ادمین | کاربر عادی → GET /admin/users | redirect/403 |
| S5 | SQL injection در فرم | POST با payload SQLi | رد/escape شده |

### فاز ۷: اعلان‌ها و ارتباطات — `scenario_notification.py`

| # | سناریو | Assert |
|---|---|---|
| N1 | دریافت اعلان‌ها | GET /notifications |
| N2 | علامت‌گذاری خوانده‌شده | POST /notifications/mark-read |
| N3 | به‌روزرسانی ترجیحات | POST /notifications/preferences/update |

---

## اولویت اجرا
1. **فاز ۱** (احراز هویت + کیف پول) — بنیان سیستم
2. **فاز ۲** (تسک‌ها) — درآمدزایی اصلی
3. **فاز ۵** (ادمین) — عملیات بحرانی
4. **فاز ۳** (ویترین) — تراکنش پیچیده
5. **فاز ۶** (امنیت)
6. **فاز ۴ + ۷** (سرمایه‌گذاری + اعلان‌ها)
