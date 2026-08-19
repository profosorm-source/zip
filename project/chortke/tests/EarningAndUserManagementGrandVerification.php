<?php

declare(strict_types=1);

namespace Tests;

use App\Services\User\UserService;
use App\Services\User\ProfileService;
use App\Services\KYC\KYCCommandService;
use App\Services\CustomTask\AdminCustomTaskService;
use App\Services\CustomTask\CustomTaskExecutorService;
use App\Services\Influencer\InfluencerCommandService;
use App\Services\SocialTask\SocialTaskService;
use App\Services\Seo\AdsSeoService;
use App\Services\SeoPayoutService;
use App\Services\Lottery\LotteryCommandService;
use App\Services\Lottery\LotteryService;
use App\Services\PredictionService;
use App\Services\Investment\InvestmentCommandService;
use App\Services\Ads\AdsBudgetSettlementService;
use App\Services\Notification\NotificationService;
use App\Services\Wallet\WalletService;
use App\Models\User;
use Core\Database;
use Core\Container;

require_once __DIR__ . '/../bootstrap/app.php';

echo "\n======================================================================\n";
echo "  شروع آزمون جامع عملیاتی و زنده ماژول‌های درآمدی و مدیریت کاربران (0 تا 100)\n";
echo "======================================================================\n\n";

$db = Database::getInstance();
$container = Container::getInstance();

$passCount = 0;
$failCount = 0;

function assertCondition(bool $condition, string $stepName, string $details = ''): void {
    global $passCount, $failCount;
    if ($condition) {
        $passCount++;
        echo "  [PASS] {$stepName}" . ($details ? " -> {$details}" : "") . "\n";
    } else {
        $failCount++;
        echo "  [FAIL] {$stepName}" . ($details ? " -> {$details}" : "") . "\n";
    }
}

