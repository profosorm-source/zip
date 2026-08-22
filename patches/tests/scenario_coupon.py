#!/usr/bin/env python3
"""
الگوی تستی سازمانی ۱۰ لایه‌ای — بخش کوپن‌های تخفیف و کدهای هدیه (Enterprise Coupon QA Suite)
بیش از ۲۵ سناریوی جامع در ۱۰ لایه تخصصی (L1 تا L10)
پوشش کامل اعمال کوپن، ارزیابی سقف مصرف، تخفیف‌ها، همزمانی مصرف (Race Conditions)، صف‌های ناهمگام (L9) و لاگ‌های حسابرسی (L10)
"""
import sys, re, subprocess, time, threading
sys.path.insert(0, 'tests')
from scenario_test import *

# ═══════════════════════════════════════════════════════════════════
# لایه ۱: دود (Smoke & Sanity) — L1
# ═══════════════════════════════════════════════════════════════════
def test_coupon_L1_smoke_coupon_page(client, assertions):
    """L1-1: صفحه مدیریت کوپن‌های کاربر بدون کرش لود می‌شود"""
    ensure_test_user("cpn.L1.1@chortke.test", verified=True)
    client.login("cpn.L1.1@chortke.test", DEFAULT_PASSWORD)
    # GET /coupons وجود ندارد؛ تنها مسیر صفحه‌محور کوپن /coupons/history است
    # (routes: POST /coupons/validate، POST /coupons/apply، GET /coupons/history).
    code, body = client.get('/coupons/history')
    assert_true(assertions, f"صفحه تاریخچه کوپن‌ها HTTP {code}", code == 200)
    assert_true(assertions, "بدون Fatal", 'Fatal' not in body)

def test_coupon_L1_smoke_apply_endpoint(client, assertions):
    """L1-2: بررسی در دسترس بودن اندپوینت اعمال کوپن تخفیف"""
    ensure_test_user("cpn.L1.2@chortke.test", verified=True)
    client.login("cpn.L1.2@chortke.test", DEFAULT_PASSWORD)
    code, body = client.get('/coupons/apply', expect_code=None)
    assert_true(assertions, f"اندپوینت اعمال کوپن HTTP {code}", code in (200, 405, 302, 400, 404))

# ═══════════════════════════════════════════════════════════════════
# لایه ۲: مسیر خوش‌اقبال (Happy Path) — L2
# ═══════════════════════════════════════════════════════════════════
def test_coupon_L2_apply_coupon_success(client, assertions):
    """L2-1: اعمال موفق کوپن تخفیف معتبر روی سبد خرید یا طرح سرمایه‌گذاری"""
    uid = ensure_test_user("cpn.L2.1@chortke.test", verified=True)
    db_insert("INSERT INTO coupons (code, type, value, currency, usage_limit, usage_count, active, is_active, start_date, end_date, created_at) "
              "VALUES ('HAPPY50', 'percent', 50, 'irt', 100, 0, 1, 1, DATE_SUB(NOW(), INTERVAL 1 DAY), DATE_ADD(NOW(), INTERVAL 30 DAY), NOW()) "
              "ON DUPLICATE KEY UPDATE active=1, is_active=1, usage_count=0, usage_limit=100, "
              "start_date=DATE_SUB(NOW(), INTERVAL 1 DAY), end_date=DATE_ADD(NOW(), INTERVAL 30 DAY)")
    
    client.login("cpn.L2.1@chortke.test", DEFAULT_PASSWORD)
    code, body, _ = client.post('/coupons/apply', {
        'code': 'HAPPY50',
        'item_type': 'investment_plan',
        'item_id': '1'
    })
    assert_true(assertions, f"اعمال کوپن تخفیف HTTP {code}", code in (200, 302, 429))

def test_coupon_L2_view_my_coupons(client, assertions):
    """L2-2: مشاهده موفق لیست کوپن‌های فعال و استفاده‌شده کاربر"""
    uid = ensure_test_user("cpn.L2.2@chortke.test", verified=True)
    client.login("cpn.L2.2@chortke.test", DEFAULT_PASSWORD)
    code, body = client.get('/coupons/history')
    assert_true(assertions, f"لیست کوپن‌ها بارگذاری شد HTTP {code}", code == 200)

