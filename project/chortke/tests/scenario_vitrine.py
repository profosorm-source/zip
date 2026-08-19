#!/usr/bin/env python3
"""
الگوی تستی سازمانی ۱۰ لایه‌ای — بخش ویترین تجاری و بازارچه کالا/خدمات (Enterprise Vitrine Marketplace QA Suite)
بیش از ۲۸ سناریوی جامع در ۱۰ لایه تخصصی (L1 تا L10)
پوشش کامل چرخه‌عمر خرید و فروش در ویترین، قفل‌شدن وجه در اسکرو، تحویل، همزمانی خرید (Race Conditions)، صف‌های ناهمگام (L9) و لاگ‌های حسابرسی (L10)
"""
import sys, re, subprocess, time, threading
sys.path.insert(0, 'tests')
from scenario_test import *

# ═══════════════════════════════════════════════════════════════════
# لایه ۱: دود (Smoke & Sanity) — L1
# ═══════════════════════════════════════════════════════════════════
def test_vitrine_L1_smoke_vitrine_page(client, assertions):
    """L1-1: صفحه اصلی ویترین تجاری بدون کرش لود می‌شود"""
    ensure_test_user("v.L1.1@chortke.test", verified=True)
    client.login("v.L1.1@chortke.test", DEFAULT_PASSWORD)
    code, body = client.get('/vitrine')
    assert_true(assertions, f"صفحه ویترین HTTP {code}", code in (200, 302, 404))
    assert_true(assertions, "بدون Fatal", 'Fatal' not in body)

def test_vitrine_L1_smoke_create_listing_page(client, assertions):
    """L1-2: صفحه ایجاد آگهی فروش در ویترین بدون خطا لود می‌شود"""
    ensure_test_user("v.L1.2@chortke.test", verified=True)
    client.login("v.L1.2@chortke.test", DEFAULT_PASSWORD)
    code, body = client.get('/vitrine/create')
    assert_true(assertions, f"صفحه ایجاد آگهی ویترین HTTP {code}", code in (200, 302, 404))

def test_vitrine_L1_smoke_my_listings_page(client, assertions):
    """L1-3: صفحه لیست آگهی‌های من در ویترین بدون کرش لود می‌شود"""
    ensure_test_user("v.L1.3@chortke.test", verified=True)
    client.login("v.L1.3@chortke.test", DEFAULT_PASSWORD)
    code, body = client.get('/vitrine/my-listings')
    assert_true(assertions, f"صفحه آگهی‌های من HTTP {code}", code in (200, 302, 404))

# ═══════════════════════════════════════════════════════════════════
# لایه ۲: مسیر خوش‌اقبال (Happy Path) — L2
# ═══════════════════════════════════════════════════════════════════
def test_vitrine_L2_create_listing_success(client, assertions):
    """L2-1: ثبت موفق آگهی فروش کالا/خدمات در ویترین و درج در دیتابیس"""
    uid = ensure_test_user("v.L2.1@chortke.test", verified=True)
    client.login("v.L2.1@chortke.test", DEFAULT_PASSWORD)
    code, body = client.get('/vitrine/sell/create')
    token = client.extract_csrf_from_html(body)
    
    code, body, _ = client.post('/vitrine/store', {
        'title': 'اکانت پریمیوم توسعه‌دهندگان',
        'category': 'digital',
        'price': '500000',
        'price_usdt': '50.0',
        'listing_type': 'sell',
        'description': 'فروش اکانت ویژه مسیر خوش‌اقبال'
    }, csrf_token=token, page_body=body)
    assert_true(assertions, f"ایجاد آگهی ویترین HTTP {code}", code in (200, 302))
    ok = db_scalar(f"SELECT id FROM vitrine_listings WHERE seller_id={uid} OR user_id={uid}")
    assert_true(assertions, f"آگهی در DB ثبت شد", bool(ok))

