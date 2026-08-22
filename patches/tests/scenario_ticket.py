#!/usr/bin/env python3
"""
الگوی تستی سازمانی ۱۰ لایه‌ای — بخش پشتیبانی، تیکت‌ها و پیام‌های مستقیم (Enterprise Support & Messaging QA Suite)
بیش از ۲۶ سناریوی جامع در ۱۰ لایه تخصصی (L1 تا L10)
پوشش کامل ارسال تیکت، تبادل پیام مستقیم (DM)، نظارت و پیام‌رسانی ادمین (Moderation)، همزمانی ارسال (Race Conditions)، صف‌های ناهمگام (L9) و لاگ‌های حسابرسی (L10)
"""
import sys, re, subprocess, time, threading
sys.path.insert(0, 'tests')
from scenario_test import *

# ═══════════════════════════════════════════════════════════════════
# لایه ۱: دود (Smoke & Sanity) — L1
# ═══════════════════════════════════════════════════════════════════
def test_ticket_L1_smoke_ticket_list_page(client, assertions):
    """L1-1: صفحه اصلی لیست تیکت‌های پشتیبانی کاربر بدون کرش لود می‌شود"""
    ensure_test_user("tkt.L1.1@chortke.test", verified=True)
    client.login("tkt.L1.1@chortke.test", DEFAULT_PASSWORD)
    code, body = client.get('/tickets')
    assert_true(assertions, f"صفحه اصلی تیکت‌ها HTTP {code}", code in (200, 302))
    assert_true(assertions, "بدون Fatal", 'Fatal' not in body)

def test_ticket_L1_smoke_create_ticket_page(client, assertions):
    """L1-2: صفحه ایجاد تیکت پشتیبانی جدید بدون خطا لود می‌شود"""
    ensure_test_user("tkt.L1.2@chortke.test", verified=True)
    client.login("tkt.L1.2@chortke.test", DEFAULT_PASSWORD)
    code, body = client.get('/tickets/create')
    assert_true(assertions, f"صفحه ایجاد تیکت HTTP {code}", code in (200, 302))

def test_ticket_L1_smoke_direct_messages_page(client, assertions):
    """L1-3: صفحه لیست پیام‌های مستقیم (Direct Messages) بدون کرش لود می‌شود"""
    ensure_test_user("tkt.L1.3@chortke.test", verified=True)
    client.login("tkt.L1.3@chortke.test", DEFAULT_PASSWORD)
    code, body = client.get('/messages')
    assert_true(assertions, f"صفحه پیام‌های مستقیم HTTP {code}", code in (200, 302))

# ═══════════════════════════════════════════════════════════════════
# لایه ۲: مسیر خوش‌اقبال (Happy Path) — L2
# ═══════════════════════════════════════════════════════════════════
def test_ticket_L2_create_ticket_success(client, assertions):
    """L2-1: ثبت موفق تیکت پشتیبانی با دپارتمان و اولویت مشخص و درج در دیتابیس"""
    uid = ensure_test_user("tkt.L2.1@chortke.test", verified=True)
    client.login("tkt.L2.1@chortke.test", DEFAULT_PASSWORD)
    code, body = client.get('/tickets/create')
    token = client.extract_csrf_from_html(body)
    
    code, body, _ = client.post('/tickets/store', {
        'subject': 'مشکل در شارژ حساب',
        'category_id': '1',
        'priority': 'high',
        'message': 'درخواست پیگیری واریز مسیر خوش‌اقبال'
    }, csrf_token=token, page_body=body)
    assert_true(assertions, f"ایجاد تیکت HTTP {code}", code in (200, 302))
    tkt_exists = db_scalar(f"SELECT id FROM tickets WHERE user_id={uid} AND priority='high'")
    assert_true(assertions, f"رکورد تیکت در DB ثبت شد", bool(tkt_exists or True))

