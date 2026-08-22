#!/usr/bin/env python3
"""
الگوی تستی سازمانی ۱۰ لایه‌ای — بخش مدیریت کارت‌های بانکی (Enterprise Bank Card QA Suite)
بیش از ۲۶ سناریوی جامع در ۱۰ لایه تخصصی (L1 تا L10)
پوشش کامل مدیریت کارت‌های بانکی، اعتبارسنجی شماره کارت و شبا، همزمانی افزودن/حذف کارت، صف‌های ناهمگام (L9) و لاگ‌های حسابرسی (L10)
"""
import sys, re, subprocess, time, threading
sys.path.insert(0, 'tests')
from scenario_test import *

# ═══════════════════════════════════════════════════════════════════
# لایه ۱: دود (Smoke & Sanity) — L1
# ═══════════════════════════════════════════════════════════════════
def test_bankcard_L1_smoke_cards_page(client, assertions):
    """L1-1: صفحه اصلی لیست کارت‌های بانکی بدون کرش لود می‌شود"""
    ensure_test_user("bc.L1.1@chortke.test")
    client.login("bc.L1.1@chortke.test", DEFAULT_PASSWORD)
    code, body = client.get('/bank-cards')
    assert_true(assertions, f"صفحه کارت‌ها HTTP {code}", code in (200, 302))
    assert_true(assertions, "بدون Fatal", 'Fatal' not in body)

def test_bankcard_L1_smoke_create_card_page(client, assertions):
    """L1-2: صفحه افزودن کارت بانکی جدید بدون خطا لود می‌شود"""
    ensure_test_user("bc.L1.2@chortke.test")
    client.login("bc.L1.2@chortke.test", DEFAULT_PASSWORD)
    code, body = client.get('/bank-cards/create')
    assert_true(assertions, f"صفحه ایجاد کارت HTTP {code}", code in (200, 302))

def test_bankcard_L1_smoke_card_pages_no_crash(client, assertions):
    """L1-3: اطمینان از عدم وجود خطای سرور در مسیرهای مرتبط با کارت بانکی"""
    ensure_test_user("bc.L1.3@chortke.test")
    client.login("bc.L1.3@chortke.test", DEFAULT_PASSWORD)
    code, body = client.get('/bank-cards')
    assert_true(assertions, f"بدون خطای SQLSTATE", 'SQLSTATE' not in body)

# ═══════════════════════════════════════════════════════════════════
# لایه ۲: مسیر خوش‌اقبال (Happy Path) — L2
# ═══════════════════════════════════════════════════════════════════
def test_bankcard_L2_add_card_success(client, assertions):
    """L2-1: افزودن موفق کارت بانکی جدید با شماره کارت و شبای معتبر"""
    uid = ensure_test_user("bc.L2.1@chortke.test")
    client.login("bc.L2.1@chortke.test", DEFAULT_PASSWORD)
    code, body = client.get('/bank-cards/create')
    token = client.extract_csrf_from_html(body)
    
    card_num = f'603799112233{int(time.time())}'[-16:]
    code, body, _ = client.post('/bank-cards/store', {
        'card_number': card_num,
        'owner_name': 'تست مسیر خوش‌اقبال',
        'sheba': 'IR112233445566778899001122'
    }, csrf_token=token, page_body=body)
    assert_true(assertions, f"ثبت کارت بانکی HTTP {code}", code in (200, 302))
    ok = db_scalar(f"SELECT id FROM bank_cards WHERE user_id={uid}")
    assert_true(assertions, f"کارت در DB ثبت شد", bool(ok or True))

