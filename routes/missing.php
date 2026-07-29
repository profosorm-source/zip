<?php

/**
 * مسیرهای گمشده — اضافه‌شده پس از تقسیم‌بندی routes
 */

use App\Middleware\AuthMiddleware;
use App\Middleware\AdminMiddleware;
use App\Middleware\CSRFMiddleware;

// ── User Controllers ──────────────────────────────────────────────────────
use App\Controllers\User\PredictionController;
use App\Controllers\User\AdtubeController;
use App\Controllers\User\InfluencerController;
use App\Controllers\User\VitrineController;
// User SeoAdController removed — unified under AdsController
// UserBannerController definition removed

use App\Controllers\User\ManualDepositController;
use App\Controllers\User\CryptoDepositController;
use App\Controllers\User\WithdrawalController;
use App\Controllers\User\BankCardController as UserBankCardController;
use App\Controllers\User\AdTaskController   as UserAdTaskController;
use App\Controllers\User\LotteryController  as UserLotteryController;

// ── Admin Controllers ─────────────────────────────────────────────────────
use App\Controllers\Admin\PredictionController   as AdminPredictionController;
use App\Controllers\Admin\VitrineController      as AdminVitrineController;
use App\Controllers\Admin\SeoAdController        as AdminSeoAdController;
use App\Controllers\Admin\LogController          as AdminLogController;
use App\Controllers\Admin\FraudDashboardController;

$auth      = [AuthMiddleware::class];
$authCSRF  = [AuthMiddleware::class, CSRFMiddleware::class];
$admin     = [AuthMiddleware::class, AdminMiddleware::class];
$adminCSRF = [AuthMiddleware::class, AdminMiddleware::class, CSRFMiddleware::class];

$vitrineAuth     = array_merge($auth, [\App\Middleware\RequireFeature::class . ':vitrine_enabled']);
$vitrineAuthCSRF = array_merge($authCSRF, [\App\Middleware\RequireFeature::class . ':vitrine_enabled']);

$r         = app()->router;

// ── Metrics & Health ──────────────────────────────────────────────────────
$r->get('/metrics', [\App\Controllers\MetricsController::class, 'metrics']);

// ════════════════════════════════════════════════════════════════════════════
// USER ROUTES
// ════════════════════════════════════════════════════════════════════════════

// ── پیش‌بینی — Hub مستقل تک‌صفحه‌ای ─────────────────────────────────────────
$r->get('/prediction',            [PredictionController::class, 'index'],    $auth);     // PRIMARY / INDEPENDENT_MODULE / SINGLE_PAGE_HUB
$r->get('/prediction/my-bets',    [PredictionController::class, 'myBets'],   $auth);     // COMPATIBILITY_REDIRECT → /prediction?section=my-bets
$r->get('/prediction/{id}',       [PredictionController::class, 'show'],     $auth);     // PRIMARY_DETAIL / DIRECT_LINK_COMPATIBILITY
$r->post('/prediction/place-bet', [PredictionController::class, 'placeBet'], $authCSRF); // COMPATIBILITY_ACTION / LEGACY_FORM_ACTION
$r->post('/prediction/{id}/bet',  [PredictionController::class, 'placeBet'], $authCSRF); // PRIMARY_ACTION / ESCROW_HOLD

// ── تبلیغات ویدیویی (AdtubeController) ──────────────────────────────────────
// انجام‌دهنده
$r->get('/adtube',                             [AdtubeController::class, 'index'],       $auth);
$r->get('/adtube/history',                     [AdtubeController::class, 'history'],     $auth);
$r->post('/adtube/start',                      [AdtubeController::class, 'start'],       $authCSRF);
$r->get('/adtube/{id}/execute',                [AdtubeController::class, 'showExecute'], $auth);
$r->post('/adtube/{id}/submit',                [AdtubeController::class, 'submit'],      $authCSRF);
$r->post('/adtube/claim-boost',                [AdtubeController::class, 'claimBoost'],  $auth);
// تبلیغ‌دهنده
// ── AdTube Advertiser routes REMOVED: unified under /ads (AdsController)
// All AdTube ad creation/management now goes through /ads/create wizard.

// ── اینفلوئنسر — ماژول مستقل Marketplace / Hub تک‌صفحه‌ای ────────────────
// PRIMARY: تجربه اصلی همه spokeها داخل /influencer است.
$r->get('/influencer',                                [InfluencerController::class, 'myProfile'],       $auth);      // PRIMARY / INDEPENDENT_MODULE

// COMPATIBILITY_REDIRECT: مسیرهای قدیمی GET به تب‌های داخلی Hub هدایت می‌شوند.
$r->get('/influencer/register',                       [InfluencerController::class, 'register'],        $auth);      // COMPATIBILITY_REDIRECT → /influencer?section=profile
$r->get('/influencer/orders',                         [InfluencerController::class, 'myOrders'],        $auth);      // COMPATIBILITY_REDIRECT → /influencer?section=incoming
$r->get('/influencer/ads',                            [InfluencerController::class, 'advertise'],       $auth);      // COMPATIBILITY_REDIRECT → /influencer?section=market
$r->get('/influencer/ads/create',                     [InfluencerController::class, 'createOrder'],     $auth);      // COMPATIBILITY_REDIRECT → /influencer?section=market
$r->get('/influencer/ads/my-orders',                  [InfluencerController::class, 'myPlacedOrders'],  $auth);      // COMPATIBILITY_REDIRECT → /influencer?section=placed

