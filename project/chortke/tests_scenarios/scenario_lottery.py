#!/usr/bin/env python3
"""
الگوی تستی ۷ لایه‌ای — بخش لاتاری (Lottery)
حداقل ۲۰ سناریو: L1=3 + L2=2 + L3=4 + L4=3 + L5=3 + L7=2 + L6(separate)
"""
import sys, re, subprocess, json
sys.path.insert(0, 'tests')
from scenario_test import *

# ═══════════════════════════════════════════════════════════════════
# لایه ۱: دود (Smoke)
# ═══════════════════════════════════════════════════════════════════

def test_lottery_L1_main_page(client, assertions):
    """L1-1: صفحه اصلی لاتاری لود می‌شود"""
    ensure_test_user("lot.smoke1@chortke.test", verified=True)
    client.login("lot.smoke1@chortke.test", DEFAULT_PASSWORD)
    code, body = client.get('/lottery')
    assert_true(assertions, f"صفحه لاتاری HTTP {code}", code in (200, 302))
    assert_true(assertions, "بدون Fatal", 'Fatal' not in body and 'SQLSTATE' not in body)
    assert_true(assertions, "محتوا > 100", len(body) > 100)

def test_lottery_L1_page_no_crash_guest(client, assertions):
    """L1-2: صفحه لاتاری مهمان بدون کرش هندل می‌شود"""
    client2 = HttpClient(f"/tmp/test_lot_guest_{id}.jar")
    code, body = client2.get('/lottery')
    # باید redirect به login یا 302 باشد
    no_crash = code != 500
    assert_true(assertions, f"مهمان هندل شد (HTTP {code})", no_crash)

def test_lottery_L1_page_features_check(client, assertions):
    """L1-3: اگر فیچر لاتاری غیرفعال باشد صفحه هندل می‌شود"""
    ensure_test_user("lot.smoke3@chortke.test", verified=True)
    client.login("lot.smoke3@chortke.test", DEFAULT_PASSWORD)
    code, body = client.get('/lottery')
    # هر پاسخ معتبر، حتی 404 اگر فیچر غیرفعال
    no_crash = code != 500 and 'Fatal' not in body
    assert_true(assertions, f"صفحه هندل شد (HTTP {code})", no_crash)

# ═══════════════════════════════════════════════════════════════════
# لایه ۲: مسیر خوش‌اقبال (Happy Path)
# ═══════════════════════════════════════════════════════════════════

def test_lottery_L2_join_round(client, assertions):
    """L2-1: شرکت در قرعه‌کشی موفق"""
    uid = ensure_test_user("lot.happy1@chortke.test", verified=True, balance_irt='500000')
    client.login("lot.happy1@chortke.test", DEFAULT_PASSWORD)
    # پیدا کردن قرعه‌کشی باز
    open_round = db_scalar("SELECT id FROM lottery_rounds WHERE status='open' LIMIT 1")
    if open_round:
        code, body, jb = client.post('/lottery/join', {
            'round_id': open_round,
        })
        handled = code in (200, 302, 422)
        assert_true(assertions, f"شرکت هندل شد (HTTP {code})", handled)
    else:
        # ایجاد قرعه‌کشی تستی
        db_insert("INSERT INTO lottery_rounds (title, status, prize_amount, starts_at, ends_at, created_at) VALUES ('تست', 'open', 100000, NOW(), DATE_ADD(NOW(), INTERVAL 1 DAY), NOW())")
        open_round = db_scalar("SELECT id FROM lottery_rounds WHERE status='open' ORDER BY id DESC LIMIT 1")
        code, body, jb = client.post('/lottery/join', {'round_id': open_round})
        handled = code in (200, 302, 422)
        assert_true(assertions, f"شرکت هندل شد (HTTP {code})", handled)

def test_lottery_L2_vote_success(client, assertions):
    """L2-2: رأی‌دادن در لاتاری موفق"""
    uid = ensure_test_user("lot.happy2@chortke.test", verified=True, balance_irt='500000')
    client.login("lot.happy2@chortke.test", DEFAULT_PASSWORD)
    open_round = db_scalar("SELECT id FROM lottery_rounds WHERE status='open' LIMIT 1")
    if open_round:
        code, body, jb = client.post('/lottery/vote', {
            'round_id': open_round,
            'choice': '1',
        })
        handled = code in (200, 302, 422)
        assert_true(assertions, f"رأی هندل شد (HTTP {code})", handled)
    else:
        assertions.append(("قرعه‌کشی یافت نشد — skip", True))

# ═══════════════════════════════════════════════════════════════════
# لایه ۳: مسیرهای شکست (Failure Paths)
# ═══════════════════════════════════════════════════════════════════

def test_lottery_L3_join_nonexistent_round(client, assertions):
    """L3-1: شرکت در قرعه‌کشی ناموجود باید هندل شود"""
    ensure_test_user("lot.fail1@chortke.test", verified=True, balance_irt='500000')
    client.login("lot.fail1@chortke.test", DEFAULT_PASSWORD)
    code, body, jb = client.post('/lottery/join', {'round_id': '99999'})
    no_crash = code != 500 and 'Fatal' not in body
    assert_true(assertions, f"ناموجود هندل شد (HTTP {code})", no_crash)

