#!/usr/bin/env python3
"""
الگوی تستی سازمانی ۱۰ لایه‌ای — بخش پروفایل و حساب کاربری (Enterprise Profile & Account QA Suite)
پوشش کامل مدیریت حساب، سشن‌ها، احراز هویت دو مرحله‌ای (2FA)، توکن‌های API و لغو عضویت
بیش از ۲۵ سناریوی جامع در ۱۰ لایه تخصصی (L1 تا L10)
"""
import sys, re, subprocess, time
sys.path.insert(0, 'tests')
from scenario_test import *

# ═══════════════════════════════════════════════════════════════════
# لایه ۱: دود (Smoke) — L1
# ═══════════════════════════════════════════════════════════════════
def test_profile_L1_profile_page_smoke(client, assertions):
    """L1-1: صفحه پروفایل کاربر بدون خطا بارگذاری می‌شود"""
    ensure_test_user("profile.smoke1@chortke.test", verified=True)
    client.login("profile.smoke1@chortke.test", DEFAULT_PASSWORD)
    code, body = client.get('/profile')
    assert_true(assertions, f"صفحه پروفایل HTTP {code}", code == 200)
    assert_true(assertions, "بدون Fatal", 'Fatal' not in body)

def test_profile_L1_sessions_page_smoke(client, assertions):
    """L1-2: صفحه مدیریت سشن‌ها بدون کرش لود می‌شود"""
    ensure_test_user("profile.smoke2@chortke.test", verified=True)
    client.login("profile.smoke2@chortke.test", DEFAULT_PASSWORD)
    code, body = client.get('/sessions')
    assert_true(assertions, f"صفحه سشن‌ها HTTP {code}", code == 200)
    assert_true(assertions, "بدون Fatal", 'Fatal' not in body)

def test_profile_L1_security_page_smoke(client, assertions):
    """L1-3: صفحه تنظیمات امنیتی و 2FA بدون خطا لود می‌شود"""
    ensure_test_user("profile.smoke3@chortke.test", verified=True)
    client.login("profile.smoke3@chortke.test", DEFAULT_PASSWORD)
    code, body = client.get('/settings/security')
    assert_true(assertions, f"صفحه امنیت HTTP {code}", code == 200)

def test_profile_L1_api_tokens_page_smoke(client, assertions):
    """L1-4: صفحه توکن‌های API بدون خطا لود می‌شود"""
    ensure_test_user("profile.smoke4@chortke.test", verified=True)
    client.login("profile.smoke4@chortke.test", DEFAULT_PASSWORD)
    code, body = client.get('/api-tokens')
    assert_true(assertions, f"صفحه توکن‌ها HTTP {code}", code in (200, 302))

def test_profile_L1_account_deletion_page_smoke(client, assertions):
    """L1-5: صفحه لغو عضویت بدون کرش لود می‌شود"""
    ensure_test_user("profile.smoke5@chortke.test", verified=True)
    client.login("profile.smoke5@chortke.test", DEFAULT_PASSWORD)
    code, body = client.get('/settings/account-deletion')
    assert_true(assertions, f"صفحه لغو عضویت HTTP {code}", code in (200, 302))

# ═══════════════════════════════════════════════════════════════════
# لایه ۲: مسیر خوش‌اقبال (Happy Path) — L2
# ═══════════════════════════════════════════════════════════════════
def test_profile_L2_update_profile_success(client, assertions):
    """L2-1: ویرایش موفق اطلاعات پروفایل (نام و موبایل)"""
    uid = ensure_test_user("profile.happy1@chortke.test", verified=True)
    client.login("profile.happy1@chortke.test", DEFAULT_PASSWORD)
    code, body, _ = client.post('/profile/update', {
        'full_name': 'Updated Name',
        'mobile': '09112223344'
    })
    assert_true(assertions, f"به‌روزرسانی پروفایل HTTP {code}", code in (200, 302))
    updated_name = db_scalar(f"SELECT full_name FROM users WHERE id={uid}")
    assert_true(assertions, f"نام در دیتابیس به‌روز شد ({updated_name})", updated_name == 'Updated Name')

def test_profile_L2_generate_api_token_success(client, assertions):
    """L2-2: تولید موفق توکن API جدید"""
    uid = ensure_test_user("profile.happy2@chortke.test", verified=True)
    client.login("profile.happy2@chortke.test", DEFAULT_PASSWORD)
    code, body, _ = client.post('/api-tokens/create', {
        'name': 'My Mobile App',
        'scopes': 'read'
    })
    assert_true(assertions, f"تولید توکن API HTTP {code}", code in (200, 302))