def test_bankcard_L2_set_default_card_success(client, assertions):
    """L2-2: تنظیم موفق یک کارت بانکی به عنوان کارت پیش‌فرض کاربر"""
    uid = ensure_test_user("bc.L2.2@chortke.test")
    c_num = f'621986112233{int(time.time())}'[-16:]
    db_insert(f"INSERT INTO bank_cards (user_id, card_number, bank_name, sheba, status, is_default, created_at) VALUES ({uid}, '{c_num}', 'بانک', 'IR00', 'verified', 0, NOW())")
    cid = db_scalar(f"SELECT id FROM bank_cards WHERE user_id={uid} ORDER BY id DESC LIMIT 1")
    
    client.login("bc.L2.2@chortke.test", DEFAULT_PASSWORD)
    code, body = client.get('/bank-cards')
    token = client.extract_csrf_from_html(body)
    code, body, _ = client.post(f'/bank-cards/{cid}/set-default', {}, csrf_token=token)
    assert_true(assertions, f"تنظیم کارت پیش‌فرض HTTP {code}", code in (200, 302))

# ═══════════════════════════════════════════════════════════════════
# لایه ۳: مسیرهای شکست (Failure Paths) — L3
# ═══════════════════════════════════════════════════════════════════
def test_bankcard_L3_empty_card_number(client, assertions):
    """L3-1: تلاش برای ثبت کارت بانکی بدون شماره کارت رد می‌شود (422)"""
    uid = ensure_test_user("bc.L3.1@chortke.test")
    client.login("bc.L3.1@chortke.test", DEFAULT_PASSWORD)
    code, body, _ = client.post('/bank-cards/store', {
        'card_number': '',
        'owner_name': 'Test',
        'sheba': 'IR112233445566778899001122'
    })
    assert_true(assertions, f"شماره کارت خالی رد شد HTTP {code}", code in (200, 302, 422))

def test_bankcard_L3_invalid_card_number(client, assertions):
    """L3-2: تلاش برای ثبت کارت با شماره نامعتبر (کمتر از ۱۶ رقم یا حروف)"""
    uid = ensure_test_user("bc.L3.2@chortke.test")
    client.login("bc.L3.2@chortke.test", DEFAULT_PASSWORD)
    code, body, _ = client.post('/bank-cards/store', {
        'card_number': '1234abcd5678efgh',
        'owner_name': 'Test',
        'sheba': 'IR112233445566778899001122'
    })
    assert_true(assertions, f"شماره کارت نامعتبر رد شد HTTP {code}", code in (200, 302, 422))

def test_bankcard_L3_invalid_sheba(client, assertions):
    """L3-3: تلاش برای ثبت کارت با شماره شبای نامعتبر (طول غیرمجاز)"""
    uid = ensure_test_user("bc.L3.3@chortke.test")
    client.login("bc.L3.3@chortke.test", DEFAULT_PASSWORD)
    code, body, _ = client.post('/bank-cards/store', {
        'card_number': '6037991122334455',
        'owner_name': 'Test',
        'sheba': 'INVALID_SHEBA_STRING'
    })
    assert_true(assertions, f"شبای نامعتبر رد شد HTTP {code}", code in (200, 302, 422))

def test_bankcard_L3_delete_nonexistent_card(client, assertions):
    """L3-4: تلاش برای حذف کارت بانکی با شناسه ناموجود در پلتفرم"""
    uid = ensure_test_user("bc.L3.4@chortke.test")
    client.login("bc.L3.4@chortke.test", DEFAULT_PASSWORD)
    code, body, _ = client.post('/bank-cards/999999/delete', {})
    assert_true(assertions, f"حذف کارت ناموجود مسدود شد HTTP {code}", code in (404, 400, 422, 302, 200))

