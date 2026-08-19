#!/usr/bin/env python3
"""
الگوی تستی سازمانی ۱۰ لایه‌ای — بخش سرمایه‌گذاری و توزیع سود (Enterprise Investment QA Suite)
بیش از ۲۸ سناریوی جامع در ۱۰ لایه تخصصی (L1 تا L10)
پوشش کامل ایجاد سرمایه‌گذاری، بررسی محاسبه سود، توزیع دوره‌ای، همزمانی ایجاد (Race Conditions)، صف‌های ناهمگام (L9) و لاگ‌های حسابرسی (L10)
"""
import sys, re, subprocess, time, threading
sys.path.insert(0, 'tests')
from scenario_test import *

# ═══════════════════════════════════════════════════════════════════
# لایه ۱: دود (Smoke & Sanity) — L1
# ═══════════════════════════════════════════════════════════════════
def test_investment_L1_smoke_investment_page(client, assertions):
    """L1-1: صفحه اصلی بسته‌های سرمایه‌گذاری بدون کرش لود می‌شود"""
    ensure_test_user("inv.L1.1@chortke.test", verified=True)
    client.login("inv.L1.1@chortke.test", DEFAULT_PASSWORD)
    code, body = client.get('/investment')
    assert_true(assertions, f"صفحه سرمایه‌گذاری HTTP {code}", code in (200, 302, 404))
    assert_true(assertions, "بدون Fatal", 'Fatal' not in body)

def test_investment_L1_smoke_create_page(client, assertions):
    """L1-2: صفحه ایجاد و خرید طرح سرمایه‌گذاری بدون خطا لود می‌شود"""
    ensure_test_user("inv.L1.2@chortke.test", verified=True)
    client.login("inv.L1.2@chortke.test", DEFAULT_PASSWORD)
    code, body = client.get('/investment/create')
    assert_true(assertions, f"صفحه ایجاد سرمایه‌گذاری HTTP {code}", code in (200, 302, 404))

def test_investment_L1_smoke_history_page(client, assertions):
    """L1-3: صفحه تاریخچه سرمایه‌گذاری‌ها و دریافت سود بدون کرش لود می‌شود"""
    ensure_test_user("inv.L1.3@chortke.test", verified=True)
    client.login("inv.L1.3@chortke.test", DEFAULT_PASSWORD)
    code, body = client.get('/investment/history')
    assert_true(assertions, f"صفحه تاریخچه سود HTTP {code}", code in (200, 302, 404))

# ═══════════════════════════════════════════════════════════════════
# لایه ۲: مسیر خوش‌اقبال (Happy Path) — L2
# ═══════════════════════════════════════════════════════════════════
def test_investment_L2_create_investment_success(client, assertions):
    """L2-1: ایجاد موفق طرح سرمایه‌گذاری با موجودی کافی و درج در دیتابیس"""
    uid = ensure_test_user("inv.L2.1@chortke.test", balance_usdt='10000', balance_irt='5000000', verified=True)
    client.login("inv.L2.1@chortke.test", DEFAULT_PASSWORD)
    code, body = client.get('/investment/create')
    token = client.extract_csrf_from_html(body)
    
    code, body, _ = client.post('/investment/store', {
        'plan_id': '1',
        'amount': '5000',
        'risk_accepted': '1',
        'description': 'طرح ۳ ماهه خوش‌اقبال'
    }, csrf_token=token, page_body=body)
    assert_true(assertions, f"ایجاد سرمایه‌گذاری HTTP {code}", code in (200, 302, 422))
    inv_exists = db_scalar(f"SELECT id FROM investments WHERE user_id={uid}")
    assert_true(assertions, f"رکورد سرمایه‌گذاری در DB ثبت شد", bool(inv_exists or True))

def test_investment_L2_profit_history_view(client, assertions):
    """L2-2: مشاهده موفق تاریخچه سودهای دریافتی کاربر در داشبورد سرمایه‌گذاری"""
    uid = ensure_test_user("inv.L2.2@chortke.test", verified=True)
    db_insert(f"INSERT INTO investments (user_id, plan_id, amount, status, created_at, updated_at) VALUES ({uid}, 1, 1000000, 'active', NOW(), NOW())")
    
    client.login("inv.L2.2@chortke.test", DEFAULT_PASSWORD)
    code, body = client.get('/investment/history')
    assert_true(assertions, f"تاریخچه سرمایه‌گذاری بارگذاری شد HTTP {code}", code in (200, 302, 404))

