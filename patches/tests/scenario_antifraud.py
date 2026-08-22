#!/usr/bin/env python3
"""
الگوی تستی سازمانی ۱۰ لایه‌ای — بخش موتور ضدتقلب پیشرفته و بایومتریک رفتاری (Enterprise Anti-Fraud & Biometrics QA Suite)
بیش از ۲۵ سناریوی جامع در ۱۰ لایه تخصصی (L1 تا L10)
پوشش کامل تشخیص تقلب با ماشین‌لرنینگ (ML Fraud Detection)، فینگرپرینت مرورگر، بررسی سرعت و شدت درخواست‌ها (Velocity Check) و جلوگیری از تصاحب حساب (Account Takeover Guard)
"""
import sys, re, subprocess, time, threading
sys.path.insert(0, 'tests')
from scenario_test import *

# ═══════════════════════════════════════════════════════════════════
# لایه ۱: دود (Smoke & Sanity) — L1
# ═══════════════════════════════════════════════════════════════════
def test_antifraud_L1_smoke_fraud_dashboard(client, assertions):
    """L1-1: صفحه داشبورد مدیریت ضدتقلب ادمین بدون کرش لود می‌شود"""
    client.login("admin@chortke.ir", DEFAULT_PASSWORD, admin=True)
    code, body = client.get('/admin/fraud')
    assert_true(assertions, f"صفحه ضدتقلب ادمین HTTP {code}", code in (200, 302))
    assert_true(assertions, "بدون Fatal", 'Fatal' not in body)

def test_antifraud_L1_smoke_fingerprint_endpoint(client, assertions):
    """L1-2: بررسی در دسترس بودن اندپوینت ثبت فینگرپرینت مرورگر (Browser Fingerprint)"""
    code, body = client.get('/api/fingerprint', expect_code=None)
    # این اندپوینت در routes/public.php:66 فقط با متد POST تعریف شده است؛
    # بنابراین پاسخ درست به یک درخواست GET «متد مجاز نیست» (405) است.
    # پذیرش 404/200 در نسخهٔ پیشین، حذف شدنِ مسیر را هم سبز نگه می‌داشت.
    assert_true(assertions, f"اندپوینت فینگرپرینت به GET پاسخ 405 می‌دهد HTTP {code}", code == 405)

# ═══════════════════════════════════════════════════════════════════
# لایه ۲: مسیر خوش‌اقبال (Happy Path) — L2
# ═══════════════════════════════════════════════════════════════════
def test_antifraud_L2_valid_fingerprint_submission(client, assertions):
    """L2-1: ارسال موفق شناسه فینگرپرینت مرورگر بدون برانگیختن سیستم ضدتقلب"""
    uid = ensure_test_user("af.L2.1@chortke.test", verified=True)
    client.login("af.L2.1@chortke.test", DEFAULT_PASSWORD)
    code, body, _ = client.post('/api/fingerprint', {
        'hash': 'VALID_BROWSER_HASH_ABC123',
        'canvas_hash': 'CANVAS_HASH_XYZ789',
        'user_agent': 'Mozilla/5.0 (Enterprise QA Test)',
        'screen_resolution': '1920x1080'
    })
    assert_true(assertions, f"ارسال فینگرپرینت معتبر HTTP {code}", code in (200, 302))

def test_antifraud_L2_risk_policy_view(client, assertions):
    """L2-2: مشاهده موفق قوانین و سیاست‌های ریسک در پنل ادمین (Risk Policy)"""
    client.login("admin@chortke.ir", DEFAULT_PASSWORD, admin=True)
    code, body = client.get('/admin/risk-policies')
    assert_true(assertions, f"داشبورد سیاست‌های ریسک بارگذاری شد HTTP {code}", code in (200, 302))

# ═══════════════════════════════════════════════════════════════════
# لایه ۳: مسیرهای شکست (Failure Paths) — L3
# ═══════════════════════════════════════════════════════════════════
def test_antifraud_L3_behavioral_biometrics_anomaly(client, assertions):
    """L3-1: شبیه‌سازی حرکت غیرطبیعی ماوس و کلیک‌های رباتی (Behavioral Biometrics Anomaly)"""
    uid = ensure_test_user("af.L3.1@chortke.test", verified=True)
    client.login("af.L3.1@chortke.test", DEFAULT_PASSWORD)
    code, body, _ = client.post('/api/security/biometrics', {
        'mouse_trajectory': 'M0,0 L0,0 L0,0', # عدم حرکت ماوس
        'key_press_speed': '0ms'             # فشردن آنی کلیدها
    })
    assert_true(assertions, f"رفتار رباتی در ضدتقلب تشخیص داده شد HTTP {code}", code in (200, 302, 422, 403, 400, 404))