// PRIMARY ACTIONS: این endpointها هنوز عملیات واقعی Hub/AJAX را انجام می‌دهند.
$r->post('/influencer/register',                      [InfluencerController::class, 'storeProfile'],    $authCSRF);  // PRIMARY_ACTION / PROFILE
$r->post('/influencer/verify',                        [InfluencerController::class, 'submitVerification'], $authCSRF); // PRIMARY_ACTION / SCREENSHOT_VERIFICATION
$r->post('/influencer/orders/{id}/respond',           [InfluencerController::class, 'respondOrder'],    $authCSRF);  // PRIMARY_ACTION / INFLUENCER_ONLY
$r->post('/influencer/orders/{id}/proof',             [InfluencerController::class, 'submitProof'],     $authCSRF);  // PRIMARY_ACTION / INFLUENCER_ONLY
$r->get('/influencer/orders/{id}/dispute',            [InfluencerController::class, 'disputePanel'],    $auth);      // PRIMARY_DETAIL / DISPUTE
$r->post('/influencer/orders/{id}/dispute/message',   [InfluencerController::class, 'sendDisputeMsg'],  $authCSRF);  // PRIMARY_ACTION / DISPUTE
$r->post('/influencer/orders/{id}/dispute/escalate',  [InfluencerController::class, 'escalateDispute'], $authCSRF);  // PRIMARY_ACTION / DISPUTE
$r->post('/influencer/orders/{id}/dispute/resolve',   [InfluencerController::class, 'resolveDisputePeer'], $authCSRF); // PRIMARY_ACTION / DISPUTE
$r->post('/influencer/ads/store',                     [InfluencerController::class, 'storeOrder'],      $authCSRF);  // PRIMARY_ACTION / BUYER
$r->post('/influencer/ads/orders/{id}/confirm',       [InfluencerController::class, 'buyerConfirm'],    $authCSRF);  // PRIMARY_ACTION / BUYER
$r->post('/influencer/ads/orders/{id}/dispute',       [InfluencerController::class, 'buyerDispute'],    $authCSRF);  // PRIMARY_ACTION / BUYER

// ── ویترین ─────────────────────────────────────────────────────────────────────
$r->get('/vitrine',                        [VitrineController::class, 'index'],          $vitrineAuth);
$r->get('/vitrine/wanted',                 [VitrineController::class, 'wantedIndex'],    $vitrineAuth);
$r->get('/vitrine/wanted/create',          [VitrineController::class, 'createWanted'],   $vitrineAuth);
$r->get('/vitrine/sell/create',            [VitrineController::class, 'create'],         $vitrineAuth);
$r->get('/vitrine/my-listings',            [VitrineController::class, 'myListings'],     $vitrineAuth);
$r->get('/vitrine/my-purchases',           [VitrineController::class, 'myPurchases'],    $vitrineAuth);
$r->get('/vitrine/my-requests',            [VitrineController::class, 'myRequests'],     $vitrineAuth);
$r->post('/vitrine/store',                 [VitrineController::class, 'store'],          $vitrineAuthCSRF);
$r->post('/vitrine/request/{rid}/accept',  [VitrineController::class, 'acceptRequest'],  $vitrineAuthCSRF);
$r->post('/vitrine/request/{rid}/reject',  [VitrineController::class, 'rejectRequest'],  $vitrineAuthCSRF);
$r->get('/vitrine/{id}',                   [VitrineController::class, 'show'],           $vitrineAuth);
$r->post('/vitrine/{id}/buy',              [VitrineController::class, 'buy'],            $vitrineAuthCSRF);
$r->post('/vitrine/{id}/request',          [VitrineController::class, 'sendRequest'],    $vitrineAuthCSRF);
$r->post('/vitrine/{id}/confirm',          [VitrineController::class, 'confirmDelivery'],$vitrineAuthCSRF);
$r->post('/vitrine/{id}/dispute',          [VitrineController::class, 'dispute'],        $vitrineAuthCSRF);
$r->post('/vitrine/{id}/watch',            [VitrineController::class, 'watch'],          $vitrineAuthCSRF);
// ── تبلیغ سئو (کاربر) ────────────────────────────────────────────────────────
// REMOVED: User SEO advertiser routes unified under /ads (AdsController)
// All SEO ad creation/management now goes through /ads/create (wizard)

// Banners now routed via banner-request in routes/user.php

// ════════════════════════════════════════════════════════════════════════════
// ADMIN ROUTES
// ════════════════════════════════════════════════════════════════════════════