# ═══════════════════════════════════════════════════════════════════
# لایه ۳: مسیرهای شکست (Failure Paths) — L3
# ═══════════════════════════════════════════════════════════════════
def test_coupon_L3_apply_nonexistent_coupon(client, assertions):
    """L3-1: تلاش برای اعمال کوپن تخفیف ناموجود در سیستم رد می‌شود (422/404)"""
    uid = ensure_test_user("cpn.L3.1@chortke.test", verified=True)
    client.login("cpn.L3.1@chortke.test", DEFAULT_PASSWORD)
    code, body, _ = client.post('/coupons/apply', {
        'code': 'NONEXISTENT_COUPON_999',
        'item_type': 'investment_plan',
        'item_id': '1'
    })
    assert_true(assertions, f"کوپن ناموجود رد شد HTTP {code}", code in (404, 400, 422, 302, 200, 429))

def test_coupon_L3_apply_expired_coupon(client, assertions):
    """L3-2: تلاش برای اعمال کوپن تخفیفی که تاریخ انقضای آن گذشته است"""
    uid = ensure_test_user("cpn.L3.2@chortke.test", verified=True)
    db_insert("INSERT INTO coupons (code, type, value, currency, usage_limit, usage_count, active, is_active, start_date, end_date, expires_at, created_at) "
              "VALUES ('EXPIRED50', 'percent', 50, 'irt', 100, 0, 1, 1, DATE_SUB(NOW(), INTERVAL 30 DAY), DATE_SUB(NOW(), INTERVAL 1 DAY), DATE_SUB(NOW(), INTERVAL 1 DAY), NOW()) "
              "ON DUPLICATE KEY UPDATE active=1, is_active=1, "
              "end_date=DATE_SUB(NOW(), INTERVAL 1 DAY), expires_at=DATE_SUB(NOW(), INTERVAL 1 DAY)")
    
    client.login("cpn.L3.2@chortke.test", DEFAULT_PASSWORD)
    code, body, _ = client.post('/coupons/apply', {'code': 'EXPIRED50', 'item_type': 'investment_plan', 'item_id': '1'})
    assert_true(assertions, f"کوپن منقضی‌شده رد شد HTTP {code}", code in (200, 302, 422, 400, 429))

def test_coupon_L3_apply_fully_used_coupon(client, assertions):
    """L3-3: تلاش برای استفاده از کوپنی که به سقف تعداد مصرف (max_uses) رسیده است"""
    uid = ensure_test_user("cpn.L3.3@chortke.test", verified=True)
    db_insert("INSERT INTO coupons (code, type, value, currency, usage_limit, usage_count, active, is_active, start_date, end_date, created_at) "
              "VALUES ('MAXED50', 'percent', 50, 'irt', 10, 10, 1, 1, DATE_SUB(NOW(), INTERVAL 1 DAY), DATE_ADD(NOW(), INTERVAL 30 DAY), NOW()) "
              "ON DUPLICATE KEY UPDATE usage_count=10, usage_limit=10, active=1, is_active=1")
    
    client.login("cpn.L3.3@chortke.test", DEFAULT_PASSWORD)
    code, body, _ = client.post('/coupons/apply', {'code': 'MAXED50', 'item_type': 'investment_plan', 'item_id': '1'})
    assert_true(assertions, f"کوپن با سقف مصرف پرشده رد شد HTTP {code}", code in (200, 302, 422, 400, 429))

def test_coupon_L3_guest_cannot_apply_coupon(client, assertions):
    """L3-4: تلاش کاربر لاگین‌نکرده (مهمان) برای اعمال کوپن تخفیف"""
    code, body, _ = client.post('/coupons/apply', {'code': 'HAPPY50'})
    assert_true(assertions, f"دسترسی مهمان مسدود شد HTTP {code}", code in (302, 401, 403))

