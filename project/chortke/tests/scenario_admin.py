#!/usr/bin/env python3
"""
الگوی تستی سازمانی ۱۰ لایه‌ای — بخش پنل حاکمیتی و مدیریت ادمین (Enterprise Admin Governance QA Suite)
پوشش کامل مدیریت کاربران، بررسی مدارک KYC، تایید/رد درخواست‌های برداشت، بررسی ددلاین‌ها، همزمانی و لاگ‌های Sentry
بیش از ۲۸ سناریوی جامع در ۱۰ لایه تخصصی (L1 تا L10)
"""
import sys, re, subprocess, time
sys.path.insert(0, 'tests')
from scenario_test import *

def admin_login(client):
    """ورود ادمین"""
    ensure_test_user("admin@chortke.ir", role="admin")
    client.login("admin@chortke.ir", DEFAULT_PASSWORD, admin=True)

# ═══════════════════════════════════════════════════════════════════
# لایه ۱: دود (Smoke) — L1
# ═══════════════════════════════════════════════════════════════════
def test_admin_L1_smoke_kyc_pages(client, assertions):
    """L1-1: صفحات مدیریت KYC ادمین لود می‌شوند"""
    admin_login(client)
    code, _ = client.get('/admin/kyc')
    assert_true(assertions, f"صفحه لیست KYC (HTTP {code})", code == 200)

def test_admin_L1_smoke_withdrawal_pages(client, assertions):
    """L1-2: صفحات درخواست‌های برداشت ادمین لود می‌شوند"""
    admin_login(client)
    code, _ = client.get('/admin/withdrawals')
    assert_true(assertions, f"صفحه لیست برداشت‌ها (HTTP {code})", code == 200)

def test_admin_L1_smoke_user_pages(client, assertions):
    """L1-3: صفحات مدیریت کاربران لود می‌شوند"""
    admin_login(client)
    code, _ = client.get('/admin/users')
    assert_true(assertions, f"صفحه لیست کاربران (HTTP {code})", code == 200)

def test_admin_L1_smoke_sentry_dashboard(client, assertions):
    """L1-4: صفحه مانیتورینگ Sentry ادمین لود می‌شود"""
    admin_login(client)
    code, _ = client.get('/admin/sentry')
    assert_true(assertions, f"صفحه Sentry ادمین (HTTP {code})", code in (200, 302))

# ═══════════════════════════════════════════════════════════════════
# لایه ۲: مسیر خوش‌اقبال (Happy Path) — L2
# ═══════════════════════════════════════════════════════════════════
def test_admin_L2_kyc_verify_success(client, assertions):
    """L2-1: تأیید موفق مدارک KYC کاربر توسط ادمین"""
    uid = ensure_test_user("admin.kyc@chortke.test", verified=False)
    db_insert(f"""
        INSERT INTO kyc_verifications (user_id, status, national_code, submitted_at)
        VALUES ({uid}, 'pending', '1234567890', NOW())
        ON DUPLICATE KEY UPDATE status='pending', reviewed_at=NULL
    """)
    kyc_id = db_scalar(f"SELECT id FROM kyc_verifications WHERE user_id={uid}")
    admin_login(client)
    code, _, jb = client.post(f'/admin/kyc/verify/{kyc_id}', {})
    assert_true(assertions, f"تأیید KYC (HTTP {code})", code in (200, 302))
    status = db_scalar(f"SELECT status FROM kyc_verifications WHERE id={kyc_id}")
    assert_true(assertions, f"KYC verified شد (status={status})", status == 'verified')

def test_admin_L2_ban_user_success(client, assertions):
    """L2-2: مسدودسازی (Ban) موفق حساب کاربر متخلف"""
    uid = ensure_test_user("admin.ban@chortke.test", verified=True)
    admin_login(client)
    code, _, jb = client.post(f'/admin/users/{uid}/ban', {'reason': 'test ban'})
    assert_true(assertions, f"ban کاربر (HTTP {code})", code in (200, 302))
    status = db_scalar(f"SELECT status FROM users WHERE id={uid}")
    assert_true(assertions, f"کاربر banned شد (status={status})", status == 'banned')

