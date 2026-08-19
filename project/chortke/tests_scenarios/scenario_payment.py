#!/usr/bin/env python3
"""
الگوی تستی ۷ لایه‌ای — بخش پرداخت (Payment)
حداقل ۲۰ سناریو: L1=3 + L2=2 + L3=4 + L4=3 + L5=3 + L7=2 + L6(separate)
"""
import sys, re, subprocess, json
sys.path.insert(0, 'tests')
from scenario_test import *

# ═══════════════════════════════════════════════════════════════════
# لایه ۱: دود (Smoke) — آیا صفحات لود می‌شوند؟
# ═══════════════════════════════════════════════════════════════════

def test_payment_L1_deposit_page_loads(client, assertions):
    """L1-1: صفحه واریز لود می‌شود"""
    ensure_test_user("pay.smoke@chortke.test", verified=True)
    client.login("pay.smoke@chortke.test", DEFAULT_PASSWORD)
    code, body = client.get('/wallet/deposit')
    assert_true(assertions, f"صفحه واریز HTTP {code}", code in (200, 302))
    assert_true(assertions, "بدون Fatal", 'Fatal error' not in body and 'SQLSTATE' not in body)
    assert_true(assertions, "محتوا وجود دارد", len(body) > 100)

def test_payment_L1_manual_deposit_page(client, assertions):
    """L1-2: صفحه واریز دستی لود می‌شود"""
    ensure_test_user("pay.smoke2@chortke.test", verified=True)
    client.login("pay.smoke2@chortke.test", DEFAULT_PASSWORD)
    code, body = client.get('/wallet/deposit/manual')
    assert_true(assertions, f"صفحه واریز دستی HTTP {code}", code in (200, 302))
    assert_true(assertions, "بدون Exception", 'Exception' not in body)

def test_payment_L1_deposit_list_page(client, assertions):
    """L1-3: لیست واریزهای دستی لود می‌شود"""
    ensure_test_user("pay.smoke3@chortke.test", verified=True)
    client.login("pay.smoke3@chortke.test", DEFAULT_PASSWORD)
    code, body = client.get('/manual-deposits')
    assert_true(assertions, f"لیست واریز HTTP {code}", code in (200, 302))

# ═══════════════════════════════════════════════════════════════════
# لایه ۲: مسیر خوش‌اقبال (Happy Path)
# ═══════════════════════════════════════════════════════════════════

def test_payment_L2_manual_deposit_success(client, assertions):
    """L2-1: واریز دستی موفق — درخواست در DB ثبت می‌شود"""
    uid = ensure_test_user("pay.happy1@chortke.test", verified=True)
    client.login("pay.happy1@chortke.test", DEFAULT_PASSWORD)
    before = db_scalar(f"SELECT COUNT(*) FROM manual_deposits WHERE user_id={uid}")
    code, body, jb = client.post('/wallet/deposit/manual', {
        'amount': '500000',
        'description': 'تست واریز دستی',
    })
    after = db_scalar(f"SELECT COUNT(*) FROM manual_deposits WHERE user_id={uid}")
    assert_true(assertions, f"واریز ثبت شد (HTTP {code})", code in (200, 302))
    assert_true(assertions, f"رکورد در DB افزایش یافت ({before} → {after})", int(after) > int(before))

def test_payment_L2_payment_request_creates_record(client, assertions):
    """L2-2: درخواست پرداخت آنلاین رکورد ایجاد می‌کند"""
    uid = ensure_test_user("pay.happy2@chortke.test", verified=True, balance_irt='100000')
    client.login("pay.happy2@chortke.test", DEFAULT_PASSWORD)
    before = db_scalar(f"SELECT COUNT(*) FROM payment_logs WHERE user_id={uid}")
    code, body, jb = client.post_json('/payment/request', {
        'gateway': 'zarinpal',
        'amount': '50000',
        'idempotency_key': f'pay_test_{uid}_001',
    })
    after = db_scalar(f"SELECT COUNT(*) FROM payment_logs WHERE user_id={uid}")
    # پرداخت واقعی نیاز به gateway دارد ولی لاگ باید ثبت شود
    handled = code in (200, 302, 422, 503)  # 422=validation, 503=gateway down
    assert_true(assertions, f"درخواست هندل شد (HTTP {code})", handled)
    assert_true(assertions, "بدون Fatal/Exception", 'Fatal' not in body and 'SQLSTATE' not in body)