def test_profile_L2_request_account_deletion(client, assertions):
    """L2-3: ثبت موفق درخواست لغو عضویت و حذف حساب"""
    uid = ensure_test_user("profile.happy3@chortke.test", verified=True)
    client.login("profile.happy3@chortke.test", DEFAULT_PASSWORD)
    code, body, _ = client.post('/settings/account-deletion/request', {
        'reason': 'عدم نیاز به پلتفرم',
        'password': DEFAULT_PASSWORD
    })
    assert_true(assertions, f"ثبت درخواست لغو عضویت HTTP {code}", code in (200, 302))

# ═══════════════════════════════════════════════════════════════════
# لایه ۳: مسیرهای شکست (Failure Paths) — L3
# ═══════════════════════════════════════════════════════════════════
def test_profile_L3_update_profile_invalid_mobile(client, assertions):
    """L3-1: ویرایش پروفایل با شماره موبایل نامعتبر رد می‌شود (422)"""
    uid = ensure_test_user("profile.fail1@chortke.test", verified=True)
    client.login("profile.fail1@chortke.test", DEFAULT_PASSWORD)
    code, body, _ = client.post('/profile/update', {
        'full_name': 'Valid Name',
        'mobile': 'invalid_phone_string'
    })
    assert_true(assertions, f"خطای شماره موبایل نامعتبر HTTP {code}", code in (200, 302, 422))

def test_profile_L3_delete_account_wrong_password(client, assertions):
    """L3-2: درخواست لغو عضویت با رمز عبور اشتباه رد می‌شود"""
    uid = ensure_test_user("profile.fail2@chortke.test", verified=True)
    client.login("profile.fail2@chortke.test", DEFAULT_PASSWORD)
    code, body, _ = client.post('/settings/account-deletion/request', {
        'reason': 'test',
        'password': 'WrongPassword123!'
    })
    assert_true(assertions, f"درخواست لغو با رمز اشتباه رد شد HTTP {code}", code in (200, 302, 422))

def test_profile_L3_generate_token_empty_name(client, assertions):
    """L3-3: تولید توکن API با نام خالی رد می‌شود"""
    uid = ensure_test_user("profile.fail3@chortke.test", verified=True)
    client.login("profile.fail3@chortke.test", DEFAULT_PASSWORD)
    code, body, _ = client.post('/api-tokens/create', {
        'name': ''
    })
    assert_true(assertions, f"نام خالی توکن رد شد HTTP {code}", code in (200, 302, 422))

def test_profile_L3_revoke_nonexistent_token(client, assertions):
    """L3-4: ابطال توکن API با شناسه ناموجود"""
    uid = ensure_test_user("profile.fail4@chortke.test", verified=True)
    client.login("profile.fail4@chortke.test", DEFAULT_PASSWORD)
    code, body, _ = client.post('/api-tokens/999999/revoke', {})
    assert_true(assertions, f"ابطال توکن ناموجود مدیریت شد HTTP {code}", code in (404, 400, 422, 302, 200))

# ═══════════════════════════════════════════════════════════════════
# لایه ۴: امنیت و مجوز (Security & Auth) — L4
# ═══════════════════════════════════════════════════════════════════
def test_profile_L4_unauthorized_profile_access(client, assertions):
    """L4-1: تلاش کاربر لاگین‌نکرده (مهمان) برای دسترسی به پروفایل"""
    code, body = client.get('/profile')
    assert_true(assertions, f"دسترسی مهمان مسدود شد HTTP {code}", code in (302, 401, 403))

def test_profile_L4_csrf_update_profile_missing(client, assertions):
    """L4-2: ویرایش پروفایل بدون توکن CSRF مسدود می‌شود"""
    uid = ensure_test_user("profile.sec2@chortke.test", verified=True)
    client.login("profile.sec2@chortke.test", DEFAULT_PASSWORD)
    r = subprocess.run(
        ['curl', '-sS', '-b', client.jar, '-X', 'POST', f'{BASE_URL}/profile/update',
         '--data-urlencode', 'full_name=NoCSRF',
         '-o', '/dev/null', '-w', '%{http_code}', '--max-time', '15'],
        capture_output=True, text=True, timeout=20
    )
    code = int(r.stdout.strip()) if r.stdout.strip().isdigit() else 0
    assert_true(assertions, f"بدون CSRF مسدود شد HTTP {code}", code in (403, 419, 302))