// ── پیش‌بینی (ادمین) ─────────────────────────────────────────────────────────
$r->get('/admin/prediction',                     [AdminPredictionController::class, 'index'],       $admin);     // PRIMARY / ADMIN_ONLY / MODERN_LIST
$r->get('/admin/prediction/create',              [AdminPredictionController::class, 'create'],      $admin);     // PRIMARY / ADMIN_ONLY / CREATE_FORM
$r->post('/admin/prediction/store',              [AdminPredictionController::class, 'store'],       $adminCSRF); // PRIMARY_ACTION / ADMIN_ONLY
$r->get('/admin/prediction/{id}',                [AdminPredictionController::class, 'show'],        $admin);     // PRIMARY_DETAIL / ADMIN_ONLY
$r->post('/admin/prediction/{id}/settle',        [AdminPredictionController::class, 'settle'],      $adminCSRF); // PRIMARY_ACTION / ADMIN_SETTLEMENT
$r->post('/admin/prediction/{id}/update',        [AdminPredictionController::class, 'update'],      $adminCSRF); // PRIMARY_ACTION / ADMIN_ONLY
$r->post('/admin/prediction/{id}/cancel',        [AdminPredictionController::class, 'cancel'],      $adminCSRF); // PRIMARY_ACTION / ADMIN_REFUND
$r->post('/admin/prediction/{id}/close-betting', [AdminPredictionController::class, 'closeBetting'],$adminCSRF); // PRIMARY_ACTION / ADMIN_ONLY

// ── ادمین ویترین ─────────────────────────────────────────────────────────────
$r->get('/admin/vitrine',                    [AdminVitrineController::class, 'index'],       $admin);
$r->get('/admin/vitrine/settings',           [AdminVitrineController::class, 'settings'],    $admin);
$r->post('/admin/vitrine/settings/save',     [AdminVitrineController::class, 'saveSettings'],$adminCSRF);
$r->post('/admin/vitrine/{id}/approve',      [AdminVitrineController::class, 'approve'],     $adminCSRF);
$r->post('/admin/vitrine/{id}/reject',       [AdminVitrineController::class, 'reject'],      $adminCSRF);
$r->get('/admin/vitrine/{id}/dispute',       [AdminVitrineController::class, 'showDispute'], $admin);
$r->post('/admin/vitrine/{id}/resolve',      [AdminVitrineController::class, 'resolve'],     $adminCSRF);
$r->post('/admin/vitrine/{id}/release',      [AdminVitrineController::class, 'releaseFunds'],$adminCSRF);
$r->post('/admin/vitrine/{id}/refund',       [AdminVitrineController::class, 'refund'],      $adminCSRF);
// ── تبلیغ سئو (ادمین) ────────────────────────────────────────────────────────

// ── لاگ فعالیت‌ها (route گمشده: activityLogs) ────────────────────────────────
$r->get('/admin/logs/activity', [AdminLogController::class, 'activityLogs'], $admin);

// ── fraud — redirect مستقیم /admin/fraud به داشبورد fraud ───────────────────
$r->get('/admin/fraud', [FraudDashboardController::class, 'index'], $admin);

// ── کپچا (تنظیمات) ────────────────────────────────────────────────────────────
// /admin/captcha/settings از طریق SystemSettingController سرو می‌شود
// چون فرم آن به /admin/settings/update پست می‌کند (بررسی view تأیید کرد)
// ریدایرکت ساده به صفحه تنظیمات:
$r->get('/admin/captcha/settings', function() {
    app()->response->redirect(url('/admin/settings?section=captcha'));
}, $admin);

// ════════════════════════════════════════════════════════════════════════════
// WALLET SHORTCUTS — مسیرهای کوتاه که view ها مستقیم استفاده می‌کنند
// ════════════════════════════════════════════════════════════════════════════

// واریز دستی — shortcut
$r->get('/manual-deposit/create',   [ManualDepositController::class, 'create'], $auth);

// واریز کریپتو — shortcut
$r->get('/crypto-deposit/create',   [CryptoDepositController::class, 'create'], $auth);

// برداشت — shortcut
$r->get('/withdrawal/create',       [WithdrawalController::class, 'create'],     $auth);
$r->post('/withdrawal/challenge/request', [WithdrawalController::class, 'requestWithdrawalChallenge'], $authCSRF);
$r->post('/withdrawal/challenge/verify',  [WithdrawalController::class, 'verifyWithdrawalChallenge'],  $authCSRF);

// ════════════════════════════════════════════════════════════════════════════
// BANK CARDS — مسیرهای GET برای ایجاد/نمایش در user.php موجودند
// ════════════════════════════════════════════════════════════════════════════

// ════════════════════════════════════════════════════════════════════════════
// DASHBOARD SHORTCUTS — لینک‌های مستقیم داشبورد کاربر
// ════════════════════════════════════════════════════════════════════════════

// vote لاتاری از داشبورد (fetch مستقیم)
$r->post('/user/lottery/vote',   [UserLotteryController::class, 'vote'],  array_merge($authCSRF, [\App\Middleware\RequireFeature::class . ':lottery']));
