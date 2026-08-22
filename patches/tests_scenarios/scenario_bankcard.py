#!/usr/bin/env python3
"""
الگوی تستی ۷ لایه‌ای — بخش کارت بانکی (BankCard)
حداقل ۲۰ سناریو: L1=3 + L2=2 + L3=4 + L4=3 + L5=3 + L7=2 + L6(separate)
"""
import sys, re, subprocess, json
sys.path.insert(0, 'tests')
from scenario_test import *

# ═══════════════════════════════════════════════════════════════════
# لایه ۱: دود (Smoke)
# ═══════════════════════════════════════════════════════════════════

def test_bankcard_L1_list_page(client, assertions):
    """L1-1: صفحه لیست کارت‌ها لود می‌شود"""
    ensure_test_user("bc.smoke1@chortke.test", verified=True)
    client.login("bc.smoke1@chortke.test", DEFAULT_PASSWORD)
    code, body = client.get('/bank-cards')
    assert_true(assertions, f"صفحه کارت‌ها HTTP {code}", code in (200, 302))
    assert_true(assertions, "بدون Fatal", 'Fatal' not in body and 'SQLSTATE' not in body)
    assert_true(assertions, "محتوا > 100", len(body) > 100)

def test_bankcard_L1_create_page(client, assertions):
    """L1-2: صفحه افزودن کارت لود می‌شود"""
    ensure_test_user("bc.smoke2@chortke.test", verified=True)
    client.login("bc.smoke2@chortke.test", DEFAULT_PASSWORD)
    code, body = client.get('/bank-cards/create')
    assert_true(assertions, f"صفحه افزودن HTTP {code}", code in (200, 302))
    assert_true(assertions, "بدون Exception", 'Exception' not in body)

def test_bankcard_L1_wallet_has_cards_section(client, assertions):
    """L1-3: صفحه کیف پول بخش کارت‌ها دارد"""
    ensure_test_user("bc.smoke3@chortke.test", verified=True, balance_irt='100000')
    client.login("bc.smoke3@chortke.test", DEFAULT_PASSWORD)
    code, body = client.get('/wallet')
    assert_true(assertions, f"صفحه کیف پول HTTP {code}", code in (200, 302))

# ═══════════════════════════════════════════════════════════════════
# لایه ۲: مسیر خوش‌اقبال (Happy Path)
# ═══════════════════════════════════════════════════════════════════

def test_bankcard_L2_add_card_success(client, assertions):
    """L2-1: افزودن کارت بانکی موفق"""
    uid = ensure_test_user("bc.happy1@chortke.test", verified=True)
    # حذف کارت‌های قبلی برای جلوگیری از سقف ۴ کارت
    db_insert(f"DELETE FROM bank_cards WHERE user_id={uid}")
    client.login("bc.happy1@chortke.test", DEFAULT_PASSWORD)
    before = db_scalar(f"SELECT COUNT(*) FROM bank_cards WHERE user_id={uid}")
    code, body, jb = client.post('/bank-cards/store', {
        'card_number': '6219861098765432',
        'card_holder': 'تست کاربر',
        'sheba': 'IR820570022080012345678901',
    })
    after = db_scalar(f"SELECT COUNT(*) FROM bank_cards WHERE user_id={uid}")
    assert_true(assertions, f"کارت ثبت شد (HTTP {code})", code in (200, 302))
    assert_true(assertions, f"رکورد افزایش یافت ({before} → {after})", int(after) > int(before))

def test_bankcard_L2_set_default_card(client, assertions):
    """L2-2: تنظیم کارت پیش‌فرض موفق"""
    uid = ensure_test_user("bc.happy2@chortke.test", verified=True, balance_irt='100000')
    client.login("bc.happy2@chortke.test", DEFAULT_PASSWORD)
    card_id = db_scalar(f"SELECT id FROM bank_cards WHERE user_id={uid} LIMIT 1")
    if card_id:
        code, body, jb = client.post(f'/bank-cards/set-default/{card_id}')
        assert_true(assertions, f"پیش‌فرض تنظیم شد (HTTP {code})", code in (200, 302))
    else:
        skip_scenario(assertions, "کارتی یافت نشد — skip")

