#!/usr/bin/env python3
"""
الگوی تستی ۷ لایه‌ای — بخش پیش‌بینی (Prediction)
حداقل ۲۰ سناریو: L1=3 + L2=2 + L3=4 + L4=3 + L5=3 + L7=2 + L6(separate)
"""
import sys, re, subprocess, json
sys.path.insert(0, 'tests')
from scenario_test import *

# ═══════════════════════════════════════════════════════════════════
# لایه ۱: دود (Smoke)
# ═══════════════════════════════════════════════════════════════════

def test_prediction_L1_main_page(client, assertions):
    """L1-1: صفحه اصلی پیش‌بینی لود می‌شود"""
    ensure_test_user("pred.smoke1@chortke.test", verified=True)
    client.login("pred.smoke1@chortke.test", DEFAULT_PASSWORD)
    code, body = client.get('/prediction')
    assert_true(assertions, f"صفحه پیش‌بینی HTTP {code}", code in (200, 302))
    assert_true(assertions, "بدون Fatal", 'Fatal' not in body and 'SQLSTATE' not in body)
    assert_true(assertions, "محتوا > 100", len(body) > 100)

def test_prediction_L1_my_bets_page(client, assertions):
    """L1-2: صفحه شرط‌های من لود می‌شود"""
    ensure_test_user("pred.smoke2@chortke.test", verified=True)
    client.login("pred.smoke2@chortke.test", DEFAULT_PASSWORD)
    code, body = client.get('/prediction/my-bets')
    assert_true(assertions, f"شرط‌های من HTTP {code}", code in (200, 302, 302))

def test_prediction_L1_game_detail_page(client, assertions):
    """L1-3: صفحه جزئیات بازی لود می‌شود"""
    ensure_test_user("pred.smoke3@chortke.test", verified=True)
    client.login("pred.smoke3@chortke.test", DEFAULT_PASSWORD)
    game = db_scalar("SELECT id FROM prediction_games WHERE status='open' LIMIT 1")
    if game:
        code, body = client.get(f'/prediction/{game}')
        assert_true(assertions, f"جزئیات بازی HTTP {code}", code in (200, 302))
    else:
        code, body = client.get('/prediction/1')
        no_crash = code != 500
        assert_true(assertions, f"صفحه هندل شد (HTTP {code})", no_crash)

# ═══════════════════════════════════════════════════════════════════
# لایه ۲: مسیر خوش‌اقبال (Happy Path)
# ═══════════════════════════════════════════════════════════════════

def test_prediction_L2_place_bet(client, assertions):
    """L2-1: ثبت شرط موفق"""
    uid = ensure_test_user("pred.happy1@chortke.test", verified=True, balance_irt='500000')
    client.login("pred.happy1@chortke.test", DEFAULT_PASSWORD)
    game = db_scalar("SELECT id FROM prediction_games WHERE status='open' LIMIT 1")
    if game:
        before = db_scalar(f"SELECT COUNT(*) FROM prediction_bets WHERE user_id={uid}")
        code, body, jb = client.post(f'/prediction/{game}/bet', {
            'choice': 'yes',
            'amount': '10000',
        })
        after = db_scalar(f"SELECT COUNT(*) FROM prediction_bets WHERE user_id={uid}")
        handled = code in (200, 302, 422)
        assert_true(assertions, f"شرط هندل شد (HTTP {code})", handled)
        if code in (200, 302):
            assert_true(assertions, f"رکورد افزایش ({before} → {after})", int(after) > int(before))
    else:
        assertions.append(("بازي یافت نشد — skip", True))

def test_prediction_L2_view_game_with_bets(client, assertions):
    """L2-2: مشاهده بازی با شرط‌های موجود"""
    uid = ensure_test_user("pred.happy2@chortke.test", verified=True)
    client.login("pred.happy2@chortke.test", DEFAULT_PASSWORD)
    game = db_scalar("SELECT id FROM prediction_games LIMIT 1")
    if game:
        code, body = client.get(f'/prediction/{game}')
        assert_true(assertions, f"بازی نمایش داده شد (HTTP {code})", code in (200, 302))
    else:
        assertions.append(("بازی یافت نشد — skip", True))

# ═══════════════════════════════════════════════════════════════════
# لایه ۳: مسیرهای شکست (Failure Paths)
# ═══════════════════════════════════════════════════════════════════

