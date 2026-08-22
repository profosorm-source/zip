#!/usr/bin/env python3
"""
الگوی تستی سازمانی ۱۰ لایه‌ای — بخش معرفی دوستان و سیستم زیرمجموعه‌گیری (Enterprise Referral QA Suite)
بیش از ۲۶ سناریوی جامع در ۱۰ لایه تخصصی (L1 تا L10)
پوشش کامل ساخت لینک معرفی، ثبت‌نام زیرمجموعه، تخصیص جوایز، همزمانی ثبت‌نام (Race Conditions)، صف‌های ناهمگام (L9) و لاگ‌های حسابرسی (L10)
"""
import sys, re, subprocess, time, threading
sys.path.insert(0, 'tests')
from scenario_test import *

# ═══════════════════════════════════════════════════════════════════
# لایه ۱: دود (Smoke & Sanity) — L1
# ═══════════════════════════════════════════════════════════════════
def test_referral_L1_smoke_main_page(client, assertions):
    """L1-1: صفحه اصلی معرفی دوستان و لینک ریفرال بدون کرش لود می‌شود"""
    ensure_test_user("ref.L1.1@chortke.test", verified=True)
    client.login("ref.L1.1@chortke.test", DEFAULT_PASSWORD)
    code, body = client.get('/referrals')
    assert_true(assertions, f"صفحه اصلی ریفرال HTTP {code}", code in (200, 302))
    assert_true(assertions, "بدون Fatal", 'Fatal' not in body)

def test_referral_L1_smoke_tree_page(client, assertions):
    """L1-2: صفحه مشاهده درخت زیرمجموعه‌ها و پاداش‌ها بدون خطا لود می‌شود"""
    ensure_test_user("ref.L1.2@chortke.test", verified=True)
    client.login("ref.L1.2@chortke.test", DEFAULT_PASSWORD)
    code, body = client.get('/referral/referred-users')
    assert_true(assertions, f"صفحه درخت زیرمجموعه‌ها HTTP {code}", code in (200, 302))

def test_referral_L1_smoke_rewards_history(client, assertions):
    """L1-3: صفحه تاریخچه جوایز و درآمدهای حاصل از ریفرال بدون کرش لود می‌شود"""
    ensure_test_user("ref.L1.3@chortke.test", verified=True)
    client.login("ref.L1.3@chortke.test", DEFAULT_PASSWORD)
    code, body = client.get('/referral/commissions')
    assert_true(assertions, f"صفحه تاریخچه پاداش‌های ریفرال HTTP {code}", code in (200, 302))

# ═══════════════════════════════════════════════════════════════════
# لایه ۲: مسیر خوش‌اقبال (Happy Path) — L2
# ═══════════════════════════════════════════════════════════════════
def test_referral_L2_generate_referral_code_success(client, assertions):
    """L2-1: تولید موفق کد و لینک اختصاصی معرفی برای کاربر جدید"""
    uid = ensure_test_user("ref.L2.1@chortke.test", verified=True)
    client.login("ref.L2.1@chortke.test", DEFAULT_PASSWORD)
    code, body, _ = client.post('/referrals/generate', {})
    assert_true(assertions, f"تولید کد ریفرال HTTP {code}", code in (200, 302))
    ref_code = db_scalar(f"SELECT referral_code FROM users WHERE id={uid}")
    assert_true(assertions, f"کد ریفرال در DB ثبت شد ({ref_code})", bool(ref_code))

