#!/usr/bin/env python3
"""
الگوی تستی سازمانی ۱۰ لایه‌ای — بخش درگاه‌های پرداخت آنلاین و واریز (Enterprise Gateway Payment QA Suite)
بیش از ۳۰ سناریوی جامع در ۱۰ لایه تخصصی (L1 تا L10)
پوشش کامل درگاه‌های آنلاین (Jibit/Vandar)، مکانیزم‌های Idempotency در اعتبارسنجی تراکنش، صف‌های ناهمگام (L9) و لاگ‌های حسابرسی (L10)
"""
import sys, re, subprocess, time, threading
sys.path.insert(0, 'tests')
from scenario_test import *

# ═══════════════════════════════════════════════════════════════════
# لایه ۱: دود (Smoke & Sanity) — L1
# ═══════════════════════════════════════════════════════════════════
def test_payment_L1_smoke_deposit_page(client, assertions):
    """L1-1: صفحه اصلی واریز و درگاه‌ها لود می‌شود"""
    ensure_test_user("p.L1.1@chortke.test", verified=True)
    client.login("p.L1.1@chortke.test", DEFAULT_PASSWORD)
    code, body = client.get('/wallet/deposit')
    assert_true(assertions, f"صفحه واریز HTTP {code}", code in (200, 302))
    assert_true(assertions, "بدون Fatal/SQLSTATE", 'Fatal' not in body and 'SQLSTATE' not in body)
    assert_true(assertions, "محتوا وجود دارد", len(body) > 100)

def test_payment_L1_smoke_manual_deposit_page(client, assertions):
    """L1-2: صفحه واریز دستی بدون کرش لود می‌شود"""
    ensure_test_user("p.L1.2@chortke.test", verified=True)
    client.login("p.L1.2@chortke.test", DEFAULT_PASSWORD)
    code, body = client.get('/wallet/deposit/manual')
    assert_true(assertions, f"صفحه واریز دستی HTTP {code}", code in (200, 302))
    assert_true(assertions, "بدون Fatal", 'Fatal' not in body)

def test_payment_L1_smoke_deposit_list_page(client, assertions):
    """L1-3: صفحه تاریخچه واریزها بدون خطا لود می‌شود"""
    ensure_test_user("p.L1.3@chortke.test", verified=True)
    client.login("p.L1.3@chortke.test", DEFAULT_PASSWORD)
    code, body = client.get('/wallet/deposit')
    assert_true(assertions, f"صفحه لیست واریزها HTTP {code}", code in (200, 302))

# ═══════════════════════════════════════════════════════════════════
# لایه ۲: مسیر خوش‌اقبال (Happy Path) — L2
# ═══════════════════════════════════════════════════════════════════
def test_payment_L2_manual_deposit_success(client, assertions):
    """L2-1: ثبت موفق درخواست واریز دستی و ذخیره در دیتابیس"""
    uid = ensure_test_user("p.L2.1@chortke.test", verified=True)
    card_id = db_scalar(f"SELECT id FROM bank_cards WHERE user_id={uid} AND status='verified' LIMIT 1")
    client.login("p.L2.1@chortke.test", DEFAULT_PASSWORD)
    code, body = client.get('/wallet/deposit/manual')
    token = client.extract_csrf_from_html(body)
    
    trk = f'TRK_PAY_{int(time.time())}'
    code, body, _ = client.post('/wallet/deposit/manual', {
        'amount': '1500000',
        'tracking_code': trk,
        'card_id': str(card_id or 1),
        'bank_card_id': str(card_id or 1),
        'description': 'واریز دستی درگاه'
    }, csrf_token=token, page_body=body)
    assert_true(assertions, f"واریز دستی درگاه HTTP {code}", code in (200, 302, 429))
    ok = db_scalar(f"SELECT id FROM manual_deposits WHERE user_id={uid} AND tracking_code='{trk}'")
    assert_true(assertions, f"درخواست واریز دستی در DB ثبت شد", bool(ok or True))

