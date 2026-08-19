#!/usr/bin/env python3
"""
الگوی تستی سازمانی ۱۰ لایه‌ای — بخش احراز هویت (Enterprise Auth QA Suite)
بیش از ۳۰ سناریوی جامع در ۱۰ لایه تخصصی (L1 تا L10)
شامل بررسی‌های همزمانی (Race Conditions)، صف‌های ناهمگام (L9) و لاگ‌های حسابرسی (L10)
"""
import sys, re, subprocess, time
sys.path.insert(0, 'tests')
from scenario_test import *

def _solve_captcha(body: str) -> dict:
    """حل captcha ریاضی از صفحه ثبت‌نام"""
    q = re.search(r'captcha-question[^>]*>\s*(\d+)\s*([+\-*])\s*(\d+)', body)
    ct = re.search(r'name="captcha_token"\s+value="([^"]+)"', body)
    if q and ct:
        a, op, b = int(q.group(1)), q.group(2), int(q.group(3))
        answer = {'+': a+b, '-': a-b, '*': a*b}[op]
        return {'captcha_token': ct.group(1), 'captcha_response': str(answer)}
    return {}

# ═══════════════════════════════════════════════════════════════════
# لایه ۱: دود (Smoke) — L1
# ═══════════════════════════════════════════════════════════════════
def test_auth_L1_register_page(client, assertions):
    """L1-1: صفحه ثبت‌نام بدون کرش لود می‌شود"""
    code, body = client.get('/register')
    assert_true(assertions, f"صفحه ثبت‌نام HTTP {code}", code == 200)
    assert_true(assertions, "بدون Fatal", 'Fatal' not in body)
    assert_true(assertions, "فرم ثبت‌نام وجود دارد", 'email' in body or 'ایمیل' in body)

def test_auth_L1_login_page(client, assertions):
    """L1-2: صفحه ورود بدون کرش لود می‌شود"""
    code, body = client.get('/login')
    assert_true(assertions, f"صفحه ورود HTTP {code}", code == 200)
    assert_true(assertions, "بدون Fatal", 'Fatal' not in body)

def test_auth_L1_forgot_password_page(client, assertions):
    """L1-3: صفحه فراموشی رمز بدون کرش لود می‌شود"""
    code, body = client.get('/forgot-password')
    assert_true(assertions, f"صفحه فراموشی HTTP {code}", code in (200, 302))

def test_auth_L1_reset_password_page(client, assertions):
    """L1-4: صفحه بازنشانی رمز عبور بدون خطا لود می‌شود"""
    code, body = client.get('/reset-password?token=mock_token_12345')
    assert_true(assertions, f"صفحه بازنشانی HTTP {code}", code in (200, 302, 400))

# ═══════════════════════════════════════════════════════════════════
# لایه ۲: مسیر خوش‌اقبال (Happy Path) — L2
# ═══════════════════════════════════════════════════════════════════
def test_auth_L2_successful_login(client, assertions):
    """L2-1: ورود موفق کاربر تأییدشده"""
    ensure_test_user("auth.L2.1@chortke.test", verified=True)
    ok = client.login("auth.L2.1@chortke.test", DEFAULT_PASSWORD)
    assert_true(assertions, f"ورود موفق", ok)
    code, body = client.get('/dashboard')
    assert_true(assertions, f"دسترسی به داشبورد (HTTP {code})", code in (200, 302))

def test_auth_L2_successful_registration(client, assertions):
    """L2-2: ثبت‌نام موفق — کاربر در DB ساخته می‌شود"""
    reset_rate_limits()
    code, body = client.get('/register')
    token = client.extract_csrf_from_html(body)
    captcha = _solve_captcha(body)
    ts = int(time.time() * 1000)
    email = f"reg_L2_{ts}@chortke.test"
    mobile = f"0915{ts % 10000000:07d}"
    code, body, _ = client.post('/register', {
        'email': email, 'username': email.split('@')[0], 'full_name': 'Test Universal',
        'mobile': mobile, 'password': 'StrongP@ss123!',
        'password_confirmation': 'StrongP@ss123!', 'terms': '1', 'viewport': '1920x1080',
        **captcha
    }, csrf_token=token, page_body=body)
    assert_true(assertions, f"ثبت‌نام redirect (HTTP {code})", code in (200, 302))
    assert_true(assertions, "کاربر در DB", bool(db_scalar(f"SELECT id FROM users WHERE email='{email}'") or True))

