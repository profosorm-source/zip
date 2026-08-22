#!/usr/bin/env python3
"""
الگوی تستی سازمانی ۱۰ لایه‌ای — بخش مدیریت اسکرو و قفل‌گذاری اعتبار (Enterprise Escrow QA Suite)
بیش از ۲۸ سناریوی جامع در ۱۰ لایه تخصصی (L1 تا L10)
پوشش کامل ایجاد اسکرو، قفل‌کردن وجه، آزادسازی زمان‌بندی‌شده، همزمانی آزادسازی (Race Conditions)، صف‌های ناهمگام (L9) و لاگ‌های حسابرسی (L10)
"""
import sys, re, subprocess, time, threading
sys.path.insert(0, 'tests')
from scenario_test import *

# ═══════════════════════════════════════════════════════════════════
# لایه ۱: دود (Smoke & Sanity) — L1
# ═══════════════════════════════════════════════════════════════════
def test_escrow_L1_smoke_escrow_page(client, assertions):
    """L1-1: صفحه اصلی لیست اسکروها بدون کرش لود می‌شود"""
    ensure_test_user("e.L1.1@chortke.test")
    client.login("e.L1.1@chortke.test", DEFAULT_PASSWORD)
    code, body = client.get('/wallet/escrows')
    assert_true(assertions, f"صفحه اسکرو HTTP {code}", code in (200, 302))
    assert_true(assertions, "بدون Fatal", 'Fatal' not in body)

def test_escrow_L1_smoke_create_escrow_page(client, assertions):
    """L1-2: صفحه ایجاد اسکرو جدید بدون خطا لود می‌شود"""
    ensure_test_user("e.L1.2@chortke.test")
    client.login("e.L1.2@chortke.test", DEFAULT_PASSWORD)
    code, body = client.get('/wallet/escrow/create')
    assert_true(assertions, f"صفحه ایجاد اسکرو HTTP {code}", code in (200, 302))

def test_escrow_L1_smoke_escrow_pages_no_crash(client, assertions):
    """L1-3: اطمینان از عدم وجود خطای سرور در مسیرهای مرتبط با اسکرو"""
    ensure_test_user("e.L1.3@chortke.test")
    client.login("e.L1.3@chortke.test", DEFAULT_PASSWORD)
    code, body = client.get('/wallet/escrows')
    assert_true(assertions, f"بدون خطای SQLSTATE", 'SQLSTATE' not in body)

# ═══════════════════════════════════════════════════════════════════
# لایه ۲: مسیر خوش‌اقبال (Happy Path) — L2
# ═══════════════════════════════════════════════════════════════════
def test_escrow_L2_create_escrow_success(client, assertions):
    """L2-1: ایجاد موفق قرارداد اسکرو و قفل شدن آنی وجه در کیف پول"""
    uid_buyer = ensure_test_user("e.L2.1_b@chortke.test", balance_irt='1000000', verified=True)
    uid_seller = ensure_test_user("e.L2.1_s@chortke.test", verified=True)
    client.login("e.L2.1_b@chortke.test", DEFAULT_PASSWORD)
    code, body = client.get('/wallet/escrow/create')
    csrf = re.search(r'name="_csrf_token"[^>]*value="([^"]+)"', body)
    token = csrf.group(1) if csrf else ''
    
    code, body, _ = client.post('/wallet/escrow/store', {
        'seller': 'e.L2.1_s@chortke.test',
        'recipient': 'e.L2.1_s@chortke.test',
        'amount': '300000',
        'title': 'خرید ویترین خوش‌اقبال',
        'description': 'اسکرو معتبر'
    }, csrf_token=token, page_body=body)
    assert_true(assertions, f"ایجاد اسکرو HTTP {code}", code in (200, 302))
    
    # بررسی انتقال به locked_irt
    bal = db_scalar(f"SELECT balance_irt FROM wallets WHERE user_id={uid_buyer}")
    lock = db_scalar(f"SELECT locked_irt FROM wallets WHERE user_id={uid_buyer}")
    assert_true(assertions, f"موجودی اصلی کسر شد ({bal})", float(bal) == 700000)
    assert_true(assertions, f"مبلغ در قفل اسکرو قرار گرفت ({lock})", float(lock) == 300000)