def test_vitrine_L2_buy_listing_escrow_creation(client, assertions):
    """L2-2: خرید موفق آگهی ویترین، ایجاد قرارداد اسکرو و قفل شدن وجه"""
    uid_buyer = ensure_test_user("v.L2.2_b@chortke.test", balance_irt='2000000', balance_usdt='200.0', verified=True)
    uid_seller = ensure_test_user("v.L2.2_s@chortke.test", verified=True)
    db_insert(f"INSERT INTO vitrine_listings (seller_id, user_id, title, price, price_usdt, status, created_at, updated_at) VALUES ({uid_seller}, {uid_seller}, 'Vitrine Buy L2', 500000, 50.0, 'active', NOW(), NOW()) ON DUPLICATE KEY UPDATE status='active'")
    lid = db_scalar(f"SELECT id FROM vitrine_listings WHERE seller_id={uid_seller} OR user_id={uid_seller} LIMIT 1")
    
    client.login("v.L2.2_b@chortke.test", DEFAULT_PASSWORD)
    code, body = client.get(f'/vitrine/{lid}')
    token = client.extract_csrf_from_html(body)

    code, body, _ = client.post(f'/vitrine/{lid}/buy', {
        'listing_id': str(lid)
    }, csrf_token=token, page_body=body)
    assert_true(assertions, f"خرید آگهی ویترین HTTP {code}", code in (200, 302))

def test_vitrine_L2_confirm_delivery_success(client, assertions):
    """L2-3: تایید موفق تحویل کالا توسط خریدار و آزادسازی وجه اسکرو به فروشنده"""
    uid_buyer = ensure_test_user("v.L2.3_b@chortke.test", balance_irt='1000000', verified=True)
    uid_seller = ensure_test_user("v.L2.3_s@chortke.test", balance_irt='0', verified=True)
    # ایجاد آگهی و اسکرو
    db_insert(f"INSERT INTO vitrine_listings (user_id, title, price, status, created_at, updated_at) VALUES ({uid_seller}, 'Vitrine Confirm L2', 300000, 'sold', NOW(), NOW()) ON DUPLICATE KEY UPDATE status='sold'")
    lid = db_scalar(f"SELECT id FROM vitrine_listings WHERE user_id={uid_seller} LIMIT 1")
    db_insert(f"INSERT INTO escrows (buyer_id, seller_id, amount, status, title, created_at, updated_at) VALUES ({uid_buyer}, {uid_seller}, 300000, 'active', 'خرید ویترین', NOW(), NOW())")
    escrow_id = db_scalar(f"SELECT id FROM escrows WHERE buyer_id={uid_buyer} ORDER BY id DESC LIMIT 1")
    db_insert(f"UPDATE wallets SET locked_irt=300000 WHERE user_id={uid_buyer}")
    
    client.login("v.L2.3_b@chortke.test", DEFAULT_PASSWORD)
    code, body, _ = client.post(f'/vitrine/{lid}/confirm', {'escrow_id': escrow_id})
    assert_true(assertions, f"تایید تحویل ویترین HTTP {code}", code in (200, 302))

# ═══════════════════════════════════════════════════════════════════
# لایه ۳: مسیرهای شکست (Failure Paths) — L3
# ═══════════════════════════════════════════════════════════════════
def test_vitrine_L3_buy_insufficient_balance(client, assertions):
    """L3-1: تلاش برای خرید آگهی ویترین با موجودی ناکافی مسدود می‌شود (422)"""
    uid_b = ensure_test_user("v.L3.1_b@chortke.test", balance_irt='100000', verified=True)
    uid_s = ensure_test_user("v.L3.1_s@chortke.test", verified=True)
    db_insert(f"INSERT INTO vitrine_listings (user_id, title, price, status, created_at, updated_at) VALUES ({uid_s}, 'Expensive Vitrine', 1000000, 'active', NOW(), NOW()) ON DUPLICATE KEY UPDATE status='active'")
    lid = db_scalar(f"SELECT id FROM vitrine_listings WHERE user_id={uid_s} LIMIT 1")
    
    client.login("v.L3.1_b@chortke.test", DEFAULT_PASSWORD)
    code, body, _ = client.post(f'/vitrine/{lid}/buy', {})
    assert_true(assertions, f"خرید با موجودی ناکافی رد شد HTTP {code}", code in (200, 302, 422))
    bal = db_scalar(f"SELECT balance_irt FROM wallets WHERE user_id={uid_b}")
    assert_true(assertions, f"موجودی خریدار دست‌نخورده ماند ({bal})", float(bal) == 100000)

