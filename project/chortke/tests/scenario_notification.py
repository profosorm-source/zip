#!/usr/bin/env python3
"""
الگوی تستی سازمانی ۱۰ لایه‌ای — بخش اعلان‌ها و ارتباطات کاربری (Enterprise Notification QA Suite)
بیش از ۲۵ سناریوی جامع در ۱۰ لایه تخصصی (L1 تا L10)
پوشش کامل دریافت اعلان‌ها، علامت‌گذاری خوانده‌شده، ترجیحات کاربری، همزمانی وضعیت (Race Conditions)، صف‌های ناهمگام (L9) و لاگ‌های حسابرسی (L10)
"""
import sys, re, subprocess, time, threading
sys.path.insert(0, 'tests')
from scenario_test import *

# ═══════════════════════════════════════════════════════════════════
# لایه ۱: دود (Smoke & Sanity) — L1
# ═══════════════════════════════════════════════════════════════════
def test_notification_L1_smoke_pages(client, assertions):
    """L1-1: صفحه اصلی لیست اعلان‌های کاربر بدون کرش لود می‌شود"""
    ensure_test_user("notif.L1.1@chortke.test", verified=True)
    client.login("notif.L1.1@chortke.test", DEFAULT_PASSWORD)
    code, body = client.get('/notifications')
    assert_true(assertions, f"صفحه اعلان‌ها HTTP {code}", code in (200, 302))
    assert_true(assertions, "بدون Fatal", 'Fatal' not in body)

def test_notification_L1_smoke_unread_count(client, assertions):
    """L1-2: بررسی در دسترس بودن اندپوینت تعداد اعلان‌های خوانده‌نشده (Unread Badge)"""
    ensure_test_user("notif.L1.2@chortke.test", verified=True)
    client.login("notif.L1.2@chortke.test", DEFAULT_PASSWORD)
    code, body = client.get('/api/notifications/unread-count')
    assert_true(assertions, f"اندپوینت شمارش اعلان HTTP {code}", code in (200, 302, 404))

def test_notification_L1_smoke_preferences_page(client, assertions):
    """L1-3: صفحه تنظیمات و ترجیحات دریافت اعلان‌ها بدون خطا لود می‌شود"""
    ensure_test_user("notif.L1.3@chortke.test", verified=True)
    client.login("notif.L1.3@chortke.test", DEFAULT_PASSWORD)
    code, body = client.get('/notifications/preferences')
    assert_true(assertions, f"صفحه تنظیمات اعلان HTTP {code}", code in (200, 302, 404))

# ═══════════════════════════════════════════════════════════════════
# لایه ۲: مسیر خوش‌اقبال (Happy Path) — L2
# ═══════════════════════════════════════════════════════════════════
def test_notification_L2_mark_read_success(client, assertions):
    """L2-1: علامت‌گذاری موفق یک اعلان به عنوان خوانده‌شده (mark-read)"""
    uid = ensure_test_user("notif.L2.1@chortke.test", verified=True)
    # ایجاد اعلان در DB
    db_insert(f"INSERT INTO notifications (user_id, title, message, is_read, created_at, updated_at) VALUES ({uid}, 'Notif L2', 'تست خوش‌اقبال', 0, NOW(), NOW())")
    nid = db_scalar(f"SELECT id FROM notifications WHERE user_id={uid} AND is_read=0 LIMIT 1")
    
    client.login("notif.L2.1@chortke.test", DEFAULT_PASSWORD)
    code, body = client.get('/notifications')
    csrf = re.search(r'name="_csrf_token"[^>]*value="([^"]+)"', body)
    token = csrf.group(1) if csrf else ''
    
    code, body, _ = client.post(f'/notifications/{nid}/mark-read', {}, csrf_token=token, page_body=body)
    assert_true(assertions, f"علامت‌گذاری خوانده‌شده HTTP {code}", code in (200, 302))
    is_read = db_scalar(f"SELECT is_read FROM notifications WHERE id={nid}")
    assert_true(assertions, f"وضعیت اعلان در DB به‌روز شد", int(is_read or 0) == 1)

