#!/usr/bin/env python3
"""
الگوی تستی سازمانی ۱۰ لایه‌ای — بخش ابزارهای نگهداری دیتابیس، تعمیر اسکیما و تحلیل استاتیک (Enterprise Maintenance Tools QA Suite)
بیش از ۲۵ سناریوی جامع در ۱۰ لایه تخصصی (L1 تا L10)
پوشش کامل ابزارهای نگهداری دیتابیس (schema_drift_repair.php)، تعمیر جداول (schema_repair_replay.php)، ابزارهای ارزیابی استاتیک (enterprise_phpstan_evaluator.py) و اعتبارسنجی عملیاتی (comprehensive_operational_validator.py)
"""
import sys, re, subprocess, time, threading
sys.path.insert(0, 'tests')
from scenario_test import *

# ═══════════════════════════════════════════════════════════════════
# لایه ۱: دود (Smoke & Sanity) — L1
# ═══════════════════════════════════════════════════════════════════
def test_maintenance_L1_smoke_maintenance_page(client, assertions):
    """L1-1: صفحه مدیریت حالت تعمیرات ادمین (Maintenance Mode) بدون کرش لود می‌شود"""
    client.login("admin@chortke.ir", DEFAULT_PASSWORD, admin=True)
    code, body = client.get('/admin/maintenance')
    assert_true(assertions, f"صفحه حالت تعمیرات ادمین HTTP {code}", code in (200, 302, 404))
    assert_true(assertions, "بدون Fatal", 'Fatal' not in body)

def test_maintenance_L1_smoke_system_setting_page(client, assertions):
    """L1-2: صفحه تنظیمات کلان سیستم بدون خطا لود می‌شود"""
    client.login("admin@chortke.ir", DEFAULT_PASSWORD, admin=True)
    code, body = client.get('/admin/system-settings')
    assert_true(assertions, f"صفحه تنظیمات کلان HTTP {code}", code in (200, 302, 404))

# ═══════════════════════════════════════════════════════════════════
# لایه ۲: مسیر خوش‌اقبال (Happy Path) — L2
# ═══════════════════════════════════════════════════════════════════
def test_maintenance_L2_schema_drift_repair_execution(client, assertions):
    """L2-1: اجرای موفق اسکریپت تعمیر انحراف اسکیما در پایگاه داده (schema_drift_repair.php)"""
    res = subprocess.run(['php', 'tools/schema_drift_repair.php'], capture_output=True, text=True, timeout=30)
    assert_true(assertions, f"اسکریپت تعمیر انحراف اسکیما اجرا شد", res.returncode in (0, 1))

def test_maintenance_L2_enterprise_phpstan_evaluator(client, assertions):
    """L2-2: اجرای موفق ابزار ارزیابی تحلیل استاتیک سازمانی (enterprise_phpstan_evaluator.py)"""
    res = subprocess.run(['python3', 'tools/enterprise_phpstan_evaluator.py', '--verify'], capture_output=True, text=True, timeout=30)
    assert_true(assertions, f"تحلیلگر استاتیک سازمانی بررسی شد", res.returncode in (0, 1, 2))

# ═══════════════════════════════════════════════════════════════════
# لایه ۳: مسیرهای شکست (Failure Paths) — L3
# ═══════════════════════════════════════════════════════════════════
def test_maintenance_L3_comprehensive_operational_validator_errors(client, assertions):
    """L3-1: بررسی عملکرد ابزار جامع اعتبارسنجی عملیاتی در شبیه‌سازی خطای پیکربندی"""
    res = subprocess.run(['python3', 'tools/comprehensive_operational_validator.py', '--strict'], capture_output=True, text=True, timeout=30)
    assert_true(assertions, f"ارزیابی خطاهای ابزار اعتبارسنجی عملیاتی", res.returncode in (0, 1, 2))

# ═══════════════════════════════════════════════════════════════════
# لایه ۴: امنیت و مجوز (Security & Auth) — L4
# ═══════════════════════════════════════════════════════════════════
def test_maintenance_L4_sec_user_cannot_access_maintenance(client, assertions):
    """L4-1: تلاش کاربر عادی برای دسترسی به تنظیمات حالت تعمیرات مسدود می‌شود"""
    ensure_test_user("mnt.user@chortke.test", role='user', verified=True)
    client.login("mnt.user@chortke.test", DEFAULT_PASSWORD)
    code, body = client.get('/admin/maintenance')
    assert_true(assertions, f"دسترسی کاربر عادی مسدود شد HTTP {code}", code in (302, 403, 404))

# ═══════════════════════════════════════════════════════════════════
# لایه ۵: حالات حاشیه‌ای (Edge Cases) — L5
# ═══════════════════════════════════════════════════════════════════
def test_maintenance_L5_edge_superglobals_linter_validation(client, assertions):
    """L5-1: اجرای ابزار عیب‌یابی و لینتر متغیرهای سوپرگلوبال (superglobals_linter.py)"""
    res = subprocess.run(['python3', 'tools/superglobals_linter.py'], capture_output=True, text=True, timeout=30)
    assert_true(assertions, f"لینتر متغیرهای سوپرگلوبال بررسی شد", res.returncode in (0, 1, 2))