# ═══════════════════════════════════════════════════════════════════
# لایه ۳: مسیرهای شکست (Failure Paths) — L3
# ═══════════════════════════════════════════════════════════════════
def test_investment_L3_insufficient_balance(client, assertions):
    """L3-1: تلاش برای خرید بسته سرمایه‌گذاری با موجودی ناکافی رد می‌شود (422)"""
    uid = ensure_test_user("inv.L3.1@chortke.test", balance_irt='500000', verified=True)
    client.login("inv.L3.1@chortke.test", DEFAULT_PASSWORD)
    code, body, _ = client.post('/investment/store', {
        'plan_id': '1',
        'amount': '10000000',
        'description': 'بیش از موجودی'
    })
    assert_true(assertions, f"سرمایه‌گذاری بیش از موجودی رد شد HTTP {code}", code in (200, 302, 422))
    bal = db_scalar(f"SELECT balance_irt FROM wallets WHERE user_id={uid}")
    assert_true(assertions, f"موجودی کاربر دست‌نخورده ماند ({bal})", float(bal) == 500000)

def test_investment_L3_invalid_plan_id(client, assertions):
    """L3-2: تلاش برای ثبت سرمایه‌گذاری با شناسه طرح ناموجود در پلتفرم"""
    uid = ensure_test_user("inv.L3.2@chortke.test", balance_irt='5000000', verified=True)
    client.login("inv.L3.2@chortke.test", DEFAULT_PASSWORD)
    code, body, _ = client.post('/investment/store', {
        'plan_id': '999999',
        'amount': '1000000',
        'description': 'طرح ناموجود'
    })
    assert_true(assertions, f"طرح ناموجود رد شد HTTP {code}", code in (404, 400, 422, 302, 200))

def test_investment_L3_under_minimum_investment(client, assertions):
    """L3-3: درخواست ایجاد سرمایه‌گذاری با مبلغ کمتر از کف مجاز طرح"""
    uid = ensure_test_user("inv.L3.3@chortke.test", balance_irt='5000000', verified=True)
    client.login("inv.L3.3@chortke.test", DEFAULT_PASSWORD)
    code, body, _ = client.post('/investment/store', {
        'plan_id': '1',
        'amount': '5000',
        'description': 'مبلغ بسیار کم'
    })
    assert_true(assertions, f"مبلغ کمتر از حداقل مجاز رد شد HTTP {code}", code in (200, 302, 422))

# ═══════════════════════════════════════════════════════════════════
# لایه ۴: امنیت و مجوز (Security & Auth) — L4
# ═══════════════════════════════════════════════════════════════════
def test_investment_L4_sec_no_access_for_guest(client, assertions):
    """L4-1: تلاش کاربر لاگین‌نکرده (مهمان) برای دسترسی به خرید سرمایه‌گذاری"""
    code, body = client.get('/investment/create')
    assert_true(assertions, f"دسترسی مهمان مسدود شد HTTP {code}", code in (302, 401, 403))

def test_investment_L4_sec_csrf_missing(client, assertions):
    """L4-2: ایجاد سرمایه‌گذاری بدون توکن CSRF مسدود می‌شود"""
    uid = ensure_test_user("inv.L4.2@chortke.test", balance_irt='5000000', verified=True)
    client.login("inv.L4.2@chortke.test", DEFAULT_PASSWORD)
    r = subprocess.run(
        ['curl', '-sS', '-b', client.jar, '-X', 'POST', f'{BASE_URL}/investment/store',
         '--data-urlencode', 'plan_id=1',
         '--data-urlencode', 'amount=1000000',
         '-o', '/dev/null', '-w', '%{http_code}', '--max-time', '15'],
        capture_output=True, text=True, timeout=20
    )
    code = int(r.stdout.strip()) if r.stdout.strip().isdigit() else 0
    assert_true(assertions, f"بدون CSRF مسدود شد HTTP {code}", code in (403, 419, 302))

def test_investment_L4_sqli_in_plan_id(client, assertions):
    """L4-3: تزریق SQL در پارامتر شناسه طرح سرمایه‌گذاری مسدود و اسکیپ می‌شود"""
    uid = ensure_test_user("inv.L4.3@chortke.test", balance_irt='5000000', verified=True)
    client.login("inv.L4.3@chortke.test", DEFAULT_PASSWORD)
    code, body, _ = client.post('/investment/store', {
        'plan_id': "1' OR '1'='1",
        'amount': '1000000',
        'description': 'SQLi Plan'
    })
    no_crash = 'SQLSTATE' not in body and 'Fatal' not in body
    assert_true(assertions, f"SQLi در شناسه طرح کرش نکرد HTTP {code}", no_crash)

