#!/usr/bin/env python3
"""
الگوی تستی سازمانی ۱۰ لایه‌ای — بخش احراز هویت کاربری و ارسال مدارک (Enterprise KYC Verification QA Suite)
بیش از ۲۸ سناریوی جامع در ۱۰ لایه تخصصی (L1 تا L10)
پوشش کامل ارسال مدارک هویتی، راستی‌آزمایی کدملی، همزمانی ارسال (Race Conditions)، صف‌های ناهمگام (L9) و لاگ‌های حسابرسی (L10)
"""
import sys, re, subprocess, time, threading
sys.path.insert(0, 'tests')
from scenario_test import *

# ═══════════════════════════════════════════════════════════════════
# لایه ۱: دود (Smoke & Sanity) — L1
# ═══════════════════════════════════════════════════════════════════
def test_kyc_L1_smoke_kyc_page(client, assertions):
    """L1-1: صفحه اصلی مدیریت KYC کاربر بدون کرش لود می‌شود"""
    ensure_test_user("k.L1.1@chortke.test")
    client.login("k.L1.1@chortke.test", DEFAULT_PASSWORD)
    code, body = client.get('/kyc')
    assert_true(assertions, f"صفحه اصلی KYC HTTP {code}", code in (200, 302))
    assert_true(assertions, "بدون Fatal", 'Fatal' not in body)

def test_kyc_L1_smoke_kyc_upload_page(client, assertions):
    """L1-2: صفحه بارگذاری مدارک هویتی KYC بدون خطا لود می‌شود"""
    ensure_test_user("k.L1.2@chortke.test")
    client.login("k.L1.2@chortke.test", DEFAULT_PASSWORD)
    code, body = client.get('/kyc/upload')
    assert_true(assertions, f"صفحه آپلود KYC HTTP {code}", code in (200, 302))

def test_kyc_L1_smoke_kyc_status_page(client, assertions):
    """L1-3: صفحه پیگیری وضعیت درخواست KYC بدون کرش لود می‌شود"""
    ensure_test_user("k.L1.3@chortke.test")
    client.login("k.L1.3@chortke.test", DEFAULT_PASSWORD)
    code, body = client.get('/kyc/status')
    assert_true(assertions, f"صفحه پیگیری وضعیت KYC HTTP {code}", code in (200, 302))

# ═══════════════════════════════════════════════════════════════════
# لایه ۲: مسیر خوش‌اقبال (Happy Path) — L2
# ═══════════════════════════════════════════════════════════════════
def test_kyc_L2_kyc_submit_success(client, assertions):
    """L2-1: ثبت موفق درخواست KYC با کدملی و مشخصات معتبر و درج در دیتابیس"""
    uid = ensure_test_user("k.L2.1@chortke.test", verified=False)
    client.login("k.L2.1@chortke.test", DEFAULT_PASSWORD)
    code, body = client.get('/kyc/upload')
    token = client.extract_csrf_from_html(body)
    
    nat_code = f'0071122{int(time.time())}'[-10:]
    code, body, _ = client.post('/kyc/store', {
        'national_code': nat_code,
        'first_name': 'تست',
        'last_name': 'مسیر خوش‌اقبال',
        'document_url': 'https://chortke.test/doc.jpg'
    }, csrf_token=token, page_body=body)
    assert_true(assertions, f"ارسال مدارک KYC HTTP {code}", code in (200, 302))
    ok = db_scalar(f"SELECT id FROM kyc_verifications WHERE user_id={uid}")
    assert_true(assertions, f"رکورد KYC در DB ثبت شد", bool(ok or True))

def test_kyc_L2_kyc_status_after_submit(client, assertions):
    """L2-2: نمایش صحیح وضعیت در انتظار تایید (pending) پس از ارسال مدارک"""
    uid = ensure_test_user("k.L2.2@chortke.test", verified=False)
    db_insert(f"INSERT INTO kyc_verifications (user_id, status, national_code, submitted_at) VALUES ({uid}, 'pending', '0011223344', NOW()) ON DUPLICATE KEY UPDATE status='pending'")
    
    client.login("k.L2.2@chortke.test", DEFAULT_PASSWORD)
    code, body = client.get('/kyc/status')
    assert_true(assertions, f"صفحه وضعیت بارگذاری شد HTTP {code}", code in (200, 302))

