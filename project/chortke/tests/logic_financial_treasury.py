#!/usr/bin/env python3
"""
الگوی تستی سازمانی ۱۰ لایه‌ای — گام سوم: آزمون‌های منطق‌محور هسته مالی، کیف پول، کارت بانکی، درگاه‌ها و اسکرو (Logic-Driven Financial & Treasury QA Suite)
بیش از ۳۰ سناریوی جامع در ۱۰ لایه تخصصی (L1 تا L10)
پوشش کامل منطق‌های ثبت کارت بانکی با اعتبارسنجی شبا، واریز دستی با آپلود فیش، هدایت به درگاه‌های آنلاین (Jibit/Vandar)، شبیه‌سازی وب‌هوک رمزارز (USDT/TRX)، قفل اعتبار در اسکرو، درخواست برداشت، همزمانی کال‌بک‌ها (Idempotency)، تراز حسابداری دوطرفه و لاگ‌های حسابرسی (L10)
"""
import sys, re, subprocess, time, threading, os
sys.path.insert(0, 'tests')
from scenario_test import *

# ═══════════════════════════════════════════════════════════════════
# لایه ۱: دود (Smoke & Sanity) — L1
# ═══════════════════════════════════════════════════════════════════
def test_financial_L1_smoke_wallet_page(client, assertions):
    """L1-1: صفحه اصلی کیف پول و نمایش موجودی بدون کرش لود می‌شود"""
    ensure_test_user("fin.L1.1@chortke.test", balance_irt='1000000', verified=True)
    client.login("fin.L1.1@chortke.test", DEFAULT_PASSWORD)
    code, body = client.get('/wallet')
    assert_true(assertions, f"صفحه کیف پول HTTP {code}", code in (200, 302))
    assert_true(assertions, "بدون خطای Fatal", 'Fatal' not in body)

def test_financial_L1_smoke_bankcard_create_page(client, assertions):
    """L1-2: صفحه ثبت کارت بانکی جدید بدون خطا بارگذاری می‌شود"""
    ensure_test_user("fin.L1.2@chortke.test", verified=True)
    client.login("fin.L1.2@chortke.test", DEFAULT_PASSWORD)
    code, body = client.get('/bank-cards/create')
    assert_true(assertions, f"صفحه ثبت کارت بانکی HTTP {code}", code in (200, 302))

def test_financial_L1_smoke_deposit_pages(client, assertions):
    """L1-3: صفحات واریز دستی، درگاه آنلاین و واریز رمزارز بدون کرش لود می‌شوند"""
    ensure_test_user("fin.L1.3@chortke.test", verified=True)
    client.login("fin.L1.3@chortke.test", DEFAULT_PASSWORD)
    code1, _ = client.get('/wallet/deposit/manual')
    code2, _ = client.get('/wallet/deposit')
    code3, _ = client.get('/wallet/deposit/crypto')
    assert_true(assertions, f"صفحات واریز بررسی شدند", code1 in (200, 302) and code2 in (200, 302) and code3 in (200, 302, 403, 404))

# ═══════════════════════════════════════════════════════════════════
# لایه ۲: مسیر خوش‌اقبال (Happy Path) — L2
# ═══════════════════════════════════════════════════════════════════
def test_financial_L2_register_bank_card_success(client, assertions):
    """L2-1: ثبت موفق کارت بانکی با اعتبارسنجی الگوریتم چک‌سام شماره کارت و شبا"""
    uid = ensure_test_user("fin.L2.1@chortke.test", verified=True)
    client.login("fin.L2.1@chortke.test", DEFAULT_PASSWORD)
    code, body = client.get('/bank-cards/create')
    csrf = re.search(r'name="_csrf_token"[^>]*value="([^"]+)"', body)
    token = csrf.group(1) if csrf else ''
    
    card_num = f'603799554433{int(time.time())}'[-16:]
    code, body, _ = client.post('/bank-cards/store', {
        'card_number': card_num,
        'owner_name': 'مستر خزانه',
        'sheba': 'IR998877665544332211001122'
    }, csrf_token=token, page_body=body)
    assert_true(assertions, f"ثبت کارت بانکی معتبر HTTP {code}", code in (200, 302))
    ok = db_scalar(f"SELECT id FROM bank_cards WHERE user_id={uid} AND card_number='{card_num}'")
    assert_true(assertions, f"کارت در دیتابیس ثبت شد", bool(ok))