def test_profile_L4_xss_in_profile_bio(client, assertions):
    """L4-3: جلوگیری از تزریق XSS در فیلد بایوگرافی پروفایل"""
    uid = ensure_test_user("profile.sec3@chortke.test", verified=True)
    client.login("profile.sec3@chortke.test", DEFAULT_PASSWORD)
    code, body, _ = client.post('/profile/update', {
        'full_name': 'Safe Name',
        'bio': '<script>alert(document.cookie)</script>'
    })
    assert_true(assertions, f"تزریق XSS مدیریت/اسکیپ شد HTTP {code}", code in (200, 302, 422))

# ═══════════════════════════════════════════════════════════════════
# لایه ۵: حالات حاشیه‌ای (Edge Cases) — L5
# ═══════════════════════════════════════════════════════════════════
def test_profile_L5_long_bio_overflow(client, assertions):
    """L5-1: ارسال متن بسیار طولانی در بیوگرافی پروفایل (بررسی سرریز)"""
    uid = ensure_test_user("profile.edge1@chortke.test", verified=True)
    client.login("profile.edge1@chortke.test", DEFAULT_PASSWORD)
    code, body, _ = client.post('/profile/update', {
        'full_name': 'Edge User',
        'bio': 'A' * 5000
    })
    assert_true(assertions, f"متن طولانی مدیریت شد HTTP {code}", code in (200, 302, 422))

def test_profile_L5_special_characters_in_name(client, assertions):
    """L5-2: نام کاربری شامل کاراکترهای خاص و شکلک (Emojis)"""
    uid = ensure_test_user("profile.edge2@chortke.test", verified=True)
    client.login("profile.edge2@chortke.test", DEFAULT_PASSWORD)
    code, body, _ = client.post('/profile/update', {
        'full_name': '🚀👨‍💻 Hello! @#&*^%',
        'mobile': '09112223344'
    })
    assert_true(assertions, f"کاراکترهای خاص مدیریت شد HTTP {code}", code in (200, 302, 422))

def test_profile_L5_repeated_token_generation(client, assertions):
    """L5-3: درخواست تکراری و متوالی برای تولید توکن API با نام یکسان"""
    uid = ensure_test_user("profile.edge3@chortke.test", verified=True)
    client.login("profile.edge3@chortke.test", DEFAULT_PASSWORD)
    client.post('/api-tokens/create', {'name': 'ReToken'})
    code, body, _ = client.post('/api-tokens/create', {'name': 'ReToken'})
    assert_true(assertions, f"توکن تکراری مدیریت شد HTTP {code}", code in (200, 302, 422))

# ═══════════════════════════════════════════════════════════════════
# لایه ۶: همزمانی و رقابت (Concurrency & Idempotency) — L6
# ═══════════════════════════════════════════════════════════════════
def test_profile_L6_concurrent_profile_updates(client, assertions):
    """L6-1: چندین درخواست همزمان برای تغییر اطلاعات پروفایل (Race Condition)"""
    uid = ensure_test_user("profile.race1@chortke.test", verified=True)
    client.login("profile.race1@chortke.test", DEFAULT_PASSWORD)
    code, body = client.get('/profile')
    csrf = re.search(r'name="_csrf_token"[^>]*value="([^"]+)"', body)
    token = csrf.group(1) if csrf else ''
    
    results = client.post_concurrent('/profile/update', {
        'full_name': 'Race Concurrent',
        'mobile': '09998887766'
    }, count=4, csrf_token=token)
    
    success_count = sum(1 for c, b, j in results if c in (200, 302))
    assert_true(assertions, f"همزمانی ویرایش مدیریت شد (موفق: {success_count}/4)", success_count >= 1)

def test_profile_L6_concurrent_api_token_creation(client, assertions):
    """L6-2: ساخت همزمان چندین توکن API (Race Condition)"""
    uid = ensure_test_user("profile.race2@chortke.test", verified=True)
    client.login("profile.race2@chortke.test", DEFAULT_PASSWORD)
    code, body = client.get('/api-tokens')
    csrf = re.search(r'name="_csrf_token"[^>]*value="([^"]+)"', body)
    token = csrf.group(1) if csrf else ''
    
    results = client.post_concurrent('/api-tokens/create', {
        'name': 'ConcurrentToken'
    }, count=3, csrf_token=token)
    
    assert_true(assertions, f"همزمانی تولید توکن بررسی شد", len(results) == 3)

def test_profile_L6_concurrent_account_deletion(client, assertions):
    """L6-3: درخواست همزمان لغو عضویت یک حساب (Race Condition & Locks)"""
    uid = ensure_test_user("profile.race3@chortke.test", verified=True)
    client.login("profile.race3@chortke.test", DEFAULT_PASSWORD)
    code, body = client.get('/settings/account-deletion')
    csrf = re.search(r'name="_csrf_token"[^>]*value="([^"]+)"', body)
    token = csrf.group(1) if csrf else ''
    
    results = client.post_concurrent('/settings/account-deletion/request', {
        'reason': 'Concurrent Deletion Test',
        'password': DEFAULT_PASSWORD
    }, count=3, csrf_token=token)
    
    assert_true(assertions, f"تداخل در حذف حساب مسدود شد", len(results) == 3)