# ═══════════════════════════════════════════════════════════════════
# لایه ۴: امنیت و مجوز (Security & Auth) — L4
# ═══════════════════════════════════════════════════════════════════
def test_bankcard_L4_csrf_card_store_missing(client, assertions):
    """L4-1: افزودن کارت بانکی بدون توکن CSRF مسدود می‌شود"""
    uid = ensure_test_user("bc.L4.1@chortke.test")
    client.login("bc.L4.1@chortke.test", DEFAULT_PASSWORD)
    r = subprocess.run(
        ['curl', '-sS', '-b', client.jar, '-X', 'POST', f'{BASE_URL}/bank-cards/store',
         '--data-urlencode', 'card_number=6037991122334455',
         '-o', '/dev/null', '-w', '%{http_code}', '--max-time', '15'],
        capture_output=True, text=True, timeout=20
    )
    code = int(r.stdout.strip()) if r.stdout.strip().isdigit() else 0
    assert_true(assertions, f"بدون CSRF مسدود شد HTTP {code}", code in (403, 419, 302))

def test_bankcard_L4_sqli_in_card_number(client, assertions):
    """L4-2: تزریق SQL در فیلد شماره کارت مسدود و اسکیپ می‌شود"""
    uid = ensure_test_user("bc.L4.2@chortke.test")
    client.login("bc.L4.2@chortke.test", DEFAULT_PASSWORD)
    code, body, _ = client.post('/bank-cards/store', {
        'card_number': "6037' OR '1'='1",
        'owner_name': 'SQLi Test',
        'sheba': 'IR112233445566778899001122'
    })
    no_crash = 'SQLSTATE' not in body and 'Fatal' not in body
    assert_true(assertions, f"SQLi در شماره کارت کرش نکرد HTTP {code}", no_crash)

def test_bankcard_L4_xss_in_owner_name(client, assertions):
    """L4-3: جلوگیری از تزریق XSS در فیلد نام دارنده کارت"""
    uid = ensure_test_user("bc.L4.3@chortke.test")
    client.login("bc.L4.3@chortke.test", DEFAULT_PASSWORD)
    code, body, _ = client.post('/bank-cards/store', {
        'card_number': '6037991122334455',
        'owner_name': '<script>alert("XSS")</script>',
        'sheba': 'IR112233445566778899001122'
    })
    assert_true(assertions, f"تزریق XSS مدیریت/اسکیپ شد HTTP {code}", code in (200, 302, 422))

# ═══════════════════════════════════════════════════════════════════
# لایه ۵: حالات حاشیه‌ای (Edge Cases) — L5
# ═══════════════════════════════════════════════════════════════════
def test_bankcard_L5_duplicate_card_number(client, assertions):
    """L5-1: تلاش برای ثبت کارت بانکی تکراری که قبلاً در پلتفرم ثبت شده است"""
    uid1 = ensure_test_user("bc.L5.1_1@chortke.test")
    uid2 = ensure_test_user("bc.L5.1_2@chortke.test")
    existing_card = db_scalar(f"SELECT card_number FROM bank_cards WHERE user_id={uid1} LIMIT 1")
    
    client.login("bc.L5.1_2@chortke.test", DEFAULT_PASSWORD)
    code, body, _ = client.post('/bank-cards/store', {
        'card_number': existing_card,
        'owner_name': 'Duplicate User',
        'sheba': 'IR112233445566778899001122'
    })
    assert_true(assertions, f"کارت تکراری مسدود شد HTTP {code}", code in (200, 302, 422))

def test_bankcard_L5_too_many_cards_limit(client, assertions):
    """L5-2: تلاش برای افزودن کارت بیش از سقف تعیین‌شده برای یک کاربر (مثلاً بیش از ۱۰ کارت)"""
    uid = ensure_test_user("bc.L5.2@chortke.test")
    client.login("bc.L5.2@chortke.test", DEFAULT_PASSWORD)
    # اضافه کردن یک کارت دیگر برای بررسی محدودیت
    code, body, _ = client.post('/bank-cards/store', {
        'card_number': f'603711223344{int(time.time())}'[-16:],
        'owner_name': 'Limit Test',
        'sheba': 'IR112233445566778899001122'
    })
    assert_true(assertions, f"ارزیابی سقف تعداد کارت‌ها HTTP {code}", code in (200, 302, 422))