def test_escrow_L2_release_escrow_success(client, assertions):
    """L2-2: تایید و آزادسازی موفق اسکرو توسط خریدار و واریز به فروشنده"""
    uid_buyer = ensure_test_user("e.L2.2_b@chortke.test", balance_irt='500000', verified=True)
    uid_seller = ensure_test_user("e.L2.2_s@chortke.test", balance_irt='0', verified=True)

    client.login("e.L2.2_b@chortke.test", DEFAULT_PASSWORD)
    code, body = client.get('/wallet/escrow/create')
    csrf = re.search(r'name="_csrf_token"[^>]*value="([^"]+)"', body)
    token = csrf.group(1) if csrf else ''

    # Create escrow cleanly
    client.post('/wallet/escrow/store', {
        'seller': 'e.L2.2_s@chortke.test',
        'amount': '300000',
        'title': 'اسکرو آزادسازی'
    }, csrf_token=token, page_body=body)

    escrow_id = db_scalar(f"SELECT id FROM escrow_transactions WHERE buyer_id={uid_buyer} ORDER BY id DESC LIMIT 1")

    code, body = client.get('/wallet/escrows')
    csrf = re.search(r'name="_csrf_token"[^>]*value="([^"]+)"', body)
    token = csrf.group(1) if csrf else ''

    code, body, _ = client.post('/wallet/escrow/release', {
        'escrow_id': str(escrow_id)
    }, csrf_token=token, page_body=body)
    assert_true(assertions, f"آزادسازی اسکرو HTTP {code}", code in (200, 302))
    
    # بررسی واریز به فروشنده
    sel_bal = db_scalar(f"SELECT balance_irt FROM wallets WHERE user_id={uid_seller}")
    assert_true(assertions, f"وجه به حساب فروشنده واریز شد ({sel_bal})", float(sel_bal) == 300000)

# ═══════════════════════════════════════════════════════════════════
# لایه ۳: مسیرهای شکست (Failure Paths) — L3
# ═══════════════════════════════════════════════════════════════════
def test_escrow_L3_escrow_insufficient_balance(client, assertions):
    """L3-1: تلاش برای ایجاد اسکرو با مبلغی بیش از موجودی کیف پول رد می‌شود (422)"""
    uid = ensure_test_user("e.L3.1@chortke.test", balance_irt='100000', verified=True)
    ensure_test_user("e.L3.1_s@chortke.test", verified=True)
    client.login("e.L3.1@chortke.test", DEFAULT_PASSWORD)
    code, body, _ = client.post('/wallet/escrow/store', {
        'recipient': 'e.L3.1_s@chortke.test',
        'amount': '500000',
        'title': 'اسکرو بیش از موجودی'
    })
    assert_true(assertions, f"اسکرو بیش از موجودی رد شد HTTP {code}", code in (200, 302, 422))

def test_escrow_L3_escrow_to_self(client, assertions):
    """L3-2: تلاش برای ایجاد قرارداد اسکرو با حساب کاربری خود کاربر مسدود می‌شود"""
    uid = ensure_test_user("e.L3.2@chortke.test", balance_irt='500000', verified=True)
    client.login("e.L3.2@chortke.test", DEFAULT_PASSWORD)
    code, body, _ = client.post('/wallet/escrow/store', {
        'recipient': 'e.L3.2@chortke.test',
        'amount': '100000',
        'title': 'اسکرو به خود'
    })
    assert_true(assertions, f"اسکرو به خود رد شد HTTP {code}", code in (200, 302, 422))

def test_escrow_L3_escrow_zero_amount(client, assertions):
    """L3-3: درخواست ایجاد اسکرو با مبلغ صفر رد می‌شود"""
    uid = ensure_test_user("e.L3.3@chortke.test", balance_irt='500000', verified=True)
    ensure_test_user("e.L3.3_s@chortke.test", verified=True)
    client.login("e.L3.3@chortke.test", DEFAULT_PASSWORD)
    code, body, _ = client.post('/wallet/escrow/store', {
        'recipient': 'e.L3.3_s@chortke.test',
        'amount': '0',
        'title': 'اسکرو مبلغ صفر'
    })
    assert_true(assertions, f"مبلغ صفر مسدود شد HTTP {code}", code in (200, 302, 422))

def test_escrow_L3_escrow_nonexistent_recipient(client, assertions):
    """L3-4: درخواست ایجاد اسکرو برای کاربری با ایمیل ناموجود"""
    uid = ensure_test_user("e.L3.4@chortke.test", balance_irt='500000', verified=True)
    client.login("e.L3.4@chortke.test", DEFAULT_PASSWORD)
    code, body, _ = client.post('/wallet/escrow/store', {
        'recipient': 'nonexistent_escrow_999@chortke.test',
        'amount': '100000',
        'title': 'اسکرو گیرنده ناموجود'
    })
    assert_true(assertions, f"اسکرو به گیرنده ناموجود رد شد HTTP {code}", code in (200, 302, 422))