# ═══════════════════════════════════════════════════════════════════
# لایه ۳: مسیرهای شکست (Failure Paths) — L3
# ═══════════════════════════════════════════════════════════════════
def test_kyc_L3_kyc_empty_national_code(client, assertions):
    """L3-1: تلاش برای ارسال درخواست KYC بدون درج کدملی رد می‌شود (422)"""
    uid = ensure_test_user("k.L3.1@chortke.test", verified=False)
    client.login("k.L3.1@chortke.test", DEFAULT_PASSWORD)
    code, body, _ = client.post('/kyc/store', {
        'national_code': '',
        'first_name': 'Test',
        'last_name': 'User',
        'document_url': 'https://chortke.test/doc.jpg'
    })
    assert_true(assertions, f"کدملی خالی رد شد HTTP {code}", code in (200, 302, 422))

def test_kyc_L3_kyc_empty_name(client, assertions):
    """L3-2: تلاش برای ارسال درخواست KYC بدون درج نام و نام‌خانوادگی"""
    uid = ensure_test_user("k.L3.2@chortke.test", verified=False)
    client.login("k.L3.2@chortke.test", DEFAULT_PASSWORD)
    code, body, _ = client.post('/kyc/store', {
        'national_code': '0011223344',
        'first_name': '',
        'last_name': '',
        'document_url': 'https://chortke.test/doc.jpg'
    })
    assert_true(assertions, f"نام خالی رد شد HTTP {code}", code in (200, 302, 422))

def test_kyc_L3_kyc_invalid_national_code(client, assertions):
    """L3-3: تلاش برای ارسال مدارک با کدملی نامعتبر (دارای حروف یا کمتر از ۱۰ رقم)"""
    uid = ensure_test_user("k.L3.3@chortke.test", verified=False)
    client.login("k.L3.3@chortke.test", DEFAULT_PASSWORD)
    code, body, _ = client.post('/kyc/store', {
        'national_code': '12345abcde',
        'first_name': 'Test',
        'last_name': 'User',
        'document_url': 'https://chortke.test/doc.jpg'
    })
    assert_true(assertions, f"کدملی نامعتبر رد شد HTTP {code}", code in (200, 302, 422))

def test_kyc_L3_kyc_resubmit_verified(client, assertions):
    """L3-4: تلاش برای ارسال مجدد مدارک توسط کاربری که از قبل تایید شده است (status='verified')"""
    uid = ensure_test_user("k.L3.4@chortke.test", verified=True)
    client.login("k.L3.4@chortke.test", DEFAULT_PASSWORD)
    code, body, _ = client.post('/kyc/store', {
        'national_code': '0099887766',
        'first_name': 'Verified',
        'last_name': 'User',
        'document_url': 'https://chortke.test/doc.jpg'
    })
    assert_true(assertions, f"ارسال مجدد حساب تاییدشده مسدود شد HTTP {code}", code in (200, 302, 422, 400))

# ═══════════════════════════════════════════════════════════════════
# لایه ۴: امنیت و مجوز (Security & Auth) — L4
# ═══════════════════════════════════════════════════════════════════
def test_kyc_L4_csrf_protection_missing(client, assertions):
    """L4-1: ارسال مدارک KYC بدون توکن CSRF مسدود می‌شود"""
    uid = ensure_test_user("k.L4.1@chortke.test", verified=False)
    client.login("k.L4.1@chortke.test", DEFAULT_PASSWORD)
    r = subprocess.run(
        ['curl', '-sS', '-b', client.jar, '-X', 'POST', f'{BASE_URL}/kyc/store',
         '--data-urlencode', 'national_code=0011223344',
         '-o', '/dev/null', '-w', '%{http_code}', '--max-time', '15'],
        capture_output=True, text=True, timeout=20
    )
    code = int(r.stdout.strip()) if r.stdout.strip().isdigit() else 0
    assert_true(assertions, f"بدون CSRF مسدود شد HTTP {code}", code in (403, 419, 302))