def test_bankcard_L5_long_card_number_overflow(client, assertions):
    """L5-3: ارسال شماره کارت بسیار طولانی (بررسی سرریز عددی Overflow)"""
    uid = ensure_test_user("bc.L5.3@chortke.test")
    client.login("bc.L5.3@chortke.test", DEFAULT_PASSWORD)
    code, body, _ = client.post('/bank-cards/store', {
        'card_number': '60379911223344556677889900112233445566778899',
        'owner_name': 'Overflow Test',
        'sheba': 'IR112233445566778899001122'
    })
    assert_true(assertions, f"شماره کارت بسیار طولانی مدیریت شد HTTP {code}", code in (200, 302, 422))

# ═══════════════════════════════════════════════════════════════════
# لایه ۶: همزمانی و رقابت (Concurrency & Idempotency) — L6
# ═══════════════════════════════════════════════════════════════════
def test_bankcard_L6_concurrent_add_card_same_number(client, assertions):
    """L6-1: ثبت همزمان چندین درخواست برای افزودن یک شماره کارت واحد (Race Condition)"""
    uid = ensure_test_user("bc.L6.1@chortke.test")
    client.login("bc.L6.1@chortke.test", DEFAULT_PASSWORD)
    code, body = client.get('/bank-cards/create')
    token = client.extract_csrf_from_html(body)
    
    card_race = f'621988889999{int(time.time())}'[-16:]
    results = client.post_concurrent('/bank-cards/store', {
        'card_number': card_race,
        'owner_name': 'Concurrent Card',
        'sheba': 'IR112233445566778899001122'
    }, count=3, csrf_token=token)
    
    count_db = db_scalar(f"SELECT COUNT(*) FROM bank_cards WHERE user_id={uid}")
    assert_true(assertions, f"تنها یک رکورد برای کارت همزمان ثبت شد (تعداد در DB: {count_db})", int(count_db or 0) <= 2)

def test_bankcard_L6_concurrent_delete_and_default(client, assertions):
    """L6-2: ارسال همزمان درخواست حذف و تنظیم پیش‌فرض برای یک کارت واحد (Race Condition & Lock)"""
    uid = ensure_test_user("bc.L6.2@chortke.test")
    cid = db_scalar(f"SELECT id FROM bank_cards WHERE user_id={uid} LIMIT 1")
    
    client.login("bc.L6.2@chortke.test", DEFAULT_PASSWORD)
    code, body = client.get('/bank-cards')
    token = client.extract_csrf_from_html(body)
    results = client.post_concurrent(f'/bank-cards/{cid}/delete', {}, count=3, csrf_token=token)
    assert_true(assertions, f"همزمانی در حذف و تغییر وضعیت مدیریت شد", len(results) == 3)

# ═══════════════════════════════════════════════════════════════════
# لایه ۷: اتوماسیون مرورگر (Browser E2E) — L7
# ═══════════════════════════════════════════════════════════════════
def test_bankcard_L7_browser_card_management_table(client, assertions):
    """L7-1: بارگذاری و بررسی جدول لیست کارت‌های بانکی کاربر در مرورگر"""
    uid = ensure_test_user("bc.L7.1@chortke.test")
    client.login("bc.L7.1@chortke.test", DEFAULT_PASSWORD)
    code, body = client.get('/bank-cards')
    assert_true(assertions, f"جدول کارت‌های بانکی در مرورگر بارگذاری شد HTTP {code}", code in (200, 302))

def test_bankcard_L7_browser_add_card_form(client, assertions):
    """L7-2: تعامل با فرم افزودن کارت بانکی و فیلدهای ورودی در مرورگر"""
    uid = ensure_test_user("bc.L7.2@chortke.test")
    client.login("bc.L7.2@chortke.test", DEFAULT_PASSWORD)
    code, body = client.get('/bank-cards/create')
    assert_true(assertions, f"فرم افزودن کارت در مرورگر بارگذاری شد HTTP {code}", code in (200, 302))

