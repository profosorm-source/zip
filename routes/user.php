<?php

/**
 * مسیرهای پنل کاربری — همه نیاز به AuthMiddleware دارند
 */

use App\Middleware\AuthMiddleware;
use App\Middleware\AdvancedFraudMiddleware;
use App\Middleware\CSRFMiddleware;
use App\Middleware\RateLimitMiddleware;
use App\Middleware\ConcurrentRequestMiddleware;
use App\Middleware\RequireFeature;
use App\Controllers\User\DashboardController    as UserDashboardController;
use App\Controllers\User\ProfileController;
use App\Controllers\User\SettingsController;
use App\Controllers\User\SessionController      as UserSessionController;
use App\Controllers\User\KYCController          as UserKYCController;
use App\Controllers\User\BankCardController     as UserBankCardController;
use App\Controllers\User\NotificationController as UserNotificationController;
use App\Controllers\User\TwoFactorController;
use App\Controllers\User\SocialAccountController;
use App\Controllers\User\SeoController;
use App\Controllers\User\CustomTaskController;
use App\Controllers\User\ContentController;
use App\Controllers\User\InvestmentController       as UserInvestmentController;
use App\Controllers\User\LotteryController          as UserLotteryController;
use App\Controllers\User\ReferralController         as UserReferralController;
use App\Controllers\User\LevelController            as UserLevelController;
use App\Controllers\User\BugReportController        as UserBugReportController;
use App\Controllers\User\CouponController;
use App\Controllers\SearchController;
// CustomTaskAdController removed — unified under AdsController
use App\Controllers\User\SocialTaskController;
use App\Controllers\User\TicketController;
use App\Controllers\Api\SocialTaskApiController;

use App\Controllers\User\MessageController;
use App\Controllers\User\AdsController;
use App\Controllers\User\AdsApiController;
use App\Controllers\User\TaskFeedController;
use App\Controllers\User\DisputeController;
use App\Controllers\User\ApiTokenController;

$auth     = [AuthMiddleware::class];
$authCSRF = [AuthMiddleware::class, CSRFMiddleware::class, RateLimitMiddleware::class . ':15,1'];
$authF    = [AuthMiddleware::class, AdvancedFraudMiddleware::class];
$secure   = [AuthMiddleware::class, CSRFMiddleware::class, ConcurrentRequestMiddleware::class, AdvancedFraudMiddleware::class, RateLimitMiddleware::class];

// Feature-flag aware middleware groups
$investmentAuth  = array_merge($auth, [RequireFeature::class . ':investment', RateLimitMiddleware::class . ':10,1']);
$investmentSecure = array_merge($secure, [RequireFeature::class . ':investment']);
$tasksAuth       = array_merge($auth, [RequireFeature::class . ':tasks']);
$tasksSecure     = array_merge($secure, [RequireFeature::class . ':tasks']);
$referralAuth    = array_merge($auth, [RequireFeature::class . ':referral']);
$couponsAuth     = array_merge($auth, [RequireFeature::class . ':coupons']);
$couponsSecure   = array_merge($secure, [RequireFeature::class . ':coupons']);
$r        = app()->router;

// ── داشبورد ──────────────────────────────────────────────────────────────
$r->get('/dashboard', [UserDashboardController::class, 'index'], $authF);

// ── پروفایل ──────────────────────────────────────────────────────────────
$r->get('/profile',                  [ProfileController::class, 'index'],          $auth);
$r->post('/profile/update',          [ProfileController::class, 'update'],         $authCSRF);
$r->post('/profile/change-password', [ProfileController::class, 'changePassword'], $authCSRF);
$r->post('/profile/upload-avatar',   [ProfileController::class, 'uploadAvatar'],   $authCSRF);
$r->post('/profile/delete-avatar',   [ProfileController::class, 'deleteAvatar'],   $authCSRF);

// ── احراز هویت دو مرحله‌ای ───────────────────────────────────────────────
$r->post('/two-factor/authorize', [TwoFactorController::class, 'authorizeSetup'], $authCSRF);
$r->get('/two-factor',         [TwoFactorController::class, 'index'],   $auth);
$r->get('/two-factor/qr',      [TwoFactorController::class, 'qrCode'],  $secure);
$r->post('/two-factor/enable', [TwoFactorController::class, 'enable'],  $authCSRF);
$r->post('/two-factor/disable',[TwoFactorController::class, 'disable'], $authCSRF);

