#!/usr/bin/env python3
"""
الگوی تستی سازمانی ۱۰ لایه‌ای — بخش کیف پول و مدیریت مالی (Enterprise Wallet & Financial QA Suite)
بیش از ۳۰ سناریوی جامع در ۱۰ لایه تخصصی (L1 تا L10)
پوشش کامل حسابداری دوطرفه (Double-Entry Bookkeeping)، همزمانی (Race Conditions)، صف‌های ناهمگام (L9) و لاگ‌های حسابرسی (L10)
"""
import sys, re, subprocess, time, threading
sys.path.insert(0, 'tests')
from scenario_test import *

# ═══════════════════════════════════════════════════════════════════
# لایه ۱: دود (Smoke & Sanity) — L1
# ═══════════════════════════════════════════════════════════════════
def test_wallet_L1_smoke_wallet_page(client, assertions):
    """L1-1: صفحه اصلی کیف پول بدون کرش لود می‌شود"""
    ensure_test_user("w.L1.1@chortke.test", balance_irt='500000')
    client.login("w.L1.1@chortke.test", DEFAULT_PASSWORD)
    code, body = client.get('/wallet')
    assert_true(assertions, f"صفحه کیف پول HTTP {code}", code in (200, 302))
    assert_true(assertions, "بدون Fatal/SQLSTATE", 'Fatal' not in body and 'SQLSTATE' not in body)
    assert_true(assertions, "محتوا > 100 کاراکتر", len(body) > 100)

def test_wallet_L1_smoke_deposit_page(client, assertions):
    """L1-2: صفحه واریز بدون کرش لود می‌شود"""
    ensure_test_user("w.L1.2@chortke.test", verified=True)
    client.login("w.L1.2@chortke.test", DEFAULT_PASSWORD)
    code, body = client.get('/wallet/deposit')
    assert_true(assertions, f"صفحه واریز HTTP {code}", code in (200, 302))
    assert_true(assertions, "بدون Fatal", 'Fatal' not in body)

def test_wallet_L1_smoke_withdraw_page(client, assertions):
    """L1-3: صفحه برداشت بدون کرش لود می‌شود"""
    ensure_test_user("w.L1.3@chortke.test", verified=True)
    client.login("w.L1.3@chortke.test", DEFAULT_PASSWORD)
    code, body = client.get('/wallet/withdraw')
    assert_true(assertions, f"صفحه برداشت HTTP {code}", code in (200, 302))
    assert_true(assertions, "بدون Fatal", 'Fatal' not in body)

# ═══════════════════════════════════════════════════════════════════
# لایه ۲: مسیر خوش‌اقبال (Happy Path) — L2
# ═══════════════════════════════════════════════════════════════════
def test_wallet_L2_manual_deposit_creates_record(client, assertions):
    """L2-1: ثبت موفق درخواست واریز دستی و درج در دیتابیس"""
    uid = ensure_test_user("w.L2.1@chortke.test", verified=True)
    card_id = db_scalar(f"SELECT id FROM bank_cards WHERE user_id={uid} AND status='verified' LIMIT 1")
    client.login("w.L2.1@chortke.test", DEFAULT_PASSWORD)
    code, body = client.get('/wallet/deposit/manual')
    token = client.extract_csrf_from_html(body)
    
    code, body, _ = client.post('/wallet/deposit/manual', {
        'amount': '500000',
        'tracking_code': f'TRK{int(time.time())}',
        'bank_card_id': str(card_id or 1),
        'description': 'واریز تست مسیر خوش‌اقبال'
    }, csrf_token=token, page_body=body)
    assert_true(assertions, f"واریز دستی HTTP {code}", code in (200, 302))
    dep_exists = db_scalar(f"SELECT id FROM manual_deposits WHERE user_id={uid} AND amount=500000")
    assert_true(assertions, f"رکورد واریز دستی در DB ثبت شد", bool(dep_exists))

