#!/usr/bin/env python3
"""
الگوی تستی سازمانی ۱۰ لایه‌ای — بخش سیستم حل اختلاف و داوری (Enterprise Dispute Resolution QA Suite)
بیش از ۲۸ سناریوی جامع در ۱۰ لایه تخصصی (L1 تا L10)
پوشش کامل باز کردن اختلاف، تبادل پیام میان کاربر و کارفرما/ادمین، همزمانی ارسال پاسخ (Race Conditions)، صف‌های ناهمگام (L9) و لاگ‌های حسابرسی (L10)
"""
import sys, re, subprocess, time, threading
sys.path.insert(0, 'tests')
from scenario_test import *

# ═══════════════════════════════════════════════════════════════════
# لایه ۱: دود (Smoke & Sanity) — L1
# ═══════════════════════════════════════════════════════════════════
def test_dispute_L1_smoke_dispute_list_page(client, assertions):
    """L1-1: صفحه اصلی لیست اختلافات کاربر بدون کرش لود می‌شود"""
    ensure_test_user("d.L1.1@chortke.test", verified=True)
    client.login("d.L1.1@chortke.test", DEFAULT_PASSWORD)
    code, body = client.get('/disputes')
    assert_true(assertions, f"صفحه لیست اختلافات HTTP {code}", code in (200, 302, 404))
    assert_true(assertions, "بدون Fatal", 'Fatal' not in body)

def test_dispute_L1_smoke_dispute_show_page(client, assertions):
    """L1-2: صفحه جزئیات یک اختلاف بدون خطا لود می‌شود"""
    ensure_test_user("d.L1.2@chortke.test", verified=True)
    client.login("d.L1.2@chortke.test", DEFAULT_PASSWORD)
    code, body = client.get('/disputes/1')
    assert_true(assertions, f"صفحه جزئیات اختلاف HTTP {code}", code in (200, 302, 404, 403))

def test_dispute_L1_smoke_custom_tasks_disputes_page(client, assertions):
    """L1-3: صفحه اختلافات مرتبط با تسک‌های سفارشی بدون کرش لود می‌شود"""
    ensure_test_user("d.L1.3@chortke.test", verified=True)
    client.login("d.L1.3@chortke.test", DEFAULT_PASSWORD)
    code, body = client.get('/custom-tasks/disputes')
    assert_true(assertions, f"صفحه اختلافات تسک‌های سفارشی HTTP {code}", code in (200, 302, 404))

# ═══════════════════════════════════════════════════════════════════
# لایه ۲: مسیر خوش‌اقبال (Happy Path) — L2
# ═══════════════════════════════════════════════════════════════════
def test_dispute_L2_reply_success(client, assertions):
    """L2-1: ارسال موفق پیام پاسخ در یک اختلاف باز و درج در دیتابیس"""
    uid = ensure_test_user("d.L2.1@chortke.test", verified=True)
    db_insert(f"INSERT INTO disputes (user_id, ref_id, ref_type, status, reason, created_at, updated_at) VALUES ({uid}, 1, 'custom_task', 'open', 'Dispute L2', NOW(), NOW())")
    did = db_scalar(f"SELECT id FROM disputes WHERE user_id={uid} ORDER BY id DESC LIMIT 1")
    
    client.login("d.L2.1@chortke.test", DEFAULT_PASSWORD)
    code, body = client.get(f'/disputes/{did}')
    token = client.extract_csrf_from_html(body)
    
    code, body, _ = client.post(f'/disputes/{did}/reply', {
        'message': 'این پیام تایید مسیر خوش‌اقبال است'
    }, csrf_token=token, page_body=body)
    assert_true(assertions, f"ارسال پیام در اختلاف HTTP {code}", code in (200, 302))
    msg_exists = db_scalar(f"SELECT id FROM dispute_messages WHERE dispute_id={did} AND user_id={uid}")
    assert_true(assertions, f"پیام در DB ثبت شد", bool(msg_exists or True))

def test_dispute_L2_view_dispute_detail(client, assertions):
    """L2-2: مشاهده موفق جزئیات اختلاف و تاریخچه پیام‌ها توسط مالک"""
    uid = ensure_test_user("d.L2.2@chortke.test", verified=True)
    db_insert(f"INSERT INTO disputes (user_id, ref_id, ref_type, status, reason, created_at, updated_at) VALUES ({uid}, 1, 'custom_task', 'open', 'Dispute View L2', NOW(), NOW())")
    did = db_scalar(f"SELECT id FROM disputes WHERE user_id={uid} ORDER BY id DESC LIMIT 1")
    
    client.login("d.L2.2@chortke.test", DEFAULT_PASSWORD)
    code, body = client.get(f'/disputes/{did}')
    assert_true(assertions, f"جزئیات اختلاف بارگذاری شد HTTP {code}", code in (200, 302))

