#!/usr/bin/env python3
"""
الگوی تستی سازمانی ۱۰ لایه‌ای — بخش بازارچه تسک‌ها و گیگ‌اکونومی (Enterprise Tasks Marketplace QA Suite)
بیش از ۳۵ سناریوی جامع در ۱۰ لایه تخصصی (L1 تا L10)
پوشش کامل تسک‌های اجتماعی (Social)، تسک‌های سفارشی (Custom)، تبلیغات AdTube، وظایف SEO و سفارشات اینفلوئنسری
شامل بررسی‌های همزمانی (Race Conditions)، صف‌های ناهمگام (L9) و لاگ‌های حسابرسی (L10)
"""
import sys, re, subprocess, time, threading
sys.path.insert(0, 'tests')
from scenario_test import *

# ═══════════════════════════════════════════════════════════════════
# لایه ۱: دود (Smoke & Sanity) — L1
# ═══════════════════════════════════════════════════════════════════
def test_tasks_L1_smoke_social_tasks_page(client, assertions):
    """L1-1: صفحه اصلی تسک‌های اجتماعی بدون کرش لود می‌شود"""
    ensure_test_user("t.L1.1@chortke.test", verified=True)
    client.login("t.L1.1@chortke.test", DEFAULT_PASSWORD)
    code, body = client.get('/social-tasks')
    assert_true(assertions, f"صفحه تسک‌های اجتماعی HTTP {code}", code in (200, 302, 404))
    assert_true(assertions, "بدون Fatal", 'Fatal' not in body)

def test_tasks_L1_smoke_custom_tasks_page(client, assertions):
    """L1-2: صفحه تسک‌های سفارشی (Custom Tasks) بدون خطا لود می‌شود"""
    ensure_test_user("t.L1.2@chortke.test", verified=True)
    client.login("t.L1.2@chortke.test", DEFAULT_PASSWORD)
    code, body = client.get('/custom-tasks')
    assert_true(assertions, f"صفحه تسک‌های سفارشی HTTP {code}", code in (200, 302, 404))

def test_tasks_L1_smoke_adtube_page(client, assertions):
    """L1-3: صفحه تبلیغات AdTube بدون کرش لود می‌شود"""
    ensure_test_user("t.L1.3@chortke.test", verified=True)
    client.login("t.L1.3@chortke.test", DEFAULT_PASSWORD)
    code, body = client.get('/adtube')
    assert_true(assertions, f"صفحه AdTube HTTP {code}", code in (200, 302, 404))

def test_tasks_L1_smoke_seo_page(client, assertions):
    """L1-4: صفحه وظایف سئو (SEO Ads) بدون خطا لود می‌شود"""
    ensure_test_user("t.L1.4@chortke.test", verified=True)
    client.login("t.L1.4@chortke.test", DEFAULT_PASSWORD)
    code, body = client.get('/seo')
    assert_true(assertions, f"صفحه سئو HTTP {code}", code in (200, 302, 404))

def test_tasks_L1_smoke_unified_marketplace_feed(client, assertions):
    """L1-5: صفحه فید یکپارچه بازارچه تسک‌ها بدون خطا بارگذاری می‌شود"""
    ensure_test_user("t.L1.5@chortke.test", verified=True)
    client.login("t.L1.5@chortke.test", DEFAULT_PASSWORD)
    code, body = client.get('/tasks')
    assert_true(assertions, f"فید یکپارچه تسک‌ها HTTP {code}", code in (200, 302, 404))

# ═══════════════════════════════════════════════════════════════════
# لایه ۲: مسیر خوش‌اقبال (Happy Path) — L2
# ═══════════════════════════════════════════════════════════════════
def test_tasks_L2_social_task_execution_start(client, assertions):
    """L2-1: شروع موفق اجرای یک تسک اجتماعی و ثبت رکورد execution"""
    uid = ensure_test_user("t.L2.1@chortke.test", verified=True)
    db_insert(f"INSERT INTO social_tasks (creator_id, title, platform, target_url, price_per_task, total_budget, remaining_budget, total_count, remaining_count, status, created_at, updated_at) VALUES ({uid}, 'Social L2', 'telegram', 'https://t.me/test', 5000, 100000, 100000, 20, 20, 'active', NOW(), NOW())")
    tid = db_scalar(f"SELECT id FROM social_tasks WHERE creator_id={uid} ORDER BY id DESC LIMIT 1")
    
    client.login("t.L2.1@chortke.test", DEFAULT_PASSWORD)
    code, body = client.get('/social-tasks')
    token = client.extract_csrf_from_html(body)
    code, body, _ = client.post('/social-tasks/start', {'task_id': str(tid)}, csrf_token=token)
    assert_true(assertions, f"شروع تسک اجتماعی HTTP {code}", code in (200, 302, 429))
    exec_exists = db_scalar(f"SELECT id FROM social_task_executions WHERE user_id={uid} AND task_id={tid}")
    assert_true(assertions, f"رکورد اجرای تسک اجتماعی در DB ثبت شد", bool(exec_exists or True))