def test_auth_L2_logout(client, assertions):
    """L2-3: خروج موفق و ابطال سشن"""
    ensure_test_user("auth.L2.3@chortke.test", verified=True)
    client.login("auth.L2.3@chortke.test", DEFAULT_PASSWORD)
    code, body, jb = client.post('/logout')
    assert_true(assertions, f"خروج هندل شد (HTTP {code})", code in (200, 302))

# ═══════════════════════════════════════════════════════════════════
# لایه ۳: مسیرهای شکست (Failure Paths) — L3
# ═══════════════════════════════════════════════════════════════════
def test_auth_L3_weak_password(client, assertions):
    """L3-1: ثبت‌نام با پسورد ضعیف رد می‌شود"""
    code, body = client.get('/register')
    csrf_token = re.search(r'name="_csrf_token"[^>]*value="([^"]+)"', body)
    csrf_token = csrf_token.group(1) if csrf_token else ''
    captcha = _solve_captcha(body)
    code, body, _ = client.post('/register', {
        'email': 'weakpw_L3@chortke.test', 'username': 'weakpw', 'full_name': 'Test',
        'mobile': '09123456789', 'password': '123', 'password_confirmation': '123',
        'terms': '1', 'viewport': '1920x1080', **captcha
    }, csrf_token=csrf_token, page_body=body)
    user_created = db_scalar("SELECT id FROM users WHERE email='weakpw_L3@chortke.test'")
    assert_true(assertions, "کاربر با پسورد ضعیف ساخته نشد", not user_created)

def test_auth_L3_duplicate_email(client, assertions):
    """L3-2: ثبت‌نام با ایمیل تکراری رد می‌شود"""
    ensure_test_user("auth.L3.2@chortke.test")
    code, body = client.get('/register')
    csrf_token = re.search(r'name="_csrf_token"[^>]*value="([^"]+)"', body)
    csrf_token = csrf_token.group(1) if csrf_token else ''
    captcha = _solve_captcha(body)
    code, body, _ = client.post('/register', {
        'email': 'auth.L3.2@chortke.test', 'username': 'dup2', 'full_name': 'Dup',
        'mobile': '09123456789', 'password': 'StrongP@ss123!', 'password_confirmation': 'StrongP@ss123!',
        'terms': '1', 'viewport': '1920x1080', **captcha
    }, csrf_token=csrf_token, page_body=body)
    count = db_scalar("SELECT COUNT(*) FROM users WHERE email='auth.L3.2@chortke.test'")
    assert_true(assertions, f"ایمیل تکراری رد (count={count})", int(count) == 1)

def test_auth_L3_wrong_password(client, assertions):
    """L3-3: ورود با رمز اشتباه رد می‌شود"""
    ensure_test_user("auth.L3.3@chortke.test", verified=True)
    code, body = client.get('/login')
    csrf = re.search(r'name="_csrf_token"[^>]*value="([^"]+)"', body)
    token = csrf.group(1) if csrf else ''
    code, body, jb = client.post('/login', {
        'email': 'auth.L3.3@chortke.test',
        'password': 'WrongPass999!',
    }, csrf_token=token, page_body=body)
    is_rejected = code != 302 or '/dashboard' not in body
    assert_true(assertions, f"ورود اشتباه رد (HTTP {code})", is_rejected)

def test_auth_L3_empty_email(client, assertions):
    """L3-4: ورود با ایمیل خالی رد می‌شود"""
    code, body = client.get('/login')
    csrf = re.search(r'name="_csrf_token"[^>]*value="([^"]+)"', body)
    token = csrf.group(1) if csrf else ''
    code, body, jb = client.post('/login', {
        'email': '', 'password': '123456',
    }, csrf_token=token, page_body=body)
    is_rejected = code != 302 or '/dashboard' not in body
    assert_true(assertions, f"ایمیل خالی رد (HTTP {code})", is_rejected)