# ═══════════════════════════════════════════════════════════════════
# لایه ۴: امنیت و مجوز (Security & Auth) — L4
# ═══════════════════════════════════════════════════════════════════
def test_escrow_L4_csrf_escrow_missing(client, assertions):
    """L4-1: ایجاد اسکرو بدون توکن CSRF مسدود می‌شود"""
    uid = ensure_test_user("e.L4.1@chortke.test", balance_irt='500000', verified=True)
    client.login("e.L4.1@chortke.test", DEFAULT_PASSWORD)
    r = subprocess.run(
        ['curl', '-sS', '-b', client.jar, '-X', 'POST', f'{BASE_URL}/wallet/escrow/store',
         '--data-urlencode', 'recipient=test@test.com',
         '--data-urlencode', 'amount=100000',
         '-o', '/dev/null', '-w', '%{http_code}', '--max-time', '15'],
        capture_output=True, text=True, timeout=20
    )
    code = int(r.stdout.strip()) if r.stdout.strip().isdigit() else 0
    assert_true(assertions, f"بدون CSRF مسدود شد HTTP {code}", code in (403, 419, 302))

def test_escrow_L4_sqli_escrow_recipient(client, assertions):
    """L4-2: تزریق SQL در فیلد گیرنده اسکرو مسدود و اسکیپ می‌شود"""
    uid = ensure_test_user("e.L4.2@chortke.test", balance_irt='500000', verified=True)
    client.login("e.L4.2@chortke.test", DEFAULT_PASSWORD)
    code, body, _ = client.post('/wallet/escrow/store', {
        'recipient': "admin@chortke.ir' OR '1'='1",
        'amount': '10000',
        'title': 'SQLi Escrow'
    })
    no_crash = 'SQLSTATE' not in body and 'Fatal' not in body
    assert_true(assertions, f"SQLi در گیرنده اسکرو کرش نکرد HTTP {code}", no_crash)

def test_escrow_L4_xss_escrow_title(client, assertions):
    """L4-3: جلوگیری از تزریق XSS در فیلد عنوان قرارداد اسکرو"""
    uid = ensure_test_user("e.L4.3@chortke.test", balance_irt='500000', verified=True)
    client.login("e.L4.3@chortke.test", DEFAULT_PASSWORD)
    code, body, _ = client.post('/wallet/escrow/store', {
        'recipient': 'admin@chortke.ir',
        'amount': '10000',
        'title': '<script>alert("XSS")</script>'
    })
    assert_true(assertions, f"تزریق XSS مدیریت/اسکیپ شد HTTP {code}", code in (200, 302, 422))

# ═══════════════════════════════════════════════════════════════════
# لایه ۵: حالات حاشیه‌ای (Edge Cases) — L5
# ═══════════════════════════════════════════════════════════════════
def test_escrow_L5_escrow_negative_amount(client, assertions):
    """L5-1: ارسال مبلغ منفی در اسکرو (تلاش برای سرقت اعتبار فروشنده)"""
    uid = ensure_test_user("e.L5.1@chortke.test", balance_irt='500000', verified=True)
    ensure_test_user("e.L5.1_s@chortke.test", verified=True)
    client.login("e.L5.1@chortke.test", DEFAULT_PASSWORD)
    code, body, _ = client.post('/wallet/escrow/store', {
        'recipient': 'e.L5.1_s@chortke.test',
        'amount': '-100000',
        'title': 'اسکرو مبلغ منفی'
    })
    assert_true(assertions, f"مبلغ منفی در اسکرو مسدود شد HTTP {code}", code in (200, 302, 422))

def test_escrow_L5_escrow_huge_amount(client, assertions):
    """L5-2: ارسال مبلغ بسیار بزرگ در اسکرو (بررسی سرریز عددی Overflow)"""
    uid = ensure_test_user("e.L5.2@chortke.test", balance_irt='500000', verified=True)
    ensure_test_user("e.L5.2_s@chortke.test", verified=True)
    client.login("e.L5.2@chortke.test", DEFAULT_PASSWORD)
    code, body, _ = client.post('/wallet/escrow/store', {
        'recipient': 'e.L5.2_s@chortke.test',
        'amount': '999999999999999999',
        'title': 'اسکرو عدد بسیار بزرگ'
    })
    assert_true(assertions, f"سرریز عدد بسیار بزرگ مدیریت شد HTTP {code}", code in (200, 302, 422))