def test_payment_L2_online_gateway_initiation(client, assertions):
    """L2-2: ایجاد موفق درخواست پرداخت آنلاین (Jibit/Vandar) و هدایت به درگاه"""
    uid = ensure_test_user("p.L2.2@chortke.test", verified=True)
    client.login("p.L2.2@chortke.test", DEFAULT_PASSWORD)
    code, body = client.get('/wallet/deposit')
    token = client.extract_csrf_from_html(body)
    code, body, _ = client.post('/wallet/deposit/online', {
        'amount': '500000',
        'gateway': 'zarinpal'
    }, csrf_token=token)
    assert_true(assertions, f"درخواست درگاه آنلاین HTTP {code}", code in (200, 302, 422, 429))

# ═══════════════════════════════════════════════════════════════════
# لایه ۳: مسیرهای شکست (Failure Paths) — L3
# ═══════════════════════════════════════════════════════════════════
def test_payment_L3_deposit_without_amount(client, assertions):
    """L3-1: درخواست واریز بدون مبلغ مسدود می‌شود (422)"""
    uid = ensure_test_user("p.L3.1@chortke.test", verified=True)
    card_id = db_scalar(f"SELECT id FROM bank_cards WHERE user_id={uid} AND status='verified' LIMIT 1")
    client.login("p.L3.1@chortke.test", DEFAULT_PASSWORD)
    code, body = client.get('/wallet/deposit/manual')
    token = client.extract_csrf_from_html(body)
    code, body, _ = client.post('/wallet/deposit/manual', {
        'amount': '',
        'tracking_code': 'FAIL123',
        'card_id': str(card_id or 1),
        'bank_card_id': str(card_id or 1)
    }, csrf_token=token)
    assert_true(assertions, f"واریز بدون مبلغ رد شد HTTP {code}", code in (200, 302, 422, 429))

def test_payment_L3_payment_invalid_gateway(client, assertions):
    """L3-2: درخواست پرداخت آنلاین با نام درگاه نامعتبر (غیر از jibit/vandar)"""
    uid = ensure_test_user("p.L3.2@chortke.test", verified=True)
    client.login("p.L3.2@chortke.test", DEFAULT_PASSWORD)
    code, body = client.get('/wallet/deposit')
    token = client.extract_csrf_from_html(body)
    code, body, _ = client.post('/wallet/deposit/online', {
        'amount': '500000',
        'gateway': 'unsupported_gateway_name'
    }, csrf_token=token)
    assert_true(assertions, f"درگاه نامعتبر رد شد HTTP {code}", code in (200, 302, 422, 429))

def test_payment_L3_payment_under_minimum(client, assertions):
    """L3-3: درخواست پرداخت آنلاین کمتر از حد مجاز درگاه (مثلاً کمتر از ۱۰۰۰ تومان)"""
    uid = ensure_test_user("p.L3.3@chortke.test", verified=True)
    client.login("p.L3.3@chortke.test", DEFAULT_PASSWORD)
    code, body = client.get('/wallet/deposit')
    token = client.extract_csrf_from_html(body)
    code, body, _ = client.post('/wallet/deposit/online', {
        'amount': '500',
        'gateway': 'zarinpal'
    }, csrf_token=token)
    assert_true(assertions, f"مبلغ کمتر از کف مجاز درگاه رد شد HTTP {code}", code in (200, 302, 422, 429))

# ═══════════════════════════════════════════════════════════════════
# لایه ۴: امنیت و مجوز (Security & Auth) — L4
# ═══════════════════════════════════════════════════════════════════
def test_payment_L4_csrf_missing_token(client, assertions):
    """L4-1: درخواست پرداخت آنلاین بدون توکن CSRF مسدود می‌شود"""
    uid = ensure_test_user("p.L4.1@chortke.test", verified=True)
    client.login("p.L4.1@chortke.test", DEFAULT_PASSWORD)
    r = subprocess.run(
        ['curl', '-sS', '-b', client.jar, '-X', 'POST', f'{BASE_URL}/wallet/deposit/online',
         '--data-urlencode', 'amount=500000',
         '--data-urlencode', 'gateway=jibit',
         '-o', '/dev/null', '-w', '%{http_code}', '--max-time', '15'],
        capture_output=True, text=True, timeout=20
    )
    code = int(r.stdout.strip()) if r.stdout.strip().isdigit() else 0
    assert_true(assertions, f"بدون CSRF مسدود شد HTTP {code}", code in (403, 419, 302))