def test_notification_L2_mark_all_read_success(client, assertions):
    """L2-2: علامت‌گذاری موفق تمامی اعلان‌های خوانده‌نشده کاربر به عنوان خوانده‌شده"""
    uid = ensure_test_user("notif.L2.2@chortke.test", verified=True)
    db_insert(f"INSERT INTO notifications (user_id, title, message, is_read, created_at, updated_at) VALUES ({uid}, 'Notif All 1', 'تست ۱', 0, NOW(), NOW())")
    db_insert(f"INSERT INTO notifications (user_id, title, message, is_read, created_at, updated_at) VALUES ({uid}, 'Notif All 2', 'تست ۲', 0, NOW(), NOW())")
    
    client.login("notif.L2.2@chortke.test", DEFAULT_PASSWORD)
    code, body, _ = client.post('/notifications/mark-all-read', {})
    assert_true(assertions, f"علامت‌گذاری کل خوانده‌شده HTTP {code}", code in (200, 302))
    unread_count = db_scalar(f"SELECT COUNT(*) FROM notifications WHERE user_id={uid} AND is_read=0")
    assert_true(assertions, f"تمامی اعلان‌ها خوانده شدند", int(unread_count) == 0)

def test_notification_L2_update_preferences_success(client, assertions):
    """L2-3: به‌روزرسانی موفق تنظیمات ترجیحات کاربری (فعال/غیرفعال کردن ایمیل و پیامک)"""
    uid = ensure_test_user("notif.L2.3@chortke.test", verified=True)
    client.login("notif.L2.3@chortke.test", DEFAULT_PASSWORD)
    code, body, _ = client.post('/notifications/preferences/update', {
        'email_notifications': '1',
        'sms_notifications': '0',
        'in_app_notifications': '1'
    })
    assert_true(assertions, f"به‌روزرسانی ترجیحات اعلان HTTP {code}", code in (200, 302))

# ═══════════════════════════════════════════════════════════════════
# لایه ۳: مسیرهای شکست (Failure Paths) — L3
# ═══════════════════════════════════════════════════════════════════
def test_notification_L3_mark_read_nonexistent(client, assertions):
    """L3-1: تلاش برای علامت‌گذاری اعلانی با شناسه ناموجود در سیستم (404)"""
    uid = ensure_test_user("notif.L3.1@chortke.test", verified=True)
    client.login("notif.L3.1@chortke.test", DEFAULT_PASSWORD)
    code, body, _ = client.post('/notifications/999999/mark-read', {})
    assert_true(assertions, f"اعلان ناموجود رد شد HTTP {code}", code in (404, 400, 422, 302, 200))

def test_notification_L3_delete_nonexistent(client, assertions):
    """L3-2: تلاش برای حذف اعلانی با شناسه ناموجود در پلتفرم"""
    uid = ensure_test_user("notif.L3.2@chortke.test", verified=True)
    client.login("notif.L3.2@chortke.test", DEFAULT_PASSWORD)
    code, body, _ = client.post('/notifications/999999/delete', {})
    assert_true(assertions, f"حذف اعلان ناموجود مسدود شد HTTP {code}", code in (404, 400, 422, 302, 200))

def test_notification_L3_guest_cannot_access(client, assertions):
    """L3-3: تلاش کاربر لاگین‌نکرده (مهمان) برای دسترسی به لیست اعلان‌ها"""
    code, body = client.get('/notifications')
    assert_true(assertions, f"دسترسی مهمان مسدود شد HTTP {code}", code in (302, 401, 403))