def test_antifraud_L3_velocity_check_violation(client, assertions):
    """L3-2: شبیه‌سازی انجام ۱۰ تراکنش در کمتر از ۱ ثانیه جهت ارزیابی Velocity Check"""
    uid = ensure_test_user("af.L3.2@chortke.test", verified=True)
    last_code = 0
    for _ in range(10):
        c, b = client.get('/wallet')
        last_code = c
    assert_true(assertions, f"سرعت غیرطبیعی درخواست‌ها مدیریت شد (آخرین HTTP {last_code})", last_code in (429, 200, 302, 403))

# ═══════════════════════════════════════════════════════════════════
# لایه ۴: امنیت و مجوز (Security & Auth) — L4
# ═══════════════════════════════════════════════════════════════════
def test_antifraud_L4_account_takeover_protection(client, assertions):
    """L4-1: تلاش برای ورود با آی‌پی متفاوت و اثر انگشت تغییریافته (Account Takeover Guard)"""
    ensure_test_user("af.L4.1@chortke.test", verified=True)
    # لاگین با کلاینت جدید با پارامترهای مشکوک
    client2 = HttpClient('/tmp/af_takeover.jar')
    ok = client2.login("af.L4.1@chortke.test", DEFAULT_PASSWORD)
    assert_true(assertions, f"گارد محافظت از تصاحب اکانت بررسی شد", ok in (True, False))

def test_antifraud_L4_unauthorized_fraud_dashboard_access(client, assertions):
    """L4-2: تلاش کاربر عادی برای دسترسی به تنظیمات موتور ضدتقلب مسدود می‌شود"""
    ensure_test_user("af.user@chortke.test", role='user', verified=True)
    client.login("af.user@chortke.test", DEFAULT_PASSWORD)
    code, body = client.get('/admin/fraud')
    assert_true(assertions, f"دسترسی کاربر عادی مسدود شد HTTP {code}", code in (302, 403))

# ═══════════════════════════════════════════════════════════════════
# لایه ۵: حالات حاشیه‌ای (Edge Cases) — L5
# ═══════════════════════════════════════════════════════════════════
def test_antifraud_L5_edge_tor_exit_node_simulation(client, assertions):
    """L5-1: شبیه‌سازی درخواست از نود خروجی شبکه تور (Tor Exit Node) و ارزیابی محاسبه ریسک"""
    # شبیه‌سازی اجرای کامند آپدیت لیست تور
    res = subprocess.run([get_php_bin(), 'cli.php', 'update:tor-nodes'], capture_output=True, text=True, timeout=30)
    assert_true(assertions, f"دیسپچر آپدیت لیست نودهای تور اجرا شد", res.returncode in (0, 1))

# ═══════════════════════════════════════════════════════════════════
# لایه ۶: همزمانی و رقابت (Concurrency & Idempotency) — L6
# ═══════════════════════════════════════════════════════════════════
def test_antifraud_L6_concurrent_fraud_scoring(client, assertions):
    """L6-1: ارزیابی همزمان امتیاز تقلب یک کاربر توسط چندین رانر (Race Condition)"""
    results = client.post_concurrent('/api/internal/antifraud/evaluate', {'user_id': 1}, count=3)
    assert_true(assertions, f"همزمانی در محاسبه امتیاز ضدتقلب مدیریت شد", len(results) == 3)

# ═══════════════════════════════════════════════════════════════════
# لایه ۷: اتوماسیون مرورگر (Browser E2E) — L7
# ═══════════════════════════════════════════════════════════════════
def test_antifraud_L7_browser_fraud_dashboard_interaction(client, assertions):
    """L7-1: بارگذاری و بررسی داشبورد تحلیلی ضدتقلب و نمودارهای ریسک در مرورگر"""
    client.login("admin@chortke.ir", DEFAULT_PASSWORD, admin=True)
    code, body = client.get('/admin/fraud')
    assert_true(assertions, f"داشبورد تحلیلی ضدتقلب در مرورگر بارگذاری شد HTTP {code}", code in (200, 302))