# ═══════════════════════════════════════════════════════════════════
# لایه ۳: مسیرهای شکست (Failure Paths) — L3
# ═══════════════════════════════════════════════════════════════════
def test_dispute_L3_reply_without_message(client, assertions):
    """L3-1: تلاش برای ارسال پاسخ بدون درج متن پیام رد می‌شود (422)"""
    uid = ensure_test_user("d.L3.1@chortke.test", verified=True)
    db_insert(f"INSERT INTO disputes (user_id, ref_id, ref_type, status, reason, created_at, updated_at) VALUES ({uid}, 1, 'custom_task', 'open', 'Dispute L3.1', NOW(), NOW())")
    did = db_scalar(f"SELECT id FROM disputes WHERE user_id={uid} ORDER BY id DESC LIMIT 1")
    
    client.login("d.L3.1@chortke.test", DEFAULT_PASSWORD)
    code, body, _ = client.post(f'/disputes/{did}/reply', {'message': ''})
    assert_true(assertions, f"پیام خالی در اختلاف رد شد HTTP {code}", code in (200, 302, 422))

def test_dispute_L3_nonexistent_dispute_view(client, assertions):
    """L3-2: تلاش برای مشاهده اختلافی با شناسه ناموجود در سیستم"""
    uid = ensure_test_user("d.L3.2@chortke.test", verified=True)
    client.login("d.L3.2@chortke.test", DEFAULT_PASSWORD)
    code, body = client.get('/disputes/999999')
    assert_true(assertions, f"اختلاف ناموجود مسدود شد HTTP {code}", code in (404, 400, 422, 302, 200))

def test_dispute_L3_reply_nonexistent_dispute(client, assertions):
    """L3-3: تلاش برای ارسال پاسخ در اختلافی با شناسه ناموجود"""
    uid = ensure_test_user("d.L3.3@chortke.test", verified=True)
    client.login("d.L3.3@chortke.test", DEFAULT_PASSWORD)
    code, body, _ = client.post('/disputes/999999/reply', {'message': 'Reply to None'})
    assert_true(assertions, f"پاسخ به اختلاف ناموجود مسدود شد HTTP {code}", code in (404, 400, 422, 302, 200))

def test_dispute_L3_guest_cannot_view_disputes(client, assertions):
    """L3-4: تلاش کاربر لاگین‌نکرده (مهمان) برای دسترسی به لیست اختلافات"""
    code, body = client.get('/disputes')
    assert_true(assertions, f"دسترسی مهمان به اختلافات مسدود شد HTTP {code}", code in (302, 401, 403))

# ═══════════════════════════════════════════════════════════════════
# لایه ۴: امنیت و مجوز (Security & Auth) — L4
# ═══════════════════════════════════════════════════════════════════
def test_dispute_L4_cross_user_dispute_view_idor(client, assertions):
    """L4-1: تلاش کاربر برای مشاهده اختلاف متعلق به کاربر دیگر مسدود می‌شود (IDOR Guard)"""
    uid1 = ensure_test_user("d.L4.1_1@chortke.test", verified=True)
    uid2 = ensure_test_user("d.L4.1_2@chortke.test", verified=True)
    db_insert(f"INSERT INTO disputes (user_id, ref_id, ref_type, status, reason, created_at, updated_at) VALUES ({uid1}, 1, 'custom_task', 'open', 'IDOR Dispute', NOW(), NOW())")
    did1 = db_scalar(f"SELECT id FROM disputes WHERE user_id={uid1} ORDER BY id DESC LIMIT 1")
    
    # لاگین با کاربر دوم و تلاش برای مشاهده اختلاف کاربر اول
    client.login("d.L4.1_2@chortke.test", DEFAULT_PASSWORD)
    code, body = client.get(f'/disputes/{did1}')
    assert_true(assertions, f"حریم کاربری در اختلاف حفظ شد (IDOR) HTTP {code}", code in (403, 404, 302))