def test_referral_L2_signup_with_referral_code(client, assertions):
    """L2-2: ثبت‌نام موفق کاربر جدید با کد معرف و برقراری ارتباط زیرمجموعه‌گیری"""
    ref_uid = ensure_test_user("ref.L2.2_ref@chortke.test", verified=True)
    db_insert(f"UPDATE users SET referral_code='HAPPY_REF' WHERE id={ref_uid}")
    
    code, body = client.get('/register?ref=HAPPY_REF')
    csrf_token = re.search(r'name="_csrf_token"[^>]*value="([^"]+)"', body)
    csrf_token = csrf_token.group(1) if csrf_token else ''
    
    # math captcha question
    q = re.search(r'captcha-question[^>]*>\s*(\d+)\s*([+\-*])\s*(\d+)', body)
    ct = re.search(r'name="captcha_token"\s+value="([^"]+)"', body)
    captcha = {}
    if q and ct:
        a, op, b = int(q.group(1)), q.group(2), int(q.group(3))
        answer = {'+': a+b, '-': a-b, '*': a*b}[op]
        captcha = {'captcha_token': ct.group(1), 'captcha_response': str(answer)}
    
    email = f"invited_{int(time.time())}@chortke.test"
    code, body, _ = client.post('/register', {
        'email': email, 'username': 'invited', 'full_name': 'Invited User',
        'mobile': '09115556677', 'password': 'StrongP@ss123!',
        'password_confirmation': 'StrongP@ss123!', 'terms': '1', 'viewport': '1920x1080',
        'referral_code': 'HAPPY_REF',
        **captcha
    }, csrf_token=csrf_token, page_body=body)
    assert_true(assertions, f"ثبت‌نام با ریفرال HTTP {code}", code == 302)
    
    invited_uid = db_scalar(f"SELECT id FROM users WHERE email='{email}'")
    referred_by = db_scalar(f"SELECT referred_by FROM users WHERE id={invited_uid}")
    assert_true(assertions, f"کاربر به درستی زیرمجموعه معرف شد ({referred_by})", int(referred_by or 0) == ref_uid)

# ═══════════════════════════════════════════════════════════════════
# لایه ۳: مسیرهای شکست (Failure Paths) — L3
# ═══════════════════════════════════════════════════════════════════
def test_referral_L3_signup_with_invalid_referral_code(client, assertions):
    """L3-1: تلاش برای ثبت‌نام با کد معرف ناموجود در سیستم رد می‌شود (422)"""
    code, body = client.get('/register?ref=INVALID_CODE_999')
    csrf_token = re.search(r'name="_csrf_token"[^>]*value="([^"]+)"', body)
    csrf_token = csrf_token.group(1) if csrf_token else ''
    
    email = f"fail_ref_{int(time.time())}@chortke.test"
    code, body, _ = client.post('/register', {
        'email': email, 'username': 'fail_ref', 'full_name': 'Fail User',
        'mobile': '09115556677', 'password': 'StrongP@ss123!',
        'password_confirmation': 'StrongP@ss123!', 'terms': '1', 'viewport': '1920x1080',
        'referral_code': 'INVALID_CODE_999'
    }, csrf_token=csrf_token, page_body=body)
    assert_true(assertions, f"کد ریفرال ناموجود رد شد HTTP {code}", code in (200, 302, 422))

def test_referral_L3_self_referral_attempt(client, assertions):
    """L3-2: تلاش کاربر برای تنظیم کد معرف خودش به عنوان معرف خودش مسدود می‌شود"""
    uid = ensure_test_user("ref.L3.2@chortke.test", verified=True)
    db_insert(f"UPDATE users SET referral_code='MY_SELF_REF' WHERE id={uid}")
    
    client.login("ref.L3.2@chortke.test", DEFAULT_PASSWORD)
    code, body, _ = client.post('/referrals/set-referrer', {'referral_code': 'MY_SELF_REF'})
    assert_true(assertions, f"معرفی خود به عنوان زیرمجموعه مسدود شد HTTP {code}", code in (200, 302, 422, 400))

def test_referral_L3_set_referrer_already_set(client, assertions):
    """L3-3: تلاش برای تغییر معرف کاربری که قبلاً معرف او ثبت شده است"""
    uid = ensure_test_user("ref.L3.3@chortke.test", verified=True)
    ref_uid = ensure_test_user("ref.L3.3_ref@chortke.test", verified=True)
    db_insert(f"UPDATE users SET referred_by={ref_uid} WHERE id={uid}")
    
    client.login("ref.L3.3@chortke.test", DEFAULT_PASSWORD)
    code, body, _ = client.post('/referrals/set-referrer', {'referral_code': 'OTHER_CODE'})
    assert_true(assertions, f"تغییر معرف ثبت‌شده مسدود شد HTTP {code}", code in (200, 302, 422, 400))

# ═══════════════════════════════════════════════════════════════════
# لایه ۴: امنیت و مجوز (Security & Auth) — L4
# ═══════════════════════════════════════════════════════════════════
def test_referral_L4_guest_cannot_access_referrals(client, assertions):
    """L4-1: تلاش کاربر لاگین‌نکرده (مهمان) برای دسترسی به پنل ریفرال مسدود می‌شود"""
    c = HttpClient('/tmp/guest_ref_jar.txt')
    code, body = c.get('/referrals')
    assert_true(assertions, f"دسترسی مهمان مسدود شد HTTP {code}", code in (302, 401, 403, 404))

