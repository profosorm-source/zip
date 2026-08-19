# الگوی تستی استاندارد چرتکه — Test Matrix Pattern v2.0

**تاریخ به‌روزرسانی:** ۱۴۰۵/۰۴/۰۶  
**وضعیت:** فعال — باید برای هر بخش رعایت شود

---

## ۱. اصول

هر بخش از پروژه باید با **هر ۷ لایه‌ی زیر** تست شود. هیچ بخشی بدون پوشش همه‌ی لایه‌ها کامل محسوب نمی‌شود.

## ۲. لایه‌های تست (هر بخش = ۷ لایه)

### لایه ۱: دود (Smoke) — `curl`
| چیست | روش | حداقل |
|---|---|---|
| صفحه لود می‌شود؟ | `GET` → 200/302 | ۱ |
| کرش سرور نیست؟ | بدنه بدون `Fatal/Exception/SQLSTATE` | ۱ |
| محتوا وجود دارد؟ | بدنه > 100 کاراکتر | ۱ |
| **حداقل سناریو** | | **۳** |

### لایه ۲: مسیر خوش‌اقبال (Happy Path) — `curl + DB`
| چیست | روش | حداقل |
|---|---|---|
| عملیات موفق با داده صحیح | `POST` با فیلدهای کامل | ۱ |
| تغییر در DB رخ داد؟ | `SELECT` قبل/بعد از عملیات | ۱ |
| پاسخ صحیح است؟ | JSON `success:true` یا redirect به مقصد درست | — |
| **حداقل سناریو** | | **۲** |

### لایه ۳: مسیرهای شکست (Failure Paths) — `curl`
| چیست | روش | حداقل |
|---|---|---|
| داده ناقص | `POST` بدون فیلد الزامی → 422 | ۱ |
| داده نامعتبر | `POST` با مقدار غیرمجاز → 422 | ۱ |
| بیش از سقف | مبلغ/تعداد بیش از حد → 422 | ۱ |
| منبع ناموجود | ID = 99999 → 404/422 | ۱ |
| **حداقل سناریو** | | **۴** |

### لایه ۴: امنیت و مجوز (Security) — `curl`
| چیست | روش | حداقل |
|---|---|---|
| دسترسی غیرمجاز | کاربر عادی → مسیر ادمین → 403/302 | ۱ |
| CSRF محافظت | `POST` بدون token → رد | ۱ |
| تزریق (Injection) | Payload SQLi/XSS → رد/escape | ۱ |
| مالکیت منبع | کاربر A → منبع کاربر B → 403 | — |
| **حداقل سناریو** | | **۳** |

### لایه ۵: موارد لبه (Edge Cases) — `curl`
| چیست | روش | حداقل |
|---|---|---|
| مقدار صفر/منفی | amount=0, amount=-1 | ۱ |
| مقدار خالی | string خالی | — |
| مقدار بسیار بزرگ | amount=999999999999 | — |
| تکرار عملیات | دو بار submit → idempotency | ۱ |
| شرایط رقابتی (Race) | دو درخواست همزمان | ۱ |
| **حداقل سناریو** | | **۳** |

### لایه ۶: مرورگر (Browser) — `Playwright`
| چیست | روش | حداقل |
|---|---|---|
| خطای کنسول JS نیست؟ | `page.on('console')` | ۱ |
| خطای اجرای JS نیست؟ | `page.on('pageerror')` | — |
| asset failure نیست؟ | `page.on('requestfailed')` | — |
| DOM رندر شد؟ | `body.textContent().length > 100` | ۱ |
| فرم قابل fill است؟ | `locator('input[name]').fill()` | ۱ |
| دکمه کلیک می‌شود؟ | `locator('button').click()` | — |
| AJAX response درست است؟ | `page.waitForResponse()` | — |
| **حداقل سناریو** | | **۳** |

### لایه ۷: یکپارچگی داده (Data Integrity) — `DB`
| چیست | روش | حداقل |
|---|---|---|
| موجودی همخوان است؟ | `SUM(wallets) = SUM(transactions)` | ۱ |
| رکورد یتیم نیست؟ | FK معتبر | — |
| enum درست است؟ | `status` در مقادیر مجاز | ۱ |
| timestamp ست شد؟ | `created_at`, `updated_at` معتبر | — |
| **حداقل سناریو** | | **۲** |

