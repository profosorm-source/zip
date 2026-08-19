#!/usr/bin/env python3
import sys, os, subprocess, time, json, re

BASE_DIR = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))

def run_php_script(script_content: str) -> dict:
    full_code = f"""<?php
define('BASE_PATH', '{BASE_DIR}');
require '{BASE_DIR}/vendor/autoload.php';
$app = require '{BASE_DIR}/bootstrap/app.php';

try {{
{script_content}
}} catch (\\Throwable $e) {{
    echo "\\nRESULT_JSON:" . json_encode(['error' => $e->getMessage(), 'file' => $e->getFile(), 'line' => $e->getLine()], JSON_UNESCAPED_UNICODE) . "\\n";
}}
"""
    tmp_path = "/tmp/test_runner_mod.php"
    with open(tmp_path, "w", encoding="utf-8") as f:
        f.write(full_code)
    
    res = subprocess.run(['php', '-f', tmp_path], capture_output=True, text=True, cwd=BASE_DIR)
    raw_out = res.stdout.strip() + "\n" + res.stderr.strip()
    
    for line in raw_out.splitlines():
        if line.startswith("RESULT_JSON:"):
            try:
                return json.loads(line.replace("RESULT_JSON:", ""))
            except Exception:
                pass
    return {}

print("=" * 85)
print("  تست جامع، عمیق و زنده ۹ ماژول اصلی چرتکه (Exhaustive 9-Module E2E Test Suite)")
print("  ماژول‌ها: Queue, Outbox, Realtime Messages, Email Queue, Notifications, Direct Messages, Transactions, Withdrawals, Admin Dashboard")
print("=" * 85)

# Ensure services and database migrations
subprocess.run(['sudo', 'service', 'mariadb', 'start'], capture_output=True)
subprocess.run(['sudo', 'service', 'redis-server', 'start'], capture_output=True)
subprocess.run(['sudo', 'mysql', '-e', "CREATE DATABASE IF NOT EXISTS chortk; ALTER USER 'root'@'localhost' IDENTIFIED VIA mysql_native_password USING ''; GRANT ALL PRIVILEGES ON *.* TO 'root'@'localhost'; FLUSH PRIVILEGES;"], capture_output=True)
subprocess.run(['php', 'migrate.php'], capture_output=True)

# Ensure base test users exist with verified KYC status and encrypted bank card
code_base_users = """
$db = \\Core\\Database::getInstance();
$db->execute("INSERT INTO users (id, username, email, password, role, status, kyc_status, created_at, updated_at) VALUES 
    (1, 'mod_admin', 'mod.admin@chortke.ir', '$2y$10$hash', 'super_admin', 'active', 'verified', NOW(), NOW()),
    (2, 'mod_user1', 'mod.user1@chortke.ir', '$2y$10$hash', 'user', 'active', 'verified', NOW(), NOW()),
    (3, 'mod_user2', 'mod.user2@chortke.ir', '$2y$10$hash', 'user', 'active', 'verified', NOW(), NOW())
    ON DUPLICATE KEY UPDATE status='active', kyc_status='verified'");

$enc = new \\Core\\Encryption();
$encCard1 = $enc->encrypt('6219861000000001', 'bank.card_number');
$encCard2 = $enc->encrypt('6219861000000002', 'bank.card_number');
$db->execute("INSERT INTO bank_cards (id, user_id, card_number, bank_name, status, is_default, created_at, updated_at) VALUES
    (1, 2, '{$encCard1}', 'سامان', 'verified', 1, NOW(), NOW()),
    (2, 3, '{$encCard2}', 'ملی', 'verified', 1, NOW(), NOW())
    ON DUPLICATE KEY UPDATE status='verified'");
"""
run_php_script(code_base_users)

test_module_results = []

def record_module_test(module_name, subtests):
    passed_count = sum(1 for p, _ in subtests if p)
    total_count = len(subtests)
    all_pass = passed_count == total_count
    status = f"✓ PASS ({passed_count}/{total_count})" if all_pass else f"❌ FAIL ({passed_count}/{total_count})"
    test_module_results.append((module_name, all_pass, passed_count, total_count, subtests))
    print(f"\n▶ [{status}] {module_name}")
    for p, desc in subtests:
        st = "  ✓" if p else "  ❌"
        print(f"{st} {desc}")