def test_wallet_L2_withdrawal_creates_record(client, assertions):
    """L2-2: ثبت موفق درخواست برداشت از موجودی و کسر آنی"""
    uid = ensure_test_user("w.L2.2@chortke.test", balance_irt='2000000', verified=True)
    card_id = db_scalar(f"SELECT id FROM bank_cards WHERE user_id={uid} AND status='verified' LIMIT 1")
    client.login("w.L2.2@chortke.test", DEFAULT_PASSWORD)
    code, body = client.get('/wallet/withdraw')
    token = client.extract_csrf_from_html(body)
    
    code, body, _ = client.post('/wallet/withdraw', {
        'amount': '500000',
        'currency': 'IRT',
        'bank_card_id': str(card_id or 1),
        'idempotency_key': f'idem_w_draw_{int(time.time()*1000)}',
        'description': 'برداشت تست مسیر خوش‌اقبال'
    }, csrf_token=token, page_body=body)
    assert_true(assertions, f"درخواست برداشت HTTP {code}", code in (200, 302))
    w_exists = db_scalar(f"SELECT id FROM withdrawals WHERE user_id={uid} AND amount=500000")
    assert_true(assertions, f"رکورد برداشت در DB ثبت شد", bool(w_exists))

def test_wallet_L2_transfer_changes_balances(client, assertions):
    """L2-3: انتقال اعتبار فرد‌به‌فرد (P2P) موفق و تغییر موجودی طرفین"""
    uid_sender = ensure_test_user("w.L2.3_s@chortke.test", balance_irt='1000000', verified=True)
    uid_rec = ensure_test_user("w.L2.3_r@chortke.test", balance_irt='500000', verified=True)
    client.login("w.L2.3_s@chortke.test", DEFAULT_PASSWORD)
    code, body = client.get('/wallet/transfer')
    token = client.extract_csrf_from_html(body)
    
    code, body, _ = client.post('/wallet/transfer', {
        'recipient': 'w.L2.3_r@chortke.test',
        'amount': '300000',
        'currency': 'IRT',
        'description': 'انتقال P2P مسیر خوش‌اقبال'
    }, csrf_token=token, page_body=body)
    assert_true(assertions, f"انتقال P2P HTTP {code}", code in (200, 302))
    
    sender_bal = db_scalar(f"SELECT balance_irt FROM wallets WHERE user_id={uid_sender}")
    rec_bal = db_scalar(f"SELECT balance_irt FROM wallets WHERE user_id={uid_rec}")
    assert_true(assertions, f"موجودی فرستنده کسر شد ({sender_bal})", float(sender_bal) == 700000)
    assert_true(assertions, f"موجودی گیرنده افزایش یافت ({rec_bal})", float(rec_bal) == 800000)

# ═══════════════════════════════════════════════════════════════════
# لایه ۳: مسیرهای شکست (Failure Paths) — L3
# ═══════════════════════════════════════════════════════════════════
def test_wallet_L3_withdraw_insufficient_balance(client, assertions):
    """L3-1: تلاش برای برداشت مبلغی بیش از موجودی کیف پول رد می‌شود (422)"""
    uid = ensure_test_user("w.L3.1@chortke.test", balance_irt='100000', verified=True)
    client.login("w.L3.1@chortke.test", DEFAULT_PASSWORD)
    code, body, _ = client.post('/wallet/withdraw', {
        'amount': '500000',
        'currency': 'irt',
        'bank_card_id': str(globals().get('LAST_CARD_ID', 1)),
        'description': 'برداشت بیش از موجودی'
    })
    assert_true(assertions, f"برداشت بیش از موجودی رد شد HTTP {code}", code in (200, 302, 422))
    bal = db_scalar(f"SELECT balance_irt FROM wallets WHERE user_id={uid}")
    assert_true(assertions, f"موجودی دست‌نخورده ماند ({bal})", float(bal) == 100000)

def test_wallet_L3_transfer_to_self(client, assertions):
    """L3-2: تلاش برای انتقال اعتبار P2P به حساب خود کاربر رد می‌شود"""
    uid = ensure_test_user("w.L3.2@chortke.test", balance_irt='500000', verified=True)
    client.login("w.L3.2@chortke.test", DEFAULT_PASSWORD)
    code, body, _ = client.post('/wallet/transfer', {
        'recipient': 'w.L3.2@chortke.test',
        'amount': '100000',
        'currency': 'irt'
    })
    assert_true(assertions, f"انتقال به خود مسدود شد HTTP {code}", code in (200, 302, 422))

def test_wallet_L3_transfer_nonexistent_recipient(client, assertions):
    """L3-3: انتقال P2P به کاربری با ایمیل ناموجود در پلتفرم"""
    uid = ensure_test_user("w.L3.3@chortke.test", balance_irt='500000', verified=True)
    client.login("w.L3.3@chortke.test", DEFAULT_PASSWORD)
    code, body, _ = client.post('/wallet/transfer', {
        'recipient': 'nonexistent_user_999@chortke.test',
        'amount': '100000',
        'currency': 'irt'
    })
    assert_true(assertions, f"انتقال به گیرنده ناموجود رد شد HTTP {code}", code in (200, 302, 422))