---

## ۳. فرمول تست هر بخش

```
تست‌های هر بخش = لایه ۱ + لایه ۲ + لایه ۳ + لایه ۴ + لایه ۵ + لایه ۶ + لایه ۷
حداقل سناریوها = ۳(Smoke) + ۲(Happy) + ۴(Failure) + ۳(Security) + ۳(Edge) + ۳(Browser) + ۲(Data) = ۲۰
```

## ۴. ساختار فایل

```
tests/
├── scenario_test.py              # زیرساخت مشترک (HttpClient, DB helpers, TestSuite)
├── scenario_{module}.py          # لایه‌های ۱-۵ و ۷ (Python + curl + DB)
├── browser_{module}.js           # لایه ۶ (Playwright)
├── run_all.py                    # اجرای یکپارچه
├── TEST_MATRIX.md                # این فایل — تعریف الگو
└── ROADMAP.md                    # نقشه راه فازها
```

### قرارداد نام‌گذاری توابع تست

```python
def test_{module}_{layer}_{description}(client, assertions):
    # مثال:
    # test_wallet_L1_page_loads       → لایه ۱ دود
    # test_wallet_L2_deposit_success  → لایه ۲ خوش‌اقبال
    # test_wallet_L3_missing_amount   → لایه ۳ شکست
    # test_wallet_L4_csrf_missing     → لایه ۴ امنیت
    # test_wallet_L5_zero_amount      → لایه ۵ لبه
    # test_wallet_L7_balance_match    → لایه ۷ یکپارچگی
```

### قرارداد نام‌گذاری Browser test

```javascript
// browser_{module}.js
async function test_L6_{module}_{description}(page) { ... }
// مثال:
// test_L6_wallet_form_fill(page)
// test_L6_wallet_no_js_errors(page)
```

## ۵. اجرا

- **لایه ۱ تا ۵ و ۷**: Python `scenario_{module}.py` با DB snapshot/restore
- **لایه ۶**: Playwright `browser_{module}.js` با Chromium

```bash
# اجرای یک ماژول
python3 tests/scenario_wallet.py
node tests/browser_wallet.js

# اجرای همه
python3 tests/run_all.py all
```

## ۶. گزارش

هر بخش گزارش می‌دهد:

```
بخش X: ۲۰/۲۰ PASS
  لایه ۱ دود:        ۳/۳ ✓
  لایه ۲ خوش‌اقبال:   ۲/۲ ✓
  لایه ۳ شکست:       ۴/۴ ✓
  لایه ۴ امنیت:      ۳/۳ ✓
  لایه ۵ لبه:        ۳/۳ ✓
  لایه ۶ مرورگر:     ۳/۳ ✓
  لایه ۷ یکپارچگی:    ۲/۲ ✓
```

---

## ۷. Gap Analysis — وضعیت فعلی (۱۴۰۵/۰۴/۰۶)

### بخش‌هایی که سناریو دارند

| بخش | فایل | L1 | L2 | L3 | L4 | L5 | L6 | L7 | مجموع | حداقل ۲۰ |
|------|------|----|----|----|----|----|----|----|-------|-----------|
| احراز هویت | scenario_auth.py | ۶ | ۷ | ۵ | ۳ | ۰ | ۰ | ۰ | ۲۱ | ❌ L5,L6,L7 خالی |
| کیف پول | scenario_wallet.py | ۰ | ۷ | ۲ | ۰ | ۳ | ۰ | ۰ | ۱۲ | ❌ L1,L4,L6,L7 خالی |
| تسک‌ها | scenario_tasks.py | ۵ | ۱۱ | ۷ | ۰ | ۳ | ۰ | ۰ | ۲۶ | ❌ L4,L6,L7 خالی |
| ادمین | scenario_admin.py | ۳ | ۲ | ۴ | ۳ | ۳ | ۰ | ۲ | ۱۷ | ❌ L6 خالی |
| ویترین | scenario_vitrine.py | ۱ | ۱ | ۴ | ۲ | ۲ | ۰ | ۱ | ۱۱ | ❌ L6 خالی |
| سرمایه‌گذاری | scenario_investment.py | ۱ | ۱ | ۲ | ۲ | ۲ | ۰ | ۱ | ۱۰ | ❌ L6 خالی |
| اعلان‌ها | scenario_notification.py | ۲ | ۲ | ۲ | ۲ | ۱ | ۰ | ۱ | ۱۰ | ❌ L6 خالی |
| عمیق | scenario_deep.py | ۰ | ۰ | ۰ | ۳ | ۵ | ۰ | ۱ | ۹ | تکمیلی |