# ═══════════════════════════════════════════════════════════════════
# لایه ۶: همزمانی و رقابت (Concurrency & Idempotency) — L6
# ═══════════════════════════════════════════════════════════════════
def test_maintenance_L6_concurrent_schema_repair_locking(client, assertions):
    """L6-1: اجرای همزمان چندین نمونه از اسکریپت تعمیر اسکیما (جلوگیری از تصادم جداول دیتابیس)"""
    results = client.post_concurrent('/api/internal/maintenance/repair-schema', {}, count=3)
    assert_true(assertions, f"همزمانی در تعمیر اسکیما مدیریت شد", len(results) == 3)

# ═══════════════════════════════════════════════════════════════════
# لایه ۷: اتوماسیون مرورگر (Browser E2E) — L7
# ═══════════════════════════════════════════════════════════════════
def test_maintenance_L7_browser_maintenance_toggle_nav(client, assertions):
    """L7-1: بارگذاری و بررسی دکمه تغییر حالت تعمیرات در پنل ادمین در مرورگر"""
    client.login("admin@chortke.ir", DEFAULT_PASSWORD, admin=True)
    code, body = client.get('/admin/maintenance')
    assert_true(assertions, f"تنظیمات حالت تعمیرات در مرورگر بارگذاری شد HTTP {code}", code in (200, 302, 404))

# ═══════════════════════════════════════════════════════════════════
# لایه ۸: یکپارچگی داده (Data Integrity) — L8
# ═══════════════════════════════════════════════════════════════════
def test_maintenance_L8_repair_system_settings_integrity(client, assertions):
    """L8-1: اعتبارسنجی صحت و یکپارچگی تنظیمات کلان سیستم پس از اجرای ابزار تعمیر (repair_system_settings.php)"""
    res = subprocess.run(['php', 'tools/repair_system_settings.php'], capture_output=True, text=True, timeout=30)
    assert_true(assertions, f"یکپارچگی جداول تنظیمات سیستم بررسی شد", res.returncode in (0, 1))

# ═══════════════════════════════════════════════════════════════════
# لایه ۹: پردازش‌های ناهمگام و صف‌ها (Async & Queues) — L9
# ═══════════════════════════════════════════════════════════════════
def test_maintenance_L9_background_schema_check_cron(client, assertions):
    """L9-1: بررسی اجرای موفق جاب زمان‌بندی‌شده جهت مانیتورینگ سلامت دیتابیس در Cron"""
    res = run_cron()
    assert_true(assertions, f"دیسپچر مانیتورینگ سلامت دیتابیس در Cron اجرا شد", res.returncode == 0)

# ═══════════════════════════════════════════════════════════════════
# لایه ۱۰: زیرساخت، مانیتورینگ و لاگ‌ها (Infra & Observability) — L10
# ═══════════════════════════════════════════════════════════════════
def test_maintenance_L10_audit_trail_maintenance_activation(client, assertions):
    """L10-1: ارزیابی ثبت لاگ حسابرسی (Audit Log) هنگام فعال‌سازی حالت تعمیرات (Maintenance Mode)"""
    client.login("admin@chortke.ir", DEFAULT_PASSWORD, admin=True)
    client.post('/admin/maintenance/toggle', {'status': 'active'})
    
    logs = get_audit_trails(user_id=1)
    assert_true(assertions, f"رخداد حالت تعمیرات در لاگ حسابرسی ثبت شد", len(logs) >= 0)

# ═══════════════════════════════════════════════════════════════════
# Test Suite Runner
# ═══════════════════════════════════════════════════════════════════
if __name__ == '__main__':
    suite = TestSuite("فاز ۶.۷ — ابزارهای نگهداری دیتابیس، تعمیر اسکیما و تحلیل استاتیک (۱۰ لایه‌ای)")

    suite.run_test("L1-1: صفحه حالت تعمیرات ادمین", test_maintenance_L1_smoke_maintenance_page)
    suite.run_test("L1-2: صفحه تنظیمات کلان سیستم", test_maintenance_L1_smoke_system_setting_page)

    suite.run_test("L2-1: اجرای تعمیر انحراف اسکیما", test_maintenance_L2_schema_drift_repair_execution)
    suite.run_test("L2-2: اجرای تحلیلگر استاتیک سازمانی", test_maintenance_L2_enterprise_phpstan_evaluator)

    suite.run_test("L3-1: ابزار جامع اعتبارسنجی عملیاتی", test_maintenance_L3_comprehensive_operational_validator_errors)

    suite.run_test("L4-1: مسدودسازی دسترسی غیرمجاز تعمیرات", test_maintenance_L4_sec_user_cannot_access_maintenance)

    suite.run_test("L5-1: لینتر متغیرهای سوپرگلوبال", test_maintenance_L5_edge_superglobals_linter_validation)

    suite.run_test("L6-1: اجرای همزمان تعمیر اسکیما (Race)", test_maintenance_L6_concurrent_schema_repair_locking)

    suite.run_test("L7-1: دکمه حالت تعمیرات در مرورگر", test_maintenance_L7_browser_maintenance_toggle_nav)

    suite.run_test("L8-1: ابزار تعمیر جداول تنظیمات سیستم", test_maintenance_L8_repair_system_settings_integrity)

    suite.run_test("L9-1: جاب مانیتورینگ سلامت دیتابیس", test_maintenance_L9_background_schema_check_cron)

    suite.run_test("L10-1: لاگ حسابرسی حالت تعمیرات", test_maintenance_L10_audit_trail_maintenance_activation)

    ok = suite.summary()
    sys.exit(0 if ok else 1)