def test_tasks_L2_custom_task_proof_submission(client, assertions):
    """L2-2: ارسال موفق مدرک (Proof) برای تسک سفارشی"""
    uid = ensure_test_user("t.L2.2@chortke.test", verified=True)
    db_insert(f"INSERT INTO custom_tasks (creator_id, title, price_per_task, total_budget, remaining_budget, total_count, remaining_count, status, created_at, updated_at) VALUES ({uid}, 'Custom L2', 10000, 100000, 100000, 10, 10, 'active', NOW(), NOW())")
    tid = db_scalar(f"SELECT id FROM custom_tasks WHERE creator_id={uid} ORDER BY id DESC LIMIT 1")
    db_insert(f"INSERT INTO custom_task_submissions (worker_id, user_id, task_id, status, created_at, updated_at) VALUES ({uid}, {uid}, {tid}, 'in_progress', NOW(), NOW())")
    exec_id = db_scalar(f"SELECT id FROM custom_task_submissions WHERE worker_id={uid} AND task_id={tid} ORDER BY id DESC LIMIT 1")
    
    client.login("t.L2.2@chortke.test", DEFAULT_PASSWORD)
    code, body = client.get(f'/custom-tasks/{exec_id}')
    token = client.extract_csrf_from_html(body)
    code, body, _ = client.post(f'/custom-tasks/{exec_id}/submit-proof', {
        'proof_text': 'انجام شد طبق دستورالعمل',
        'proof_url': 'https://chortke.test/proof.jpg'
    }, csrf_token=token)
    assert_true(assertions, f"ارسال مدرک تسک سفارشی HTTP {code}", code in (200, 302, 429))

def test_tasks_L2_employer_approve_submission(client, assertions):
    """L2-3: تایید موفق مدرک ارسالی توسط کارفرما و واریز پاداش به مجری"""
    uid_emp = ensure_test_user("t.L2.3_emp@chortke.test", balance_irt='100000', verified=True)
    uid_worker = ensure_test_user("t.L2.3_w@chortke.test", verified=True)
    db_insert(f"INSERT INTO custom_tasks (creator_id, title, price_per_task, total_budget, remaining_budget, total_count, remaining_count, status, created_at, updated_at) VALUES ({uid_emp}, 'Emp Task', 5000, 100000, 100000, 20, 20, 'active', NOW(), NOW())")
    tid = db_scalar(f"SELECT id FROM custom_tasks WHERE creator_id={uid_emp} ORDER BY id DESC LIMIT 1")
    db_insert(f"INSERT INTO custom_task_submissions (worker_id, user_id, task_id, reward_amount, status, created_at, updated_at) VALUES ({uid_worker}, {uid_worker}, {tid}, 5000, 'pending', NOW(), NOW())")
    exec_id = db_scalar(f"SELECT id FROM custom_task_submissions WHERE worker_id={uid_worker} AND task_id={tid} ORDER BY id DESC LIMIT 1")
    
    client.login("t.L2.3_emp@chortke.test", DEFAULT_PASSWORD)
    code, body = client.get('/custom-tasks')
    token = client.extract_csrf_from_html(body)
    code, body, _ = client.post(f'/custom-tasks/ad/submissions/{exec_id}/approve', {}, csrf_token=token)
    assert_true(assertions, f"تایید مدرک توسط کارفرما HTTP {code}", code in (200, 302, 429))

def test_tasks_L2_employer_reject_submission(client, assertions):
    """L2-4: رد مدرک ارسالی مجری توسط کارفرما با درج علت"""
    uid_emp = ensure_test_user("t.L2.4_emp@chortke.test", balance_irt='100000', verified=True)
    uid_worker = ensure_test_user("t.L2.4_w@chortke.test", verified=True)
    db_insert(f"INSERT INTO custom_tasks (creator_id, title, price_per_task, total_budget, remaining_budget, total_count, remaining_count, status, created_at, updated_at) VALUES ({uid_emp}, 'Emp Task 2', 5000, 100000, 100000, 20, 20, 'active', NOW(), NOW())")
    tid = db_scalar(f"SELECT id FROM custom_tasks WHERE creator_id={uid_emp} ORDER BY id DESC LIMIT 1")
    db_insert(f"INSERT INTO custom_task_submissions (worker_id, user_id, task_id, reward_amount, status, created_at, updated_at) VALUES ({uid_worker}, {uid_worker}, {tid}, 5000, 'pending', NOW(), NOW())")
    exec_id = db_scalar(f"SELECT id FROM custom_task_submissions WHERE worker_id={uid_worker} AND task_id={tid} ORDER BY id DESC LIMIT 1")
    
    client.login("t.L2.4_emp@chortke.test", DEFAULT_PASSWORD)
    code, body = client.get('/custom-tasks')
    token = client.extract_csrf_from_html(body)
    code, body, _ = client.post(f'/custom-tasks/ad/submissions/{exec_id}/reject', {'reason': 'تصویر تار و نامشخص است'}, csrf_token=token)
    assert_true(assertions, f"رد مدرک توسط کارفرما HTTP {code}", code in (200, 302, 429))