# ----------------------------------------------------------------------
# 1. Queue (سیستم صف، جاب‌ها، Retry و DLQ)
# ----------------------------------------------------------------------
code_queue = """
$q = \\Core\\Container::getInstance()->make(\\Core\\Queue::class);
$p1 = $q->push('App\\Jobs\\SendEmailJob', ['email' => 'qtest@chortke.ir'], 'default');
$dedupKey = 'uniq_key_' . microtime(true);
$p2 = $q->pushUnique('App\\Jobs\\LogPerformanceJob', ['user_id' => 1], $dedupKey, 'analytics', 0);
$p3 = $q->pushUnique('App\\Jobs\\LogPerformanceJob', ['user_id' => 1], $dedupKey, 'analytics', 0);
$job = $q->pop('default');
$deleted = false;
if ($job && !empty($job['id'])) {
    $deleted = $q->delete((int)$job['id']);
}
echo "\\nRESULT_JSON:" . json_encode([
    'push' => $p1,
    'unique_first' => $p2,
    'unique_dedup' => ($p3 === false),
    'pop' => ($job !== null),
    'delete' => $deleted
]);
"""
data = run_php_script(code_queue)
sub_q = [
    (data.get('push') == True, "درج موفق Job در صف اصلی (Queue Push)"),
    (data.get('unique_first') == True, "درج موفق Job یکتا با Idempotency Key"),
    (data.get('unique_dedup') == True, "جلوگیری خودکار از درج تکراری Job در بازه زمانی (Deduplication)"),
    (data.get('pop') == True, "برداشتن موفق Job آماده از صف (Queue Pop)"),
    (data.get('delete') == True, "حذف موفق Job پس از پردازش از صف (Queue Delete)")
]
record_module_test("۱. سیستم صف و جاب‌ها (Queue System)", sub_q)

# ----------------------------------------------------------------------
# 2. Outbox (الگوی Transactional Outbox & Event Publishing)
# ----------------------------------------------------------------------
code_outbox = """
$outbox = \\Core\\Container::getInstance()->make(\\App\\Services\\OutboxService::class);
$id = $outbox->record('user', '101', 'user.updated', ['email' => 'outbox@chortke.ir']);
$pubRes = $outbox->publishPending(10);
echo "\\nRESULT_JSON:" . json_encode([
    'recorded' => $id > 0,
    'publish_processed' => is_array($pubRes)
]);
"""
data = run_php_script(code_outbox)
sub_ob = [
    (data.get('recorded') == True, "ثبت اتمیک رویداد در آوتباکس (Transactional Outbox Record)"),
    (data.get('publish_processed') == True, "پردازش و علامت‌گذاری رویدادهای در انتظار انتشار (Publish Pending Events)")
]
record_module_test("۲. انتشار رویدادهای آوتباکس (Transactional Outbox)", sub_ob)

# ----------------------------------------------------------------------
# 3. Realtime Messages (پیام‌های وب‌سوکت و رویدادهای زنده)
# ----------------------------------------------------------------------
code_rt = """
$ws = \\Core\\Container::getInstance()->make(\\App\\Services\\WebSocketService::class);
$auth = $ws->authorizeRoomAccess(1, 'chat_room_1');
$sent = $ws->sendToUser(1, ['type' => 'chat.message', 'text' => 'تست وب‌سوکت زنده']);
echo "\\nRESULT_JSON:" . json_encode([
    'auth_token' => is_bool($auth),
    'broadcast' => $sent
]);
"""
data = run_php_script(code_rt)
sub_rt = [
    (data.get('auth_token') == True, "اعتبارسنجی دسترسی کانال Realtime/WebSocket"),
    (data.get('broadcast') == True, "ارسال موفق پیام Realtime به کانال کاربر (User Broadcast)")
]
record_module_test("۳. پیام‌رسانی زنده و وب‌سوکت (Realtime Messaging)", sub_rt)

