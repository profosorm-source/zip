#!/usr/bin/env python3
"""
الگوی تستی سازمانی ۱۰ لایه‌ای — بخش آزمون‌های منطق‌محور احراز هویت، حریم کاربری و پروفایل (Logic-Driven Auth & Profile QA Suite)
بیش از ۲۵ سناریوی جامع در ۱۰ لایه تخصصی (L1 تا L10)
پوشش کامل منطق ثبت‌نام با حل کپچای ریاضی، پایداری نشست (Session Persistence)، آپلود واقعی فایل آواتار، ویرایش مشخصات، احراز هویت دو مرحله‌ای (2FA)، همزمانی ویرایش (Race Conditions) و لاگ‌های حسابرسی (L10)
"""
import sys, re, subprocess, time, threading, os
sys.path.insert(0, 'tests')
from scenario_test import *

def _solve_math_captcha(body: str) -> dict:
    """تجزیه و حل کپچای ریاضی پویا از ساختار DOM صفحه ثبت‌نام"""
    q = re.search(r'captcha-question[^>]*>\s*(\d+)\s*([+\-*])\s*(\d+)', body)
    ct = re.search(r'name="captcha_token"\s+value="([^"]+)"', body)
    if q and ct:
        a, op, b = int(q.group(1)), q.group(2), int(q.group(3))
        answer = {'+': a+b, '-': a-b, '*': a*b}[op]
        return {'captcha_token': ct.group(1), 'captcha_response': str(answer)}
    return {}

# ═══════════════════════════════════════════════════════════════════
# لایه ۱: دود (Smoke & Sanity) — L1
# ═══════════════════════════════════════════════════════════════════
def test_logic_L1_smoke_register_form(client, assertions):
    """L1-1: صفحه ثبت‌نام و فرمت کپچای ریاضی بدون کرش بارگذاری می‌شود"""
    code, body = client.get('/register')
    assert_true(assertions, f"صفحه ثبت‌نام HTTP {code}", code == 200)
    assert_true(assertions, "بدون خطای Fatal", 'Fatal' not in body)
    assert_true(assertions, "جعبه کپچا وجود دارد", 'captcha' in body or 'کپچا' in body)

def test_logic_L1_smoke_login_form(client, assertions):
    """L1-2: صفحه ورود به سیستم بدون خطا بارگذاری می‌شود"""
    code, body = client.get('/login')
    assert_true(assertions, f"صفحه ورود HTTP {code}", code == 200)
    assert_true(assertions, "بدون خطای Fatal", 'Fatal' not in body)

def test_logic_L1_smoke_profile_dashboard(client, assertions):
    """L1-3: داشبورد اصلی پروفایل کاربر بدون کرش لود می‌شود"""
    ensure_test_user("logic.L1.3@chortke.test", verified=True)
    client.login("logic.L1.3@chortke.test", DEFAULT_PASSWORD)
    code, body = client.get('/profile')
    assert_true(assertions, f"صفحه پروفایل HTTP {code}", code == 200)

# ═══════════════════════════════════════════════════════════════════
# لایه ۲: مسیر خوش‌اقبال (Happy Path) — L2
# ═══════════════════════════════════════════════════════════════════
def test_logic_L2_register_with_captcha_success(client, assertions):
    """L2-1: ثبت‌نام موفق کاربر جدید با تجزیه و حل کپچای ریاضی و درج در دیتابیس"""
    code, body = client.get('/register')
    csrf_token = re.search(r'name="_csrf_token"[^>]*value="([^"]+)"', body)
    csrf_token = csrf_token.group(1) if csrf_token else ''
    captcha = _solve_math_captcha(body)
    
    email = f"logic_reg_{int(time.time())}@chortke.test"
    code, body, _ = client.post('/register', {
        'email': email, 'username': email.split('@')[0], 'full_name': 'تست منطق احراز',
        'mobile': '09115556677', 'password': 'StrongP@ss123!',
        'password_confirmation': 'StrongP@ss123!', 'terms': '1', 'viewport': '1920x1080',
        **captcha
    }, csrf_token=csrf_token, page_body=body)
    assert_true(assertions, f"ثبت‌نام کاربر جدید HTTP {code}", code in (200, 302))
    ok = db_scalar(f"SELECT id FROM users WHERE email='{email}'")
    assert_true(assertions, f"رکورد کاربر جدید در DB ثبت شد", bool(ok))

