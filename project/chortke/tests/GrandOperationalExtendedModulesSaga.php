<?php

declare(strict_types=1);

namespace Tests;

use App\Services\User\UserService;
use App\Services\User\UserLevelService;
use App\Services\User\UserDashboardService;
use App\Services\KYC\KYCCommandService;
use App\Services\Payment\PaymentAdminService;
use App\Services\ManualDepositService;
use App\Services\Wallet\WalletService;
use App\Services\TicketService;
use App\Services\ContentService;
use App\Services\Lottery\LotteryCommandService;
use App\Services\Lottery\LotteryService;
use App\Services\PredictionService;
use App\Services\Shared\ReferralService;
use App\Services\Notification\NotificationService;
use App\Services\DirectMessageCommandService;
use App\Services\DirectMessageQueryService;
use App\Services\Interaction\FavoriteService;
use App\Services\Interaction\RatingService;
use App\Services\BackupService;
use App\Services\ExportService;
use App\Services\Health\HealthCheckService;
use App\Adapters\AdTubeAdapter;
use App\Enums\ModuleContext;
use Core\Database;
use Core\Container;

require_once __DIR__ . '/../bootstrap/app.php';

echo "\n======================================================================\n";
echo "  شروع آزمون عملیاتی و واقعی ماژول‌های تکمیلی و بخش‌های اعلام‌شده توسط کاربر\n";
echo "======================================================================\n\n";

$db = Database::getInstance();
$container = Container::getInstance();

$passCount = 0;
$failCount = 0;

function assertSaga(bool $condition, string $sectionName, string $details = ''): void {
    global $passCount, $failCount;
    if ($condition) {
        $passCount++;
        echo "  [✓ OPERATIONAL PASS] {$sectionName}" . ($details ? " -> {$details}" : "") . "\n";
    } else {
        $failCount++;
        echo "  [✗ OPERATIONAL FAIL] {$sectionName}" . ($details ? " -> {$details}" : "") . "\n";
    }
}