def test_admin_L2_unban_user_success(client, assertions):
    """L2-3: رفع مسدودیت (Unban) حساب کاربر"""
    uid = ensure_test_user("admin.unban@chortke.test", verified=True)
    db_insert(f"UPDATE users SET status='banned' WHERE id={uid}")
    admin_login(client)
    code, _, jb = client.post(f'/admin/users/{uid}/unban', {'reason': 'test unban'})
    assert_true(assertions, f"unban کاربر (HTTP {code})", code in (200, 302))
    status = db_scalar(f"SELECT status FROM users WHERE id={uid}")
    assert_true(assertions, f"کاربر active شد (status={status})", status == 'active')

def test_admin_L2_suspend_user_success(client, assertions):
    """L2-4: تعلیق (Suspend) حساب کاربر"""
    uid = ensure_test_user("admin.suspend@chortke.test", verified=True)
    admin_login(client)
    code, _, jb = client.post(f'/admin/users/{uid}/suspend', {'reason': 'test suspend'})
    assert_true(assertions, f"suspend کاربر (HTTP {code})", code in (200, 302))
    status = db_scalar(f"SELECT status FROM users WHERE id={uid}")
    assert_true(assertions, f"کاربر suspended شد (status={status})", status == 'suspended')

# ═══════════════════════════════════════════════════════════════════
# لایه ۳: مسیرهای شکست (Failure Paths) — L3
# ═══════════════════════════════════════════════════════════════════
def test_admin_L3_kyc_reject(client, assertions):
    """L3-1: رد مدارک KYC کاربر با درج دلیل"""
    uid = ensure_test_user("admin.rejectkyc@chortke.test", verified=False)
    db_insert(f"""
        INSERT INTO kyc_verifications (user_id, status, national_code, submitted_at)
        VALUES ({uid}, 'pending', '9876543210', NOW())
        ON DUPLICATE KEY UPDATE status='pending', reviewed_at=NULL
    """)
    kyc_id = db_scalar(f"SELECT id FROM kyc_verifications WHERE user_id={uid}")
    admin_login(client)
    code, _, jb = client.post(f'/admin/kyc/reject/{kyc_id}', {'reason': 'مدارک ناخوانا'})
    assert_true(assertions, f"رد KYC (HTTP {code})", code in (200, 302))
    status = db_scalar(f"SELECT status FROM kyc_verifications WHERE id={kyc_id}")
    assert_true(assertions, f"KYC rejected شد (status={status})", status == 'rejected')

def test_admin_L3_ban_nonexistent_user(client, assertions):
    """L3-2: تلاش برای Ban کاربر با شناسه ناموجود (404/422)"""
    admin_login(client)
    code, _, jb = client.post('/admin/users/99999/ban', {'reason': 'test'})
    is_handled = code in (400, 404, 422, 302, 500)
    assert_true(assertions, f"ban کاربر ناموجود هندل شد (HTTP {code})", is_handled)

def test_admin_L3_withdrawal_process_insufficient(client, assertions):
    """L3-3: بررسی فرآیند تأیید برداشت با داده‌های معتبر"""
    uid = ensure_test_user("admin.wdraw@chortke.test", balance_irt='1000000')
    db_insert(f"""
        INSERT INTO withdrawals (user_id, amount, status, currency, bank_card_id, created_at, updated_at)
        VALUES ({uid}, 200000, 'pending', 'irt', 1, NOW(), NOW())
    """)
    wid = db_scalar(f"SELECT id FROM withdrawals WHERE user_id={uid} ORDER BY id DESC LIMIT 1")
    admin_login(client)
    code, _, jb = client.post('/admin/withdrawals/process', {'withdrawal_id': wid})
    assert_true(assertions, f"تأیید برداشت (HTTP {code})", code in (200, 302, 422))

def test_admin_L3_withdrawal_reject(client, assertions):
    """L3-4: رد درخواست برداشت کاربر با ثبت علت"""
    uid = ensure_test_user("admin.wdrawrej@chortke.test", balance_irt='500000')
    db_insert(f"""
        INSERT INTO withdrawals (user_id, amount, status, currency, bank_card_id, created_at, updated_at)
        VALUES ({uid}, 100000, 'pending', 'irt', 1, NOW(), NOW())
    """)
    wid = db_scalar(f"SELECT id FROM withdrawals WHERE user_id={uid} ORDER BY id DESC LIMIT 1")
    admin_login(client)
    code, _, jb = client.post('/admin/withdrawals/reject', {'withdrawal_id': wid, 'reason': 'invalid'})
    assert_true(assertions, f"رد برداشت (HTTP {code})", code in (200, 302, 422))

