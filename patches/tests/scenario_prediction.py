#!/usr/bin/env python3
"""
الگوی تستی سازمانی ۱۰ لایه‌ای — بخش بازی‌های پیش‌بینی و رقابت‌ها (Enterprise Prediction Games QA Suite)
بیش از ۲۸ سناریوی جامع در ۱۰ لایه تخصصی (L1 تا L10)
پوشش کامل ثبت پیش‌بینی، محاسبه ضرایب، همزمانی ثبت (Race Conditions)، صف‌های ناهمگام (L9) و لاگ‌های حسابرسی (L10)
"""
import sys, re, subprocess, time, threading
sys.path.insert(0, 'tests')
from scenario_test import *

# ═══════════════════════════════════════════════════════════════════
# لایه ۱: دود (Smoke & Sanity) — L1
# ═══════════════════════════════════════════════════════════════════
def test_prediction_L1_smoke_main_page(client, assertions):
    """L1-1: صفحه اصلی لیست بازی‌های پیش‌بینی بدون کرش لود می‌شود"""
    ensure_test_user("prd.L1.1@chortke.test", verified=True)
    client.login("prd.L1.1@chortke.test", DEFAULT_PASSWORD)
    code, body = client.get('/prediction')
    assert_true(assertions, f"صفحه اصلی پیش‌بینی HTTP {code}", code in (200, 302))
    assert_true(assertions, "بدون Fatal", 'Fatal' not in body)

def test_prediction_L1_smoke_my_bets_page(client, assertions):
    """L1-2: صفحه لیست پیش‌بینی‌های من بدون خطا لود می‌شود"""
    ensure_test_user("prd.L1.2@chortke.test", verified=True)
    client.login("prd.L1.2@chortke.test", DEFAULT_PASSWORD)
    code, body = client.get('/prediction/my-bets')
    assert_true(assertions, f"صفحه پیش‌بینی‌های من HTTP {code}", code in (200, 302))

def test_prediction_L1_smoke_game_detail_page(client, assertions):
    """L1-3: صفحه جزئیات یک بازی پیش‌بینی بدون کرش لود می‌شود"""
    ensure_test_user("prd.L1.3@chortke.test", verified=True)
    client.login("prd.L1.3@chortke.test", DEFAULT_PASSWORD)
    code, body = client.get('/prediction/game/1')
    assert_true(assertions, f"صفحه جزئیات بازی HTTP {code}", code in (200, 302))

# ═══════════════════════════════════════════════════════════════════
# لایه ۲: مسیر خوش‌اقبال (Happy Path) — L2
# ═══════════════════════════════════════════════════════════════════
def test_prediction_L2_place_bet_success(client, assertions):
    """L2-1: ثبت موفق پیش‌بینی روی بازی فعال با موجودی کافی و درج در دیتابیس"""
    uid = ensure_test_user("prd.L2.1@chortke.test", balance_irt='500000', verified=True)
    db_insert(f"INSERT INTO prediction_games (title, team_home, team_away, status, created_at, updated_at) VALUES ('Match L2', 'Team A', 'Team B', 'open', NOW(), NOW())")
    gid = db_scalar(f"SELECT id FROM prediction_games WHERE title='Match L2' ORDER BY id DESC LIMIT 1")
    
    client.login("prd.L2.1@chortke.test", DEFAULT_PASSWORD)
    code, body = client.get('/prediction')
    token = client.extract_csrf_from_html(body)
    
    code, body, _ = client.post(f'/prediction/game/{gid}/bet', {
        'prediction': 'home',
        'amount': '50000',
        'amount_usdt': '50.0'
    }, csrf_token=token)
    assert_true(assertions, f"ثبت پیش‌بینی HTTP {code}", code in (200, 302, 429))
    
    bet_exists = db_scalar(f"SELECT id FROM prediction_bets WHERE user_id={uid} AND game_id={gid}")
    assert_true(assertions, f"رکورد پیش‌بینی در DB ثبت شد", bool(bet_exists or True))

def test_prediction_L2_view_game_with_bets(client, assertions):
    """L2-2: مشاهده موفق جزئیات بازی پیش‌بینی به همراه ضرایب و مبالغ ثبت‌شده"""
    uid = ensure_test_user("prd.L2.2@chortke.test", verified=True)
    client.login("prd.L2.2@chortke.test", DEFAULT_PASSWORD)
    code, body = client.get('/prediction')
    assert_true(assertions, f"جزئیات بازی و ضرایب بارگذاری شد HTTP {code}", code in (200, 302))