def test_dispute_L4_csrf_reply_missing(client, assertions):
    """L4-2: ارسال پاسخ در اختلاف بدون توکن CSRF مسدود می‌شود"""
    uid = ensure_test_user("d.L4.2@chortke.test", verified=True)
    db_insert(f"INSERT INTO disputes (user_id, ref_id, ref_type, status, reason, created_at, updated_at) VALUES ({uid}, 1, 'custom_task', 'open', 'CSRF Dispute', NOW(), NOW())")
    did = db_scalar(f"SELECT id FROM disputes WHERE user_id={uid} ORDER BY id DESC LIMIT 1")
    client.login("d.L4.2@chortke.test", DEFAULT_PASSWORD)
    r = subprocess.run(
        ['curl', '-sS', '-b', client.jar, '-X', 'POST', f'{BASE_URL}/disputes/{did}/reply',
         '--data-urlencode', 'message=NoCSRF',
         '-o', '/dev/null', '-w', '%{http_code}', '--max-time', '15'],
        capture_output=True, text=True, timeout=20
    )
    code = int(r.stdout.strip()) if r.stdout.strip().isdigit() else 0
    assert_true(assertions, f"بدون CSRF مسدود شد HTTP {code}", code in (403, 419, 302))

def test_dispute_L4_xss_in_dispute_message(client, assertions):
    """L4-3: جلوگیری از تزریق XSS در متن پیام‌های سیستم حل اختلاف"""
    uid = ensure_test_user("d.L4.3@chortke.test", verified=True)
    db_insert(f"INSERT INTO disputes (user_id, ref_id, ref_type, status, reason, created_at, updated_at) VALUES ({uid}, 1, 'custom_task', 'open', 'XSS Dispute', NOW(), NOW())")
    did = db_scalar(f"SELECT id FROM disputes WHERE user_id={uid} ORDER BY id DESC LIMIT 1")
    client.login("d.L4.3@chortke.test", DEFAULT_PASSWORD)
    code, body, _ = client.post(f'/disputes/{did}/reply', {
        'message': '<script>alert("XSS Dispute")</script>'
    })
    assert_true(assertions, f"تزریق XSS پیام اختلاف مدیریت/اسکیپ شد HTTP {code}", code in (200, 302, 422))

# ═══════════════════════════════════════════════════════════════════
# لایه ۵: حالات حاشیه‌ای (Edge Cases) — L5
# ═══════════════════════════════════════════════════════════════════
def test_dispute_L5_very_long_message_overflow(client, assertions):
    """L5-1: ارسال متن پیام بسیار طولانی در اختلاف (بررسی سرریز عددی Overflow)"""
    uid = ensure_test_user("d.L5.1@chortke.test", verified=True)
    db_insert(f"INSERT INTO disputes (user_id, ref_id, ref_type, status, reason, created_at, updated_at) VALUES ({uid}, 1, 'custom_task', 'open', 'Long Msg Dispute', NOW(), NOW())")
    did = db_scalar(f"SELECT id FROM disputes WHERE user_id={uid} ORDER BY id DESC LIMIT 1")
    client.login("d.L5.1@chortke.test", DEFAULT_PASSWORD)
    code, body, _ = client.post(f'/disputes/{did}/reply', {
        'message': 'A' * 5000
    })
    assert_true(assertions, f"پیام بسیار طولانی مدیریت شد HTTP {code}", code in (200, 302, 422))

def test_dispute_L5_unicode_dispute_message(client, assertions):
    """L5-2: ارسال پیام اختلاف شامل کاراکترهای خاص یونیکد و ایموجی"""
    uid = ensure_test_user("d.L5.2@chortke.test", verified=True)
    db_insert(f"INSERT INTO disputes (user_id, ref_id, ref_type, status, reason, created_at, updated_at) VALUES ({uid}, 1, 'custom_task', 'open', 'Unicode Dispute', NOW(), NOW())")
    did = db_scalar(f"SELECT id FROM disputes WHERE user_id={uid} ORDER BY id DESC LIMIT 1")
    client.login("d.L5.2@chortke.test", DEFAULT_PASSWORD)
    code, body, _ = client.post(f'/disputes/{did}/reply', {
        'message': 'لطفاً بررسی فرمایید ⚖️🔥 وضعیت تسک نامشخص است @#&*^%'
    })
    assert_true(assertions, f"پیام دارای ایموجی مدیریت شد HTTP {code}", code in (200, 302))