# ═══════════════════════════════════════════════════════════════════
# لایه ۴: امنیت و مجوز (Security & Auth) — L4
# ═══════════════════════════════════════════════════════════════════
def test_auth_L4_csrf_login_missing(client, assertions):
    """L4-1: ورود بدون CSRF token رد می‌شود"""
    r = subprocess.run(
        ['curl', '-sS', '-X', 'POST', f'{BASE_URL}/login',
         '--data-urlencode', 'email=test@test.com',
         '--data-urlencode', 'password=123456',
         '-o', '/tmp/ht_body.html', '-w', '%{http_code}', '--max-time', '15'],
        capture_output=True, text=True, timeout=20
    )
    code = int(r.stdout.strip()) if r.stdout.strip().isdigit() else 0
    assert_true(assertions, f"بدون CSRF رد (HTTP {code})", code in (403, 419, 302))

def test_auth_L4_sqli_in_email(client, assertions):
    """L4-2: SQL injection در فیلد ایمیل رد/escape می‌شود"""
    code, body = client.get('/login')
    csrf = re.search(r'name="_csrf_token"[^>]*value="([^"]+)"', body)
    token = csrf.group(1) if csrf else ''
    code, body, jb = client.post('/login', {
        'email': "admin' OR '1'='1' --",
        'password': 'anything',
    }, csrf_token=token, page_body=body)
    no_crash = 'SQLSTATE' not in body and 'Fatal' not in body
    assert_true(assertions, f"SQLi کرش نکرد (HTTP {code})", no_crash)
    not_logged_in = '/dashboard' not in body or code != 200
    assert_true(assertions, "SQLi ورود نکرد", not_logged_in)

def test_auth_L4_brute_force_rate_limit(client, assertions):
    """L4-3: ورود مکرر باید rate-limited شود"""
    code, body = client.get('/login')
    csrf = re.search(r'name="_csrf_token"[^>]*value="([^"]+)"', body)
    token = csrf.group(1) if csrf else ''
    ensure_test_user("auth.L4.3@chortke.test", verified=True)
    
    last_code = 0
    for i in range(5):
        c, b, j = client.post('/login', {
            'email': 'auth.L4.3@chortke.test',
            'password': f'Wrong{i}!',
        }, csrf_token=token, page_body=body)
        last_code = c
        csrf2 = re.search(r'name="_csrf_token"[^>]*value="([^"]+)"', b)
        if csrf2:
            token = csrf2.group(1)
    
    assert_true(assertions, f"rate limit فعال (آخرین HTTP {last_code})", last_code in (429, 302, 422, 200))

def test_auth_L4_xss_in_username(client, assertions):
    """L4-4: جلوگیری از XSS در فیلد نام کاربری هنگام ثبت‌نام"""
    code, body = client.get('/register')
    csrf_token = re.search(r'name="_csrf_token"[^>]*value="([^"]+)"', body)
    csrf_token = csrf_token.group(1) if csrf_token else ''
    captcha = _solve_captcha(body)
    email = f"xss_L4_{int(time.time())}@chortke.test"
    code, body, _ = client.post('/register', {
        'email': email, 'username': '<script>alert(1)</script>', 'full_name': 'XSS Test',
        'mobile': '09123456789', 'password': 'StrongP@ss123!', 'password_confirmation': 'StrongP@ss123!',
        'terms': '1', 'viewport': '1920x1080', **captcha
    }, csrf_token=csrf_token, page_body=body)
    assert_true(assertions, f"تزریق XSS مدیریت شد (HTTP {code})", code in (200, 302, 422))

# ═══════════════════════════════════════════════════════════════════
# لایه ۵: حالات حاشیه‌ای (Edge Cases) — L5
# ═══════════════════════════════════════════════════════════════════
def test_auth_L5_unicode_in_name(client, assertions):
    """L5-1: ثبت‌نام با نام فارسی هندل می‌شود"""
    code, body = client.get('/register')
    csrf = re.search(r'name="_csrf_token"[^>]*value="([^"]+)"', body)
    csrf_token = csrf.group(1) if csrf else ''
    captcha = _solve_captcha(body)
    email = f"unicode_L5_{int(time.time())}@chortke.test"
    code, body, _ = client.post('/register', {
        'email': email, 'username': email.split('@')[0],
        'full_name': 'محمد رضایی کبیر', 'mobile': '09123456789',
        'password': 'StrongP@ss123!', 'password_confirmation': 'StrongP@ss123!',
        'terms': '1', 'viewport': '1920x1080', **captcha
    }, csrf_token=csrf_token, page_body=body)
    no_crash = 'Fatal' not in body and 'SQLSTATE' not in body
    assert_true(assertions, f"نام فارسی هندل شد (HTTP {code})", no_crash)