// ── جلسات فعال ───────────────────────────────────────────────────────────
$r->get('/sessions',                      [UserSessionController::class, 'index'],     $auth);
$r->post('/sessions/terminate/{id}',      [UserSessionController::class, 'terminate'], $authCSRF);

// ── KYC ──────────────────────────────────────────────────────────────────
$r->get('/kyc',           [UserKYCController::class, 'index'],  $auth);
$r->get('/kyc/upload',    [UserKYCController::class, 'upload'], $auth);
$r->post('/kyc/submit',   [UserKYCController::class, 'submit'], $authCSRF);
$r->get('/kyc/status',    [UserKYCController::class, 'status'], $auth);
$r->get('/kyc/show/{id}', [UserKYCController::class, 'show'],   $auth);

// ── کارت‌های بانکی ────────────────────────────────────────────────────────
$r->get('/bank-cards',                    [UserBankCardController::class, 'index'],      $auth);
$r->get('/bank-cards/create',             [UserBankCardController::class, 'create'],     $auth);
$r->post('/bank-cards/store',             [UserBankCardController::class, 'store'],      $authCSRF);
$r->post('/bank-cards/delete/{id}',       [UserBankCardController::class, 'delete'],     $authCSRF);
$r->post('/bank-cards/set-default/{id}',  [UserBankCardController::class, 'setDefault'], $authCSRF);

// ── ویترین یکپارچه تسک‌ها ──────────────────────────────────────────────────
$r->get('/tasks', [TaskFeedController::class, 'index'], $tasksAuth);

// ── اعلان‌ها ──────────────────────────────────────────────────────────────
$r->get('/notifications',                          [UserNotificationController::class, 'index'],             $auth);
$r->get('/notifications/get',                      [UserNotificationController::class, 'get'],               $auth);
$r->get('/notifications/unread-count',             [UserNotificationController::class, 'unreadCount'],       $auth);
$r->get('/notifications/preferences',              [UserNotificationController::class, 'preferences'],       $auth);
$r->post('/notifications/mark-read',               [UserNotificationController::class, 'markAsRead'],        $authCSRF);
$r->post('/notifications/mark-all-read',           [UserNotificationController::class, 'markAllAsRead'],     $authCSRF);
$r->post('/notifications/archive',                 [UserNotificationController::class, 'archive'],           $authCSRF);
$r->post('/notifications/preferences/update',      [UserNotificationController::class, 'updatePreferences'], $authCSRF);
$r->get('/notifications/poll',                     [UserNotificationController::class, 'poll'],             $auth);
$r->get('/notifications/click',                    [UserNotificationController::class, 'click'],            $auth);
$r->post('/notifications/delete',                  [UserNotificationController::class, 'delete'],           $authCSRF);

// ── مرکز حل اختلافات (Dispute Resolution Center) ──────────────────────────
$r->get('/disputes',                [DisputeController::class, 'index'],      $auth);
$r->get('/disputes/{id}',           [DisputeController::class, 'show'],       $auth);
$r->post('/disputes/{id}/reply',    [DisputeController::class, 'addMessage'], $authCSRF);

// ── تیکت‌ها ───────────────────────────────────────────────────────────────────
$r->get('/tickets',             [TicketController::class, 'index'],  $auth);
$r->get('/tickets/create',      [TicketController::class, 'create'], $auth);
$r->post('/tickets/store',      [TicketController::class, 'store'],  $authCSRF);
$r->get('/tickets/show/{id}',   [TicketController::class, 'show'],   $auth);
$r->post('/tickets/reply',      [TicketController::class, 'reply'],  $authCSRF);
$r->post('/tickets/close',      [TicketController::class, 'close'],  $authCSRF);

$r->post('/notifications/fcm-token',               [UserNotificationController::class, 'saveFcmToken'],     $authCSRF);

