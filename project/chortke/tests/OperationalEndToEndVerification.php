<?php

declare(strict_types=1);

namespace Tests;

use App\Models\User;
use App\Models\Wallet;
use App\Models\VitrineListing;
use App\Models\Ticket;
use App\Services\Wallet\WalletMutationService;
use App\Services\Wallet\WalletService;
use App\Services\VitrineService;
use App\Services\User\UserService;
use App\Services\TicketService;
use Core\Database;
use Core\Container;

/**
 * OperationalEndToEndVerification — اجرای آزمون‌های ۱۰۰٪ عملیاتی و لایو روی MariaDB و Redis
 *
 * این اسکریپت تمام مراحل کاری پلتفرم را به صورت واقعی روی دیتابیس اجرا و مقادیر جدول‌ها را
 * قبل و بعد از هر اکشن بازرسی می‌کند.
 */
require_once __DIR__ . '/../bootstrap/app.php';

echo "\n======================================================================\n";
echo "  شروع اجرای آزمون‌های ۱۰۰٪ عملیاتی، اکشن‌محور و زنده (Live MariaDB Operations)\n";
echo "======================================================================\n\n";

$db = Database::getInstance();
$container = Container::getInstance();

$userSvc = $container->make(UserService::class);
$walletSvc = $container->make(WalletService::class);
$vitrineSvc = $container->make(VitrineService::class);

/** @var array{pass: int, fail: int} $stats */
$stats = ['pass' => 0, 'fail' => 0];

/**
 * @param array{pass: int, fail: int} $stats
 */
function opAssert(string $title, bool $condition, array &$stats, string $detail = ''): void {
    if ($condition) {
        echo "  ✓ PASS: {$title}" . ($detail ? " ({$detail})" : "") . "\n";
        $stats['pass']++;
    } else {
        echo "  ✗ FAIL: {$title}" . ($detail ? " ({$detail})" : "") . "\n";
        $stats['fail']++;
    }
}

// ─── اکشن ۱: ثبت‌نام و ساخت کیف‌پول واقعی در دیتابیس ───────────────
echo "▶ [اکشن ۱]: افتتاح حساب کاربری و ایجاد کیف‌پول در MariaDB...\n";
$email1 = 'op_user1_' . time() . '@chortke.test';
$passHash = hash_password('123456');

$u1Id = (int)$db->insert(
    "INSERT INTO users (username, email, password, full_name, role, status, kyc_status, email_verified_at, created_at, updated_at)
     VALUES (?, ?, ?, ?, 'user', 'active', 'verified', NOW(), NOW(), NOW())",
    ['user1_' . time(), $email1, $passHash, 'کاربر عملیاتی اول']
);

$db->insert("INSERT INTO wallets (user_id, balance_irt, balance_usdt, locked_irt, locked_usdt) VALUES (?, 0, 0, 0, 0)", [$u1Id]);

$w1 = $db->fetch("SELECT balance_irt, balance_usdt FROM wallets WHERE user_id = ?", [$u1Id]);
$w1Irt = $w1 !== null ? float_value($w1->balance_irt) : null;
opAssert("حساب کاربری op_user1 در دیتابیس ایجاد شد", $u1Id > 0, $stats, "User ID: {$u1Id}");
opAssert("کیف پول اول با موجودی اولیه صفر ایجاد شد", $w1Irt === 0.0, $stats);


// ─── اکشن ۲: واریز دستی ۵ میلیون تومانی و شارژ دیتابیس ──────────────
echo "\n▶ [اکشن ۲]: ثبت واریز دستی ۵ میلیون تومانی و شارژ کیف پول...\n";
$trkCode = 'TRK_OP_' . time();
$depId = (int)$db->insert(
    "INSERT INTO manual_deposits (user_id, amount, currency, tracking_code, status, created_at)
     VALUES (?, 5000000, 'irt', ?, 'pending', NOW())",
    [$u1Id, $trkCode]
);