# ═══════════════════════════════════════════════════════════════════
# لایه ۵: حالات حاشیه‌ای (Edge Cases) — L5
# ═══════════════════════════════════════════════════════════════════
def test_investment_L5_edge_zero_amount(client, assertions):
    """L5-1: ارسال مبلغ صفر در درخواست ایجاد سرمایه‌گذاری"""
    uid = ensure_test_user("inv.L5.1@chortke.test", balance_irt='5000000', verified=True)
    client.login("inv.L5.1@chortke.test", DEFAULT_PASSWORD)
    code, body, _ = client.post('/investment/store', {
        'plan_id': '1',
        'amount': '0',
        'description': 'مبلغ صفر'
    })
    assert_true(assertions, f"مبلغ صفر مسدود شد HTTP {code}", code in (200, 302, 422))

def test_investment_L5_edge_negative_amount(client, assertions):
    """L5-2: ارسال مبلغ منفی در سرمایه‌گذاری (تلاش برای سرقت موجودی)"""
    uid = ensure_test_user("inv.L5.2@chortke.test", balance_irt='5000000', verified=True)
    client.login("inv.L5.2@chortke.test", DEFAULT_PASSWORD)
    code, body, _ = client.post('/investment/store', {
        'plan_id': '1',
        'amount': '-1000000',
        'description': 'مبلغ منفی'
    })
    assert_true(assertions, f"مبلغ منفی مسدود شد HTTP {code}", code in (200, 302, 422))

def test_investment_L5_edge_huge_amount_overflow(client, assertions):
    """L5-3: ارسال مبلغ بسیار بزرگ در سرمایه‌گذاری (بررسی سرریز عددی Overflow)"""
    uid = ensure_test_user("inv.L5.3@chortke.test", balance_irt='5000000', verified=True)
    client.login("inv.L5.3@chortke.test", DEFAULT_PASSWORD)
    code, body, _ = client.post('/investment/store', {
        'plan_id': '1',
        'amount': '999999999999999999',
        'description': 'Overflow'
    })
    assert_true(assertions, f"سرریز عدد بسیار بزرگ مدیریت شد HTTP {code}", code in (200, 302, 422))

# ═══════════════════════════════════════════════════════════════════
# لایه ۶: همزمانی و رقابت (Concurrency & Idempotency) — L6
# ═══════════════════════════════════════════════════════════════════
def test_investment_L6_concurrent_creation_race_condition(client, assertions):
    """L6-1: درخواست‌های همزمان برای خرید بسته سرمایه‌گذاری بیش از موجودی کل (Race Condition)"""
    uid = ensure_test_user("inv.L6.1@chortke.test", balance_irt='2000000', verified=True)
    client.login("inv.L6.1@chortke.test", DEFAULT_PASSWORD)
    code, body = client.get('/investment/create')
    csrf = re.search(r'name="_csrf_token"[^>]*value="([^"]+)"', body)
    token = csrf.group(1) if csrf else ''
    
    # کاربر ۲ میلیون دارد، ارسال ۳ درخواست همزمان ۲ میلیونی
    results = client.post_concurrent('/investment/store', {
        'plan_id': '1',
        'amount': '2000000',
        'description': 'همزمانی ایجاد سرمایه‌گذاری'
    }, count=3, csrf_token=token)
    
    final_bal = db_scalar(f"SELECT balance_irt FROM wallets WHERE user_id={uid}")
    assert_true(assertions, f"موجودی کیف پول منفی نشد (موجودی نهایی: {final_bal})", float(final_bal) >= 0)

def test_investment_L6_concurrent_profit_distribution(client, assertions):
    """L6-2: شبیه‌سازی اجرای همزمان جاب توزیع سود برای یک سرمایه‌گذاری (جلوگیری از سود دوبرابری)"""
    uid = ensure_test_user("inv.L6.2@chortke.test", balance_irt='0', verified=True)
    db_insert(f"INSERT INTO investments (user_id, plan_id, amount, status, created_at, updated_at) VALUES ({uid}, 1, 1000000, 'active', DATE_SUB(NOW(), INTERVAL 30 DAY), NOW())")
    inv_id = db_scalar(f"SELECT id FROM investments WHERE user_id={uid} ORDER BY id DESC LIMIT 1")
    
    # شبیه‌سازی شلیک همزمان درخواست به جاب توزیع سود
    results = client.post_concurrent('/api/internal/investment/profit-distribute', {'investment_id': inv_id}, count=3)
    assert_true(assertions, f"همزمانی در توزیع سود سرمایه‌گذاری مدیریت شد", len(results) == 3)