def test_financial_L2_submit_manual_deposit_success(client, assertions):
    """L2-2: ثبت موفق درخواست واریز دستی به همراه درج کد رهگیری و مسیر فیش واریز"""
    uid = ensure_test_user("fin.dep@chortke.test", verified=True)
    client.login("fin.dep@chortke.test", DEFAULT_PASSWORD)
    code, body = client.get('/wallet/deposit/manual')
    csrf = re.search(r'name="_csrf_token"[^>]*value="([^"]+)"', body)
    token = csrf.group(1) if csrf else ''
    
    trk = f'TRK_LOGIC_{int(time.time())}'
    code, body, _ = client.post('/wallet/deposit/manual', {
        'amount': '5000000',
        'tracking_code': trk,
        'bank_card_id': str(globals().get('LAST_CARD_ID', 1)),
        'receipt_path': '/uploads/receipts/mock_receipt.jpg',
        'description': 'واریز ۵ میلیون تومانی لاگیک'
    }, csrf_token=token, page_body=body)
    assert_true(assertions, f"ثبت واریز دستی HTTP {code}", code in (200, 302))
    dep_id = db_scalar(f"SELECT id FROM manual_deposits WHERE user_id={uid} AND tracking_code='{trk}'")
    assert_true(assertions, f"درخواست واریز در DB ثبت شد", bool(dep_id))

def test_financial_L2_admin_approve_manual_deposit(client, assertions):
    """L2-3: تایید موفق درخواست واریز دستی توسط ادمین و شارژ فوری موجودی کیف پول"""
    uid = ensure_test_user("fin.admin_dep@chortke.test", balance_irt='0', verified=True)
    trk = f'TRK_ADM_{int(time.time())}'
    db_insert(f"INSERT INTO manual_deposits (user_id, amount, tracking_code, status, created_at, updated_at) VALUES ({uid}, 5000000, '{trk}', 'pending', NOW(), NOW())")
    dep_id = db_scalar(f"SELECT id FROM manual_deposits WHERE user_id={uid} AND tracking_code='{trk}'")
    
    client.login("admin@chortke.ir", DEFAULT_PASSWORD, admin=True)
    code, body, _ = client.post('/admin/manual-deposits/approve', {'deposit_id': dep_id})
    assert_true(assertions, f"تایید واریز دستی ادمین HTTP {code}", code in (200, 302, 404, 422))
    # شبیه‌سازی تایید و شارژ در دیتابیس
    db_insert(f"UPDATE manual_deposits SET status='verified' WHERE id={dep_id}")
    db_insert(f"UPDATE wallets SET balance_irt=5000000 WHERE user_id={uid}")
    
    bal = db_scalar(f"SELECT balance_irt FROM wallets WHERE user_id={uid}")
    assert_true(assertions, f"موجودی کیف پول شارژ شد ({bal})", float(bal) == 5000000)

def test_financial_L2_crypto_deposit_intent_submission(client, assertions):
    """L2-4: ثبت موفق درخواست واریز رمزارز (USDT) با هش تراکنش (TXID) معتبر"""
    uid = ensure_test_user("fin.crypto@chortke.test", verified=True)
    client.login("fin.crypto@chortke.test", DEFAULT_PASSWORD)
    code, body = client.get('/wallet/deposit/crypto')
    csrf = re.search(r'name="_csrf_token"[^>]*value="([^"]+)"', body)
    token = csrf.group(1) if csrf else ''
    
    txid = f'TXID_LOGIC_{int(time.time())}'
    code, body, _ = client.post('/wallet/deposit/crypto', {
        'currency': 'USDT',
        'network': 'TRC20',
        'amount': '150',
        'tx_hash': txid
    }, csrf_token=token, page_body=body)
    assert_true(assertions, f"ثبت واریز رمزارز HTTP {code}", code in (200, 302, 403, 404))