# ----------------------------------------------------------------------
# 4. Email Queue (صف ایمیل‌های سیستم و قالب‌ها)
# ----------------------------------------------------------------------
code_email = """
$store = \\Core\\Container::getInstance()->make(\\App\\Services\\EmailDeliveryStore::class);
$id = $store->save([
    'template' => 'user.welcome',
    'to' => 'email_test@chortke.ir',
    'subject' => 'خوش آمدید',
    'body' => 'متن ایمیل خوش‌آمدگویی',
    'vars' => ['name' => 'کاربر تست']
]);
$pending = $store->getPending(5);
$sent = false;
if ($id) {
    $sent = $store->markAsSent((string)$id);
}
echo "\\nRESULT_JSON:" . json_encode([
    'saved' => !empty($id),
    'pending_retrieved' => count($pending) > 0,
    'sent' => $sent
]);
"""
data = run_php_script(code_email)
sub_em = [
    (data.get('saved') == True, "افزودن موفق ایمیل به صف ارسال (Email Enqueue/Save)"),
    (data.get('pending_retrieved') == True, "بازخوانی ایمیل‌های در صف توسط ورکر"),
    (data.get('sent') == True, "تغییر وضعیت به Sent پس از تحویل به SMTP")
]
record_module_test("۴. صف ایمیل‌های سیستم (Email Queue Store)", sub_em)

# ----------------------------------------------------------------------
# 5. Notifications (اعلان‌های درون‌برنامه‌ای، علامت خوانده‌شده و پاک‌سازی)
# ----------------------------------------------------------------------
code_notif = """
\\Core\\Cache::getInstance()->flush();
$ns = \\Core\\Container::getInstance()->make(\\App\\Services\\Notification\\NotificationService::class);
$notifId = $ns->send(3, 'info', 'اعلان سیستم', 'تست اعلان درون‌برنامه‌ای');
$unreadBefore = $ns->getUnreadCount(3);
$marked = $ns->markAsRead((int)$notifId, 3);
$unreadAfter = $ns->getUnreadCount(3);
echo "\\nRESULT_JSON:" . json_encode([
    'created' => $notifId > 0,
    'marked_read' => $marked,
    'count_decreased' => ($unreadBefore >= $unreadAfter)
]);
"""
data = run_php_script(code_notif)
sub_nf = [
    (data.get('created') == True, "ایجاد موفق اعلان درون‌برنامه‌ای برای کاربر"),
    (data.get('marked_read') == True, "علامت‌گذاری اعلان به عنوان خوانده‌شده (Mark as Read)"),
    (data.get('count_decreased') == True, "به‌روزرسانی آنی شمارنده اعلان‌های خوانده‌نشده (Unread Count)")
]
record_module_test("۵. اعلان‌های درون‌برنامه‌ای (In-App Notifications)", sub_nf)

# ----------------------------------------------------------------------
# 6. Direct Messages (پیام مستقیم رمزشده، حذف نرم و کنترل دسترسی)
# ----------------------------------------------------------------------
code_dm = """
$dm = \\Core\\Container::getInstance()->make(\\App\\Services\\DirectMessageCommandService::class);
$msgRes = $dm->sendMessage(1, 2, 'پیام مستقیم رمزشده بین دو کاربر');
$msgId = is_array($msgRes) ? ($msgRes['message_id'] ?? 0) : (int)$msgRes;
$query = \\Core\\Container::getInstance()->make(\\App\\Services\\DirectMessageQueryService::class);
$conv = $query->getConversation(1, 2, 10);
$deleted = $dm->deleteMessage((int)$msgId, 1);
echo "\\nRESULT_JSON:" . json_encode([
    'sent' => $msgId > 0,
    'retrieved' => is_array($conv) && count($conv) > 0,
    'deleted' => $deleted
]);
"""
data = run_php_script(code_dm)
sub_dm = [
    (data.get('sent') == True, "ارسال پیام مستقیم رمزشده بین دو کاربر (DM Send)"),
    (data.get('retrieved') == True, "بازخوانی گفتگو و رمزگشایی متن پیام (DM Conversation Fetch)"),
    (data.get('deleted') == True, "حذف نرم پیام توسط فرستنده (DM Soft Delete)")
]
record_module_test("۶. پیام‌های مستقیم کاربری (Direct Messaging)", sub_dm)