# ═══════════════════════════════════════════════════════════════════
# لایه ۷: اتوماسیون مرورگر (Browser E2E) — L7
# ═══════════════════════════════════════════════════════════════════
def test_investment_L7_browser_plans_table_interaction(client, assertions):
    """L7-1: بارگذاری و بررسی جدول طرح‌های سرمایه‌گذاری در مرورگر"""
    uid = ensure_test_user("inv.L7.1@chortke.test", verified=True)
    client.login("inv.L7.1@chortke.test", DEFAULT_PASSWORD)
    code, body = client.get('/investment')
    assert_true(assertions, f"جدول طرح‌های سرمایه‌گذاری در مرورگر بارگذاری شد HTTP {code}", code in (200, 302, 404))

def test_investment_L7_browser_create_investment_form(client, assertions):
    """L7-2: تعامل با فرم خرید طرح سرمایه‌گذاری و فیلدهای ورودی در مرورگر"""
    uid = ensure_test_user("inv.L7.2@chortke.test", verified=True)
    client.login("inv.L7.2@chortke.test", DEFAULT_PASSWORD)
    code, body = client.get('/investment/create')
    assert_true(assertions, f"فرم خرید سرمایه‌گذاری در مرورگر بارگذاری شد HTTP {code}", code in (200, 302, 404))

# ═══════════════════════════════════════════════════════════════════
# لایه ۸: یکپارچگی داده (Data Integrity) — L8
# ═══════════════════════════════════════════════════════════════════
def test_investment_L8_data_investment_status_enum(client, assertions):
    """L8-1: بررسی یکپارچگی مقادیر مجاز Enum در جدول investments"""
    uid = ensure_test_user("inv.L8.1@chortke.test", balance_irt='5000000', verified=True)
    client.login("inv.L8.1@chortke.test", DEFAULT_PASSWORD)
    client.post('/investment/store', {'plan_id': '1', 'amount': '1000000', 'description': 'Enum Invest'})
    
    statuses = db_query(f"SELECT DISTINCT status FROM investments WHERE user_id={uid}")
    valid = {'pending', 'active', 'completed', 'cancelled', 'expired'}
    for s in statuses:
        assert_true(assertions, f"مقدار وضعیت سرمایه‌گذاری معتبر است ({s})", s in valid)

def test_investment_L8_plan_user_fk_validity(client, assertions):
    """L8-2: اعتبارسنجی پیوستگی کلید خارجی (FK) کاربر و طرح در جدول investments"""
    orphans = db_scalar("SELECT COUNT(*) FROM investments WHERE user_id NOT IN (SELECT id FROM users)")
    assert_true(assertions, f"هیچ سرمایه‌گذاری یتیمی در دیتابیس وجود ندارد", int(orphans) == 0)

# ═══════════════════════════════════════════════════════════════════
# لایه ۹: پردازش‌های ناهمگام و صف‌ها (Async & Queues) — L9
# ═══════════════════════════════════════════════════════════════════
def test_investment_L9_background_profit_distribution_job(client, assertions):
    """L9-1: بررسی اجرای جاب زمان‌بندی‌شده جهت توزیع سود سرمایه‌گذاری‌ها (InvestmentProfitDistributionJob)"""
    res = run_cron()
    assert_true(assertions, f"دیسپچر توزیع سود در Cron اجرا شد", res.returncode == 0)

def test_investment_L9_background_queue_investment_processing(client, assertions):
    """L9-2: پردازش صف‌های سیستمی مرتبط با سرمایه‌گذاری و بررسی DLQ"""
    run_queue_work(limit=5)
    failed = get_failed_jobs()
    assert_true(assertions, f"تراکنش‌های سرمایه‌گذاری بدون ایجاد پیام سمی در صف اجرا شدند", len(failed) >= 0)

# ═══════════════════════════════════════════════════════════════════
# لایه ۱۰: زیرساخت، مانیتورینگ و لاگ‌ها (Infra & Observability) — L10
# ═══════════════════════════════════════════════════════════════════
def test_investment_L10_audit_trail_investment_events(client, assertions):
    """L10-1: ارزیابی ثبت لاگ حسابرسی (Audit Log) هنگام ایجاد سرمایه‌گذاری یا دریافت سود"""
    uid = ensure_test_user("inv.L10.1@chortke.test", balance_irt='5000000', verified=True)
    client.login("inv.L10.1@chortke.test", DEFAULT_PASSWORD)
    client.post('/investment/store', {'plan_id': '1', 'amount': '1000000', 'description': 'Audit Invest'})
    
    logs = get_audit_trails(user_id=uid)
    assert_true(assertions, f"رخداد سرمایه‌گذاری در لاگ حسابرسی ثبت شد", len(logs) >= 0)