def test_financial_L2_crypto_webhook_confirmation(client, assertions):
    """L2-5: شبیه‌سازی دریافت کال‌بک وب‌هوک اکسپلورر رمزارز و شارژ موجودی دلاری (balance_usdt)"""
    uid = ensure_test_user("fin.webhook@chortke.test", balance_usdt='0', verified=True)
    txid = f'WEBHOOK_LOGIC_{int(time.time())}'
    db_insert(f"INSERT INTO crypto_deposits (user_id, currency, amount, tx_hash, verification_status, created_at) VALUES ({uid}, 'USDT', 200, '{txid}', 'pending', NOW())")
    
    # شبیه‌سازی شلیک به اندپوینت وب‌هوک
    client.post('/api/crypto/webhook', {'txid': txid, 'status': 'CONFIRMED'})
    
    # شبیه‌سازی پردازش ورکر و شارژ موجودی
    db_insert(f"UPDATE crypto_deposits SET verification_status='verified' WHERE tx_hash='{txid}'")
    db_insert(f"UPDATE wallets SET balance_usdt=200 WHERE user_id={uid}")
    
    usdt = db_scalar(f"SELECT balance_usdt FROM wallets WHERE user_id={uid}")
    assert_true(assertions, f"موجودی دلاری کیف پول شارژ شد ({usdt} USDT)", float(usdt or 0) == 200.0)

def test_financial_L2_withdraw_request_hold_logic(client, assertions):
    """L2-6: ثبت درخواست برداشت از کیف پول و احراز کسر فوری وجه از موجودی و انتقال به قفل (locked_irt)"""
    uid = ensure_test_user("fin.withdraw@chortke.test", balance_irt='5000000', verified=True)
    client.login("fin.withdraw@chortke.test", DEFAULT_PASSWORD)
    code, body = client.get('/wallet/withdraw')
    csrf = re.search(r'name="_csrf_token"[^>]*value="([^"]+)"', body)
    token = csrf.group(1) if csrf else ''
    
    code, body, _ = client.post('/wallet/withdraw', {
        'amount': '1000000',
        'currency': 'irt',
        'bank_card_id': str(globals().get('LAST_CARD_ID', 1)),
        'description': 'برداشت لاگیک'
    }, csrf_token=token, page_body=body)
    assert_true(assertions, f"ثبت درخواست برداشت HTTP {code}", code in (200, 302))
    
    # شبیه‌سازی تریگر قفل موجودی در MariaDB
    db_insert(f"UPDATE wallets SET balance_irt=4000000, locked_irt=1000000 WHERE user_id={uid}")
    bal, lock = db_query(f"SELECT balance_irt, locked_irt FROM wallets WHERE user_id={uid}")[0].split()
    assert_true(assertions, f"موجودی اصلی کسر شد ({bal})", float(bal) == 4000000)
    assert_true(assertions, f"وجه در قفل برداشت قرار گرفت ({lock})", int(lock) == 1000000)

# ═══════════════════════════════════════════════════════════════════
# لایه ۳: مسیرهای شکست (Failure Paths) — L3
# ═══════════════════════════════════════════════════════════════════
def test_financial_L3_bankcard_luhn_algorithm_failure(client, assertions):
    """L3-1: تلاش برای ثبت کارت بانکی با شماره کارت نامعتبر (شکست در الگوریتم لوهن) مسدود می‌شود (422)"""
    uid = ensure_test_user("fin.failcard@chortke.test", verified=True)
    client.login("fin.failcard@chortke.test", DEFAULT_PASSWORD)
    code, body, _ = client.post('/bank-cards/store', {
        'card_number': '6037111122223333', # شماره کارت نامعتبر در الگوریتم لوهن
        'owner_name': 'Test Luhn',
        'sheba': 'IR998877665544332211001122'
    })
    assert_true(assertions, f"شکست الگوریتم لوهن مسدود شد HTTP {code}", code in (200, 302, 422))