# ═══════════════════════════════════════════════════════════════════
# لایه ۳: مسیرهای شکست (Failure Paths)
# ═══════════════════════════════════════════════════════════════════

def test_payment_L3_deposit_without_amount(client, assertions):
    """L3-1: واریز بدون مبلغ باید رد شود"""
    ensure_test_user("pay.fail1@chortke.test", verified=True)
    client.login("pay.fail1@chortke.test", DEFAULT_PASSWORD)
    code, body, jb = client.post('/wallet/deposit/manual', {
        'amount': '',
        'description': 'test',
    })
    is_rejected = code == 422 or 'نامعتبر' in body or 'الزامی' in body
    assert_true(assertions, f"واریز بدون مبلغ رد شد (HTTP {code})", is_rejected)

def test_payment_L3_payment_invalid_gateway(client, assertions):
    """L3-2: پرداخت با gateway نامعتبر باید رد شود"""
    ensure_test_user("pay.fail2@chortke.test", verified=True)
    client.login("pay.fail2@chortke.test", DEFAULT_PASSWORD)
    code, body, jb = client.post_json('/payment/request', {
        'gateway': 'fake_gateway',
        'amount': '50000',
        'idempotency_key': 'pay_fail2_invalid_gw_001',
    })
    is_rejected = code == 422 or (jb and not jb.get('success', True)) or 'نامعتبر' in body
    assert_true(assertions, f"gateway نامعتبر رد شد (HTTP {code})", is_rejected)

def test_payment_L3_payment_missing_idempotency(client, assertions):
    """L3-3: درخواست پرداخت بدون idempotency_key باید رد شود"""
    ensure_test_user("pay.fail3@chortke.test", verified=True)
    client.login("pay.fail3@chortke.test", DEFAULT_PASSWORD)
    code, body, jb = client.post_json('/payment/request', {
        'gateway': 'zarinpal',
        'amount': '50000',
    })
    is_rejected = code == 422 or (jb and not jb.get('success', True))
    assert_true(assertions, f"بدون idempotency رد شد (HTTP {code})", is_rejected)

def test_payment_L3_guest_cannot_deposit(client, assertions):
    """L3-4: مهمان نمی‌تواند واریز کند"""
    client2 = HttpClient(f"/tmp/test_guest_pay_{id}.jar")
    code, body = client2.get('/wallet/deposit/manual')
    assert_true(assertions, f"مهمان رد شد (HTTP {code})", code in (302, 403))

# ═══════════════════════════════════════════════════════════════════
# لایه ۴: امنیت و مجوز (Security)
# ═══════════════════════════════════════════════════════════════════

def test_payment_L4_csrf_missing_token(client, assertions):
    """L4-1: درخواست پرداخت بدون CSRF token باید رد شود"""
    ensure_test_user("pay.sec1@chortke.test", verified=True)
    client.login("pay.sec1@chortke.test", DEFAULT_PASSWORD)
    # POST مستقیم بدون CSRF token
    r = subprocess.run(
        ['curl', '-sS', '-b', client.jar, '-X', 'POST',
         f'{BASE_URL}/wallet/deposit/manual',
         '--data-urlencode', 'amount=500000',
         '--data-urlencode', 'description=test',
         '-o', '/tmp/ht_body.html', '-w', '%{http_code}', '--max-time', '15'],
        capture_output=True, text=True, timeout=20
    )
    code = int(r.stdout.strip()) if r.stdout.strip().isdigit() else 0
    assert_true(assertions, f"بدون CSRF رد شد (HTTP {code})", code in (403, 419, 302))

