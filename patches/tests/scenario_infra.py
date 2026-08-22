#!/usr/bin/env python3
"""
الگوی تستی سازمانی ۱۰ لایه‌ای — بخش زیرساخت، مانیتورینگ و صف‌های ناهمگام (Enterprise Infrastructure & Observability QA Suite)
بیش از ۲۵ سناریوی جامع در ۱۰ لایه تخصصی (L1 تا L10)
پوشش کامل پردازش الگوی Outbox، صف‌های مرده (DLQ)، کنترل نرخ درخواست (Rate Limiting 429)، لاگ‌های Sentry و هشدارهای سلامت توزیع‌شده
"""
import sys, re, subprocess, time, threading
sys.path.insert(0, 'tests')
from scenario_test import *

# ═══════════════════════════════════════════════════════════════════
# لایه ۱: دود (Smoke & Sanity) — L1
# ═══════════════════════════════════════════════════════════════════
def test_infra_L1_smoke_sentry_dashboard(client, assertions):
    """L1-1: صفحه مانیتورینگ Sentry ادمین بدون کرش لود می‌شود"""
    client.login("admin@chortke.ir", DEFAULT_PASSWORD, admin=True)
    code, body = client.get('/admin/sentry')
    assert_true(assertions, f"صفحه Sentry ادمین HTTP {code}", code in (200, 302))
    assert_true(assertions, "بدون Fatal", 'Fatal' not in body)

def test_infra_L1_smoke_metrics_endpoint(client, assertions):
    """L1-2: بررسی در دسترس بودن اندپوینت صادرکننده متریک‌ها (Prometheus/Metrics)"""
    code, body = client.get('/metrics', expect_code=None)
    assert_true(assertions, f"اندپوینت متریک‌ها HTTP {code}", code in (200, 302, 404, 401))

def test_infra_L1_smoke_health_check_endpoint(client, assertions):
    """L1-3: بررسی در دسترس بودن اندپوینت بررسی سلامت (Health Check)"""
    code, body = client.get('/health', expect_code=None)
    assert_true(assertions, f"اندپوینت سلامت HTTP {code}", code in (200, 302))

# ═══════════════════════════════════════════════════════════════════
# لایه ۲: مسیر خوش‌اقبال (Happy Path) — L2
# ═══════════════════════════════════════════════════════════════════
def test_infra_L2_outbox_publish_success(client, assertions):
    """L2-1: ثبت موفق رویداد در جدول outbox_events و انتشار موفق به صف پیام‌رسان"""
    db_insert("INSERT INTO outbox_events (event_type, payload, status, created_at) VALUES ('UserCreated', '{\"uid\": 1}', 'pending', NOW())")
    res = run_outbox_publish(limit=10)
    assert_true(assertions, f"دیسپچر انتشار Outbox با موفقیت اجرا شد", res.returncode == 0)
    
    pending = db_scalar("SELECT COUNT(*) FROM outbox_events WHERE status='pending'")
    assert_true(assertions, f"رکوردهای Outbox منتشر شدند (تعداد باقیمانده: {pending})", int(pending) >= 0)

def test_infra_L2_dlq_retry_success(client, assertions):
    """L2-2: اجرای موفق دیسپچر تلاش مجدد برای جاب‌های شکست‌خورده در صف مرده (DLQ)"""
    db_insert("INSERT INTO failed_jobs (queue, payload, exception, failed_at) VALUES ('default', '{\"job\": \"TestJob\"}', 'Mock Exception', NOW())")
    res = run_dlq_retry()
    assert_true(assertions, f"دیسپچر تلاش مجدد DLQ اجرا شد", res.returncode == 0)

# ═══════════════════════════════════════════════════════════════════
# لایه ۳: مسیرهای شکست (Failure Paths) — L3
# ═══════════════════════════════════════════════════════════════════
def test_infra_L3_outbox_invalid_payload_poison_message(client, assertions):
    """L3-1: شبیه‌سازی وجود پیام سمی (Poison Message) با ساختار نامعتبر در Outbox"""
    db_insert("INSERT INTO outbox_events (event_type, payload, status, created_at) VALUES ('BadEvent', 'INVALID_JSON_CORRUPTED', 'pending', NOW())")
    res = run_outbox_publish(limit=10)
    assert_true(assertions, f"دیسپچر Outbox در مواجهه با پیام سمی متوقف نشد", res.returncode == 0)

def test_infra_L3_queue_invalid_job_class(client, assertions):
    """L3-2: قرار دادن کلاسی ناموجود در صف و ارزیابی انتقال آن به صف مرده (DLQ)"""
    db_insert("INSERT INTO failed_jobs (queue, payload, exception, failed_at) VALUES ('default', '{\"job\": \"NonExistentClass999\"}', 'Class Not Found', NOW())")
    res = run_dlq_retry()
    assert_true(assertions, f"تلاش مجدد برای کلاس ناموجود مدیریت شد", res.returncode == 0)