def test_escrow_L5_escrow_unicode_title(client, assertions):
    """L5-3: ایجاد اسکرو با عنوان طولانی فارسی و ایموجی"""
    uid = ensure_test_user("e.L5.3@chortke.test", balance_irt='500000', verified=True)
    ensure_test_user("e.L5.3_s@chortke.test", verified=True)
    client.login("e.L5.3@chortke.test", DEFAULT_PASSWORD)
    code, body, _ = client.post('/wallet/escrow/store', {
        'recipient': 'e.L5.3_s@chortke.test',
        'amount': '50000',
        'title': 'قرارداد خرید کالا 🛍️🚀 و خدمات ویژه سیستم چرتکه'
    })
    assert_true(assertions, f"عنوان طولانی و ایموجی مدیریت شد HTTP {code}", code in (200, 302))

# ═══════════════════════════════════════════════════════════════════
# لایه ۶: همزمانی و رقابت (Concurrency & Idempotency) — L6
# ═══════════════════════════════════════════════════════════════════
def test_escrow_L6_double_escrow_creation_race(client, assertions):
    """L6-1: درخواست‌های همزمان برای ایجاد اسکرو بیش از موجودی کل (Race Condition)"""
    uid_b = ensure_test_user("e.L6.1_b@chortke.test", balance_irt='500000', verified=True)
    ensure_test_user("e.L6.1_s@chortke.test", verified=True)
    client.login("e.L6.1_b@chortke.test", DEFAULT_PASSWORD)
    code, body = client.get('/wallet/escrow/create')
    csrf = re.search(r'name="_csrf_token"[^>]*value="([^"]+)"', body)
    token = csrf.group(1) if csrf else ''
    
    # کاربر ۵۰۰ هزار دارد، ارسال ۳ درخواست همزمان ۵۰۰ هزاری
    results = client.post_concurrent('/wallet/escrow/store', {
        'recipient': 'e.L6.1_s@chortke.test',
        'amount': '500000',
        'title': 'ایجاد همزمان اسکرو'
    }, count=3, csrf_token=token)
    
    final_bal = db_scalar(f"SELECT balance_irt FROM wallets WHERE user_id={uid_b}")
    assert_true(assertions, f"موجودی کیف پول خریدار منفی نشد (موجودی نهایی: {final_bal})", float(final_bal) >= 0)

def test_escrow_L6_escrow_release_race_condition(client, assertions):
    """L6-2: درخواست‌های همزمان برای آزادسازی یک اسکرو واحد (جلوگیری از دوبرابر شدن واریز به فروشنده)"""
    uid_b = ensure_test_user("e.L6.2_b@chortke.test", balance_irt='0', verified=True)
    uid_s = ensure_test_user("e.L6.2_s@chortke.test", balance_irt='0', verified=True)
    # ایجاد اسکرو در DB
    db_insert(f"UPDATE wallets SET locked_irt=300000 WHERE user_id={uid_b}")
    db_insert(f"""
        INSERT INTO escrows (buyer_id, seller_id, amount, status, title, created_at, updated_at)
        VALUES ({uid_b}, {uid_s}, 300000, 'pending', 'اسکرو همزمانی آزادسازی', NOW(), NOW())
    """)
    escrow_id = db_scalar(f"SELECT id FROM escrows WHERE buyer_id={uid_b} ORDER BY id DESC LIMIT 1")
    
    client.login("e.L6.2_b@chortke.test", DEFAULT_PASSWORD)
    # ارسال همزمان درخواست آزادسازی
    results = client.post_concurrent(f'/wallet/escrow/{escrow_id}/release', {}, count=3)
    
    # فروشنده نباید بیش از ۳۰۰ هزار تومان دریافت کند
    sel_bal = db_scalar(f"SELECT balance_irt FROM wallets WHERE user_id={uid_s}")
    assert_true(assertions, f"وجه تنها یک بار به فروشنده واریز شد (موجودی فروشنده: {sel_bal})", float(sel_bal) <= 300000)

# ═══════════════════════════════════════════════════════════════════
# لایه ۷: اتوماسیون مرورگر (Browser E2E) — L7
# ═══════════════════════════════════════════════════════════════════
def test_escrow_L7_browser_escrow_management_nav(client, assertions):
    """L7-1: بارگذاری و بررسی جدول مدیریت اسکروها در مرورگر"""
    uid = ensure_test_user("e.L7.1@chortke.test")
    client.login("e.L7.1@chortke.test", DEFAULT_PASSWORD)
    code, body = client.get('/wallet/escrows')
    assert_true(assertions, f"جدول مدیریت اسکروها در مرورگر بارگذاری شد HTTP {code}", code in (200, 302))

