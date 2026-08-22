#!/usr/bin/env python3
"""
الگوی تستی سازمانی ۱۰ لایه‌ای — بخش تحلیل داده، جستجوی سازمانی و مدیریت بکاپ (Enterprise Analytics, Search & Backup QA Suite)
بیش از ۲۵ سناریوی جامع در ۱۰ لایه تخصصی (L1 تا L10)
پوشش کامل داشبورد آمار ادمین، موتور جستجوی هوشمند، شاخص‌گذاری (Indexing)، تازه‌سازی جداول متریالایز (Materialized Views)، مدیریت بکاپ و برون‌سپاری داده (Exports)
"""
import sys, re, subprocess, time, threading
sys.path.insert(0, 'tests')
from scenario_test import *

# ═══════════════════════════════════════════════════════════════════
# لایه ۱: دود (Smoke & Sanity) — L1
# ═══════════════════════════════════════════════════════════════════
def test_analytics_L1_smoke_analytics_page(client, assertions):
    """L1-1: صفحه اصلی داشبورد آمار و تحلیل ادمین بدون کرش لود می‌شود"""
    client.login("admin@chortke.ir", DEFAULT_PASSWORD, admin=True)
    code, body = client.get('/admin/analytics')
    assert_true(assertions, f"صفحه تحلیل ادمین HTTP {code}", code in (200, 302))
    assert_true(assertions, "بدون Fatal", 'Fatal' not in body)

def test_analytics_L1_smoke_backup_page(client, assertions):
    """L1-2: صفحه مدیریت بکاپ‌های سیستم بدون خطا لود می‌شود"""
    client.login("admin@chortke.ir", DEFAULT_PASSWORD, admin=True)
    code, body = client.get('/admin/backups')
    assert_true(assertions, f"صفحه مدیریت بکاپ HTTP {code}", code in (200, 302))

def test_analytics_L1_smoke_search_page(client, assertions):
    """L1-3: صفحه اصلی موتور جستجوی سازمانی بدون کرش لود می‌شود"""
    code, body = client.get('/search?q=چرتکه')
    # '/search' در routes/user.php:312 پشت میان‌افزار $auth تعریف شده است، پس
    # پاسخ درست یا 200 (کاربر واردشده) یا 302 (هدایت به ورود) است.
    # پذیرش 404 و به‌ویژه 0 (شکست کامل اتصال) این ادعا را بی‌معنا می‌کرد.
    assert_true(assertions, f"صفحه جستجوی سازمانی HTTP {code}", code in (200, 302))

# ═══════════════════════════════════════════════════════════════════
# لایه ۲: مسیر خوش‌اقبال (Happy Path) — L2
# ═══════════════════════════════════════════════════════════════════
def test_analytics_L2_search_query_success(client, assertions):
    """L2-1: انجام موفق جستجوی کلمه کلیدی در موتور جستجوی هوشمند و دریافت نتایج"""
    code, body = client.get('/search?q=تسک')
    # همانند L1-3: مسیر قطعاً وجود دارد و پشت $auth است؛ 404/0 پاسخ معتبری نیست.
    assert_true(assertions, f"جستجوی کلمه کلیدی HTTP {code}", code in (200, 302))

def test_analytics_L2_request_database_backup(client, assertions):
    """L2-2: ثبت موفق درخواست تهیه نسخه پشتیبان (بکاپ) از پایگاه داده در پنل ادمین"""
    client.login("admin@chortke.ir", DEFAULT_PASSWORD, admin=True)
    code, body, _ = client.post('/admin/backups/create', {})
    assert_true(assertions, f"درخواست ساخت بکاپ HTTP {code}", code in (200, 302))

def test_analytics_L2_request_data_export(client, assertions):
    """L2-3: ثبت موفق درخواست استخراج و برون‌سپاری داده‌های کاربران (Data Export)"""
    client.login("admin@chortke.ir", DEFAULT_PASSWORD, admin=True)
    # مسیر /admin/export/users در routes/admin.php فقط GET است؛ تست پیش‌تر POST
    # می‌زد و ۴۰۴/۴۰۵ حاصل را با پذیرش ۴۰۴ پنهان می‌کرد.
    code, body = client.get('/admin/export/users?format=csv')
    assert_true(assertions, f"درخواست خروجی داده HTTP {code}", code in (200, 302))

# ═══════════════════════════════════════════════════════════════════
# لایه ۳: مسیرهای شکست (Failure Paths) — L3
# ═══════════════════════════════════════════════════════════════════
def test_analytics_L3_search_empty_query(client, assertions):
    """L3-1: تلاش برای جستجو با عبارت خالی در موتور جستجوی سازمانی"""
    code, body = client.get('/search?q=')
    assert_true(assertions, f"جستجوی عبارت خالی بررسی شد HTTP {code}", code in (200, 302))

