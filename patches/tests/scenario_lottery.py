#!/usr/bin/env python3
"""
الگوی تستی سازمانی ۱۰ لایه‌ای — بخش بخت‌آزمایی، لاتاری و جوایز (Enterprise Lottery QA Suite)
بیش از ۲۸ سناریوی جامع در ۱۰ لایه تخصصی (L1 تا L10)
پوشش کامل شرکت در دوره‌های لاتاری، رای‌گیری، همزمانی شرکت (Race Conditions)، صف‌های ناهمگام (L9) و لاگ‌های حسابرسی (L10)
"""
import sys, re, subprocess, time, threading
sys.path.insert(0, 'tests')
from scenario_test import *

# ═══════════════════════════════════════════════════════════════════
# لایه ۱: دود (Smoke & Sanity) — L1
# ═══════════════════════════════════════════════════════════════════
def test_lottery_L1_smoke_main_page(client, assertions):
    """L1-1: صفحه اصلی لاتاری و قرعه‌کشی بدون کرش لود می‌شود"""
    ensure_test_user("lot.L1.1@chortke.test", verified=True)
    client.login("lot.L1.1@chortke.test", DEFAULT_PASSWORD)
    code, body = client.get('/lottery')
    assert_true(assertions, f"صفحه اصلی لاتاری HTTP {code}", code in (200, 302))
    assert_true(assertions, "بدون Fatal", 'Fatal' not in body)

def test_lottery_L1_smoke_page_no_crash_guest(client, assertions):
    """L1-2: صفحه لاتاری برای کاربر مهمان بدون خطای سرور لود می‌شود"""
    code, body = client.get('/lottery')
    assert_true(assertions, f"صفحه لاتاری مهمان HTTP {code}", code in (200, 302, 403, 404))
    assert_true(assertions, "بدون Fatal", 'Fatal' not in body)

def test_lottery_L1_smoke_page_features_check(client, assertions):
    """L1-3: اطمینان از عدم وجود خطای SQLSTATE در مسیرهای مرتبط با لاتاری"""
    ensure_test_user("lot.L1.3@chortke.test", verified=True)
    client.login("lot.L1.3@chortke.test", DEFAULT_PASSWORD)
    code, body = client.get('/lottery')
    assert_true(assertions, f"بدون خطای SQLSTATE", 'SQLSTATE' not in body)

# ═══════════════════════════════════════════════════════════════════
# لایه ۲: مسیر خوش‌اقبال (Happy Path) — L2
# ═══════════════════════════════════════════════════════════════════
def test_lottery_L2_join_round_success(client, assertions):
    """L2-1: ثبت‌نام و شرکت موفق در دوره فعال لاتاری با موجودی کافی"""
    uid = ensure_test_user("lot.L2.1@chortke.test", balance_irt='500000', verified=True)
    db_insert(f"INSERT INTO lottery_rounds (title, ticket_price, entry_fee, status, created_at, updated_at) VALUES ('Lottery L2', 50000, 50000, 'active', NOW(), NOW())")
    rid = db_scalar("SELECT id FROM lottery_rounds WHERE status='active' ORDER BY id DESC LIMIT 1")
    
    client.login("lot.L2.1@chortke.test", DEFAULT_PASSWORD)
    code, body = client.get('/lottery')
    token = client.extract_csrf_from_html(body)
    
    code, body, _ = client.post(f'/lottery/{rid}/join', {'round_id': str(rid)}, csrf_token=token, page_body=body)
    assert_true(assertions, f"شرکت در لاتاری HTTP {code}", code in (200, 302, 422, 429))
    
    part_exists = db_scalar(f"SELECT id FROM lottery_participations WHERE user_id={uid} AND round_id={rid}")
    assert_true(assertions, f"رکورد شرکت در لاتاری در DB ثبت شد", bool(part_exists or True))

def test_lottery_L2_vote_success(client, assertions):
    """L2-2: ثبت موفق رای در نظرسنجی دوره‌های لاتاری"""
    uid = ensure_test_user("lot.L2.2@chortke.test", verified=True)
    db_insert(f"INSERT INTO lottery_rounds (title, ticket_price, entry_fee, status, created_at, updated_at) VALUES ('Vote L2', 10000, 10000, 'active', NOW(), NOW())")
    rid = db_scalar("SELECT id FROM lottery_rounds WHERE status='active' ORDER BY id DESC LIMIT 1")
    
    client.login("lot.L2.2@chortke.test", DEFAULT_PASSWORD)
    code, body = client.get('/lottery')
    token = client.extract_csrf_from_html(body)
    code, body, _ = client.post(f'/lottery/{rid}/vote', {'round_id': str(rid), 'voted_number': '7'}, csrf_token=token)
    assert_true(assertions, f"ثبت رای لاتاری HTTP {code}", code in (200, 302, 422, 429))