### بخش‌هایی که **اصلاً سناریو ندارند**

| بخش | اهمیت | فایل‌های کنترلر | سناریو لازم؟ |
|------|--------|-----------------|-------------|
| 💰 پرداخت (Payment) | حیاتی | PaymentController, AdminOnlinePaymentController | ✅ فوری |
| 💰 رمزارز (Crypto) | حیاتی | CryptoDepositController, AdminCryptoDepositController | ✅ فوری |
| 🏦 کارت بانکی (BankCard) | بالا | BankCardController, AdminBankCardController | ✅ فوری |
| 🔒 KYC | بالا | KYCController, AdminKYCController | ✅ مهم |
| ⚖️ اختلاف (Dispute) | بالا | DisputeController | ✅ مهم |
| 🤝 اسکرو (Escrow) | بالا | EscrowController | ✅ مهم |
| 🎲 لاتاری (Lottery) | بالا | AdminLotteryController | ✅ مهم |
| 📊 پیش‌بینی (Prediction) | بالا | AdminPredictionController | ✅ مهم |
| 👤 پروفایل (Profile) | متوسط | ProfileController | ✅ مطلوب |
| 🔗 معرفی (Referral) | متوسط | AdminReferralController | ✅ مطلوب |
| ⭐ امتیاز (Score) | متوسط | — | ✅ مطلوب |
| 🎫 کوپن (Coupon) | متوسط | AdminCouponController | ✅ مطلوب |
| 📊 تحلیل (Analytics) | متوسط | AdminAnalyticsController | ✅ مطلوب |
| 🔔 سانتری (Sentry) | متوسط | AdminSystemController | 🟡 بعداً |
| 🔍 سئو (SEO) | پایین | SeoController, AdminSeoAdController | 🟡 بعداً |

### لایه ۶ — مرورگر (بزرگترین گپ)

| بخش | فایل browser_ موجود؟ |
|------|---------------------|
| عمومی | browser_test.js (عمومی) |
| عمیق | browser_deep_test.js (عمومی) |
| کیف پول | ❌ |
| تسک‌ها | ❌ |
| ادمین | ❌ |
| ویترین | ❌ |
| سرمایه‌گذاری | ❌ |
| اعلان‌ها | ❌ |
| پرداخت | ❌ |
| KYC | ❌ |

---

## ۸. اولویت‌بندی اصلاح

### 🔴 فوری — بخش‌های حیاتی مالی + لایه‌های خالی

1. **پرداخت (Payment)** → `scenario_payment.py` (۲۰+ سناریو)
2. **رمزارز (Crypto)** → `scenario_crypto.py` (۲۰+ سناریو)
3. **کارت بانکی** → تکمیل wallet با L1,L4,L7
4. **wallet L1,L4,L7** → اضافه کردن لایه‌های خالی
5. **auth L5,L7** → اضافه کردن لبه و یکپارچگی
6. **tasks L4** → اضافه کردن امنیت

### 🟡 مهم — بخش‌های با اهمیت بالا

7. **KYC** → `scenario_kyc.py`
8. **Dispute** → `scenario_dispute.py`
9. **Escrow** → `scenario_escrow.py`
10. **Lottery** → `scenario_lottery.py`
11. **Browser tests** → `browser_wallet.js`, `browser_tasks.js`, ...

### 🟢 مطلوب — پوشش کامل

12. Profile, Referral, Score, Coupon, Analytics
13. تبدیل تمام سناریوها به فرمت L{1-7} نام‌گذاری استاندارد
14. سناریوهای رقابتی (Race) برای همه عملیات مالی