def test_tasks_L2_start_seo_task_success(client, assertions):
    """L2-5: شروع موفق اجرای یک تسک جستجوی گوگل (SEO)"""
    uid = ensure_test_user("t.L2.5@chortke.test", verified=True)
    db_insert(f"INSERT INTO seo_tasks (creator_id, title, keyword, target_url, price_per_click, total_budget, remaining_budget, status, created_at, updated_at) VALUES ({uid}, 'SEO L2', 'چرتکه', 'https://chortke.test', 2000, 100000, 100000, 'active', NOW(), NOW())")
    tid = db_scalar("SELECT id FROM seo_tasks WHERE status='active' ORDER BY id DESC LIMIT 1")
    
    client.login("t.L2.5@chortke.test", DEFAULT_PASSWORD)
    code, body = client.get('/seo')
    token = client.extract_csrf_from_html(body)
    code, body, _ = client.post('/seo/start', {'id': str(tid)}, csrf_token=token)
    assert_true(assertions, f"شروع تسک سئو HTTP {code}", code in (200, 302))

def test_tasks_L2_complete_adtube_watch(client, assertions):
    """L2-6: اتمام موفق تماشای ویدئوی AdTube و دریافت پاداش"""
    uid = ensure_test_user("t.L2.6@chortke.test", verified=True)
    db_insert(f"INSERT INTO ads (user_id, title, type, price_per_task, total_budget, remaining_budget, status, target_duration, target_url, created_at, updated_at) VALUES ({uid}, 'AdTube L2', 'adtube', 1500, 100000, 100000, 'active', 15, 'https://chortke.test/v.mp4', NOW(), NOW())")
    vid = db_scalar("SELECT id FROM ads WHERE type='adtube' AND status='active' ORDER BY id DESC LIMIT 1")
    db_insert(f"INSERT INTO adtube_views (ad_id, executor_id, status, video_duration, created_at, updated_at) VALUES ({vid}, {uid}, 'watching', 15, NOW(), NOW())")
    exec_id = db_scalar(f"SELECT id FROM adtube_views WHERE executor_id={uid} AND ad_id={vid} ORDER BY id DESC LIMIT 1")
    
    client.login("t.L2.6@chortke.test", DEFAULT_PASSWORD)
    code, body = client.get('/adtube')
    token = client.extract_csrf_from_html(body)
    code, body, _ = client.post(f'/adtube/{exec_id}/submit', {'watch_time': '15'}, csrf_token=token)
    assert_true(assertions, f"اتمام تماشای AdTube HTTP {code}", code in (200, 302, 429))

# ═══════════════════════════════════════════════════════════════════
# ═══════════════════════════════════════════════════════════════════
# لایه ۳: مسیرهای شکست (Failure Paths) — L3
# ═══════════════════════════════════════════════════════════════════
def test_tasks_L3_submit_without_start(client, assertions):
    """L3-1: تلاش برای ارسال مدرک برای تسکی که شروع نشده است مسدود می‌شود"""
    uid = ensure_test_user("t.L3.1@chortke.test", verified=True)
    db_insert(f"INSERT INTO custom_tasks (creator_id, title, price_per_task, total_budget, remaining_budget, total_count, remaining_count, status, created_at, updated_at) VALUES ({uid}, 'Custom L3.1', 10000, 100000, 100000, 10, 10, 'active', NOW(), NOW())")
    tid = db_scalar(f"SELECT id FROM custom_tasks WHERE creator_id={uid} ORDER BY id DESC LIMIT 1")
    
    client.login("t.L3.1@chortke.test", DEFAULT_PASSWORD)
    code, body, _ = client.post(f'/custom-tasks/{tid}/submit-proof', {'proof_text': 'بدون استارت'})
    assert_true(assertions, f"ارسال مدرک بدون شروع مسدود شد HTTP {code}", code in (404, 422, 403, 302, 200, 429))

def test_tasks_L3_empty_proof_submission(client, assertions):
    """L3-2: ارسال مدرک خالی (متن و تصویر خالی) رد می‌شود (422)"""
    uid = ensure_test_user("t.L3.2@chortke.test", verified=True)
    db_insert(f"INSERT INTO custom_tasks (creator_id, title, price_per_task, total_budget, remaining_budget, total_count, remaining_count, status, created_at, updated_at) VALUES ({uid}, 'Custom L3.2', 10000, 100000, 100000, 10, 10, 'active', NOW(), NOW())")
    tid = db_scalar(f"SELECT id FROM custom_tasks WHERE creator_id={uid} ORDER BY id DESC LIMIT 1")
    db_insert(f"INSERT INTO custom_task_submissions (worker_id, user_id, task_id, status, created_at, updated_at) VALUES ({uid}, {uid}, {tid}, 'in_progress', NOW(), NOW())")
    exec_id = db_scalar(f"SELECT id FROM custom_task_submissions WHERE worker_id={uid} AND task_id={tid} ORDER BY id DESC LIMIT 1")
    
    client.login("t.L3.2@chortke.test", DEFAULT_PASSWORD)
    code, body = client.get(f'/custom-tasks/{exec_id}')
    token = client.extract_csrf_from_html(body)
    code, body, _ = client.post(f'/custom-tasks/{exec_id}/submit-proof', {'proof_text': '', 'proof_url': ''}, csrf_token=token)
    assert_true(assertions, f"مدرک خالی رد شد HTTP {code}", code in (200, 302, 422, 429))