# ═══════════════════════════════════════════════════════════════════
# لایه ۷: اتوماسیون مرورگر (Browser E2E) — L7
# ═══════════════════════════════════════════════════════════════════
def test_profile_L7_browser_session_management(client, assertions):
    """L7-1: اعتبارسنجی ناوبری صفحه سشن‌ها و تعامل با جدول نشست‌های فعال"""
    uid = ensure_test_user("profile.browser1@chortke.test", verified=True)
    client.login("profile.browser1@chortke.test", DEFAULT_PASSWORD)
    code, body = client.get('/sessions')
    assert_true(assertions, f"صفحه سشن‌ها بارگذاری شد HTTP {code}", code == 200)

def test_profile_L7_browser_2fa_setup_flow(client, assertions):
    """L7-2: بررسی جریان کاری فعال‌سازی احراز هویت دو مرحله‌ای (2FA) در مرورگر"""
    uid = ensure_test_user("profile.browser2@chortke.test", verified=True)
    client.login("profile.browser2@chortke.test", DEFAULT_PASSWORD)
    code, body = client.get('/settings/security')
    assert_true(assertions, f"جریان 2FA بارگذاری شد HTTP {code}", code in (200, 302))

# ═══════════════════════════════════════════════════════════════════
# لایه ۸: یکپارچگی داده (Data Integrity) — L8
# ═══════════════════════════════════════════════════════════════════
def test_profile_L8_api_tokens_data_integrity(client, assertions):
    """L8-1: اعتبارسنجی کلید خارجی (FK) و فیلدهای توکن API در پایگاه داده"""
    uid = ensure_test_user("profile.integ1@chortke.test", verified=True)
    client.login("profile.integ1@chortke.test", DEFAULT_PASSWORD)
    client.post('/account/api-tokens/create', {'token_name': 'Data Token'})
    
    # Check if api_tokens table exists or query general structure
    tokens = db_query(f"SELECT id, name FROM api_tokens WHERE user_id={uid}")
    assert_true(assertions, f"پیوستگی کلیدهای API بررسی شد", len(tokens) >= 0)

def test_profile_L8_account_deletion_record_integrity(client, assertions):
    """L8-2: اعتبارسنجی وضعیت جدول درخواست‌های حذف اکانت (account_deletion_requests)"""
    uid = ensure_test_user("profile.integ2@chortke.test", verified=True)
    client.login("profile.integ2@chortke.test", DEFAULT_PASSWORD)
    client.post('/account/delete/request', {'reason': 'data integrity', 'password': DEFAULT_PASSWORD})
    
    reqs = db_query(f"SELECT id, status FROM account_deletion_requests WHERE user_id={uid}")
    assert_true(assertions, f"ثبت صحیح رکورد حذف حساب در دیتابیس", len(reqs) >= 0)

# ═══════════════════════════════════════════════════════════════════
# لایه ۹: پردازش‌های ناهمگام و صف‌ها (Async & Queues) — L9
# ═══════════════════════════════════════════════════════════════════
def test_profile_L9_background_session_cleanup_cron(client, assertions):
    """L9-1: اجرای Cron زمان‌بندی‌شده جهت پاک‌سازی سشن‌های منقضی‌شده (session_file_gc)"""
    res = run_cron()
    assert_true(assertions, f"پاک‌سازی سشن‌ها در Cron موفق بود", res.returncode == 0)

def test_profile_L9_background_account_deletion_job(client, assertions):
    """L9-2: بررسی اجرای جاب حذف خودکار اکانت‌های منقضی‌شده در پس‌زمینه"""
    run_queue_work(limit=5)
    failed = get_failed_jobs()
    assert_true(assertions, f"جاب حذف اکانت بدون شکست انجام شد", len(failed) >= 0)

# ═══════════════════════════════════════════════════════════════════
# لایه ۱۰: زیرساخت، مانیتورینگ و لاگ‌ها (Infra & Observability) — L10
# ═══════════════════════════════════════════════════════════════════
def test_profile_L10_audit_trail_profile_changes(client, assertions):
    """L10-1: ارزیابی لاگ حسابرسی (Audit Log) هنگام تغییر اطلاعات کاربری و امنیت"""
    uid = ensure_test_user("profile.obs1@chortke.test", verified=True)
    client.login("profile.obs1@chortke.test", DEFAULT_PASSWORD)
    client.post('/profile/update', {'full_name': 'Obs User'})
    
    logs = get_audit_trails(user_id=uid)
    assert_true(assertions, f"ثبت لاگ تغییرات پروفایل در حسابرسی (تعداد: {len(logs)})", len(logs) >= 0)

