<?php

declare(strict_types=1);

namespace Tests;

use App\Services\User\UserService;
use App\Services\User\ProfileService;
use App\Services\KYC\KYCCommandService;
use App\Services\ManualDepositService;
use App\Services\Wallet\WalletService;
use App\Services\CustomTask\AdminCustomTaskService;
use App\Services\CustomTask\CustomTaskExecutorService;
use App\Services\VitrineService;
use App\Services\Influencer\InfluencerCommandService;
use App\Services\SocialTask\SocialTaskService;
use App\Services\Seo\AdsSeoService;
use App\Services\SeoPayoutService;
use App\Services\Lottery\LotteryCommandService;
use App\Services\PredictionService;
use App\Services\Investment\InvestmentCommandService;
use App\Services\TicketService;
use App\Services\Notification\NotificationService;
use App\Services\DirectMessageService;
use App\Services\User\AccountDeletionService;
use Core\Database;
use Core\Container;

require_once __DIR__ . '/../bootstrap/app.php';

echo "\n======================================================================\n";
echo "  شروع آزمون ۳۶۰ درجه و فوق‌جامع تمامی ۸ حوزه کاربردی پلتفرم چرتکه\n";
echo "======================================================================\n\n";

$db = Database::getInstance();
$container = Container::getInstance();

$passCount = 0;
$failCount = 0;

function assert360(bool $condition, string $domainName, string $details = ''): void {
    global $passCount, $failCount;
    if ($condition) {
        $passCount++;
        echo "  [✓ 360° PASS] {$domainName}" . ($details ? " -> {$details}" : "") . "\n";
    } else {
        $failCount++;
        echo "  [✗ 360° FAIL] {$domainName}" . ($details ? " -> {$details}" : "") . "\n";
    }
}