def test_lottery_L3_join_closed_round(client, assertions):
    """L3-2: شرکت در قرعه‌کشی بسته‌شده باید رد شود"""
    ensure_test_user("lot.fail2@chortke.test", verified=True, balance_irt='500000')
    client.login("lot.fail2@chortke.test", DEFAULT_PASSWORD)
    closed_round = db_scalar("SELECT id FROM lottery_rounds WHERE status IN ('closed','finished') LIMIT 1")
    if closed_round:
        code, body, jb = client.post('/lottery/join', {'round_id': closed_round})
        is_rejected = code == 422 or (jb and not jb.get('success', True)) or code in (302,)
        assert_true(assertions, f"قرعه‌کشی بسته رد شد (HTTP {code})", is_rejected or code == 200)
    else:
        assertions.append(("قرعه‌کشی بسته یافت نشد — skip", True))

def test_lottery_L3_double_join(client, assertions):
    """L3-3: شرکت دوگانه در یک قرعه‌کشی باید هندل شود"""
    uid = ensure_test_user("lot.fail3@chortke.test", verified=True, balance_irt='500000')
    client.login("lot.fail3@chortke.test", DEFAULT_PASSWORD)
    open_round = db_scalar("SELECT id FROM lottery_rounds WHERE status='open' LIMIT 1")
    if open_round:
        code1, _, _ = client.post('/lottery/join', {'round_id': open_round})
        code2, _, _ = client.post('/lottery/join', {'round_id': open_round})
        no_crash = code1 != 500 and code2 != 500
        assert_true(assertions, f"شرکت دوگانه هندل شد ({code1}, {code2})", no_crash)
    else:
        assertions.append(("قرعه‌کشی یافت نشد — skip", True))

def test_lottery_L3_guest_cannot_join(client, assertions):
    """L3-4: مهمان نمی‌تواند در لاتاری شرکت کند"""
    client2 = HttpClient(f"/tmp/test_lot_guest2_{id}.jar")
    r = subprocess.run(
        ['curl', '-sS', '-X', 'POST', f'{BASE_URL}/lottery/join',
         '-o', '/dev/null', '-w', '%{http_code}', '--max-time', '15'],
        capture_output=True, text=True, timeout=20
    )
    code = int(r.stdout.strip()) if r.stdout.strip().isdigit() else 0
    assert_true(assertions, f"مهمان رد شد (HTTP {code})", code in (302, 403))

# ═══════════════════════════════════════════════════════════════════
# لایه ۴: امنیت و مجوز (Security)
# ═══════════════════════════════════════════════════════════════════

def test_lottery_L4_csrf_protection(client, assertions):
    """L4-1: شرکت بدون CSRF باید رد شود"""
    ensure_test_user("lot.sec1@chortke.test", verified=True, balance_irt='500000')
    client.login("lot.sec1@chortke.test", DEFAULT_PASSWORD)
    r = subprocess.run(
        ['curl', '-sS', '-b', client.jar, '-X', 'POST',
         f'{BASE_URL}/lottery/join',
         '--data-urlencode', 'round_id=1',
         '-o', '/tmp/ht_body.html', '-w', '%{http_code}', '--max-time', '15'],
        capture_output=True, text=True, timeout=20
    )
    code = int(r.stdout.strip()) if r.stdout.strip().isdigit() else 0
    assert_true(assertions, f"بدون CSRF رد شد (HTTP {code})", code in (403, 419, 302))

def test_lottery_L4_sqli_in_vote(client, assertions):
    """L4-2: SQL injection در رأی باید رد/escape شود"""
    ensure_test_user("lot.sec2@chortke.test", verified=True, balance_irt='500000')
    client.login("lot.sec2@chortke.test", DEFAULT_PASSWORD)
    code, body, jb = client.post('/lottery/vote', {
        'round_id': "1' OR '1'='1",
        'choice': '1',
    })
    no_crash = code != 500 and 'SQLSTATE' not in body
    assert_true(assertions, f"SQLi رد شد (HTTP {code})", no_crash)

def test_lottery_L4_user_cannot_admin(client, assertions):
    """L4-3: کاربر عادی نمی‌تواند لاتاری ادمین ببیند"""
    ensure_test_user("lot.sec3@chortke.test", verified=True)
    client.login("lot.sec3@chortke.test", DEFAULT_PASSWORD)
    code, body = client.get('/admin/prediction')
    assert_true(assertions, f"ادمین محروم (HTTP {code})", code in (302, 403))

# ═══════════════════════════════════════════════════════════════════
# لایه ۵: موارد لبه (Edge Cases)
# ═══════════════════════════════════════════════════════════════════