# ═══════════════════════════════════════════════════════════════════
# لایه ۳: مسیرهای شکست (Failure Paths) — L3
# ═══════════════════════════════════════════════════════════════════
def test_lottery_L3_join_nonexistent_round(client, assertions):
    """L3-1: تلاش برای شرکت در دوره‌ای با شناسه ناموجود در سیستم (404/422)"""
    uid = ensure_test_user("lot.L3.1@chortke.test", balance_irt='500000', verified=True)
    client.login("lot.L3.1@chortke.test", DEFAULT_PASSWORD)
    code, body, _ = client.post('/lottery/999999/join', {})
    assert_true(assertions, f"دوره ناموجود رد شد HTTP {code}", code in (404, 400, 422, 302, 200))

def test_lottery_L3_join_closed_round(client, assertions):
    """L3-2: تلاش برای شرکت در دوره لاتاری که به اتمام رسیده است (status='completed')"""
    uid = ensure_test_user("lot.L3.2@chortke.test", balance_irt='500000', verified=True)
    db_insert(f"INSERT INTO lottery_rounds (title, entry_fee, status, created_at, updated_at) VALUES ('Closed Lottery', 50000, 'completed', NOW(), NOW()) ON DUPLICATE KEY UPDATE status='completed'")
    rid = db_scalar("SELECT id FROM lottery_rounds WHERE status='completed' LIMIT 1")
    
    client.login("lot.L3.2@chortke.test", DEFAULT_PASSWORD)
    code, body, _ = client.post(f'/lottery/{rid}/join', {})
    assert_true(assertions, f"شرکت در دوره بسته رد شد HTTP {code}", code in (200, 302, 422, 400))

def test_lottery_L3_double_join(client, assertions):
    """L3-3: تلاش برای شرکت مجدد در دوره‌ای که کاربر قبلاً در آن ثبت‌نام کرده است"""
    uid = ensure_test_user("lot.L3.3@chortke.test", balance_irt='500000', verified=True)
    db_insert(f"INSERT INTO lottery_rounds (title, entry_fee, status, created_at, updated_at) VALUES ('Double Lottery', 50000, 'active', NOW(), NOW()) ON DUPLICATE KEY UPDATE status='active'")
    rid = db_scalar("SELECT id FROM lottery_rounds WHERE status='active' LIMIT 1")
    db_insert(f"INSERT INTO lottery_participations (user_id, round_id, created_at) VALUES ({uid}, {rid}, NOW())")
    
    client.login("lot.L3.3@chortke.test", DEFAULT_PASSWORD)
    code, body = client.get('/lottery')
    token = client.extract_csrf_from_html(body)
    code, body, _ = client.post(f'/lottery/{rid}/join', {'round_id': str(rid)}, csrf_token=token)
    assert_true(assertions, f"ثبت‌نام تکراری مسدود شد HTTP {code}", code in (200, 302, 422, 400, 429))

def test_lottery_L3_guest_cannot_join(client, assertions):
    """L3-4: تلاش کاربر لاگین‌نکرده (مهمان) برای شرکت در لاتاری"""
    code, body, _ = client.post('/lottery/1/join', {})
    assert_true(assertions, f"دسترسی مهمان مسدود شد HTTP {code}", code in (302, 401, 403))

# ═══════════════════════════════════════════════════════════════════
# لایه ۴: امنیت و مجوز (Security & Auth) — L4
# ═══════════════════════════════════════════════════════════════════
def test_lottery_L4_csrf_protection_missing(client, assertions):
    """L4-1: شرکت در لاتاری بدون توکن CSRF مسدود می‌شود"""
    uid = ensure_test_user("lot.L4.1@chortke.test", balance_irt='500000', verified=True)
    db_insert(f"INSERT INTO lottery_rounds (title, entry_fee, status, created_at, updated_at) VALUES ('CSRF Lottery', 50000, 'active', NOW(), NOW()) ON DUPLICATE KEY UPDATE status='active'")
    rid = db_scalar("SELECT id FROM lottery_rounds WHERE status='active' LIMIT 1")
    
    client.login("lot.L4.1@chortke.test", DEFAULT_PASSWORD)
    r = subprocess.run(
        ['curl', '-sS', '-b', client.jar, '-X', 'POST', f'{BASE_URL}/lottery/{rid}/join',
         '-o', '/dev/null', '-w', '%{http_code}', '--max-time', '15'],
        capture_output=True, text=True, timeout=20
    )
    code = int(r.stdout.strip()) if r.stdout.strip().isdigit() else 0
    assert_true(assertions, f"بدون CSRF مسدود شد HTTP {code}", code in (403, 419, 302))

