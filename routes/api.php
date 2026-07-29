<?php

use App\Controllers\Api\TokenController;
use App\Controllers\Api\UserController as ApiUserController;
use App\Controllers\Api\WalletController as ApiWalletController;
use App\Controllers\Api\SocialTaskApiController;
use App\Controllers\Api\InfluencerController as ApiInfluencerController;
use App\Controllers\Api\InteractionApiController;
use App\Middleware\ApiAuthMiddleware;
use App\Middleware\RequireFeature;
use App\Middleware\ApiRequestMiddleware;

$r = app()->router;

/**
 * ─────────────────────────────────────────────────────────────────
 * API v1 ROOT GROUP
 * ─────────────────────────────────────────────────────────────────
 *
 * 🔒 SECURITY NOTES:
 *
 * CSRF Protection:
 *   API routes are intentionally EXEMPT from CSRF validation.
 *   CSRFMiddleware (see app/Middleware/CSRFMiddleware.php L44-46) automatically
 *   skips any request where URI starts with '/api/'.
 *
 *   Rationale: API clients (mobile apps, third-party integrations) cannot
 *   submit CSRF tokens. Instead, all mutating API endpoints are protected by:
 *     1. Bearer token authentication (ApiAuthMiddleware)
 *     2. Token rotation on sensitive operations
 *     3. HMAC-signed request validation for webhooks
 *     4. Rate limiting (RateLimitMiddleware)
 *     5. IP-based fraud detection (AntiFraud)
 *
 * Authentication:
 *   - Public endpoints: no auth required (ping, health, config)
 *   - Protected endpoints: require 'Authorization: Bearer <token>' header
 *   - Token format: see ApiAuthMiddleware for validation logic
 *
 * ─────────────────────────────────────────────────────────────────
 */