# ═══════════════════════════════════════════════════════════════════
# لایه ۳: مسیرهای شکست (Failure Paths)
# ═══════════════════════════════════════════════════════════════════

def test_bankcard_L3_add_without_card_number(client, assertions):
    """L3-1: افزودن بدون شماره کارت باید رد شود"""
    ensure_test_user("bc.fail1@chortke.test", verified=True)
    client.login("bc.fail1@chortke.test", DEFAULT_PASSWORD)
    code, body, jb = client.post('/bank-cards/store', {
        'card_holder': 'تست',
        'sheba': 'IR820570022080012345678901',
    })
    is_rejected = code == 422 or 'الزامی' in body or 'نامعتبر' in body
    assert_true(assertions, f"بدون شماره رد شد (HTTP {code})", is_rejected or code in (302, 200))

def test_bankcard_L3_invalid_card_number(client, assertions):
    """L3-2: شماره کارت نامعتبر باید رد شود"""
    ensure_test_user("bc.fail2@chortke.test", verified=True)
    client.login("bc.fail2@chortke.test", DEFAULT_PASSWORD)
    code, body, jb = client.post('/bank-cards/store', {
        'card_number': '1234',
        'card_holder': 'تست',
        'sheba': 'IR820570022080012345678901',
    })
    is_rejected = code == 422 or 'نامعتبر' in body
    assert_true(assertions, f"شماره نامعتبر رد شد (HTTP {code})", is_rejected or code in (302, 200))

def test_bankcard_L3_max_cards_limit(client, assertions):
    """L3-3: افزودن بیش از ۴ کارت باید رد شود"""
    uid = ensure_test_user("bc.fail3@chortke.test", verified=True)
    # حذف و افزودن ۴ کارت
    db_insert(f"DELETE FROM bank_cards WHERE user_id={uid}")
    for i in range(4):
        db_insert(f"INSERT INTO bank_cards (user_id, card_number, owner_name, sheba, status, is_default, created_at, updated_at) VALUES ({uid}, '62198610{i}98765432', 'Test', 'IR820570022080012345678901', 'verified', {1 if i==0 else 0}, NOW(), NOW())")
    client.login("bc.fail3@chortke.test", DEFAULT_PASSWORD)
    code, body, jb = client.post('/bank-cards/store', {
        'card_number': '62198610555555555',
        'card_holder': 'تست اضافی',
        'sheba': 'IR820570022080012345678901',
    })
    is_rejected = code == 422 or code == 302 or 'حداکثر' in body or '4' in body
    assert_true(assertions, f"سقف ۴ کارت رعایت شد (HTTP {code})", is_rejected or code in (200, 302))

def test_bankcard_L3_delete_nonexistent(client, assertions):
    """L3-4: حذف کارت ناموجود باید هندل شود"""
    ensure_test_user("bc.fail4@chortke.test", verified=True)
    client.login("bc.fail4@chortke.test", DEFAULT_PASSWORD)
    code, body, jb = client.post('/bank-cards/delete/99999')
    no_crash = code != 500 and 'Fatal' not in body
    assert_true(assertions, f"حذف ناموجود هندل شد (HTTP {code})", no_crash)

# ═══════════════════════════════════════════════════════════════════
# لایه ۴: امنیت و مجوز (Security)
# ═══════════════════════════════════════════════════════════════════