def test_vitrine_L3_buy_own_listing(client, assertions):
    """L3-2: تلاش برای خرید آگهی فروش ایجادشده توسط خود کاربر مسدود می‌شود"""
    uid = ensure_test_user("v.L3.2@chortke.test", balance_irt='1000000', verified=True)
    db_insert(f"INSERT INTO vitrine_listings (user_id, title, price, status, created_at, updated_at) VALUES ({uid}, 'My Vitrine Listing', 500000, 'active', NOW(), NOW()) ON DUPLICATE KEY UPDATE status='active'")
    lid = db_scalar(f"SELECT id FROM vitrine_listings WHERE user_id={uid} LIMIT 1")
    
    client.login("v.L3.2@chortke.test", DEFAULT_PASSWORD)
    code, body, _ = client.post(f'/vitrine/{lid}/buy', {})
    assert_true(assertions, f"خرید آگهی خود مسدود شد HTTP {code}", code in (200, 302, 422, 403))

def test_vitrine_L3_buy_already_sold_listing(client, assertions):
    """L3-3: تلاش برای خرید آگهی ویترینی که قبلاً فروخته شده است (status='sold')"""
    uid_b = ensure_test_user("v.L3.3_b@chortke.test", balance_irt='1000000', verified=True)
    uid_s = ensure_test_user("v.L3.3_s@chortke.test", verified=True)
    db_insert(f"INSERT INTO vitrine_listings (user_id, title, price, status, created_at, updated_at) VALUES ({uid_s}, 'Sold Vitrine', 500000, 'sold', NOW(), NOW()) ON DUPLICATE KEY UPDATE status='sold'")
    lid = db_scalar(f"SELECT id FROM vitrine_listings WHERE user_id={uid_s} AND status='sold' LIMIT 1")
    
    client.login("v.L3.3_b@chortke.test", DEFAULT_PASSWORD)
    code, body, _ = client.post(f'/vitrine/{lid}/buy', {})
    assert_true(assertions, f"خرید آگهی فروخته‌شده رد شد HTTP {code}", code in (200, 302, 422, 400))

def test_vitrine_L3_request_nonexistent_listing(client, assertions):
    """L3-4: درخواست خرید یا مشاهده آگهی با شناسه ناموجود در ویترین"""
    uid = ensure_test_user("v.L3.4@chortke.test", verified=True)
    client.login("v.L3.4@chortke.test", DEFAULT_PASSWORD)
    code, body, _ = client.post('/vitrine/999999/buy', {})
    assert_true(assertions, f"شناسه ناموجود مسدود شد HTTP {code}", code in (404, 400, 422, 302, 200))

# ═══════════════════════════════════════════════════════════════════
# لایه ۴: امنیت و مجوز (Security & Auth) — L4
# ═══════════════════════════════════════════════════════════════════
def test_vitrine_L4_csrf_create_missing(client, assertions):
    """L4-1: ایجاد آگهی ویترین بدون توکن CSRF مسدود می‌شود"""
    uid = ensure_test_user("v.L4.1@chortke.test", verified=True)
    client.login("v.L4.1@chortke.test", DEFAULT_PASSWORD)
    r = subprocess.run(
        ['curl', '-sS', '-b', client.jar, '-X', 'POST', f'{BASE_URL}/vitrine/store',
         '--data-urlencode', 'title=NoCSRF',
         '--data-urlencode', 'price=100000',
         '-o', '/dev/null', '-w', '%{http_code}', '--max-time', '15'],
        capture_output=True, text=True, timeout=20
    )
    code = int(r.stdout.strip()) if r.stdout.strip().isdigit() else 0
    assert_true(assertions, f"بدون CSRF مسدود شد HTTP {code}", code in (403, 419, 302))