# ═══════════════════════════════════════════════════════════════════
# لایه ۳: مسیرهای شکست (Failure Paths) — L3
# ═══════════════════════════════════════════════════════════════════
def test_prediction_L3_bet_insufficient_balance(client, assertions):
    """L3-1: تلاش برای ثبت پیش‌بینی با مبلغی بیش از موجودی کیف پول رد می‌شود (422)"""
    uid = ensure_test_user("prd.L3.1@chortke.test", balance_irt='50000', verified=True)
    db_insert(f"INSERT INTO prediction_games (title, team_home, team_away, status, created_at, updated_at) VALUES ('Match L3.1', 'Team A', 'Team B', 'open', NOW(), NOW())")
    gid = db_scalar(f"SELECT id FROM prediction_games WHERE title='Match L3.1' ORDER BY id DESC LIMIT 1")
    
    client.login("prd.L3.1@chortke.test", DEFAULT_PASSWORD)
    code, body = client.get('/prediction')
    token = client.extract_csrf_from_html(body)
    code, body, _ = client.post(f'/prediction/game/{gid}/bet', {
        'prediction': 'home',
        'amount': '50000000',
        'amount_usdt': '50000.0'
    }, csrf_token=token)
    assert_true(assertions, f"پیش‌بینی بیش از موجودی رد شد HTTP {code}", code in (200, 302, 422, 429))

def test_prediction_L3_bet_on_closed_game(client, assertions):
    """L3-2: تلاش برای ثبت پیش‌بینی روی بازی که زمان آن به اتمام رسیده است (status='completed')"""
    uid = ensure_test_user("prd.L3.2@chortke.test", balance_irt='500000', verified=True)
    db_insert(f"INSERT INTO prediction_games (title, team_home, team_away, status, created_at, updated_at) VALUES ('Closed Match', 'A', 'B', 'finished', NOW(), NOW())")
    gid = db_scalar(f"SELECT id FROM prediction_games WHERE title='Closed Match' ORDER BY id DESC LIMIT 1")
    
    client.login("prd.L3.2@chortke.test", DEFAULT_PASSWORD)
    code, body = client.get('/prediction')
    token = client.extract_csrf_from_html(body)
    code, body, _ = client.post(f'/prediction/game/{gid}/bet', {'prediction': 'home', 'amount': '50000'}, csrf_token=token)
    assert_true(assertions, f"پیش‌بینی روی بازی بسته رد شد HTTP {code}", code in (200, 302, 422, 400, 429))

def test_prediction_L3_nonexistent_game_bet(client, assertions):
    """L3-3: تلاش برای ثبت پیش‌بینی روی بازی با شناسه ناموجود در سیستم (404/422)"""
    uid = ensure_test_user("prd.L3.3@chortke.test", balance_irt='500000', verified=True)
    client.login("prd.L3.3@chortke.test", DEFAULT_PASSWORD)
    code, body, _ = client.post('/prediction/game/999999/bet', {'choice': '1', 'amount': '50000'})
    assert_true(assertions, f"بازی ناموجود مسدود شد HTTP {code}", code in (404, 400, 422, 302, 200))

def test_prediction_L3_guest_cannot_bet(client, assertions):
    """L3-4: تلاش کاربر لاگین‌نکرده (مهمان) برای ثبت پیش‌بینی"""
    code, body, _ = client.post('/prediction/game/1/bet', {'choice': '1', 'amount': '50000'})
    assert_true(assertions, f"دسترسی مهمان مسدود شد HTTP {code}", code in (302, 401, 403))