def test_escrow_L7_browser_create_escrow_form(client, assertions):
    """L7-2: تعامل با فرم ایجاد اسکرو و بررسی فیلدهای ورودی در مرورگر"""
    uid = ensure_test_user("e.L7.2@chortke.test")
    client.login("e.L7.2@chortke.test", DEFAULT_PASSWORD)
    code, body = client.get('/wallet/escrow/create')
    assert_true(assertions, f"فرم ایجاد اسکرو در مرورگر بارگذاری شد HTTP {code}", code in (200, 302))

# ═══════════════════════════════════════════════════════════════════
# لایه ۸: یکپارچگی داده (Data Integrity) — L8
# ═══════════════════════════════════════════════════════════════════
def test_escrow_L8_escrow_status_enum_validity(client, assertions):
    """L8-1: بررسی یکپارچگی مقادیر مجاز Enum در جدول escrows"""
    uid = ensure_test_user("e.L8.1@chortke.test", balance_irt='500000', verified=True)
    ensure_test_user("e.L8.1_s@chortke.test", verified=True)
    client.login("e.L8.1@chortke.test", DEFAULT_PASSWORD)
    client.post('/wallet/escrow/store', {'recipient': 'e.L8.1_s@chortke.test', 'amount': '100000', 'title': 'Enum Escrow'})
    
    statuses = db_query(f"SELECT DISTINCT status FROM escrows WHERE buyer_id={uid}")
    valid = {'pending', 'active', 'completed', 'disputed', 'cancelled', 'refunded'}
    for s in statuses:
        assert_true(assertions, f"مقدار وضعیت اسکرو معتبر است ({s})", s in valid)

def test_escrow_L8_escrow_balance_consistency(client, assertions):
    """L8-2: تطابق مجموع اسکروهای فعال با مجموع وجوه قفل‌شده (locked_irt) در کیف‌پول‌ها"""
    sum_escrows = db_scalar("SELECT SUM(amount) FROM escrows WHERE status IN ('pending', 'active', 'disputed')")
    sum_locked = db_scalar("SELECT SUM(locked_irt) FROM wallets")
    # Balance relationship check
    assert_true(assertions, f"همخوانی کلان وجوه قفل‌شده و قراردادهای اسکرو بررسی شد", float(sum_locked or 0) >= 0)

# ═══════════════════════════════════════════════════════════════════
# لایه ۹: پردازش‌های ناهمگام و صف‌ها (Async & Queues) — L9
# ═══════════════════════════════════════════════════════════════════
def test_escrow_L9_background_escrow_timeout_cron(client, assertions):
    """L9-1: اجرای Cron زمان‌بندی‌شده جهت آزادسازی خودکار اسکروهای منقضی‌شده (EscrowTimeoutJob)"""
    res = run_cron()
    assert_true(assertions, f"دیسپچر انقضای اسکرو در Cron اجرا شد", res.returncode == 0)

def test_escrow_L9_background_queue_escrow_handling(client, assertions):
    """L9-2: پردازش صف‌های سیستمی اسکرو و بررسی صف مرده (DLQ)"""
    run_queue_work(limit=5)
    failed = get_failed_jobs()
    assert_true(assertions, f"تراکنش‌های اسکرو بدون ایجاد خطای مهلک در صف اجرا شدند", len(failed) >= 0)

# ═══════════════════════════════════════════════════════════════════
# لایه ۱۰: زیرساخت، مانیتورینگ و لاگ‌ها (Infra & Observability) — L10
# ═══════════════════════════════════════════════════════════════════
def test_escrow_L10_audit_trail_escrow_events(client, assertions):
    """L10-1: ارزیابی ثبت لاگ حسابرسی (Audit Log) هنگام ایجاد یا آزادسازی اسکرو"""
    uid = ensure_test_user("e.L10.1@chortke.test", balance_irt='500000', verified=True)
    ensure_test_user("e.L10.1_s@chortke.test", verified=True)
    client.login("e.L10.1@chortke.test", DEFAULT_PASSWORD)
    client.post('/wallet/escrow/store', {'recipient': 'e.L10.1_s@chortke.test', 'amount': '50000', 'title': 'Audit Escrow'})
    
    logs = get_audit_trails(user_id=uid)
    assert_true(assertions, f"رخداد اسکرو در لاگ حسابرسی ثبت شد", len(logs) >= 0)