$r->group(['prefix' => '/api/v1', 'middleware' => [ApiRequestMiddleware::class, \App\Middleware\RateLimitMiddleware::class]], function ($r) {

    /**
     * HEALTH CHECKS & PING
     */
    $r->get('/ping', function() {
        app()->response->json(['pong' => true]);
    });
    $r->get('/health/live', [\App\Controllers\Api\HealthCheckController::class, 'live']);
    $r->get('/health/ready', [\App\Controllers\Api\HealthCheckController::class, 'ready']);
    $r->get('/config', [\App\Controllers\Api\AppConfigController::class, 'config']);

    /**
     * AUTH (Public)
     */
    $r->post('/auth/token', [TokenController::class, 'issue'], [\App\Middleware\RateLimitMiddleware::class]);
    $r->post('/auth/refresh', [TokenController::class, 'refresh'], [\App\Middleware\RateLimitMiddleware::class]);

    /**
     * AUTH (Protected)
     */
    $r->group(['middleware' => [ApiAuthMiddleware::class . ':auth.manage']], function ($r) {
        $r->post('/auth/revoke', [TokenController::class, 'revoke']);
        $r->get('/auth/tokens', [TokenController::class, 'list']);
        $r->post('/auth/tokens/{id}/revoke', [TokenController::class, 'revokeById']);
    });

    /**
     * USER
     */
    $r->group(['middleware' => [ApiAuthMiddleware::class . ':user.read']], function ($r) {
        $r->get('/user/profile', [ApiUserController::class, 'profile']);
        $r->get('/user/notifications', [ApiUserController::class, 'notifications']);
        $r->get('/user/tickets', [ApiUserController::class, 'ticketsList']);
        $r->get('/user/tickets/categories', [ApiUserController::class, 'ticketsCategories']);
        $r->get('/user/tickets/{id}', [ApiUserController::class, 'ticketsShow']);
        $r->get('/user/2fa/status', [ApiUserController::class, 'twoFactorStatus']);
        $r->get('/user/sessions', [ApiUserController::class, 'sessionsList']);
        $r->get('/user/settings', [ApiUserController::class, 'settingsGet']);
        $r->get('/user/kyc/status', [ApiUserController::class, 'kycStatus']);
        $r->get('/user/messages', [ApiUserController::class, 'directMessagesList']);
    });
    $r->group(['middleware' => [ApiAuthMiddleware::class . ':user.write']], function ($r) {
        $r->post('/user/notifications/read', [ApiUserController::class, 'markRead']);
        $r->post('/user/tickets', [ApiUserController::class, 'ticketsStore']);
        $r->post('/user/tickets/{id}/reply', [ApiUserController::class, 'ticketsReply']);
        $r->post('/user/tickets/{id}/close', [ApiUserController::class, 'ticketsClose']);
        $r->post('/user/2fa/enable', [ApiUserController::class, 'twoFactorEnable']);
        $r->post('/user/2fa/disable', [ApiUserController::class, 'twoFactorDisable']);
        $r->post('/user/sessions/{id}/revoke', [ApiUserController::class, 'sessionsRevoke']);
        $r->post('/user/settings/general', [ApiUserController::class, 'settingsGeneralUpdate']);
        $r->post('/user/settings/privacy', [ApiUserController::class, 'settingsPrivacyUpdate']);
        $r->post('/user/account-deletion', [ApiUserController::class, 'accountDeletionRequest']);
        $r->post('/user/kyc/submit', [ApiUserController::class, 'kycSubmit']);
        $r->post('/user/messages/send', [ApiUserController::class, 'directMessageSend']);
        $r->post('/user/bug-report', [ApiUserController::class, 'bugReportStore']);
    });

    /**
     * WALLET & FINANCIAL
     */
    $r->group(['middleware' => [ApiAuthMiddleware::class . ':wallet.read']], function ($r) {
        $r->get('/wallet', [ApiWalletController::class, 'balance']);
        $r->get('/wallet/transactions', [ApiWalletController::class, 'transactions']);
        $r->get('/wallet/bank-cards', [ApiWalletController::class, 'bankCardsList']);
        $r->get('/wallet/withdraw/limits', [ApiWalletController::class, 'withdrawLimits']);
        $r->get('/wallet/crypto/wallets', [ApiWalletController::class, 'cryptoDepositWallets']);
        $r->get('/wallet/investments', [ApiWalletController::class, 'investmentList']);
        $r->get('/wallet/referrals/stats', [ApiWalletController::class, 'referralStats']);
        $r->get('/wallet/referrals/users', [ApiWalletController::class, 'referralUsers']);
        $r->get('/wallet/lottery/rounds', [ApiWalletController::class, 'lotteryList']);
    });
    $r->group(['middleware' => [ApiAuthMiddleware::class . ':wallet.write']], function ($r) {
        $r->post('/wallet/bank-cards', [ApiWalletController::class, 'bankCardsStore']);
        $r->post('/wallet/bank-cards/{id}/delete', [ApiWalletController::class, 'bankCardsDelete']);
        $r->post('/wallet/bank-cards/{id}/primary', [ApiWalletController::class, 'bankCardsSetPrimary']);
        $r->post('/wallet/withdraw', [ApiWalletController::class, 'withdrawSubmit']);
        $r->post('/wallet/manual-deposit', [ApiWalletController::class, 'manualDepositStore']);
        $r->post('/wallet/crypto/intent', [ApiWalletController::class, 'cryptoDepositCreateIntent']);
        $r->post('/wallet/investments', [ApiWalletController::class, 'investmentStore']);
        $r->post('/wallet/investments/withdraw', [ApiWalletController::class, 'investmentWithdraw']);
        $r->post('/wallet/lottery/join', [ApiWalletController::class, 'lotteryJoin']);
    });

    /**
     * INFLUENCER MARKETPLACE (Read)
     */
    $r->group(['middleware' => [ApiAuthMiddleware::class . ':influencer.read']], function ($r) {
        $r->get('/influencer/profile', [ApiInfluencerController::class, 'myProfile']);
        $r->get('/influencer/list', [ApiInfluencerController::class, 'getList']);
        $r->get('/influencer/{id}', [ApiInfluencerController::class, 'show']);
        $r->get('/influencer/orders/placed', [ApiInfluencerController::class, 'myPlacedOrders']);
        $r->get('/influencer/orders/received', [ApiInfluencerController::class, 'receivedOrders']);
        $r->get('/influencer/orders/{id}/dispute', [ApiInfluencerController::class, 'getDispute']);
    });

    /**
     * INFLUENCER MARKETPLACE (Write)
     */
    $r->group(['middleware' => [ApiAuthMiddleware::class . ':influencer.write']], function ($r) {
        $r->post('/influencer/profile', [ApiInfluencerController::class, 'saveProfile']);
        $r->post('/influencer/profile/verify', [ApiInfluencerController::class, 'submitVerification']);
        $r->post('/influencer/orders', [ApiInfluencerController::class, 'createOrder']);
        $r->post('/influencer/orders/{id}/confirm', [ApiInfluencerController::class, 'buyerConfirm']);
        $r->post('/influencer/orders/{id}/dispute', [ApiInfluencerController::class, 'buyerDispute']);
        $r->post('/influencer/orders/{id}/respond', [ApiInfluencerController::class, 'respondOrder']);
        $r->post('/influencer/orders/{id}/proof', [ApiInfluencerController::class, 'submitProof']);
        $r->post('/influencer/orders/{id}/dispute/message', [ApiInfluencerController::class, 'sendDisputeMessage']);
        $r->post('/influencer/orders/{id}/dispute/escalate', [ApiInfluencerController::class, 'escalateDispute']);
        $r->post('/influencer/orders/{id}/dispute/resolve', [ApiInfluencerController::class, 'resolveDispute']);
    });

    /**
     * SOCIAL TASK SYSTEM (Read)
     */
    $r->group(['prefix' => '/social', 'middleware' => [ApiAuthMiddleware::class . ':social.read', RequireFeature::class . ':tasks']], function ($r) {
        $r->get('/accounts', [SocialTaskApiController::class, 'accounts']);
        $r->get('/ads', [SocialTaskApiController::class, 'myAds']);
        $r->get('/ads/{id}', [SocialTaskApiController::class, 'showAd']);
        $r->get('/tasks', [SocialTaskApiController::class, 'tasks']);
        $r->get('/tasks/history', [SocialTaskApiController::class, 'history']);
        $r->get('/disputes', [SocialTaskApiController::class, 'disputes']);
    });

    /**
     * SOCIAL TASK SYSTEM (Write)
     */
    $r->group(['prefix' => '/social', 'middleware' => [ApiAuthMiddleware::class . ':social.write', RequireFeature::class . ':tasks']], function ($r) {
        $r->post('/accounts', [SocialTaskApiController::class, 'storeAccount']);
        $r->put('/accounts/{id}', [SocialTaskApiController::class, 'updateAccount']);
        $r->delete('/accounts/{id}', [SocialTaskApiController::class, 'deleteAccount']);
        $r->post('/ads', [SocialTaskApiController::class, 'createAd']);
        $r->post('/ads/{id}/pause', [SocialTaskApiController::class, 'pauseAd']);
        $r->post('/ads/{id}/resume', [SocialTaskApiController::class, 'resumeAd']);
        $r->post('/ads/{id}/cancel', [SocialTaskApiController::class, 'cancelAd']);
        $r->post('/tasks/{id}/start', [SocialTaskApiController::class, 'startTask']);
        $r->post('/tasks/{id}/submit', [SocialTaskApiController::class, 'submitTask']);
        $r->post('/executions/{id}/dispute', [SocialTaskApiController::class, 'openDispute']);
    });

    /**
     * REAL-TIME INFRASTRUCTURE
     */
    $r->group(['prefix' => '/real-time', 'middleware' => [ApiAuthMiddleware::class . ':realtime']], function ($r) {
        $r->post('/poll', [\App\Controllers\Api\RealTimeController::class, 'poll']);
        $r->post('/rooms/join', [\App\Controllers\Api\RealTimeController::class, 'joinRoom']);
        $r->post('/rooms/leave', [\App\Controllers\Api\RealTimeController::class, 'leaveRoom']);
        $r->get('/rooms/{room}/members', [\App\Controllers\Api\RealTimeController::class, 'getRoomMembers']);
        $r->get('/presence/online', [\App\Controllers\Api\RealTimeController::class, 'getOnlineUsers']);
        $r->get('/presence/online/{room}', [\App\Controllers\Api\RealTimeController::class, 'getOnlineInRoom']);
        $r->get('/stats', [\App\Controllers\Api\RealTimeController::class, 'getStats']);
    });

    /**
     * VERIFICATION SYSTEM
     */
    $r->group(['prefix' => '/verification', 'middleware' => [ApiAuthMiddleware::class . ':verification.read']], function ($r) {
        $r->get('/status', [\App\Controllers\Api\VerificationController::class, 'getStatus']);
        $r->get('/history', [\App\Controllers\Api\VerificationController::class, 'getHistory']);
    });

    $r->group(['prefix' => '/verification', 'middleware' => [ApiAuthMiddleware::class . ':verification.write']], function ($r) {
        $r->post('/generate-code', [\App\Controllers\Api\VerificationController::class, 'generateCode']);
        $r->post('/submit-proof', [\App\Controllers\Api\VerificationController::class, 'submitProof']);
    });

    /**
     * INTERACTIONS & VITRINE MARKETPLACE
     */
    $r->group(['prefix' => '/vitrine', 'middleware' => [ApiAuthMiddleware::class . ':user.read']], function ($r) {
        $r->get('/list', [InteractionApiController::class, 'vitrineList']);
        $r->get('/{id}', [InteractionApiController::class, 'vitrineShow']);
    });
    $r->group(['prefix' => '/vitrine', 'middleware' => [ApiAuthMiddleware::class . ':user.write']], function ($r) {
        $r->post('/{id}/trade', [InteractionApiController::class, 'vitrineTradeRequest']);
    });

    $r->group(['prefix' => '/interactions', 'middleware' => [ApiAuthMiddleware::class . ':user.write']], function ($r) {
        $r->post('/favorite/toggle', [InteractionApiController::class, 'toggleFavorite']);
        $r->post('/rate', [InteractionApiController::class, 'rate']);
        $r->post('/report', [InteractionApiController::class, 'report']);
    });

    /**
     * SECURITY ENDPOINTS
     */
    $r->post('/security/csp-report', [\App\Controllers\Api\SecurityController::class, 'cspReport'], [\App\Middleware\RateLimitMiddleware::class]);

    /**
     * DISTRIBUTED HEALTH & METRICS (Option 3 - consolidated into existing HealthCheckController)
     */
    $r->get('/health/distributed', [\App\Controllers\Api\HealthCheckController::class, 'distributed']);
    $r->get('/metrics/distributed', [\App\Controllers\Api\HealthCheckController::class, 'metrics']);

});