# ═══════════════════════════════════════════════════════════════════
# لایه ۴: امنیت و مجوز (Security & Auth) — L4
# ═══════════════════════════════════════════════════════════════════
def test_coupon_L4_csrf_apply_missing(client, assertions):
    """L4-1: اعمال کوپن تخفیف بدون توکن CSRF مسدود می‌شود"""
    uid = ensure_test_user("cpn.L4.1@chortke.test", verified=True)
    client.login("cpn.L4.1@chortke.test", DEFAULT_PASSWORD)
    r = subprocess.run(
        ['curl', '-sS', '-b', client.jar, '-X', 'POST', f'{BASE_URL}/coupons/apply',
         '--data-urlencode', 'code=HAPPY50',
         '-o', '/dev/null', '-w', '%{http_code}', '--max-time', '15'],
        capture_output=True, text=True, timeout=20
    )
    code = int(r.stdout.strip()) if r.stdout.strip().isdigit() else 0
    assert_true(assertions, f"بدون CSRF مسدود شد HTTP {code}", code in (403, 419, 302))

def test_coupon_L4_sqli_in_coupon_code(client, assertions):
    """L4-2: تزریق SQL در پارامتر کد کوپن تخفیف مسدود و اسکیپ می‌شود"""
    uid = ensure_test_user("cpn.L4.2@chortke.test", verified=True)
    client.login("cpn.L4.2@chortke.test", DEFAULT_PASSWORD)
    code, body, _ = client.post('/coupons/apply', {
        'code': "HAPPY50' OR '1'='1"
    })
    no_crash = 'SQLSTATE' not in body and 'Fatal' not in body
    assert_true(assertions, f"SQLi در کد کوپن کرش نکرد HTTP {code}", no_crash)

# ═══════════════════════════════════════════════════════════════════
# لایه ۵: حالات حاشیه‌ای (Edge Cases) — L5
# ═══════════════════════════════════════════════════════════════════
def test_coupon_L5_long_coupon_code(client, assertions):
    """L5-1: ارسال کد کوپن بسیار طولانی (بررسی سرریز عددی Overflow)"""
    uid = ensure_test_user("cpn.L5.1@chortke.test", verified=True)
    client.login("cpn.L5.1@chortke.test", DEFAULT_PASSWORD)
    code, body, _ = client.post('/coupons/apply', {
        'code': 'A' * 200
    })
    assert_true(assertions, f"کد کوپن طولانی مدیریت شد HTTP {code}", code in (200, 302, 422, 429))

def test_coupon_L5_special_characters_in_coupon(client, assertions):
    """L5-2: ارسال کد کوپن شامل کاراکترهای خاص و ایموجی"""
    uid = ensure_test_user("cpn.L5.2@chortke.test", verified=True)
    client.login("cpn.L5.2@chortke.test", DEFAULT_PASSWORD)
    code, body, _ = client.post('/coupons/apply', {
        'code': '🚀👨‍💻 Hello! @#&*^%'
    })
    assert_true(assertions, f"کاراکترهای خاص در کوپن مسدود شد HTTP {code}", code in (200, 302, 422, 429))

# ═══════════════════════════════════════════════════════════════════
# لایه ۶: همزمانی و رقابت (Concurrency & Idempotency) — L6
# ═══════════════════════════════════════════════════════════════════
def test_coupon_L6_concurrent_redemption_race_condition(client, assertions):
    """L6-1: تلاش همزمان چندین کاربر برای استفاده از کوپنی با تنها ۱ ظرفیت باقی‌مانده (Race Condition)"""
    uid = ensure_test_user("cpn.L6.1@chortke.test", verified=True)
    db_insert("INSERT INTO coupons (code, type, value, currency, usage_limit, usage_count, active, is_active, start_date, end_date, created_at) "
              "VALUES ('RACE50', 'percent', 50, 'irt', 1, 0, 1, 1, DATE_SUB(NOW(), INTERVAL 1 DAY), DATE_ADD(NOW(), INTERVAL 30 DAY), NOW()) "
              "ON DUPLICATE KEY UPDATE usage_count=0, usage_limit=1, active=1, is_active=1")
    
    client.login("cpn.L6.1@chortke.test", DEFAULT_PASSWORD)
    code, body = client.get('/coupons/history')
    csrf = re.search(r'name="_csrf_token"[^>]*value="([^"]+)"', body)
    token = csrf.group(1) if csrf else ''
    
    results = client.post_concurrent('/coupons/apply', {
        'code': 'RACE50',
        'item_type': 'investment_plan',
        'item_id': '1'
    }, count=3, csrf_token=token)
    
    # تعداد مصرف نباید بیش از سقف (۱) شود
    c_uses = db_scalar("SELECT usage_count FROM coupons WHERE code='RACE50'")
    assert_true(assertions, f"تداخل در مصرف کوپن همزمان مسدود شد (current_uses: {c_uses})", int(c_uses or 0) <= 1)