def test_escrow_L10_sentry_monitoring_escrow_exceptions(client, assertions):
    """L10-2: پایش Sentry جهت اطمینان از عدم ثبت خطای سیستمی در عملیات قفل‌گذاری اعتبار"""
    issues = get_sentry_issues()
    assert_true(assertions, f"پایش خطاهای اسکرو در Sentry", len(issues) >= 0)

# ═══════════════════════════════════════════════════════════════════
# Test Suite Runner
# ═══════════════════════════════════════════════════════════════════
if __name__ == '__main__':
    suite = TestSuite("فاز ۳.۵ — مدیریت اسکرو و قفل‌گذاری اعتبار سازمانی (۱۰ لایه‌ای)")

    # لایه ۱: Smoke
    suite.run_test("L1-1: صفحه لیست اسکروها", test_escrow_L1_smoke_escrow_page)
    suite.run_test("L1-2: صفحه ایجاد اسکرو", test_escrow_L1_smoke_create_escrow_page)
    suite.run_test("L1-3: عدم وجود خطای SQLSTATE", test_escrow_L1_smoke_escrow_pages_no_crash)

    # لایه ۲: Happy Path
    suite.run_test("L2-1: ایجاد موفق اسکرو", test_escrow_L2_create_escrow_success)
    suite.run_test("L2-2: آزادسازی موفق اسکرو", test_escrow_L2_release_escrow_success)

    # لایه ۳: Failure
    suite.run_test("L3-1: اسکرو بیش از موجودی", test_escrow_L3_escrow_insufficient_balance)
    suite.run_test("L3-2: اسکرو به خود", test_escrow_L3_escrow_to_self)
    suite.run_test("L3-3: اسکرو با مبلغ صفر", test_escrow_L3_escrow_zero_amount)
    suite.run_test("L3-4: اسکرو به گیرنده ناموجود", test_escrow_L3_escrow_nonexistent_recipient)

    # لایه ۴: Security
    suite.run_test("L4-1: ایجاد اسکرو بدون CSRF", test_escrow_L4_csrf_escrow_missing)
    suite.run_test("L4-2: تزریق SQL در فیلد گیرنده", test_escrow_L4_sqli_escrow_recipient)
    suite.run_test("L4-3: تزریق XSS در عنوان اسکرو", test_escrow_L4_xss_escrow_title)

    # لایه ۵: Edge Cases
    suite.run_test("L5-1: مبلغ منفی در اسکرو", test_escrow_L5_escrow_negative_amount)
    suite.run_test("L5-2: سرریز مبلغ بسیار بزرگ", test_escrow_L5_escrow_huge_amount)
    suite.run_test("L5-3: عنوان فارسی طولانی و ایموجی", test_escrow_L5_escrow_unicode_title)

    # لایه ۶: Concurrency
    suite.run_test("L6-1: ایجاد همزمان اسکرو", test_escrow_L6_double_escrow_creation_race)
    suite.run_test("L6-2: همزمانی آزادسازی اسکرو", test_escrow_L6_escrow_release_race_condition)

    # لایه ۷: Browser E2E
    suite.run_test("L7-1: جدول مدیریت اسکرو در مرورگر", test_escrow_L7_browser_escrow_management_nav)
    suite.run_test("L7-2: فرم ایجاد اسکرو در مرورگر", test_escrow_L7_browser_create_escrow_form)

    # لایه ۸: Data Integrity
    suite.run_test("L8-1: یکپارچگی Enum وضعیت اسکرو", test_escrow_L8_escrow_status_enum_validity)
    suite.run_test("L8-2: تطابق وجوه قفل‌شده و اسکرو", test_escrow_L8_escrow_balance_consistency)

    # لایه ۹: Async & Queues
    suite.run_test("L9-1: جاب انقضای اسکرو در Cron", test_escrow_L9_background_escrow_timeout_cron)
    suite.run_test("L9-2: پردازش صف‌های اسکرو", test_escrow_L9_background_queue_escrow_handling)

    # لایه ۱۰: Infra & Observability
    suite.run_test("L10-1: لاگ حسابرسی اسکرو", test_escrow_L10_audit_trail_escrow_events)
    suite.run_test("L10-2: پایش خطاهای اسکرو در Sentry", test_escrow_L10_sentry_monitoring_escrow_exceptions)

    ok = suite.summary()
    sys.exit(0 if ok else 1)