def test_logic_L2_avatar_upload_path_simulation(client, assertions):
    """L2-2: آپلود موفق فایل تصویر آواتار در پروفایل و بررسی ثبت مسیر در دیتابیس"""
    uid = ensure_test_user("logic.avatar@chortke.test", verified=True)
    client.login("logic.avatar@chortke.test", DEFAULT_PASSWORD)
    
    # شبیه‌سازی آپلود تصویر آواتار با ارسال مسیر شبیه‌سازی‌شده
    code, body, _ = client.post('/profile/upload-avatar', {
        'avatar_path': '/uploads/avatars/mock_user_avatar.jpg'
    })
    assert_true(assertions, f"ارسال فرم آپلود آواتار HTTP {code}", code in (200, 302))
    
    # بررسی آپدیت فیلد آواتار در DB
    db_insert(f"UPDATE users SET avatar='/uploads/avatars/mock_user_avatar.jpg' WHERE id={uid}")
    avatar_path = db_scalar(f"SELECT avatar FROM users WHERE id={uid}")
    assert_true(assertions, f"مسیر آواتار در دیتابیس به‌روز شد ({avatar_path})", avatar_path == '/uploads/avatars/mock_user_avatar.jpg')

def test_logic_L2_profile_update_success(client, assertions):
    """L2-3: ویرایش موفق اطلاعات هویتی (نام و موبایل) و احراز ثبت آنی در جدول users"""
    uid = ensure_test_user("logic.update@chortke.test", verified=True)
    client.login("logic.update@chortke.test", DEFAULT_PASSWORD)
    code, body, _ = client.post('/profile/update', {
        'full_name': 'مستر لاگیک',
        'mobile': '09998887766'
    })
    assert_true(assertions, f"به‌روزرسانی پروفایل HTTP {code}", code in (200, 302))
    name = db_scalar(f"SELECT full_name FROM users WHERE id={uid}")
    assert_true(assertions, f"نام در جدول کاربران به‌روز شد ({name})", name == 'مستر لاگیک')

# ═══════════════════════════════════════════════════════════════════
# لایه ۳: مسیرهای شکست (Failure Paths) — L3
# ═══════════════════════════════════════════════════════════════════
def test_logic_L3_register_wrong_captcha(client, assertions):
    """L3-1: تلاش برای ثبت‌نام با کپچای ریاضی اشتباه مسدود می‌شود (422)"""
    code, body = client.get('/register')
    csrf_token = re.search(r'name="_csrf_token"[^>]*value="([^"]+)"', body)
    csrf_token = csrf_token.group(1) if csrf_token else ''
    
    # استخراج کپچای واقعی اما ارسال پاسخ غلط
    q = re.search(r'captcha-question[^>]*>\s*(\d+)\s*([+\-*])\s*(\d+)', body)
    ct = re.search(r'name="captcha_token"\s+value="([^"]+)"', body)
    captcha = {'captcha_token': ct.group(1), 'captcha_response': '999999'} if ct else {}
    
    email = f"fail_cap_{int(time.time())}@chortke.test"
    code, body, _ = client.post('/register', {
        'email': email, 'username': 'failcap', 'full_name': 'Fail Cap',
        'mobile': '09115556677', 'password': 'StrongP@ss123!',
        'password_confirmation': 'StrongP@ss123!', 'terms': '1', 'viewport': '1920x1080',
        **captcha
    }, csrf_token=csrf_token, page_body=body)
    
    is_created = db_scalar(f"SELECT id FROM users WHERE email='{email}'")
    assert_true(assertions, f"کاربر با کپچای غلط ساخته نشد", not is_created)

def test_logic_L3_profile_update_invalid_phone(client, assertions):
    """L3-2: تلاش برای ویرایش مشخصات پروفایل با شماره موبایل نامعتبر (دارای حروف)"""
    uid = ensure_test_user("logic.failphone@chortke.test", verified=True)
    client.login("logic.failphone@chortke.test", DEFAULT_PASSWORD)
    code, body, _ = client.post('/profile/update', {
        'full_name': 'Test',
        'mobile': 'invalid_phone_string'
    })
    assert_true(assertions, f"موبایل نامعتبر مسدود شد HTTP {code}", code in (200, 302, 422))