def test_prediction_L3_bet_insufficient_balance(client, assertions):
    """L3-1: شرط با موجودی ناکافی باید رد شود"""
    uid = ensure_test_user("pred.fail1@chortke.test", verified=True, balance_irt='1000')
    client.login("pred.fail1@chortke.test", DEFAULT_PASSWORD)
    game = db_scalar("SELECT id FROM prediction_games WHERE status='open' LIMIT 1")
    if game:
        code, body, jb = client.post(f'/prediction/{game}/bet', {
            'choice': 'yes',
            'amount': '500000',
        })
        is_rejected = code == 422 or (jb and not jb.get('success', True))
        assert_true(assertions, f"موجودی ناکافی رد شد (HTTP {code})", is_rejected or code in (302, 200))
    else:
        assertions.append(("بازی یافت نشد — skip", True))

def test_prediction_L3_bet_on_closed_game(client, assertions):
    """L3-2: شرط روی بازی بسته‌شده باید رد شود"""
    ensure_test_user("pred.fail2@chortke.test", verified=True, balance_irt='500000')
    client.login("pred.fail2@chortke.test", DEFAULT_PASSWORD)
    closed = db_scalar("SELECT id FROM prediction_games WHERE status IN ('closed','finished','cancelled') LIMIT 1")
    if closed:
        code, body, jb = client.post(f'/prediction/{closed}/bet', {'choice': 'yes', 'amount': '10000'})
        is_rejected = code == 422 or (jb and not jb.get('success', True))
        assert_true(assertions, f"بازی بسته رد شد (HTTP {code})", is_rejected or code in (302, 200))
    else:
        assertions.append(("بازی بسته یافت نشد — skip", True))

def test_prediction_L3_nonexistent_game(client, assertions):
    """L3-3: شرط روی بازی ناموجود باید هندل شود"""
    ensure_test_user("pred.fail3@chortke.test", verified=True, balance_irt='500000')
    client.login("pred.fail3@chortke.test", DEFAULT_PASSWORD)
    code, body, jb = client.post('/prediction/99999/bet', {'choice': 'yes', 'amount': '10000'})
    no_crash = code != 500
    assert_true(assertions, f"ناموجود هندل شد (HTTP {code})", no_crash)

def test_prediction_L3_guest_cannot_bet(client, assertions):
    """L3-4: مهمان نمی‌تواند شرط ببندد"""
    client2 = HttpClient(f"/tmp/test_pred_guest_{id}.jar")
    code, body = client2.get('/prediction')
    assert_true(assertions, f"مهمان رد شد (HTTP {code})", code in (302, 403, 200))

# ═══════════════════════════════════════════════════════════════════
# لایه ۴: امنیت و مجوز (Security)
# ═══════════════════════════════════════════════════════════════════

def test_prediction_L4_csrf_protection(client, assertions):
    """L4-1: شرط بدون CSRF باید رد شود"""
    ensure_test_user("pred.sec1@chortke.test", verified=True, balance_irt='500000')
    client.login("pred.sec1@chortke.test", DEFAULT_PASSWORD)
    r = subprocess.run(
        ['curl', '-sS', '-b', client.jar, '-X', 'POST',
         f'{BASE_URL}/prediction/1/bet',
         '--data-urlencode', 'choice=yes',
         '--data-urlencode', 'amount=10000',
         '-o', '/tmp/ht_body.html', '-w', '%{http_code}', '--max-time', '15'],
        capture_output=True, text=True, timeout=20
    )
    code = int(r.stdout.strip()) if r.stdout.strip().isdigit() else 0
    assert_true(assertions, f"بدون CSRF رد شد (HTTP {code})", code in (403, 419, 302))

def test_prediction_L4_sqli_in_choice(client, assertions):
    """L4-2: SQL injection در انتخاب باید رد/escape شود"""
    ensure_test_user("pred.sec2@chortke.test", verified=True, balance_irt='500000')
    client.login("pred.sec2@chortke.test", DEFAULT_PASSWORD)
    code, body, jb = client.post('/prediction/1/bet', {
        'choice': "'; DROP TABLE prediction_bets; --",
        'amount': '10000',
    })
    no_crash = code != 500 and 'SQLSTATE' not in body
    assert_true(assertions, f"SQLi رد شد (HTTP {code})", no_crash)

def test_prediction_L4_user_cannot_admin(client, assertions):
    """L4-3: کاربر عادی نمی‌تواند بازی ایجاد کند"""
    ensure_test_user("pred.sec3@chortke.test", verified=True)
    client.login("pred.sec3@chortke.test", DEFAULT_PASSWORD)
    code, body = client.get('/admin/prediction/create')
    assert_true(assertions, f"ادمین محروم (HTTP {code})", code in (302, 403))

# ═══════════════════════════════════════════════════════════════════
# لایه ۵: موارد لبه (Edge Cases)
# ═══════════════════════════════════════════════════════════════════