// ── تنظیمات کاربر ──────────────────────────────────────────────────────────
$r->get('/settings/general',                       [SettingsController::class, 'general'],                   $auth);
$r->post('/settings/general/update',               [SettingsController::class, 'updateGeneral'],             $authCSRF);
$r->get('/settings/privacy',                       [SettingsController::class, 'privacy'],                   $auth);
$r->post('/settings/privacy/update',               [SettingsController::class, 'updatePrivacy'],             $authCSRF);
$r->get('/settings/security',                      [SettingsController::class, 'security'],                  $auth);
$r->post('/settings/security/update',              [SettingsController::class, 'updateSecurity'],            $authCSRF);
$r->get('/settings/notifications',                 [UserNotificationController::class, 'preferences'],        $auth);
$r->post('/settings/notifications/update',         [UserNotificationController::class, 'updatePreferences'],  $authCSRF);
$r->get('/settings/data-export',                   [SettingsController::class, 'dataExport'],                $auth);
$r->get('/settings/account-deletion',              [SettingsController::class, 'accountDeletion'],           $auth);
$r->post('/settings/account-deletion/request',     [SettingsController::class, 'requestAccountDeletion'],    $authCSRF);
$r->post('/settings/account-deletion/cancel',      [SettingsController::class, 'cancelAccountDeletion'],     $authCSRF);

// ── حساب‌های اجتماعی ──────────────────────────────────────────────────────
$r->get('/social-accounts',              [SocialAccountController::class, 'index'],      $auth);
$r->get('/social-accounts/create',       [SocialAccountController::class, 'showCreate'], $auth);
$r->post('/social-accounts/store',       [SocialAccountController::class, 'store'],      $authCSRF);
$r->get('/social-accounts/{id}/edit',    [SocialAccountController::class, 'showEdit'],   $auth);
$r->post('/social-accounts/{id}/update', [SocialAccountController::class, 'update'],     $authCSRF);
$r->post('/social-accounts/{id}/delete', [SocialAccountController::class, 'delete'],     $authCSRF);

// ============================================
// USER - Worker (انجام‌دهنده)
// ============================================

$r->get('/seo',                [SeoController::class, 'index'],       $auth);
$r->get('/seo/history',        [SeoController::class, 'history'],     $auth);
$r->post('/seo/start',         [SeoController::class, 'start'],       $authCSRF);
$r->get('/seo/{id}/execute',   [SeoController::class, 'execute'], $auth);
$r->post('/seo/{id}/complete', [SeoController::class, 'complete'],    $authCSRF);
$r->get('/seo/execution/{id}',   [SeoController::class, 'showExecution'], $auth);
$r->post('/seo/{id}/cancel', [SeoController::class, 'cancel'],    $authCSRF);

// ── کسب درآمد از محتوا (Independent Module / PRIMARY) ─────────────────────
$r->get('/content',              [ContentController::class, 'index'],    $auth);
$r->get('/content/create',       [ContentController::class, 'create'],   $auth);
$r->post('/content/store',       [ContentController::class, 'store'],    $authCSRF);
$r->get('/content/revenues',     [ContentController::class, 'revenues'], $auth);
$r->get('/content/{id}',         [ContentController::class, 'show'],     $auth);

// لیست وظایف تبلیغ‌دهنده (My Ads)
$r->get('/custom-tasks', [CustomTaskController::class, 'index'], $tasksAuth);

// لیست وظایف موجود برای انجام (Worker)
$r->get('/custom-tasks/available', [CustomTaskController::class, 'available'], $tasksAuth);

// تاریخچه انجام‌های من
$r->get('/custom-tasks/my-submissions', [CustomTaskController::class, 'mySubmissions'], $tasksAuth);

// ایجاد وظیفه جدید
// CustomTask advertiser routes REMOVED: unified under /ads (AdsController)

// فرم ارسال مدرک برای اجرای تسک سفارشی (PRIMARY / EXECUTOR_ONLY)
$r->get('/custom-tasks/submissions/{id}/proof', [CustomTaskController::class, 'proofForm'], $tasksAuth);

// جزئیات اختلاف تسک سفارشی (PRIMARY / EXECUTOR_ONLY)
$r->get('/custom-tasks/disputes/{id}', [CustomTaskController::class, 'disputeDetail'], $tasksAuth);
$r->post('/custom-tasks/disputes/{id}/reply', [CustomTaskController::class, 'replyDispute'], $tasksSecure);

// جزئیات وظیفه و submission ها
$r->get('/custom-tasks/{id}', [CustomTaskController::class, 'show'], $tasksAuth);

// شروع انجام تسک (Ajax)
$r->post('/custom-tasks/start', [CustomTaskController::class, 'start'], $tasksSecure);

// ارسال مدرک (Ajax)
$r->post('/custom-tasks/{id}/submit-proof', [CustomTaskController::class, 'submitProof'], $tasksSecure);

// تایید/رد توسط تبلیغ‌دهنده (Ajax)
$r->post('/custom-tasks/review', [CustomTaskController::class, 'review'], $tasksSecure);