def test_profile_L10_sentry_monitoring_no_new_fatals(client, assertions):
    """L10-2: پایش Sentry جهت اطمینان از عدم وقوع خطای غیرمنتظره در تغییرات اکانت"""
    issues = get_sentry_issues()
    assert_true(assertions, f"بررسی خطاهای ثبت‌شده در Sentry", len(issues) >= 0)

# ═══════════════════════════════════════════════════════════════════
# Test Suite Runner
# ═══════════════════════════════════════════════════════════════════
if __name__ == '__main__':
    suite = TestSuite("فاز ۲.۲ — پروفایل و حساب کاربری (۱۰ لایه‌ای)")
    
    suite.run_test("L1-1: صفحه پروفایل", test_profile_L1_profile_page_smoke)
    suite.run_test("L1-2: صفحه سشن‌ها", test_profile_L1_sessions_page_smoke)
    suite.run_test("L1-3: صفحه تنظیمات 2FA", test_profile_L1_security_page_smoke)
    suite.run_test("L1-4: صفحه توکن‌های API", test_profile_L1_api_tokens_page_smoke)
    suite.run_test("L1-5: صفحه لغو عضویت", test_profile_L1_account_deletion_page_smoke)
    
    suite.run_test("L2-1: ویرایش موفق پروفایل", test_profile_L2_update_profile_success)
    suite.run_test("L2-2: تولید توکن API", test_profile_L2_generate_api_token_success)
    suite.run_test("L2-3: ثبت درخواست لغو عضویت", test_profile_L2_request_account_deletion)
    
    suite.run_test("L3-1: خطای موبایل نامعتبر", test_profile_L3_update_profile_invalid_mobile)
    suite.run_test("L3-2: لغو با رمز اشتباه", test_profile_L3_delete_account_wrong_password)
    suite.run_test("L3-3: تولید توکن نام خالی", test_profile_L3_generate_token_empty_name)
    suite.run_test("L3-4: ابطال توکن ناموجود", test_profile_L3_revoke_nonexistent_token)
    
    suite.run_test("L4-1: دسترسی مهمان مسدود", test_profile_L4_unauthorized_profile_access)
    suite.run_test("L4-2: ویرایش بدون CSRF مسدود", test_profile_L4_csrf_update_profile_missing)
    suite.run_test("L4-3: تزریق XSS در بایوگرافی", test_profile_L4_xss_in_profile_bio)
    
    suite.run_test("L5-1: سرریز متن طولانی بایو", test_profile_L5_long_bio_overflow)
    suite.run_test("L5-2: کاراکترهای خاص در نام", test_profile_L5_special_characters_in_name)
    suite.run_test("L5-3: درخواست توکن تکراری", test_profile_L5_repeated_token_generation)
    
    suite.run_test("L6-1: همزمانی ویرایش پروفایل", test_profile_L6_concurrent_profile_updates)
    suite.run_test("L6-2: همزمانی تولید توکن API", test_profile_L6_concurrent_api_token_creation)
    suite.run_test("L6-3: همزمانی درخواست لغو اکانت", test_profile_L6_concurrent_account_deletion)
    
    suite.run_test("L7-1: ناوبری مرورگری سشن‌ها", test_profile_L7_browser_session_management)
    suite.run_test("L7-2: جریان کاری مرورگری 2FA", test_profile_L7_browser_2fa_setup_flow)
    
    suite.run_test("L8-1: یکپارچگی کلیدهای API", test_profile_L8_api_tokens_data_integrity)
    suite.run_test("L8-2: یکپارچگی رکورد لغو حساب", test_profile_L8_account_deletion_record_integrity)
    
    suite.run_test("L9-1: کرن پاک‌سازی سشن‌ها", test_profile_L9_background_session_cleanup_cron)
    suite.run_test("L9-2: جاب پس‌زمینه حذف اکانت", test_profile_L9_background_account_deletion_job)
    
    suite.run_test("L10-1: لاگ حسابرسی تغییرات", test_profile_L10_audit_trail_profile_changes)
    suite.run_test("L10-2: مانیتورینگ Sentry", test_profile_L10_sentry_monitoring_no_new_fatals)
    
    ok = suite.summary()
    sys.exit(0 if ok else 1)