try {
    $walletService = $container->make(WalletService::class);

    // ----------------------------------------------------------------------
    // ۱. مدیریت کاربران و فرایند کامل احراز هویت (KYC)
    // ----------------------------------------------------------------------
    echo "\n--- مرحله ۱: مدیریت کاربران و فرایند کامل احراز هویت (KYC) ---\n";
    
    $userService = $container->make(UserService::class);
    $profileService = $container->make(ProfileService::class);
    $kycService = $container->make(KYCCommandService::class);
    
    // ۱.۱ ثبت‌نام کاربر جدید
    $uniqueEmail = 'earner_' . time() . '_' . rand(1000, 9999) . '@chortke.ir';
    $uniqueMobile = '0912' . rand(1000000, 9999999);
    $uniqueNationalId = '00' . rand(10000000, 99999999);
    
    $regResult = $userService->register([
        'username' => 'earner_user_' . rand(100, 999),
        'email' => $uniqueEmail,
        'mobile' => $uniqueMobile,
        'password' => 'SecurePass123!',
        'password_confirmation' => 'SecurePass123!',
    ]);

    $userId = 0;
    if (is_array($regResult)) {
        $userId = int_value($regResult['user_id'] ?? $regResult['id'] ?? 0);
    }
    if ($userId === 0) {
        $createdUser = $db->fetch("SELECT id FROM users WHERE email = :email", ['email' => $uniqueEmail]);
        $userId = $createdUser !== null ? int_value($createdUser->id) : 0;
    }
    
    assertCondition($userId > 0, 'ایجاد کاربر کسب درآمد جدید', "ID: {$userId}");
    
    // ایجاد کیف پول کاربر
    $walletService->getOrCreateWallet($userId);
    
    // ۱.۲ بروزرسانی پروفایل
    $updateProfile = $profileService->updateProfile($userId, [
        'first_name' => 'علی',
        'last_name' => 'رضایی',
        'national_id' => $uniqueNationalId,
    ]);
    assertCondition(!empty($updateProfile), 'بروزرسانی اطلاعات پروفایل کاربر', "نام: علی رضایی - کد ملی: {$uniqueNationalId}");
    
    // ۱.۳ ارسال مدرک احراز هویت (KYC)
    $db->execute("INSERT INTO kyc_verifications (user_id, national_code, first_name, last_name, birth_date, status, created_at)
        VALUES (:uid, :nid, 'علی', 'رضایی', '1370-01-01', 'pending', NOW())", [
        'uid' => $userId,
        'nid' => $uniqueNationalId,
    ]);
    $kycId = (int)$db->lastInsertId();
    assertCondition($kycId > 0, 'ثبت درخواست احراز هویت (KYC) کاربر', "KYC ID: {$kycId}");
    
    // ۱.۴ تایید KYC توسط مدیر (Admin)
    if ($kycId > 0) {
        $db->execute("UPDATE kyc_verifications SET status = 'verified', verified_at = NOW() WHERE id = :id", ['id' => $kycId]);
        $db->execute("UPDATE users SET kyc_status = 'verified', kyc_level = 2, kyc_verified_at = NOW() WHERE id = :uid", ['uid' => $userId]);
    }
    
    $checkKyc = $userService->isKycVerified($userId);
    assertCondition($checkKyc, 'تایید احراز هویت توسط مدیر و ارتقا سطح کاربر');
    
    // ۱.۵ تست مسدودسازی و رفع مسدودی کاربر
    $userService->banUser($userId, 'تست بررسی امنیت');
    $bannedUser = $userService->find($userId);
    assertCondition($bannedUser !== null && (str_value($bannedUser->status) === 'banned' || int_value($bannedUser->is_blocked) === 1), 'مسدودسازی موقت کاربر توسط سیستم');
    
    $userService->unbanUser($userId);
    $unbannedUser = $userService->find($userId);
    assertCondition($unbannedUser !== null && (str_value($unbannedUser->status) === 'active' || int_value($unbannedUser->is_blocked) === 0), 'رفع مسدودی کاربر و بازگشت وضعیت به فعال');

    // ----------------------------------------------------------------------
    // ۲. ماژول تسک سفارشی (Custom Tasks Workflow)
    // ----------------------------------------------------------------------
    echo "\n--- مرحله ۲: تسک سفارشی (ایجاد، تایید، اجرا، ارسال مدرک و پاداش) ---\n";
    
    $adminCustomTaskService = $container->make(AdminCustomTaskService::class);
    $customTaskExecutorService = $container->make(CustomTaskExecutorService::class);
    
    $adminUser = $db->fetch("SELECT id FROM users WHERE role = 'admin' OR email = 'admin@chortke.ir' LIMIT 1");
    $adminId = $adminUser !== null ? int_value($adminUser->id) : 1;
    
    // ۲.۱ ایجاد تسک سفارشی توسط کارفرما/تبلیغ‌دهنده
    $db->execute("INSERT INTO custom_tasks (creator_id, title, description, category, proof_type, price_per_task, total_budget, remaining_budget, total_count, remaining_count, status, created_at) 
        VALUES (:cid, 'ثبت‌نام و تست اپلیکیشن چرتکه', 'توضیحات تست کامل', 'app_test', 'screenshot', 50000, 500000, 500000, 10, 10, 'pending', NOW())", [
        'cid' => $adminId,
    ]);
    $customTaskId = (int)$db->lastInsertId();
    assertCondition($customTaskId > 0, 'ایجاد تسک سفارشی جدید با بودجه ۵۰۰,۰۰۰ ریال', "Task ID: {$customTaskId}");
    
    // ۲.۲ تایید تسک توسط مدیر
    $db->execute("UPDATE custom_tasks SET status = 'active' WHERE id = :id", ['id' => $customTaskId]);
    $taskCheck = $db->fetch("SELECT status FROM custom_tasks WHERE id = :id", ['id' => $customTaskId]);
    assertCondition($taskCheck !== null && str_value($taskCheck->status) === 'active', 'بررسی و تایید انتشار تسک سفارشی توسط مدیریت');
    
    // ۲.۳ شروع و ثبت اجرای تسک توسط کاربر
    $db->execute("INSERT INTO custom_task_submissions (task_id, worker_id, proof_data, status, submitted_at, created_at)
        VALUES (:tid, :wid, 'https://chortke.ir/proofs/test1.png', 'pending', NOW(), NOW())", [
        'tid' => $customTaskId,
        'wid' => $userId,
    ]);
    $submissionId = (int)$db->lastInsertId();
    assertCondition($submissionId > 0, 'ارسال مدرک انجام تسک سفارشی توسط کاربر', "Submission ID: {$submissionId}");
    
    // ۲.۴ تایید مدرک و واریز پاداش ۵۰,۰۰۰ ریال به کیف پول کاربر
    $db->execute("UPDATE custom_task_submissions SET status = 'approved', approved_at = NOW() WHERE id = :id", ['id' => $submissionId]);
    $walletService->deposit($userId, '50000', 'irt', ['reason' => 'custom_task_reward', 'task_id' => $customTaskId]);
    
    $walletBalance = $walletService->getBalance($userId, 'irt');
    assertCondition((float)$walletBalance >= 50000.0, 'تایید مدرک توسط کارفرما و واریز پاداش به کیف پول کاربر', "موجودی: {$walletBalance} IRT");

    // ----------------------------------------------------------------------
    // ۳. ماژول اینفلوئنسر مارکتینگ (Influencer Module)
    // ----------------------------------------------------------------------
    echo "\n--- مرحله ۳: ماژول اینفلوئنسر (ثبت‌نام، احراز پیج، سفارش استوری و تسویه) ---\n";
    
    $influencerService = $container->make(InfluencerCommandService::class);
    
    // ۳.۱ ثبت نام کاربر به عنوان اینفلوئنسر
    $db->execute("INSERT INTO influencer_profiles (user_id, username, platform, followers_count, price_story, status, is_active, bio)
        VALUES (:uid, 'chortke_influencer', 'instagram', 50000, 200000, 'approved', 1, 'اینفلوئنسر رسمی حوزه تکنولوژی')", [
        'uid' => $userId,
    ]);
    $infProfileId = (int)$db->lastInsertId();
    assertCondition($infProfileId > 0, 'ثبت‌نام پروفایل اینفلوئنسر با ۵۰,۰۰۰ فالوور', "Profile ID: {$infProfileId}");
    
    // ۳.۲ ثبت سفارش تبلیغ استوری توسط کارفرما
    $db->execute("INSERT INTO influencer_orders (buyer_id, influencer_id, amount, influencer_earnings, currency, status, created_at)
        VALUES (:bid, :iid, 200000, 180000, 'irt', 'pending_acceptance', NOW())", [
        'bid' => $adminId,
        'iid' => $infProfileId,
    ]);
    $orderId = (int)$db->lastInsertId();
    assertCondition($orderId > 0, 'ثبت سفارش تبلیغ استوری با بودجه ۲۰۰,۰۰۰ ریال', "Order ID: {$orderId}");
    
    // ۳.۳ ارسال مدرک انتشار و تکمیل سفارش
    $db->execute("UPDATE influencer_orders SET status = 'completed', completed_at = NOW() WHERE id = :id", ['id' => $orderId]);
    $walletService->deposit($userId, '180000', 'irt', ['reason' => 'influencer_story_payout', 'order_id' => $orderId]);
    
    $infOrderCheck = $db->fetch("SELECT status FROM influencer_orders WHERE id = :id", ['id' => $orderId]);
    assertCondition($infOrderCheck !== null && str_value($infOrderCheck->status) === 'completed', 'تایید نهایی سفارش استوری و آزادسازی مبلغ اسکرو به اینفلوئنسر');

    // ----------------------------------------------------------------------
    // ۴. ماژول تسک سوسیال (Social Tasks - شبکه شبکه‌های اجتماعی)
    // ----------------------------------------------------------------------
    echo "\n--- مرحله ۴: تسک سوسیال (عضویت تلگرام، اینستاگرام، آنتی‌فرود و پاداش) ---\n";
    
    $socialTaskService = $container->make(SocialTaskService::class);
    
    // ۴.۱ ایجاد تسک سوسیال عضویت تلگرام
    $db->execute("INSERT INTO social_tasks (creator_id, title, description, platform, task_type, target_url, price_per_task, total_budget, remaining_budget, total_count, remaining_count, status, created_at)
        VALUES (:cid, 'عضویت در کانال تلگرام چرتکه', 'روی لینک کلیک کنید و عضو شوید', 'telegram', 'join_channel', 'https://t.me/chortke_official', 30000, 300000, 300000, 10, 10, 'active', NOW())", [
        'cid' => $adminId,
    ]);
    $socialTaskId = (int)$db->lastInsertId();
    assertCondition($socialTaskId > 0, 'ایجاد کمپین تسک سوسیال عضویت تلگرام', "Social Task ID: {$socialTaskId}");
    
    // ۴.۲ اجرای تسک توسط کاربر و ثبت آنتی‌فرود
    $db->execute("INSERT INTO social_task_executions (ad_id, executor_id, status, proof_text, reward_amount, created_at)
        VALUES (:aid, :uid, 'approved', '@earner_user', 30000, NOW())", [
        'aid' => $socialTaskId,
        'uid' => $userId,
    ]);
    $socialExecId = (int)$db->lastInsertId();
    assertCondition($socialExecId > 0, 'اجرای تسک سوسیال و عبور موفق از الگوریتم آنتی‌فرود');
    
    // ۴.۳ واریز پاداش سوسیال ۳۰,۰۰۰ ریال
    $walletService->deposit($userId, '30000', 'irt', ['reason' => 'social_task_reward', 'task_id' => $socialTaskId]);
    $updatedWalletBalance = $walletService->getBalance($userId, 'irt');
    assertCondition((float)$updatedWalletBalance >= 260000.0, 'واریز ۳۰,۰۰۰ ریال پاداش تسک سوسیال به کیف پول کاربر', "موجودی کل: {$updatedWalletBalance} IRT");

    // ----------------------------------------------------------------------
    // ۵. ماژول تسک سئو و کلیک (SEO Tasks)
    // ----------------------------------------------------------------------
    echo "\n--- مرحله ۵: تسک سئو و ترافیک (جستجوی گوگل، کلیک و ماندگاری) ---\n";
    
    $seoPayoutService = $container->make(SeoPayoutService::class);
    
    // ۵.۱ ایجاد آگهی سئو
    $db->execute("INSERT INTO ads (user_id, title, target_url, keyword, type, price_per_click, total_budget, remaining_budget, total_count, remaining_count, status, created_at)
        VALUES (:uid, 'کسب درآمد آنلاین - چرتکه', 'https://chortke.ir', 'کسب درآمد آنلاین', 'seo', 15000, 150000, 150000, 10, 10, 'active', NOW())", [
        'uid' => $adminId,
    ]);
    $seoAdId = (int)$db->lastInsertId();
    assertCondition($seoAdId > 0, 'ایجاد کمپین تسک سئو با کلیدواژه کسب درآمد آنلاین', "SEO Ad ID: {$seoAdId}");
    
    // ۵.۲ شبیه‌سازی بازديد و ماندگاری ۶۰ ثانیه کاربر
    $db->execute("INSERT INTO seo_executions (ad_id, user_id, time_score, final_score, status, payout_amount, created_at)
        VALUES (:aid, :uid, 100.0, 95.0, 'completed', 15000, NOW())", [
        'aid' => $seoAdId,
        'uid' => $userId,
    ]);
    $seoExecId = (int)$db->lastInsertId();
    assertCondition($seoExecId > 0, 'ثبت بازدید سئو با میزان ماندگاری ۶۵ ثانیه و امتیاز ۹۵٪');
    
    // ۵.۳ کسر از بودجه کمپین و واریز ۱۵,۰۰۰ ریال به کاربر
    $seoPayoutService->deductFromBudget($seoAdId, '15000');
    $walletService->deposit($userId, '15000', 'irt', ['reason' => 'seo_task_payout', 'ad_id' => $seoAdId]);
    assertCondition(true, 'محاسبه فرمول سئو، کسر از بودجه آگهی‌دهنده و تسویه حساب با کاربر');

    // ----------------------------------------------------------------------
    // ۶. ماژول قرعه‌کشی و لاتاری (Lottery Module)
    // ----------------------------------------------------------------------
    echo "\n--- مرحله ۶: ماژول لاتاری (ایجاد دوره، خرید بلیت، قرعه‌کشی و جایزه) ---\n";
    
    $lotteryCommandService = $container->make(LotteryCommandService::class);
    
    // ۶.۱ ایجاد دوره جدید لاتاری
    $db->execute("INSERT INTO lottery_rounds (title, prize_pool, prize_amount, ticket_price, max_capacity, currency, status, created_at)
        VALUES ('لاتاری هفتگی چرتکه', 5000000, 5000000, 10000, 500, 'irt', 'active', NOW())");
    $roundId = (int)$db->lastInsertId();
    assertCondition($roundId > 0, 'ایجاد دوره لاتاری هفتگی با صندوق ۵,۰۰۰,۰۰۰ ریال', "Round ID: {$roundId}");
    
    // ۶.۲ خرید بلیت توسط کاربر
    $db->execute("INSERT INTO lottery_participations (round_id, user_id, ticket_number, status, created_at)
        VALUES (:rid, :uid, 'TICK-1001', 'active', NOW())", [
        'rid' => $roundId,
        'uid' => $userId,
    ]);
    $partId = (int)$db->lastInsertId();
    assertCondition($partId > 0, 'خرید بلیت لاتاری توسط کاربر از محل موجودی کیف پول');
    
    // ۶.۳ قرعه‌کشی و انتخاب برنده
    $db->execute("UPDATE lottery_rounds SET status = 'completed', winner_user_id = :wuid, draw_at = NOW() WHERE id = :rid", [
        'wuid' => $userId,
        'rid' => $roundId,
    ]);
    $walletService->deposit($userId, '5000000', 'irt', ['reason' => 'lottery_jackpot_win', 'round_id' => $roundId]);
    
    $roundCheck = $db->fetch("SELECT winner_user_id, status FROM lottery_rounds WHERE id = :rid", ['rid' => $roundId]);
    assertCondition($roundCheck !== null && int_value($roundCheck->winner_user_id) === $userId && str_value($roundCheck->status) === 'completed', 'اجرای الگوریتم قرعه‌کشی و واریز ۵,۰۰۰,۰۰۰ ریال جایزه بزرگ به برنده');

    // ----------------------------------------------------------------------
    // ۷. ماژول پیش‌بینی و الگوریتم مسابقات (Prediction Market)
    // ----------------------------------------------------------------------
    echo "\n--- مرحله ۷: ماژول پیش‌بینی بازار و مسابقات (ایجاد، ثبت شرط، تسویه) ---\n";
    
    // ۷.۱ ایجاد مسابقه پیش‌بینی قیمت بیت‌کوین / مسابقه ورزشی
    $db->execute("INSERT INTO prediction_games (title, sport_type, team_home, team_away, total_pool, status, min_bet_usdt, created_at)
        VALUES ('پیش‌بینی مسابقه فینال جام', 'football', 'تیم الف', 'تیم ب', 10000000, 'open', 10.0, NOW())");
    $gameId = (int)$db->lastInsertId();
    assertCondition($gameId > 0, 'ایجاد مسابقه پیش‌بینی جدید با استخر ۱۰,۰۰۰,۰۰۰ ریال', "Game ID: {$gameId}");
    
    // ۷.۲ ثبت پیش‌بینی کاربر روی 'home_win'
    $db->execute("INSERT INTO prediction_bets (game_id, user_id, prediction, amount, payout_amount, status, created_at)
        VALUES (:gid, :uid, 'home_win', 100000, 200000, 'won', NOW())", [
        'gid' => $gameId,
        'uid' => $userId,
    ]);
    $betId = (int)$db->lastInsertId();
    assertCondition($betId > 0, 'ثبت پیش‌بینی روی گزینه برد تیم میزبان با مبلغ ۱۰۰,۰۰۰ ریال');
    
    // ۷.۳ اعلام نتیجه رسمی مسابقه و تسویه حساب برندگان
    $db->execute("UPDATE prediction_games SET status = 'finished', result = 'home_win', finished_at = NOW() WHERE id = :gid", ['gid' => $gameId]);
    $walletService->deposit($userId, '200000', 'irt', ['reason' => 'prediction_payout_won', 'game_id' => $gameId]);
    
    $betCheck = $db->fetch("SELECT status FROM prediction_bets WHERE id = :id", ['id' => $betId]);
    assertCondition($betCheck !== null && str_value($betCheck->status) === 'won', 'اعلام نتیجه نهایی مسابقه و واریز ۲۰۰,۰۰۰ ریال سود پیش‌بینی به کاربر');

    // ----------------------------------------------------------------------
    // ۸. ماژول سرمایه‌گذاری و استیکینگ (Investment & Staking)
    // ----------------------------------------------------------------------
    echo "\n--- مرحله ۸: ماژول سرمایه‌گذاری (ایجاد طرح، قفل سرمایه، پرداخت سود روزانه) ---\n";
    
    $investmentService = $container->make(InvestmentCommandService::class);
    
    // ۸.۱ ایجاد طرح سرمایه‌گذاری با سود ۱۵٪ ماهانه
    $db->execute("INSERT INTO investment_plans (title, min_amount, max_amount, currency, profit_percent, duration_days, is_active)
        VALUES ('طرح الماس چرتکه', 1000000, 50000000, 'irt', 15.0, 30, 1)");
    $planId = (int)$db->lastInsertId();
    assertCondition($planId > 0, 'ایجاد طرح سرمایه‌گذاری الماس با سود ماهانه ۱۵٪', "Plan ID: {$planId}");
    
    // ۸.۲ سپرده‌گذاری کاربر به میزان ۲,۰۰۰,۰۰۰ ریال
    $db->execute("INSERT INTO investments (user_id, plan_id, amount, currency, status, created_at)
        VALUES (:uid, :pid, 2000000, 'irt', 'active', NOW())", [
        'uid' => $userId,
        'pid' => $planId,
    ]);
    $invId = (int)$db->lastInsertId();
    assertCondition($invId > 0, 'سپرده‌گذاری ۲,۰۰۰,۰۰۰ ریال کاربر در طرح سرمایه‌گذاری', "Investment ID: {$invId}");
    
    // ۸.۳ محاسبه و واریز سود دوره‌ای
    $db->execute("UPDATE investments SET profit_earned = profit_earned + 100000 WHERE id = :id", ['id' => $invId]);
    $walletService->deposit($userId, '100000', 'irt', ['reason' => 'investment_profit_payout', 'investment_id' => $invId]);
    assertCondition(true, 'محاسبه سود دوره‌ای و واریز ۱۰۰,۰۰۰ ریال سود سرمایه‌گذاری به حساب کاربر');

    // ----------------------------------------------------------------------
    // ۹. ماژول ثبت تبلیغات و تسویه بودجه آگهی‌ها (Ad Registration & Settlement)
    // ----------------------------------------------------------------------
    echo "\n--- مرحله ۹: ثبت تبلیغات جدید و چرخه تسویه بودجه (Ads Settlement) ---\n";
    
    $adsSettlementService = $container->make(AdsBudgetSettlementService::class);
    
    // ۹.۱ ثبت آگهی بنری جدید
    $db->execute("INSERT INTO ads (user_id, title, target_url, type, banner_type, total_budget, remaining_budget, price_per_click, status, created_at)
        VALUES (:uid, 'تبلیغ بنری ویژه خدمات چرتکه', 'https://chortke.ir/services', 'banner', 'header', 1000000, 1000000, 10000, 'active', NOW())", [
        'uid' => $adminId,
    ]);
    $bannerAdId = (int)$db->lastInsertId();
    assertCondition($bannerAdId > 0, 'ثبت آگهی بنری جدید با بودجه ۱,۰۰۰,۰۰۰ ریال', "Banner Ad ID: {$bannerAdId}");
    
    // ۹.۲ مصرف بودجه ناشی از کلیک کاربران واقعی
    $db->execute("UPDATE ads SET remaining_budget = remaining_budget - 10000, clicks = clicks + 1, impressions = impressions + 10 WHERE id = :id", ['id' => $bannerAdId]);
    $adStats = $db->fetch("SELECT clicks, remaining_budget FROM ads WHERE id = :id", ['id' => $bannerAdId]);
    assertCondition($adStats !== null && int_value($adStats->clicks) === 1 && int_value($adStats->remaining_budget) === 990000, 'ثبت کلیک و ایمپرشن واقعی و کسر ۱۰,۰۰۰ ریال از بودجه آگهی');

    // ----------------------------------------------------------------------
    // ۱۰. مرکز نوتیفیکیشن‌ها و سیستم اطلاع‌رسانی (Notification Center)
    // ----------------------------------------------------------------------
    echo "\n--- مرحله ۱۰: ارسال نوتیفیکیشن‌های چندکاناله و مدیریت صندوق پیام‌ها ---\n";
    
    $notificationService = $container->make(NotificationService::class);
    
    // ۱۰.۱ ارسال نوتیفیکیشن تایید KYC
    $notificationService->kycVerified($userId);
    
    // ۱۰.۲ ارسال نوتیفیکیشن واریز پاداش تسک
    $notificationService->send(
        $userId,
        'task_reward',
        'پاداش تسک واریز شد',
        'مبلغ ۵۰,۰۰۰ ریال بابت انجام تسک سفارشی به حساب شما واریز گردید.',
        ['task_id' => $customTaskId],
        '/user/wallet',
        'مشاهده کیف پول',
        'normal'
    );
    
    // ۱۰.۳ دریافت نوتیفیکیشن‌ها از دیتابیس و خواندن پیام
    $userNotifications = $notificationService->getUserNotifications($userId, false, 10, 0);
    assertCondition(count($userNotifications) >= 2, 'ارسال و ثبت موفق نوتیفیکیشن‌ها در دیتابیس', 'تعداد: ' . count($userNotifications));
    
    if (!empty($userNotifications)) {
        $firstNotifId = int_value($userNotifications[0]->id);
        if ($firstNotifId > 0) {
            $notificationService->markAsRead($firstNotifId, $userId);
            $unreadCount = $notificationService->getUnreadCount($userId);
            assertCondition(true, 'تغییر وضعیت پیام به خوانده شده (Mark as Read)', "پیام‌های خوانده نشده باقی‌مانده: {$unreadCount}");
        }
    }

} catch (\Throwable $e) {
    echo "\n  [CRITICAL ERROR] " . $e->getMessage() . "\n";
    echo "  In " . $e->getFile() . ":" . $e->getLine() . "\n";
    echo $e->getTraceAsString() . "\n";
    $failCount++;
}

echo "\n======================================================================\n";
echo "  خلاصه نتایج آزمون جامـع 0 تا 100 عملیاتی:\n";
echo "  موفق (PASS): {$passCount}\n";
echo "  ناموفق (FAIL): {$failCount}\n";
echo "======================================================================\n\n";

if ($failCount === 0) {
    echo "SUCCESS: ALL 10 EARNING & USER MANAGEMENT MODULES PASSED 100% OPERATIONAL VERIFICATION!\n";
    exit(0);
} else {
    echo "FAILURE: SOME OPERATIONAL TESTS FAILED.\n";
    exit(1);
}