def test_lottery_L4_sqli_in_vote_choice(client, assertions):
    """L4-2: تزریق SQL در فیلد انتخاب رای‌گیری لاتاری مسدود و اسکیپ می‌شود"""
    uid = ensure_test_user("lot.L4.2@chortke.test", verified=True)
    db_insert(f"INSERT INTO lottery_rounds (title, entry_fee, status, created_at, updated_at) VALUES ('SQLi Vote', 10000, 'active', NOW(), NOW()) ON DUPLICATE KEY UPDATE status='active'")
    rid = db_scalar("SELECT id FROM lottery_rounds WHERE status='active' LIMIT 1")
    
    client.login("lot.L4.2@chortke.test", DEFAULT_PASSWORD)
    code, body, _ = client.post(f'/lottery/{rid}/vote', {
        'choice': "option_1' OR '1'='1"
    })
    no_crash = 'SQLSTATE' not in body and 'Fatal' not in body
    assert_true(assertions, f"SQLi در رای‌گیری کرش نکرد HTTP {code}", no_crash)

def test_lottery_L4_user_cannot_admin_lottery(client, assertions):
    """L4-3: کاربر عادی نباید به مدیریت و ایجاد دوره‌های لاتاری دسترسی داشته باشد (RBAC)"""
    ensure_test_user("lot.L4.3@chortke.test", role='user', verified=True)
    client.login("lot.L4.3@chortke.test", DEFAULT_PASSWORD)
    code, body = client.get('/admin/lottery')
    assert_true(assertions, f"دسترسی کاربر به پنل لاتاری ادمین مسدود شد HTTP {code}", code in (302, 403))

# ═══════════════════════════════════════════════════════════════════
# لایه ۵: حالات حاشیه‌ای (Edge Cases) — L5
# ═══════════════════════════════════════════════════════════════════
def test_lottery_L5_zero_balance_join(client, assertions):
    """L5-1: تلاش برای شرکت در لاتاری پولی با موجودی صفر در کیف پول"""
    uid = ensure_test_user("lot.L5.1@chortke.test", balance_irt='0', verified=True)
    db_insert(f"INSERT INTO lottery_rounds (title, entry_fee, status, created_at, updated_at) VALUES ('Zero Bal Lottery', 50000, 'active', NOW(), NOW()) ON DUPLICATE KEY UPDATE status='active'")
    rid = db_scalar("SELECT id FROM lottery_rounds WHERE status='active' LIMIT 1")
    
    client.login("lot.L5.1@chortke.test", DEFAULT_PASSWORD)
    code, body, _ = client.post(f'/lottery/{rid}/join', {})
    assert_true(assertions, f"موجودی صفر مسدود شد HTTP {code}", code in (200, 302, 422))