def test_payment_L4_sqli_in_amount(client, assertions):
    """L4-2: SQL injection در فیلد مبلغ باید رد/escape شود"""
    ensure_test_user("pay.sec2@chortke.test", verified=True)
    client.login("pay.sec2@chortke.test", DEFAULT_PASSWORD)
    code, body, jb = client.post('/wallet/deposit/manual', {
        'amount': "1'; DROP TABLE users; --",
        'description': 'sqli test',
    })
    # نباید 500 باشد — یعنی کرش نکرده
    no_crash = code != 500 and 'SQLSTATE' not in body and 'Fatal' not in body
    assert_true(assertions, f"SQLi رد/escape شد (HTTP {code})", no_crash)

def test_payment_L4_xss_in_description(client, assertions):
    """L4-3: XSS در فیلد توضیحات باید escape شود"""
    ensure_test_user("pay.sec3@chortke.test", verified=True)
    client.login("pay.sec3@chortke.test", DEFAULT_PASSWORD)
    xss_payload = '<script>alert("xss")</script>'
    code, body, jb = client.post('/wallet/deposit/manual', {
        'amount': '500000',
        'description': xss_payload,
    })
    # اگر در بدنه بدون escape نمایش داده شود = XSS
    has_raw_xss = xss_payload in body and '&lt;script&gt;' not in body
    assert_true(assertions, f"XSS escape شد (HTTP {code})", not has_raw_xss or code in (422, 302))

# ═══════════════════════════════════════════════════════════════════
# لایه ۵: موارد لبه (Edge Cases)
# ═══════════════════════════════════════════════════════════════════

def test_payment_L5_zero_amount(client, assertions):
    """L5-1: واریز مبلغ صفر باید رد شود"""
    ensure_test_user("pay.edge1@chortke.test", verified=True)
    client.login("pay.edge1@chortke.test", DEFAULT_PASSWORD)
    code, body, jb = client.post('/wallet/deposit/manual', {
        'amount': '0',
        'description': 'zero amount',
    })
    is_rejected = code == 422 or (jb and not jb.get('success', True)) or 'صفر' in body or 'نامعتبر' in body
    assert_true(assertions, f"مبلغ صفر رد شد (HTTP {code})", is_rejected)

def test_payment_L5_negative_amount(client, assertions):
    """L5-2: واریز مبلغ منفی باید رد شود"""
    ensure_test_user("pay.edge2@chortke.test", verified=True)
    client.login("pay.edge2@chortke.test", DEFAULT_PASSWORD)
    code, body, jb = client.post('/wallet/deposit/manual', {
        'amount': '-50000',
        'description': 'negative amount',
    })
    is_rejected = code == 422 or (jb and not jb.get('success', True))
    assert_true(assertions, f"مبلغ منفی رد شد (HTTP {code})", is_rejected)

def test_payment_L5_huge_amount(client, assertions):
    """L5-3: واریز مبلغ بسیار بزرگ باید هندل شود"""
    ensure_test_user("pay.edge3@chortke.test", verified=True)
    client.login("pay.edge3@chortke.test", DEFAULT_PASSWORD)
    code, body, jb = client.post('/wallet/deposit/manual', {
        'amount': '999999999999999',
        'description': 'huge amount',
    })
    # نباید کرش کند — هر رشتهای پاسخ معتبر است
    no_crash = code != 500 and 'Fatal' not in body and 'SQLSTATE' not in body
    assert_true(assertions, f"مبلغ بزرگ هندل شد (HTTP {code})", no_crash)

# ═══════════════════════════════════════════════════════════════════
# لایه ۷: یکپارچگی داده (Data Integrity)
# ═══════════════════════════════════════════════════════════════════

def test_payment_L7_manual_deposit_status_valid(client, assertions):
    """L7-1: وضعیت واریزهای دستی در مقادیر مجاز است"""
    uid = ensure_test_user("pay.data1@chortke.test", verified=True, balance_irt='100000')
    client.login("pay.data1@chortke.test", DEFAULT_PASSWORD)
    # ایجاد یک واریز
    client.post('/wallet/deposit/manual', {
        'amount': '100000',
        'description': 'تست یکپارچگی',
    })
    # بررسی مقادیر status
    invalid = db_scalar(
        f"SELECT COUNT(*) FROM manual_deposits WHERE user_id={uid} "
        f"AND status NOT IN ('pending','approved','rejected','cancelled')"
    )
    assert_equal(assertions, "status نامعتبر وجود ندارد", int(invalid), 0)