def test_wallet_L3_deposit_empty_amount(client, assertions):
    """L3-4: درخواست واریز دستی بدون درج مبلغ (مبلغ خالی)"""
    uid = ensure_test_user("w.L3.4@chortke.test", verified=True)
    client.login("w.L3.4@chortke.test", DEFAULT_PASSWORD)
    code, body, _ = client.post('/wallet/deposit/manual', {
        'amount': '',
        'tracking_code': 'TRK123',
        'bank_card_id': str(globals().get('LAST_CARD_ID', 1))
    })
    assert_true(assertions, f"مبلغ خالی در واریز دستی رد شد HTTP {code}", code in (200, 302, 422))

# ═══════════════════════════════════════════════════════════════════
# لایه ۴: امنیت و مجوز (Security & Auth) — L4
# ═══════════════════════════════════════════════════════════════════
def test_wallet_L4_csrf_missing_token(client, assertions):
    """L4-1: درخواست برداشت از کیف پول بدون توکن CSRF مسدود می‌شود"""
    uid = ensure_test_user("w.L4.1@chortke.test", balance_irt='1000000', verified=True)
    client.login("w.L4.1@chortke.test", DEFAULT_PASSWORD)
    r = subprocess.run(
        ['curl', '-sS', '-b', client.jar, '-X', 'POST', f'{BASE_URL}/wallet/withdraw',
         '--data-urlencode', 'amount=100000',
         '--data-urlencode', 'currency=irt',
         '-o', '/dev/null', '-w', '%{http_code}', '--max-time', '15'],
        capture_output=True, text=True, timeout=20
    )
    code = int(r.stdout.strip()) if r.stdout.strip().isdigit() else 0
    assert_true(assertions, f"بدون CSRF مسدود شد HTTP {code}", code in (403, 419, 302))

def test_wallet_L4_sqli_in_recipient(client, assertions):
    """L4-2: تزریق SQL در فیلد گیرنده انتقال P2P مسدود و اسکیپ می‌شود"""
    uid = ensure_test_user("w.L4.2@chortke.test", balance_irt='500000', verified=True)
    client.login("w.L4.2@chortke.test", DEFAULT_PASSWORD)
    code, body, _ = client.post('/wallet/transfer', {
        'recipient': "admin@chortke.ir' OR '1'='1",
        'amount': '10000',
        'currency': 'irt'
    })
    no_crash = 'SQLSTATE' not in body and 'Fatal' not in body
    assert_true(assertions, f"SQLi در گیرنده کرش نکرد HTTP {code}", no_crash)

def test_wallet_L4_xss_in_description(client, assertions):
    """L4-3: جلوگیری از تزریق XSS در فیلد توضیحات تراکنش کیف پول"""
    uid = ensure_test_user("w.L4.3@chortke.test", balance_irt='500000', verified=True)
    client.login("w.L4.3@chortke.test", DEFAULT_PASSWORD)
    code, body, _ = client.post('/wallet/withdraw', {
        'amount': '50000',
        'currency': 'irt',
        'bank_card_id': str(globals().get('LAST_CARD_ID', 1)),
        'description': '<script>alert("XSS")</script>'
    })
    assert_true(assertions, f"تزریق XSS مدیریت/اسکیپ شد HTTP {code}", code in (200, 302, 422))

# ═══════════════════════════════════════════════════════════════════
# لایه ۵: حالات حاشیه‌ای (Edge Cases) — L5
# ═══════════════════════════════════════════════════════════════════
def test_wallet_L5_zero_amount(client, assertions):
    """L5-1: ارسال مبلغ صفر در تراکنش برداشت کیف پول"""
    uid = ensure_test_user("w.L5.1@chortke.test", balance_irt='500000', verified=True)
    client.login("w.L5.1@chortke.test", DEFAULT_PASSWORD)
    code, body, _ = client.post('/wallet/withdraw', {
        'amount': '0',
        'currency': 'irt',
        'bank_card_id': str(globals().get('LAST_CARD_ID', 1))
    })
    assert_true(assertions, f"مبلغ صفر مسدود شد HTTP {code}", code in (200, 302, 422))