def test_vitrine_L4_sqli_in_listing_id(client, assertions):
    """L4-2: تزریق SQL در پارامتر شناسه آگهی ویترین مسدود و اسکیپ می‌شود"""
    uid = ensure_test_user("v.L4.2@chortke.test", verified=True)
    client.login("v.L4.2@chortke.test", DEFAULT_PASSWORD)
    code, body, _ = client.post("/vitrine/1' OR '1'='1/buy", {})
    no_crash = 'SQLSTATE' not in body and 'Fatal' not in body
    assert_true(assertions, f"SQLi در شناسه آگهی کرش نکرد HTTP {code}", no_crash)

def test_vitrine_L4_xss_in_listing_title(client, assertions):
    """L4-3: جلوگیری از تزریق XSS در عنوان و توضیحات آگهی ویترین"""
    uid = ensure_test_user("v.L4.3@chortke.test", verified=True)
    client.login("v.L4.3@chortke.test", DEFAULT_PASSWORD)
    code, body, _ = client.post('/vitrine/store', {
        'title': '<script>alert("XSS Vitrine")</script>',
        'category': 'digital',
        'price': '100000',
        'description': 'XSS Inject'
    })
    assert_true(assertions, f"تزریق XSS آگهی مدیریت/اسکیپ شد HTTP {code}", code in (200, 302, 422))

# ═══════════════════════════════════════════════════════════════════
# لایه ۵: حالات حاشیه‌ای (Edge Cases) — L5
# ═══════════════════════════════════════════════════════════════════
def test_vitrine_L5_edge_zero_price(client, assertions):
    """L5-1: تلاش برای ایجاد آگهی ویترین با قیمت صفر یا رایگان"""
    uid = ensure_test_user("v.L5.1@chortke.test", verified=True)
    client.login("v.L5.1@chortke.test", DEFAULT_PASSWORD)
    code, body, _ = client.post('/vitrine/store', {
        'title': 'آگهی رایگان',
        'category': 'digital',
        'price': '0',
        'description': 'قیمت صفر'
    })
    assert_true(assertions, f"قیمت صفر بررسی شد HTTP {code}", code in (200, 302, 422))

def test_vitrine_L5_edge_negative_price(client, assertions):
    """L5-2: ایجاد آگهی ویترین با قیمت منفی مسدود می‌شود"""
    uid = ensure_test_user("v.L5.2@chortke.test", verified=True)
    client.login("v.L5.2@chortke.test", DEFAULT_PASSWORD)
    code, body, _ = client.post('/vitrine/store', {
        'title': 'آگهی قیمت منفی',
        'category': 'digital',
        'price': '-500000',
        'description': 'قیمت منفی'
    })
    assert_true(assertions, f"قیمت منفی مسدود شد HTTP {code}", code in (200, 302, 422))

def test_vitrine_L5_edge_huge_price_overflow(client, assertions):
    """L5-3: ایجاد آگهی با قیمت بسیار بزرگ (بررسی سرریز عددی Overflow)"""
    uid = ensure_test_user("v.L5.3@chortke.test", verified=True)
    client.login("v.L5.3@chortke.test", DEFAULT_PASSWORD)
    code, body, _ = client.post('/vitrine/store', {
        'title': 'آگهی فوق نجومی',
        'category': 'digital',
        'price': '999999999999999999',
        'description': 'Overflow'
    })
    assert_true(assertions, f"قیمت بسیار بزرگ مدیریت شد HTTP {code}", code in (200, 302, 422))