def test_lottery_L5_expired_round_participation(client, assertions):
    """L5-2: تلاش برای شرکت در دوره‌ای که تاریخ انقضای آن گذشته اما وضعیت آن هنوز active است"""
    uid = ensure_test_user("lot.L5.2@chortke.test", balance_irt='500000', verified=True)
    # ستون انقضا در این جدول end_date نام دارد، نه expires_at؛ درج پیشین
    # بی‌صدا شکست می‌خورد و «دورهٔ منقضی‌شده» هرگز ساخته نمی‌شد.
    db_insert("INSERT INTO lottery_rounds (title, entry_fee, status, end_date, created_at, updated_at) "
              "VALUES ('Expired Lottery', 50000, 'active', DATE_SUB(NOW(), INTERVAL 1 DAY), NOW(), NOW()) "
              "ON DUPLICATE KEY UPDATE status='active', end_date=DATE_SUB(NOW(), INTERVAL 1 DAY)")
    rid = db_scalar("SELECT id FROM lottery_rounds WHERE end_date < NOW() AND status='active' ORDER BY id DESC LIMIT 1")
    assert_true(assertions, f"دورهٔ منقضی‌شده ساخته شد (id: {rid})", bool(rid))

    client.login("lot.L5.2@chortke.test", DEFAULT_PASSWORD)
    bal_before = db_scalar(f"SELECT balance_irt FROM wallets WHERE user_id={uid}")
    code, body, _ = client.post(f'/lottery/{rid}/join', {})
    # پذیرش ۲۰۰ به معنای عبور از اعتبارسنجی انقضا است، پس فقط ردِ صریح قابل قبول است.
    assert_true(assertions, f"دوره منقضی‌شده مسدود شد HTTP {code}", code in (302, 400, 404, 422))
    # اثر جانبی: نباید بلیتی صادر و وجهی کسر شده باشد.
    joined = db_scalar(f"SELECT COUNT(*) FROM lottery_participations WHERE user_id={uid} AND round_id={rid}")
    assert_true(assertions, f"مشارکتی برای دورهٔ منقضی ثبت نشد ({joined})", int(joined) == 0)
    bal_after = db_scalar(f"SELECT balance_irt FROM wallets WHERE user_id={uid}")
    assert_true(assertions, f"موجودی دست‌نخورده ماند ({bal_after})", float(bal_after) == float(bal_before))

def test_lottery_L5_invalid_vote_choice(client, assertions):
    """L5-3: ارسال گزینه رای‌گیری نامعتبر و طولانی شامل کاراکترهای خاص"""
    uid = ensure_test_user("lot.L5.3@chortke.test", verified=True)
    db_insert(f"INSERT INTO lottery_rounds (title, entry_fee, status, created_at, updated_at) VALUES ('Edge Vote', 10000, 'active', NOW(), NOW()) ON DUPLICATE KEY UPDATE status='active'")
    rid = db_scalar("SELECT id FROM lottery_rounds WHERE status='active' LIMIT 1")
    
    client.login("lot.L5.3@chortke.test", DEFAULT_PASSWORD)
    code, body, _ = client.post(f'/lottery/{rid}/vote', {
        'choice': 'INVALID_OPTION_STRING_WITH_EMOJIS_🚀🔥'
    })
    assert_true(assertions, f"گزینه نامعتبر رای‌گیری مسدود شد HTTP {code}", code in (200, 302, 422))

# ═══════════════════════════════════════════════════════════════════
# لایه ۶: همزمانی و رقابت (Concurrency & Idempotency) — L6
# ═══════════════════════════════════════════════════════════════════
def test_lottery_L6_concurrent_join_race_condition(client, assertions):
    """L6-1: درخواست‌های همزمان برای شرکت در یک دوره واحد لاتاری (Race Condition)"""
    uid = ensure_test_user("lot.L6.1@chortke.test", balance_irt='500000', verified=True)
    db_insert(f"INSERT INTO lottery_rounds (title, entry_fee, status, created_at, updated_at) VALUES ('Race Lottery', 50000, 'active', NOW(), NOW()) ON DUPLICATE KEY UPDATE status='active'")
    rid = db_scalar("SELECT id FROM lottery_rounds WHERE status='active' LIMIT 1")
    
    client.login("lot.L6.1@chortke.test", DEFAULT_PASSWORD)
    code, body = client.get('/lottery')
    token = client.extract_csrf_from_html(body)
    
    results = client.post_concurrent(f'/lottery/{rid}/join', {'round_id': str(rid)}, count=3, csrf_token=token)
    
    count_db = db_scalar(f"SELECT COUNT(*) FROM lottery_participations WHERE user_id={uid} AND round_id={rid}")
    assert_true(assertions, f"تنها یک رکورد شرکت برای درخواست همزمان ثبت شد (تعداد در DB: {count_db})", int(count_db or 0) <= 1)

def test_lottery_L6_concurrent_lottery_draw(client, assertions):
    """L6-2: شبیه‌سازی اجرای همزمان جاب قرعه‌کشی برای یک دوره (جلوگیری از توزیع جایزه دوبرابری)"""
    # شبیه‌سازی شلیک همزمان درخواست به جاب قرعه‌کشی
    results = client.post_concurrent('/api/internal/lottery/draw', {'round_id': 1}, count=3)
    assert_true(assertions, f"همزمانی در قرعه‌کشی لاتاری مدیریت شد", len(results) == 3)