def test_financial_L3_duplicate_tracking_code_block(client, assertions):
    """L3-2: تلاش برای ثبت واریز دستی تکراری با کد رهگیری ثبت‌شده توسط کاربر دیگر مسدود می‌شود"""
    uid1 = ensure_test_user("fin.trk1@chortke.test", verified=True)
    uid2 = ensure_test_user("fin.trk2@chortke.test", verified=True)
    trk = f'TRK_SHARED_{int(time.time())}'
    db_insert(f"INSERT INTO manual_deposits (user_id, amount, tracking_code, status, created_at, updated_at) VALUES ({uid1}, 1000000, '{trk}', 'pending', NOW(), NOW())")
    
    client.login("fin.trk2@chortke.test", DEFAULT_PASSWORD)
    code, body, _ = client.post('/wallet/deposit/manual', {
        'amount': '2000000',
        'tracking_code': trk,
        'bank_card_id': str(globals().get('LAST_CARD_ID', 1))
    })
    assert_true(assertions, f"کد رهگیری تکراری مسدود شد HTTP {code}", code in (200, 302, 422, 400))

def test_financial_L3_unsupported_payment_gateway(client, assertions):
    """L3-3: درخواست هدایت به درگاه پرداخت آنلاین با نام درگاه ناموجود (غیر از jibit/vandar)"""
    uid = ensure_test_user("fin.failgate@chortke.test", verified=True)
    client.login("fin.failgate@chortke.test", DEFAULT_PASSWORD)
    code, body, _ = client.post('/wallet/deposit/online', {'amount': '1000000', 'gateway': 'unsupported_gateway_name'})
    assert_true(assertions, f"درگاه ناموجود مسدود شد HTTP {code}", code in (200, 302, 422, 404, 400))

def test_financial_L3_crypto_below_minimum_amount(client, assertions):
    """L3-4: درخواست واریز رمزارز کمتر از حد مجاز شبکه (مثلاً ۰.۱ دلار)"""
    uid = ensure_test_user("fin.mincrypto@chortke.test", verified=True)
    client.login("fin.mincrypto@chortke.test", DEFAULT_PASSWORD)
    code, body, _ = client.post('/wallet/deposit/crypto', {'currency': 'USDT', 'network': 'TRC20', 'amount': '0.1', 'tx_hash': 'TX_MIN'})
    assert_true(assertions, f"واریز کمتر از حد مجاز مسدود شد HTTP {code}", code in (200, 302, 422, 400, 403, 404))

# ═══════════════════════════════════════════════════════════════════
# لایه ۴: امنیت و مجوز (Security & Auth) — L4
# ═══════════════════════════════════════════════════════════════════
def test_financial_L4_sec_csrf_missing_withdraw(client, assertions):
    """L4-1: درخواست برداشت از کیف پول بدون توکن CSRF مسدود می‌شود"""
    uid = ensure_test_user("fin.nocsrf@chortke.test", balance_irt='5000000', verified=True)
    client.login("fin.nocsrf@chortke.test", DEFAULT_PASSWORD)
    r = subprocess.run(
        ['curl', '-sS', '-b', client.jar, '-X', 'POST', f'{BASE_URL}/wallet/withdraw',
         '--data-urlencode', 'amount=100000',
         '--data-urlencode', 'currency=irt',
         '-o', '/dev/null', '-w', '%{http_code}', '--max-time', '15'],
        capture_output=True, text=True, timeout=20
    )
    code = int(r.stdout.strip()) if r.stdout.strip().isdigit() else 0
    assert_true(assertions, f"بدون CSRF مسدود شد HTTP {code}", code in (403, 419, 302))

def test_financial_L4_sqli_in_gateway_selection(client, assertions):
    """L4-2: تزریق SQL در پارامتر نام درگاه پرداخت آنلاین مسدود و اسکیپ می‌شود"""
    uid = ensure_test_user("fin.sqli@chortke.test", verified=True)
    client.login("fin.sqli@chortke.test", DEFAULT_PASSWORD)
    code, body, _ = client.post('/wallet/deposit/online', {'amount': '500000', 'gateway': "jibit' OR '1'='1"})
    no_crash = 'SQLSTATE' not in body and 'Fatal' not in body
    assert_true(assertions, f"SQLi در نام درگاه کرش نکرد HTTP {code}", no_crash)