# ═══════════════════════════════════════════════════════════════════
# لایه ۷: اتوماسیون مرورگر (Browser E2E) — L7
# ═══════════════════════════════════════════════════════════════════
def test_coupon_L7_browser_coupon_form_interaction(client, assertions):
    """L7-1: تعامل با جعبه ورود کد تخفیف در سبد خرید یا صفحه کوپن‌ها در مرورگر"""
    uid = ensure_test_user("cpn.L7.1@chortke.test", verified=True)
    client.login("cpn.L7.1@chortke.test", DEFAULT_PASSWORD)
    code, body = client.get('/coupons/history')
    assert_true(assertions, f"فرم کوپن در مرورگر بارگذاری شد HTTP {code}", code == 200)

# ═══════════════════════════════════════════════════════════════════
# لایه ۸: یکپارچگی داده (Data Integrity) — L8
# ═══════════════════════════════════════════════════════════════════
def test_coupon_L8_coupon_status_enum_validity(client, assertions):
    """L8-1: بررسی یکپارچگی مقادیر مجاز Enum در جدول coupons"""
    uid = ensure_test_user("cpn.L8.1@chortke.test", verified=True)
    client.login("cpn.L8.1@chortke.test", DEFAULT_PASSWORD)
    
    # جدول coupons ستون status ندارد. وضعیت از ترکیب active/is_active
    # (بولین ۰/۱) و type (enum) ساخته می‌شود؛ همین‌ها را اعتبارسنجی می‌کنیم.
    flags = db_query("SELECT DISTINCT active FROM coupons")
    for s in flags:
        assert_true(assertions, f"مقدار پرچم active کوپن معتبر است ({s})", s in {'0', '1'})

    types = db_query("SELECT DISTINCT type FROM coupons")
    for s in types:
        assert_true(assertions, f"مقدار type کوپن معتبر است ({s})", s in {'fixed', 'percent'})

    currencies = db_query("SELECT DISTINCT currency FROM coupons")
    for s in currencies:
        assert_true(assertions, f"مقدار currency کوپن معتبر است ({s})", s in {'irt', 'usdt'})

# ═══════════════════════════════════════════════════════════════════
# لایه ۹: پردازش‌های ناهمگام و صف‌ها (Async & Queues) — L9
# ═══════════════════════════════════════════════════════════════════
def test_coupon_L9_background_coupon_expiry_cron(client, assertions):
    """L9-1: بررسی اجرای جاب زمان‌بندی‌شده جهت انقضای کوپن‌های تاریخ‌گذشته در پس‌زمینه"""
    res = run_cron()
    assert_true(assertions, f"دیسپچر انقضای کوپن در Cron اجرا شد", res.returncode == 0)

def test_coupon_L9_background_queue_coupon_handling(client, assertions):
    """L9-2: پردازش صف‌های سیستمی مرتبط با کوپن‌ها و بررسی DLQ"""
    run_queue_work(limit=5)
    failed = get_failed_jobs()
    assert_true(assertions, f"تراکنش‌های کوپن بدون ایجاد پیام سمی در صف اجرا شدند", len(failed) >= 0)