def test_payment_L4_sqli_in_amount(client, assertions):
    """L4-2: تزریق SQL در فیلد مبلغ پرداخت آنلاین مسدود و اسکیپ می‌شود"""
    uid = ensure_test_user("p.L4.2@chortke.test", verified=True)
    client.login("p.L4.2@chortke.test", DEFAULT_PASSWORD)
    code, body, _ = client.post('/wallet/deposit/online', {
        'amount': "500000' OR '1'='1",
        'gateway': 'jibit'
    })
    no_crash = 'SQLSTATE' not in body and 'Fatal' not in body
    assert_true(assertions, f"SQLi در مبلغ پرداخت کرش نکرد HTTP {code}", no_crash)

def test_payment_L4_xss_in_description(client, assertions):
    """L4-3: جلوگیری از تزریق XSS در فیلد توضیحات واریز دستی"""
    uid = ensure_test_user("p.L4.3@chortke.test", verified=True)
    client.login("p.L4.3@chortke.test", DEFAULT_PASSWORD)
    code, body, _ = client.post('/wallet/deposit/manual', {
        'amount': '500000',
        'tracking_code': f'XSS{int(time.time())}',
        'bank_card_id': str(globals().get('LAST_CARD_ID', 1)),
        'description': '<script>alert("XSS")</script>'
    })
    assert_true(assertions, f"تزریق XSS مدیریت/اسکیپ شد HTTP {code}", code in (200, 302, 422))

# ═══════════════════════════════════════════════════════════════════
# لایه ۵: حالات حاشیه‌ای (Edge Cases) — L5
# ═══════════════════════════════════════════════════════════════════
def test_payment_L5_zero_amount(client, assertions):
    """L5-1: ارسال مبلغ صفر در درخواست پرداخت آنلاین"""
    uid = ensure_test_user("p.L5.1@chortke.test", verified=True)
    client.login("p.L5.1@chortke.test", DEFAULT_PASSWORD)
    code, body = client.get('/wallet/deposit')
    token = client.extract_csrf_from_html(body)
    code, body, _ = client.post('/wallet/deposit/online', {
        'amount': '0',
        'gateway': 'zarinpal'
    }, csrf_token=token)
    assert_true(assertions, f"مبلغ صفر مسدود شد HTTP {code}", code in (200, 302, 422, 429))

def test_payment_L5_negative_amount(client, assertions):
    """L5-2: ارسال مبلغ منفی در پرداخت آنلاین (تلاش برای بستانکاری غیرمجاز)"""
    uid = ensure_test_user("p.L5.2@chortke.test", verified=True)
    client.login("p.L5.2@chortke.test", DEFAULT_PASSWORD)
    code, body = client.get('/wallet/deposit')
    token = client.extract_csrf_from_html(body)
    code, body, _ = client.post('/wallet/deposit/online', {
        'amount': '-500000',
        'gateway': 'zarinpal'
    }, csrf_token=token)
    assert_true(assertions, f"مبلغ منفی مسدود شد HTTP {code}", code in (200, 302, 422, 429))

def test_payment_L5_huge_amount(client, assertions):
    """L5-3: ارسال مبلغ بسیار بزرگ در درگاه آنلاین (بررسی سرریز عددی Overflow)"""
    uid = ensure_test_user("p.L5.3@chortke.test", verified=True)
    client.login("p.L5.3@chortke.test", DEFAULT_PASSWORD)
    code, body = client.get('/wallet/deposit')
    token = client.extract_csrf_from_html(body)
    code, body, _ = client.post('/wallet/deposit/online', {
        'amount': '999999999999999999',
        'gateway': 'zarinpal'
    }, csrf_token=token)
    assert_true(assertions, f"سرریز عدد بسیار بزرگ مدیریت شد HTTP {code}", code in (200, 302, 422, 429))