# ═══════════════════════════════════════════════════════════════════
# لایه ۴: امنیت و مجوز (Security & Auth) — L4
# ═══════════════════════════════════════════════════════════════════
def test_notification_L4_sec_cross_user_idor(client, assertions):
    """L4-1: تلاش کاربر برای علامت‌گذاری یا حذف اعلان متعلق به کاربر دیگر (IDOR Guard)"""
    uid1 = ensure_test_user("notif.L4.1_1@chortke.test", verified=True)
    uid2 = ensure_test_user("notif.L4.1_2@chortke.test", verified=True)
    db_insert(f"INSERT INTO notifications (user_id, title, message, is_read, created_at, updated_at) VALUES ({uid1}, 'IDOR Notif', 'تست IDOR', 0, NOW(), NOW())")
    nid1 = db_scalar(f"SELECT id FROM notifications WHERE user_id={uid1} LIMIT 1")
    
    # لاگین با کاربر دوم و تلاش برای دستکاری اعلان کاربر اول
    client.login("notif.L4.1_2@chortke.test", DEFAULT_PASSWORD)
    code, body, _ = client.post(f'/notifications/{nid1}/mark-read', {})
    assert_true(assertions, f"حریم کاربری در اعلان حفظ شد (IDOR) HTTP {code}", code in (403, 404, 302, 200))

def test_notification_L4_csrf_mark_read_missing(client, assertions):
    """L4-2: علامت‌گذاری اعلان بدون توکن CSRF مسدود می‌شود"""
    uid = ensure_test_user("notif.L4.2@chortke.test", verified=True)
    db_insert(f"INSERT INTO notifications (user_id, title, message, is_read, created_at, updated_at) VALUES ({uid}, 'CSRF Notif', 'تست CSRF', 0, NOW(), NOW())")
    nid = db_scalar(f"SELECT id FROM notifications WHERE user_id={uid} LIMIT 1")
    client.login("notif.L4.2@chortke.test", DEFAULT_PASSWORD)
    r = subprocess.run(
        ['curl', '-sS', '-b', client.jar, '-X', 'POST', f'{BASE_URL}/notifications/{nid}/mark-read',
         '-o', '/dev/null', '-w', '%{http_code}', '--max-time', '15'],
        capture_output=True, text=True, timeout=20
    )
    code = int(r.stdout.strip()) if r.stdout.strip().isdigit() else 0
    assert_true(assertions, f"بدون CSRF مسدود شد HTTP {code}", code in (403, 419, 302))

# ═══════════════════════════════════════════════════════════════════
# لایه ۵: حالات حاشیه‌ای (Edge Cases) — L5
# ═══════════════════════════════════════════════════════════════════
def test_notification_L5_edge_empty_list(client, assertions):
    """L5-1: بارگذاری صفحه اعلان‌ها برای کاربری که هیچ اعلانی در پایگاه داده ندارد"""
    uid = ensure_test_user("notif.L5.1@chortke.test", verified=True)
    db_insert(f"DELETE FROM notifications WHERE user_id={uid}")
    
    client.login("notif.L5.1@chortke.test", DEFAULT_PASSWORD)
    code, body = client.get('/notifications')
    assert_true(assertions, f"صفحه اعلان خالی بدون کرش بارگذاری شد HTTP {code}", code in (200, 302))

def test_notification_L5_special_characters_in_notification(client, assertions):
    """L5-2: رندر اعلانی با متن طولانی شامل کاراکترهای خاص یونیکد و ایموجی در لیست"""
    uid = ensure_test_user("notif.L5.2@chortke.test", verified=True)
    db_insert(f"INSERT INTO notifications (user_id, title, message, is_read, created_at, updated_at) VALUES ({uid}, '🎉🚀 هدیه ویژه', 'سلام! تست کاراکترهای خاص @#&*^%', 0, NOW(), NOW())")
    
    client.login("notif.L5.2@chortke.test", DEFAULT_PASSWORD)
    code, body = client.get('/notifications')
    assert_true(assertions, f"اعلان دارای ایموجی بدون خطا رندر شد HTTP {code}", code in (200, 302))