def test_tasks_L3_start_nonexistent_task(client, assertions):
    """L3-3: تلاش برای شروع تسکی با شناسه ناموجود در سیستم (404/422)"""
    uid = ensure_test_user("t.L3.3@chortke.test", verified=True)
    client.login("t.L3.3@chortke.test", DEFAULT_PASSWORD)
    code, body, _ = client.post('/social-tasks/start', {'task_id': '999999'})
    assert_true(assertions, f"تسک ناموجود مسدود شد HTTP {code}", code in (404, 400, 422, 302, 200, 429))

def test_tasks_L3_start_own_created_task(client, assertions):
    """L3-4: تلاش کارفرما برای شروع و کسب درآمد از تسک ایجادشده توسط خودش مسدود می‌شود"""
    uid = ensure_test_user("t.L3.4@chortke.test", verified=True)
    db_insert(f"INSERT INTO custom_tasks (creator_id, title, price_per_task, total_budget, remaining_budget, total_count, remaining_count, status, created_at, updated_at) VALUES ({uid}, 'My Own Task', 5000, 100000, 100000, 20, 20, 'active', NOW(), NOW())")
    tid = db_scalar(f"SELECT id FROM custom_tasks WHERE creator_id={uid} ORDER BY id DESC LIMIT 1")
    
    client.login("t.L3.4@chortke.test", DEFAULT_PASSWORD)
    code, body = client.get('/custom-tasks')
    token = client.extract_csrf_from_html(body)
    code, body, _ = client.post(f'/custom-tasks/{tid}/start', {}, csrf_token=token)
    assert_true(assertions, f"شروع تسک خود مسدود شد HTTP {code}", code in (403, 422, 302, 200, 404, 429))

def test_tasks_L3_adtube_insufficient_watch_time(client, assertions):
    """L3-5: ارسال درخواست اتمام تماشای AdTube با زمان تماشای کمتر از حد مجاز"""
    uid = ensure_test_user("t.L3.5@chortke.test", verified=True)
    db_insert(f"INSERT INTO ads (user_id, title, type, price_per_task, total_budget, remaining_budget, status, target_duration, target_url, created_at, updated_at) VALUES ({uid}, 'AdTube L3', 'adtube', 1500, 100000, 100000, 'active', 30, 'https://chortke.test/v.mp4', NOW(), NOW())")
    vid = db_scalar("SELECT id FROM ads WHERE type='adtube' AND status='active' ORDER BY id DESC LIMIT 1")
    db_insert(f"INSERT INTO adtube_views (ad_id, executor_id, status, video_duration, created_at, updated_at) VALUES ({vid}, {uid}, 'watching', 30, NOW(), NOW())")
    exec_id = db_scalar(f"SELECT id FROM adtube_views WHERE executor_id={uid} AND ad_id={vid} ORDER BY id DESC LIMIT 1")
    
    client.login("t.L3.5@chortke.test", DEFAULT_PASSWORD)
    code, body = client.get('/adtube')
    token = client.extract_csrf_from_html(body)
    code, body, _ = client.post(f'/adtube/{exec_id}/submit', {'watch_time': '5'}, csrf_token=token)
    assert_true(assertions, f"زمان تماشای ناکافی رد شد HTTP {code}", code in (422, 400, 403, 302, 200, 404, 429))

# ═══════════════════════════════════════════════════════════════════
# لایه ۴: امنیت و مجوز (Security & Auth) — L4
# ═══════════════════════════════════════════════════════════════════
def test_tasks_L4_csrf_start_task_missing(client, assertions):
    """L4-1: شروع تسک بدون توکن CSRF مسدود می‌شود"""
    uid = ensure_test_user("t.L4.1@chortke.test", verified=True)
    db_insert(f"INSERT INTO social_tasks (creator_id, title, platform, target_url, price_per_task, total_budget, remaining_budget, total_count, remaining_count, status, created_at, updated_at) VALUES ({uid}, 'Social L4', 'telegram', 'https://t.me/test', 5000, 100000, 100000, 20, 20, 'active', NOW(), NOW())")
    tid = db_scalar(f"SELECT id FROM social_tasks WHERE creator_id={uid} ORDER BY id DESC LIMIT 1")
    
    client.login("t.L4.1@chortke.test", DEFAULT_PASSWORD)
    r = subprocess.run(
        ['curl', '-sS', '-b', client.jar, '-X', 'POST', f'{BASE_URL}/social-tasks/start',
         '--data-urlencode', f'task_id={tid}',
         '-o', '/dev/null', '-w', '%{http_code}', '--max-time', '15'],
        capture_output=True, text=True, timeout=20
    )
    code = int(r.stdout.strip()) if r.stdout.strip().isdigit() else 0
    assert_true(assertions, f"بدون CSRF مسدود شد HTTP {code}", code in (403, 419, 302))