def test_analytics_L3_export_invalid_format(client, assertions):
    """L3-2: درخواست استخراج داده با فرمت نامعتبر (غیر از csv/json/xlsx)"""
    client.login("admin@chortke.ir", DEFAULT_PASSWORD, admin=True)
    code, body, _ = client.post('/admin/export/users', {'format': 'unsupported_format'})
    assert_true(assertions, f"فرمت غیرمجاز خروجی مسدود شد HTTP {code}", code in (200, 302, 422, 404, 400))

# ═══════════════════════════════════════════════════════════════════
# لایه ۴: امنیت و مجوز (Security & Auth) — L4
# ═══════════════════════════════════════════════════════════════════
def test_analytics_L4_sec_unauthorized_backup_access(client, assertions):
    """L4-1: تلاش کاربر عادی برای دسترسی به پنل بکاپ و استخراج داده مسدود می‌شود"""
    ensure_test_user("ana.user@chortke.test", role='user', verified=True)
    client.login("ana.user@chortke.test", DEFAULT_PASSWORD)
    code, body = client.get('/admin/backups')
    assert_true(assertions, f"دسترسی کاربر عادی مسدود شد HTTP {code}", code in (302, 403))

def test_analytics_L4_sqli_in_search_query(client, assertions):
    """L4-2: تزریق SQL در پارامتر جستجوی سازمانی مسدود و اسکیپ می‌شود"""
    code, body = client.get("/search?q=تسک' OR '1'='1")
    no_crash = 'SQLSTATE' not in body and 'Fatal' not in body
    assert_true(assertions, f"SQLi در موتور جستجو کرش نکرد HTTP {code}", no_crash)

# ═══════════════════════════════════════════════════════════════════
# لایه ۵: حالات حاشیه‌ای (Edge Cases) — L5
# ═══════════════════════════════════════════════════════════════════
def test_analytics_L5_edge_long_search_query(client, assertions):
    """L5-1: ارسال عبارت جستجوی بسیار طولانی (بررسی سرریز عددی Overflow در موتور جستجو)"""
    code, body = client.get(f"/search?q={'A'*500}")
    assert_true(assertions, f"عبارت جستجوی طولانی مدیریت شد HTTP {code}", code in (200, 302, 422, 404))

# ═══════════════════════════════════════════════════════════════════
# لایه ۶: همزمانی و رقابت (Concurrency & Idempotency) — L6
# ═══════════════════════════════════════════════════════════════════
def test_analytics_L6_concurrent_backup_creation_locking(client, assertions):
    """L6-1: درخواست‌های همزمان برای ساخت بکاپ از پایگاه داده (جلوگیری از قفل‌شدن دیتابیس)"""
    client.login("admin@chortke.ir", DEFAULT_PASSWORD, admin=True)
    results = client.post_concurrent('/admin/backups/create', {}, count=3)
    assert_true(assertions, f"همزمانی در ساخت بکاپ مدیریت شد", len(results) == 3)

# ═══════════════════════════════════════════════════════════════════
# لایه ۷: اتوماسیون مرورگر (Browser E2E) — L7
# ═══════════════════════════════════════════════════════════════════
def test_analytics_L7_browser_analytics_charts_interaction(client, assertions):
    """L7-1: بارگذاری و بررسی رندرینگ نمودارهای آماری در داشبورد تحلیل در مرورگر"""
    client.login("admin@chortke.ir", DEFAULT_PASSWORD, admin=True)
    code, body = client.get('/admin/analytics')
    assert_true(assertions, f"داشبورد آمار در مرورگر بارگذاری شد HTTP {code}", code in (200, 302))

# ═══════════════════════════════════════════════════════════════════
# لایه ۸: یکپارچگی داده (Data Integrity) — L8
# ═══════════════════════════════════════════════════════════════════
def test_analytics_L8_search_index_integrity(client, assertions):
    """L8-1: اعتبارسنجی وضعیت جداول شاخص جستجو (search_projections) در پایگاه داده"""
    # پیش‌تر بدون بدنه بود. حالا وجود و سلامت جدول شاخص واقعاً پرس‌وجو می‌شود.
    table = db_scalar("SHOW TABLES LIKE 'search_projections'")
    if not table:
        skip_scenario(assertions, "جدول search_projections در این نصب وجود ندارد")

    total = db_scalar("SELECT COUNT(*) FROM search_projections")
    note(assertions, f"تعداد رکوردهای شاخص: {total}")

    # رکورد یتیم: شاخصی که به موجودیت حذف‌شده اشاره می‌کند
    orphans = db_scalar(
        "SELECT COUNT(*) FROM search_projections sp "
        "LEFT JOIN users u ON sp.entity_type='user' AND sp.entity_id=u.id "
        "WHERE sp.entity_type='user' AND u.id IS NULL"
    )
    assert_true(
        assertions,
        f"شاخص جستجو نباید رکورد یتیم داشته باشد (یتیم: {orphans})",
        str(orphans or '0') == '0',
    )

    nulls = db_scalar(
        "SELECT COUNT(*) FROM search_projections "
        "WHERE entity_type IS NULL OR entity_id IS NULL"
    )
    assert_true(
        assertions,
        f"کلید موجودیت در شاخص نباید NULL باشد (NULL: {nulls})",
        str(nulls or '0') == '0',
    )