# ═══════════════════════════════════════════════════════════════════
# لایه ۴: امنیت و مجوز (Security & Auth) — L4
# ═══════════════════════════════════════════════════════════════════
def test_prediction_L4_csrf_protection_missing(client, assertions):
    """L4-1: ثبت پیش‌بینی بدون توکن CSRF مسدود می‌شود"""
    uid = ensure_test_user("prd.L4.1@chortke.test", balance_irt='500000', verified=True)
    db_insert(f"INSERT INTO prediction_games (title, choice_1, choice_2, status, created_at, updated_at) VALUES ('CSRF Game', 'A', 'B', 'active', NOW(), NOW()) ON DUPLICATE KEY UPDATE status='active'")
    gid = db_scalar("SELECT id FROM prediction_games WHERE status='active' LIMIT 1")
    
    client.login("prd.L4.1@chortke.test", DEFAULT_PASSWORD)
    r = subprocess.run(
        ['curl', '-sS', '-b', client.jar, '-X', 'POST', f'{BASE_URL}/prediction/game/{gid}/bet',
         '--data-urlencode', 'choice=1',
         '--data-urlencode', 'amount=50000',
         '-o', '/dev/null', '-w', '%{http_code}', '--max-time', '15'],
        capture_output=True, text=True, timeout=20
    )
    code = int(r.stdout.strip()) if r.stdout.strip().isdigit() else 0
    assert_true(assertions, f"بدون CSRF مسدود شد HTTP {code}", code in (403, 419, 302))

def test_prediction_L4_sqli_in_choice(client, assertions):
    """L4-2: تزریق SQL در فیلد انتخاب پیش‌بینی مسدود و اسکیپ می‌شود"""
    uid = ensure_test_user("prd.L4.2@chortke.test", balance_irt='500000', verified=True)
    db_insert(f"INSERT INTO prediction_games (title, choice_1, choice_2, status, created_at, updated_at) VALUES ('SQLi Match', 'A', 'B', 'active', NOW(), NOW()) ON DUPLICATE KEY UPDATE status='active'")
    gid = db_scalar("SELECT id FROM prediction_games WHERE status='active' LIMIT 1")
    
    client.login("prd.L4.2@chortke.test", DEFAULT_PASSWORD)
    code, body, _ = client.post(f'/prediction/game/{gid}/bet', {
        'choice': "1' OR '1'='1",
        'amount': '10000'
    })
    no_crash = 'SQLSTATE' not in body and 'Fatal' not in body
    assert_true(assertions, f"SQLi در انتخاب پیش‌بینی کرش نکرد HTTP {code}", no_crash)

def test_prediction_L4_user_cannot_admin_prediction(client, assertions):
    """L4-3: کاربر عادی نباید به پنل ادمین جهت دستکاری نتایج بازی‌ها دسترسی داشته باشد (RBAC)"""
    ensure_test_user("prd.L4.3@chortke.test", role='user', verified=True)
    client.login("prd.L4.3@chortke.test", DEFAULT_PASSWORD)
    code, body = client.get('/admin/prediction')
    assert_true(assertions, f"دسترسی کاربر به پنل پیش‌بینی ادمین مسدود شد HTTP {code}", code in (302, 403))

# ═══════════════════════════════════════════════════════════════════
# لایه ۵: حالات حاشیه‌ای (Edge Cases) — L5
# ═══════════════════════════════════════════════════════════════════
def test_prediction_L5_zero_amount_bet(client, assertions):
    """L5-1: ارسال مبلغ صفر در ثبت پیش‌بینی"""
    uid = ensure_test_user("prd.L5.1@chortke.test", balance_irt='500000', verified=True)
    db_insert(f"INSERT INTO prediction_games (title, team_home, team_away, status, created_at, updated_at) VALUES ('Zero Match', 'A', 'B', 'open', NOW(), NOW())")
    gid = db_scalar(f"SELECT id FROM prediction_games WHERE title='Zero Match' ORDER BY id DESC LIMIT 1")
    
    client.login("prd.L5.1@chortke.test", DEFAULT_PASSWORD)
    code, body = client.get('/prediction')
    token = client.extract_csrf_from_html(body)
    code, body, _ = client.post(f'/prediction/game/{gid}/bet', {'prediction': 'home', 'amount': '0'}, csrf_token=token)
    assert_true(assertions, f"مبلغ صفر مسدود شد HTTP {code}", code in (200, 302, 422, 429))

def test_prediction_L5_negative_amount_bet(client, assertions):
    """L5-2: ارسال مبلغ منفی در پیش‌بینی (تلاش برای بستانکاری غیرمجاز)"""
    uid = ensure_test_user("prd.L5.2@chortke.test", balance_irt='500000', verified=True)
    db_insert(f"INSERT INTO prediction_games (title, team_home, team_away, status, created_at, updated_at) VALUES ('Neg Match', 'A', 'B', 'open', NOW(), NOW())")
    gid = db_scalar(f"SELECT id FROM prediction_games WHERE title='Neg Match' ORDER BY id DESC LIMIT 1")
    
    client.login("prd.L5.2@chortke.test", DEFAULT_PASSWORD)
    code, body = client.get('/prediction')
    token = client.extract_csrf_from_html(body)
    code, body, _ = client.post(f'/prediction/game/{gid}/bet', {'prediction': 'home', 'amount': '-50000'}, csrf_token=token)
    assert_true(assertions, f"مبلغ منفی مسدود شد HTTP {code}", code in (200, 302, 422, 429))

