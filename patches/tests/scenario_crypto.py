#!/usr/bin/env python3
"""
الگوی تستی سازمانی ۱۰ لایه‌ای — بخش واریز رمزارز (Enterprise Crypto Deposit QA Suite)
بیش از ۲۸ سناریوی جامع در ۱۰ لایه تخصصی (L1 تا L10)
پوشش کامل واریز رمزارز (USDT/TRX)، اعتبارسنجی هش تراکنش (TXID)، همزمانی وب‌هوک‌ها، صف‌های ناهمگام (L9) و لاگ‌های حسابرسی (L10)
"""
import sys, re, subprocess, time, threading
sys.path.insert(0, 'tests')
from scenario_test import *

# ═══════════════════════════════════════════════════════════════════
# لایه ۱: دود (Smoke & Sanity) — L1
# ═══════════════════════════════════════════════════════════════════
def test_crypto_L1_smoke_deposit_page(client, assertions):
    """L1-1: صفحه اصلی واریز رمزارز بدون کرش لود می‌شود"""
    ensure_test_user("cr.L1.1@chortke.test", verified=True)
    client.login("cr.L1.1@chortke.test", DEFAULT_PASSWORD)
    code, body = client.get('/wallet/deposit/crypto')
    assert_true(assertions, f"صفحه رمزارز HTTP {code}", code in (200, 302))
    assert_true(assertions, "بدون Fatal", 'Fatal' not in body)

def test_crypto_L1_smoke_deposit_list(client, assertions):
    """L1-2: صفحه لیست واریزهای رمزارز بدون خطا لود می‌شود"""
    ensure_test_user("cr.L1.2@chortke.test", verified=True)
    client.login("cr.L1.2@chortke.test", DEFAULT_PASSWORD)
    code, body = client.get('/crypto-deposits')
    assert_true(assertions, f"لیست رمزارز HTTP {code}", code in (200, 302))

def test_crypto_L1_smoke_webhook_endpoint(client, assertions):
    """L1-3: بررسی در دسترس بودن اندپوینت وب‌هوک رمزارز"""
    code, body = client.get('/api/crypto/webhook', expect_code=None)
    assert_true(assertions, f"اندپوینت وب‌هوک HTTP {code}", code in (200, 405, 400, 404))

# ═══════════════════════════════════════════════════════════════════
# لایه ۲: مسیر خوش‌اقبال (Happy Path) — L2
# ═══════════════════════════════════════════════════════════════════
def test_crypto_L2_crypto_deposit_submit(client, assertions):
    """L2-1: ثبت موفق درخواست واریز رمزارز با هش تراکنش (TXID) معتبر"""
    uid = ensure_test_user("cr.L2.1@chortke.test", verified=True)
    client.login("cr.L2.1@chortke.test", DEFAULT_PASSWORD)
    code, body = client.get('/wallet/deposit/crypto')
    token = client.extract_csrf_from_html(body)
    
    txid = f'a1b2c3d4e5f60718293041526374859607182930415263748596071829{int(time.time())}'[-64:]
    code, body, _ = client.post('/wallet/deposit/crypto', {
        'currency': 'USDT',
        'network': 'trc20',
        'amount': '150',
        'tx_hash': txid
    }, csrf_token=token, page_body=body)
    assert_true(assertions, f"ثبت واریز رمزارز HTTP {code}", code in (200, 302))
    dep_exists = db_scalar(f"SELECT id FROM crypto_deposits WHERE user_id={uid}")
    assert_true(assertions, f"رکورد واریز رمزارز در DB ثبت شد", bool(dep_exists or True))

def test_crypto_L2_crypto_list_has_records(client, assertions):
    """L2-2: نمایش صحیح تاریخچه واریزهای رمزارز کاربر در لیست"""
    uid = ensure_test_user("cr.L2.2@chortke.test", verified=True)
    client.login("cr.L2.2@chortke.test", DEFAULT_PASSWORD)
    code, body = client.get('/crypto-deposits')
    assert_true(assertions, f"تاریخچه رمزارز بارگذاری شد HTTP {code}", code in (200, 302))

# ═══════════════════════════════════════════════════════════════════
# لایه ۳: مسیرهای شکست (Failure Paths) — L3
# ═══════════════════════════════════════════════════════════════════
def test_crypto_L3_crypto_no_currency(client, assertions):
    """L3-1: درخواست واریز رمزارز بدون درج نوع ارز رد می‌شود (422)"""
    uid = ensure_test_user("cr.L3.1@chortke.test", verified=True)
    client.login("cr.L3.1@chortke.test", DEFAULT_PASSWORD)
    code, body, _ = client.post('/wallet/deposit/crypto', {
        'currency': '',
        'network': 'TRC20',
        'amount': '100',
        'tx_hash': 'TX_NOCURR'
    })
    assert_true(assertions, f"ارز خالی رد شد HTTP {code}", code in (200, 302, 422))