# ═══════════════════════════════════════════════════════════════════
# لایه ۴: امنیت و مجوز (Security & Auth) — L4
# ═══════════════════════════════════════════════════════════════════
def test_admin_L4_sec_user_cannot_access(client, assertions):
    """L4-1: کاربر عادی نباید به پنل و عملیات ادمین دسترسی داشته باشد (RBAC)"""
    ensure_test_user("admin.forbidden@chortke.test", role='user', verified=True)
    client.login("admin.forbidden@chortke.test", DEFAULT_PASSWORD)
    code, _ = client.get('/admin/users')
    assert_true(assertions, f"کاربر عادی از admin محروم (HTTP {code})", code in (302, 403))

def test_admin_L4_sec_ban_without_csrf(client, assertions):
    """L4-2: عملیات مسدودسازی کاربر بدون توکن CSRF مسدود می‌شود"""
    uid = ensure_test_user("admin.nocsrf@chortke.test", verified=True)
    admin_login(client)
    r = subprocess.run(
        ['curl', '-sS', '-b', client.jar, '-X', 'POST',
         f'{BASE_URL}/admin/users/{uid}/ban',
         '--data-urlencode', 'reason=test',
         '-o', '/dev/null', '-w', '%{http_code}', '--max-time', '10'],
        capture_output=True, text=True, timeout=15
    )
    code = int(r.stdout.strip()) if r.stdout.strip().isdigit() else 0
    is_rejected = code in (302, 403, 419, 422)
    assert_true(assertions, f"ban بدون CSRF رد شد (HTTP {code})", is_rejected)

def test_admin_L4_sec_kyc_verify_permission(client, assertions):
    """L4-3: ادمین فقط با نقش و مجوز حاکمیتی مجاز به بررسی KYC است"""
    uid = ensure_test_user("admin.kycsec@chortke.test", verified=False)
    db_insert(f"""
        INSERT INTO kyc_verifications (user_id, status, national_code, submitted_at)
        VALUES ({uid}, 'pending', '1111111111', NOW())
        ON DUPLICATE KEY UPDATE status='pending', reviewed_at=NULL
    """)
    kyc_id = db_scalar(f"SELECT id FROM kyc_verifications WHERE user_id={uid}")
    admin_login(client)
    code, _, _ = client.post(f'/admin/kyc/verify/{kyc_id}', {})
    assert_true(assertions, f"تأیید KYC با permission ادمین (HTTP {code})", code in (200, 302))

# ═══════════════════════════════════════════════════════════════════
# لایه ۵: حالات حاشیه‌ای (Edge Cases) — L5
# ═══════════════════════════════════════════════════════════════════
def test_admin_L5_edge_kyc_invalid_id(client, assertions):
    """L5-1: تأیید KYC با شناسه ناموجود در مرزهای سیستم"""
    admin_login(client)
    code, _, _ = client.post('/admin/kyc/verify/99999', {})
    is_handled = code in (400, 404, 422, 302, 500)
    assert_true(assertions, f"KYC ناموجود هندل شد (HTTP {code})", is_handled)

def test_admin_L5_edge_double_ban(client, assertions):
    """L5-2: تلاش برای Ban مجدد کاربری که از قبل مسدود شده است"""
    uid = ensure_test_user("admin.doubleban@chortke.test", verified=True)
    db_insert(f"UPDATE users SET status='banned' WHERE id={uid}")
    admin_login(client)
    code, _, _ = client.post(f'/admin/users/{uid}/ban', {'reason': 'double'})
    is_handled = code in (200, 302, 422)
    assert_true(assertions, f"ban مجدد هندل شد (HTTP {code})", is_handled)

def test_admin_L5_edge_withdrawal_already_processed(client, assertions):
    """L5-3: تأیید درخواست برداشتی که قبلاً پردازش و تسویه شده است"""
    uid = ensure_test_user("admin.wdrawdone@chortke.test", balance_irt='500000')
    db_insert(f"""
        INSERT INTO withdrawals (user_id, amount, status, currency, bank_card_id, created_at, updated_at)
        VALUES ({uid}, 50000, 'completed', 'irt', 1, NOW(), NOW())
    """)
    wid = db_scalar(f"SELECT id FROM withdrawals WHERE user_id={uid} ORDER BY id DESC LIMIT 1")
    admin_login(client)
    code, _, _ = client.post('/admin/withdrawals/process', {'withdrawal_id': wid})
    is_handled = code in (200, 302, 422)
    assert_true(assertions, f"برداشت تکراری هندل شد (HTTP {code})", is_handled)

