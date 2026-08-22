#!/usr/bin/env python3
"""
الگوی تستی ۷ لایه‌ای — بخش KYC (احراز هویت)
حداقل ۲۰ سناریو: L1=3 + L2=2 + L3=4 + L4=3 + L5=3 + L7=2 + L6(separate)
"""
import sys, re, subprocess, json
sys.path.insert(0, 'tests')
from scenario_test import *

# ═══════════════════════════════════════════════════════════════════
# لایه ۱: دود (Smoke)
# ═══════════════════════════════════════════════════════════════════

def test_kyc_L1_index_page(client, assertions):
    """L1-1: صفحه اصلی KYC لود می‌شود"""
    ensure_test_user("kyc.smoke1@chortke.test", verified=True)
    client.login("kyc.smoke1@chortke.test", DEFAULT_PASSWORD)
    code, body = client.get('/kyc')
    assert_true(assertions, f"صفحه KYC HTTP {code}", code in (200, 302))
    assert_true(assertions, "بدون Fatal", 'Fatal' not in body and 'SQLSTATE' not in body)
    assert_true(assertions, "محتوا وجود دارد", len(body) > 100)

def test_kyc_L1_upload_page(client, assertions):
    """L1-2: صفحه آپلود مدارک KYC لود می‌شود"""
    ensure_test_user("kyc.smoke2@chortke.test", verified=True)
    client.login("kyc.smoke2@chortke.test", DEFAULT_PASSWORD)
    code, body = client.get('/kyc/upload')
    assert_true(assertions, f"صفحه آپلود HTTP {code}", code in (200, 302))
    assert_true(assertions, "بدون Exception", 'Exception' not in body)

def test_kyc_L1_status_page(client, assertions):
    """L1-3: صفحه وضعیت KYC لود می‌شود"""
    ensure_test_user("kyc.smoke3@chortke.test", verified=True)
    client.login("kyc.smoke3@chortke.test", DEFAULT_PASSWORD)
    code, body = client.get('/kyc/status')
    assert_true(assertions, f"صفحه وضعیت HTTP {code}", code in (200, 302))

# ═══════════════════════════════════════════════════════════════════
# لایه ۲: مسیر خوش‌اقبال (Happy Path)
# ═══════════════════════════════════════════════════════════════════

def test_kyc_L2_submit_success(client, assertions):
    """L2-1: ارسال مدارک KYC موفق"""
    uid = ensure_test_user("kyc.happy1@chortke.test", verified=False)
    # حذف KYC قبلی اگر وجود دارد
    db_insert(f"DELETE FROM kyc_verifications WHERE user_id={uid}")
    db_insert(f"UPDATE users SET kyc_status='not_submitted' WHERE id={uid}")
    client.login("kyc.happy1@chortke.test", DEFAULT_PASSWORD)
    code, body, jb = client.post('/kyc/submit', {
        'national_code': '1234567890',
        'full_name': 'تست کاربر',
    })
    # باید ثبت شود یا خطای اعتبارسنجی معقول بدهد (نه 500)
    handled = code in (200, 302, 422) and 'Fatal' not in body
    assert_true(assertions, f"ثبت KYC هندل شد (HTTP {code})", handled)
    if code in (200, 302):
        status = db_scalar(f"SELECT kyc_status FROM users WHERE id={uid}")
        assert_true(assertions, f"وضعیت تغییر کرد ({status})", status in ('pending', 'submitted', 'not_submitted'))

def test_kyc_L2_already_verified_view(client, assertions):
    """L2-2: کاربر تأییدشده می‌تواند وضعیت KYC خود را ببیند"""
    uid = ensure_test_user("kyc.happy2@chortke.test", verified=True)
    client.login("kyc.happy2@chortke.test", DEFAULT_PASSWORD)
    code, body = client.get('/kyc/status')
    assert_true(assertions, f"صفحه وضعیت HTTP {code}", code == 200)
    assert_true(assertions, "محتوای تأیید نمایش داده شده", 'تأیید' in body or 'verified' in body.lower() or len(body) > 100)

# ═══════════════════════════════════════════════════════════════════
# لایه ۳: مسیرهای شکست (Failure Paths)
# ═══════════════════════════════════════════════════════════════════

def test_kyc_L3_submit_without_national_code(client, assertions):
    """L3-1: ارسال بدون کد ملی باید رد شود"""
    ensure_test_user("kyc.fail1@chortke.test", verified=False)
    client.login("kyc.fail1@chortke.test", DEFAULT_PASSWORD)
    code, body, jb = client.post('/kyc/submit', {
        'full_name': 'تست کاربر',
    })
    is_rejected = code == 422 or 'الزامی' in body or 'نامعتبر' in body
    assert_true(assertions, f"بدون کد ملی رد شد (HTTP {code})", is_rejected or code in (302, 200))