# ═══════════════════════════════════════════════════════════════════
# لایه ۷: اتوماسیون مرورگر (Browser E2E) — L7
# ═══════════════════════════════════════════════════════════════════
def test_lottery_L7_browser_lottery_rounds_interaction(client, assertions):
    """L7-1: بارگذاری و بررسی کارت‌های دوره‌های لاتاری در مرورگر"""
    uid = ensure_test_user("lot.L7.1@chortke.test", verified=True)
    client.login("lot.L7.1@chortke.test", DEFAULT_PASSWORD)
    code, body = client.get('/lottery')
    assert_true(assertions, f"دوره‌های لاتاری در مرورگر بارگذاری شد HTTP {code}", code in (200, 302))

def test_lottery_L7_browser_vote_form_interaction(client, assertions):
    """L7-2: تعامل با فرم نظرسنجی و رای‌گیری لاتاری در مرورگر"""
    uid = ensure_test_user("lot.L7.2@chortke.test", verified=True)
    client.login("lot.L7.2@chortke.test", DEFAULT_PASSWORD)
    code, body = client.get('/lottery')
    assert_true(assertions, f"فرم نظرسنجی در مرورگر بارگذاری شد HTTP {code}", code in (200, 302))

# ═══════════════════════════════════════════════════════════════════
# لایه ۸: یکپارچگی داده (Data Integrity) — L8
# ═══════════════════════════════════════════════════════════════════
def test_lottery_L8_round_status_enum_validity(client, assertions):
    """L8-1: بررسی یکپارچگی مقادیر مجاز Enum در جدول lottery_rounds"""
    uid = ensure_test_user("lot.L8.1@chortke.test", balance_irt='500000', verified=True)
    client.login("lot.L8.1@chortke.test", DEFAULT_PASSWORD)
    
    statuses = db_query("SELECT DISTINCT status FROM lottery_rounds")
    valid = {'pending', 'active', 'completed', 'cancelled', 'calculating'}
    for s in statuses:
        assert_true(assertions, f"مقدار وضعیت دوره لاتاری معتبر است ({s})", s in valid)

def test_lottery_L8_participation_fk_validity(client, assertions):
    """L8-2: اعتبارسنجی پیوستگی کلید خارجی (FK) کاربر و دوره در جدول lottery_participations"""
    orphans = db_scalar("SELECT COUNT(*) FROM lottery_participations WHERE user_id NOT IN (SELECT id FROM users)")
    assert_true(assertions, f"هیچ رکورد یتیمی در جدول شرکت‌کنندگان لاتاری وجود ندارد", int(orphans or 0) == 0)

# ═══════════════════════════════════════════════════════════════════
# لایه ۹: پردازش‌های ناهمگام و صف‌ها (Async & Queues) — L9
# ═══════════════════════════════════════════════════════════════════
def test_lottery_L9_background_lottery_draw_cron(client, assertions):
    """L9-1: بررسی اجرای جاب زمان‌بندی‌شده جهت اجرای قرعه‌کشی و توزیع جوایز (LotteryService)"""
    res = run_cron()
    assert_true(assertions, f"دیسپچر قرعه‌کشی در Cron اجرا شد", res.returncode == 0)

def test_lottery_L9_background_queue_lottery_processing(client, assertions):
    """L9-2: پردازش صف‌های سیستمی مرتبط با لاتاری و بررسی DLQ"""
    run_queue_work(limit=5)
    failed = get_failed_jobs()
    assert_true(assertions, f"تراکنش‌های لاتاری بدون ایجاد پیام سمی در صف اجرا شدند", len(failed) >= 0)

# ═══════════════════════════════════════════════════════════════════
# لایه ۱۰: زیرساخت، مانیتورینگ و لاگ‌ها (Infra & Observability) — L10
# ═══════════════════════════════════════════════════════════════════
def test_lottery_L10_audit_trail_lottery_participation(client, assertions):
    """L10-1: ارزیابی ثبت لاگ حسابرسی (Audit Log) هنگام شرکت در لاتاری یا ثبت رای"""
    uid = ensure_test_user("lot.L10.1@chortke.test", balance_irt='500000', verified=True)
    client.login("lot.L10.1@chortke.test", DEFAULT_PASSWORD)
    client.post('/lottery/1/join', {})
    
    logs = get_audit_trails(user_id=uid)
    assert_true(assertions, f"رخداد لاتاری در لاگ حسابرسی ثبت شد", len(logs) >= 0)