// ── تبلیغات استوری ────────────────────────────────────────────────────────
// InfluencerController routes are in routes/missing.php

// ── Legacy Social-Ads advertiser routes REMOVED: unified under /ads (AdsController)

// Execution approval/rejection remain on legacy path for backward compatibility
// until fully migrated to /ads/{id}/execution/{executionId}/approve
$r->get('/social-ads/execution/{id}',       [SocialTaskController::class, 'executionDetail'],    $tasksAuth);
$r->post('/social-ads/execution/{id}/approve', [SocialTaskController::class, 'approveExecution'],   $tasksSecure);
$r->post('/social-ads/execution/{id}/reject', [SocialTaskController::class, 'rejectExecution'],    $tasksSecure);

// ── پیام‌های مستقیم ──────────────────────────────────────────────────────────
$r->get('/messages',                        [MessageController::class, 'index'],          $auth);
$r->get('/messages/{id}',                   [MessageController::class, 'show'],           $auth);
$r->post('/messages/send',                  [MessageController::class, 'send'],           $authCSRF);
$r->post('/messages/{id}/delete',           [MessageController::class, 'delete'],         $authCSRF);
$r->post('/messages/typing',                [MessageController::class, 'setTyping'],      $authCSRF);
$r->get('/messages/typing/users',           [MessageController::class, 'getTypingUsers'], $auth);
$r->post('/messages/{id}/reaction',         [MessageController::class, 'addReaction'],    $authCSRF);
$r->get('/messages/unread/count',           [MessageController::class, 'getUnreadCount'], $auth);

// ── سرمایه‌گذاری ──────────────────────────────────────────────────────────
$r->get('/investment',                 [UserInvestmentController::class, 'index'],         $investmentAuth);
$r->get('/investment/create',          [UserInvestmentController::class, 'create'],        $investmentAuth);
$r->post('/investment/store',          [UserInvestmentController::class, 'store'],         $investmentSecure);
$r->post('/investment/withdraw',       [UserInvestmentController::class, 'withdraw'],      $investmentSecure);
$r->get('/investment/profit-history',  [UserInvestmentController::class, 'profitHistory'], $investmentAuth);

// ── قرعه‌کشی ──────────────────────────────────────────────────────────────
$r->get('/lottery',       [UserLotteryController::class, 'index'], array_merge($auth, [RequireFeature::class . ':lottery']));
$r->post('/lottery/join', [UserLotteryController::class, 'join'],  array_merge($authCSRF, [RequireFeature::class . ':lottery']));
$r->post('/lottery/vote', [UserLotteryController::class, 'vote'],  array_merge($authCSRF, [RequireFeature::class . ':lottery']));

// ── زیرمجموعه‌گیری ────────────────────────────────────────────────────────
$r->get('/referral',                [UserReferralController::class, 'index'],        $referralAuth);
$r->get('/referral/commissions',    [UserReferralController::class, 'commissions'],  $referralAuth);
$r->get('/referral/referred-users', [UserReferralController::class, 'referredUsers'],$referralAuth);

// ── سطح‌بندی ──────────────────────────────────────────────────────────────
$r->get('/level',           [UserLevelController::class, 'index'],    $auth);
$r->post('/level/purchase', [UserLevelController::class, 'purchase'], $secure);

// ── گزارش باگ ─────────────────────────────────────────────────────────────
$r->get('/bug-reports',                   [UserBugReportController::class, 'index'],      $auth);
$r->post('/bug-reports/store',            [UserBugReportController::class, 'store'],      $authCSRF);
$r->get('/bug-reports/{id}',              [UserBugReportController::class, 'show'],       $auth);
$r->post('/bug-reports/{id}/comment',     [UserBugReportController::class, 'addComment'], $authCSRF);

// ── توکن‌های API کاربر ────────────────────────────────────────────────────
$r->get('/api-tokens',              [ApiTokenController::class, 'index'],  $auth);
$r->post('/api-tokens/create',      [ApiTokenController::class, 'create'], $authCSRF);
$r->post('/api-tokens/{id}/revoke', [ApiTokenController::class, 'revoke'], $authCSRF);

// ── کوپن ─────────────────────────────────────────────────────────────────
$r->post('/coupons/validate', [CouponController::class, 'validate'], $couponsSecure);
$r->get('/coupons/history',   [CouponController::class, 'history'],  $couponsAuth);