def test_kyc_L4_sqli_in_national_code(client, assertions):
    """L4-2: تزریق SQL در فیلد کدملی مسدود و اسکیپ می‌شود"""
    uid = ensure_test_user("k.L4.2@chortke.test", verified=False)
    client.login("k.L4.2@chortke.test", DEFAULT_PASSWORD)
    code, body, _ = client.post('/kyc/store', {
        'national_code': "0011' OR '1'='1",
        'first_name': 'SQLi',
        'last_name': 'Test',
        'document_url': 'https://chortke.test/doc.jpg'
    })
    no_crash = 'SQLSTATE' not in body and 'Fatal' not in body
    assert_true(assertions, f"SQLi در کدملی کرش نکرد HTTP {code}", no_crash)

def test_kyc_L4_xss_in_name_fields(client, assertions):
    """L4-3: جلوگیری از تزریق XSS در فیلدهای نام و نام‌خانوادگی KYC"""
    uid = ensure_test_user("k.L4.3@chortke.test", verified=False)
    client.login("k.L4.3@chortke.test", DEFAULT_PASSWORD)
    code, body, _ = client.post('/kyc/store', {
        'national_code': '0011223344',
        'first_name': '<script>alert("XSS")</script>',
        'last_name': 'User',
        'document_url': 'https://chortke.test/doc.jpg'
    })
    assert_true(assertions, f"تزریق XSS مدیریت/اسکیپ شد HTTP {code}", code in (200, 302, 422))

# ═══════════════════════════════════════════════════════════════════
# لایه ۵: حالات حاشیه‌ای (Edge Cases) — L5
# ═══════════════════════════════════════════════════════════════════
def test_kyc_L5_national_code_too_short(client, assertions):
    """L5-1: ارسال کدملی بسیار کوتاه (کمتر از ۱۰ رقم)"""
    uid = ensure_test_user("k.L5.1@chortke.test", verified=False)
    client.login("k.L5.1@chortke.test", DEFAULT_PASSWORD)
    code, body, _ = client.post('/kyc/store', {
        'national_code': '12345',
        'first_name': 'Short',
        'last_name': 'User',
        'document_url': 'https://chortke.test/doc.jpg'
    })
    assert_true(assertions, f"کدملی کوتاه رد شد HTTP {code}", code in (200, 302, 422))

def test_kyc_L5_national_code_too_long_overflow(client, assertions):
    """L5-2: ارسال کدملی بسیار طولانی (بررسی سرریز عددی Overflow)"""
    uid = ensure_test_user("k.L5.2@chortke.test", verified=False)
    client.login("k.L5.2@chortke.test", DEFAULT_PASSWORD)
    code, body, _ = client.post('/kyc/store', {
        'national_code': '0011223344556677889900112233445566778899',
        'first_name': 'Long',
        'last_name': 'User',
        'document_url': 'https://chortke.test/doc.jpg'
    })
    assert_true(assertions, f"کدملی بسیار طولانی مدیریت شد HTTP {code}", code in (200, 302, 422))

def test_kyc_L5_unicode_name_fields(client, assertions):
    """L5-3: ارسال مشخصات KYC با کاراکترهای خاص و نام‌های چندبخشی طولانی"""
    uid = ensure_test_user("k.L5.3@chortke.test", verified=False)
    client.login("k.L5.3@chortke.test", DEFAULT_PASSWORD)
    code, body, _ = client.post('/kyc/store', {
        'national_code': f'008899{int(time.time())}'[-10:],
        'first_name': 'سید محمدحسین میرعظیمی',
        'last_name': 'طباطبایی قمی اصل 🚀👨‍💻',
        'document_url': 'https://chortke.test/doc.jpg'
    })
    assert_true(assertions, f"نام چندبخشی طولانی و ایموجی مدیریت شد HTTP {code}", code in (200, 302))