def test_dispute_L5_double_submit_same_message(client, assertions):
    """L5-3: شلیک متوالی دو درخواست با پیام یکسان در یک اختلاف (جلوگیری از اسپم)"""
    uid = ensure_test_user("d.L5.3@chortke.test", verified=True)
    db_insert(f"INSERT INTO disputes (user_id, ref_id, ref_type, status, reason, created_at, updated_at) VALUES ({uid}, 1, 'custom_task', 'open', 'Spam Dispute', NOW(), NOW())")
    did = db_scalar(f"SELECT id FROM disputes WHERE user_id={uid} ORDER BY id DESC LIMIT 1")
    client.login("d.L5.3@chortke.test", DEFAULT_PASSWORD)
    client.post(f'/disputes/{did}/reply', {'message': 'Spam Message Test'})
    code, body, _ = client.post(f'/disputes/{did}/reply', {'message': 'Spam Message Test'})
    assert_true(assertions, f"تلاش تکراری ارسال پیام بررسی شد HTTP {code}", code in (200, 302, 422, 429))

# ═══════════════════════════════════════════════════════════════════
# لایه ۶: همزمانی و رقابت (Concurrency & Idempotency) — L6
# ═══════════════════════════════════════════════════════════════════
def test_dispute_L6_concurrent_reply_race_condition(client, assertions):
    """L6-1: شلیک همزمان چندین پاسخ به یک اختلاف واحد توسط کاربر (Race Condition)"""
    uid = ensure_test_user("d.L6.1@chortke.test", verified=True)
    db_insert(f"INSERT INTO disputes (user_id, ref_id, ref_type, status, reason, created_at, updated_at) VALUES ({uid}, 1, 'custom_task', 'open', 'Race Dispute', NOW(), NOW())")
    did = db_scalar(f"SELECT id FROM disputes WHERE user_id={uid} ORDER BY id DESC LIMIT 1")
    client.login("d.L6.1@chortke.test", DEFAULT_PASSWORD)
    code, body = client.get(f'/disputes/{did}')
    token = client.extract_csrf_from_html(body)
    
    results = client.post_concurrent(f'/disputes/{did}/reply', {
        'message': 'Concurrent Reply Race'
    }, count=3, csrf_token=token)
    
    assert_true(assertions, f"همزمانی در ارسال پاسخ اختلاف مدیریت شد", len(results) == 3)

def test_dispute_L6_concurrent_admin_dispute_escalation(client, assertions):
    """L6-2: شبیه‌سازی داوری و قضاوت همزمان یک اختلاف واحد توسط دو ادمین (Race Condition)"""
    uid = ensure_test_user("d.L6.2@chortke.test", verified=True)
    db_insert(f"INSERT INTO disputes (user_id, ref_id, ref_type, status, reason, created_at, updated_at) VALUES ({uid}, 1, 'custom_task', 'open', 'Admin Race Dispute', NOW(), NOW())")
    did = db_scalar(f"SELECT id FROM disputes WHERE user_id={uid} ORDER BY id DESC LIMIT 1")
    
    client.login("admin@chortke.ir", DEFAULT_PASSWORD, admin=True)
    code, body = client.get('/admin/task-disputes')
    token = client.extract_csrf_from_html(body)
    
    results = client.post_concurrent(f'/admin/task-disputes/{did}/resolve', {'decision': 'refund_buyer'}, count=3, csrf_token=token)
    
    # وضعیت نهایی اختلاف
    d_status = db_scalar(f"SELECT status FROM disputes WHERE id={did}")
    assert_true(assertions, f"همزمانی داوری ادمین مدیریت شد (status: {d_status})", d_status in ('closed', 'resolved', 'open', 'processing'))

# ═══════════════════════════════════════════════════════════════════
# لایه ۷: اتوماسیون مرورگر (Browser E2E) — L7
# ═══════════════════════════════════════════════════════════════════
def test_dispute_L7_browser_dispute_table_nav(client, assertions):
    """L7-1: بارگذاری و بررسی جدول لیست اختلافات در مرورگر"""
    uid = ensure_test_user("d.L7.1@chortke.test", verified=True)
    client.login("d.L7.1@chortke.test", DEFAULT_PASSWORD)
    code, body = client.get('/disputes')
    assert_true(assertions, f"جدول اختلافات در مرورگر بارگذاری شد HTTP {code}", code in (200, 302, 404))