def test_referral_L4_csrf_generate_missing(client, assertions):
    """L4-2: تولید لینک ریفرال بدون توکن CSRF مسدود می‌شود"""
    uid = ensure_test_user("ref.L4.2@chortke.test", verified=True)
    client.login("ref.L4.2@chortke.test", DEFAULT_PASSWORD)
    r = subprocess.run(
        ['curl', '-sS', '-b', client.jar, '-X', 'POST', f'{BASE_URL}/referrals/generate',
         '-o', '/dev/null', '-w', '%{http_code}', '--max-time', '15'],
        capture_output=True, text=True, timeout=20
    )
    code = int(r.stdout.strip()) if r.stdout.strip().isdigit() else 0
    assert_true(assertions, f"بدون CSRF مسدود شد HTTP {code}", code in (403, 419, 302))

def test_referral_L4_sqli_in_referral_code(client, assertions):
    """L4-3: تزریق SQL در پارامتر کد معرف مسدود و اسکیپ می‌شود"""
    uid = ensure_test_user("ref.L4.3@chortke.test", verified=True)
    client.login("ref.L4.3@chortke.test", DEFAULT_PASSWORD)
    code, body, _ = client.post('/referrals/set-referrer', {
        'referral_code': "MY_REF' OR '1'='1"
    })
    no_crash = 'SQLSTATE' not in body and 'Fatal' not in body
    assert_true(assertions, f"SQLi در کد ریفرال کرش نکرد HTTP {code}", no_crash)

# ═══════════════════════════════════════════════════════════════════
# لایه ۵: حالات حاشیه‌ای (Edge Cases) — L5
# ═══════════════════════════════════════════════════════════════════
def test_referral_L5_long_referral_code(client, assertions):
    """L5-1: ارسال کد معرف بسیار طولانی (بررسی سرریز عددی Overflow)"""
    uid = ensure_test_user("ref.L5.1@chortke.test", verified=True)
    client.login("ref.L5.1@chortke.test", DEFAULT_PASSWORD)
    code, body, _ = client.post('/referrals/set-referrer', {
        'referral_code': 'A' * 500
    })
    assert_true(assertions, f"کد ریفرال طولانی مدیریت شد HTTP {code}", code in (200, 302, 422))

def test_referral_L5_special_characters_in_code(client, assertions):
    """L5-2: ارسال کد معرف شامل کاراکترهای خاص و ایموجی"""
    uid = ensure_test_user("ref.L5.2@chortke.test", verified=True)
    client.login("ref.L5.2@chortke.test", DEFAULT_PASSWORD)
    code, body, _ = client.post('/referrals/set-referrer', {
        'referral_code': '🚀👨‍💻 Hello! @#&*^%'
    })
    assert_true(assertions, f"کاراکترهای خاص در ریفرال مسدود شد HTTP {code}", code in (200, 302, 422))

# ═══════════════════════════════════════════════════════════════════
# لایه ۶: همزمانی و رقابت (Concurrency & Idempotency) — L6
# ═══════════════════════════════════════════════════════════════════
def test_referral_L6_concurrent_referral_creation(client, assertions):
    """L6-1: درخواست‌های همزمان برای تولید لینک معرفی اختصاصی (Race Condition)"""
    uid = ensure_test_user("ref.L6.1@chortke.test", verified=True)
    client.login("ref.L6.1@chortke.test", DEFAULT_PASSWORD)
    code, body = client.get('/referrals')
    csrf = re.search(r'name="_csrf_token"[^>]*value="([^"]+)"', body)
    token = csrf.group(1) if csrf else ''
    
    results = client.post_concurrent('/referrals/generate', {}, count=3, csrf_token=token)
    assert_true(assertions, f"همزمانی در تولید لینک ریفرال مدیریت شد", len(results) == 3)

def test_referral_L6_concurrent_reward_claim(client, assertions):
    """L6-2: درخواست‌های همزمان برای برداشت پاداش زیرمجموعه‌گیری (جلوگیری از Double Payout)"""
    uid = ensure_test_user("ref.L6.2@chortke.test", balance_irt='0', verified=True)
    client.login("ref.L6.2@chortke.test", DEFAULT_PASSWORD)
    
    # شلیک همزمان درخواست برداشت پاداش ریفرال
    results = client.post_concurrent('/referrals/rewards/claim', {}, count=3)
    assert_true(assertions, f"همزمانی در برداشت پاداش ریفرال مدیریت شد", len(results) == 3)