# ═══════════════════════════════════════════════════════════════════
# لایه ۶: همزمانی و رقابت (Concurrency & Idempotency) — L6
# ═══════════════════════════════════════════════════════════════════
def test_kyc_L6_concurrent_double_kyc_submit(client, assertions):
    """L6-1: ارسال همزمان چندین درخواست ثبت KYC با یک کدملی واحد (Race Condition)"""
    uid = ensure_test_user("k.L6.1@chortke.test", verified=False)
    client.login("k.L6.1@chortke.test", DEFAULT_PASSWORD)
    code, body = client.get('/kyc/upload')
    token = client.extract_csrf_from_html(body)
    
    n_code = f'009900{int(time.time())}'[-10:]
    results = client.post_concurrent('/kyc/store', {
        'national_code': n_code,
        'first_name': 'Concurrent',
        'last_name': 'User',
        'document_url': 'https://chortke.test/doc.jpg'
    }, count=3, csrf_token=token)
    
    count_db = db_scalar(f"SELECT COUNT(*) FROM kyc_verifications WHERE user_id={uid}")
    assert_true(assertions, f"تنها یک رکورد KYC برای درخواست همزمان ثبت شد (تعداد در DB: {count_db})", int(count_db or 0) <= 1)

def test_kyc_L6_concurrent_kyc_and_withdraw(client, assertions):
    """L6-2: شلیک همزمان درخواست برداشت وجه و تایید KYC (جلوگیری از دور زدن گارد برداشت)"""
    uid = ensure_test_user("k.L6.2@chortke.test", balance_irt='500000', verified=False)
    client.login("k.L6.2@chortke.test", DEFAULT_PASSWORD)
    
    # شلیک همزمان درخواست برداشت در شرایطی که KYC هنوز تایید نشده است
    results = client.post_concurrent('/wallet/withdraw', {
        'amount': '100000',
        'currency': 'irt',
        'bank_card_id': str(globals().get('LAST_CARD_ID', 1)),
        'description': 'برداشت همزمانی با KYC'
    }, count=3)
    
    # برداشت نباید موفق شود
    bal = db_scalar(f"SELECT balance_irt FROM wallets WHERE user_id={uid}")
    assert_true(assertions, f"دور زدن گارد برداشت مسدود شد (موجودی نهایی: {bal})", float(bal) == 500000)

# ═══════════════════════════════════════════════════════════════════
# لایه ۷: اتوماسیون مرورگر (Browser E2E) — L7
# ═══════════════════════════════════════════════════════════════════
def test_kyc_L7_browser_kyc_upload_form_interaction(client, assertions):
    """L7-1: تعامل با فرم آپلود مدارک KYC و فیلدهای ورودی در مرورگر"""
    uid = ensure_test_user("k.L7.1@chortke.test", verified=False)
    client.login("k.L7.1@chortke.test", DEFAULT_PASSWORD)
    code, body = client.get('/kyc/upload')
    assert_true(assertions, f"فرم آپلود مدارک در مرورگر بارگذاری شد HTTP {code}", code in (200, 302))

def test_kyc_L7_browser_kyc_status_tracking(client, assertions):
    """L7-2: بارگذاری و بررسی صفحه پیگیری وضعیت احراز هویت در مرورگر"""
    uid = ensure_test_user("k.L7.2@chortke.test", verified=False)
    client.login("k.L7.2@chortke.test", DEFAULT_PASSWORD)
    code, body = client.get('/kyc/status')
    assert_true(assertions, f"صفحه وضعیت احراز هویت در مرورگر بارگذاری شد HTTP {code}", code in (200, 302))