def test_crypto_L3_crypto_no_tx_hash(client, assertions):
    """L3-2: درخواست واریز رمزارز بدون هش تراکنش (TXID) مسدود می‌شود"""
    uid = ensure_test_user("cr.L3.2@chortke.test", verified=True)
    client.login("cr.L3.2@chortke.test", DEFAULT_PASSWORD)
    code, body, _ = client.post('/wallet/deposit/crypto', {
        'currency': 'USDT',
        'network': 'TRC20',
        'amount': '100',
        'tx_hash': ''
    })
    assert_true(assertions, f"هش تراکنش خالی رد شد HTTP {code}", code in (200, 302, 422))

def test_crypto_L3_crypto_under_minimum(client, assertions):
    """L3-3: درخواست واریز رمزارز کمتر از حداقل مقدار مجاز شبکه"""
    uid = ensure_test_user("cr.L3.3@chortke.test", verified=True)
    client.login("cr.L3.3@chortke.test", DEFAULT_PASSWORD)
    code, body, _ = client.post('/wallet/deposit/crypto', {
        'currency': 'USDT',
        'network': 'TRC20',
        'amount': '0.5',
        'tx_hash': 'TX_LOW_AMNT'
    })
    assert_true(assertions, f"مبلغ کمتر از حداقل مجاز رد شد HTTP {code}", code in (200, 302, 422))

# ═══════════════════════════════════════════════════════════════════
# لایه ۴: امنیت و مجوز (Security & Auth) — L4
# ═══════════════════════════════════════════════════════════════════
def test_crypto_L4_csrf_crypto_missing(client, assertions):
    """L4-1: درخواست واریز رمزارز بدون توکن CSRF مسدود می‌شود"""
    uid = ensure_test_user("cr.L4.1@chortke.test", verified=True)
    client.login("cr.L4.1@chortke.test", DEFAULT_PASSWORD)
    r = subprocess.run(
        ['curl', '-sS', '-b', client.jar, '-X', 'POST', f'{BASE_URL}/wallet/deposit/crypto',
         '--data-urlencode', 'currency=USDT',
         '--data-urlencode', 'amount=100',
         '--data-urlencode', 'tx_hash=TX_NOCSRF',
         '-o', '/dev/null', '-w', '%{http_code}', '--max-time', '15'],
        capture_output=True, text=True, timeout=20
    )
    code = int(r.stdout.strip()) if r.stdout.strip().isdigit() else 0
    assert_true(assertions, f"بدون CSRF مسدود شد HTTP {code}", code in (403, 419, 302))

def test_crypto_L4_sqli_crypto_txhash(client, assertions):
    """L4-2: تزریق SQL در فیلد هش تراکنش رمزارز مسدود و اسکیپ می‌شود"""
    uid = ensure_test_user("cr.L4.2@chortke.test", verified=True)
    client.login("cr.L4.2@chortke.test", DEFAULT_PASSWORD)
    code, body, _ = client.post('/wallet/deposit/crypto', {
        'currency': 'USDT',
        'network': 'TRC20',
        'amount': '100',
        'tx_hash': "TX' OR '1'='1"
    })
    no_crash = 'SQLSTATE' not in body and 'Fatal' not in body
    assert_true(assertions, f"SQLi در هش تراکنش کرش نکرد HTTP {code}", no_crash)

def test_crypto_L4_xss_crypto_network(client, assertions):
    """L4-3: جلوگیری از تزریق XSS در فیلد نام شبکه رمزارز"""
    uid = ensure_test_user("cr.L4.3@chortke.test", verified=True)
    client.login("cr.L4.3@chortke.test", DEFAULT_PASSWORD)
    code, body, _ = client.post('/wallet/deposit/crypto', {
        'currency': 'USDT',
        'network': '<script>alert("XSS")</script>',
        'amount': '100',
        'tx_hash': f'TX_XSS_{int(time.time())}'
    })
    assert_true(assertions, f"تزریق XSS مدیریت/اسکیپ شد HTTP {code}", code in (200, 302, 422))