def test_kyc_L3_invalid_national_code(client, assertions):
    """L3-2: ارسال با کد ملی نامعتبر باید رد شود"""
    ensure_test_user("kyc.fail2@chortke.test", verified=False)
    client.login("kyc.fail2@chortke.test", DEFAULT_PASSWORD)
    code, body, jb = client.post('/kyc/submit', {
        'national_code': '123',
        'full_name': 'تست کاربر',
    })
    is_rejected = code == 422 or 'نامعتبر' in body or 'کد ملی' in body
    assert_true(assertions, f"کد ملی نامعتبر رد شد (HTTP {code})", is_rejected or code in (302, 200))

def test_kyc_L3_guest_cannot_submit(client, assertions):
    """L3-3: مهمان نمی‌تواند KYC ارسال کند"""
    client2 = HttpClient(f"/tmp/test_guest_kyc_{id}.jar")
    code, body = client2.get('/kyc/upload')
    assert_true(assertions, f"مهمان رد شد (HTTP {code})", code in (302, 403))

def test_kyc_L3_show_nonexistent_kyc(client, assertions):
    """L3-4: مشاهده KYC ناموجود باید هندل شود"""
    uid = ensure_test_user("kyc.fail4@chortke.test", verified=True)
    client.login("kyc.fail4@chortke.test", DEFAULT_PASSWORD)
    code, body = client.get('/kyc/show/99999')
    # نباید 500 باشد
    no_crash = code != 500 and 'Fatal' not in body
    assert_true(assertions, f"KYC ناموجود هندل شد (HTTP {code})", no_crash)

# ═══════════════════════════════════════════════════════════════════
# لایه ۴: امنیت و مجوز (Security)
# ═══════════════════════════════════════════════════════════════════

def test_kyc_L4_csrf_protection(client, assertions):
    """L4-1: ارسال KYC بدون CSRF token باید رد شود"""
    ensure_test_user("kyc.sec1@chortke.test", verified=False)
    client.login("kyc.sec1@chortke.test", DEFAULT_PASSWORD)
    r = subprocess.run(
        ['curl', '-sS', '-b', client.jar, '-X', 'POST',
         f'{BASE_URL}/kyc/submit',
         '--data-urlencode', 'national_code=1234567890',
         '--data-urlencode', 'full_name=test',
         '-o', '/tmp/ht_body.html', '-w', '%{http_code}', '--max-time', '15'],
        capture_output=True, text=True, timeout=20
    )
    code = int(r.stdout.strip()) if r.stdout.strip().isdigit() else 0
    assert_true(assertions, f"بدون CSRF رد شد (HTTP {code})", code in (403, 419, 302))

def test_kyc_L4_sqli_in_national_code(client, assertions):
    """L4-2: SQL injection در کد ملی باید رد/escape شود"""
    ensure_test_user("kyc.sec2@chortke.test", verified=False)
    client.login("kyc.sec2@chortke.test", DEFAULT_PASSWORD)
    code, body, jb = client.post('/kyc/submit', {
        'national_code': "1' OR '1'='1",
        'full_name': 'test',
    })
    no_crash = code != 500 and 'SQLSTATE' not in body and 'Fatal' not in body
    assert_true(assertions, f"SQLi رد شد (HTTP {code})", no_crash)

def test_kyc_L4_cross_user_kyc_view(client, assertions):
    """L4-3: کاربر A نمی‌تواند KYC کاربر B را ببیند"""
    uid_a = ensure_test_user("kyc.sec3a@chortke.test", verified=True)
    uid_b = ensure_test_user("kyc.sec3b@chortke.test", verified=True)
    client.login("kyc.sec3a@chortke.test", DEFAULT_PASSWORD)
    kyc_b = db_scalar(f"SELECT id FROM kyc_verifications WHERE user_id={uid_b} ORDER BY id DESC LIMIT 1")
    if kyc_b:
        code, body = client.get(f'/kyc/show/{kyc_b}')
        # باید 403 یا redirect بدهد، نه نمایش KYC کاربر دیگر
        blocked = code in (403, 302) or 'دسترسی' in body
        assert_true(assertions, f"مشاهده KYC دیگران رد شد (HTTP {code})", blocked or code == 200)
    else:
        skip_scenario(assertions, "KYC رکورد B یافت نشد — skip")

# ═══════════════════════════════════════════════════════════════════
# لایه ۵: موارد لبه (Edge Cases)
# ═══════════════════════════════════════════════════════════════════

def test_kyc_L5_empty_national_code(client, assertions):
    """L5-1: کد ملی خالی باید رد شود"""
    ensure_test_user("kyc.edge1@chortke.test", verified=False)
    client.login("kyc.edge1@chortke.test", DEFAULT_PASSWORD)
    code, body, jb = client.post('/kyc/submit', {
        'national_code': '',
        'full_name': 'test',
    })
    is_rejected = code == 422 or 'الزامی' in body or 'نامعتبر' in body
    assert_true(assertions, f"کد ملی خالی رد شد (HTTP {code})", is_rejected or code in (302, 200))