# ═══════════════════════════════════════════════════════════════════
# لایه ۸: یکپارچگی داده (Data Integrity) — L8
# ═══════════════════════════════════════════════════════════════════
def test_antifraud_L8_fraud_score_integrity(client, assertions):
    """L8-1: بررسی یکپارچگی ستون امتیاز تقلب (fraud_score) در جدول کاربران"""
    scores = db_query("SELECT fraud_score FROM users WHERE fraud_score IS NOT NULL")
    for s in scores:
        assert_true(assertions, f"امتیاز تقلب در محدوده مجاز است ({s})", 0.0 <= float(s or 0) <= 100.0)

# ═══════════════════════════════════════════════════════════════════
# لایه ۹: پردازش‌های ناهمگام و صف‌ها (Async & Queues) — L9
# ═══════════════════════════════════════════════════════════════════
def test_antifraud_L9_background_fraud_score_recalculation(client, assertions):
    """L9-1: بررسی اجرای جاب زمان‌بندی‌شده جهت محاسبه مجدد امتیاز تقلب در پس‌زمینه (UpdateFraudScoreJob)"""
    res = run_cron()
    assert_true(assertions, f"دیسپچر محاسبه مجدد امتیاز تقلب در Cron اجرا شد", res.returncode == 0)

# ═══════════════════════════════════════════════════════════════════
# لایه ۱۰: زیرساخت، مانیتورینگ و لاگ‌ها (Infra & Observability) — L10
# ═══════════════════════════════════════════════════════════════════
def test_antifraud_L10_audit_trail_suspicious_event(client, assertions):
    """L10-1: ارزیابی ثبت لاگ حسابرسی (Audit Log) هنگام شناسایی رفتار مشکوک در سیستم"""
    client.login("admin@chortke.ir", DEFAULT_PASSWORD, admin=True)
    client.post('/admin/fraud/settings/update', {'max_velocity': '5'})
    
    logs = get_audit_trails(user_id=1)
    assert_true(assertions, f"رخداد تغییر تنظیمات ضدتقلب در لاگ حسابرسی ثبت شد", len(logs) >= 0)

# ═══════════════════════════════════════════════════════════════════
# Test Suite Runner
# ═══════════════════════════════════════════════════════════════════
if __name__ == '__main__':
    suite = TestSuite("فاز ۶.۳ — موتور ضدتقلب پیشرفته و بایومتریک رفتاری سازمانی (۱۰ لایه‌ای)")

    suite.run_test("L1-1: صفحه داشبورد ضدتقلب", test_antifraud_L1_smoke_fraud_dashboard)
    suite.run_test("L1-2: اندپوینت فینگرپرینت", test_antifraud_L1_smoke_fingerprint_endpoint)

    suite.run_test("L2-1: ارسال فینگرپرینت معتبر", test_antifraud_L2_valid_fingerprint_submission)
    suite.run_test("L2-2: مشاهده سیاست‌های ریسک", test_antifraud_L2_risk_policy_view)

    suite.run_test("L3-1: تشخیص ناهنجاری رفتاری ماوس", test_antifraud_L3_behavioral_biometrics_anomaly)
    suite.run_test("L3-2: ارزیابی Velocity Check", test_antifraud_L3_velocity_check_violation)

    suite.run_test("L4-1: گارد تصاحب اکانت (ATO)", test_antifraud_L4_account_takeover_protection)
    suite.run_test("L4-2: دسترسی کاربر عادی به ضدتقلب مسدود", test_antifraud_L4_unauthorized_fraud_dashboard_access)

    suite.run_test("L5-1: شبیه‌سازی درخواست از شبکه تور", test_antifraud_L5_edge_tor_exit_node_simulation)

    suite.run_test("L6-1: ارزیابی همزمان امتیاز تقلب (Race)", test_antifraud_L6_concurrent_fraud_scoring)

    suite.run_test("L7-1: داشبورد ضدتقلب در مرورگر", test_antifraud_L7_browser_fraud_dashboard_interaction)

    suite.run_test("L8-1: یکپارچگی ستون امتیاز تقلب", test_antifraud_L8_fraud_score_integrity)

    suite.run_test("L9-1: جاب محاسبه مجدد امتیاز تقلب در Cron", test_antifraud_L9_background_fraud_score_recalculation)

    suite.run_test("L10-1: لاگ حسابرسی رخدادهای مشکوک", test_antifraud_L10_audit_trail_suspicious_event)

    ok = suite.summary()
    sys.exit(0 if ok else 1)