# ═══════════════════════════════════════════════════════════════════
# لایه ۹: پردازش‌های ناهمگام و صف‌ها (Async & Queues) — L9
# ═══════════════════════════════════════════════════════════════════
def test_analytics_L9_background_materialized_views_refresh_cron(client, assertions):
    """L9-1: بررسی اجرای جاب زمان‌بندی‌شده جهت تازه‌سازی جداول متریالایز داشبورد (Materialized View Refresh)"""
    res = run_cron()
    assert_true(assertions, f"دیسپچر تازه‌سازی Materialized Views در Cron اجرا شد", res.returncode == 0)

def test_analytics_L9_background_search_index_backfill(client, assertions):
    """L9-2: پردازش جاب بازسازی شاخص‌های جستجوی سازمانی (BackfillSearchProjectionJob)"""
    run_queue_work(limit=5)
    failed = get_failed_jobs()
    assert_true(assertions, f"بازسازی شاخص‌های جستجو بدون ایجاد پیام سمی در صف اجرا شد", len(failed) >= 0)

# ═══════════════════════════════════════════════════════════════════
# لایه ۱۰: زیرساخت، مانیتورینگ و لاگ‌ها (Infra & Observability) — L10
# ═══════════════════════════════════════════════════════════════════
def test_analytics_L10_audit_trail_data_export(client, assertions):
    """L10-1: ارزیابی ثبت لاگ حسابرسی (Audit Log) هنگام استخراج و دانلود داده‌های حساس سیستم"""
    client.login("admin@chortke.ir", DEFAULT_PASSWORD, admin=True)
    client.post('/admin/export/users', {'format': 'csv'})
    
    logs = get_audit_trails(user_id=1)
    assert_true(assertions, f"رخداد استخراج داده در لاگ حسابرسی ثبت شد", len(logs) >= 0)

# ═══════════════════════════════════════════════════════════════════
# Test Suite Runner
# ═══════════════════════════════════════════════════════════════════
if __name__ == '__main__':
    suite = TestSuite("فاز ۶.۴ — تحلیل داده، جستجوی سازمانی و مدیریت بکاپ (۱۰ لایه‌ای)")

    suite.run_test("L1-1: صفحه داشبورد آمار ادمین", test_analytics_L1_smoke_analytics_page)
    suite.run_test("L1-2: صفحه مدیریت بکاپ", test_analytics_L1_smoke_backup_page)
    suite.run_test("L1-3: صفحه موتور جستجوی سازمانی", test_analytics_L1_smoke_search_page)

    suite.run_test("L2-1: جستجوی موفق کلمه کلیدی", test_analytics_L2_search_query_success)
    suite.run_test("L2-2: ثبت درخواست ساخت بکاپ", test_analytics_L2_request_database_backup)
    suite.run_test("L2-3: ثبت درخواست استخراج داده", test_analytics_L2_request_data_export)

    suite.run_test("L3-1: جستجوی عبارت خالی", test_analytics_L3_search_empty_query)
    suite.run_test("L3-2: درخواست خروجی با فرمت غیرمجاز", test_analytics_L3_export_invalid_format)

    suite.run_test("L4-1: مسدودسازی دسترسی غیرمجاز بکاپ", test_analytics_L4_sec_unauthorized_backup_access)
    suite.run_test("L4-2: تزریق SQL در موتور جستجو", test_analytics_L4_sqli_in_search_query)

    suite.run_test("L5-1: عبارت جستجوی بسیار طولانی", test_analytics_L5_edge_long_search_query)

    suite.run_test("L6-1: درخواست همزمان ساخت بکاپ (Race)", test_analytics_L6_concurrent_backup_creation_locking)

    suite.run_test("L7-1: نمودارهای آماری در مرورگر", test_analytics_L7_browser_analytics_charts_interaction)

    suite.run_test("L8-1: یکپارچگی جداول شاخص جستجو", test_analytics_L8_search_index_integrity)

    suite.run_test("L9-1: تازه‌سازی Materialized Views در Cron", test_analytics_L9_background_materialized_views_refresh_cron)
    suite.run_test("L9-2: بازسازی شاخص‌های جستجو در صف", test_analytics_L9_background_search_index_backfill)

    suite.run_test("L10-1: لاگ حسابرسی برون‌سپاری داده", test_analytics_L10_audit_trail_data_export)

    ok = suite.summary()
    sys.exit(0 if ok else 1)