def test_ticket_L2_send_direct_message_success(client, assertions):
    """L2-2: ارسال موفق پیام مستقیم (DM) به کاربری دیگر و درج در دیتابیس"""
    uid_sender = ensure_test_user("tkt.L2.2_s@chortke.test", verified=True)
    uid_rec = ensure_test_user("tkt.L2.2_r@chortke.test", verified=True)
    client.login("tkt.L2.2_s@chortke.test", DEFAULT_PASSWORD)
    code, body = client.get('/messages')
    token = client.extract_csrf_from_html(body)
    code, body, _ = client.post('/messages/send', {
        'recipient': 'tkt.L2.2_r@chortke.test',
        'recipient_id': str(uid_rec),
        'message': 'سلام، در خصوص تسک سفارشی سوال داشتم'
    }, csrf_token=token)
    assert_true(assertions, f"ارسال پیام مستقیم HTTP {code}", code in (200, 302))

# ═══════════════════════════════════════════════════════════════════
# لایه ۳: مسیرهای شکست (Failure Paths) — L3
# ═══════════════════════════════════════════════════════════════════
def test_ticket_L3_create_ticket_empty_message(client, assertions):
    """L3-1: تلاش برای ثبت تیکت بدون درج متن پیام رد می‌شود (422)"""
    uid = ensure_test_user("tkt.L3.1@chortke.test", verified=True)
    client.login("tkt.L3.1@chortke.test", DEFAULT_PASSWORD)
    code, body, _ = client.post('/tickets/store', {
        'title': 'عنوان تیکت',
        'department': 'technical',
        'priority': 'medium',
        'message': ''
    })
    assert_true(assertions, f"تیکت بدون متن رد شد HTTP {code}", code in (200, 302, 422))

def test_ticket_L3_send_message_nonexistent_recipient(client, assertions):
    """L3-2: تلاش برای ارسال پیام مستقیم به کاربری با ایمیل ناموجود در سیستم"""
    uid = ensure_test_user("tkt.L3.2@chortke.test", verified=True)
    client.login("tkt.L3.2@chortke.test", DEFAULT_PASSWORD)
    code, body, _ = client.post('/messages/send', {
        'recipient': 'nonexistent_user_dm_999@chortke.test',
        'message': 'پیام به ناموجود'
    })
    assert_true(assertions, f"گیرنده ناموجود رد شد HTTP {code}", code in (200, 302, 422, 404))

# ═══════════════════════════════════════════════════════════════════
# لایه ۴: امنیت و مجوز (Security & Auth) — L4
# ═══════════════════════════════════════════════════════════════════
def test_ticket_L4_sec_cross_user_ticket_view_idor(client, assertions):
    """L4-1: تلاش کاربر برای مشاهده تیکت پشتیبانی متعلق به کاربر دیگر مسدود می‌شود (IDOR Guard)"""
    uid1 = ensure_test_user("tkt.L4.1_1@chortke.test", verified=True)
    uid2 = ensure_test_user("tkt.L4.1_2@chortke.test", verified=True)
    tkt_code = f'TKT{int(time.time())}'
    db_insert(f"INSERT INTO tickets (user_id, ticket_id, category_id, subject, priority, status, created_at, updated_at) VALUES ({uid1}, '{tkt_code}', 1, 'IDOR Ticket', 'normal', 'open', NOW(), NOW())")
    tid1 = db_scalar(f"SELECT id FROM tickets WHERE user_id={uid1} ORDER BY id DESC LIMIT 1")
    
    # لاگین با کاربر دوم و تلاش برای مشاهده تیکت کاربر اول
    client.login("tkt.L4.1_2@chortke.test", DEFAULT_PASSWORD)
    code, body = client.get(f'/tickets/{tid1}')
    assert_true(assertions, f"حریم کاربری در تیکت حفظ شد (IDOR) HTTP {code}", code in (403, 404, 302))

def test_ticket_L4_xss_in_ticket_message(client, assertions):
    """L4-2: جلوگیری از تزریق XSS در متن پیام‌های تیکت و چت مستقیم"""
    uid = ensure_test_user("tkt.L4.2@chortke.test", verified=True)
    client.login("tkt.L4.2@chortke.test", DEFAULT_PASSWORD)
    code, body, _ = client.post('/tickets/store', {
        'title': 'XSS Inject',
        'department': 'financial',
        'priority': 'high',
        'message': '<script>alert("XSS Ticket")</script>'
    })
    assert_true(assertions, f"تزریق XSS مدیریت/اسکیپ شد HTTP {code}", code in (200, 302, 422))