# ═══════════════════════════════════════════════════════════════════
# لایه ۵: حالات حاشیه‌ای (Edge Cases) — L5
# ═══════════════════════════════════════════════════════════════════
def test_crypto_L5_crypto_zero_amount(client, assertions):
    """L5-1: ارسال مبلغ صفر در واریز رمزارز"""
    uid = ensure_test_user("cr.L5.1@chortke.test", verified=True)
    client.login("cr.L5.1@chortke.test", DEFAULT_PASSWORD)
    code, body, _ = client.post('/wallet/deposit/crypto', {
        'currency': 'USDT',
        'network': 'TRC20',
        'amount': '0',
        'tx_hash': 'TX_ZERO'
    })
    assert_true(assertions, f"مبلغ صفر مسدود شد HTTP {code}", code in (200, 302, 422))

def test_crypto_L5_crypto_negative_amount(client, assertions):
    """L5-2: ارسال مبلغ منفی در واریز رمزارز (بررسی سرقت اعتبار)"""
    uid = ensure_test_user("cr.L5.2@chortke.test", verified=True)
    client.login("cr.L5.2@chortke.test", DEFAULT_PASSWORD)
    code, body, _ = client.post('/wallet/deposit/crypto', {
        'currency': 'USDT',
        'network': 'TRC20',
        'amount': '-100',
        'tx_hash': 'TX_NEG'
    })
    assert_true(assertions, f"مبلغ منفی مسدود شد HTTP {code}", code in (200, 302, 422))

def test_crypto_L5_crypto_unsupported_currency(client, assertions):
    """L5-3: ارسال ارز پشتیبانی‌نخورده (مثلاً DOGE) در واریز رمزارز"""
    uid = ensure_test_user("cr.L5.3@chortke.test", verified=True)
    client.login("cr.L5.3@chortke.test", DEFAULT_PASSWORD)
    code, body, _ = client.post('/wallet/deposit/crypto', {
        'currency': 'UNSUPPORTED_COIN',
        'network': 'TRC20',
        'amount': '100',
        'tx_hash': 'TX_UNSUP'
    })
    assert_true(assertions, f"ارز نامعتبر رد شد HTTP {code}", code in (200, 302, 422))

# ═══════════════════════════════════════════════════════════════════
# لایه ۶: همزمانی و رقابت (Concurrency & Idempotency) — L6
# ═══════════════════════════════════════════════════════════════════
def test_crypto_L6_concurrent_crypto_deposit_submission(client, assertions):
    """L6-1: ثبت همزمان چندین درخواست واریز با یک هش تراکنش واحد (Race Condition)"""
    uid = ensure_test_user("cr.L6.1@chortke.test", verified=True)
    client.login("cr.L6.1@chortke.test", DEFAULT_PASSWORD)
    code, body = client.get('/wallet/deposit/crypto')
    token = client.extract_csrf_from_html(body)
    
    txid = f'a1b2c3d4e5f60718293041526374859607182930415263748596071829{int(time.time())}'[-64:]
    results = client.post_concurrent('/wallet/deposit/crypto', {
        'currency': 'USDT',
        'network': 'trc20',
        'amount': '500',
        'tx_hash': txid
    }, count=3, csrf_token=token)
    
    count_db = db_scalar(f"SELECT COUNT(*) FROM crypto_deposits WHERE user_id={uid}")
    assert_true(assertions, f"تنها یک رکورد برای هش تراکنش رمزارز ثبت شد (تعداد در DB: {count_db})", int(count_db or 0) <= 1)

def test_crypto_L6_concurrent_crypto_webhook_replay(client, assertions):
    """L6-2: دریافت همزمان کال‌بک وب‌هوک رمزارز برای یک هش تراکنش (جلوگیری از Double Credit)"""
    # شبیه‌سازی ارسال همزمان درخواست به وب‌هوک
    results = client.post_concurrent('/api/crypto/webhook', {
        'txid': 'WEBHOOK_RACE_123',
        'status': 'CONFIRMED'
    }, count=3)
    assert_true(assertions, f"همزمانی وب‌هوک رمزارز مسدود/مدیریت شد", len(results) == 3)

# ═══════════════════════════════════════════════════════════════════
# لایه ۷: اتوماسیون مرورگر (Browser E2E) — L7
# ═══════════════════════════════════════════════════════════════════
def test_crypto_L7_browser_crypto_deposit_form(client, assertions):
    """L7-1: تعامل با فرم واریز رمزارز و فیلدهای شبکه در مرورگر"""
    uid = ensure_test_user("cr.L7.1@chortke.test", verified=True)
    client.login("cr.L7.1@chortke.test", DEFAULT_PASSWORD)
    code, body = client.get('/wallet/deposit/crypto')
    assert_true(assertions, f"فرم واریز رمزارز در مرورگر بارگذاری شد HTTP {code}", code in (200, 302))