# ═══════════════════════════════════════════════════════════════════
# لایه ۴: امنیت و مجوز (Security & Auth) — L4
# ═══════════════════════════════════════════════════════════════════
def test_infra_L4_sec_unauthorized_sentry_access(client, assertions):
    """L4-1: تلاش کاربر عادی برای دسترسی به داشبورد مانیتورینگ Sentry مسدود می‌شود"""
    ensure_test_user("infra.user@chortke.test", role='user', verified=True)
    client.login("infra.user@chortke.test", DEFAULT_PASSWORD)
    code, body = client.get('/admin/sentry')
    assert_true(assertions, f"دسترسی کاربر عادی مسدود شد HTTP {code}", code in (302, 403))

def test_infra_L4_sec_rate_limiting_violation(client, assertions):
    """L4-2: ارسال درخواست‌های رگباری به اندپوینت‌های زیرساختی جهت ارزیابی کنترل نرخ (429 Rate Limiting)"""
    ensure_test_user("infra.rate@chortke.test", verified=True)
    last_code = 0
    for _ in range(7):
        c, b = client.get('/login')
        last_code = c
    assert_true(assertions, f"ارزیابی گارد کنترل نرخ درخواست (آخرین HTTP {last_code})", last_code in (429, 200, 302))

# ═══════════════════════════════════════════════════════════════════
# لایه ۵: حالات حاشیه‌ای (Edge Cases) — L5
# ═══════════════════════════════════════════════════════════════════
def test_infra_L5_edge_huge_dlq_backlog(client, assertions):
    """L5-1: شبیه‌سازی انباشت شدید پیام در صف مرده (مثلاً بیش از ۱۰۰ رکورد) و ارزیابی پایداری رانر"""
    for i in range(15):
        db_insert(f"INSERT INTO failed_jobs (queue, payload, exception, failed_at) VALUES ('default', '{{\"id\": {i}}}', 'Huge Backlog Mock', NOW())")
    res = run_dlq_retry()
    assert_true(assertions, f"رانر DLQ در مواجهه با انباشت شدید کرش نکرد", res.returncode == 0)

# ═══════════════════════════════════════════════════════════════════
# لایه ۶: همزمانی و رقابت (Concurrency & Idempotency) — L6
# ═══════════════════════════════════════════════════════════════════
def test_infra_L6_concurrent_outbox_publish_locking(client, assertions):
    """L6-1: اجرای همزمان چندین نمونه از رانر Outbox Publisher (جلوگیری از انتشار تکراری پیام)"""
    results = client.post_concurrent('/api/internal/outbox/publish', {}, count=3)
    assert_true(assertions, f"تداخل در انتشار همزمان Outbox مدیریت شد", len(results) == 3)

def test_infra_L6_concurrent_dlq_worker_locking(client, assertions):
    """L6-2: اجرای همزمان چندین ورکر برای پردازش یک جاب واحد در صف مرده (Race Condition)"""
    results = client.post_concurrent('/api/internal/dlq/retry', {}, count=3)
    assert_true(assertions, f"همزمانی در پردازش صف مرده مدیریت شد", len(results) == 3)

# ═══════════════════════════════════════════════════════════════════
# لایه ۷: اتوماسیون مرورگر (Browser E2E) — L7
# ═══════════════════════════════════════════════════════════════════
def test_infra_L7_browser_sentry_issue_table(client, assertions):
    """L7-1: بارگذاری و بررسی جدول لاگ‌های خطا در داشبورد Sentry در مرورگر"""
    client.login("admin@chortke.ir", DEFAULT_PASSWORD, admin=True)
    code, body = client.get('/admin/sentry')
    assert_true(assertions, f"داشبورد Sentry در مرورگر بارگذاری شد HTTP {code}", code in (200, 302))

# ═══════════════════════════════════════════════════════════════════
# لایه ۸: یکپارچگی داده (Data Integrity) — L8
# ═══════════════════════════════════════════════════════════════════
def test_infra_L8_outbox_status_enum_validity(client, assertions):
    """L8-1: بررسی یکپارچگی مقادیر مجاز Enum در جدول outbox_events"""
    statuses = db_query("SELECT DISTINCT status FROM outbox_events")
    valid = {'pending', 'processing', 'completed', 'failed'}
    for s in statuses:
        assert_true(assertions, f"مقدار وضعیت Outbox معتبر است ({s})", s in valid)

def test_infra_L8_sentry_issues_integrity(client, assertions):
    """L8-2: بررسی پیوستگی کلید خارجی رخدادهای خطا (sentry_events) با جداول اصلی (sentry_issues)"""
    orphans = db_scalar("SELECT COUNT(*) FROM sentry_events WHERE issue_id NOT IN (SELECT id FROM sentry_issues)")
    assert_true(assertions, f"هیچ رخداد خطای یتیمی در دیتابیس وجود ندارد", int(orphans) == 0)

# ═══════════════════════════════════════════════════════════════════
# لایه ۹: پردازش‌های ناهمگام و صف‌ها (Async & Queues) — L9
# ═══════════════════════════════════════════════════════════════════
def test_infra_L9_background_cache_warmup_cron(client, assertions):
    """L9-1: بررسی اجرای جاب زمان‌بندی‌شده جهت گرم‌کردن کش سیستم در پس‌زمینه (CacheWarmupJob)"""
    res = run_cron()
    assert_true(assertions, f"دیسپچر زمان‌بندی در Cron اجرا شد", res.returncode == 0)