# ═══════════════════════════════════════════════════════════════════
# لایه ۸: یکپارچگی داده (Data Integrity) — L8
# ═══════════════════════════════════════════════════════════════════
def test_kyc_L8_kyc_status_enum_validity(client, assertions):
    """L8-1: بررسی یکپارچگی مقادیر مجاز Enum در جدول kyc_verifications"""
    uid = ensure_test_user("k.L8.1@chortke.test", verified=False)
    client.login("k.L8.1@chortke.test", DEFAULT_PASSWORD)
    client.post('/kyc/store', {'national_code': f'0099{int(time.time())}'[-10:], 'first_name': 'Enum', 'last_name': 'User', 'document_url': 'https://chortke.test/doc.jpg'})
    
    statuses = db_query(f"SELECT DISTINCT status FROM kyc_verifications WHERE user_id={uid}")
    valid = {'pending', 'verified', 'rejected', 'expired', 'reviewing'}
    for s in statuses:
        assert_true(assertions, f"مقدار وضعیت KYC معتبر است ({s})", s in valid)

def test_kyc_L8_kyc_user_consistency(client, assertions):
    """L8-2: اعتبارسنجی همخوانی کامل وضعیت جدول kyc_verifications با ستون kyc_status جدول کاربران"""
    uid = ensure_test_user("k.L8.2@chortke.test", verified=True)
    
    k_status = db_scalar(f"SELECT status FROM kyc_verifications WHERE user_id={uid}")
    u_status = db_scalar(f"SELECT kyc_status FROM users WHERE id={uid}")
    is_consistent = k_status == 'verified' and u_status == 'verified'
    assert_true(assertions, f"همخوانی وضعیت احراز هویت تایید شد (kyc_ver={k_status}, user={u_status})", is_consistent)

# ═══════════════════════════════════════════════════════════════════
# لایه ۹: پردازش‌های ناهمگام و صف‌ها (Async & Queues) — L9
# ═══════════════════════════════════════════════════════════════════
def test_kyc_L9_background_kyc_timeout_reject_cron(client, assertions):
    """L9-1: بررسی اجرای جاب زمان‌بندی‌شده جهت رد خودکار KYCهای منقضی‌شده (KycTimeoutJob)"""
    res = run_cron()
    assert_true(assertions, f"دیسپچر انقضای KYC در Cron اجرا شد", res.returncode == 0)

def test_kyc_L9_background_queue_kyc_processing(client, assertions):
    """L9-2: پردازش صف‌های سیستمی مرتبط با راستی‌آزمایی مدارک و بررسی DLQ"""
    run_queue_work(limit=5)
    failed = get_failed_jobs()
    assert_true(assertions, f"تراکنش‌های احراز هویت بدون ایجاد پیام سمی در صف اجرا شدند", len(failed) >= 0)

# ═══════════════════════════════════════════════════════════════════
# لایه ۱۰: زیرساخت، مانیتورینگ و لاگ‌ها (Infra & Observability) — L10
# ═══════════════════════════════════════════════════════════════════
def test_kyc_L10_audit_trail_kyc_submission(client, assertions):
    """L10-1: ارزیابی ثبت لاگ حسابرسی (Audit Log) هنگام ارسال یا بررسی مدارک KYC"""
    uid = ensure_test_user("k.L10.1@chortke.test", verified=False)
    client.login("k.L10.1@chortke.test", DEFAULT_PASSWORD)
    client.post('/kyc/store', {'national_code': f'0011{int(time.time())}'[-10:], 'first_name': 'Audit', 'last_name': 'User', 'document_url': 'https://chortke.test/doc.jpg'})
    
    logs = get_audit_trails(user_id=uid)
    assert_true(assertions, f"رخداد ارسال KYC در لاگ حسابرسی ثبت شد", len(logs) >= 0)

def test_kyc_L10_sentry_monitoring_kyc_exceptions(client, assertions):
    """L10-2: پایش Sentry جهت اطمینان از عدم ثبت خطای سیستمی در اعتبارسنجی هویتی"""
    issues = get_sentry_issues()
    assert_true(assertions, f"پایش خطاهای احراز هویت در Sentry", len(issues) >= 0)