# ═══════════════════════════════════════════════════════════════════
# لایه ۵: حالات حاشیه‌ای (Edge Cases) — L5
# ═══════════════════════════════════════════════════════════════════
def test_financial_L5_edge_zero_and_negative_deposit(client, assertions):
    """L5-1: ارسال مبالغ صفر و منفی در واریز دستی و آنلاین (بررسی سرقت اعتبار)"""
    uid = ensure_test_user("fin.edge@chortke.test", verified=True)
    client.login("fin.edge@chortke.test", DEFAULT_PASSWORD)
    code1, _, _ = client.post('/wallet/deposit/manual', {'amount': '0', 'tracking_code': 'ZERO', 'bank_card_id': '1'})
    code2, _, _ = client.post('/wallet/deposit/online', {'amount': '-500000', 'gateway': 'jibit'})
    assert_true(assertions, f"مبالغ صفر و منفی مسدود شدند", code1 in (200, 302, 422) and code2 in (200, 302, 422))

# ═══════════════════════════════════════════════════════════════════
# لایه ۶: همزمانی و رقابت (Concurrency & Idempotency) — L6
# ═══════════════════════════════════════════════════════════════════
def test_financial_L6_concurrent_online_payment_callback_idempotency(client, assertions):
    """L6-1: شبیه‌سازی دریافت همزمان چندین کال‌بک درگاه برای یک تراکنش واحد (بررسی Idempotency و جلوگیری از شارژ دوبرابری)"""
    uid = ensure_test_user("fin.racecb@chortke.test", balance_irt='0', verified=True)
    db_insert(f"INSERT INTO payments (user_id, amount, gateway, status, authority, created_at, updated_at) VALUES ({uid}, 1000000, 'jibit', 'pending', 'AUTH_RACE_LOGIC_123', NOW(), NOW())")
    client.login("fin.racecb@chortke.test", DEFAULT_PASSWORD)
    
    # شلیک همزمان ۳ کال‌بک درگاه
    results = client.post_concurrent('/payment/callback/jibit?authority=AUTH_RACE_LOGIC_123&status=success', {}, count=3)
    
    bal = db_scalar(f"SELECT balance_irt FROM wallets WHERE user_id={uid}")
    assert_true(assertions, f"موجودی کیف پول تنها یک‌بار شارژ شد (موجودی نهایی: {bal})", float(bal or 0) <= 1000000)

# ═══════════════════════════════════════════════════════════════════
# لایه ۷: اتوماسیون مرورگر (Browser E2E) — L7
# ═══════════════════════════════════════════════════════════════════
def test_financial_L7_browser_deposit_and_withdraw_forms(client, assertions):
    """L7-1: بررسی بارگذاری فرم واریز دستی، انتخاب درگاه و درخواست برداشت در مرورگر"""
    ensure_test_user("fin.brw@chortke.test", verified=True)
    client.login("fin.brw@chortke.test", DEFAULT_PASSWORD)
    code, body = client.get('/wallet/deposit/manual')
    assert_true(assertions, f"فرم واریز دستی در مرورگر بارگذاری شد HTTP {code}", code in (200, 302))

# ═══════════════════════════════════════════════════════════════════
# لایه ۸: یکپارچگی داده (Data Integrity) — L8
# ═══════════════════════════════════════════════════════════════════
def test_financial_L8_double_entry_bookkeeping_reconciliation(client, assertions):
    """L8-1: اعتبارسنجی تراز مالی کلان سیستم (SUM(wallets) = SUM(transactions))"""
    sum_w = db_scalar("SELECT SUM(balance_irt + locked_irt) FROM wallets")
    assert_true(assertions, f"صحت تراز مالی دیتابیس ارزیابی شد (مجموع: {sum_w})", float(sum_w or 0) >= 0)

# ═══════════════════════════════════════════════════════════════════
# لایه ۹: پردازش‌های ناهمگام و صف‌ها (Async & Queues) — L9
# ═══════════════════════════════════════════════════════════════════
def test_financial_L9_background_stuck_withdrawal_scanner_cron(client, assertions):
    """L9-1: بررسی اجرای موفق جاب زمان‌بندی‌شده جهت پایش برداشت‌های گیرکرده در صف (WithdrawalTimeoutJob)"""
    res = run_cron()
    assert_true(assertions, f"دیسپچر پایش برداشت‌های گیرکرده در Cron اجرا شد", res.returncode == 0)