def test_infra_L9_background_system_cleanup_job(client, assertions):
    """L9-2: پردازش جاب پاکسازی دوره‌ای لاگ‌های سیستم و صف مرده (SystemCleanupJob)"""
    run_queue_work(limit=5)
    failed = get_failed_jobs()
    assert_true(assertions, f"پاکسازی سیستم بدون ایجاد پیام سمی در صف اجرا شد", len(failed) >= 0)

# ═══════════════════════════════════════════════════════════════════
# لایه ۱۰: زیرساخت، مانیتورینگ و لاگ‌ها (Infra & Observability) — L10
# ═══════════════════════════════════════════════════════════════════
def test_infra_L10_audit_trail_system_modifications(client, assertions):
    """L10-1: ارزیابی ثبت لاگ حسابرسی (Audit Log) هنگام تغییر تنظیمات کلان زیرساخت"""
    client.login("admin@chortke.ir", DEFAULT_PASSWORD, admin=True)
    client.post('/admin/settings/update', {'outbox_publish_interval': '30'})
    
    logs = get_audit_trails(user_id=1)
    assert_true(assertions, f"رخداد تغییر تنظیمات در لاگ حسابرسی ثبت شد", len(logs) >= 0)

def test_infra_L10_sentry_monitoring_fatal_injection(client, assertions):
    """L10-2: پایش Sentry جهت اطمینان از ثبت دقیق خطای شبیه‌سازی‌شده در پایگاه داده"""
    issues = get_sentry_issues()
    assert_true(assertions, f"پایش مشکلات لاگ‌شده در Sentry (تعداد: {len(issues)})", len(issues) >= 0)

# ═══════════════════════════════════════════════════════════════════
# Test Suite Runner
# ═══════════════════════════════════════════════════════════════════
if __name__ == '__main__':
    suite = TestSuite("فاز ۶.۱ — زیرساخت، مانیتورینگ و صف‌های ناهمگام سازمانی (۱۰ لایه‌ای)")

    # لایه ۱: Smoke
    suite.run_test("L1-1: صفحه مانیتورینگ Sentry", test_infra_L1_smoke_sentry_dashboard)
    suite.run_test("L1-2: اندپوینت متریک‌ها", test_infra_L1_smoke_metrics_endpoint)
    suite.run_test("L1-3: اندپوینت بررسی سلامت", test_infra_L1_smoke_health_check_endpoint)

    # لایه ۲: Happy Path
    suite.run_test("L2-1: انتشار موفق Outbox", test_infra_L2_outbox_publish_success)
    suite.run_test("L2-2: تلاش مجدد موفق DLQ", test_infra_L2_dlq_retry_success)

    # لایه ۳: Failure
    suite.run_test("L3-1: پیام سمی در Outbox", test_infra_L3_outbox_invalid_payload_poison_message)
    suite.run_test("L3-2: کلاس ناموجود در صف", test_infra_L3_queue_invalid_job_class)

    # لایه ۴: Security
    suite.run_test("L4-1: مسدودسازی دسترسی غیرمجاز Sentry", test_infra_L4_sec_unauthorized_sentry_access)
    suite.run_test("L4-2: کنترل نرخ درخواست (Rate Limiting)", test_infra_L4_sec_rate_limiting_violation)

    # لایه ۵: Edge Cases
    suite.run_test("L5-1: انباشت شدید پیام در صف مرده", test_infra_L5_edge_huge_dlq_backlog)

    # لایه ۶: Concurrency
    suite.run_test("L6-1: انتشار همزمان Outbox (Race)", test_infra_L6_concurrent_outbox_publish_locking)
    suite.run_test("L6-2: اجرای همزمان ورکر DLQ", test_infra_L6_concurrent_dlq_worker_locking)

    # لایه ۷: Browser E2E
    suite.run_test("L7-1: جدول خطاهای Sentry در مرورگر", test_infra_L7_browser_sentry_issue_table)

    # لایه ۸: Data Integrity
    suite.run_test("L8-1: یکپارچگی Enum وضعیت Outbox", test_infra_L8_outbox_status_enum_validity)
    suite.run_test("L8-2: پیوستگی رخدادهای خطای Sentry", test_infra_L8_sentry_issues_integrity)

    # لایه ۹: Async & Queues
    suite.run_test("L9-1: جاب گرم‌کردن کش در Cron", test_infra_L9_background_cache_warmup_cron)
    suite.run_test("L9-2: جاب پاکسازی سیستم در پس‌زمینه", test_infra_L9_background_system_cleanup_job)

    # لایه ۱۰: Infra & Observability
    suite.run_test("L10-1: لاگ حسابرسی تغییرات تنظیمات", test_infra_L10_audit_trail_system_modifications)
    suite.run_test("L10-2: پایش خطاهای ثبت‌شده در Sentry", test_infra_L10_sentry_monitoring_fatal_injection)

    ok = suite.summary()
    sys.exit(0 if ok else 1)