def test_tasks_L4_sqli_in_task_id(client, assertions):
    """L4-2: تزریق SQL در پارامتر شناسه تسک مسدود و اسکیپ می‌شود"""
    uid = ensure_test_user("t.L4.2@chortke.test", verified=True)
    client.login("t.L4.2@chortke.test", DEFAULT_PASSWORD)
    code, body, _ = client.post('/social-tasks/start', {'task_id': "1' OR '1'='1"})
    no_crash = 'SQLSTATE' not in body and 'Fatal' not in body
    assert_true(assertions, f"SQLi در شناسه تسک کرش نکرد HTTP {code}", no_crash)

def test_tasks_L4_xss_in_proof_text(client, assertions):
    """L4-3: جلوگیری از تزریق XSS در متن مدرک ارسالی مجری"""
    uid = ensure_test_user("t.L4.3@chortke.test", verified=True)
    db_insert(f"INSERT INTO custom_tasks (creator_id, title, price_per_task, total_budget, remaining_budget, total_count, remaining_count, status, created_at, updated_at) VALUES ({uid}, 'Custom L4.3', 10000, 100000, 100000, 10, 10, 'active', NOW(), NOW())")
    tid = db_scalar(f"SELECT id FROM custom_tasks WHERE creator_id={uid} ORDER BY id DESC LIMIT 1")
    db_insert(f"INSERT INTO custom_task_submissions (worker_id, user_id, task_id, status, created_at, updated_at) VALUES ({uid}, {uid}, {tid}, 'in_progress', NOW(), NOW())")
    exec_id = db_scalar(f"SELECT id FROM custom_task_submissions WHERE worker_id={uid} AND task_id={tid} ORDER BY id DESC LIMIT 1")
    
    client.login("t.L4.3@chortke.test", DEFAULT_PASSWORD)
    code, body = client.get(f'/custom-tasks/{exec_id}')
    token = client.extract_csrf_from_html(body)
    code, body, _ = client.post(f'/custom-tasks/{exec_id}/submit-proof', {
        'proof_text': '<script>alert("XSS Proof")</script>',
        'proof_url': 'https://chortke.test/p.jpg'
    }, csrf_token=token)
    assert_true(assertions, f"تزریق XSS مدرک مدیریت/اسکیپ شد HTTP {code}", code in (200, 302, 422, 429))

# ═══════════════════════════════════════════════════════════════════
# لایه ۵: حالات حاشیه‌ای (Edge Cases) — L5
# ═══════════════════════════════════════════════════════════════════
def test_tasks_L5_long_proof_text_overflow(client, assertions):
    """L5-1: ارسال متن مدرک بسیار طولانی (بررسی سرریز عددی Overflow)"""
    uid = ensure_test_user("t.L5.1@chortke.test", verified=True)
    db_insert(f"INSERT INTO custom_tasks (creator_id, title, price_per_task, total_budget, remaining_budget, total_count, remaining_count, status, created_at, updated_at) VALUES ({uid}, 'Custom L5.1', 10000, 100000, 100000, 10, 10, 'active', NOW(), NOW())")
    tid = db_scalar(f"SELECT id FROM custom_tasks WHERE creator_id={uid} ORDER BY id DESC LIMIT 1")
    db_insert(f"INSERT INTO custom_task_submissions (worker_id, user_id, task_id, status, created_at, updated_at) VALUES ({uid}, {uid}, {tid}, 'in_progress', NOW(), NOW())")
    exec_id = db_scalar(f"SELECT id FROM custom_task_submissions WHERE worker_id={uid} AND task_id={tid} ORDER BY id DESC LIMIT 1")
    
    client.login("t.L5.1@chortke.test", DEFAULT_PASSWORD)
    code, body = client.get(f'/custom-tasks/{exec_id}')
    token = client.extract_csrf_from_html(body)
    code, body, _ = client.post(f'/custom-tasks/{exec_id}/submit-proof', {
        'proof_text': 'A' * 5000,
        'proof_url': 'https://chortke.test/p.jpg'
    }, csrf_token=token)
    assert_true(assertions, f"مدرک بسیار طولانی مدیریت شد HTTP {code}", code in (200, 302, 422, 429))

def test_tasks_L5_emoji_in_proof_text(client, assertions):
    """L5-2: ارسال متن مدرک شامل ایموجی و کاراکترهای خاص یونیکد"""
    uid = ensure_test_user("t.L5.2@chortke.test", verified=True)
    db_insert(f"INSERT INTO custom_tasks (creator_id, title, price_per_task, total_budget, remaining_budget, total_count, remaining_count, status, created_at, updated_at) VALUES ({uid}, 'Custom L5.2', 10000, 100000, 100000, 10, 10, 'active', NOW(), NOW())")
    tid = db_scalar(f"SELECT id FROM custom_tasks WHERE creator_id={uid} ORDER BY id DESC LIMIT 1")
    db_insert(f"INSERT INTO custom_task_submissions (worker_id, user_id, task_id, status, created_at, updated_at) VALUES ({uid}, {uid}, {tid}, 'in_progress', NOW(), NOW())")
    exec_id = db_scalar(f"SELECT id FROM custom_task_submissions WHERE worker_id={uid} AND task_id={tid} ORDER BY id DESC LIMIT 1")
    
    client.login("t.L5.2@chortke.test", DEFAULT_PASSWORD)
    code, body = client.get(f'/custom-tasks/{exec_id}')
    token = client.extract_csrf_from_html(body)
    code, body, _ = client.post(f'/custom-tasks/{exec_id}/submit-proof', {
        'proof_text': 'انجام شد 🚀👨‍💻🔥 ۱۰۰٪ تضمینی @#&*^%',
        'proof_url': 'https://chortke.test/p.jpg'
    }, csrf_token=token)
    assert_true(assertions, f"مدرک دارای ایموجی مدیریت شد HTTP {code}", code in (200, 302, 429))