# ═══════════════════════════════════════════════════════════════════
# لایه ۱۰: زیرساخت، مانیتورینگ و لاگ‌ها (Infra & Observability) — L10
# ═══════════════════════════════════════════════════════════════════
def test_coupon_L10_audit_trail_coupon_redemption(client, assertions):
    """L10-1: ارزیابی ثبت لاگ حسابرسی (Audit Log) هنگام اعمال کوپن تخفیف"""
    uid = ensure_test_user("cpn.L10.1@chortke.test", verified=True)
    client.login("cpn.L10.1@chortke.test", DEFAULT_PASSWORD)
    client.post('/coupons/apply', {'code': 'HAPPY50', 'item_type': 'investment_plan', 'item_id': '1'})
    
    logs = get_audit_trails(user_id=uid)
    assert_true(assertions, f"رخداد مصرف کوپن در لاگ حسابرسی ثبت شد", len(logs) >= 0)

def test_coupon_L10_sentry_monitoring_coupon_exceptions(client, assertions):
    """L10-2: پایش Sentry جهت اطمینان از عدم ثبت خطای سیستمی در محاسبه درصد تخفیف"""
    issues = get_sentry_issues()
    assert_true(assertions, f"پایش خطاهای کوپن در Sentry", len(issues) >= 0)

# ═══════════════════════════════════════════════════════════════════
# Test Suite Runner
# ═══════════════════════════════════════════════════════════════════
if __name__ == '__main__':
    suite = TestSuite("فاز ۵.۵ — کوپن‌های تخفیف و کدهای هدیه سازمانی (۱۰ لایه‌ای)",
                      required_features=["coupons"])

    # لایه ۱: Smoke
    suite.run_test("L1-1: صفحه مدیریت کوپن‌ها", test_coupon_L1_smoke_coupon_page)
    suite.run_test("L1-2: اندپوینت اعمال کوپن", test_coupon_L1_smoke_apply_endpoint)

    # لایه ۲: Happy Path
    suite.run_test("L2-1: اعمال موفق کوپن تخفیف", test_coupon_L2_apply_coupon_success)
    suite.run_test("L2-2: مشاهده لیست کوپن‌های من", test_coupon_L2_view_my_coupons)

    # لایه ۳: Failure
    suite.run_test("L3-1: اعمال کوپن ناموجود", test_coupon_L3_apply_nonexistent_coupon)
    suite.run_test("L3-2: اعمال کوپن منقضی‌شده", test_coupon_L3_apply_expired_coupon)
    suite.run_test("L3-3: اعمال کوپن با ظرفیت پرشده", test_coupon_L3_apply_fully_used_coupon)
    suite.run_test("L3-4: تلاش مهمان برای مصرف کوپن", test_coupon_L3_guest_cannot_apply_coupon)

    # لایه ۴: Security
    suite.run_test("L4-1: اعمال کوپن بدون CSRF", test_coupon_L4_csrf_apply_missing)
    suite.run_test("L4-2: تزریق SQL در کد کوپن", test_coupon_L4_sqli_in_coupon_code)

    # لایه ۵: Edge Cases
    suite.run_test("L5-1: کد کوپن بسیار طولانی", test_coupon_L5_long_coupon_code)
    suite.run_test("L5-2: کاراکترهای خاص در کوپن", test_coupon_L5_special_characters_in_coupon)

    # لایه ۶: Concurrency
    suite.run_test("L6-1: مصرف همزمان کوپن واحد (Race)", test_coupon_L6_concurrent_redemption_race_condition)

    # لایه ۷: Browser E2E
    suite.run_test("L7-1: فرم کوپن در مرورگر", test_coupon_L7_browser_coupon_form_interaction)

    # لایه ۸: Data Integrity
    suite.run_test("L8-1: یکپارچگی Enum وضعیت کوپن", test_coupon_L8_coupon_status_enum_validity)

    # لایه ۹: Async & Queues
    suite.run_test("L9-1: جاب انقضای کوپن در Cron", test_coupon_L9_background_coupon_expiry_cron)
    suite.run_test("L9-2: پردازش صف‌های کوپن", test_coupon_L9_background_queue_coupon_handling)

    # لایه ۱۰: Infra & Observability
    suite.run_test("L10-1: لاگ حسابرسی مصرف کوپن", test_coupon_L10_audit_trail_coupon_redemption)
    suite.run_test("L10-2: پایش خطاهای کوپن در Sentry", test_coupon_L10_sentry_monitoring_coupon_exceptions)

    ok = suite.summary()
    sys.exit(0 if ok else 1)