/*
|--------------------------------------------------------------------------
| Social Tasks — Executor
|--------------------------------------------------------------------------
*/
$r->get('/social-tasks',                [SocialTaskController::class, 'index'],              $tasksAuth);
$r->get('/social-tasks/dashboard',      [SocialTaskController::class, 'executorDashboard'],  $tasksAuth);
$r->get('/social-tasks/history',        [SocialTaskController::class, 'history'],            $tasksAuth);
$r->post('/social-tasks/start',         [SocialTaskController::class, 'start'],              $tasksSecure);
$r->get('/social-tasks/{id}/execute',   [SocialTaskController::class, 'showExecute'],        $tasksAuth);
$r->post('/social-tasks/{id}/submit',   [SocialTaskController::class, 'submit'],             $tasksSecure);
$r->get('/social-tasks/{id}/rate',        [SocialTaskController::class, 'rateExecutionForm'],  $tasksAuth);
$r->post('/social-tasks/{id}/rate',       [SocialTaskController::class, 'rateExecution'],      $tasksSecure);
$r->get('/social-ratings/history',        [SocialTaskController::class, 'ratingHistory'],     $tasksAuth);

/*
|--------------------------------------------------------------------------
| Social Ads — Advertiser
|--------------------------------------------------------------------------
*/


/*
|--------------------------------------------------------------------------
| Social Tasks — API (موبایل)
|--------------------------------------------------------------------------
*/
$r->post('/api/social-tasks/behavior',        [SocialTaskApiController::class, 'recordBehavior'], $tasksSecure);
$r->post('/api/social-tasks/camera-verify',   [SocialTaskApiController::class, 'cameraVerify'],   $tasksSecure);
$r->get('/api/social-tasks/trust-status',     [SocialTaskApiController::class, 'trustStatus'],    $tasksAuth);

// ── جستجو ─────────────────────────────────────────────────────────────────
$r->get('/search',      [SearchController::class, 'fullResults'], $auth);
$r->get('/search/ajax', [SearchController::class, 'userSearch'],  $auth);

// ── آگهی‌های من - سیستم متمرکز و یکپارچه جدید ──
$r->get('/ads',               [AdsController::class, 'index'],        $tasksAuth);
$r->get('/ads/create',        [AdsController::class, 'create'],       $tasksAuth);
$r->post('/ads/store',        [AdsController::class, 'store'],        $tasksSecure);
$r->post('/ads/toggle-status',[AdsController::class, 'toggleStatus'], $tasksSecure);
$r->post('/ads/{id}/cancel',  [AdsController::class, 'cancel'],       $tasksSecure);
$r->get('/ads/{id}',          [AdsController::class, 'show'],         $tasksAuth);

// ── AJAX API for Unified Ad Wizard ──
// Web AJAX endpoints for the Ads wizard. Do not place these under /api/* because
// AuthMiddleware intentionally blocks cookie-based auth on API routes.
$r->get('/ads/api/type-info',       [AdsApiController::class, 'typeInfo'],       $auth);
$r->post('/ads/api/validate-field', [AdsApiController::class, 'validateField'],  $authCSRF);
$r->post('/ads/api/preview-cost',   [AdsApiController::class, 'previewCost'],    $authCSRF);

/*
|--------------------------------------------------------------------------
| Custom Tasks - Advertiser
|--------------------------------------------------------------------------
*/
$r->post('/custom-tasks/ad/submissions/{id}/approve', [AdsController::class, 'approveSubmission'], $tasksSecure);
$r->post('/custom-tasks/ad/submissions/{id}/reject', [AdsController::class, 'rejectSubmission'], $tasksSecure);

/*
|--------------------------------------------------------------------------
| Custom Tasks - Executor
|--------------------------------------------------------------------------
*/
$r->get('/custom-tasks/detail/{id}', [CustomTaskController::class, 'show'], $tasksAuth);
$r->post('/custom-tasks/{id}/start-execution', [CustomTaskController::class, 'start'], $tasksSecure);
$r->post('/custom-tasks/submissions/{id}/submit-proof-action', [CustomTaskController::class, 'submitProof'], $tasksSecure);

$r->get('/custom-tasks/my-submissions-list', [CustomTaskController::class, 'mySubmissions'], $tasksAuth);
$r->get('/custom-tasks/disputes-list', [CustomTaskController::class, 'disputes'], $tasksAuth);
$r->post('/custom-tasks/submissions/{id}/dispute-action', [CustomTaskController::class, 'storeDispute'], $tasksSecure);