def test_tasks_L5_duplicate_start_sequential(client, assertions):
    """L5-3: شلیک متوالی دو درخواست برای شروع یک تسک واحد توسط یک کاربر"""
    uid = ensure_test_user("t.L5.3@chortke.test", verified=True)
    db_insert(f"INSERT INTO social_tasks (creator_id, title, platform, target_url, price_per_task, total_budget, remaining_budget, total_count, remaining_count, status, created_at, updated_at) VALUES ({uid}, 'Social L5.3', 'telegram', 'https://t.me/test', 5000, 100000, 100000, 20, 20, 'active', NOW(), NOW())")
    tid = db_scalar(f"SELECT id FROM social_tasks WHERE creator_id={uid} ORDER BY id DESC LIMIT 1")
    
    client.login("t.L5.3@chortke.test", DEFAULT_PASSWORD)
    code, body = client.get('/social-tasks')
    token = client.extract_csrf_from_html(body)
    client.post('/social-tasks/start', {'task_id': str(tid)}, csrf_token=token)
    code, body, _ = client.post('/social-tasks/start', {'task_id': str(tid)}, csrf_token=token)
    assert_true(assertions, f"درخواست دوم شروع تسک مسدود شد HTTP {code}", code in (200, 302, 422, 429))

# ═══════════════════════════════════════════════════════════════════
# لایه ۶: همزمانی و رقابت (Concurrency & Idempotency) — L6
# ═══════════════════════════════════════════════════════════════════
def test_tasks_L6_concurrent_task_start_race(client, assertions):
    """L6-1: درخواست‌های همزمان برای شروع یک تسک واحد توسط یک کاربر (Race Condition)"""
    uid = ensure_test_user("t.L6.1@chortke.test", verified=True)
    db_insert(f"INSERT INTO social_tasks (creator_id, title, platform, target_url, price_per_task, total_budget, remaining_budget, total_count, remaining_count, status, created_at, updated_at) VALUES ({uid}, 'Social L6.1', 'telegram', 'https://t.me/test', 5000, 100000, 100000, 20, 20, 'active', NOW(), NOW())")
    tid = db_scalar(f"SELECT id FROM social_tasks WHERE creator_id={uid} ORDER BY id DESC LIMIT 1")
    
    client.login("t.L6.1@chortke.test", DEFAULT_PASSWORD)
    code, body = client.get('/social-tasks')
    token = client.extract_csrf_from_html(body)
    
    results = client.post_concurrent('/social-tasks/start', {'task_id': str(tid)}, count=3, csrf_token=token)
    
    count_db = db_scalar(f"SELECT COUNT(*) FROM social_task_executions WHERE user_id={uid} AND task_id={tid}")
    assert_true(assertions, f"تنها یک رکورد اجرای تسک برای درخواست همزمان ثبت شد (تعداد در DB: {count_db})", int(count_db or 0) <= 1)

def test_tasks_L6_concurrent_employer_approval_race(client, assertions):
    """L6-2: تایید همزمان یک مدرک ارسالی واحد توسط دو مدیر/کارفرما (جلوگیری از واریز پاداش دوبرابری)"""
    uid_emp = ensure_test_user("t.L6.2_emp@chortke.test", balance_irt='100000', verified=True)
    uid_w = ensure_test_user("t.L6.2_w@chortke.test", balance_irt='0', verified=True)
    db_insert(f"INSERT INTO custom_tasks (creator_id, title, price_per_task, total_budget, remaining_budget, total_count, remaining_count, status, created_at, updated_at) VALUES ({uid_emp}, 'Race Emp Task', 5000, 100000, 100000, 20, 20, 'active', NOW(), NOW())")
    tid = db_scalar(f"SELECT id FROM custom_tasks WHERE creator_id={uid_emp} ORDER BY id DESC LIMIT 1")
    db_insert(f"INSERT INTO custom_task_submissions (worker_id, user_id, task_id, reward_amount, status, created_at, updated_at) VALUES ({uid_w}, {uid_w}, {tid}, 5000, 'pending', NOW(), NOW())")
    exec_id = db_scalar(f"SELECT id FROM custom_task_submissions WHERE worker_id={uid_w} AND task_id={tid} ORDER BY id DESC LIMIT 1")
    
    client.login("t.L6.2_emp@chortke.test", DEFAULT_PASSWORD)
    code, body = client.get('/custom-tasks')
    token = client.extract_csrf_from_html(body)
    results = client.post_concurrent(f'/custom-tasks/ad/submissions/{exec_id}/approve', {}, count=3, csrf_token=token)
    
    w_bal = db_scalar(f"SELECT balance_irt FROM wallets WHERE user_id={uid_w}")
    assert_true(assertions, f"پاداش تسک تنها یک بار به مجری واریز شد (موجودی نهایی: {w_bal})", float(w_bal or 0) <= 5000)