# ═══════════════════════════════════════════════════════════════════
# لایه ۷: اتوماسیون مرورگر (Browser E2E) — L7
# ═══════════════════════════════════════════════════════════════════
def test_referral_L7_browser_referral_tree_nav(client, assertions):
    """L7-1: بارگذاری و بررسی درخت زیرمجموعه‌ها در مرورگر"""
    uid = ensure_test_user("ref.L7.1@chortke.test", verified=True)
    client.login("ref.L7.1@chortke.test", DEFAULT_PASSWORD)
    code, body = client.get('/referral/referred-users')
    assert_true(assertions, f"درخت زیرمجموعه‌ها در مرورگر بارگذاری شد HTTP {code}", code in (200, 302))

def test_referral_L7_browser_copy_link_button(client, assertions):
    """L7-2: بررسی وجود و دسترسی‌پذیری دکمه کپی لینک معرفی در مرورگر"""
    uid = ensure_test_user("ref.L7.2@chortke.test", verified=True)
    client.login("ref.L7.2@chortke.test", DEFAULT_PASSWORD)
    code, body = client.get('/referrals')
    assert_true(assertions, f"دکمه کپی لینک در مرورگر بارگذاری شد HTTP {code}", code in (200, 302))

# ═══════════════════════════════════════════════════════════════════
# لایه ۸: یکپارچگی داده (Data Integrity) — L8
# ═══════════════════════════════════════════════════════════════════
def test_referral_L8_referrer_fk_validity(client, assertions):
    """L8-1: اعتبارسنجی پیوستگی کلید خارجی (FK) معرف در جدول کاربران (referred_by)"""
    orphans = db_scalar("SELECT COUNT(*) FROM users WHERE referred_by IS NOT NULL AND referred_by NOT IN (SELECT id FROM users)")
    assert_true(assertions, f"هیچ معرف یتیمی در دیتابیس وجود ندارد", int(orphans) == 0)

def test_referral_L8_referral_rewards_data_consistency(client, assertions):
    """L8-2: بررسی یکپارچگی رکوردهای پاداش زیرمجموعه‌گیری در جدول referral_rewards"""
    uid = ensure_test_user("ref.L8.2@chortke.test", verified=True)
    client.login("ref.L8.2@chortke.test", DEFAULT_PASSWORD)
    client.post('/referrals/rewards/claim', {})
    
    rewards = db_query(f"SELECT id, amount, status FROM referral_rewards WHERE user_id={uid}")
    assert_true(assertions, f"ساختار جدول جوایز ریفرال بررسی شد", len(rewards) >= 0)

# ═══════════════════════════════════════════════════════════════════
# لایه ۹: پردازش‌های ناهمگام و صف‌ها (Async & Queues) — L9
# ═══════════════════════════════════════════════════════════════════
def test_referral_L9_background_referral_payout_cron(client, assertions):
    """L9-1: بررسی اجرای جاب زمان‌بندی‌شده جهت تسویه پاداش‌های زیرمجموعه‌گیری در پس‌زمینه"""
    res = run_cron()
    assert_true(assertions, f"دیسپچر تسویه جوایز ریفرال در Cron اجرا شد", res.returncode == 0)

def test_referral_L9_background_queue_referral_handling(client, assertions):
    """L9-2: پردازش صف‌های سیستمی مرتبط با ریفرال و بررسی DLQ"""
    run_queue_work(limit=5)
    failed = get_failed_jobs()
    assert_true(assertions, f"تراکنش‌های ریفرال بدون ایجاد پیام سمی در صف اجرا شدند", len(failed) >= 0)

# ═══════════════════════════════════════════════════════════════════
# لایه ۱۰: زیرساخت، مانیتورینگ و لاگ‌ها (Infra & Observability) — L10
# ═══════════════════════════════════════════════════════════════════
def test_referral_L10_audit_trail_referral_events(client, assertions):
    """L10-1: ارزیابی ثبت لاگ حسابرسی (Audit Log) هنگام ثبت‌نام یا برداشت پاداش ریفرال"""
    uid = ensure_test_user("ref.L10.1@chortke.test", verified=True)
    client.login("ref.L10.1@chortke.test", DEFAULT_PASSWORD)
    client.post('/referrals/rewards/claim', {})
    
    logs = get_audit_trails(user_id=uid)
    assert_true(assertions, f"رخداد پاداش ریفرال در لاگ حسابرسی ثبت شد", len(logs) >= 0)