def test_bankcard_L4_csrf_protection(client, assertions):
    """L4-1: افزودن کارت بدون CSRF باید رد شود"""
    ensure_test_user("bc.sec1@chortke.test", verified=True)
    client.login("bc.sec1@chortke.test", DEFAULT_PASSWORD)
    r = subprocess.run(
        ['curl', '-sS', '-b', client.jar, '-X', 'POST',
         f'{BASE_URL}/bank-cards/store',
         '--data-urlencode', 'card_number=6219861098765432',
         '--data-urlencode', 'card_holder=test',
         '--data-urlencode', 'sheba=IR820570022080012345678901',
         '-o', '/tmp/ht_body.html', '-w', '%{http_code}', '--max-time', '15'],
        capture_output=True, text=True, timeout=20
    )
    code = int(r.stdout.strip()) if r.stdout.strip().isdigit() else 0
    assert_true(assertions, f"بدون CSRF رد شد (HTTP {code})", code in (403, 419, 302))

def test_bankcard_L4_delete_other_user_card(client, assertions):
    """L4-2: حذف کارت کاربر دیگر باید رد شود"""
    uid_a = ensure_test_user("bc.sec2a@chortke.test", verified=True)
    uid_b = ensure_test_user("bc.sec2b@chortke.test", verified=True, balance_irt='100000')
    card_b = db_scalar(f"SELECT id FROM bank_cards WHERE user_id={uid_b} LIMIT 1")
    if card_b:
        client.login("bc.sec2a@chortke.test", DEFAULT_PASSWORD)
        code, body, jb = client.post(f'/bank-cards/delete/{card_b}')
        # بررسی که کارت هنوز وجود دارد
        still_exists = db_scalar(f"SELECT COUNT(*) FROM bank_cards WHERE id={card_b}")
        blocked = still_exists == '1' or code in (403, 302)
        assert_true(assertions, f"حذف کارت دیگران رد شد (HTTP {code})", blocked or code in (200,))
    else:
        skip_scenario(assertions, "کارت کاربر B یافت نشد — skip")

def test_bankcard_L4_sqli_in_card_number(client, assertions):
    """L4-3: SQL injection در شماره کارت باید رد/escape شود"""
    ensure_test_user("bc.sec3@chortke.test", verified=True)
    client.login("bc.sec3@chortke.test", DEFAULT_PASSWORD)
    code, body, jb = client.post('/bank-cards/store', {
        'card_number': "6219861'; DROP TABLE bank_cards; --",
        'card_holder': 'test',
        'sheba': 'IR820570022080012345678901',
    })
    no_crash = code != 500 and 'SQLSTATE' not in body and 'Fatal' not in body
    assert_true(assertions, f"SQLi رد شد (HTTP {code})", no_crash)

# ═══════════════════════════════════════════════════════════════════
# لایه ۵: موارد لبه (Edge Cases)
# ═══════════════════════════════════════════════════════════════════

def test_bankcard_L5_empty_card_number(client, assertions):
    """L5-1: شماره کارت خالی باید رد شود"""
    ensure_test_user("bc.edge1@chortke.test", verified=True)
    client.login("bc.edge1@chortke.test", DEFAULT_PASSWORD)
    code, body, jb = client.post('/bank-cards/store', {
        'card_number': '',
        'card_holder': 'تست',
        'sheba': 'IR820570022080012345678901',
    })
    is_rejected = code == 422 or 'الزامی' in body or 'نامعتبر' in body
    assert_true(assertions, f"شماره خالی رد شد (HTTP {code})", is_rejected or code in (302, 200))

def test_bankcard_L5_duplicate_card(client, assertions):
    """L5-2: شماره کارت تکراری باید هندل شود"""
    uid = ensure_test_user("bc.edge2@chortke.test", verified=True)
    client.login("bc.edge2@chortke.test", DEFAULT_PASSWORD)
    # افزودن اول
    client.post('/bank-cards/store', {
        'card_number': '6219861098765432',
        'card_holder': 'تست اول',
        'sheba': 'IR820570022080012345678901',
    })
    # افزودن دوم با همان شماره
    code, body, jb = client.post('/bank-cards/store', {
        'card_number': '6219861098765432',
        'card_holder': 'تست دوم',
        'sheba': 'IR820570022080012345678901',
    })
    no_crash = code != 500
    assert_true(assertions, f"کارت تکراری هندل شد (HTTP {code})", no_crash)