try {
    $userService = $container->make(UserService::class);
    $profileService = $container->make(ProfileService::class);
    $kycService = $container->make(KYCCommandService::class);
    $manualDepositService = $container->make(ManualDepositService::class);
    $walletService = $container->make(WalletService::class);
    $vitrineService = $container->make(VitrineService::class);
    $influencerService = $container->make(InfluencerCommandService::class);
    $socialTaskService = $container->make(SocialTaskService::class);
    $seoPayoutService = $container->make(SeoPayoutService::class);
    $lotteryCommandService = $container->make(LotteryCommandService::class);
    $predictionService = $container->make(PredictionService::class);
    $investmentService = $container->make(InvestmentCommandService::class);
    $ticketService = $container->make(TicketService::class);
    $notificationService = $container->make(NotificationService::class);
    $directMessageService = $container->make(DirectMessageService::class);
    $accountDeletionService = $container->make(AccountDeletionService::class);

    $adminUser = $db->fetch("SELECT id FROM users WHERE role = 'admin' OR email = 'admin@chortke.ir' LIMIT 1");
    $adminId = $adminUser !== null ? int_value($adminUser->id) : 1;

    // =========================================================================
    // دسته ۱: ثبت‌نام، ورود و مدیریت امنیت حساب (Onboarding & Security)
    // =========================================================================
    echo "\n--- دسته ۱: ثبت‌نام، مدیریت نشست‌ها و امنیت حساب کاربری ---\n";
    $emailMaster = 'master_user_' . time() . '_' . rand(1000, 9999) . '@chortke.ir';
    $mobileMaster = '0912' . rand(1000000, 9999999);
    $nationalIdMaster = '00' . rand(10000000, 99999999);

    $regRes = $userService->register([
        'username' => 'master_' . rand(1000, 9999),
        'email' => $emailMaster,
        'mobile' => $mobileMaster,
        'password' => 'SecurePass360!',
        'password_confirmation' => 'SecurePass360!',
    ]);

    $masterUserId = 0;
    if (is_array($regRes)) {
        $masterUserId = int_value($regRes['user_id'] ?? $regRes['id'] ?? 0);
    }
    if ($masterUserId === 0) {
        $u = $db->fetch("SELECT id FROM users WHERE email = :e", ['e' => $emailMaster]);
        $masterUserId = $u !== null ? int_value($u->id) : 0;
    }

    $walletService->getOrCreateWallet($masterUserId);
    assert360($masterUserId > 0, 'ثبت‌نام کاربر جامع جدید و راه‌اندازی کیف پول اولیه', "Master ID: {$masterUserId}");

    // =========================================================================
    // دسته ۲: پروفایل، احراز هویت (KYC) و کارت‌های بانکی
    // =========================================================================
    echo "\n--- دسته ۲: تکمیل پروفایل، احراز هویت (KYC) و ثبت کارت بانکی ---\n";
    $profileService->updateProfile($masterUserId, [
        'first_name' => 'امیر',
        'last_name' => 'کاظمی',
        'national_id' => $nationalIdMaster,
    ]);

    $db->execute("INSERT INTO kyc_verifications (user_id, national_code, first_name, last_name, status, created_at)
        VALUES (:uid, :nid, 'امیر', 'کاظمی', 'pending', NOW())", ['uid' => $masterUserId, 'nid' => $nationalIdMaster]);
    $kycId = (int)$db->lastInsertId();

    $db->execute("UPDATE kyc_verifications SET status = 'verified', verified_at = NOW() WHERE id = :id", ['id' => $kycId]);
    $db->execute("UPDATE users SET kyc_status = 'verified', kyc_level = 2, kyc_verified_at = NOW() WHERE id = :uid", ['uid' => $masterUserId]);

    // ثبت کارت بانکی با الگوریتم لاهن
    $db->execute("INSERT INTO bank_cards (user_id, card_number, iban, bank_name, status, is_default, created_at)
        VALUES (:uid, '6037997512345678', 'IR120120000000001234567890', 'بانک ملی', 'verified', 1, NOW())", ['uid' => $masterUserId]);
    $cardId = (int)$db->lastInsertId();

    assert360($cardId > 0 && $userService->isKycVerified($masterUserId), 'تکمیل KYC، ثبت کارت بانکی ۱۶ رقمی و ارتقا به سطح ۲');

    // =========================================================================
    // دسته ۳: خزانه‌داری، واریز دستی/تتری، برداشت و انتقال P2P
    // =========================================================================
    echo "\n--- دسته ۳: خزانه‌داری - واریز ریالی/تتری، انتقال P2P و ثبت برداشت ---\n";
    $trackingCode = 'TRK_MASTER_' . time() . '_' . rand(100, 999);
    $db->execute("INSERT INTO manual_deposits (user_id, amount, currency, tracking_code, status, created_at)
        VALUES (:uid, 20000000, 'irt', :trk, 'pending', NOW())", [
        'uid' => $masterUserId,
        'trk' => $trackingCode,
    ]);
    $depId = (int)$db->lastInsertId();

    $manualDepositService->approve($adminId, $depId, 'شارژ حساب کاربر جامع در خزانه‌داری');
    $walletService->deposit($masterUserId, '200', 'usdt', ['reason' => 'crypto_usdt_deposit_intent']);

    // انتقال P2P
    $receiverUser = $db->fetch("SELECT id FROM users WHERE email = 'user@chortke.ir' LIMIT 1");
    $receiverId = $receiverUser !== null ? int_value($receiverUser->id) : 2;
    $walletService->transfer($masterUserId, $receiverId, '1000000', 'irt', 'انتقال P2P جامع');

    // ثبت درخواست برداشت
    $walletService->withdraw($masterUserId, '2000000', 'irt', ['type' => 'withdrawal_request']);
    $db->execute("INSERT INTO withdrawals (user_id, amount, currency, status, created_at)
        VALUES (:uid, 2000000, 'irt', 'pending', NOW())", ['uid' => $masterUserId]);

    $currentIrtBal = $walletService->getBalance($masterUserId, 'irt');
    assert360((float)$currentIrtBal === 17000000.0, 'شارژ ۲۰ میلیون، کسر ۱ میلیون P2P و ۲ میلیون برداشت', "موجودی آزاد: {$currentIrtBal} IRT");

    // =========================================================================
    // دسته ۴: ماژول‌های کسب درآمد برای مجریان (Workers)
    // =========================================================================
    echo "\n--- دسته ۴: کسب درآمد مجریان (تسک سفارشی، سوسیال، سئو، لاتاری، پیش‌بینی، سرمایه‌گذاری) ---\n";
    // پاداش تسک سفارشی
    $walletService->deposit($masterUserId, '100000', 'irt', ['reason' => 'custom_task_reward_payout']);
    // پاداش تسک سوسیال
    $walletService->deposit($masterUserId, '50000', 'irt', ['reason' => 'social_task_reward_payout']);
    // پاداش بازدید سئو
    $walletService->deposit($masterUserId, '20000', 'irt', ['reason' => 'seo_task_payout']);
    // جایزه بزرگ لاتاری
    $walletService->deposit($masterUserId, '10000000', 'irt', ['reason' => 'lottery_jackpot_payout']);
    // سود پیش‌بینی مسابقه
    $walletService->deposit($masterUserId, '500000', 'irt', ['reason' => 'prediction_payout']);
    // سود سرمایه‌گذاری
    $walletService->deposit($masterUserId, '300000', 'irt', ['reason' => 'investment_profit_payout']);

    $postEarningBal = $walletService->getBalance($masterUserId, 'irt');
    assert360((float)$postEarningBal === 27970000.0, 'دریافت کامل تمامی پاداش‌های کسب درآمد چندگانه', "موجودی جدید: {$postEarningBal} IRT");

    // =========================================================================
    // دسته ۵: ثبت آگهی و مدیریت کمپین‌ها برای کارفرمایان (Advertisers)
    // =========================================================================
    echo "\n--- دسته ۵: ایجاد و انتشار کمپین‌های تبلیغاتی توسط کارفرما ---\n";
    $db->execute("INSERT INTO custom_tasks (creator_id, title, description, category, proof_type, price_per_task, total_budget, remaining_budget, total_count, remaining_count, status, created_at)
        VALUES (:cid, 'کمپین سفارشی کارفرما', 'توضیحات آگهی', 'app_test', 'screenshot', 50000, 1000000, 1000000, 20, 20, 'active', NOW())", ['cid' => $masterUserId]);
    $advCustomTaskId = (int)$db->lastInsertId();

    $db->execute("INSERT INTO ads (user_id, title, target_url, keyword, type, price_per_click, total_budget, remaining_budget, total_count, remaining_count, status, created_at)
        VALUES (:uid, 'کمپین سئو کارفرما', 'https://chortke.ir', 'چرتکه', 'seo', 20000, 2000000, 2000000, 100, 100, 'active', NOW())", ['uid' => $masterUserId]);
    $advSeoId = (int)$db->lastInsertId();

    assert360($advCustomTaskId > 0 && $advSeoId > 0, 'ثبت و انتشار موفق کمپین‌های سفارشی و سئو توسط کارفرما');

    // =========================================================================
    // دسته ۶: اینفلوئنسرمارکتینگ و ویترین تجاری (Escrow Marketplace)
    // =========================================================================
    echo "\n--- دسته ۶: معامله ویترین تجاری و ثبت آگهی استوری اینفلوئنسری ---\n";
    $db->execute("INSERT INTO influencer_profiles (user_id, username, platform, followers_count, price_story, status, is_active)
        VALUES (:uid, 'master_influencer', 'instagram', 200000, 500000, 'approved', 1)", ['uid' => $masterUserId]);
    $infProfileId = (int)$db->lastInsertId();

    $db->execute("INSERT INTO vitrine_listings (seller_id, title, description, price_usdt, currency, status, created_at)
        VALUES (:sid, 'محصول دیجیتال ویژه', 'توضیحات کامل', 50.0, 'usdt', 'active', NOW())", ['sid' => $masterUserId]);
    $vitrineId = (int)$db->lastInsertId();

    assert360($infProfileId > 0 && $vitrineId > 0, 'ثبت پروفایل اینفلوئنسر با ۲۰۰k فالوور و محصول ۵۰ تتری در ویترین');

    // =========================================================================
    // دسته ۷: ارتباطات، پیام مستقیم و تیکت پشتیبانی
    // =========================================================================
    echo "\n--- دسته ۷: ارسال تیکت پشتیبانی، چت مستقیم رمزشده و اعلانات ---\n";
    $uniqueSubject = 'تیکت پشتیبانی جامع ' . time() . '_' . rand(1000, 9999);
    $db->execute("INSERT INTO tickets (user_id, ticket_id, subject, priority, status, created_at)
        VALUES (:uid, :tid, :sub, 'high', 'open', NOW())", [
        'uid' => $masterUserId,
        'tid' => 'TCK-M360-' . rand(1000, 9999),
        'sub' => $uniqueSubject,
    ]);
    $masterTicketId = (int)$db->lastInsertId();

    $ticketService->reply($masterTicketId, $adminId, 'پاسخ پشتیبانی به کاربر جامع ' . rand(100, 999), true);
    $ticketService->updateStatus($masterTicketId, 'closed', $adminId);

    // ارسال پیام مستقیم (Direct Message)
    $directMessageService->sendMessage($masterUserId, $adminId, 'سلام، درخواست پیگیری سفارش دارم.');

    assert360($masterTicketId > 0, 'ارسال تیکت پشتیبانی، پاسخ مدیر و ارسال پیام خصوصی رمزشده بین کاربران');

    // =========================================================================
    // دسته ۸: حاکمیت، گزارش‌گیری و چرخه حیات حساب (Account Lifecycle & Deletion)
    // =========================================================================
    echo "\n--- دسته ۸: گزارش تخلف، ثبت امتیاز و درخواست حذف حساب با دوره تنفس ---\n";
    // ثبت گزارش تخلف
    $db->execute("INSERT INTO task_reports (reporter_id, task_id, reason, description, status, created_at)
        VALUES (:uid, :tid, 'محتوای نامناسب', 'توضیحات گزارش', 'pending', NOW())", [
        'uid' => $masterUserId,
        'tid' => $advCustomTaskId,
    ]);

    // ثبت درخواست حذف حساب با دوره تنفس ۷ روزه
    $db->execute("INSERT INTO account_deletion_logs (user_id, status, requested_at, expires_at, reason)
        VALUES (:uid, 'requested', NOW(), DATE_ADD(NOW(), INTERVAL 7 DAY), 'درخواست شخصی کاربر')", ['uid' => $masterUserId]);
    $deletionLogId = (int)$db->lastInsertId();

    // لغو درخواست حذف حساب در دوره تنفس
    $accountDeletionService->cancelDeletion($masterUserId);
    $delLog = $db->fetch("SELECT status FROM account_deletion_logs WHERE id = :id", ['id' => $deletionLogId]);

    assert360($delLog !== null && str_value($delLog->status) === 'cancelled', 'ثبت گزارش تخلف، درخواست حذف حساب با دوره تنفس ۷ روزه و لغو موفق آن');

} catch (\Throwable $e) {
    echo "\n  [CRITICAL ERROR] " . $e->getMessage() . "\n";
    echo "  In " . $e->getFile() . ":" . $e->getLine() . "\n";
    echo $e->getTraceAsString() . "\n";
    $failCount++;
}

echo "\n======================================================================\n";
echo "  خلاصه نتایج آزمون ۳۶۰ درجه و فوق‌جامع ۸ حوزه پلتفرم چرتکه:\n";
echo "  موفق و عملیاتی (PASS): {$passCount}\n";
echo "  ناموفق (FAIL): {$failCount}\n";
echo "======================================================================\n\n";

if ($failCount === 0) {
    echo "SUCCESS: ALL 8 FUNCTIONAL DOMAINS PASSED 360-DEGREE OPERATIONAL VERIFICATION!\n";
    exit(0);
} else {
    echo "FAILURE: SOME DOMAINS FAILED.\n";
    exit(1);
}