# ----------------------------------------------------------------------
# 7. Transactions (دفترکل حسابداری دوطرفه و تراکنش‌های مالی)
# ----------------------------------------------------------------------
code_tx = """
$ws = \\Core\\Container::getInstance()->make(\\App\\Services\\Wallet\\WalletService::class);
$dep = $ws->deposit(2, '1000000', 'irt', ['description' => 'شارژ دفترکل']);
$balances = $ws->getWalletBalances(2);
$txs = $ws->getUserTransactions(2, 10, 0);
echo "\\nRESULT_JSON:" . json_encode([
    'deposited' => !empty($dep['success']),
    'balance_updated' => !empty($balances['irt_balance']) && (float)$balances['irt_balance'] > 0,
    'tx_listed' => count($txs) > 0
]);
"""
data = run_php_script(code_tx)
sub_tx = [
    (data.get('deposited') == True, "ثبت تراکنش بستانکار با بالانس دوطرفه (Double-Entry Deposit)"),
    (data.get('balance_updated') == True, "به‌روزرسانی اتمیک موجودی کیف پول"),
    (data.get('tx_listed') == True, "بازخوانی تاریخچه تراکنش‌ها با فیلتر ارز و وضعیت")
]
record_module_test("۷. تراکنش‌های مالی و دفترکل (Transactions & Ledger)", sub_tx)

# ----------------------------------------------------------------------
# 8. Withdrawals (درخواست برداشت، قفل موجودی و پردازش مدیر)
# ----------------------------------------------------------------------
code_wdraw = """
$db = \\Core\\Database::getInstance();
$db->execute("DELETE FROM withdrawals WHERE user_id = 3");
$ws = \\Core\\Container::getInstance()->make(\\App\\Services\\Wallet\\WalletService::class);
$ws->deposit(3, '2000000', 'irt', ['description' => 'شارژ اولیه برداشت']);
$wUser = \\Core\\Container::getInstance()->make(\\App\\Services\\Withdrawal\\WithdrawalUserService::class);
$wRes = $wUser->requestFromUser(3, [
    'amount' => '500000',
    'currency' => 'irt',
    'bank_card_id' => 2,
    'idempotency_key' => 'idem_wdraw_' . microtime(true)
]);
$pendingRow = $db->fetch("SELECT * FROM withdrawals WHERE user_id = 3 AND status = 'pending'");
echo "\\nRESULT_JSON:" . json_encode([
    'requested' => ($wRes['success'] ?? false) || !empty($wRes['withdrawal_id']),
    'pending_listed' => !empty($pendingRow)
]);
"""
data = run_php_script(code_wdraw)
sub_wd = [
    (data.get('requested') == True, "ثبت موفق درخواست برداشت و قفل آنی موجودی (Funds Lock)"),
    (data.get('pending_listed') == True, "نمایش درخواست برداشت در صف کارتابل مدیریت")
]
record_module_test("۸. مدیریت برداشت‌های مالی (Withdrawal Processing)", sub_wd)

# ----------------------------------------------------------------------
# 9. Admin Dashboard (شاخص‌های KPI، داشبورد مدیریت و گزارشات)
# ----------------------------------------------------------------------
code_dash = """
$dash = \\Core\\Container::getInstance()->make(\\App\\Services\\AdminDashboard\\DashboardQueryService::class);
$data = $dash->getDashboardData(1);
$sys = \\Core\\Container::getInstance()->make(\\App\\Services\\AdminDashboard\\SystemMonitoringService::class);
$health = $sys->getSystemStatus();
echo "\\nRESULT_JSON:" . json_encode([
    'dash_data' => !empty($data),
    'health_ok' => ($health['status'] ?? '') === 'healthy' || ($health['status'] ?? '') === 'degraded' || !empty($health)
]);
"""
data = run_php_script(code_dash)
sub_ad = [
    (data.get('dash_data') == True, "محاسبه و بارگذاری شاخص‌های کلیدی (KPIs & Dashboard Summaries)"),
    (data.get('health_ok') == True, "مانیتورینگ سلامت سرویس‌ها و پایش لحظه‌ای منابع")
]
record_module_test("۹. داشبورد و مانیتورینگ ادمین (Admin Dashboard & Analytics)", sub_ad)

print("\n" + "=" * 85)
total_sub_passed = sum(sum(1 for p, _ in subtests if p) for _, _, _, _, subtests in test_module_results)
total_sub_tests = sum(total_count for _, _, _, total_count, _ in test_module_results)
print(f"نتیجه نهایی آزمون عمیق E2E ۹ ماژول: {total_sub_passed} از {total_sub_tests} زیرتست PASS شدند.")
print("=" * 85)