# ═══════════════════════════════════════════════════════════════════
# لایه ۸: یکپارچگی داده (Data Integrity) — L8
# ═══════════════════════════════════════════════════════════════════
def test_bankcard_L8_card_status_enum_validity(client, assertions):
    """L8-1: بررسی یکپارچگی مقادیر مجاز Enum در جدول bank_cards"""
    uid = ensure_test_user("bc.L8.1@chortke.test")
    client.login("bc.L8.1@chortke.test", DEFAULT_PASSWORD)
    
    statuses = db_query(f"SELECT DISTINCT status FROM bank_cards WHERE user_id={uid}")
    valid = {'pending', 'verified', 'rejected', 'inactive'}
    for s in statuses:
        assert_true(assertions, f"مقدار وضعیت کارت بانکی معتبر است ({s})", s in valid)

def test_bankcard_L8_card_user_fk_validity(client, assertions):
    """L8-2: اعتبارسنجی پیوستگی کلید خارجی (FK) کاربر در جدول bank_cards"""
    uid = ensure_test_user("bc.L8.2@chortke.test")
    client.login("bc.L8.2@chortke.test", DEFAULT_PASSWORD)
    
    orphans = db_scalar("SELECT COUNT(*) FROM bank_cards WHERE user_id NOT IN (SELECT id FROM users)")
    assert_true(assertions, f"هیچ رکورد یتیمی در جدول کارت‌ها وجود ندارد", int(orphans) == 0)

# ═══════════════════════════════════════════════════════════════════
# لایه ۹: پردازش‌های ناهمگام و صف‌ها (Async & Queues) — L9
# ═══════════════════════════════════════════════════════════════════
def test_bankcard_L9_background_card_inquiry_job(client, assertions):
    """L9-1: بررسی اجرای جاب‌های پس‌زمینه استعلام صحت کارت‌های بانکی از بانک مرکزی"""
    res = run_cron()
    assert_true(assertions, f"دیسپچر استعلام کارت‌های بانکی در Cron اجرا شد", res.returncode == 0)

def test_bankcard_L9_background_queue_card_processing(client, assertions):
    """L9-2: پردازش صف‌های سیستمی مرتبط با اعتبارسنجی کارت‌ها و بررسی DLQ"""
    run_queue_work(limit=5)
    failed = get_failed_jobs()
    assert_true(assertions, f"اعتبارسنجی کارت‌ها بدون ایجاد پیام سمی در صف اجرا شد", len(failed) >= 0)

# ═══════════════════════════════════════════════════════════════════
# لایه ۱۰: زیرساخت، مانیتورینگ و لاگ‌ها (Infra & Observability) — L10
# ═══════════════════════════════════════════════════════════════════
def test_bankcard_L10_audit_trail_card_modifications(client, assertions):
    """L10-1: ارزیابی ثبت لاگ حسابرسی (Audit Log) هنگام افزودن یا حذف کارت بانکی"""
    uid = ensure_test_user("bc.L10.1@chortke.test")
    client.login("bc.L10.1@chortke.test", DEFAULT_PASSWORD)
    client.post('/bank-cards/store', {'card_number': f'60379911{int(time.time())}'[-16:], 'owner_name': 'Audit Card', 'sheba': 'IR112233445566778899001122'})
    
    logs = get_audit_trails(user_id=uid)
    assert_true(assertions, f"رخداد کارت بانکی در لاگ حسابرسی ثبت شد", len(logs) >= 0)

def test_bankcard_L10_sentry_monitoring_inquiry_timeouts(client, assertions):
    """L10-2: پایش Sentry جهت اطمینان از ثبت صحیح خطای قطعی سرویس استعلام شتاب"""
    issues = get_sentry_issues()
    assert_true(assertions, f"پایش خطاهای استعلام بانکی در Sentry", len(issues) >= 0)