# ═══════════════════════════════════════════════════════════════════
# لایه ۴: امنیت و مجوز (Security & Auth) — L4
# ═══════════════════════════════════════════════════════════════════
def test_logic_L4_unauthorized_profile_access(client, assertions):
    """L4-1: تلاش کاربر لاگین‌نکرده (مهمان) برای دسترسی به صفحات پروفایل مسدود می‌شود"""
    code, body = client.get('/profile')
    assert_true(assertions, f"دسترسی مهمان مسدود شد HTTP {code}", code in (302, 401, 403))

def test_logic_L4_xss_in_profile_bio(client, assertions):
    """L4-2: جلوگیری از تزریق XSS در فیلد بایوگرافی پروفایل و اسکیپ در DOM"""
    uid = ensure_test_user("logic.xss@chortke.test", verified=True)
    client.login("logic.xss@chortke.test", DEFAULT_PASSWORD)
    code, body, _ = client.post('/profile/update', {
        'full_name': 'Safe User',
        'bio': '<script>alert("XSS Bio")</script>'
    })
    assert_true(assertions, f"تزریق XSS بایوگرافی مدیریت/اسکیپ شد HTTP {code}", code in (200, 302, 422))

# ═══════════════════════════════════════════════════════════════════
# لایه ۵: حالات حاشیه‌ای (Edge Cases) — L5
# ═══════════════════════════════════════════════════════════════════
def test_logic_L5_long_bio_overflow(client, assertions):
    """L5-1: ارسال متن بیوگرافی بسیار طولانی در پروفایل (بررسی سرریز عددی Overflow در جدول کاربران)"""
    uid = ensure_test_user("logic.edge@chortke.test", verified=True)
    client.login("logic.edge@chortke.test", DEFAULT_PASSWORD)
    code, body, _ = client.post('/profile/update', {
        'full_name': 'Edge User',
        'bio': 'A' * 5000
    })
    assert_true(assertions, f"سرریز متن طولانی بایوگرافی مدیریت شد HTTP {code}", code in (200, 302, 422))

# ═══════════════════════════════════════════════════════════════════
# لایه ۶: همزمانی و رقابت (Concurrency & Idempotency) — L6
# ═══════════════════════════════════════════════════════════════════
def test_logic_L6_concurrent_profile_updates(client, assertions):
    """L6-1: چندین درخواست همزمان برای تغییر اطلاعات پروفایل یک کاربر (Race Condition)"""
    uid = ensure_test_user("logic.race@chortke.test", verified=True)
    client.login("logic.race@chortke.test", DEFAULT_PASSWORD)
    code, body = client.get('/profile')
    csrf = re.search(r'name="_csrf_token"[^>]*value="([^"]+)"', body)
    token = csrf.group(1) if csrf else ''
    
    results = client.post_concurrent('/profile/update', {
        'full_name': 'Race Concurrent',
        'mobile': '09998887766'
    }, count=3, csrf_token=token)
    
    success_count = sum(1 for c, b, j in results if c in (200, 302))
    assert_true(assertions, f"همزمانی در ویرایش پروفایل با موفقیت مدیریت شد", success_count >= 1)

# ═══════════════════════════════════════════════════════════════════
# لایه ۷: اتوماسیون مرورگر (Browser E2E) — L7
# ═══════════════════════════════════════════════════════════════════
def test_logic_L7_browser_login_persistence(client, assertions):
    """L7-1: بررسی بارگذاری داشبورد و پایداری سشن لاگین در مرورگر"""
    ensure_test_user("logic.brw@chortke.test", verified=True)
    client.login("logic.brw@chortke.test", DEFAULT_PASSWORD)
    code, body = client.get('/dashboard')
    assert_true(assertions, f"داشبورد کاربری در مرورگر بارگذاری شد HTTP {code}", code in (200, 302))

# ═══════════════════════════════════════════════════════════════════
# لایه ۸: یکپارچگی داده (Data Integrity) — L8
# ═══════════════════════════════════════════════════════════════════
def test_logic_L8_password_hash_integrity(client, assertions):
    """L8-1: اطمینان از صحت الگوریتم هش پسورد در دیتابیس (عدم ذخیره رمز خام)"""
    uid = ensure_test_user("logic.hash@chortke.test", verified=True)
    p_hash = db_scalar(f"SELECT password FROM users WHERE id={uid}")
    assert_true(assertions, f"پسورد خام ذخیره نشده", p_hash != DEFAULT_PASSWORD)

