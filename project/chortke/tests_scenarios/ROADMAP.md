# نقشه راه تست سناریوهای ۷ لایه‌ای — پروژه چرتکه
**روش:** الگوی Test Matrix Pattern — هر بخش = ۷ لایه = حداقل ۲۰ سناریو
**زیرساخت:** Python `scenario_*.py` (L1-L5,L7) + Playwright `browser_*.js` (L6) + DB snapshot/restore

---

## وضعیت فعلی (۱۴۰۵/۰۴/۰۶)

| بخش | فایل | L1 | L2 | L3 | L4 | L5 | L6 | L7 | مجموع | وضعیت |
|------|------|----|----|----|----|----|----|----|-------|-------|
| احراز هویت | scenario_auth.py | ۶ | ۷ | ۵ | ۳ | ۰ | ۰ | ۰ | ۲۱ | ❌ L5,L6,L7 خالی |
| کیف پول | scenario_wallet.py | ۳ | ۵ | ۴ | ۳ | ۲ | ۰ | ۲ | ۱۹ | ⚠️ L6 خالی |
| تسک‌ها | scenario_tasks.py | ۵ | ۱۱ | ۷ | ۰ | ۳ | ۰ | ۰ | ۲۶ | ❌ L4,L6,L7 خالی |
| ادمین | scenario_admin.py | ۳ | ۲ | ۴ | ۳ | ۳ | ۰ | ۲ | ۱۷ | ❌ L6 خالی |
| ویترین | scenario_vitrine.py | ۱ | ۱ | ۴ | ۲ | ۲ | ۰ | ۱ | ۱۱ | ❌ L6 خالی |
| سرمایه‌گذاری | scenario_investment.py | ۱ | ۱ | ۲ | ۲ | ۲ | ۰ | ۱ | ۱۰ | ❌ L6 خالی |
| اعلان‌ها | scenario_notification.py | ۲ | ۲ | ۲ | ۲ | ۱ | ۰ | ۱ | ۱۰ | ❌ L6 خالی |
| عمیق | scenario_deep.py | ۰ | ۰ | ۰ | ۳ | ۵ | ۰ | ۱ | ۹ | تکمیلی |
| **پرداخت** 🆕 | scenario_payment.py | ۳ | ۲ | ۴ | ۳ | ۳ | ۰ | ۲ | **۱۷** | ⚠️ L6 خالی |
| **KYC** 🆕 | scenario_kyc.py | ۳ | ۲ | ۴ | ۳ | ۳ | ۰ | ۲ | **۱۷** | ⚠️ L6 خالی |
| **اختلافات** 🆕 | scenario_dispute.py | ۳ | ۲ | ۴ | ۳ | ۳ | ۰ | ۲ | **۱۷** | ⚠️ L6 خالی |
| **کارت بانکی** 🆕 | scenario_bankcard.py | ۳ | ۲ | ۴ | ۳ | ۳ | ۰ | ۲ | **۱۷** | ⚠️ L6 خالی |
| **اسکرو** 🆕 | scenario_escrow.py | ۳ | ۲ | ۴ | ۳ | ۳ | ۰ | ۲ | **۱۷** | ⚠️ L6 خالی |
| **لاتاری** 🆕 | scenario_lottery.py | ۳ | ۲ | ۴ | ۳ | ۳ | ۰ | ۲ | **۱۷** | ⚠️ L6 خالی |
| **پیش‌بینی** 🆕 | scenario_prediction.py | ۳ | ۲ | ۴ | ۳ | ۳ | ۰ | ۲ | **۱۷** | ⚠️ L6 خالی |

---

## فازهای بعدی (به ترتیب بحرانیت)

### فاز ۱: تکمیل لایه‌های خالی ماژول‌های موجود ✅ (انجام‌شده)
- [x] wallet: اضافه‌شدن L1 (3), L4 (3), L7 (2)
- [x] تعریف الگوی ۷ لایه‌ای استاندارد (TEST_MATRIX.md v2.0)

### فاز ۲: بخش‌های حیاتی مالی جدید ✅ (انجام‌شده)
- [x] پرداخت → scenario_payment.py (17 سناریو)
- [x] KYC → scenario_kyc.py (17 سناریو)
- [x] اختلافات → scenario_dispute.py (17 سناریو)

### فاز ۳: بخش‌های با اهمیت بالا ✅ (انجام‌شده)
- [x] کارت بانکی → scenario_bankcard.py (17 سناریو)
- [x] اسکرو → scenario_escrow.py (17 سناریو)
- [x] لاتاری → scenario_lottery.py (17 سناریو)
- [x] پیش‌بینی → scenario_prediction.py (17 سناریو)
- [ ] رمزارز → scenario_crypto.py (بعدی)

### فاز ۴: تکمیل لایه‌های خالی ماژول‌های قدیمی
- [ ] auth: اضافه‌کردن L5 (Edge Cases) + L7 (Data Integrity)
- [ ] tasks: اضافه‌کردن L4 (Security) + L7 (Data Integrity)
- [ ] investment: تکمیل به ۲۰ سناریو
- [ ] vitrine: تکمیل به ۲۰ سناریو
- [ ] notification: تکمیل به ۲۰ سناریو

### فاز ۵: لایه ۶ — تست‌های مرورگری
- [ ] browser_wallet.js
- [ ] browser_tasks.js
- [ ] browser_admin.js
- [ ] browser_kyc.js
- [ ] browser_vitrine.js
- [ ] browser_payment.js

### فاز ۶: بخش‌های مطلوب
- [ ] پروفایل → scenario_profile.py
- [ ] معرفی → scenario_referral.py
- [ ] امتیاز/سطح → scenario_score.py
- [ ] کوپن → scenario_coupon.py
- [ ] تحلیل → scenario_analytics.py