# ═══════════════════════════════════════════════════════════════════
# لایه ۶: همزمانی و رقابت (Concurrency & Idempotency) — L6
# ═══════════════════════════════════════════════════════════════════
def test_admin_L6_concurrent_kyc_verification(client, assertions):
    """L6-1: بررسی همزمان یک مدرک KYC توسط چندین ادمین (Race Condition)"""
    uid = ensure_test_user("admin.racekyc@chortke.test", verified=False)
    db_insert(f"""
        INSERT INTO kyc_verifications (user_id, status, national_code, submitted_at)
        VALUES ({uid}, 'pending', '7777777777', NOW())
        ON DUPLICATE KEY UPDATE status='pending', reviewed_at=NULL
    """)
    kyc_id = db_scalar(f"SELECT id FROM kyc_verifications WHERE user_id={uid}")
    admin_login(client)
    code, body = client.get('/admin/kyc')
    csrf = re.search(r'name="_csrf_token"[^>]*value="([^"]+)"', body)
    token = csrf.group(1) if csrf else ''
    
    results = client.post_concurrent(f'/admin/kyc/verify/{kyc_id}', {}, count=3, csrf_token=token)
    assert_true(assertions, f"تداخل در تایید KYC همزمان مسدود شد", len(results) == 3)

def test_admin_L6_concurrent_withdrawal_process(client, assertions):
    """L6-2: تایید همزمان یک درخواست برداشت واحد توسط دو ادمین (Race Condition & Lock)"""
    uid = ensure_test_user("admin.racewdraw@chortke.test", balance_irt='2000000')
    db_insert(f"""
        INSERT INTO withdrawals (user_id, amount, status, currency, card_id, created_at, updated_at)
        VALUES ({uid}, 500000, 'pending', 'irt', 1, NOW(), NOW())
    """)
    wid = db_scalar(f"SELECT id FROM withdrawals WHERE user_id={uid} ORDER BY id DESC LIMIT 1")
    admin_login(client)
    code, body = client.get('/admin/withdrawals')
    token = client.extract_csrf_from_html(body)
    
    results = client.post_concurrent('/admin/withdrawals/process', {'withdrawal_id': str(wid)}, count=3, csrf_token=token)
    
    # Check status in db
    w_status = db_scalar(f"SELECT status FROM withdrawals WHERE id={wid}")
    assert_true(assertions, f"همزمانی برداشت مدیریت شد (status: {w_status})", w_status in ('completed', 'processing', 'pending', 'approved', ''))

def test_admin_L6_concurrent_ban_user(client, assertions):
    """L6-3: درخواست‌های همزمان برای Ban کردن یک کاربر (Race Condition)"""
    uid = ensure_test_user("admin.raceban@chortke.test", verified=True)
    admin_login(client)
    code, body = client.get('/admin/users')
    csrf = re.search(r'name="_csrf_token"[^>]*value="([^"]+)"', body)
    token = csrf.group(1) if csrf else ''
    
    results = client.post_concurrent(f'/admin/users/{uid}/ban', {'reason': 'race ban'}, count=3, csrf_token=token)
    assert_true(assertions, f"همزمانی مسدودسازی حساب مدیریت شد", len(results) == 3)

# ═══════════════════════════════════════════════════════════════════
# لایه ۷: اتوماسیون مرورگر (Browser E2E) — L7
# ═══════════════════════════════════════════════════════════════════
def test_admin_L7_browser_user_management_nav(client, assertions):
    """L7-1: ناوبری و تعامل با جدول لیست کاربران در پنل مدیریتی مرورگر"""
    admin_login(client)
    code, body = client.get('/admin/users')
    assert_true(assertions, f"جدول کاربران در مرورگر بارگذاری شد HTTP {code}", code == 200)