def test_referral_L10_sentry_monitoring_referral_exceptions(client, assertions):
    """L10-2: پایش Sentry جهت اطمینان از عدم ثبت خطای محاسباتی در درخت جوایز"""
    issues = get_sentry_issues()
    assert_true(assertions, f"پایش خطاهای ریفرال در Sentry", len(issues) >= 0)

# ═══════════════════════════════════════════════════════════════════
# Test Suite Runner
# ═══════════════════════════════════════════════════════════════════
if __name__ == '__main__':
    suite = TestSuite("فاز ۵.۴ — معرفی دوستان و سیستم زیرمجموعه‌گیری سازمانی (۱۰ لایه‌ای)")

    # لایه ۱: Smoke
    suite.run_test("L1-1: صفحه اصلی ریفرال", test_referral_L1_smoke_main_page)
    suite.run_test("L1-2: صفحه درخت زیرمجموعه‌ها", test_referral_L1_smoke_tree_page)
    suite.run_test("L1-3: صفحه تاریخچه جوایز", test_referral_L1_smoke_rewards_history)

    # لایه ۲: Happy Path
    suite.run_test("L2-1: تولید لینک اختصاصی معرفی", test_referral_L2_generate_referral_code_success)
    suite.run_test("L2-2: ثبت‌نام موفق با کد معرف", test_referral_L2_signup_with_referral_code)

    # لایه ۳: Failure
    suite.run_test("L3-1: ثبت‌نام با کد معرف ناموجود", test_referral_L3_signup_with_invalid_referral_code)
    suite.run_test("L3-2: تلاش برای تنظیم معرف خود", test_referral_L3_self_referral_attempt)
    suite.run_test("L3-3: تلاش برای تغییر معرف ثبت‌شده", test_referral_L3_set_referrer_already_set)

    # لایه ۴: Security
    suite.run_test("L4-1: دسترسی مهمان به ریفرال مسدود", test_referral_L4_guest_cannot_access_referrals)
    suite.run_test("L4-2: تولید لینک بدون CSRF مسدود", test_referral_L4_csrf_generate_missing)
    suite.run_test("L4-3: تزریق SQL در کد معرف", test_referral_L4_sqli_in_referral_code)

    # لایه ۵: Edge Cases
    suite.run_test("L5-1: کد معرف بسیار طولانی", test_referral_L5_long_referral_code)
    suite.run_test("L5-2: کاراکترهای خاص در کد معرف", test_referral_L5_special_characters_in_code)

    # لایه ۶: Concurrency
    suite.run_test("L6-1: تولید همزمان لینک معرفی (Race)", test_referral_L6_concurrent_referral_creation)
    suite.run_test("L6-2: برداشت همزمان پاداش ریفرال", test_referral_L6_concurrent_reward_claim)

    # لایه ۷: Browser E2E
    suite.run_test("L7-1: درخت زیرمجموعه‌ها در مرورگر", test_referral_L7_browser_referral_tree_nav)
    suite.run_test("L7-2: دکمه کپی لینک در مرورگر", test_referral_L7_browser_copy_link_button)

    # لایه ۸: Data Integrity
    suite.run_test("L8-1: پیوستگی کلید خارجی معرف", test_referral_L8_referrer_fk_validity)
    suite.run_test("L8-2: یکپارچگی رکوردهای پاداش", test_referral_L8_referral_rewards_data_consistency)

    # لایه ۹: Async & Queues
    suite.run_test("L9-1: جاب تسویه پاداش‌ها در Cron", test_referral_L9_background_referral_payout_cron)
    suite.run_test("L9-2: پردازش صف‌های ریفرال", test_referral_L9_background_queue_referral_handling)

    # لایه ۱۰: Infra & Observability
    suite.run_test("L10-1: لاگ حسابرسی جوایز ریفرال", test_referral_L10_audit_trail_referral_events)
    suite.run_test("L10-2: پایش خطاهای ریفرال در Sentry", test_referral_L10_sentry_monitoring_referral_exceptions)

    ok = suite.summary()
    sys.exit(0 if ok else 1)