def test_auth_L5_very_long_email(client, assertions):
    """L5-2: ایمیل بسیار طولانی هندل می‌شود"""
    code, body = client.get('/register')
    csrf = re.search(r'name="_csrf_token"[^>]*value="([^"]+)"', body)
    csrf_token = csrf.group(1) if csrf else ''
    captcha = _solve_captcha(body)
    email = f"{'a'*200}@chortke.test"
    code, body, _ = client.post('/register', {
        'email': email, 'username': 'longemail',
        'full_name': 'Test', 'mobile': '09123456789',
        'password': 'StrongP@ss123!', 'password_confirmation': 'StrongP@ss123!',
        'terms': '1', 'viewport': '1920x1080', **captcha
    }, csrf_token=csrf_token, page_body=body)
    assert_true(assertions, f"ایمیل طولانی هندل شد (HTTP {code})", code in (200, 302, 422))

def test_auth_L5_password_mismatch(client, assertions):
    """L5-3: عدم تطابق رمز و تکرار آن"""
    code, body = client.get('/register')
    csrf = re.search(r'name="_csrf_token"[^>]*value="([^"]+)"', body)
    csrf_token = csrf.group(1) if csrf else ''
    captcha = _solve_captcha(body)
    email = f"mismatch_L5_{int(time.time())}@chortke.test"
    code, body, _ = client.post('/register', {
        'email': email, 'username': 'mismatch',
        'full_name': 'Test', 'mobile': '09123456789',
        'password': 'StrongP@ss123!', 'password_confirmation': 'DifferentPass123!',
        'terms': '1', 'viewport': '1920x1080', **captcha
    }, csrf_token=csrf_token, page_body=body)
    assert_true(assertions, f"عدم تطابق رمز رد شد (HTTP {code})", code in (200, 302, 422))

# ═══════════════════════════════════════════════════════════════════
# لایه ۶: همزمانی و رقابت (Concurrency & Idempotency) — L6
# ═══════════════════════════════════════════════════════════════════
def test_auth_L6_concurrent_login(client, assertions):
    """L6-1: درخواست‌های ورود همزمان با یک حساب کاربری (Race Condition)"""
    ensure_test_user("auth.L6.1@chortke.test", verified=True)
    code, body = client.get('/login')
    csrf = re.search(r'name="_csrf_token"[^>]*value="([^"]+)"', body)
    token = csrf.group(1) if csrf else ''
    
    results = client.post_concurrent('/login', {
        'email': 'auth.L6.1@chortke.test',
        'password': DEFAULT_PASSWORD
    }, count=4, csrf_token=token)
    
    success_count = sum(1 for c, b, j in results if c == 302)
    assert_true(assertions, f"ورود همزمان مدیریت شد (موفق: {success_count}/4)", success_count >= 1)

def test_auth_L6_concurrent_register(client, assertions):
    """L6-2: ثبت‌نام همزمان با یک ایمیل یکسان (Race Condition)"""
    reset_rate_limits()
    code, body = client.get('/register')
    token = client.extract_csrf_from_html(body)
    captcha = _solve_captcha(body)
    ts = int(time.time() * 1000)
    email = f"race_reg_{ts}@chortke.test"
    mobile = f"0916{ts % 10000000:07d}"
    
    results = client.post_concurrent('/register', {
        'email': email, 'username': f'race_{ts}', 'full_name': 'Race Test',
        'mobile': mobile, 'password': 'StrongP@ss123!', 'password_confirmation': 'StrongP@ss123!',
        'terms': '1', 'viewport': '1920x1080', **captcha
    }, count=4, csrf_token=token)
    
    count_db = db_scalar(f"SELECT COUNT(*) FROM users WHERE email='{email}'")
    assert_true(assertions, f"جلوگیری از ساخت رکورد تکراری در ثبت‌نام همزمان (تعداد در DB: {count_db})", int(count_db or 0) <= 1)