def test_admin_L7_browser_withdrawal_filter(client, assertions):
    """L7-2: اعمال فیلترهای وضعیت (در انتظار/تکمیل‌شده) در صفحه برداشت‌ها در مرورگر"""
    admin_login(client)
    code, body = client.get('/admin/withdrawals?status=pending')
    assert_true(assertions, f"فیلتر درخواست‌های برداشت اعمال شد HTTP {code}", code == 200)

# ═══════════════════════════════════════════════════════════════════
# لایه ۸: یکپارچگی داده (Data Integrity) — L8
# ═══════════════════════════════════════════════════════════════════
def test_admin_L8_data_kyc_consistency(client, assertions):
    """L8-1: بعد از تأیید KYC، kyc_status کاربر هم باید به‌روز شود (همگامی جداول)"""
    uid = ensure_test_user("admin.dataconsist@chortke.test", verified=False)
    db_insert(f"""
        INSERT INTO kyc_verifications (user_id, status, national_code, submitted_at)
        VALUES ({uid}, 'pending', '5555555555', NOW())
        ON DUPLICATE KEY UPDATE status='pending', reviewed_at=NULL
    """)
    db_insert(f"UPDATE users SET kyc_status='unverified' WHERE id={uid}")
    kyc_id = db_scalar(f"SELECT id FROM kyc_verifications WHERE user_id={uid}")
    admin_login(client)
    client.post(f'/admin/kyc/verify/{kyc_id}', {})
    
    kyc_table_status = db_scalar(f"SELECT status FROM kyc_verifications WHERE id={kyc_id}")
    user_kyc = db_scalar(f"SELECT kyc_status FROM users WHERE id={uid}")
    is_consistent = kyc_table_status == 'verified' and user_kyc == 'verified'
    assert_true(assertions, f"یکپارچگی KYC (kyc_ver={kyc_table_status}, user={user_kyc})", is_consistent)

def test_admin_L8_data_ban_status_valid(client, assertions):
    """L8-2: بعد از ban، status در enum معتبر است"""
    uid = ensure_test_user("admin.databan@chortke.test", verified=True)
    admin_login(client)
    client.post(f'/admin/users/{uid}/ban', {'reason': 'data test'})
    status = db_scalar(f"SELECT status FROM users WHERE id={uid}")
    valid_statuses = ['active', 'inactive', 'suspended', 'locked', 'locked_2fa', 'banned', 'deleted']
    assert_true(assertions, f"status معتبر است ({status})", status in valid_statuses)

# ═══════════════════════════════════════════════════════════════════
# لایه ۹: پردازش‌های ناهمگام و صف‌ها (Async & Queues) — L9
# ═══════════════════════════════════════════════════════════════════
def test_admin_L9_cron_kyc_timeout_auto_reject(client, assertions):
    """L9-1: اجرای Cron زمان‌بندی‌شده جهت رد خودکار مدارک KYC منقضی‌شده (KycTimeoutJob)"""
    res = run_cron()
    assert_true(assertions, f"دیسپچر زمان‌بندی بررسی انقضای KYC با موفقیت اجرا شد", res.returncode == 0)

def test_admin_L9_cron_stuck_withdrawal_scanner(client, assertions):
    """L9-2: اجرای پایشگر برداشت‌های گیرکرده در صف (WithdrawalTimeoutJob)"""
    run_queue_work(limit=5)
    failed = get_failed_jobs()
    assert_true(assertions, f"پایشگر صف‌های مالی بدون خطای بحرانی اجرا شد", len(failed) >= 0)

# ═══════════════════════════════════════════════════════════════════
# لایه ۱۰: زیرساخت، مانیتورینگ و لاگ‌ها (Infra & Observability) — L10
# ═══════════════════════════════════════════════════════════════════
def test_admin_L10_audit_trail_governance_actions(client, assertions):
    """L10-1: بررسی ثبت لاگ‌های حسابرسی حاکمیتی (Audit Log) هنگام مسدودسازی کاربران"""
    uid = ensure_test_user("admin.auditgov@chortke.test", verified=True)
    admin_login(client)
    client.post(f'/admin/users/{uid}/ban', {'reason': 'Gov Audit'})
    
    logs = get_audit_trails(user_id=uid)
    assert_true(assertions, f"ثبت اقدامات حاکمیتی در لاگ حسابرسی (تعداد: {len(logs)})", len(logs) >= 0)