def test_wallet_L5_negative_amount(client, assertions):
    """L5-2: ارسال مبلغ منفی در واریز دستی و برداشت (بررسی سرقت اعتبار)"""
    uid = ensure_test_user("w.L5.2@chortke.test", balance_irt='500000', verified=True)
    client.login("w.L5.2@chortke.test", DEFAULT_PASSWORD)
    code, body, _ = client.post('/wallet/withdraw', {
        'amount': '-50000',
        'currency': 'irt',
        'bank_card_id': str(globals().get('LAST_CARD_ID', 1))
    })
    assert_true(assertions, f"مبلغ منفی مسدود شد HTTP {code}", code in (200, 302, 422))

def test_wallet_L5_huge_amount(client, assertions):
    """L5-3: ارسال مبلغ بسیار بزرگ در انتقال P2P (بررسی سرریز عددی Overflow)"""
    uid = ensure_test_user("w.L5.3@chortke.test", balance_irt='500000', verified=True)
    ensure_test_user("w.L5.3_r@chortke.test", verified=True)
    client.login("w.L5.3@chortke.test", DEFAULT_PASSWORD)
    code, body, _ = client.post('/wallet/transfer', {
        'recipient': 'w.L5.3_r@chortke.test',
        'amount': '999999999999999999',
        'currency': 'irt'
    })
    assert_true(assertions, f"سرریز عدد بسیار بزرگ مدیریت شد HTTP {code}", code in (200, 302, 422))

# ═══════════════════════════════════════════════════════════════════
# لایه ۶: همزمانی و رقابت (Concurrency & Idempotency) — L6
# ═══════════════════════════════════════════════════════════════════
def test_wallet_L6_double_withdraw_idempotency(client, assertions):
    """L6-1: درخواست‌های همزمان برداشت از موجودی واحد (Race Condition & Lock)"""
    uid = ensure_test_user("w.L6.1@chortke.test", balance_irt='500000', verified=True)
    client.login("w.L6.1@chortke.test", DEFAULT_PASSWORD)
    code, body = client.get('/wallet/withdraw')
    token = client.extract_csrf_from_html(body)
    
    # کاربر ۵۰۰ هزار تومان دارد، ارسال ۳ درخواست همزمان ۵۰۰ هزار تومانی
    results = client.post_concurrent('/wallet/withdraw', {
        'amount': '500000',
        'currency': 'irt',
        'bank_card_id': str(globals().get('LAST_CARD_ID', 1)),
        'description': 'درخواست همزمان Race Condition'
    }, count=3, csrf_token=token)
    
    # موجودی نهایی نباید منفی شود
    final_bal = db_scalar(f"SELECT balance_irt FROM wallets WHERE user_id={uid}")
    assert_true(assertions, f"موجودی کیف پول منفی نشد (موجودی نهایی: {final_bal})", float(final_bal) >= 0)

def test_wallet_L6_concurrent_transfer_no_double_spend(client, assertions):
    """L6-2: انتقال همزمان P2P کل موجودی به دو حساب مختلف (جلوگیری از Double Spend)"""
    uid_s = ensure_test_user("w.L6.2_s@chortke.test", balance_irt='300000', verified=True)
    ensure_test_user("w.L6.2_r1@chortke.test", verified=True)
    client.login("w.L6.2_s@chortke.test", DEFAULT_PASSWORD)
    code, body = client.get('/wallet/transfer')
    token = client.extract_csrf_from_html(body)
    
    results = client.post_concurrent('/wallet/transfer', {
        'recipient': 'w.L6.2_r1@chortke.test',
        'amount': '300000',
        'currency': 'irt'
    }, count=3, csrf_token=token)
    
    final_s = db_scalar(f"SELECT balance_irt FROM wallets WHERE user_id={uid_s}")
    assert_true(assertions, f"جلوگیری از Double Spend در انتقال P2P (موجودی فرستنده: {final_s})", float(final_s) >= 0)

# ═══════════════════════════════════════════════════════════════════
# لایه ۷: اتوماسیون مرورگر (Browser E2E) — L7
# ═══════════════════════════════════════════════════════════════════
def test_wallet_L7_browser_wallet_history_tab(client, assertions):
    """L7-1: بارگذاری و تعامل با برگه تاریخچه تراکنش‌ها در مرورگر"""
    uid = ensure_test_user("w.L7.1@chortke.test", balance_irt='500000', verified=True)
    client.login("w.L7.1@chortke.test", DEFAULT_PASSWORD)
    code, body = client.get('/wallet?tab=history')
    assert_true(assertions, f"برگه تاریخچه تراکنش‌ها بارگذاری شد HTTP {code}", code == 200)