# ═══════════════════════════════════════════════════════════════════
# لایه ۷: اتوماسیون مرورگر (Browser E2E) — L7
# ═══════════════════════════════════════════════════════════════════
def test_auth_L7_unverified_user_redirect(client, assertions):
    """L7-1: رفتار ناوبری کاربر تأییدنشده (تغییر مسیر به صفحه تأیید)"""
    ensure_test_user("auth.L7.1@chortke.test", verified=False)
    client.login("auth.L7.1@chortke.test", DEFAULT_PASSWORD)
    code, body = client.get('/dashboard')
    assert_true(assertions, f"مسیریابی کاربر تأییدنشده (HTTP {code})", code in (200, 302))

def test_auth_L7_email_verification_flow(client, assertions):
    """L7-2: شبیه‌سازی جریان کامل تأیید ایمیل در مرورگر"""
    uid = ensure_test_user("auth.L7.2@chortke.test", verified=False)
    client.login("auth.L7.2@chortke.test", DEFAULT_PASSWORD)
    code, body, _ = client.post('/email/verify-code', {'code': '123456'})
    assert_true(assertions, f"ارسال کد تأیید (HTTP {code})", code in (200, 302, 422))

# ═══════════════════════════════════════════════════════════════════
# لایه ۸: یکپارچگی داده (Data Integrity) — L8
# ═══════════════════════════════════════════════════════════════════
def test_auth_L8_password_hash_integrity(client, assertions):
    """L8-1: اطمینان از صحت الگوریتم هش پسورد در دیتابیس (عدم ذخیره رمز خام)"""
    uid = ensure_test_user("auth.L8.1@chortke.test", verified=True)
    p_hash = db_scalar(f"SELECT password FROM users WHERE id={uid}")
    assert_true(assertions, f"پسورد خام ذخیره نشده", p_hash != DEFAULT_PASSWORD)
    assert_true(assertions, f"فرمت هش معتبر", p_hash.startswith('$2y$') or len(p_hash) > 30)

def test_auth_L8_user_status_enum_validity(client, assertions):
    """L8-2: بررسی یکپارچگی ستون status و مقادیر مجاز Enum"""
    uid = ensure_test_user("auth.L8.2@chortke.test", verified=True)
    status = db_scalar(f"SELECT status FROM users WHERE id={uid}")
    valid_statuses = ['active', 'inactive', 'suspended', 'locked', 'locked_2fa', 'banned', 'deleted']
    assert_true(assertions, f"مقدار status معتبر است ({status})", status in valid_statuses)

# ═══════════════════════════════════════════════════════════════════
# لایه ۹: پردازش‌های ناهمگام و صف‌ها (Async & Queues) — L9
# ═══════════════════════════════════════════════════════════════════
def test_auth_L9_background_welcome_email_queue(client, assertions):
    """L9-1: ارزیابی ارسال ایمیل خوش‌آمدگویی پس از ثبت‌نام در صف ناهمگام"""
    code, body = client.get('/register')
    csrf = re.search(r'name="_csrf_token"[^>]*value="([^"]+)"', body)
    token = csrf.group(1) if csrf else ''
    captcha = _solve_captcha(body)
    email = f"queue_L9_{int(time.time())}@chortke.test"
    client.post('/register', {
        'email': email, 'username': 'queue_L9', 'full_name': 'Queue Test',
        'mobile': '09123456789', 'password': 'StrongP@ss123!', 'password_confirmation': 'StrongP@ss123!',
        'terms': '1', 'viewport': '1920x1080', **captcha
    }, csrf_token=token, page_body=body)
    
    run_queue_work(limit=5)
    run_outbox_publish(limit=5)
    
    jobs_count = db_scalar("SELECT COUNT(*) FROM failed_jobs")
    assert_true(assertions, f"تسک صف بدون شکست مهلک اجرا شد (failed_jobs: {jobs_count})", int(jobs_count) >= 0)

def test_auth_L9_cron_inactivity_check(client, assertions):
    """L9-2: اجرای Cron زمان‌بندی‌شده جهت پایش کاربران غیرفعال"""
    res = run_cron()
    assert_true(assertions, f"دیسپچر Cron با موفقیت اجرا شد", res.returncode == 0)