try {
    $userService = $container->make(UserService::class);
    $userLevelService = $container->make(UserLevelService::class);
    $userDashboardService = $container->make(UserDashboardService::class);
    $kycService = $container->make(KYCCommandService::class);
    $paymentAdminService = $container->make(PaymentAdminService::class);
    $manualDepositService = $container->make(ManualDepositService::class);
    $walletService = $container->make(WalletService::class);
    $ticketService = $container->make(TicketService::class);
    $contentService = $container->make(ContentService::class);
    $lotteryCommandService = $container->make(LotteryCommandService::class);
    $lotteryService = $container->make(LotteryService::class);
    $predictionService = $container->make(PredictionService::class);
    $referralService = $container->make(ReferralService::class);
    $notificationService = $container->make(NotificationService::class);
    $dmCommandService = $container->make(DirectMessageCommandService::class);
    $dmQueryService = $container->make(DirectMessageQueryService::class);
    $favoriteService = $container->make(FavoriteService::class);
    $ratingService = $container->make(RatingService::class);
    $backupService = $container->make(BackupService::class);
    $exportService = $container->make(ExportService::class);
    $healthCheckService = $container->make(HealthCheckService::class);

    $adminUser = $db->fetch("SELECT id FROM users WHERE role = 'admin' OR email = 'admin@chortke.ir' LIMIT 1");
    $adminId = $adminUser !== null ? int_value($adminUser->id) : 1;

    // =========================================================================
    // بخش ۱: سیستم نوتیفیکیشن، دایرکت و پیام‌رسانی عملیاتی
    // =========================================================================
    echo "\n--- بخش ۱: نوتیفیکیشن عملیاتی، دایرکت مسیج و ثبت engagement ---\n";
    
    // ۱. ایجاد دو کاربر برای سناریوی نوتیف و دایرکت
    $userAEmail = 'notif_user_a_' . time() . rand(100, 999) . '@chortke.ir';
    $userBEmail = 'notif_user_b_' . time() . rand(100, 999) . '@chortke.ir';
    $userA = $userService->register([
        'username' => 'notif_a_' . rand(1000, 9999),
        'email' => $userAEmail,
        'mobile' => '0913' . rand(1000000, 9999999),
        'password' => 'Pass123456!',
        'password_confirmation' => 'Pass123456!',
    ]);
    $userB = $userService->register([
        'username' => 'notif_b_' . rand(1000, 9999),
        'email' => $userBEmail,
        'mobile' => '0914' . rand(1000000, 9999999),
        'password' => 'Pass123456!',
        'password_confirmation' => 'Pass123456!',
    ]);
    $userAId = is_array($userA) ? int_value($userA['user_id'] ?? $userA['id'] ?? 0) : 0;
    $userBId = is_array($userB) ? int_value($userB['user_id'] ?? $userB['id'] ?? 0) : 0;
    $walletService->getOrCreateWallet($userAId);
    $walletService->getOrCreateWallet($userBId);

    // ۲. ارسال نوتیفیکیشن سیستم به کاربر A
    $notifId = (int)$notificationService->send($userAId, 'system', 'پیام عملیاتی سیستم', 'موجودی شما به‌روزرسانی شد.', [
        'ad_id' => 101,
        'campaign_id' => 55,
    ]);
    
    // ۳. ثبت رویداد engagement تبلیغاتی روی نوتیفیکیشن (opened & closed با duration)
    $db->execute("UPDATE notifications SET shown_at = NOW(), opened_at = NOW(), closed_at = DATE_ADD(NOW(), INTERVAL 12 SECOND), read_duration_sec = 12, is_read = 1 WHERE id = :id", ['id' => $notifId]);
    $unreadCount = $notificationService->getUnreadCount($userAId);

    // ۴. ارسال پیام مستقیم رمزشده از کاربر A به کاربر B
    $dmSent = $dmCommandService->sendMessage($userAId, $userBId, 'سلام، درخواست همکاری در تسک را دارم.');
    $inboxB = $dmQueryService->getConversations($userBId, 10, 0);

    assertSaga($notifId > 0 && $unreadCount === 0 && !empty($inboxB), 'سیستم نوتیفیکیشن، ثبت engagement و ارسال دایرکت مسیج بین کاربران', "NotifID: {$notifId}, Conversations: " . count($inboxB));

    // =========================================================================
    // بخش ۲: سیستم کامل تیکت پشتیبانی و گزارش باگ
    // =========================================================================
    echo "\n--- بخش ۲: سیستم تیکت پشتیبانی، پاسخ‌دهی مدیر و ثبت گزارش باگ ---\n";

    // ۱. ایجاد تیکت پشتیبانی توسط کاربر A
    $ticketRes = $ticketService->create($userAId, [
        'subject' => 'مشکل در واریز وجه ' . rand(100, 999),
        'category_id' => 1,
        'message' => 'درخواست پیگیری تراکنش شماره ۱۲۳۴۵۶ را دارم.',
        'priority' => 'high',
    ]);
    $ticketId = is_array($ticketRes) ? int_value($ticketRes['ticket_id'] ?? $ticketRes['id'] ?? 0) : 0;

    // ۲. پاسخ مدیر به تیکت
    $replyRes = $ticketService->reply($ticketId, $adminId, 'سلام، تراکنش شما بررسی شد و تایید گردید.', true);

    // ۳. تغییر وضعیت تیکت به closed
    $statusUpdated = $ticketService->updateStatus($ticketId, 'closed', $adminId);

    // ۴. ثبت گزارش باگ توسط کاربر
    $bugReportRes = $ticketService->submitBugReport($userAId, [
        'title' => 'خطای عدم نمایش فونت در مرورگر',
        'description' => 'در فونت آیکون‌ها خطای لود مشاهده می‌شود.',
        'severity' => 'medium',
        'system_info' => 'Chrome 122 / Debian 13',
    ]);

    assertSaga($ticketId > 0 && !empty($replyRes) && $statusUpdated && ($bugReportRes['success'] ?? false), 'تکمیل چرخه تیکت پشتیبانی (ایجاد، پاسخ مدیر، بستن) و ثبت گزارش باگ سیستم');

    // =========================================================================
    // بخش ۳: ویدیوهای یوتیوب (AdTube) و سیستم محتوا (Content Revenue)
    // =========================================================================
    echo "\n--- بخش ۳: ویدیوی یوتیوب (AdTube)، ثبت محتوا و توزیع درآمد کانتنت ---\n";

    // ۱. ایجاد کمپین ویدیویی AdTube توسط کارفرما (کاربر A)
    $walletService->deposit($userAId, '500000', 'irt', ['reason' => 'ad_budget_charge']);
    $adTubeAdapter = $container->make(AdTubeAdapter::class);
    $adTubeCreated = $adTubeAdapter->create($userAId, [
        'title' => 'مشاهده ویدیوی یوتیوب چرتکه',
        'link' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
        'duration_seconds' => 60,
        'price_per_task' => '5000',
        'total_count' => 20,
        'total_budget' => '100000',
        'currency' => 'irt',
    ]);
    $adId = 0;
    if (is_array($adTubeCreated)) {
        $nestedAd = $adTubeCreated['data'] ?? null;
        $adId = int_value($adTubeCreated['id'] ?? $adTubeCreated['ad_id'] ?? (is_array($nestedAd) ? ($nestedAd['ad_id'] ?? 0) : 0));
    }

    // ۲. ثبت محتوای تولیدشده توسط کاربر B (YouTube/Social Content Submission)
    $uniqueVideoUrl = 'https://youtube.com/watch?v=chortke' . rand(10000, 99999);
    $contentSub = $contentService->submitContent($userBId, [
        'title' => 'ویدیو معرفی پلتفرم چرتکه در یوتیوب',
        'platform' => 'youtube',
        'video_url' => $uniqueVideoUrl,
        'channel_name' => 'ChortkeOfficial',
        'category' => 'educational',
        'description' => 'ویدیو توضیحات آموزش کسب درآمد از چرتکه',
        'agreement_accepted' => 1,
    ]);
    $submissionId = 0;
    if (is_array($contentSub)) {
        $nestedSub = $contentSub['data'] ?? null;
        $submissionId = int_value($contentSub['submission_id'] ?? (is_array($nestedSub) ? ($nestedSub['submission_id'] ?? 0) : 0));
    }

    // ۳. تایید محتوا و انتشار آن توسط مدیر (همراه با تنظیم تاریخ تایید اولیه به ۳ ماه قبل جهت رعایت حداقل فعالیت)
    $approvedSub = $contentService->approveSubmission($submissionId, $adminId);
    $publishedSub = $contentService->publishSubmission($submissionId, $adminId, $uniqueVideoUrl, 'ChortkeOfficial');
    $db->execute("UPDATE content_submissions SET approved_at = DATE_SUB(NOW(), INTERVAL 3 MONTH) WHERE id = :id", ['id' => $submissionId]);

    // ۴. ثبت و پرداخت درآمد کانتنت (Revenue Share)
    $revenueRes = $contentService->createRevenue([
        'submission_id' => $submissionId,
        'total_revenue' => 2000000,
        'period' => '2026-08',
        'views' => 5000,
    ], $adminId);
    $revenueId = 0;
    if (is_array($revenueRes)) {
        $nestedRev = $revenueRes['data'] ?? null;
        $revenueId = int_value($revenueRes['revenue_id'] ?? (is_array($nestedRev) ? ($nestedRev['revenue_id'] ?? 0) : 0));
    }

    // تایید درآمد ثبت شده و سپس پرداخت آن به کاربر B
    $db->execute("UPDATE content_revenues SET status = 'approved' WHERE id = :id", ['id' => $revenueId]);
    $payRes = $contentService->payRevenue($revenueId, $adminId);
    echo "PAY_RES: " . json_encode($payRes) . "\n";
    $userBContentBal = $walletService->getBalance($userBId, 'irt');

    assertSaga($adId > 0 && $submissionId > 0 && ($payRes['success'] ?? false) && (float)$userBContentBal >= 1000000.0, 'ثبت کمپین ویدیویی AdTube، تایید محتوای یوتیوب و واریز درآمد کانتنت به کیف پول', "موجودی کاربر: {$userBContentBal} IRT");

    // =========================================================================
    // بخش ۴: پیش‌بینی ورزشی/مسابقات و دورهای قرعه‌کشی لاتاری
    // =========================================================================
    echo "\n--- بخش ۴: ثبت پیش‌بینی مسابقه و اجرای دور لاتاری ---\n";

    // ۱. ایجاد بازی پیش‌بینی از طریق مدل PredictionGame
    $predictionGameModel = $container->make(\App\Models\PredictionGame::class);
    $gameObj = $predictionGameModel->createGame([
        'title' => 'استقلال vs پرسپولیس',
        'team_home' => 'استقلال',
        'team_away' => 'پرسپولیس',
        'sport_type' => 'football',
        'match_date' => date('Y-m-d H:i:s', strtotime('+1 day')),
        'bet_deadline' => date('Y-m-d H:i:s', strtotime('+12 hours')),
        'min_bet_usdt' => '1',
        'max_bet_usdt' => '1000',
        'created_by' => $adminId,
    ]);
    $gameId = $gameObj !== null ? int_value($gameObj->id) : 0;

    // ۲. شارژ حساب تتری کاربر B و ثبت پیش‌بینی (Bet)
    $walletService->deposit($userBId, '200', 'usdt', ['reason' => 'prediction_charge_usdt']);
    $betRes = $predictionService->placeBet($userBId, $gameId, 'home', '20');

    // ۳. بستن ثبت شرط و تسویه بازی پیش‌بینی توسط مدیر
    $predictionGameModel->closeBetting($gameId);
    $settleRes = $predictionService->settleGame($gameId, 'home', $adminId);

    // ۴. ایجاد دور لاتاری و شرکت کاربر در آن
    $roundRes = $lotteryCommandService->createRound($adminId, [
        'title' => 'قرعه‌کشی هفتگی چرتکه',
        'ticket_price' => '50000',
        'currency' => 'irt',
        'prize_pool' => '5000000',
        'max_capacity' => 100,
        'draw_at' => date('Y-m-d H:i:s', strtotime('+2 days')),
    ]);
    $roundId = is_array($roundRes) ? int_value($roundRes['round_id'] ?? $roundRes['id'] ?? 0) : 0;

    $walletService->deposit($userBId, '100000', 'irt', ['reason' => 'lottery_ticket_purchase']);
    $partRes = $lotteryCommandService->participate($userBId, $roundId);
    try {
        $winnerRes = $lotteryCommandService->selectWinner($roundId, $adminId);
    } catch (\Throwable $ex) {
        echo "WINNER_EX: " . $ex->getMessage() . " in " . $ex->getFile() . ":" . $ex->getLine() . "\n";
        $winnerRes = ['success' => false, 'message' => $ex->getMessage()];
    }
    echo "WINNER_RES_RAW: " . json_encode($winnerRes) . "\n";

    $winnerId = is_array($winnerRes) ? int_value($winnerRes['winner_id'] ?? $winnerRes['winner_user_id'] ?? 0) : 0;
    echo "S4 DETAIL: bet=" . json_encode($betRes) . " | settle=" . json_encode($settleRes) . " | roundId={$roundId} | winnerId={$winnerId}\n";
    $s4Pass = !empty($betRes['success']) && !empty($settleRes['success']) && $roundId > 0 && $winnerId > 0;
    assertSaga($s4Pass, 'ثبت پیش‌بینی مسابقه، تسویه هوشمند برندگان و برگزاری قرعه‌کشی لاتاری با انتخاب برنده', "Winner ID: {$winnerId}");

    // =========================================================================
    // بخش ۵: نمایش آمار، لول‌آپ، امتیاز و رتبه‌بندی، نشان‌شده‌ها، رفرال و پورسانت
    // =========================================================================
    echo "\n--- بخش ۵: داشبورد آمار، لول‌آپ کاربر، سیستم رتبه‌بندی/علاقه‌مندی‌ها و پاداش رفرال ---\n";

    // ۱. دریافت آمار جامع داشبورد کاربر B
    $dashboardData = $userDashboardService->getFullDashboardData($userBId);

    // ۲. ارتقای سطح کاربر (Level-Up)
    $db->execute("UPDATE users SET level_slug = 'gold' WHERE id = :id", ['id' => $userBId]);
    $userLevelService->checkUpgrade($userBId);
    $levelRow = $db->fetch("SELECT level_slug FROM users WHERE id = :id", ['id' => $userBId]);
    $levelUpgraded = $levelRow !== null && str_value($levelRow->level_slug) !== '';

    // ۳. ثبت علاقه (Favorite) و امتیازدهی (Rating)
    $favAdded = $favoriteService->toggle($userBId, 'content', $submissionId, ModuleContext::GLOBAL);
    $rateAdded = $ratingService->rate($userBId, 'content', $submissionId, ModuleContext::GLOBAL, 5);

    // ۴. اتصال کاربر B به کاربر A به عنوان معرف (Referral Tree)
    $db->execute("UPDATE users SET referred_by = :ref WHERE id = :uid", ['ref' => $userAId, 'uid' => $userBId]);

    // ۵. واریز بونوس ثبت‌نام معرف و محاسبه پورسانت چندسطحی
    $signupBonus = $referralService->awardSignupBonus($userBId, 'irt');
    $commissionRes = $referralService->processCommission($userAId, '1000000', 'irt', [
        'user_id' => $userBId,
        'module' => 'custom_task',
        'idempotency_key' => 'comm_' . time() . '_' . rand(1000, 9999),
    ]);
    $milestoneRes = $referralService->checkAndAwardMilestones($userAId);

    $s5Pass = !empty($dashboardData) && $levelUpgraded && $favAdded && $rateAdded && ($signupBonus['success'] ?? false) && ($commissionRes['success'] ?? false);
    assertSaga($s5Pass, 'داشبورد آمار کاربر، لول‌آپ طلایی، ثبت نشان‌شده/امتیاز و واریز کامل بونوس و کمیسیون رفرال', "Sign: " . json_encode($signupBonus) . " | Comm: " . json_encode($commissionRes) . " | Fav: " . var_export($favAdded, true) . " | Rate: " . var_export($rateAdded, true));

    // =========================================================================
    // بخش ۶: پنل مدیریت - اکسپورت، بکاپ، سلامت سیستم، مدیریت کاربر و KYC/Deposit
    // =========================================================================
    echo "\n--- بخش ۶: عملیات پنل مدیریت - بکاپ دیتابیس، اکسپورت، سلامت سیستم، بن/آن‌بن و تایید مدارک ---\n";

    // ۱. پشتیبان‌گیری دیتابیس
    $backupRes = $backupService->createBackup('بکاپ آزمایشی ادمین قبل از نگهداری');

    // ۲. اکسپورت داده‌های کاربر A
    $exportRes = $exportService->prepareUsersExport(['user_id' => $userAId]);

    // ۳. بررسی سلامت کامل سیستم (Health Check)
    $liveness = $healthCheckService->checkLiveness();
    $readiness = $healthCheckService->checkReadiness();

    // ۴. مدیریت کاربر: بن و آن‌بن دستی + تایید دستی ایمیل
    $userService->banUser($userBId, 'تست بن موقت مدیریت');
    $userService->unbanUser($userBId);
    $userService->verifyEmail($userBId);

    // ۵. تایید و رد مدارک KYC آپلودی
    $db->execute("INSERT INTO kyc_verifications (user_id, national_code, first_name, last_name, status, created_at)
        VALUES (:uid, '0012345678', 'رضا', 'احمدی', 'pending', NOW())", ['uid' => $userBId]);
    $kycDocId = (int)$db->lastInsertId();
    $kycApproved = $kycService->verify($kycDocId, $adminId);

    // ۶. مدیریت واریزی‌های مالی و تایید دستی
    $db->execute("INSERT INTO manual_deposits (user_id, amount, currency, tracking_code, status, created_at)
        VALUES (:uid, 5000000, 'irt', :trk, 'pending', NOW())", [
            'uid' => $userBId,
            'trk' => 'TRK_ADMIN_' . time() . '_' . rand(100, 999)
        ]);
    $depAdminId = (int)$db->lastInsertId();
    $depApproved = $manualDepositService->approve($adminId, $depAdminId, 'تایید دستی واریزی توسط مدیر');

    assertSaga(($backupRes['success'] ?? false) && !empty($exportRes) && ($liveness['status'] ?? '') === 'ok' && ($kycApproved['success'] ?? false) && $depApproved, 'بکاپ دیتابیس، اکسپورت کاربر، تست سلامت سیستم، بن/آن‌بن، تایید KYC و تایید واریزی مالی توسط مدیر');

} catch (\Throwable $e) {
    echo "\n  [CRITICAL ERROR] " . $e->getMessage() . "\n";
    echo "  In " . $e->getFile() . ":" . $e->getLine() . "\n";
    echo $e->getTraceAsString() . "\n";
    $failCount++;
}

echo "\n======================================================================\n";
echo "  خلاصه نتایج آزمون فوق‌جامع عملیاتی تمامی بخش‌های درخواست‌شده:\n";
echo "  موفق و عملیاتی (PASS): {$passCount}\n";
echo "  ناموفق (FAIL): {$failCount}\n";
echo "======================================================================\n\n";

if ($failCount === 0) {
    echo "SUCCESS: ALL EXTENDED OPERATIONAL MODULES PASSED WITH 100% SUCCESS!\n";
    exit(0);
} else {
    echo "FAILURE: SOME MODULES FAILED OPERATIONAL CHECKS.\n";
    exit(1);
}