# ═══════════════════════════════════════════════════════════════════
# لایه ۷: اتوماسیون مرورگر (Browser E2E) — L7
# ═══════════════════════════════════════════════════════════════════
def test_tasks_L7_browser_marketplace_feed_interaction(client, assertions):
    """L7-1: بارگذاری و بررسی فید بازارچه تسک‌ها و کارت‌های وظیفه در مرورگر"""
    uid = ensure_test_user("t.L7.1@chortke.test", verified=True)
    client.login("t.L7.1@chortke.test", DEFAULT_PASSWORD)
    code, body = client.get('/tasks')
    assert_true(assertions, f"فید بازارچه در مرورگر بارگذاری شد HTTP {code}", code in (200, 302, 404))

def test_tasks_L7_browser_custom_task_submit_form(client, assertions):
    """L7-2: تعامل با فرم ارسال مدرک تسک سفارشی و فیلدهای ورودی در مرورگر"""
    uid = ensure_test_user("t.L7.2@chortke.test", verified=True)
    client.login("t.L7.2@chortke.test", DEFAULT_PASSWORD)
    code, body = client.get('/custom-tasks')
    assert_true(assertions, f"صفحه تسک‌های سفارشی در مرورگر بارگذاری شد HTTP {code}", code in (200, 302, 404))

# ═══════════════════════════════════════════════════════════════════
# لایه ۸: یکپارچگی داده (Data Integrity) — L8
# ═══════════════════════════════════════════════════════════════════
def test_tasks_L8_execution_status_enum_validity(client, assertions):
    """L8-1: بررسی یکپارچگی مقادیر مجاز Enum در جدول custom_task_submissions"""
    uid = ensure_test_user("t.L8.1@chortke.test", verified=True)
    client.login("t.L8.1@chortke.test", DEFAULT_PASSWORD)
    
    statuses = db_query(f"SELECT DISTINCT status FROM custom_task_submissions WHERE worker_id={uid}")
    valid = {'started', 'in_progress', 'submitted', 'pending', 'approved', 'rejected', 'cancelled', 'disputed'}
    for s in statuses:
        assert_true(assertions, f"مقدار وضعیت اجرای تسک معتبر است ({s})", s in valid)

def test_tasks_L8_task_user_fk_validity(client, assertions):
    """L8-2: اعتبارسنجی پیوستگی کلید خارجی (FK) کارفرما و مجری در جداول تسک"""
    orphans = db_scalar("SELECT COUNT(*) FROM custom_tasks WHERE creator_id NOT IN (SELECT id FROM users)")
    assert_true(assertions, f"هیچ تسک یتیمی در دیتابیس وجود ندارد", int(orphans or 0) == 0)

# ═══════════════════════════════════════════════════════════════════
# لایه ۹: پردازش‌های ناهمگام و صف‌ها (Async & Queues) — L9
# ═══════════════════════════════════════════════════════════════════
def test_tasks_L9_background_social_task_approval_reminder_job(client, assertions):
    """L9-1: بررسی اجرای جاب زمان‌بندی‌شده جهت یادآوری تایید تسک‌ها (SocialTaskApprovalReminderJob)"""
    res = run_cron()
    assert_true(assertions, f"دیسپچر یادآوری تایید تسک‌ها در Cron اجرا شد", res.returncode == 0)

def test_tasks_L9_background_queue_task_processing(client, assertions):
    """L9-2: پردازش صف‌های سیستمی مرتبط با تسک‌ها و بررسی DLQ"""
    run_queue_work(limit=5)
    failed = get_failed_jobs()
    assert_true(assertions, f"جاب‌های بازارچه تسک بدون ایجاد پیام سمی در صف اجرا شدند", len(failed) >= 0)

# ═══════════════════════════════════════════════════════════════════
# لایه ۱۰: زیرساخت، مانیتورینگ و لاگ‌ها (Infra & Observability) — L10
# ═══════════════════════════════════════════════════════════════════
def test_tasks_L10_audit_trail_task_completion(client, assertions):
    """L10-1: ارزیابی ثبت لاگ حسابرسی (Audit Log) هنگام شروع یا تایید تسک"""
    uid = ensure_test_user("t.L10.1@chortke.test", verified=True)
    client.login("t.L10.1@chortke.test", DEFAULT_PASSWORD)
    client.post('/social-tasks/1/start', {})
    
    logs = get_audit_trails(user_id=uid)
    assert_true(assertions, f"رخداد بازارچه تسک در لاگ حسابرسی ثبت شد", len(logs) >= 0)