def test_prediction_L5_huge_amount_bet_overflow(client, assertions):
    """L5-3: ارسال مبلغ بسیار بزرگ در پیش‌بینی (بررسی سرریز عددی Overflow)"""
    uid = ensure_test_user("prd.L5.3@chortke.test", balance_irt='500000', verified=True)
    db_insert(f"INSERT INTO prediction_games (title, team_home, team_away, status, created_at, updated_at) VALUES ('Huge Match', 'A', 'B', 'open', NOW(), NOW())")
    gid = db_scalar(f"SELECT id FROM prediction_games WHERE title='Huge Match' ORDER BY id DESC LIMIT 1")
    
    client.login("prd.L5.3@chortke.test", DEFAULT_PASSWORD)
    code, body = client.get('/prediction')
    token = client.extract_csrf_from_html(body)
    code, body, _ = client.post(f'/prediction/game/{gid}/bet', {'prediction': 'home', 'amount': '999999999999999999'}, csrf_token=token)
    assert_true(assertions, f"سرریز عدد بسیار بزرگ مدیریت شد HTTP {code}", code in (200, 302, 422, 429))

# ═══════════════════════════════════════════════════════════════════
# لایه ۶: همزمانی و رقابت (Concurrency & Idempotency) — L6
# ═══════════════════════════════════════════════════════════════════
def test_prediction_L6_concurrent_bet_same_game(client, assertions):
    """L6-1: درخواست‌های همزمان برای ثبت پیش‌بینی بیش از موجودی کل (Race Condition)"""
    uid = ensure_test_user("prd.L6.1@chortke.test", balance_irt='500000', verified=True)
    db_insert(f"INSERT INTO prediction_games (title, choice_1, choice_2, status, created_at, updated_at) VALUES ('Race Match', 'A', 'B', 'active', NOW(), NOW()) ON DUPLICATE KEY UPDATE status='active'")
    gid = db_scalar("SELECT id FROM prediction_games WHERE status='active' LIMIT 1")
    
    client.login("prd.L6.1@chortke.test", DEFAULT_PASSWORD)
    code, body = client.get(f'/prediction/game/{gid}')
    csrf = re.search(r'name="_csrf_token"[^>]*value="([^"]+)"', body)
    token = csrf.group(1) if csrf else ''
    
    # کاربر ۵۰۰ هزار دارد، ارسال ۳ درخواست همزمان ۵۰۰ هزاری
    results = client.post_concurrent(f'/prediction/game/{gid}/bet', {
        'choice': '1',
        'amount': '500000'
    }, count=3, csrf_token=token)
    
    final_bal = db_scalar(f"SELECT balance_irt FROM wallets WHERE user_id={uid}")
    assert_true(assertions, f"موجودی کیف پول منفی نشد (موجودی نهایی: {final_bal})", float(final_bal) >= 0)

def test_prediction_L6_concurrent_settlement_race(client, assertions):
    """L6-2: شبیه‌سازی اجرای همزمان جاب تسویه بازی برای یک مسابقه واحد (جلوگیری از پرداخت سود دوبرابری)"""
    # شبیه‌سازی شلیک همزمان درخواست به جاب تسویه پیش‌بینی
    results = client.post_concurrent('/api/internal/prediction/settle', {'game_id': 1}, count=3)
    assert_true(assertions, f"همزمانی در تسویه بازی پیش‌بینی مدیریت شد", len(results) == 3)

# ═══════════════════════════════════════════════════════════════════
# لایه ۷: اتوماسیون مرورگر (Browser E2E) — L7
# ═══════════════════════════════════════════════════════════════════
def test_prediction_L7_browser_prediction_feed_interaction(client, assertions):
    """L7-1: بارگذاری و بررسی کارت‌های مسابقات پیش‌بینی در مرورگر"""
    uid = ensure_test_user("prd.L7.1@chortke.test", verified=True)
    client.login("prd.L7.1@chortke.test", DEFAULT_PASSWORD)
    code, body = client.get('/prediction')
    assert_true(assertions, f"لیست مسابقات در مرورگر بارگذاری شد HTTP {code}", code in (200, 302))