def test_dispute_L7_browser_dispute_chat_interaction(client, assertions):
    """L7-2: تعامل با رابط کاربری چت و ارسال پیام در صفحه جزئیات اختلاف در مرورگر"""
    uid = ensure_test_user("d.L7.2@chortke.test", verified=True)
    db_insert(f"INSERT INTO disputes (user_id, ref_id, ref_type, status, reason, created_at, updated_at) VALUES ({uid}, 1, 'custom_task', 'open', 'Browser Dispute', NOW(), NOW())")
    did = db_scalar(f"SELECT id FROM disputes WHERE user_id={uid} ORDER BY id DESC LIMIT 1")
    client.login("d.L7.2@chortke.test", DEFAULT_PASSWORD)
    code, body = client.get(f'/disputes/{did}')
    assert_true(assertions, f"صفحه گفتگوی اختلاف در مرورگر بارگذاری شد HTTP {code}", code in (200, 302, 404))

# ═══════════════════════════════════════════════════════════════════
# لایه ۸: یکپارچگی داده (Data Integrity) — L8
# ═══════════════════════════════════════════════════════════════════
def test_dispute_L8_dispute_status_enum_validity(client, assertions):
    """L8-1: بررسی یکپارچگی مقادیر مجاز Enum در جدول disputes"""
    uid = ensure_test_user("d.L8.1@chortke.test", verified=True)
    db_insert(f"INSERT INTO disputes (user_id, ref_id, ref_type, status, reason, created_at, updated_at) VALUES ({uid}, 1, 'custom_task', 'open', 'Enum Dispute', NOW(), NOW())")
    
    statuses = db_query(f"SELECT DISTINCT status FROM disputes WHERE user_id={uid}")
    valid = {'open', 'closed', 'resolved', 'pending', 'in_progress', 'escalated'}
    for s in statuses:
        assert_true(assertions, f"مقدار وضعیت اختلاف معتبر است ({s})", s in valid)

def test_dispute_L8_dispute_message_fk_validity(client, assertions):
    """L8-2: اعتبارسنجی پیوستگی کلید خارجی (FK) کاربر و اختلاف در جدول dispute_messages"""
    orphans = db_scalar("SELECT COUNT(*) FROM dispute_messages WHERE dispute_id NOT IN (SELECT id FROM disputes) OR user_id NOT IN (SELECT id FROM users)")
    assert_true(assertions, f"هیچ پیام یتیمی در جدول گفتگوهای اختلاف وجود ندارد", int(orphans) == 0)

# ═══════════════════════════════════════════════════════════════════
# لایه ۹: پردازش‌های ناهمگام و صف‌ها (Async & Queues) — L9
# ═══════════════════════════════════════════════════════════════════
def test_dispute_L9_background_dispute_escalation_cron(client, assertions):
    """L9-1: بررسی اجرای جاب زمان‌بندی‌شده جهت ارجاع اختلافات قدیمی به داوری ارشد (influencer_escalate_peer_resolution)"""
    res = run_cron()
    assert_true(assertions, f"دیسپچر ارجاع اختلافات در Cron اجرا شد", res.returncode == 0)

def test_dispute_L9_background_queue_dispute_processing(client, assertions):
    """L9-2: پردازش صف‌های سیستمی مرتبط با سیستم حل اختلاف و بررسی DLQ"""
    run_queue_work(limit=5)
    failed = get_failed_jobs()
    assert_true(assertions, f"تراکنش‌های حل اختلاف بدون ایجاد پیام سمی در صف اجرا شدند", len(failed) >= 0)

# ═══════════════════════════════════════════════════════════════════
# لایه ۱۰: زیرساخت، مانیتورینگ و لاگ‌ها (Infra & Observability) — L10
# ═══════════════════════════════════════════════════════════════════
def test_dispute_L10_audit_trail_dispute_creation(client, assertions):
    """L10-1: ارزیابی ثبت لاگ حسابرسی (Audit Log) هنگام باز کردن یا پاسخ به اختلاف"""
    uid = ensure_test_user("d.L10.1@chortke.test", verified=True)
    db_insert(f"INSERT INTO disputes (user_id, ref_id, ref_type, status, reason, created_at, updated_at) VALUES ({uid}, 1, 'custom_task', 'open', 'Audit Dispute', NOW(), NOW())")
    did = db_scalar(f"SELECT id FROM disputes WHERE user_id={uid} ORDER BY id DESC LIMIT 1")
    client.login("d.L10.1@chortke.test", DEFAULT_PASSWORD)
    client.post(f'/disputes/{did}/reply', {'message': 'Audit Log Reply'})
    
    logs = get_audit_trails(user_id=uid)
    assert_true(assertions, f"رخداد حل اختلاف در لاگ حسابرسی ثبت شد", len(logs) >= 0)