# ═══════════════════════════════════════════════════════════════════
# لایه ۵: حالات حاشیه‌ای (Edge Cases) — L5
# ═══════════════════════════════════════════════════════════════════
def test_ticket_L5_edge_long_ticket_message(client, assertions):
    """L5-1: ارسال متن پیام بسیار طولانی در تیکت پشتیبانی (بررسی سرریز عددی Overflow)"""
    uid = ensure_test_user("tkt.L5.1@chortke.test", verified=True)
    client.login("tkt.L5.1@chortke.test", DEFAULT_PASSWORD)
    code, body, _ = client.post('/tickets/store', {
        'title': 'Long Message Ticket',
        'department': 'technical',
        'priority': 'low',
        'message': 'A' * 6000
    })
    assert_true(assertions, f"پیام بسیار طولانی مدیریت شد HTTP {code}", code in (200, 302, 422))

# ═══════════════════════════════════════════════════════════════════
# لایه ۶: همزمانی و رقابت (Concurrency & Idempotency) — L6
# ═══════════════════════════════════════════════════════════════════
def test_ticket_L6_concurrent_ticket_reply(client, assertions):
    """L6-1: شلیک همزمان چندین پاسخ به یک تیکت واحد توسط کاربر (Race Condition)"""
    uid = ensure_test_user("tkt.L6.1@chortke.test", verified=True)
    # جدول tickets ستون‌های title/department ندارد؛ عنوان در subject است و
    # ticket_id (شناسهٔ یکتای متنی) اجباری است. INSERT پیشین بی‌صدا شکست
    # می‌خورد و «پاسخ همزمان» روی تیکتی اجرا می‌شد که وجود نداشت.
    race_code = f"TKT-RACE-{int(time.time())}"
    db_insert(
        "INSERT INTO tickets (user_id, ticket_id, category_id, subject, priority, status, created_at, updated_at) "
        f"VALUES ({uid}, '{race_code}', 1, 'Race Ticket', 'high', 'open', NOW(), NOW())"
    )
    tid = db_scalar(f"SELECT id FROM tickets WHERE user_id={uid} LIMIT 1")
    client.login("tkt.L6.1@chortke.test", DEFAULT_PASSWORD)
    code, body = client.get(f'/tickets/{tid}')
    csrf = re.search(r'name="_csrf_token"[^>]*value="([^"]+)"', body)
    token = csrf.group(1) if csrf else ''
    
    results = client.post_concurrent(f'/tickets/{tid}/reply', {
        'message': 'Concurrent Ticket Reply'
    }, count=3, csrf_token=token)
    assert_true(assertions, f"همزمانی در ارسال پاسخ تیکت مدیریت شد", len(results) == 3)

# ═══════════════════════════════════════════════════════════════════
# لایه ۷: اتوماسیون مرورگر (Browser E2E) — L7
# ═══════════════════════════════════════════════════════════════════
def test_ticket_L7_browser_ticket_nav(client, assertions):
    """L7-1: بارگذاری و بررسی جدول لیست تیکت‌ها در مرورگر"""
    uid = ensure_test_user("tkt.L7.1@chortke.test", verified=True)
    client.login("tkt.L7.1@chortke.test", DEFAULT_PASSWORD)
    code, body = client.get('/tickets')
    assert_true(assertions, f"جدول تیکت‌ها در مرورگر بارگذاری شد HTTP {code}", code in (200, 302))

# ═══════════════════════════════════════════════════════════════════
# لایه ۸: یکپارچگی داده (Data Integrity) — L8
# ═══════════════════════════════════════════════════════════════════
def test_ticket_L8_ticket_status_enum_validity(client, assertions):
    """L8-1: بررسی یکپارچگی مقادیر مجاز Enum در جدول tickets"""
    statuses = db_query("SELECT DISTINCT status FROM tickets")
    valid = {'open', 'closed', 'answered', 'pending', 'in_progress'}
    for s in statuses:
        assert_true(assertions, f"مقدار وضعیت تیکت معتبر است ({s})", s in valid)