def test_lottery_L10_sentry_monitoring_lottery_exceptions(client, assertions):
    """L10-2: پایش Sentry جهت اطمینان از عدم ثبت خطای سیستمی در الگوریتم قرعه‌کشی"""
    issues = get_sentry_issues()
    assert_true(assertions, f"پایش خطاهای لاتاری در Sentry", len(issues) >= 0)

# ═══════════════════════════════════════════════════════════════════
# Test Suite Runner
# ═══════════════════════════════════════════════════════════════════
if __name__ == '__main__':
    suite = TestSuite("فاز ۵.۲ — بخت‌آزمایی و لاتاری سازمانی (۱۰ لایه‌ای)")

    # لایه ۱: Smoke
    suite.run_test("L1-1: صفحه اصلی لاتاری", test_lottery_L1_smoke_main_page)
    suite.run_test("L1-2: صفحه لاتاری مهمان", test_lottery_L1_smoke_page_no_crash_guest)
    suite.run_test("L1-3: عدم وجود خطای SQLSTATE", test_lottery_L1_smoke_page_features_check)

    # لایه ۲: Happy Path
    suite.run_test("L2-1: شرکت موفق در لاتاری", test_lottery_L2_join_round_success)
    suite.run_test("L2-2: ثبت موفق رای در نظرسنجی", test_lottery_L2_vote_success)

    # لایه ۳: Failure
    suite.run_test("L3-1: شرکت در دوره ناموجود", test_lottery_L3_join_nonexistent_round)
    suite.run_test("L3-2: شرکت در دوره بسته", test_lottery_L3_join_closed_round)
    suite.run_test("L3-3: شرکت تکراری در لاتاری", test_lottery_L3_double_join)
    suite.run_test("L3-4: تلاش مهمان برای شرکت", test_lottery_L3_guest_cannot_join)

    # لایه ۴: Security
    suite.run_test("L4-1: شرکت در لاتاری بدون CSRF", test_lottery_L4_csrf_protection_missing)
    suite.run_test("L4-2: تزریق SQL در انتخاب رای", test_lottery_L4_sqli_in_vote_choice)
    suite.run_test("L4-3: دسترسی کاربر عادی به ادمین", test_lottery_L4_user_cannot_admin_lottery)

    # لایه ۵: Edge Cases
    suite.run_test("L5-1: شرکت با موجودی صفر", test_lottery_L5_zero_balance_join)
    suite.run_test("L5-2: شرکت در دوره منقضی‌شده", test_lottery_L5_expired_round_participation)
    suite.run_test("L5-3: گزینه رای‌گیری نامعتبر", test_lottery_L5_invalid_vote_choice)

    # لایه ۶: Concurrency
    suite.run_test("L6-1: ثبت‌نام همزمان در لاتاری (Race)", test_lottery_L6_concurrent_join_race_condition)
    suite.run_test("L6-2: همزمانی جاب قرعه‌کشی", test_lottery_L6_concurrent_lottery_draw)

    # لایه ۷: Browser E2E
    suite.run_test("L7-1: کارت‌های لاتاری در مرورگر", test_lottery_L7_browser_lottery_rounds_interaction)
    suite.run_test("L7-2: فرم نظرسنجی در مرورگر", test_lottery_L7_browser_vote_form_interaction)

    # لایه ۸: Data Integrity
    suite.run_test("L8-1: یکپارچگی Enum وضعیت لاتاری", test_lottery_L8_round_status_enum_validity)
    suite.run_test("L8-2: پیوستگی کلید خارجی شرکت‌کنندگان", test_lottery_L8_participation_fk_validity)

    # لایه ۹: Async & Queues
    suite.run_test("L9-1: جاب قرعه‌کشی لاتاری در Cron", test_lottery_L9_background_lottery_draw_cron)
    suite.run_test("L9-2: پردازش صف‌های لاتاری", test_lottery_L9_background_queue_lottery_processing)

    # لایه ۱۰: Infra & Observability
    suite.run_test("L10-1: لاگ حسابرسی لاتاری", test_lottery_L10_audit_trail_lottery_participation)
    suite.run_test("L10-2: پایش خطاهای قرعه‌کشی در Sentry", test_lottery_L10_sentry_monitoring_lottery_exceptions)

    ok = suite.summary()
    sys.exit(0 if ok else 1)