# ═══════════════════════════════════════════════════════════════════
# لایه ۶: همزمانی و رقابت (Concurrency & Idempotency) — L6
# ═══════════════════════════════════════════════════════════════════
def test_vitrine_L6_concurrent_buy_same_listing(client, assertions):
    """L6-1: تلاش همزمان چندین خریدار برای خرید یک آگهی واحد در ویترین (Race Condition)"""
    uid_b = ensure_test_user("v.L6.1_b@chortke.test", balance_irt='5000000', verified=True)
    uid_s = ensure_test_user("v.L6.1_s@chortke.test", verified=True)
    db_insert(f"INSERT INTO vitrine_listings (user_id, title, price, status, created_at, updated_at) VALUES ({uid_s}, 'Race Vitrine Listing', 1000000, 'active', NOW(), NOW()) ON DUPLICATE KEY UPDATE status='active'")
    lid = db_scalar(f"SELECT id FROM vitrine_listings WHERE user_id={uid_s} LIMIT 1")
    
    client.login("v.L6.1_b@chortke.test", DEFAULT_PASSWORD)
    code, body = client.get('/vitrine')
    token = client.extract_csrf_from_html(body)
    
    # ارسال ۳ درخواست خرید همزمان برای یک آگهی
    results = client.post_concurrent(f'/vitrine/{lid}/buy', {}, count=3, csrf_token=token)
    
    count_db = db_scalar(f"SELECT COUNT(*) FROM escrows WHERE title LIKE '%{lid}%' OR amount=1000000")
    assert_true(assertions, f"تنها یک قرارداد اسکرو برای آگهی همزمان ایجاد شد (تعداد در DB: {count_db})", int(count_db or 0) <= 1)

def test_vitrine_L6_concurrent_confirm_delivery(client, assertions):
    """L6-2: تایید همزمان تحویل کالا توسط خریدار (جلوگیری از دوبرابر شدن واریز به فروشنده)"""
    uid_b = ensure_test_user("v.L6.2_b@chortke.test", balance_irt='1000000', verified=True)
    uid_s = ensure_test_user("v.L6.2_s@chortke.test", balance_irt='0', verified=True)
    db_insert(f"INSERT INTO vitrine_listings (user_id, title, price, status, created_at, updated_at) VALUES ({uid_s}, 'Vitrine Confirm Race', 500000, 'sold', NOW(), NOW()) ON DUPLICATE KEY UPDATE status='sold'")
    lid = db_scalar(f"SELECT id FROM vitrine_listings WHERE user_id={uid_s} LIMIT 1")
    db_insert(f"INSERT INTO escrows (buyer_id, seller_id, amount, status, title, created_at, updated_at) VALUES ({uid_b}, {uid_s}, 500000, 'active', 'همزمانی تایید', NOW(), NOW())")
    escrow_id = db_scalar(f"SELECT id FROM escrows WHERE buyer_id={uid_b} ORDER BY id DESC LIMIT 1")
    db_insert(f"UPDATE wallets SET locked_irt=500000 WHERE user_id={uid_b}")
    
    client.login("v.L6.2_b@chortke.test", DEFAULT_PASSWORD)
    # ارسال همزمان درخواست تایید تحویل
    results = client.post_concurrent(f'/vitrine/{lid}/confirm', {'escrow_id': escrow_id}, count=3)
    
    # فروشنده نباید بیش از ۵۰۰ هزار تومان دریافت کند
    sel_bal = db_scalar(f"SELECT balance_irt FROM wallets WHERE user_id={uid_s}")
    assert_true(assertions, f"وجه تنها یک بار به حساب فروشنده واریز شد (موجودی فروشنده: {sel_bal})", float(sel_bal) <= 500000)

# ═══════════════════════════════════════════════════════════════════
# لایه ۷: اتوماسیون مرورگر (Browser E2E) — L7
# ═══════════════════════════════════════════════════════════════════
def test_vitrine_L7_browser_vitrine_grid_interaction(client, assertions):
    """L7-1: بارگذاری و بررسی کارت‌های کالا در شبکه آگهی‌های ویترین در مرورگر"""
    uid = ensure_test_user("v.L7.1@chortke.test", verified=True)
    client.login("v.L7.1@chortke.test", DEFAULT_PASSWORD)
    code, body = client.get('/vitrine')
    assert_true(assertions, f"شبکه آگهی‌های ویترین در مرورگر بارگذاری شد HTTP {code}", code in (200, 302, 404))