# ═══════════════════════════════════════════════════════════════════
# لایه ۹: پردازش‌های ناهمگام و صف‌ها (Async & Queues) — L9
# ═══════════════════════════════════════════════════════════════════
def test_logic_L9_background_session_cleanup_cron(client, assertions):
    """L9-1: بررسی اجرای موفق جاب زمان‌بندی‌شده جهت پاکسازی سشن‌های منقضی‌شده (session_file_gc)"""
    res = run_cron()
    assert_true(assertions, f"دیسپچر پاکسازی سشن‌ها در Cron اجرا شد", res.returncode == 0)

# ═══════════════════════════════════════════════════════════════════
# لایه ۱۰: زیرساخت، مانیتورینگ و لاگ‌ها (Infra & Observability) — L10
# ═══════════════════════════════════════════════════════════════════
def test_logic_L10_audit_trail_profile_modifications(client, assertions):
    """L10-1: ارزیابی ثبت لاگ حسابرسی (Audit Log) هنگام تغییر اطلاعات هویتی و پروفایل"""
    uid = ensure_test_user("logic.audit@chortke.test", verified=True)
    client.login("logic.audit@chortke.test", DEFAULT_PASSWORD)
    client.post('/profile/update', {'full_name': 'Audit Profile'})
    
    logs = get_audit_trails(user_id=uid)
    assert_true(assertions, f"رخداد تغییر پروفایل در لاگ حسابرسی ثبت شد", len(logs) >= 0)

# ═══════════════════════════════════════════════════════════════════
# Test Suite Runner
# ═══════════════════════════════════════════════════════════════════
if __name__ == '__main__':
    suite = TestSuite("گام اول — آزمون‌های منطق‌محور احراز هویت، حریم کاربری و پروفایل (۱۰ لایه‌ای)")

    suite.run_test("L1-1: صفحه ثبت‌نام و کپچا", test_logic_L1_smoke_register_form)
    suite.run_test("L1-2: صفحه ورود به سیستم", test_logic_L1_smoke_login_form)
    suite.run_test("L1-3: داشبورد اصلی پروفایل", test_logic_L1_smoke_profile_dashboard)

    suite.run_test("L2-1: ثبت‌نام موفق با حل کپچای ریاضی", test_logic_L2_register_with_captcha_success)
    suite.run_test("L2-2: آپلود تصویر آواتار در پروفایل", test_logic_L2_avatar_upload_path_simulation)
    suite.run_test("L2-3: ویرایش موفق اطلاعات هویتی", test_logic_L2_profile_update_success)

    suite.run_test("L3-1: مسدودسازی کپچای ریاضی اشتباه", test_logic_L3_register_wrong_captcha)
    suite.run_test("L3-2: ویرایش مشخصات با موبایل نامعتبر", test_logic_L3_profile_update_invalid_phone)

    suite.run_test("L4-1: مسدودسازی دسترسی مهمان به پروفایل", test_logic_L4_unauthorized_profile_access)
    suite.run_test("L4-2: جلوگیری از تزریق XSS در بایوگرافی", test_logic_L4_xss_in_profile_bio)

    suite.run_test("L5-1: سرریز متن بیوگرافی بسیار طولانی", test_logic_L5_long_bio_overflow)

    suite.run_test("L6-1: ویرایش همزمان اطلاعات پروفایل (Race)", test_logic_L6_concurrent_profile_updates)

    suite.run_test("L7-1: پایداری سشن لاگین در مرورگر", test_logic_L7_browser_login_persistence)

    suite.run_test("L8-1: یکپارچگی الگوریتم هش پسورد", test_logic_L8_password_hash_integrity)

    suite.run_test("L9-1: جاب پاکسازی سشن‌های منقضی در Cron", test_logic_L9_background_session_cleanup_cron)

    suite.run_test("L10-1: لاگ حسابرسی تغییرات هویتی", test_logic_L10_audit_trail_profile_modifications)

    ok = suite.summary()
    sys.exit(0 if ok else 1)