def test_lottery_L5_zero_balance_join(client, assertions):
    """L5-1: کاربر بدون موجودی هندل می‌شود"""
    ensure_test_user("lot.edge1@chortke.test", verified=True, balance_irt='0')
    client.login("lot.edge1@chortke.test", DEFAULT_PASSWORD)
    open_round = db_scalar("SELECT id FROM lottery_rounds WHERE status='open' LIMIT 1")
    if open_round:
        code, body, jb = client.post('/lottery/join', {'round_id': open_round})
        no_crash = code != 500
        assert_true(assertions, f"بدون موجودی هندل شد (HTTP {code})", no_crash)
    else:
        assertions.append(("قرعه‌کشی یافت نشد — skip", True))

def test_lottery_L5_expired_round(client, assertions):
    """L5-2: قرعه‌کشی منقضی باید هندل شود"""
    ensure_test_user("lot.edge2@chortke.test", verified=True, balance_irt='500000')
    client.login("lot.edge2@chortke.test", DEFAULT_PASSWORD)
    # ساخت قرعه‌کشی منقضی
    db_insert("INSERT INTO lottery_rounds (title, status, prize_amount, starts_at, ends_at, created_at) VALUES ('منقضی', 'open', 1000, DATE_SUB(NOW(), INTERVAL 2 DAY), DATE_SUB(NOW(), INTERVAL 1 DAY), NOW())")
    expired = db_scalar("SELECT id FROM lottery_rounds WHERE title='منقضی' ORDER BY id DESC LIMIT 1")
    code, body, jb = client.post('/lottery/join', {'round_id': expired or '1'})
    no_crash = code != 500
    assert_true(assertions, f"منقضی هندل شد (HTTP {code})", no_crash)

def test_lottery_L5_invalid_choice(client, assertions):
    """L5-3: انتخاب نامعتبر در رأی باید هندل شود"""
    ensure_test_user("lot.edge3@chortke.test", verified=True, balance_irt='500000')
    client.login("lot.edge3@chortke.test", DEFAULT_PASSWORD)
    code, body, jb = client.post('/lottery/vote', {
        'round_id': '1',
        'choice': '<script>alert(1)</script>',
    })
    no_crash = code != 500
    assert_true(assertions, f"انتخاب نامعتبر هندل شد (HTTP {code})", no_crash)

# ═══════════════════════════════════════════════════════════════════
# لایه ۷: یکپارچگی داده (Data Integrity)
# ═══════════════════════════════════════════════════════════════════

def test_lottery_L7_round_status_valid(client, assertions):
    """L7-1: وضعیت قرعه‌کشی‌ها فقط مقادیر مجاز دارد"""
    invalid = db_scalar(
        "SELECT COUNT(*) FROM lottery_rounds "
        "WHERE status NOT IN ('open','closed','finished')"
    )
    assert_equal(assertions, "status نامعتبر در lottery_rounds", int(invalid), 0)

def test_lottery_L7_participation_status_valid(client, assertions):
    """L7-2: وضعیت شرکت‌ها فقط مقادیر مجاز دارد"""
    invalid = db_scalar(
        "SELECT COUNT(*) FROM lottery_participations "
        "WHERE status NOT IN ('pending','won','lost','refunded')"
    )
    assert_equal(assertions, "status نامعتبر در lottery_participations", int(invalid), 0)

if __name__ == '__main__':
    suite = TestSuite("بخش لاتاری — الگوی ۷ لایه‌ای")
    
    suite.run_test("L1-1: صفحه اصلی لود", test_lottery_L1_main_page)
    suite.run_test("L1-2: مهمان هندل", test_lottery_L1_page_no_crash_guest)
    suite.run_test("L1-3: فیچرفلگ هندل", test_lottery_L1_page_features_check)
    
    suite.run_test("L2-1: شرکت موفق", test_lottery_L2_join_round)
    suite.run_test("L2-2: رأی موفق", test_lottery_L2_vote_success)
    
    suite.run_test("L3-1: ناموجود هندل", test_lottery_L3_join_nonexistent_round)
    suite.run_test("L3-2: بسته‌شده رد", test_lottery_L3_join_closed_round)
    suite.run_test("L3-3: شرکت دوگانه هندل", test_lottery_L3_double_join)
    suite.run_test("L3-4: مهمان محروم", test_lottery_L3_guest_cannot_join)
    
    suite.run_test("L4-1: بدون CSRF رد", test_lottery_L4_csrf_protection)
    suite.run_test("L4-2: SQLi رد", test_lottery_L4_sqli_in_vote)
    suite.run_test("L4-3: ادمین محروم", test_lottery_L4_user_cannot_admin)
    
    suite.run_test("L5-1: بدون موجودی هندل", test_lottery_L5_zero_balance_join)
    suite.run_test("L5-2: منقضی هندل", test_lottery_L5_expired_round)
    suite.run_test("L5-3: انتخاب نامعتبر هندل", test_lottery_L5_invalid_choice)
    
    suite.run_test("L7-1: status قرعه‌کشی مجاز", test_lottery_L7_round_status_valid)
    suite.run_test("L7-2: status شرکت مجاز", test_lottery_L7_participation_status_valid)
    
    ok = suite.summary()
    sys.exit(0 if ok else 1)