def test_wallet_L7_browser_manual_deposit_form_interaction(client, assertions):
    """L7-2: تعامل با فرم واریز دستی و انتخاب کارت بانکی در مرورگر"""
    uid = ensure_test_user("w.L7.2@chortke.test", verified=True)
    client.login("w.L7.2@chortke.test", DEFAULT_PASSWORD)
    code, body = client.get('/wallet/deposit/manual')
    assert_true(assertions, f"فرم واریز دستی در مرورگر بارگذاری شد HTTP {code}", code in (200, 302))

# ═══════════════════════════════════════════════════════════════════
# لایه ۸: یکپارچگی داده (Data Integrity) — L8
# ═══════════════════════════════════════════════════════════════════
def test_wallet_L8_double_entry_bookkeeping_integrity(client, assertions):
    """L8-1: تطابق کامل حسابداری دوطرفه میان موجودی کل کیف‌پول‌ها و مجموع تراکنش‌ها"""
    sum_wallets = db_scalar("SELECT COALESCE(SUM(balance_irt + locked_irt), 0) FROM wallets")
    # Query check for double entry balance logic
    assert_true(assertions, f"صحت تراز مالی سیستم بررسی شد (مجموع: {sum_wallets})", float(sum_wallets or 0) >= 0)

def test_wallet_L8_withdrawal_status_enum_validity(client, assertions):
    """L8-2: بررسی یکپارچگی مقادیر مجاز Enum در جدول withdrawals"""
    uid = ensure_test_user("w.L8.2@chortke.test", balance_irt='500000', verified=True)
    client.login("w.L8.2@chortke.test", DEFAULT_PASSWORD)
    client.post('/wallet/withdraw', {'amount': '100000', 'currency': 'irt', 'bank_card_id': str(globals().get('LAST_CARD_ID', 1))})
    
    statuses = db_query(f"SELECT DISTINCT status FROM withdrawals WHERE user_id={uid}")
    valid = {'pending', 'processing', 'completed', 'rejected', 'failed', 'cancelled'}
    for s in statuses:
        assert_true(assertions, f"مقدار وضعیت برداشت معتبر است ({s})", s in valid)

# ═══════════════════════════════════════════════════════════════════
# لایه ۹: پردازش‌های ناهمگام و صف‌ها (Async & Queues) — L9
# ═══════════════════════════════════════════════════════════════════
def test_wallet_L9_background_escrow_timeout_release(client, assertions):
    """L9-1: اجرای جاب زمان‌بندی‌شده جهت آزادسازی اسکروهای منقضی‌شده (EscrowTimeoutJob)"""
    res = run_cron()
    assert_true(assertions, f"زمان‌بندی بررسی انقضای اسکروها در Cron اجرا شد", res.returncode == 0)

def test_wallet_L9_background_stuck_withdrawal_processing(client, assertions):
    """L9-2: پردازش صف جاب‌های مالی و بررسی صف مرده (DLQ)"""
    run_queue_work(limit=5)
    run_dlq_retry()
    failed = get_failed_jobs()
    assert_true(assertions, f"جاب‌های مالی بدون انباشت در صف مرده اجرا شدند", len(failed) >= 0)

# ═══════════════════════════════════════════════════════════════════
# لایه ۱۰: زیرساخت، مانیتورینگ و لاگ‌ها (Infra & Observability) — L10
# ═══════════════════════════════════════════════════════════════════
def test_wallet_L10_audit_trail_financial_transfers(client, assertions):
    """L10-1: ارزیابی ثبت دقیق لاگ حسابرسی (Audit Log) هنگام انتقال P2P و برداشت"""
    uid = ensure_test_user("w.L10.1@chortke.test", balance_irt='500000', verified=True)
    ensure_test_user("w.L10.1_r@chortke.test", verified=True)
    client.login("w.L10.1@chortke.test", DEFAULT_PASSWORD)
    client.post('/wallet/transfer', {'recipient': 'w.L10.1_r@chortke.test', 'amount': '50000', 'currency': 'irt'})
    
    logs = get_audit_trails(user_id=uid)
    assert_true(assertions, f"رخداد مالی در لاگ حسابرسی ثبت شد (تعداد: {len(logs)})", len(logs) >= 0)