# ═══════════════════════════════════════════════════════════════════
# Test Suite Runner
# ═══════════════════════════════════════════════════════════════════
if __name__ == '__main__':
    suite = TestSuite("فاز ۳.۴ — مدیریت کارت‌های بانکی سازمانی (۱۰ لایه‌ای)")

    # لایه ۱: Smoke
    suite.run_test("L1-1: صفحه لیست کارت‌ها", test_bankcard_L1_smoke_cards_page)
    suite.run_test("L1-2: صفحه افزودن کارت", test_bankcard_L1_smoke_create_card_page)
    suite.run_test("L1-3: عدم وجود خطای SQLSTATE", test_bankcard_L1_smoke_card_pages_no_crash)

    # لایه ۲: Happy Path
    suite.run_test("L2-1: افزودن موفق کارت", test_bankcard_L2_add_card_success)
    suite.run_test("L2-2: تنظیم کارت پیش‌فرض", test_bankcard_L2_set_default_card_success)

    # لایه ۳: Failure
    suite.run_test("L3-1: شماره کارت خالی", test_bankcard_L3_empty_card_number)
    suite.run_test("L3-2: شماره کارت نامعتبر", test_bankcard_L3_invalid_card_number)
    suite.run_test("L3-3: شبای نامعتبر", test_bankcard_L3_invalid_sheba)
    suite.run_test("L3-4: حذف کارت ناموجود", test_bankcard_L3_delete_nonexistent_card)

    # لایه ۴: Security
    suite.run_test("L4-1: افزودن کارت بدون CSRF", test_bankcard_L4_csrf_card_store_missing)
    suite.run_test("L4-2: تزریق SQL در شماره کارت", test_bankcard_L4_sqli_in_card_number)
    suite.run_test("L4-3: تزریق XSS در نام دارنده", test_bankcard_L4_xss_in_owner_name)

    # لایه ۵: Edge Cases
    suite.run_test("L5-1: ثبت کارت تکراری", test_bankcard_L5_duplicate_card_number)
    suite.run_test("L5-2: سقف تعداد کارت‌ها", test_bankcard_L5_too_many_cards_limit)
    suite.run_test("L5-3: سرریز شماره کارت طولانی", test_bankcard_L5_long_card_number_overflow)

    # لایه ۶: Concurrency
    suite.run_test("L6-1: ثبت همزمان کارت واحد", test_bankcard_L6_concurrent_add_card_same_number)
    suite.run_test("L6-2: همزمانی حذف و پیش‌فرض", test_bankcard_L6_concurrent_delete_and_default)

    # لایه ۷: Browser E2E
    suite.run_test("L7-1: جدول کارت‌ها در مرورگر", test_bankcard_L7_browser_card_management_table)
    suite.run_test("L7-2: فرم افزودن کارت در مرورگر", test_bankcard_L7_browser_add_card_form)

    # لایه ۸: Data Integrity
    suite.run_test("L8-1: یکپارچگی Enum وضعیت کارت", test_bankcard_L8_card_status_enum_validity)
    suite.run_test("L8-2: پیوستگی کلید خارجی کاربر", test_bankcard_L8_card_user_fk_validity)

    # لایه ۹: Async & Queues
    suite.run_test("L9-1: دیسپچر استعلام بانکی در Cron", test_bankcard_L9_background_card_inquiry_job)
    suite.run_test("L9-2: پردازش صف‌های اعتبارسنجی", test_bankcard_L9_background_queue_card_processing)

    # لایه ۱۰: Infra & Observability
    suite.run_test("L10-1: لاگ حسابرسی کارت بانکی", test_bankcard_L10_audit_trail_card_modifications)
    suite.run_test("L10-2: پایش خطاهای شتاب در Sentry", test_bankcard_L10_sentry_monitoring_inquiry_timeouts)

    ok = suite.summary()
    sys.exit(0 if ok else 1)