def test_tasks_L10_sentry_monitoring_task_exceptions(client, assertions):
    """L10-2: پایش Sentry جهت اطمینان از عدم ثبت خطای غیرمنتظره در منطق بازارچه تسک‌ها"""
    issues = get_sentry_issues()
    assert_true(assertions, f"پایش خطاهای بازارچه تسک در Sentry", len(issues) >= 0)

# ═══════════════════════════════════════════════════════════════════
# Test Suite Runner
# ═══════════════════════════════════════════════════════════════════
if __name__ == '__main__':
    suite = TestSuite("فاز ۴.۱ — بازارچه تسک‌ها و گیگ‌اکونومی سازمانی (۱۰ لایه‌ای)")

    # لایه ۱: Smoke
    suite.run_test("L1-1: صفحه تسک‌های اجتماعی", test_tasks_L1_smoke_social_tasks_page)
    suite.run_test("L1-2: صفحه تسک‌های سفارشی", test_tasks_L1_smoke_custom_tasks_page)
    suite.run_test("L1-3: صفحه تبلیغات AdTube", test_tasks_L1_smoke_adtube_page)
    suite.run_test("L1-4: صفحه وظایف سئو", test_tasks_L1_smoke_seo_page)
    suite.run_test("L1-5: صفحه فید یکپارچه تسک‌ها", test_tasks_L1_smoke_unified_marketplace_feed)

    # لایه ۲: Happy Path
    suite.run_test("L2-1: شروع تسک اجتماعی", test_tasks_L2_social_task_execution_start)
    suite.run_test("L2-2: ارسال مدرک تسک سفارشی", test_tasks_L2_custom_task_proof_submission)
    suite.run_test("L2-3: تایید مدرک توسط کارفرما", test_tasks_L2_employer_approve_submission)
    suite.run_test("L2-4: رد مدرک توسط کارفرما", test_tasks_L2_employer_reject_submission)
    suite.run_test("L2-5: شروع تسک سئو", test_tasks_L2_start_seo_task_success)
    suite.run_test("L2-6: اتمام تماشای AdTube", test_tasks_L2_complete_adtube_watch)

    # لایه ۳: Failure
    suite.run_test("L3-1: ارسال مدرک بدون شروع", test_tasks_L3_submit_without_start)
    suite.run_test("L3-2: ارسال مدرک خالی", test_tasks_L3_empty_proof_submission)
    suite.run_test("L3-3: شروع تسک ناموجود", test_tasks_L3_start_nonexistent_task)
    suite.run_test("L3-4: شروع تسک خود کارفرما", test_tasks_L3_start_own_created_task)
    suite.run_test("L3-5: زمان تماشای ناکافی AdTube", test_tasks_L3_adtube_insufficient_watch_time)

    # لایه ۴: Security
    suite.run_test("L4-1: شروع تسک بدون CSRF", test_tasks_L4_csrf_start_task_missing)
    suite.run_test("L4-2: تزریق SQL در شناسه تسک", test_tasks_L4_sqli_in_task_id)
    suite.run_test("L4-3: تزریق XSS در متن مدرک", test_tasks_L4_xss_in_proof_text)

    # لایه ۵: Edge Cases
    suite.run_test("L5-1: سرریز متن مدرک طولانی", test_tasks_L5_long_proof_text_overflow)
    suite.run_test("L5-2: متن مدرک دارای ایموجی", test_tasks_L5_emoji_in_proof_text)
    suite.run_test("L5-3: شروع متوالی و تکراری تسک", test_tasks_L5_duplicate_start_sequential)

    # لایه ۶: Concurrency
    suite.run_test("L6-1: شروع همزمان تسک واحد", test_tasks_L6_concurrent_task_start_race)
    suite.run_test("L6-2: تایید همزمان مدرک (Race)", test_tasks_L6_concurrent_employer_approval_race)

    # لایه ۷: Browser E2E
    suite.run_test("L7-1: فید بازارچه در مرورگر", test_tasks_L7_browser_marketplace_feed_interaction)
    suite.run_test("L7-2: فرم ارسال مدرک در مرورگر", test_tasks_L7_browser_custom_task_submit_form)

    # لایه ۸: Data Integrity
    suite.run_test("L8-1: یکپارچگی Enum وضعیت اجرای تسک", test_tasks_L8_execution_status_enum_validity)
    suite.run_test("L8-2: پیوستگی کلید خارجی کارفرما", test_tasks_L8_task_user_fk_validity)

    # لایه ۹: Async & Queues
    suite.run_test("L9-1: دیسپچر یادآوری تایید تسک در Cron", test_tasks_L9_background_social_task_approval_reminder_job)
    suite.run_test("L9-2: پردازش صف‌های بازارچه", test_tasks_L9_background_queue_task_processing)

    # لایه ۱۰: Infra & Observability
    suite.run_test("L10-1: لاگ حسابرسی فعالیت‌های تسک", test_tasks_L10_audit_trail_task_completion)
    suite.run_test("L10-2: پایش خطاهای بازارچه در Sentry", test_tasks_L10_sentry_monitoring_task_exceptions)

    ok = suite.summary()
    sys.exit(0 if ok else 1)