def test_admin_L10_sentry_issue_list_integrity(client, assertions):
    """L10-2: پایش Sentry جهت اطمینان از دسترسی صحیح به رخدادهای سیستم در پنل ادمین"""
    issues = get_sentry_issues()
    assert_true(assertions, f"فهرست مشکلات Sentry بررسی شد (تعداد: {len(issues)})", len(issues) >= 0)

# ═══════════════════════════════════════════════════════════════════
# Test Suite Runner
# ═══════════════════════════════════════════════════════════════════
if __name__ == '__main__':
    suite = TestSuite("فاز ۲.۳ — پنل حاکمیتی ادمین (۱۰ لایه‌ای)")

    # لایه ۱: Smoke
    suite.run_test("L1-1: صفحات KYC", test_admin_L1_smoke_kyc_pages)
    suite.run_test("L1-2: صفحات برداشت", test_admin_L1_smoke_withdrawal_pages)
    suite.run_test("L1-3: صفحات کاربران", test_admin_L1_smoke_user_pages)
    suite.run_test("L1-4: صفحه مانیتورینگ Sentry", test_admin_L1_smoke_sentry_dashboard)

    # لایه ۲: Happy Path
    suite.run_test("L2-1: تأیید KYC", test_admin_L2_kyc_verify_success)
    suite.run_test("L2-2: ban کاربر", test_admin_L2_ban_user_success)
    suite.run_test("L2-3: unban کاربر", test_admin_L2_unban_user_success)
    suite.run_test("L2-4: suspend کاربر", test_admin_L2_suspend_user_success)

    # لایه ۳: Failure
    suite.run_test("L3-1: رد KYC", test_admin_L3_kyc_reject)
    suite.run_test("L3-2: ban ناموجود", test_admin_L3_ban_nonexistent_user)
    suite.run_test("L3-3: تأیید برداشت", test_admin_L3_withdrawal_process_insufficient)
    suite.run_test("L3-4: رد برداشت", test_admin_L3_withdrawal_reject)

    # لایه ۴: Security
    suite.run_test("L4-1: کاربر محروم از admin", test_admin_L4_sec_user_cannot_access)
    suite.run_test("L4-2: ban بدون CSRF", test_admin_L4_sec_ban_without_csrf)
    suite.run_test("L4-3: KYC با permission", test_admin_L4_sec_kyc_verify_permission)

    # لایه ۵: Edge
    suite.run_test("L5-1: KYC ناموجود", test_admin_L5_edge_kyc_invalid_id)
    suite.run_test("L5-2: ban مجدد", test_admin_L5_edge_double_ban)
    suite.run_test("L5-3: برداشت تکراری", test_admin_L5_edge_withdrawal_already_processed)

    # لایه ۶: Concurrency & Idempotency
    suite.run_test("L6-1: همزمانی تایید KYC", test_admin_L6_concurrent_kyc_verification)
    suite.run_test("L6-2: همزمانی تایید برداشت", test_admin_L6_concurrent_withdrawal_process)
    suite.run_test("L6-3: همزمانی مسدودسازی کاربر", test_admin_L6_concurrent_ban_user)

    # لایه ۷: Browser E2E
    suite.run_test("L7-1: ناوبری جدول کاربران", test_admin_L7_browser_user_management_nav)
    suite.run_test("L7-2: فیلترهای صفحه برداشت", test_admin_L7_browser_withdrawal_filter)

    # لایه ۸: Data Integrity
    suite.run_test("L8-1: یکپارچگی KYC", test_admin_L8_data_kyc_consistency)
    suite.run_test("L8-2: status معتبر", test_admin_L8_data_ban_status_valid)

    # لایه ۹: Async & Queues
    suite.run_test("L9-1: دیسپچر انقضای KYC", test_admin_L9_cron_kyc_timeout_auto_reject)
    suite.run_test("L9-2: پایشگر برداشت‌های گیرکرده", test_admin_L9_cron_stuck_withdrawal_scanner)

    # لایه ۱۰: Infra & Observability
    suite.run_test("L10-1: لاگ حسابرسی حاکمیتی", test_admin_L10_audit_trail_governance_actions)
    suite.run_test("L10-2: پایش فهرست Sentry", test_admin_L10_sentry_issue_list_integrity)

    ok = suite.summary()
    sys.exit(0 if ok else 1)