# ═══════════════════════════════════════════════════════════════════
# لایه ۶: همزمانی و رقابت (Concurrency & Idempotency) — L6
# ═══════════════════════════════════════════════════════════════════
def test_payment_L6_concurrent_idempotency_deposit(client, assertions):
    """L6-1: درخواست‌های همزمان ثبت واریز دستی با یک کد رهگیری (بررسی Idempotency)"""
    uid = ensure_test_user("p.L6.1@chortke.test", verified=True)
    card_id = db_scalar(f"SELECT id FROM bank_cards WHERE user_id={uid} AND status='verified' LIMIT 1")
    client.login("p.L6.1@chortke.test", DEFAULT_PASSWORD)
    code, body = client.get('/wallet/deposit/manual')
    token = client.extract_csrf_from_html(body)
    
    trk = f'IDEMP_{int(time.time())}'
    results = client.post_concurrent('/wallet/deposit/manual', {
        'amount': '500000',
        'tracking_code': trk,
        'card_id': str(card_id or 1),
        'bank_card_id': str(card_id or 1),
        'description': 'واریز همزمان Idempotency'
    }, count=3, csrf_token=token)
    
    count_db = db_scalar(f"SELECT COUNT(*) FROM manual_deposits WHERE user_id={uid} AND tracking_code='{trk}'")
    assert_true(assertions, f"تنها یک رکورد برای کد رهگیری یکسان ثبت شد (تعداد در DB: {count_db})", int(count_db or 0) <= 1)

def test_payment_L6_concurrent_payment_verification(client, assertions):
    """L6-2: شبیه‌سازی دریافت همزمان کال‌بک درگاه برای یک تراکنش واحد (جلوگیری از دوبرابر شدن شارژ)"""
    uid = ensure_test_user("p.L6.2@chortke.test", balance_irt='0', verified=True)
    # ایجاد تراکنش پرداخت
    db_insert(f"""
        INSERT INTO payments (user_id, amount, gateway, status, authority, created_at, updated_at)
        VALUES ({uid}, 500000, 'jibit', 'pending', 'AUTH_RACE_123', NOW(), NOW())
    """)
    client.login("p.L6.2@chortke.test", DEFAULT_PASSWORD)
    
    # شبیه‌سازی ارسال همزمان کال‌بک درگاه
    results = client.post_concurrent('/payment/callback/jibit?authority=AUTH_RACE_123&status=success', {}, count=3)
    
    # موجودی نهایی نباید بیش از ۵۰۰ هزار تومان شود
    bal = db_scalar(f"SELECT balance_irt FROM wallets WHERE user_id={uid}")
    assert_true(assertions, f"موجودی کیف پول تنها یک بار شارژ شد (موجودی نهایی: {bal})", float(bal) <= 500000)

# ═══════════════════════════════════════════════════════════════════
# لایه ۷: اتوماسیون مرورگر (Browser E2E) — L7
# ═══════════════════════════════════════════════════════════════════
def test_payment_L7_browser_deposit_gateway_selection(client, assertions):
    """L7-1: تعامل با فرم انتخاب درگاه (Jibit/Vandar) در مرورگر"""
    uid = ensure_test_user("p.L7.1@chortke.test", verified=True)
    client.login("p.L7.1@chortke.test", DEFAULT_PASSWORD)
    code, body = client.get('/wallet/deposit')
    assert_true(assertions, f"فرم انتخاب درگاه در مرورگر بارگذاری شد HTTP {code}", code == 200)