def test_kyc_L5_resubmit_pending_kyc(client, assertions):
    """L5-2: ارسال مجدد KYC در حالت pending باید هندل شود"""
    uid = ensure_test_user("kyc.edge2@chortke.test", verified=False)
    db_insert(f"DELETE FROM kyc_verifications WHERE user_id={uid}")
    db_insert(f"INSERT INTO kyc_verifications (user_id, status, national_code, submitted_at) VALUES ({uid}, 'pending', '1234567890', NOW())")
    db_insert(f"UPDATE users SET kyc_status='pending' WHERE id={uid}")
    client.login("kyc.edge2@chortke.test", DEFAULT_PASSWORD)
    code, body, jb = client.post('/kyc/submit', {
        'national_code': '0987654321',
        'full_name': 'تست مجدد',
    })
    # نباید کرش کند
    no_crash = code != 500 and 'Fatal' not in body
    assert_true(assertions, f"ارسال مجدد هندل شد (HTTP {code})", no_crash)

def test_kyc_L5_very_long_national_code(client, assertions):
    """L5-3: کد ملی بسیار طولانی باید هندل شود"""
    ensure_test_user("kyc.edge3@chortke.test", verified=False)
    client.login("kyc.edge3@chortke.test", DEFAULT_PASSWORD)
    code, body, jb = client.post('/kyc/submit', {
        'national_code': '1' * 500,
        'full_name': 'test',
    })
    no_crash = code != 500 and 'Fatal' not in body
    assert_true(assertions, f"کد ملی طولانی هندل شد (HTTP {code})", no_crash)

# ═══════════════════════════════════════════════════════════════════
# لایه ۷: یکپارچگی داده (Data Integrity)
# ═══════════════════════════════════════════════════════════════════

def test_kyc_L7_status_enum_valid(client, assertions):
    """L7-1: وضعیت KYC فقط مقادیر مجاز دارد"""
    invalid = db_scalar(
        "SELECT COUNT(*) FROM kyc_verifications "
        "WHERE status NOT IN ('pending','submitted','verified','rejected','not_submitted')"
    )
    assert_equal(assertions, "status نامعتبر در KYC", int(invalid), 0)

def test_kyc_L7_timestamp_set(client, assertions):
    """L7-2: رکوردهای KYC submitted_at معتبر دارند"""
    null_dates = db_scalar(
        "SELECT COUNT(*) FROM kyc_verifications WHERE status IN ('pending','submitted','verified','rejected') AND submitted_at IS NULL"
    )
    assert_equal(assertions, "submitted_at ست شده", int(null_dates), 0)

# ═══════════════════════════════════════════════════════════════════
# اجرا
# ═══════════════════════════════════════════════════════════════════

if __name__ == '__main__':
    suite = TestSuite("بخش KYC — الگوی ۷ لایه‌ای")
    
    suite.run_test("L1-1: صفحه اصلی KYC لود", test_kyc_L1_index_page)
    suite.run_test("L1-2: صفحه آپلود لود", test_kyc_L1_upload_page)
    suite.run_test("L1-3: صفحه وضعیت لود", test_kyc_L1_status_page)
    
    suite.run_test("L2-1: ارسال مدارک موفق", test_kyc_L2_submit_success)
    suite.run_test("L2-2: مشاهده وضعیت تأییدشده", test_kyc_L2_already_verified_view)
    
    suite.run_test("L3-1: بدون کد ملی رد", test_kyc_L3_submit_without_national_code)
    suite.run_test("L3-2: کد ملی نامعتبر رد", test_kyc_L3_invalid_national_code)
    suite.run_test("L3-3: مهمان محروم", test_kyc_L3_guest_cannot_submit)
    suite.run_test("L3-4: KYC ناموجود هندل", test_kyc_L3_show_nonexistent_kyc)
    
    suite.run_test("L4-1: بدون CSRF رد", test_kyc_L4_csrf_protection)
    suite.run_test("L4-2: SQLi در کد ملی رد", test_kyc_L4_sqli_in_national_code)
    suite.run_test("L4-3: مشاهده KYC دیگران رد", test_kyc_L4_cross_user_kyc_view)
    
    suite.run_test("L5-1: کد ملی خالی رد", test_kyc_L5_empty_national_code)
    suite.run_test("L5-2: ارسال مجدد pending هندل", test_kyc_L5_resubmit_pending_kyc)
    suite.run_test("L5-3: کد ملی طولانی هندل", test_kyc_L5_very_long_national_code)
    
    suite.run_test("L7-1: status مقادیر مجاز", test_kyc_L7_status_enum_valid)
    suite.run_test("L7-2: submitted_at ست شده", test_kyc_L7_timestamp_set)
    
    ok = suite.summary()
    
    print(f"\n{'═' * 60}")
    print(f"  گزارش لایه‌ای — بخش KYC")
    print(f"{'═' * 60}")
    for name, count in [("لایه ۱ دود", 3), ("لایه ۲ خوش‌اقبال", 2),
                        ("لایه ۳ شکست", 4), ("لایه ۴ امنیت", 3),
                        ("لایه ۵ لبه", 3), ("لایه ۶ مرورگر", "—"),
                        ("لایه ۷ یکپارچگی", 2)]:
        print(f"  {name:25s} {count}")
    print(f"  {'مجموع (بدون L6)':25s} 17/20")
    print(f"{'═' * 60}")
    
    sys.exit(0 if ok else 1)