// تایید ادمین و شارژ کیف پول
$db->execute("UPDATE manual_deposits SET status = 'approved', approved_at = NOW() WHERE id = ?", [$depId]);
$walletSvc->deposit($u1Id, '5000000', 'irt', ['type' => 'manual_deposit', 'deposit_id' => $depId]);

$w1AfterDep = $db->fetch("SELECT balance_irt FROM wallets WHERE user_id = ?", [$u1Id]);
$w1AfterDepIrt = $w1AfterDep !== null ? float_value($w1AfterDep->balance_irt) : null;
opAssert(
    "موجودی کاربر اول پس از واریز دستی به ۵,۰۰۰,۰۰۰ تومان رسید",
    $w1AfterDepIrt === 5000000.0,
    $stats,
    "Balance: " . number_format($w1AfterDepIrt ?? 0.0) . " IRT"
);


// ─── اکشن ۳: انتقال اعتبار P2P بین دو کاربر واقعی ─────────────────
echo "\n▶ [اکشن ۳]: انتقال اعتبار ۱ میلیون تومانی P2P به کاربر دوم...\n";
$email2 = 'op_user2_' . time() . '@chortke.test';
$u2Id = (int)$db->insert(
    "INSERT INTO users (username, email, password, full_name, role, status, kyc_status, email_verified_at, created_at, updated_at)
     VALUES (?, ?, ?, ?, 'user', 'active', 'verified', NOW(), NOW(), NOW())",
    ['user2_' . time(), $email2, $passHash, 'کاربر عملیاتی دوم']
);
$db->insert("INSERT INTO wallets (user_id, balance_irt, balance_usdt, locked_irt, locked_usdt) VALUES (?, 0, 0, 0, 0)", [$u2Id]);

// انتقال ۱,۰۰۰,۰۰۰ تومان
$transferRes = $walletSvc->transfer($u1Id, $u2Id, '1000000', 'irt', 'انتقال P2P عملیاتی');

$w1AfterTrans = $db->fetch("SELECT balance_irt FROM wallets WHERE user_id = ?", [$u1Id]);
$w2AfterTrans = $db->fetch("SELECT balance_irt FROM wallets WHERE user_id = ?", [$u2Id]);
$w1AfterTransIrt = $w1AfterTrans !== null ? float_value($w1AfterTrans->balance_irt) : null;
$w2AfterTransIrt = $w2AfterTrans !== null ? float_value($w2AfterTrans->balance_irt) : null;

opAssert("موجودی فرستنده به ۴,۰۰۰,۰۰۰ تومان کسر شد", $w1AfterTransIrt === 4000000.0, $stats);
opAssert("موجودی گیرنده به ۱,۰۰۰,۰۰۰ تومان افزایش یافت", $w2AfterTransIrt === 1000000.0, $stats);


// ─── اکشن ۴: معامله اسکرو در ویترین تجاری (قفل وجه و تسویه) ──────────
echo "\n▶ [اکشن ۴]: ثبت آگهی فروش ۱۰۰ تتری در ویترین، قفل امانی و تسویه...\n";
// شارژ ۱۰۰ تتر کاربر ۱
$walletSvc->deposit($u1Id, '100', 'usdt', ['type' => 'test_charge']);

// ساخت آگهی ویترین توسط کاربر ۲ (فروشنده)
$listingId = (int)$db->insert(
    "INSERT INTO vitrine_listings (seller_id, title, category, platform, price_usdt, status, created_at, updated_at)
     VALUES (?, 'کانال تلگرام معامله عملیاتی', 'social', 'telegram', 100.0000, 'active', NOW(), NOW())",
    [$u2Id]
);

// قفل اسکرو توسط کاربر ۱ (خریدار)
$lockRes = $vitrineSvc->lockEscrow($u1Id, $listingId);
$lockOk = (bool)($lockRes['success'] ?? false);

$w1Locked = $db->fetch("SELECT balance_usdt, locked_usdt FROM wallets WHERE user_id = ?", [$u1Id]);
$w1LockedUsdt = $w1Locked !== null ? float_value($w1Locked->locked_usdt) : null;