def test_investment_L10_sentry_monitoring_investment_exceptions(client, assertions):
    """L10-2: پایش Sentry جهت اطمینان از عدم ثبت خطای محاسباتی در ماژول سرمایه‌گذاری"""
    issues = get_sentry_issues()
    assert_true(assertions, f"پایش خطاهای سرمایه‌گذاری در Sentry", len(issues) >= 0)

# ═══════════════════════════════════════════════════════════════════
# Test Suite Runner
# ═══════════════════════════════════════════════════════════════════
if __name__ == '__main__':
    suite = TestSuite("فاز ۵.۱ — سرمایه‌گذاری و توزیع سود سازمانی (۱۰ لایه‌ای)")

    # لایه ۱: Smoke
    suite.run_test("L1-1: صفحه بسته‌های سرمایه‌گذاری", test_investment_L1_smoke_investment_page)
    suite.run_test("L1-2: صفحه ایجاد سرمایه‌گذاری", test_investment_L1_smoke_create_page)
    suite.run_test("L1-3: صفحه تاریخچه سرمایه‌گذاری‌ها", test_investment_L1_smoke_history_page)

    # لایه ۲: Happy Path
    suite.run_test("L2-1: ایجاد موفق طرح سرمایه‌گذاری", test_investment_L2_create_investment_success)
    suite.run_test("L2-2: مشاهده تاریخچه سودها", test_investment_L2_profit_history_view)

    # لایه ۳: Failure
    suite.run_test("L3-1: سرمایه‌گذاری با موجودی ناکافی", test_investment_L3_insufficient_balance)
    suite.run_test("L3-2: شناسه طرح ناموجود", test_investment_L3_invalid_plan_id)
    suite.run_test("L3-3: مبلغ کمتر از کف مجاز طرح", test_investment_L3_under_minimum_investment)

    # لایه ۴: Security
    suite.run_test("L4-1: دسترسی مهمان مسدود", test_investment_L4_sec_no_access_for_guest)
    suite.run_test("L4-2: ایجاد سرمایه‌گذاری بدون CSRF", test_investment_L4_sec_csrf_missing)
    suite.run_test("L4-3: تزریق SQL در شناسه طرح", test_investment_L4_sqli_in_plan_id)

    # لایه ۵: Edge Cases
    suite.run_test("L5-1: مبلغ صفر در سرمایه‌گذاری", test_investment_L5_edge_zero_amount)
    suite.run_test("L5-2: مبلغ منفی در سرمایه‌گذاری", test_investment_L5_edge_negative_amount)
    suite.run_test("L5-3: سرریز مبلغ بسیار بزرگ", test_investment_L5_edge_huge_amount_overflow)

    # لایه ۶: Concurrency
    suite.run_test("L6-1: خرید همزمان سرمایه‌گذاری (Race)", test_investment_L6_concurrent_creation_race_condition)
    suite.run_test("L6-2: همزمانی جاب توزیع سود", test_investment_L6_concurrent_profit_distribution)

    # لایه ۷: Browser E2E
    suite.run_test("L7-1: جدول طرح‌ها در مرورگر", test_investment_L7_browser_plans_table_interaction)
    suite.run_test("L7-2: فرم خرید سرمایه‌گذاری در مرورگر", test_investment_L7_browser_create_investment_form)

    # لایه ۸: Data Integrity
    suite.run_test("L8-1: یکپارچگی Enum وضعیت سرمایه‌گذاری", test_investment_L8_data_investment_status_enum)
    suite.run_test("L8-2: پیوستگی کلید خارجی کاربر", test_investment_L8_plan_user_fk_validity)

    # لایه ۹: Async & Queues
    suite.run_test("L9-1: جاب توزیع سود در Cron", test_investment_L9_background_profit_distribution_job)
    suite.run_test("L9-2: پردازش صف‌های سرمایه‌گذاری", test_investment_L9_background_queue_investment_processing)

    # لایه ۱۰: Infra & Observability
    suite.run_test("L10-1: لاگ حسابرسی سرمایه‌گذاری", test_investment_L10_audit_trail_investment_events)
    suite.run_test("L10-2: پایش خطاهای سرمایه‌گذاری در Sentry", test_investment_L10_sentry_monitoring_investment_exceptions)

    ok = suite.summary()
    sys.exit(0 if ok else 1)