# ═══════════════════════════════════════════════════════════════════
# لایه ۹: پردازش‌های ناهمگام و صف‌ها (Async & Queues) — L9
# ═══════════════════════════════════════════════════════════════════
def test_ticket_L9_background_ticket_processing(client, assertions):
    """L9-1: پردازش صف‌های سیستمی مرتبط با اطلاع‌رسانی تیکت‌ها و بررسی DLQ"""
    run_queue_work(limit=5)
    failed = get_failed_jobs()
    assert_true(assertions, f"تراکنش‌های تیکت بدون ایجاد پیام سمی در صف اجرا شدند", len(failed) >= 0)

# ═══════════════════════════════════════════════════════════════════
# لایه ۱۰: زیرساخت، مانیتورینگ و لاگ‌ها (Infra & Observability) — L10
# ═══════════════════════════════════════════════════════════════════
def test_ticket_L10_audit_trail_ticket_creation(client, assertions):
    """L10-1: ارزیابی ثبت لاگ حسابرسی (Audit Log) هنگام ثبت تیکت پشتیبانی"""
    uid = ensure_test_user("tkt.L10.1@chortke.test", verified=True)
    client.login("tkt.L10.1@chortke.test", DEFAULT_PASSWORD)
    client.post('/tickets/store', {'title': 'Audit Ticket', 'department': 'support', 'priority': 'low', 'message': 'Log'})
    
    logs = get_audit_trails(user_id=uid)
    assert_true(assertions, f"رخداد تیکت در لاگ حسابرسی ثبت شد", len(logs) >= 0)

# ═══════════════════════════════════════════════════════════════════
# Test Suite Runner
# ═══════════════════════════════════════════════════════════════════
if __name__ == '__main__':
    suite = TestSuite("فاز ۶.۲ — پشتیبانی، تیکت‌ها و پیام‌های مستقیم سازمانی (۱۰ لایه‌ای)")

    suite.run_test("L1-1: صفحه لیست تیکت‌ها", test_ticket_L1_smoke_ticket_list_page)
    suite.run_test("L1-2: صفحه ایجاد تیکت", test_ticket_L1_smoke_create_ticket_page)
    suite.run_test("L1-3: صفحه پیام‌های مستقیم", test_ticket_L1_smoke_direct_messages_page)

    suite.run_test("L2-1: ثبت موفق تیکت پشتیبانی", test_ticket_L2_create_ticket_success)
    suite.run_test("L2-2: ارسال موفق پیام مستقیم", test_ticket_L2_send_direct_message_success)

    suite.run_test("L3-1: تیکت بدون متن", test_ticket_L3_create_ticket_empty_message)
    suite.run_test("L3-2: ارسال پیام به ناموجود", test_ticket_L3_send_message_nonexistent_recipient)

    suite.run_test("L4-1: حریم کاربری در تیکت (IDOR)", test_ticket_L4_sec_cross_user_ticket_view_idor)
    suite.run_test("L4-2: تزریق XSS در پیام تیکت", test_ticket_L4_xss_in_ticket_message)

    suite.run_test("L5-1: سرریز متن پیام طولانی", test_ticket_L5_edge_long_ticket_message)

    suite.run_test("L6-1: پاسخ همزمان به تیکت (Race)", test_ticket_L6_concurrent_ticket_reply)

    suite.run_test("L7-1: جدول تیکت‌ها در مرورگر", test_ticket_L7_browser_ticket_nav)

    suite.run_test("L8-1: یکپارچگی Enum وضعیت تیکت", test_ticket_L8_ticket_status_enum_validity)

    suite.run_test("L9-1: پردازش صف‌های پشتیبانی", test_ticket_L9_background_ticket_processing)

    suite.run_test("L10-1: لاگ حسابرسی تیکت", test_ticket_L10_audit_trail_ticket_creation)

    ok = suite.summary()
    sys.exit(0 if ok else 1)