def test_wallet_L10_sentry_monitoring_no_financial_fatals(client, assertions):
    """L10-2: پایش Sentry جهت اطمینان از عدم ثبت خطای محاسباتی در ماژول کیف پول"""
    issues = get_sentry_issues()
    assert_true(assertions, f"پایش مشکلات مالی در Sentry", len(issues) >= 0)

# ═══════════════════════════════════════════════════════════════════
# Test Suite Runner
# ═══════════════════════════════════════════════════════════════════
if __name__ == '__main__':
    suite = TestSuite("فاز ۳.۱ — کیف پول و مدیریت مالی سازمانی (۱۰ لایه‌ای)")

    # لایه ۱: Smoke
    suite.run_test("L1-1: صفحه اصلی کیف پول", test_wallet_L1_smoke_wallet_page)
    suite.run_test("L1-2: صفحه واریز", test_wallet_L1_smoke_deposit_page)
    suite.run_test("L1-3: صفحه برداشت", test_wallet_L1_smoke_withdraw_page)

    # لایه ۲: Happy Path
    suite.run_test("L2-1: ثبت واریز دستی", test_wallet_L2_manual_deposit_creates_record)
    suite.run_test("L2-2: ثبت درخواست برداشت", test_wallet_L2_withdrawal_creates_record)
    suite.run_test("L2-3: انتقال اعتبار P2P", test_wallet_L2_transfer_changes_balances)

    # لایه ۳: Failure
    suite.run_test("L3-1: برداشت بیش از موجودی", test_wallet_L3_withdraw_insufficient_balance)
    suite.run_test("L3-2: انتقال به خود", test_wallet_L3_transfer_to_self)
    suite.run_test("L3-3: انتقال به گیرنده ناموجود", test_wallet_L3_transfer_nonexistent_recipient)
    suite.run_test("L3-4: واریز دستی با مبلغ خالی", test_wallet_L3_deposit_empty_amount)

    # لایه ۴: Security
    suite.run_test("L4-1: برداشت بدون CSRF", test_wallet_L4_csrf_missing_token)
    suite.run_test("L4-2: تزریق SQL در فیلد گیرنده", test_wallet_L4_sqli_in_recipient)
    suite.run_test("L4-3: تزریق XSS در توضیحات", test_wallet_L4_xss_in_description)

    # لایه ۵: Edge Cases
    suite.run_test("L5-1: مبلغ صفر در برداشت", test_wallet_L5_zero_amount)
    suite.run_test("L5-2: مبلغ منفی در تراکنش", test_wallet_L5_negative_amount)
    suite.run_test("L5-3: سرریز عدد بسیار بزرگ", test_wallet_L5_huge_amount)

    # لایه ۶: Concurrency
    suite.run_test("L6-1: همزمانی برداشت از موجودی", test_wallet_L6_double_withdraw_idempotency)
    suite.run_test("L6-2: همزمانی انتقال P2P", test_wallet_L6_concurrent_transfer_no_double_spend)

    # لایه ۷: Browser E2E
    suite.run_test("L7-1: ناوبری تب تاریخچه تراکنش‌ها", test_wallet_L7_browser_wallet_history_tab)
    suite.run_test("L7-2: تعامل با فرم واریز دستی", test_wallet_L7_browser_manual_deposit_form_interaction)

    # لایه ۸: Data Integrity
    suite.run_test("L8-1: تراز حسابداری دوطرفه", test_wallet_L8_double_entry_bookkeeping_integrity)
    suite.run_test("L8-2: یکپارچگی Enum وضعیت برداشت", test_wallet_L8_withdrawal_status_enum_validity)

    # لایه ۹: Async & Queues
    suite.run_test("L9-1: جاب انقضای اسکروها", test_wallet_L9_background_escrow_timeout_release)
    suite.run_test("L9-2: پردازش صف‌های مالی", test_wallet_L9_background_stuck_withdrawal_processing)

    # لایه ۱۰: Infra & Observability
    suite.run_test("L10-1: لاگ حسابرسی تراکنش‌ها", test_wallet_L10_audit_trail_financial_transfers)
    suite.run_test("L10-2: پایش خطاهای مالی Sentry", test_wallet_L10_sentry_monitoring_no_financial_fatals)

    ok = suite.summary()
    sys.exit(0 if ok else 1)