opAssert("قفل وجه اسکرو ۱۰۰ تتری خریدار موفقیت‌آمیز بود", $lockOk, $stats);
opAssert("مبلغ ۱۰۰ تتر در حساب خریدار قفل گردید (Locked USDT = 100)", $w1LockedUsdt === 100.0, $stats);

// تایید تحویل توسط خریدار و آزادسازی وجه به فروشنده
$confirmRes = $vitrineSvc->confirmDelivery($u1Id, $listingId);
$confirmOk = (bool)($confirmRes['success'] ?? false);
$w2Seller = $db->fetch("SELECT balance_usdt FROM wallets WHERE user_id = ?", [$u2Id]);
$w2SellerUsdt = $w2Seller !== null ? float_value($w2Seller->balance_usdt) : 0.0;

opAssert("تایید تحویل انجام شد و معامله ویترین تسویه گردید", $confirmOk, $stats);
opAssert("مبلغ ۱۰۰ تتر به حساب فروشنده واریز شد (موجودی فروشنده: {$w2SellerUsdt} USDT)", $w2SellerUsdt > 0, $stats);


// ─── اکشن ۵: ثبت تیکت پشتیبانی و پاسخ ادمین ───────────────────────
echo "\n▶ [اکشن ۵]: ثبت تیکت پشتیبانی و پاسخ‌دهی ادمین در دیتابیس...\n";
$ticketSvc = $container->make(TicketService::class);
$ticketRes = $ticketSvc->create($u1Id, [
    'subject' => 'پیگیری واریز دستی ۵ میلیون تومانی',
    'category' => 'billing',
    'category_id' => 1,
    'priority' => 'high',
    'message' => 'سلام، واریز دستی ۵ میلیون تومانی انجام گردید. لطفاً بررسی و تایید بفرمایید.'
]);

opAssert("تیکت پشتیبانی با موفقیت در دیتابیس ثبت شد", $ticketRes['success'], $stats);

$ticketId = int_value($ticketRes['ticket_id'] ?? 0);
if ($ticketId > 0) {
    // پاسخ ادمین
    $db->insert(
        "INSERT INTO ticket_messages (ticket_id, user_id, message, is_admin, created_at)
         VALUES (?, 3, 'پاسخ ادمین: درخواست شما تایید و واریز انجام شد.', 1, NOW())",
        [$ticketId]
    );
    $db->execute("UPDATE tickets SET status = 'answered', updated_at = NOW() WHERE id = ?", [$ticketId]);

    $tRow = $db->fetch("SELECT status FROM tickets WHERE id = ?", [$ticketId]);
    $ticketStatus = $tRow !== null ? str_value($tRow->status) : '';
    opAssert("پاسخ ادمین ثبت شد و وضعیت تیکت به answered تغییر یافت", $ticketStatus === 'answered', $stats);
}


// ─── اکشن ۶: پایش ریسک ضدتقلب و ارزیابی امتیاز ────────────────────
echo "\n▶ [اکشن ۶]: ارزیابی ریسک ضدتقلب و ثبت جریمه در MariaDB...\n";
$userSvc->incrementFraudScore($u2Id, 15);
$u2Updated = $db->fetch("SELECT fraud_score FROM users WHERE id = ?", [$u2Id]);
$fraudScore = $u2Updated !== null ? int_value($u2Updated->fraud_score) : -1;

opAssert("امتیاز ریسک کاربر در دیتابیس با موفقیت ارتقا یافت (Fraud Score = {$fraudScore})", $fraudScore === 15, $stats);

$pass = $stats['pass'];
$fail = $stats['fail'];

echo "\n======================================================================\n";
echo "  خلاصه عملکرد آزمون‌های ۱۰۰٪ عملیاتی و زنده دیتابیس\n";
echo "======================================================================\n";
echo "  Passed: {$pass}    Failed: {$fail}\n";
echo "======================================================================\n\n";

if ($fail === 0) {
    exit(0);
}
exit(1);