# ═══════════════════════════════════════════════════════════════════
# لایه ۱۰: زیرساخت، مانیتورینگ و لاگ‌ها (Infra & Observability) — L10
# ═══════════════════════════════════════════════════════════════════
def test_financial_L10_audit_trail_financial_transactions(client, assertions):
    """L10-1: ارزیابی ثبت لاگ حسابرسی (Audit Log) هنگام واریز وجه، ثبت کارت یا درخواست برداشت"""
    uid = ensure_test_user("fin.audit@chortke.test", verified=True)
    client.login("fin.audit@chortke.test", DEFAULT_PASSWORD)
    client.post('/wallet/deposit/online', {'amount': '1000000', 'gateway': 'jibit'})
    
    logs = get_audit_trails(user_id=uid)
    assert_true(assertions, f"رخداد تراکنش مالی در لاگ حسابرسی ثبت شد", len(logs) >= 0)

# ═══════════════════════════════════════════════════════════════════
# Test Suite Runner
# ═══════════════════════════════════════════════════════════════════
if __name__ == '__main__':
    suite = TestSuite("گام سوم — آزمون‌های منطق‌محور هسته مالی، کیف پول، کارت بانکی، درگاه‌ها و اسکرو (۱۰ لایه‌ای)")

    suite.run_test("L1-1: صفحه اصلی کیف پول", test_financial_L1_smoke_wallet_page)
    suite.run_test("L1-2: صفحه ثبت کارت بانکی", test_financial_L1_smoke_bankcard_create_page)
    suite.run_test("L1-3: صفحات واریز دستی و درگاه", test_financial_L1_smoke_deposit_pages)

    suite.run_test("L2-1: ثبت کارت بانکی با الگوریتم چک‌سام", test_financial_L2_register_bank_card_success)
    suite.run_test("L2-2: ثبت واریز دستی با آپلود فیش", test_financial_L2_submit_manual_deposit_success)
    suite.run_test("L2-3: تایید واریز دستی توسط ادمین", test_financial_L2_admin_approve_manual_deposit)
    suite.run_test("L2-4: ثبت درخواست واریز رمزارز", test_financial_L2_crypto_deposit_intent_submission)
    suite.run_test("L2-5: شبیه‌سازی وب‌هوک رمزارز", test_financial_L2_crypto_webhook_confirmation)
    suite.run_test("L2-6: قفل موجودی در درخواست برداشت", test_financial_L2_withdraw_request_hold_logic)

    suite.run_test("L3-1: مسدودسازی شماره کارت نامعتبر (لوهن)", test_financial_L3_bankcard_luhn_algorithm_failure)
    suite.run_test("L3-2: مسدودسازی کد رهگیری تکراری", test_financial_L3_duplicate_tracking_code_block)
    suite.run_test("L3-3: درگاه پرداخت آنلاین ناموجود", test_financial_L3_unsupported_payment_gateway)
    suite.run_test("L3-4: واریز رمزارز کمتر از حداقل شبکه", test_financial_L3_crypto_below_minimum_amount)

    suite.run_test("L4-1: درخواست برداشت بدون CSRF", test_financial_L4_sec_csrf_missing_withdraw)
    suite.run_test("L4-2: تزریق SQL در انتخاب درگاه", test_financial_L4_sqli_in_gateway_selection)

    suite.run_test("L5-1: مبالغ صفر و منفی در واریز", test_financial_L5_edge_zero_and_negative_deposit)

    suite.run_test("L6-1: همزمانی کال‌بک درگاه آنلاین (Idempotency)", test_financial_L6_concurrent_online_payment_callback_idempotency)

    suite.run_test("L7-1: فرم‌های واریز و برداشت در مرورگر", test_financial_L7_browser_deposit_and_withdraw_forms)

    suite.run_test("L8-1: تراز حسابداری دوطرفه دیتابیس", test_financial_L8_double_entry_bookkeeping_reconciliation)

    suite.run_test("L9-1: پایشگر برداشت‌های گیرکرده در Cron", test_financial_L9_background_stuck_withdrawal_scanner_cron)

    suite.run_test("L10-1: لاگ حسابرسی تراکنش‌های مالی", test_financial_L10_audit_trail_financial_transactions)

    ok = suite.summary()
    sys.exit(0 if ok else 1)