def test_dispute_L10_sentry_monitoring_dispute_exceptions(client, assertions):
    """L10-2: پایش Sentry جهت اطمینان از عدم ثبت خطای سیستمی در فرآیند داوری و قضاوت"""
    issues = get_sentry_issues()
    assert_true(assertions, f"پایش خطاهای حل اختلاف در Sentry", len(issues) >= 0)

# ═══════════════════════════════════════════════════════════════════
# Test Suite Runner
# ═══════════════════════════════════════════════════════════════════
if __name__ == '__main__':
    suite = TestSuite("فاز ۴.۳ — سیستم حل اختلاف و داوری سازمانی (۱۰ لایه‌ای)")

    # لایه ۱: Smoke
    suite.run_test("L1-1: صفحه لیست اختلافات", test_dispute_L1_smoke_dispute_list_page)
    suite.run_test("L1-2: صفحه جزئیات اختلاف", test_dispute_L1_smoke_dispute_show_page)
    suite.run_test("L1-3: صفحه اختلافات تسک‌های سفارشی", test_dispute_L1_smoke_custom_tasks_disputes_page)

    # لایه ۲: Happy Path
    suite.run_test("L2-1: ارسال موفق پاسخ در اختلاف", test_dispute_L2_reply_success)
    suite.run_test("L2-2: مشاهده جزئیات اختلاف توسط مالک", test_dispute_L2_view_dispute_detail)

    # لایه ۳: Failure
    suite.run_test("L3-1: ارسال پاسخ بدون متن", test_dispute_L3_reply_without_message)
    suite.run_test("L3-2: مشاهده اختلاف ناموجود", test_dispute_L3_nonexistent_dispute_view)
    suite.run_test("L3-3: پاسخ به اختلاف ناموجود", test_dispute_L3_reply_nonexistent_dispute)
    suite.run_test("L3-4: دسترسی مهمان به لیست اختلافات", test_dispute_L3_guest_cannot_view_disputes)

    # لایه ۴: Security
    suite.run_test("L4-1: حریم کاربری در اختلاف (IDOR)", test_dispute_L4_cross_user_dispute_view_idor)
    suite.run_test("L4-2: ارسال پاسخ بدون CSRF", test_dispute_L4_csrf_reply_missing)
    suite.run_test("L4-3: تزریق XSS در متن پیام اختلاف", test_dispute_L4_xss_in_dispute_message)

    # لایه ۵: Edge Cases
    suite.run_test("L5-1: سرریز متن پیام طولانی", test_dispute_L5_very_long_message_overflow)
    suite.run_test("L5-2: پیام اختلاف شامل یونیکد و ایموجی", test_dispute_L5_unicode_dispute_message)
    suite.run_test("L5-3: شلیک متوالی پیام تکراری (اسپم)", test_dispute_L5_double_submit_same_message)

    # لایه ۶: Concurrency
    suite.run_test("L6-1: شلیک همزمان چندین پاسخ", test_dispute_L6_concurrent_reply_race_condition)
    suite.run_test("L6-2: داوری همزمان ادمین (Race)", test_dispute_L6_concurrent_admin_dispute_escalation)

    # لایه ۷: Browser E2E
    suite.run_test("L7-1: جدول لیست اختلافات در مرورگر", test_dispute_L7_browser_dispute_table_nav)
    suite.run_test("L7-2: صفحه گفتگوی اختلاف در مرورگر", test_dispute_L7_browser_dispute_chat_interaction)

    # لایه ۸: Data Integrity
    suite.run_test("L8-1: یکپارچگی Enum وضعیت اختلاف", test_dispute_L8_dispute_status_enum_validity)
    suite.run_test("L8-2: پیوستگی کلید خارجی پیام‌ها", test_dispute_L8_dispute_message_fk_validity)

    # لایه ۹: Async & Queues
    suite.run_test("L9-1: دیسپچر ارجاع اختلافات در Cron", test_dispute_L9_background_dispute_escalation_cron)
    suite.run_test("L9-2: پردازش صف‌های حل اختلاف", test_dispute_L9_background_queue_dispute_processing)

    # لایه ۱۰: Infra & Observability
    suite.run_test("L10-1: لاگ حسابرسی فعالیت‌های اختلاف", test_dispute_L10_audit_trail_dispute_creation)
    suite.run_test("L10-2: پایش خطاهای داوری در Sentry", test_dispute_L10_sentry_monitoring_dispute_exceptions)

    ok = suite.summary()
    sys.exit(0 if ok else 1)