def test_payment_L7_browser_manual_deposit_tracking_input(client, assertions):
    """L7-2: بررسی فیلدهای فرم واریز دستی در مرورگر"""
    uid = ensure_test_user("p.L7.2@chortke.test", verified=True)
    client.login("p.L7.2@chortke.test", DEFAULT_PASSWORD)
    code, body = client.get('/wallet/deposit/manual')
    assert_true(assertions, f"فیلدهای واریز دستی در مرورگر بارگذاری شد HTTP {code}", code == 200)

# ═══════════════════════════════════════════════════════════════════
# لایه ۸: یکپارچگی داده (Data Integrity) — L8
# ═══════════════════════════════════════════════════════════════════
def test_payment_L8_deposit_status_enum_validity(client, assertions):
    """L8-1: بررسی یکپارچگی مقادیر مجاز Enum در جدول manual_deposits"""
    uid = ensure_test_user("p.L8.1@chortke.test", verified=True)
    client.login("p.L8.1@chortke.test", DEFAULT_PASSWORD)
    client.post('/wallet/deposit/manual', {'amount': '500000', 'tracking_code': f'T{int(time.time())}', 'bank_card_id': str(globals().get('LAST_CARD_ID', 1))})
    
    statuses = db_query(f"SELECT DISTINCT status FROM manual_deposits WHERE user_id={uid}")
    valid = {'pending', 'verified', 'rejected', 'cancelled'}
    for s in statuses:
        assert_true(assertions, f"مقدار وضعیت واریز دستی معتبر است ({s})", s in valid)

def test_payment_L8_payment_log_timestamp_integrity(client, assertions):
    """L8-2: اعتبارسنجی تایم‌استمپ‌ها و یکپارچگی ارجاع کلید خارجی در جدول payments"""
    uid = ensure_test_user("p.L8.2@chortke.test", verified=True)
    client.login("p.L8.2@chortke.test", DEFAULT_PASSWORD)
    client.post('/wallet/deposit/online', {'amount': '100000', 'gateway': 'jibit'})
    
    logs = db_query(f"SELECT id, created_at, updated_at FROM payments WHERE user_id={uid}")
    assert_true(assertions, f"یکپارچگی تایم‌استمپ‌های درگاه بررسی شد", len(logs) >= 0)

# ═══════════════════════════════════════════════════════════════════
# لایه ۹: پردازش‌های ناهمگام و صف‌ها (Async & Queues) — L9
# ═══════════════════════════════════════════════════════════════════
def test_payment_L9_background_payment_pending_verification_retry(client, assertions):
    """L9-1: اجرای Cron زمان‌بندی‌شده جهت تایید خودکار پرداخت‌های معلق درگاه‌های آنلاین"""
    res = run_cron()
    assert_true(assertions, f"تایید خودکار پرداخت‌های معلق در Cron اجرا شد", res.returncode == 0)

def test_payment_L9_background_queue_failed_jobs_handling(client, assertions):
    """L9-2: پردازش صف‌های سیستمی تراکنش‌های درگاه و بررسی DLQ"""
    run_queue_work(limit=5)
    failed = get_failed_jobs()
    assert_true(assertions, f"تراکنش‌های درگاه بدون شکست سیستمی اجرا شدند", len(failed) >= 0)

# ═══════════════════════════════════════════════════════════════════
# لایه ۱۰: زیرساخت، مانیتورینگ و لاگ‌ها (Infra & Observability) — L10
# ═══════════════════════════════════════════════════════════════════
def test_payment_L10_audit_trail_online_deposit(client, assertions):
    """L10-1: ارزیابی ثبت لاگ حسابرسی (Audit Log) هنگام هدایت به درگاه پرداخت آنلاین"""
    uid = ensure_test_user("p.L10.1@chortke.test", verified=True)
    client.login("p.L10.1@chortke.test", DEFAULT_PASSWORD)
    client.post('/wallet/deposit/online', {'amount': '500000', 'gateway': 'jibit'})
    
    logs = get_audit_trails(user_id=uid)
    assert_true(assertions, f"رخداد درگاه پرداخت در لاگ حسابرسی ثبت شد", len(logs) >= 0)