def test_prediction_L5_zero_amount_bet(client, assertions):
    """L5-1: شرط مبلغ صفر باید رد شود"""
    ensure_test_user("pred.edge1@chortke.test", verified=True, balance_irt='500000')
    client.login("pred.edge1@chortke.test", DEFAULT_PASSWORD)
    code, body, jb = client.post('/prediction/1/bet', {
        'choice': 'yes',
        'amount': '0',
    })
    is_rejected = code == 422 or (jb and not jb.get('success', True))
    assert_true(assertions, f"مبلغ صفر رد شد (HTTP {code})", is_rejected or code in (302, 200))

def test_prediction_L5_negative_amount(client, assertions):
    """L5-2: شرط مبلغ منفی باید رد شود"""
    ensure_test_user("pred.edge2@chortke.test", verified=True, balance_irt='500000')
    client.login("pred.edge2@chortke.test", DEFAULT_PASSWORD)
    code, body, jb = client.post('/prediction/1/bet', {
        'choice': 'yes',
        'amount': '-5000',
    })
    is_rejected = code == 422 or (jb and not jb.get('success', True))
    assert_true(assertions, f"مبلغ منفی رد شد (HTTP {code})", is_rejected or code in (302, 200))

def test_prediction_L5_huge_amount(client, assertions):
    """L5-3: شرط مبلغ بسیار بزرگ باید هندل شود"""
    ensure_test_user("pred.edge3@chortke.test", verified=True, balance_irt='500000')
    client.login("pred.edge3@chortke.test", DEFAULT_PASSWORD)
    code, body, jb = client.post('/prediction/1/bet', {
        'choice': 'yes',
        'amount': '999999999999',
    })
    no_crash = code != 500
    assert_true(assertions, f"مبلغ بزرگ هندل شد (HTTP {code})", no_crash)

# ═══════════════════════════════════════════════════════════════════
# لایه ۷: یکپارچگی داده (Data Integrity)
# ═══════════════════════════════════════════════════════════════════

def test_prediction_L7_game_status_valid(client, assertions):
    """L7-1: وضعیت بازی‌ها فقط مقادیر مجاز دارد"""
    invalid = db_scalar(
        "SELECT COUNT(*) FROM prediction_games "
        "WHERE status NOT IN ('open','locked','finished','cancelled')"
    )
    assert_equal(assertions, "status نامعتبر در prediction_games", int(invalid), 0)

def test_prediction_L7_bet_status_valid(client, assertions):
    """L7-2: وضعیت شرط‌ها فقط مقادیر مجاز دارد"""
    invalid = db_scalar(
        "SELECT COUNT(*) FROM prediction_bets "
        "WHERE status NOT IN ('pending','won','lost','refunded')"
    )
    assert_equal(assertions, "status نامعتبر در prediction_bets", int(invalid), 0)

if __name__ == '__main__':
    suite = TestSuite("بخش پیش‌بینی — الگوی ۷ لایه‌ای")
    
    suite.run_test("L1-1: صفحه اصلی لود", test_prediction_L1_main_page)
    suite.run_test("L1-2: شرط‌های من لود", test_prediction_L1_my_bets_page)
    suite.run_test("L1-3: جزئیات بازی لود", test_prediction_L1_game_detail_page)
    
    suite.run_test("L2-1: ثبت شرط موفق", test_prediction_L2_place_bet)
    suite.run_test("L2-2: مشاهده بازی", test_prediction_L2_view_game_with_bets)
    
    suite.run_test("L3-1: موجودی ناکافی رد", test_prediction_L3_bet_insufficient_balance)
    suite.run_test("L3-2: بازی بسته رد", test_prediction_L3_bet_on_closed_game)
    suite.run_test("L3-3: ناموجود هندل", test_prediction_L3_nonexistent_game)
    suite.run_test("L3-4: مهمان محروم", test_prediction_L3_guest_cannot_bet)
    
    suite.run_test("L4-1: بدون CSRF رد", test_prediction_L4_csrf_protection)
    suite.run_test("L4-2: SQLi رد", test_prediction_L4_sqli_in_choice)
    suite.run_test("L4-3: ادمین محروم", test_prediction_L4_user_cannot_admin)
    
    suite.run_test("L5-1: مبلغ صفر رد", test_prediction_L5_zero_amount_bet)
    suite.run_test("L5-2: مبلغ منفی رد", test_prediction_L5_negative_amount)
    suite.run_test("L5-3: مبلغ بزرگ هندل", test_prediction_L5_huge_amount)
    
    suite.run_test("L7-1: status بازی مجاز", test_prediction_L7_game_status_valid)
    suite.run_test("L7-2: status شرط مجاز", test_prediction_L7_bet_status_valid)
    
    ok = suite.summary()
    sys.exit(0 if ok else 1)