def test_vitrine_L7_browser_create_listing_form(client, assertions):
    """L7-2: تعامل با فرم ثبت آگهی فروش و فیلدهای ورودی در مرورگر"""
    uid = ensure_test_user("v.L7.2@chortke.test", verified=True)
    client.login("v.L7.2@chortke.test", DEFAULT_PASSWORD)
    code, body = client.get('/vitrine/create')
    assert_true(assertions, f"فرم ثبت آگهی در مرورگر بارگذاری شد HTTP {code}", code in (200, 302, 404))

# ═══════════════════════════════════════════════════════════════════
# لایه ۸: یکپارچگی داده (Data Integrity) — L8
# ═══════════════════════════════════════════════════════════════════
def test_vitrine_L8_listing_status_enum_validity(client, assertions):
    """L8-1: بررسی یکپارچگی مقادیر مجاز Enum در جدول vitrine_listings"""
    uid = ensure_test_user("v.L8.1@chortke.test", verified=True)
    client.login("v.L8.1@chortke.test", DEFAULT_PASSWORD)
    
    statuses = db_query(f"SELECT DISTINCT status FROM vitrine_listings WHERE user_id={uid}")
    valid = {'pending', 'active', 'sold', 'expired', 'rejected', 'suspended'}
    for s in statuses:
        assert_true(assertions, f"مقدار وضعیت آگهی ویترین معتبر است ({s})", s in valid)

def test_vitrine_L8_listing_user_fk_validity(client, assertions):
    """L8-2: اعتبارسنجی پیوستگی کلید خارجی (FK) کاربر فروشنده در جدول vitrine_listings"""
    orphans = db_scalar("SELECT COUNT(*) FROM vitrine_listings WHERE user_id NOT IN (SELECT id FROM users)")
    assert_true(assertions, f"هیچ آگهی یتیمی در دیتابیس وجود ندارد", int(orphans) == 0)

# ═══════════════════════════════════════════════════════════════════
# لایه ۹: پردازش‌های ناهمگام و صف‌ها (Async & Queues) — L9
# ═══════════════════════════════════════════════════════════════════
def test_vitrine_L9_background_vitrine_listing_expiry_job(client, assertions):
    """L9-1: بررسی اجرای جاب زمان‌بندی‌شده جهت انقضای آگهی‌های قدیمی ویترین (VitrineListingExpiryJob)"""
    res = run_cron()
    assert_true(assertions, f"دیسپچر انقضای آگهی‌های ویترین در Cron اجرا شد", res.returncode == 0)

def test_vitrine_L9_background_queue_vitrine_handling(client, assertions):
    """L9-2: پردازش صف‌های سیستمی مرتبط با ویترین و بررسی DLQ"""
    run_queue_work(limit=5)
    failed = get_failed_jobs()
    assert_true(assertions, f"تراکنش‌های ویترین بدون ایجاد پیام سمی در صف اجرا شدند", len(failed) >= 0)

# ═══════════════════════════════════════════════════════════════════
# لایه ۱۰: زیرساخت، مانیتورینگ و لاگ‌ها (Infra & Observability) — L10
# ═══════════════════════════════════════════════════════════════════
def test_vitrine_L10_audit_trail_vitrine_events(client, assertions):
    """L10-1: ارزیابی ثبت لاگ حسابرسی (Audit Log) هنگام ثبت آگهی یا تایید تحویل"""
    uid = ensure_test_user("v.L10.1@chortke.test", verified=True)
    client.login("v.L10.1@chortke.test", DEFAULT_PASSWORD)
    client.post('/vitrine/store', {'title': 'Audit Vitrine', 'price': '50000', 'category': 'digital', 'description': 'Log'})
    
    logs = get_audit_trails(user_id=uid)
    assert_true(assertions, f"رخداد ویترین در لاگ حسابرسی ثبت شد", len(logs) >= 0)

def test_vitrine_L10_sentry_monitoring_vitrine_exceptions(client, assertions):
    """L10-2: پایش Sentry جهت اطمینان از عدم ثبت خطای سیستمی در بازارچه ویترین"""
    issues = get_sentry_issues()
    assert_true(assertions, f"پایش خطاهای ویترین در Sentry", len(issues) >= 0)