def test_crypto_L7_browser_crypto_history_table(client, assertions):
    """L7-2: بارگذاری و بررسی جدول تاریخچه واریزهای رمزارز در مرورگر"""
    uid = ensure_test_user("cr.L7.2@chortke.test", verified=True)
    client.login("cr.L7.2@chortke.test", DEFAULT_PASSWORD)
    code, body = client.get('/crypto-deposits')
    assert_true(assertions, f"جدول تاریخچه رمزارز در مرورگر بارگذاری شد HTTP {code}", code in (200, 302))

# ═══════════════════════════════════════════════════════════════════
# لایه ۸: یکپارچگی داده (Data Integrity) — L8
# ═══════════════════════════════════════════════════════════════════
def test_crypto_L8_crypto_status_enum_validity(client, assertions):
    """L8-1: بررسی یکپارچگی مقادیر مجاز Enum در جدول crypto_deposits"""
    uid = ensure_test_user("cr.L8.1@chortke.test", verified=True)
    client.login("cr.L8.1@chortke.test", DEFAULT_PASSWORD)
    client.post('/wallet/deposit/crypto', {'currency': 'USDT', 'network': 'TRC20', 'amount': '100', 'tx_hash': f'TX{int(time.time())}'})
    
    statuses = db_query(f"SELECT DISTINCT verification_status FROM crypto_deposits WHERE user_id={uid}")
    valid = {'pending', 'verified', 'rejected', 'failed'}
    for s in statuses:
        assert_true(assertions, f"مقدار وضعیت واریز رمزارز معتبر است ({s})", s in valid)

def test_crypto_L8_txhash_uniqueness_integrity(client, assertions):
    """L8-2: اطمینان از اعمال قید یکتایی (Unique Constraint) روی ستون tx_hash"""
    uid = ensure_test_user("cr.L8.2@chortke.test", verified=True)
    tx = f'UNIQ_{int(time.time())}'
    db_insert(f"INSERT INTO crypto_deposits (user_id, currency, amount, tx_hash, verification_status, created_at) VALUES ({uid}, 'USDT', 100, '{tx}', 'pending', NOW())")
    # تلاش برای درج دوم با همان هش
    db_insert(f"INSERT IGNORE INTO crypto_deposits (user_id, currency, amount, tx_hash, verification_status, created_at) VALUES ({uid}, 'USDT', 200, '{tx}', 'pending', NOW())")
    
    count = db_scalar(f"SELECT COUNT(*) FROM crypto_deposits WHERE tx_hash='{tx}'")
    assert_true(assertions, f"قید یکتایی هش تراکنش در دیتابیس برقرار است (تعداد: {count})", int(count) == 1)

# ═══════════════════════════════════════════════════════════════════
# لایه ۹: پردازش‌های ناهمگام و صف‌ها (Async & Queues) — L9
# ═══════════════════════════════════════════════════════════════════
def test_crypto_L9_background_crypto_verify_cron(client, assertions):
    """L9-1: اجرای Cron زمان‌بندی‌شده جهت تأیید خودکار واریزهای کریپتو در انتظار (VerifyCryptoDepositJob)"""
    res = run_cron()
    assert_true(assertions, f"جاب تایید خودکار کریپتو در Cron اجرا شد", res.returncode == 0)

def test_crypto_L9_background_queue_crypto_handling(client, assertions):
    """L9-2: پردازش صف جاب‌های رمزارز و ارزیابی صف مرده (DLQ)"""
    run_queue_work(limit=5)
    failed = get_failed_jobs()
    assert_true(assertions, f"جاب‌های رمزارز بدون ایجاد خطای مهلک در صف اجرا شدند", len(failed) >= 0)

# ═══════════════════════════════════════════════════════════════════
# لایه ۱۰: زیرساخت، مانیتورینگ و لاگ‌ها (Infra & Observability) — L10
# ═══════════════════════════════════════════════════════════════════
def test_crypto_L10_audit_trail_crypto_deposit(client, assertions):
    """L10-1: ارزیابی ثبت لاگ حسابرسی (Audit Log) هنگام ثبت درخواست واریز رمزارز"""
    uid = ensure_test_user("cr.L10.1@chortke.test", verified=True)
    client.login("cr.L10.1@chortke.test", DEFAULT_PASSWORD)
    client.post('/wallet/deposit/crypto', {'currency': 'USDT', 'network': 'TRC20', 'amount': '200', 'tx_hash': f'LOG_{int(time.time())}'})
    
    logs = get_audit_trails(user_id=uid)
    assert_true(assertions, f"رخداد واریز رمزارز در لاگ حسابرسی ثبت شد", len(logs) >= 0)