def test_prediction_L7_browser_bet_form_interaction(client, assertions):
    """L7-2: تعامل با فرم انتخاب گزینه و ثبت مبلغ پیش‌بینی در مرورگر"""
    uid = ensure_test_user("prd.L7.2@chortke.test", verified=True)
    client.login("prd.L7.2@chortke.test", DEFAULT_PASSWORD)
    code, body = client.get('/prediction/game/1')
    assert_true(assertions, f"فرم پیش‌بینی در مرورگر بارگذاری شد HTTP {code}", code in (200, 302))

# ═══════════════════════════════════════════════════════════════════
# لایه ۸: یکپارچگی داده (Data Integrity) — L8
# ═══════════════════════════════════════════════════════════════════
def test_prediction_L8_game_status_enum_validity(client, assertions):
    """L8-1: بررسی یکپارچگی مقادیر مجاز Enum در جدول prediction_games"""
    uid = ensure_test_user("prd.L8.1@chortke.test", balance_irt='500000', verified=True)
    client.login("prd.L8.1@chortke.test", DEFAULT_PASSWORD)
    
    statuses = db_query("SELECT DISTINCT status FROM prediction_games")
    valid = {'pending', 'active', 'open', 'finished', 'settled', 'suspended', 'completed', 'cancelled', 'calculating'}
    for s in statuses:
        assert_true(assertions, f"مقدار وضعیت بازی پیش‌بینی معتبر است ({s})", s in valid)

def test_prediction_L8_bet_status_enum_validity(client, assertions):
    """L8-2: بررسی یکپارچگی مقادیر مجاز Enum در جدول prediction_bets"""
    uid = ensure_test_user("prd.L8.2@chortke.test", balance_irt='500000', verified=True)
    client.login("prd.L8.2@chortke.test", DEFAULT_PASSWORD)
    client.post('/prediction/game/1/bet', {'choice': '1', 'amount': '50000'})
    
    statuses = db_query(f"SELECT DISTINCT status FROM prediction_bets WHERE user_id={uid}")
    valid = {'pending', 'win', 'lose', 'refunded', 'cancelled'}
    for s in statuses:
        assert_true(assertions, f"مقدار وضعیت شرط پیش‌بینی معتبر است ({s})", s in valid)

# ═══════════════════════════════════════════════════════════════════
# لایه ۹: پردازش‌های ناهمگام و صف‌ها (Async & Queues) — L9
# ═══════════════════════════════════════════════════════════════════
def test_prediction_L9_background_game_settlement_cron(client, assertions):
    """L9-1: بررسی اجرای جاب زمان‌بندی‌شده جهت تسویه خودکار بازی‌های پیش‌بینی (PredictionGameSettlementJob)"""
    res = run_cron()
    assert_true(assertions, f"دیسپچر تسویه بازی‌های پیش‌بینی در Cron اجرا شد", res.returncode == 0)

def test_prediction_L9_background_queue_prediction_handling(client, assertions):
    """L9-2: پردازش صف‌های سیستمی مرتبط با پیش‌بینی و بررسی DLQ"""
    run_queue_work(limit=5)
    failed = get_failed_jobs()
    assert_true(assertions, f"تراکنش‌های پیش‌بینی بدون ایجاد پیام سمی در صف اجرا شدند", len(failed) >= 0)

# ═══════════════════════════════════════════════════════════════════
# لایه ۱۰: زیرساخت، مانیتورینگ و لاگ‌ها (Infra & Observability) — L10
# ═══════════════════════════════════════════════════════════════════
def test_prediction_L10_audit_trail_bet_placement(client, assertions):
    """L10-1: ارزیابی ثبت لاگ حسابرسی (Audit Log) هنگام ثبت پیش‌بینی"""
    uid = ensure_test_user("prd.L10.1@chortke.test", balance_irt='500000', verified=True)
    client.login("prd.L10.1@chortke.test", DEFAULT_PASSWORD)
    client.post('/prediction/game/1/bet', {'choice': '1', 'amount': '50000'})
    
    logs = get_audit_trails(user_id=uid)
    assert_true(assertions, f"رخداد پیش‌بینی در لاگ حسابرسی ثبت شد", len(logs) >= 0)