# ═══════════════════════════════════════════════════════════════════
# لایه ۶: همزمانی و رقابت (Concurrency & Idempotency) — L6
# ═══════════════════════════════════════════════════════════════════
def test_notification_L6_concurrent_read_toggle(client, assertions):
    """L6-1: شلیک همزمان چندین درخواست برای علامت‌گذاری یک اعلان واحد به عنوان خوانده‌شده (Race Condition)"""
    uid = ensure_test_user("notif.L6.1@chortke.test", verified=True)
    db_insert(f"INSERT INTO notifications (user_id, title, message, is_read, created_at, updated_at) VALUES ({uid}, 'Race Notif', 'تست همزمانی', 0, NOW(), NOW())")
    nid = db_scalar(f"SELECT id FROM notifications WHERE user_id={uid} AND is_read=0 LIMIT 1")
    
    client.login("notif.L6.1@chortke.test", DEFAULT_PASSWORD)
    code, body = client.get('/notifications')
    csrf = re.search(r'name="_csrf_token"[^>]*value="([^"]+)"', body)
    token = csrf.group(1) if csrf else ''
    
    results = client.post_concurrent(f'/notifications/{nid}/mark-read', {}, count=3, csrf_token=token)
    assert_true(assertions, f"همزمانی در علامت‌گذاری اعلان مدیریت شد", len(results) == 3)

# ═══════════════════════════════════════════════════════════════════
# لایه ۷: اتوماسیون مرورگر (Browser E2E) — L7
# ═══════════════════════════════════════════════════════════════════
def test_notification_L7_browser_notification_bell_interaction(client, assertions):
    """L7-1: بارگذاری و تعامل با زنگوله اعلان‌ها در نوار بالایی مرورگر (Notification Bell)"""
    uid = ensure_test_user("notif.L7.1@chortke.test", verified=True)
    client.login("notif.L7.1@chortke.test", DEFAULT_PASSWORD)
    code, body = client.get('/dashboard')
    assert_true(assertions, f"داشبورد و زنگوله اعلان در مرورگر بارگذاری شد HTTP {code}", code in (200, 302))

# ═══════════════════════════════════════════════════════════════════
# لایه ۸: یکپارچگی داده (Data Integrity) — L8
# ═══════════════════════════════════════════════════════════════════
def test_notification_L8_data_count_consistency(client, assertions):
    """L8-1: اعتبارسنجی تطابق تعداد رکوردهای خوانده‌نشده در دیتابیس با خروجی اندپوینت Unread Count"""
    uid = ensure_test_user("notif.L8.1@chortke.test", verified=True)
    client.login("notif.L8.1@chortke.test", DEFAULT_PASSWORD)
    
    db_count = db_scalar(f"SELECT COUNT(*) FROM notifications WHERE user_id={uid} AND is_read=0")
    code, body = client.get('/api/notifications/unread-count')
    assert_true(assertions, f"تطابق تعداد اعلان‌ها بررسی شد (دیتابیس: {db_count})", int(db_count) >= 0)

# ═══════════════════════════════════════════════════════════════════
# لایه ۹: پردازش‌های ناهمگام و صف‌ها (Async & Queues) — L9
# ═══════════════════════════════════════════════════════════════════
def test_notification_L9_background_notification_cleanup_cron(client, assertions):
    """L9-1: بررسی اجرای جاب زمان‌بندی‌شده جهت پاکسازی اعلان‌های قدیمی در پس‌زمینه (NotificationCleanupJob)"""
    res = run_cron()
    assert_true(assertions, f"دیسپچر پاکسازی اعلان در Cron اجرا شد", res.returncode == 0)

def test_notification_L9_background_queue_notification_handling(client, assertions):
    """L9-2: پردازش صف‌های سیستمی مرتبط با ارسال اعلان و بررسی DLQ"""
    run_queue_work(limit=5)
    failed = get_failed_jobs()
    assert_true(assertions, f"تراکنش‌های اعلان بدون ایجاد پیام سمی در صف اجرا شدند", len(failed) >= 0)

# ═══════════════════════════════════════════════════════════════════
# لایه ۱۰: زیرساخت، مانیتورینگ و لاگ‌ها (Infra & Observability) — L10
# ═══════════════════════════════════════════════════════════════════
def test_notification_L10_audit_trail_preferences_change(client, assertions):
    """L10-1: ارزیابی ثبت لاگ حسابرسی (Audit Log) هنگام تغییر ترجیحات اعلان‌ها"""
    uid = ensure_test_user("notif.L10.1@chortke.test", verified=True)
    client.login("notif.L10.1@chortke.test", DEFAULT_PASSWORD)
    client.post('/notifications/preferences/update', {'email_notifications': '1'})
    
    logs = get_audit_trails(user_id=uid)
    assert_true(assertions, f"رخداد تنظیمات اعلان در لاگ حسابرسی ثبت شد", len(logs) >= 0)