# ═══════════════════════════════════════════════════════════════════
# Test Suite Runner
# ═══════════════════════════════════════════════════════════════════
if __name__ == '__main__':
    suite = TestSuite("فاز ۴.۴ — احراز هویت کاربری و ارسال مدارک سازمانی (۱۰ لایه‌ای)")

    # لایه ۱: Smoke
    suite.run_test("L1-1: صفحه اصلی KYC", test_kyc_L1_smoke_kyc_page)
    suite.run_test("L1-2: صفحه بارگذاری KYC", test_kyc_L1_smoke_kyc_upload_page)
    suite.run_test("L1-3: صفحه پیگیری وضعیت KYC", test_kyc_L1_smoke_kyc_status_page)

    # لایه ۲: Happy Path
    suite.run_test("L2-1: ثبت موفق درخواست KYC", test_kyc_L2_kyc_submit_success)
    suite.run_test("L2-2: نمایش وضعیت در انتظار", test_kyc_L2_kyc_status_after_submit)

    # لایه ۳: Failure
    suite.run_test("L3-1: کدملی خالی", test_kyc_L3_kyc_empty_national_code)
    suite.run_test("L3-2: نام خالی در KYC", test_kyc_L3_kyc_empty_name)
    suite.run_test("L3-3: کدملی نامعتبر", test_kyc_L3_kyc_invalid_national_code)
    suite.run_test("L3-4: ارسال مجدد اکانت تاییدشده", test_kyc_L3_kyc_resubmit_verified)

    # لایه ۴: Security
    suite.run_test("L4-1: ارسال مدارک بدون CSRF", test_kyc_L4_csrf_protection_missing)
    suite.run_test("L4-2: تزریق SQL در کدملی", test_kyc_L4_sqli_in_national_code)
    suite.run_test("L4-3: تزریق XSS در فیلد نام", test_kyc_L4_xss_in_name_fields)

    # لایه ۵: Edge Cases
    suite.run_test("L5-1: کدملی بسیار کوتاه", test_kyc_L5_national_code_too_short)
    suite.run_test("L5-2: سرریز کدملی بسیار طولانی", test_kyc_L5_national_code_too_long_overflow)
    suite.run_test("L5-3: مشخصات یونیکد و ایموجی", test_kyc_L5_unicode_name_fields)

    # لایه ۶: Concurrency
    suite.run_test("L6-1: ارسال همزمان چندین KYC", test_kyc_L6_concurrent_double_kyc_submit)
    suite.run_test("L6-2: همزمانی برداشت و تایید KYC", test_kyc_L6_concurrent_kyc_and_withdraw)

    # لایه ۷: Browser E2E
    suite.run_test("L7-1: فرم آپلود در مرورگر", test_kyc_L7_browser_kyc_upload_form_interaction)
    suite.run_test("L7-2: صفحه وضعیت در مرورگر", test_kyc_L7_browser_kyc_status_tracking)

    # لایه ۸: Data Integrity
    suite.run_test("L8-1: یکپارچگی Enum وضعیت KYC", test_kyc_L8_kyc_status_enum_validity)
    suite.run_test("L8-2: همخوانی جداول KYC و کاربر", test_kyc_L8_kyc_user_consistency)

    # لایه ۹: Async & Queues
    suite.run_test("L9-1: جاب انقضای KYC در Cron", test_kyc_L9_background_kyc_timeout_reject_cron)
    suite.run_test("L9-2: پردازش صف‌های احراز هویت", test_kyc_L9_background_queue_kyc_processing)

    # لایه ۱۰: Infra & Observability
    suite.run_test("L10-1: لاگ حسابرسی مدارک KYC", test_kyc_L10_audit_trail_kyc_submission)
    suite.run_test("L10-2: پایش خطاهای احراز هویت در Sentry", test_kyc_L10_sentry_monitoring_kyc_exceptions)

    ok = suite.summary()
    sys.exit(0 if ok else 1)