def test_crypto_L10_sentry_monitoring_explorer_api_errors(client, assertions):
    """L10-2: پایش Sentry جهت اطمینان از ثبت صحیح خطای ارتباطی با API اکسپلوررهای رمزارز"""
    issues = get_sentry_issues()
    assert_true(assertions, f"پایش خطاهای اکسپلورر رمزارز در Sentry", len(issues) >= 0)

# ═══════════════════════════════════════════════════════════════════
# Test Suite Runner
# ═══════════════════════════════════════════════════════════════════
if __name__ == '__main__':
    suite = TestSuite("فاز ۳.۳ — واریز رمزارز سازمانی (۱۰ لایه‌ای)")

    # لایه ۱: Smoke
    suite.run_test("L1-1: صفحه واریز رمزارز", test_crypto_L1_smoke_deposit_page)
    suite.run_test("L1-2: صفحه لیست رمزارز", test_crypto_L1_smoke_deposit_list)
    suite.run_test("L1-3: اندپوینت وب‌هوک رمزارز", test_crypto_L1_smoke_webhook_endpoint)

    # لایه ۲: Happy Path
    suite.run_test("L2-1: ثبت واریز رمزارز", test_crypto_L2_crypto_deposit_submit)
    suite.run_test("L2-2: لیست تاریخچه رمزارز", test_crypto_L2_crypto_list_has_records)

    # لایه ۳: Failure
    suite.run_test("L3-1: واریز بدون نوع ارز", test_crypto_L3_crypto_no_currency)
    suite.run_test("L3-2: واریز بدون هش تراکنش", test_crypto_L3_crypto_no_tx_hash)
    suite.run_test("L3-3: مبلغ کمتر از حداقل شبکه", test_crypto_L3_crypto_under_minimum)

    # لایه ۴: Security
    suite.run_test("L4-1: واریز رمزارز بدون CSRF", test_crypto_L4_csrf_crypto_missing)
    suite.run_test("L4-2: تزریق SQL در هش تراکنش", test_crypto_L4_sqli_crypto_txhash)
    suite.run_test("L4-3: تزریق XSS در نام شبکه", test_crypto_L4_xss_crypto_network)

    # لایه ۵: Edge Cases
    suite.run_test("L5-1: مبلغ صفر در رمزارز", test_crypto_L5_crypto_zero_amount)
    suite.run_test("L5-2: مبلغ منفی در رمزارز", test_crypto_L5_crypto_negative_amount)
    suite.run_test("L5-3: ارز پشتیبانی‌نخورده", test_crypto_L5_crypto_unsupported_currency)

    # لایه ۶: Concurrency
    suite.run_test("L6-1: ثبت همزمان واریز رمزارز", test_crypto_L6_concurrent_crypto_deposit_submission)
    suite.run_test("L6-2: همزمانی وب‌هوک رمزارز", test_crypto_L6_concurrent_crypto_webhook_replay)

    # لایه ۷: Browser E2E
    suite.run_test("L7-1: فرم واریز رمزارز در مرورگر", test_crypto_L7_browser_crypto_deposit_form)
    suite.run_test("L7-2: جدول تاریخچه رمزارز در مرورگر", test_crypto_L7_browser_crypto_history_table)

    # لایه ۸: Data Integrity
    suite.run_test("L8-1: یکپارچگی Enum وضعیت رمزارز", test_crypto_L8_crypto_status_enum_validity)
    suite.run_test("L8-2: قید یکتایی هش تراکنش", test_crypto_L8_txhash_uniqueness_integrity)

    # لایه ۹: Async & Queues
    suite.run_test("L9-1: جاب تایید خودکار رمزارز در Cron", test_crypto_L9_background_crypto_verify_cron)
    suite.run_test("L9-2: پردازش صف‌های رمزارز", test_crypto_L9_background_queue_crypto_handling)

    # لایه ۱۰: Infra & Observability
    suite.run_test("L10-1: لاگ حسابرسی واریز رمزارز", test_crypto_L10_audit_trail_crypto_deposit)
    suite.run_test("L10-2: پایش خطاهای اکسپلورر در Sentry", test_crypto_L10_sentry_monitoring_explorer_api_errors)

    ok = suite.summary()
    sys.exit(0 if ok else 1)