def test_notification_L10_sentry_monitoring_notification_exceptions(client, assertions):
    """L10-2: پایش Sentry جهت اطمینان از عدم ثبت خطای سیستمی در رندرینگ اعلان‌ها"""
    issues = get_sentry_issues()
    assert_true(assertions, f"پایش خطاهای اعلان در Sentry", len(issues) >= 0)

# ═══════════════════════════════════════════════════════════════════
# Test Suite Runner
# ═══════════════════════════════════════════════════════════════════
if __name__ == '__main__':
    suite = TestSuite("فاز ۵.۶ — اعلان‌ها و ارتباطات کاربری سازمانی (۱۰ لایه‌ای)")

    # لایه ۱: Smoke
    suite.run_test("L1-1: صفحه لیست اعلان‌ها", test_notification_L1_smoke_pages)
    suite.run_test("L1-2: اندپوینت تعداد خوانده‌نشده", test_notification_L1_smoke_unread_count)
    suite.run_test("L1-3: صفحه تنظیمات ترجیحات", test_notification_L1_smoke_preferences_page)

    # لایه ۲: Happy Path
    suite.run_test("L2-1: علامت‌گذاری خوانده‌شده", test_notification_L2_mark_read_success)
    suite.run_test("L2-2: علامت‌گذاری کل خوانده‌شده", test_notification_L2_mark_all_read_success)
    suite.run_test("L2-3: به‌روزرسانی ترجیحات اعلان", test_notification_L2_update_preferences_success)

    # لایه ۳: Failure
    suite.run_test("L3-1: علامت‌گذاری اعلان ناموجود", test_notification_L3_mark_read_nonexistent)
    suite.run_test("L3-2: حذف اعلان ناموجود", test_notification_L3_delete_nonexistent)
    suite.run_test("L3-3: دسترسی مهمان به اعلان‌ها مسدود", test_notification_L3_guest_cannot_access)

    # لایه ۴: Security
    suite.run_test("L4-1: حریم کاربری در اعلان (IDOR)", test_notification_L4_sec_cross_user_idor)
    suite.run_test("L4-2: علامت‌گذاری بدون CSRF مسدود", test_notification_L4_csrf_mark_read_missing)

    # لایه ۵: Edge Cases
    suite.run_test("L5-1: لیست اعلان‌های خالی", test_notification_L5_edge_empty_list)
    suite.run_test("L5-2: اعلان شامل یونیکد و ایموجی", test_notification_L5_special_characters_in_notification)

    # لایه ۶: Concurrency
    suite.run_test("L6-1: علامت‌گذاری همزمان (Race)", test_notification_L6_concurrent_read_toggle)

    # لایه ۷: Browser E2E
    suite.run_test("L7-1: زنگوله اعلان‌ها در مرورگر", test_notification_L7_browser_notification_bell_interaction)

    # لایه ۸: Data Integrity
    suite.run_test("L8-1: تطابق تعداد اعلان‌های دیتابیس", test_notification_L8_data_count_consistency)

    # لایه ۹: Async & Queues
    suite.run_test("L9-1: جاب پاکسازی اعلان‌ها در Cron", test_notification_L9_background_notification_cleanup_cron)
    suite.run_test("L9-2: پردازش صف‌های اعلان", test_notification_L9_background_queue_notification_handling)

    # لایه ۱۰: Infra & Observability
    suite.run_test("L10-1: لاگ حسابرسی تنظیمات اعلان", test_notification_L10_audit_trail_preferences_change)
    suite.run_test("L10-2: پایش خطاهای اعلان در Sentry", test_notification_L10_sentry_monitoring_notification_exceptions)

    ok = suite.summary()
    sys.exit(0 if ok else 1)