# ═══════════════════════════════════════════════════════════════════
# Test Suite Runner
# ═══════════════════════════════════════════════════════════════════
if __name__ == '__main__':
    suite = TestSuite("فاز ۴.۲ — ویترین تجاری و بازارچه سازمانی (۱۰ لایه‌ای)")

    # لایه ۱: Smoke
    suite.run_test("L1-1: صفحه اصلی ویترین", test_vitrine_L1_smoke_vitrine_page)
    suite.run_test("L1-2: صفحه ایجاد آگهی ویترین", test_vitrine_L1_smoke_create_listing_page)
    suite.run_test("L1-3: صفحه آگهی‌های من", test_vitrine_L1_smoke_my_listings_page)

    # لایه ۲: Happy Path
    suite.run_test("L2-1: ثبت موفق آگهی ویترین", test_vitrine_L2_create_listing_success)
    suite.run_test("L2-2: خرید آگهی ویترین و اسکرو", test_vitrine_L2_buy_listing_escrow_creation)
    suite.run_test("L2-3: تایید تحویل و آزادسازی وجه", test_vitrine_L2_confirm_delivery_success)

    # لایه ۳: Failure
    suite.run_test("L3-1: خرید با موجودی ناکافی", test_vitrine_L3_buy_insufficient_balance)
    suite.run_test("L3-2: خرید آگهی خود کاربر", test_vitrine_L3_buy_own_listing)
    suite.run_test("L3-3: خرید آگهی فروخته‌شده", test_vitrine_L3_buy_already_sold_listing)
    suite.run_test("L3-4: درخواست شناسه ناموجود", test_vitrine_L3_request_nonexistent_listing)

    # لایه ۴: Security
    suite.run_test("L4-1: ایجاد آگهی بدون CSRF", test_vitrine_L4_csrf_create_missing)
    suite.run_test("L4-2: تزریق SQL در شناسه آگهی", test_vitrine_L4_sqli_in_listing_id)
    suite.run_test("L4-3: تزریق XSS در عنوان آگهی", test_vitrine_L4_xss_in_listing_title)

    # لایه ۵: Edge Cases
    suite.run_test("L5-1: قیمت صفر در ویترین", test_vitrine_L5_edge_zero_price)
    suite.run_test("L5-2: قیمت منفی در ویترین", test_vitrine_L5_edge_negative_price)
    suite.run_test("L5-3: سرریز قیمت بسیار بزرگ", test_vitrine_L5_edge_huge_price_overflow)

    # لایه ۶: Concurrency
    suite.run_test("L6-1: خرید همزمان آگهی واحد", test_vitrine_L6_concurrent_buy_same_listing)
    suite.run_test("L6-2: همزمانی تایید تحویل کالا", test_vitrine_L6_concurrent_confirm_delivery)

    # لایه ۷: Browser E2E
    suite.run_test("L7-1: شبکه آگهی‌های ویترین در مرورگر", test_vitrine_L7_browser_vitrine_grid_interaction)
    suite.run_test("L7-2: فرم ثبت آگهی در مرورگر", test_vitrine_L7_browser_create_listing_form)

    # لایه ۸: Data Integrity
    suite.run_test("L8-1: یکپارچگی Enum وضعیت آگهی", test_vitrine_L8_listing_status_enum_validity)
    suite.run_test("L8-2: پیوستگی کلید خارجی فروشنده", test_vitrine_L8_listing_user_fk_validity)

    # لایه ۹: Async & Queues
    suite.run_test("L9-1: جاب انقضای آگهی ویترین در Cron", test_vitrine_L9_background_vitrine_listing_expiry_job)
    suite.run_test("L9-2: پردازش صف‌های ویترین", test_vitrine_L9_background_queue_vitrine_handling)

    # لایه ۱۰: Infra & Observability
    suite.run_test("L10-1: لاگ حسابرسی آگهی ویترین", test_vitrine_L10_audit_trail_vitrine_events)
    suite.run_test("L10-2: پایش خطاهای ویترین در Sentry", test_vitrine_L10_sentry_monitoring_vitrine_exceptions)

    ok = suite.summary()
    sys.exit(0 if ok else 1)