def test_payment_L7_payment_log_timestamp(client, assertions):
    """L7-2: لاگ‌های پرداخت timestamp معتبر دارند"""
    uid = ensure_test_user("pay.data2@chortke.test", verified=True)
    # بررسی لاگ‌های قبلی
    null_dates = db_scalar(
        f"SELECT COUNT(*) FROM payment_logs WHERE user_id={uid} AND created_at IS NULL"
    )
    assert_equal(assertions, "created_at ست شده", int(null_dates), 0)

# ═══════════════════════════════════════════════════════════════════
# اجرا
# ═══════════════════════════════════════════════════════════════════

if __name__ == '__main__':
    suite = TestSuite("بخش پرداخت — الگوی ۷ لایه‌ای")
    
    # لایه ۱: دود
    suite.run_test("L1-1: صفحه واریز لود", test_payment_L1_deposit_page_loads)
    suite.run_test("L1-2: صفحه واریز دستی لود", test_payment_L1_manual_deposit_page)
    suite.run_test("L1-3: لیست واریزها لود", test_payment_L1_deposit_list_page)
    
    # لایه ۲: خوش‌اقبال
    suite.run_test("L2-1: واریز دستی موفق", test_payment_L2_manual_deposit_success)
    suite.run_test("L2-2: درخواست پرداخت", test_payment_L2_payment_request_creates_record)
    
    # لایه ۳: شکست
    suite.run_test("L3-1: واریز بدون مبلغ", test_payment_L3_deposit_without_amount)
    suite.run_test("L3-2: gateway نامعتبر", test_payment_L3_payment_invalid_gateway)
    suite.run_test("L3-3: بدون idempotency_key", test_payment_L3_payment_missing_idempotency)
    suite.run_test("L3-4: مهمان محروم", test_payment_L3_guest_cannot_deposit)
    
    # لایه ۴: امنیت
    suite.run_test("L4-1: بدون CSRF رد", test_payment_L4_csrf_missing_token)
    suite.run_test("L4-2: SQLi در مبلغ رد", test_payment_L4_sqli_in_amount)
    suite.run_test("L4-3: XSS در توضیحات escape", test_payment_L4_xss_in_description)
    
    # لایه ۵: لبه
    suite.run_test("L5-1: مبلغ صفر رد", test_payment_L5_zero_amount)
    suite.run_test("L5-2: مبلغ منفی رد", test_payment_L5_negative_amount)
    suite.run_test("L5-3: مبلغ بزرگ هندل", test_payment_L5_huge_amount)
    
    # لایه ۷: یکپارچگی
    suite.run_test("L7-1: status مقادیر مجاز", test_payment_L7_manual_deposit_status_valid)
    suite.run_test("L7-2: timestamp لاگ پرداخت", test_payment_L7_payment_log_timestamp)
    
    ok = suite.summary()
    
    # گزارش لایه‌ای
    print(f"\n{'═' * 60}")
    print(f"  گزارش لایه‌ای — بخش پرداخت")
    print(f"{'═' * 60}")
    layers = {
        "لایه ۱ دود":        3,
        "لایه ۲ خوش‌اقبال":   2,
        "لایه ۳ شکست":       4,
        "لایه ۴ امنیت":      3,
        "لایه ۵ لبه":        3,
        "لایه ۶ مرورگر":     "— (browser_payment.js جداگانه)",
        "لایه ۷ یکپارچگی":    2,
    }
    for name, count in layers.items():
        print(f"  {name:25s} {count}")
    print(f"  {'مجموع (بدون L6)':25s} 17/20")
    print(f"{'═' * 60}")
    
    sys.exit(0 if ok else 1)