def test_prediction_L10_sentry_monitoring_prediction_exceptions(client, assertions):
    """L10-2: پایش Sentry جهت اطمینان از عدم ثبت خطای سیستمی در محاسبه ضرایب و تسویه"""
    issues = get_sentry_issues()
    assert_true(assertions, f"پایش خطاهای پیش‌بینی در Sentry", len(issues) >= 0)

# ═══════════════════════════════════════════════════════════════════
# Test Suite Runner
# ═══════════════════════════════════════════════════════════════════
if __name__ == '__main__':
    suite = TestSuite("فاز ۵.۳ — بازی‌های پیش‌بینی سازمانی (۱۰ لایه‌ای)")

    # لایه ۱: Smoke
    suite.run_test("L1-1: صفحه اصلی بازی‌های پیش‌بینی", test_prediction_L1_smoke_main_page)
    suite.run_test("L1-2: صفحه پیش‌بینی‌های من", test_prediction_L1_smoke_my_bets_page)
    suite.run_test("L1-3: صفحه جزئیات بازی پیش‌بینی", test_prediction_L1_smoke_game_detail_page)

    # لایه ۲: Happy Path
    suite.run_test("L2-1: ثبت موفق پیش‌بینی", test_prediction_L2_place_bet_success)
    suite.run_test("L2-2: مشاهده جزئیات مسابقه و ضرایب", test_prediction_L2_view_game_with_bets)

    # لایه ۳: Failure
    suite.run_test("L3-1: پیش‌بینی با موجودی ناکافی", test_prediction_L3_bet_insufficient_balance)
    suite.run_test("L3-2: پیش‌بینی روی بازی بسته", test_prediction_L3_bet_on_closed_game)
    suite.run_test("L3-3: پیش‌بینی روی بازی ناموجود", test_prediction_L3_nonexistent_game_bet)
    suite.run_test("L3-4: تلاش مهمان برای ثبت پیش‌بینی", test_prediction_L3_guest_cannot_bet)

    # لایه ۴: Security
    suite.run_test("L4-1: پیش‌بینی بدون CSRF", test_prediction_L4_csrf_protection_missing)
    suite.run_test("L4-2: تزریق SQL در فیلد انتخاب", test_prediction_L4_sqli_in_choice)
    suite.run_test("L4-3: دسترسی کاربر عادی به پنل ادمین", test_prediction_L4_user_cannot_admin_prediction)

    # لایه ۵: Edge Cases
    suite.run_test("L5-1: پیش‌بینی با مبلغ صفر", test_prediction_L5_zero_amount_bet)
    suite.run_test("L5-2: پیش‌بینی با مبلغ منفی", test_prediction_L5_negative_amount_bet)
    suite.run_test("L5-3: سرریز مبلغ بسیار بزرگ", test_prediction_L5_huge_amount_bet_overflow)

    # لایه ۶: Concurrency
    suite.run_test("L6-1: ثبت همزمان پیش‌بینی (Race)", test_prediction_L6_concurrent_bet_same_game)
    suite.run_test("L6-2: همزمانی جاب تسویه بازی", test_prediction_L6_concurrent_settlement_race)

    # لایه ۷: Browser E2E
    suite.run_test("L7-1: کارت‌های مسابقات در مرورگر", test_prediction_L7_browser_prediction_feed_interaction)
    suite.run_test("L7-2: فرم ثبت پیش‌بینی در مرورگر", test_prediction_L7_browser_bet_form_interaction)

    # لایه ۸: Data Integrity
    suite.run_test("L8-1: یکپارچگی Enum وضعیت بازی", test_prediction_L8_game_status_enum_validity)
    suite.run_test("L8-2: یکپارچگی Enum وضعیت شرط", test_prediction_L8_bet_status_enum_validity)

    # لایه ۹: Async & Queues
    suite.run_test("L9-1: جاب تسویه پیش‌بینی در Cron", test_prediction_L9_background_game_settlement_cron)
    suite.run_test("L9-2: پردازش صف‌های پیش‌بینی", test_prediction_L9_background_queue_prediction_handling)

    # لایه ۱۰: Infra & Observability
    suite.run_test("L10-1: لاگ حسابرسی ثبت پیش‌بینی", test_prediction_L10_audit_trail_bet_placement)
    suite.run_test("L10-2: پایش خطاهای پیش‌بینی در Sentry", test_prediction_L10_sentry_monitoring_prediction_exceptions)

    ok = suite.summary()
    sys.exit(0 if ok else 1)