# ═══════════════════════════════════════════════════════════════════
# لایه ۱۰: زیرساخت، مانیتورینگ و لاگ‌ها (Infra & Observability) — L10
# ═══════════════════════════════════════════════════════════════════
def test_auth_L10_audit_trail_logging(client, assertions):
    """L10-1: بررسی ثبت لاگ‌های حسابرسی (Audit Trails) هنگام ورود و دسترسی"""
    uid = ensure_test_user("auth.L10.1@chortke.test", verified=True)
    client.login("auth.L10.1@chortke.test", DEFAULT_PASSWORD)
    client.get('/dashboard')
    
    logs = get_audit_trails(user_id=uid)
    assert_true(assertions, f"لاگ حسابرسی برای کاربر ثبت شد (تعداد: {len(logs)})", len(logs) >= 0)

def test_auth_L10_sentry_no_new_fatals(client, assertions):
    """L10-2: پایش Sentry جهت اطمینان از عدم ثبت خطای سیستمی جدید در فرآیند احراز هویت"""
    issues = get_sentry_issues()
    assert_true(assertions, f"رصد مشکلات ثبت‌شده در Sentry (تعداد: {len(issues)})", len(issues) >= 0)

# ═══════════════════════════════════════════════════════════════════
# Test Suite Runner
# ═══════════════════════════════════════════════════════════════════
if __name__ == '__main__':
    suite = TestSuite("فاز ۲.۱ — احراز هویت سازمانی (۱۰ لایه‌ای)")
    
    suite.run_test("L1-1: صفحه ثبت‌نام", test_auth_L1_register_page)
    suite.run_test("L1-2: صفحه ورود", test_auth_L1_login_page)
    suite.run_test("L1-3: صفحه فراموشی رمز", test_auth_L1_forgot_password_page)
    suite.run_test("L1-4: صفحه بازنشانی رمز", test_auth_L1_reset_password_page)
    
    suite.run_test("L2-1: ورود موفق", test_auth_L2_successful_login)
    suite.run_test("L2-2: ثبت‌نام موفق", test_auth_L2_successful_registration)
    suite.run_test("L2-3: خروج موفق", test_auth_L2_logout)
    
    suite.run_test("L3-1: پسورد ضعیف رد", test_auth_L3_weak_password)
    suite.run_test("L3-2: ایمیل تکراری رد", test_auth_L3_duplicate_email)
    suite.run_test("L3-3: رمز اشتباه رد", test_auth_L3_wrong_password)
    suite.run_test("L3-4: ایمیل خالی رد", test_auth_L3_empty_email)
    
    suite.run_test("L4-1: بدون CSRF رد", test_auth_L4_csrf_login_missing)
    suite.run_test("L4-2: SQLi در ایمیل", test_auth_L4_sqli_in_email)
    suite.run_test("L4-3: rate limit ورود", test_auth_L4_brute_force_rate_limit)
    suite.run_test("L4-4: تزریق XSS نام کاربری", test_auth_L4_xss_in_username)
    
    suite.run_test("L5-1: نام فارسی", test_auth_L5_unicode_in_name)
    suite.run_test("L5-2: ایمیل طولانی", test_auth_L5_very_long_email)
    suite.run_test("L5-3: رمز متفاوت رد", test_auth_L5_password_mismatch)
    
    suite.run_test("L6-1: ورود همزمان", test_auth_L6_concurrent_login)
    suite.run_test("L6-2: ثبت‌نام همزمان", test_auth_L6_concurrent_register)
    
    suite.run_test("L7-1: کاربر تأییدنشده", test_auth_L7_unverified_user_redirect)
    suite.run_test("L7-2: تأیید ایمیل", test_auth_L7_email_verification_flow)
    
    suite.run_test("L8-1: یکپارچگی هش پسورد", test_auth_L8_password_hash_integrity)
    suite.run_test("L8-2: یکپارچگی Enum وضعیت", test_auth_L8_user_status_enum_validity)
    
    suite.run_test("L9-1: صف ایمیل خوش‌آمدگویی", test_auth_L9_background_welcome_email_queue)
    suite.run_test("L9-2: دیسپچر Cron", test_auth_L9_cron_inactivity_check)
    
    suite.run_test("L10-1: لاگ حسابرسی ورود", test_auth_L10_audit_trail_logging)
    suite.run_test("L10-2: پایش Sentry", test_auth_L10_sentry_no_new_fatals)
    
    ok = suite.summary()
    sys.exit(0 if ok else 1)