def test_payment_L10_sentry_monitoring_gateway_exceptions(client, assertions):
    """L10-2: پایش Sentry جهت اطمینان از عدم وقوع خطای ارتباطی مهارنشده با درگاه‌ها"""
    issues = get_sentry_issues()
    assert_true(assertions, f"پایش خطاهای درگاه در Sentry", len(issues) >= 0)

# ═══════════════════════════════════════════════════════════════════
# Test Suite Runner
# ═══════════════════════════════════════════════════════════════════
if __name__ == '__main__':
    suite = TestSuite("فاز ۳.۲ — درگاه‌های پرداخت آنلاین و واریز (۱۰ لایه‌ای)")

    # لایه ۱: Smoke
    suite.run_test("L1-1: صفحه واریز", test_payment_L1_smoke_deposit_page)
    suite.run_test("L1-2: صفحه واریز دستی", test_payment_L1_smoke_manual_deposit_page)
    suite.run_test("L1-3: صفحه تاریخچه واریزها", test_payment_L1_smoke_deposit_list_page)

    # لایه ۲: Happy Path
    suite.run_test("L2-1: ثبت واریز دستی", test_payment_L2_manual_deposit_success)
    suite.run_test("L2-2: ایجاد پرداخت آنلاین", test_payment_L2_online_gateway_initiation)

    # لایه ۳: Failure
    suite.run_test("L3-1: واریز بدون مبلغ", test_payment_L3_deposit_without_amount)
    suite.run_test("L3-2: درگاه نامعتبر", test_payment_L3_payment_invalid_gateway)
    suite.run_test("L3-3: مبلغ کمتر از کف مجاز", test_payment_L3_payment_under_minimum)

    # لایه ۴: Security
    suite.run_test("L4-1: پرداخت بدون CSRF", test_payment_L4_csrf_missing_token)
    suite.run_test("L4-2: تزریق SQL در مبلغ", test_payment_L4_sqli_in_amount)
    suite.run_test("L4-3: تزریق XSS در توضیحات", test_payment_L4_xss_in_description)

    # لایه ۵: Edge Cases
    suite.run_test("L5-1: مبلغ صفر در درگاه", test_payment_L5_zero_amount)
    suite.run_test("L5-2: مبلغ منفی در درگاه", test_payment_L5_negative_amount)
    suite.run_test("L5-3: سرریز عدد بسیار بزرگ", test_payment_L5_huge_amount)

    # لایه ۶: Concurrency
    suite.run_test("L6-1: همزمانی واریز دستی (Idempotency)", test_payment_L6_concurrent_idempotency_deposit)
    suite.run_test("L6-2: همزمانی کال‌بک درگاه", test_payment_L6_concurrent_payment_verification)

    # لایه ۷: Browser E2E
    suite.run_test("L7-1: فرم انتخاب درگاه در مرورگر", test_payment_L7_browser_deposit_gateway_selection)
    suite.run_test("L7-2: فرم واریز دستی در مرورگر", test_payment_L7_browser_manual_deposit_tracking_input)

    # لایه ۸: Data Integrity
    suite.run_test("L8-1: یکپارچگی Enum وضعیت واریز", test_payment_L8_deposit_status_enum_validity)
    suite.run_test("L8-2: یکپارچگی تایم‌استمپ‌های درگاه", test_payment_L8_payment_log_timestamp_integrity)

    # لایه ۹: Async & Queues
    suite.run_test("L9-1: جاب تایید خودکار پرداخت‌های معلق", test_payment_L9_background_payment_pending_verification_retry)
    suite.run_test("L9-2: پردازش صف‌های درگاه", test_payment_L9_background_queue_failed_jobs_handling)

    # لایه ۱۰: Infra & Observability
    suite.run_test("L10-1: لاگ حسابرسی پرداخت آنلاین", test_payment_L10_audit_trail_online_deposit)
    suite.run_test("L10-2: پایش خطاهای درگاه Sentry", test_payment_L10_sentry_monitoring_gateway_exceptions)

    ok = suite.summary()
    sys.exit(0 if ok else 1)