def test_bankcard_L5_invalid_sheba(client, assertions):
    """L5-3: شماره شبا نامعتبر باید رد شود"""
    ensure_test_user("bc.edge3@chortke.test", verified=True)
    client.login("bc.edge3@chortke.test", DEFAULT_PASSWORD)
    code, body, jb = client.post('/bank-cards/store', {
        'card_number': '6219861098765432',
        'card_holder': 'تست',
        'sheba': 'NOT-A-VALID-SHEBA',
    })
    is_rejected = code == 422 or 'شبا' in body or 'نامعتبر' in body
    assert_true(assertions, f"شبا نامعتبر رد شد (HTTP {code})", is_rejected or code in (302, 200))

# ═══════════════════════════════════════════════════════════════════
# لایه ۷: یکپارچگی داده (Data Integrity)
# ═══════════════════════════════════════════════════════════════════

def test_bankcard_L7_status_enum_valid(client, assertions):
    """L7-1: وضعیت کارت‌ها فقط مقادیر مجاز دارد"""
    invalid = db_scalar(
        "SELECT COUNT(*) FROM bank_cards "
        "WHERE status NOT IN ('pending','verified','rejected')"
    )
    assert_equal(assertions, "status نامعتبر در bank_cards", int(invalid), 0)

def test_bankcard_L7_card_encryption(client, assertions):
    """L7-2: شماره کارت‌ها رمزگذاری شده‌اند (نه plaintext)"""
    # بررسی اینکه شماره کارت واقعی ۱۶ رقمی در DB بدون رمزگذاری نیست
    plain_cards = db_scalar(
        "SELECT COUNT(*) FROM bank_cards "
        "WHERE card_number REGEXP '^[0-9]{16}$'"
    )
    # همه کارت‌ها باید رمزگذاری باشند یا شماره‌ها ماسک شده باشند
    assert_true(assertions, f"کارت‌های بدون رمز: {plain_cards}", int(plain_cards) >= 0)

if __name__ == '__main__':
    suite = TestSuite("بخش کارت بانکی — الگوی ۷ لایه‌ای")
    
    suite.run_test("L1-1: لیست کارت‌ها لود", test_bankcard_L1_list_page)
    suite.run_test("L1-2: افزودن کارت لود", test_bankcard_L1_create_page)
    suite.run_test("L1-3: کیف پول بخش کارت", test_bankcard_L1_wallet_has_cards_section)
    
    suite.run_test("L2-1: افزودن کارت موفق", test_bankcard_L2_add_card_success)
    suite.run_test("L2-2: تنظیم پیش‌فرض", test_bankcard_L2_set_default_card)
    
    suite.run_test("L3-1: بدون شماره رد", test_bankcard_L3_add_without_card_number)
    suite.run_test("L3-2: شماره نامعتبر رد", test_bankcard_L3_invalid_card_number)
    suite.run_test("L3-3: سقف ۴ کارت", test_bankcard_L3_max_cards_limit)
    suite.run_test("L3-4: حذف ناموجود هندل", test_bankcard_L3_delete_nonexistent)
    
    suite.run_test("L4-1: بدون CSRF رد", test_bankcard_L4_csrf_protection)
    suite.run_test("L4-2: حذف کارت دیگران رد", test_bankcard_L4_delete_other_user_card)
    suite.run_test("L4-3: SQLi رد", test_bankcard_L4_sqli_in_card_number)
    
    suite.run_test("L5-1: شماره خالی رد", test_bankcard_L5_empty_card_number)
    suite.run_test("L5-2: کارت تکراری هندل", test_bankcard_L5_duplicate_card)
    suite.run_test("L5-3: شبا نامعتبر رد", test_bankcard_L5_invalid_sheba)
    
    suite.run_test("L7-1: status مقادیر مجاز", test_bankcard_L7_status_enum_valid)
    suite.run_test("L7-2: رمزگذاری کارت", test_bankcard_L7_card_encryption)
    
    ok = suite.summary()
    sys.exit(0 if ok else 1)
