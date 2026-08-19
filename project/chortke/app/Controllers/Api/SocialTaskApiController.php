<?php

namespace App\Controllers\Api;

use App\Services\SocialTask\SocialTaskService;
use App\Services\SocialTask\SilentAntiFraudService;
use App\Services\SocialTask\CameraVerificationService;
use App\Services\SocialTask\BehaviorAnalysisService;
use App\Services\Gamification\TrustService;
use App\Enums\ModuleContext;
use App\Services\User\UserService;

/**
 * SocialTaskApiController - API برای سیستم وظایف اجتماعی
 *
 * Endpoints:
 * - GET /api/v1/social/accounts
 * - POST /api/v1/social/accounts
 * - PUT /api/v1/social/accounts/{id}
 * - DELETE /api/v1/social/accounts/{id}
 * - GET /api/v1/social/ads
 * - POST /api/v1/social/ads
 * - GET /api/v1/social/ads/{id}
 * - POST /api/v1/social/ads/{id}/pause
 * - POST /api/v1/social/ads/{id}/resume
 * - POST /api/v1/social/ads/{id}/cancel
 * - GET /api/v1/social/tasks
 * - POST /api/v1/social/tasks/{id}/start
 * - POST /api/v1/social/tasks/{id}/submit
 * - GET /api/v1/social/tasks/history
 * 
 * Legacy endpoints (برای سازگاری عقب‌رو):
 * - POST /api/social-tasks/behavior
 * - POST /api/social-tasks/camera-verify
 * - GET /api/social-tasks/trust-status
 */
class SocialTaskApiController extends BaseApiController
{
    private SocialTaskService      $service;
    private TrustService           $trust;
    private ?CameraVerificationService $cameraVerification;
    private ?BehaviorAnalysisService $behaviorAnalysis;
    private UserService $userService;

    public function __construct(
        SocialTaskService $service,
        TrustService $trust,
        UserService $userService,
        ?CameraVerificationService $cameraVerification = null,
        ?BehaviorAnalysisService $behaviorAnalysis = null,
        ?\App\Contracts\LoggerInterface $logger = null
    ) {
        parent::__construct(null, null, null, null, $logger);
        $this->service     = $service;
        $this->trust       = $trust;
        $this->userService = $userService;
        $this->cameraVerification = $cameraVerification;
        $this->behaviorAnalysis = $behaviorAnalysis;
    }

    // ═════════════════════════════════════════════════════════════
    // SOCIAL ACCOUNTS
    // ═════════════════════════════════════════════════════════════

    /**
     * لیست حساب‌های اجتماعی کاربر
     * GET /api/v1/social/accounts
     */
    public function accounts(): void
    {
        $user = $this->currentUser();
        if ($user === null) { $this->error('احراز هویت لازم است', 401); return; }
        $accounts = $this->service->getUserAccounts($user->id);
        $this->success($accounts);
    }

    /**
     * ایجاد حساب اجتماعی جدید
     * POST /api/v1/social/accounts
     */
    public function storeAccount(): void
    {
        $user = $this->currentUser();
        if ($user === null) { $this->error('احراز هویت لازم است', 401); return; }
        $data = $this->request->body();

        $platform = trim(str_value($data['platform'] ?? ''));
        $account_handle = trim(str_value($data['account_handle'] ?? ''));
        $access_token = trim(str_value($data['access_token'] ?? ''));

        if (empty($platform) || empty($account_handle)) {
            $this->validationError(['platform' => 'الزامی', 'account_handle' => 'الزامی']);
        }

        $result = $this->service->addAccount($user->id, $platform, $account_handle, $access_token);
        $this->success($result);
    }

    /**
     * به‌روزرسانی حساب اجتماعی
     * PUT /api/v1/social/accounts/{id}
     */
    public function updateAccount(string $id): void
    {
        $user = $this->currentUser();
        if ($user === null) { $this->error('احراز هویت لازم است', 401); return; }
        $data = $this->request->body();
        $result = $this->service->updateUserAccount($user->id, (int)$id, $data);
        
        if (!$result['success']) {
            $this->error(str_value($result['message'] ?? 'یافت نشد'), 404);
            return;
        }

        $this->success($result);
    }

    /**
     * حذف حساب اجتماعی
     * DELETE /api/v1/social/accounts/{id}
     */
    public function deleteAccount(string $id): void
    {
        $user = $this->currentUser();
        if ($user === null) { $this->error('احراز هویت لازم است', 401); return; }
        $result = $this->service->deleteUserAccount($user->id, (int)$id);
        
        if (!$result['success']) {
            $this->error(str_value($result['message'] ?? 'یافت نشد'), 404);
            return;
        }

        $this->success($result);
    }

    // ═════════════════════════════════════════════════════════════
    // ADVERTISEMENTS
    // ═════════════════════════════════════════════════════════════

    /**
     * لیست تبلیغات کاربر
     * GET /api/v1/social/ads
     */
    public function myAds(): void
    {
        $user = $this->currentUser();
        if ($user === null) { $this->error('احراز هویت لازم است', 401); return; }
        [$page, $perPage, $offset] = $this->paginationParams();
        [$ads, $total] = $this->service->getUserAds($user->id, $perPage, $offset);
        $this->paginated($ads, $total, $page, $perPage);
    }

    /**
     * ایجاد تبلیغ جدید
     * POST /api/v1/social/ads
     */
    public function createAd(): void
    {
        $user = $this->currentUser();
        if ($user === null) { $this->error('احراز هویت لازم است', 401); return; }
        $data = $this->request->body();

        $result = $this->service->createUserAd($user->id, $data);

        if (!$result['success']) {
            $this->validationError(['ad' => $result['message']]);
            return;
        }

        $this->success($result, 'تبلیغ ایجاد شد', 201);
    }

    /**
     * نمایش تبلیغ
     * GET /api/v1/social/ads/{id}
     */
    public function showAd(string $id): void
    {
        $user = $this->currentUser();
        if ($user === null) { $this->error('احراز هویت لازم است', 401); return; }
        $ad = $this->service->getAdById((int)$id);
        
        if (!$ad) {
            $this->error('تبلیغ پیدا نشد', 404);
            return;
        }

        $adObj = (object)(array)$ad;
        $adUserId = (int)($adObj->user_id ?? 0);
        $isAdmin = in_array($user->role ?? '', ['admin', 'super_admin'], true);

        // 🛡️ IDOR Guard (Issue #5): Sanitize sensitive campaign details for non-owner users
        if ($adUserId !== (int)$user->id && !$isAdmin) {
            $adData = [
                'id' => $adObj->id ?? (int)$id,
                'type' => $adObj->type ?? 'social_task',
                'title' => $adObj->title ?? '',
                'description' => $adObj->description ?? '',
                'target_url' => $adObj->target_url ?? $adObj->link ?? '',
                'platform' => $adObj->platform ?? 'general',
                'action_type' => $adObj->action_type ?? 'view',
                'reward_amount' => $adObj->price_per_task ?? $adObj->reward_amount ?? 0,
                'status' => $adObj->status ?? 'active',
            ];
            $this->success($adData);
            return;
        }

        $this->success($ad);
    }

    /**
     * توقف موقت تبلیغ
     * POST /api/v1/social/ads/{id}/pause
     */
    public function pauseAd(string $id): void
    {
        $user = $this->currentUser();
        if ($user === null) { $this->error('احراز هویت لازم است', 401); return; }
        $result = $this->service->pauseUserAd($user->id, (int)$id);
        
        if (!$result['success']) {
            $this->error(str_value($result['message'] ?? 'یافت نشد'), 404);
            return;
        }

        $this->success($result);
    }

    /**
     * از سر گیری تبلیغ
     * POST /api/v1/social/ads/{id}/resume
     */
    public function resumeAd(string $id): void
    {
        $user = $this->currentUser();
        if ($user === null) { $this->error('احراز هویت لازم است', 401); return; }
        $result = $this->service->resumeUserAd($user->id, (int)$id);
        
        if (!$result['success']) {
            $this->error(str_value($result['message'] ?? 'یافت نشد'), 404);
            return;
        }

        $this->success($result);
    }

    /**
     * لغو تبلیغ
     * POST /api/v1/social/ads/{id}/cancel
     */
    public function cancelAd(string $id): void
    {
        $user = $this->currentUser();
        if ($user === null) { $this->error('احراز هویت لازم است', 401); return; }
        $result = $this->service->cancelUserAd($user->id, (int)$id);
        
        if (!$result['success']) {
            $this->error(str_value($result['message'] ?? 'یافت نشد'), 404);
            return;
        }

        $this->success($result);
    }

    // ═════════════════════════════════════════════════════════════
    // TASKS
    // ═════════════════════════════════════════════════════════════

    /**
     * لیست وظایف موجود برای کاربر
     * GET /api/v1/social/tasks
     */
    public function tasks(): void
    {
        $user = $this->currentUser();
        if ($user === null) { $this->error('احراز هویت لازم است', 401); return; }
        [$page, $perPage, $offset] = $this->paginationParams();
        [$tasks, $total] = $this->service->getAvailableTasksForExecutor($perPage, $offset);
        $this->paginated($tasks, $total, $page, $perPage);
    }

    /**
     * شروع اجرای وظیفه
     * POST /api/v1/social/tasks/{id}/start
     */
    public function startTask(string $id): void
    {
        $user = $this->currentUser();
        if ($user === null) { $this->error('احراز هویت لازم است', 401); return; }
        $result = $this->service->startTask($user->id, (int)$id);
        $this->success($result);
    }

    /**
     * ارسال نتیجه وظیفه
     * POST /api/v1/social/tasks/{id}/submit
     */
    public function submitTask(string $id): void
    {
        $user = $this->currentUser();
        if ($user === null) { $this->error('احراز هویت لازم است', 401); return; }
        $data = $this->request->body();

        $result = $this->service->submitTask($user->id, (int)$id, $data);
        $this->success($result);
    }

    /**
     * تاریخچه وظایف کاربر
     * GET /api/v1/social/tasks/history
     */
    public function history(): void
    {
        $user = $this->currentUser();
        if ($user === null) { $this->error('احراز هویت لازم است', 401); return; }
        [$page, $perPage, $offset] = $this->paginationParams();
        [$history, $total] = $this->service->getUserExecutionHistory($user->id, $perPage, $offset);
        $this->paginated($history, $total, $page, $perPage);
    }

    // ═════════════════════════════════════════════════════════════
    // LEGACY ENDPOINTS (برای سازگاری عقب‌رو)
    // ═════════════════════════════════════════════════════════════

    // ─────────────────────────────────────────────────────────────
    // ثبت behavior signals (موبایل در حین انجام)
    // ─────────────────────────────────────────────────────────────

    /**
     * POST /api/social-tasks/behavior
     * Body: {
     *   execution_id: int,
     *   signals: {
     *     tap_count, swipe_count, scroll_count, touch_pauses,
     *     touch_timing_variance, scroll_speed_variance, scroll_pauses,
     *     session_duration, active_time, reconnect_count,
     *     app_blur_count, max_blur_duration,
     *     hesitation_count, avg_action_delay_ms, natural_delay_count
     *   }
     * }
     */
    public function recordBehavior(): void
    {
        $user = $this->currentUser();
        if ($user === null) { $this->error('احراز هویت لازم است', 401); return; }
        $body = $this->request->body();
        $executionId = int_value($body['execution_id'] ?? 0);
        $signals = (array)($body['signals'] ?? []);

        if (!$executionId) {
            $this->error('execution_id الزامی است', 400);
        }

        // فقط فیلدهای مجاز. توجه: camera فقط برای فلو مشکوک موبایل فعال می‌شود، نه برای همه تسک‌ها.
        $allowedSignals = [
            'tap_count', 'swipe_count', 'scroll_count', 'touch_pauses',
            'touch_timing_variance', 'scroll_speed_variance', 'scroll_pauses',
            'session_duration', 'active_time', 'expected_time', 'reconnect_count',
            'app_blur_count', 'max_blur_duration', 'is_mobile', 'client_mode', 'client_version',
            'hesitation_count', 'avg_action_delay_ms', 'natural_delay_count',
        ];
        $filtered = array_intersect_key($signals, array_flip($allowedSignals));

        $success = $this->service->recordBehaviorSignals($executionId, (int)$user->id, $filtered);

        $analysis = $this->behaviorAnalysis ? $this->behaviorAnalysis->analyze($filtered) : [];
        $behaviorScore = float_value($analysis['behavior_score'] ?? 100);
        $isMobile = !empty($filtered['is_mobile']) || (bool)preg_match('/Android|iPhone|iPad|Mobile/i', $this->request->userAgent());

        $cameraRequired = false;
        $cameraRequestId = null;
        if ($success && $isMobile && $this->cameraVerification) {
            try {
                if ($this->cameraVerification->isRequired($executionId, $behaviorScore, $filtered)) {
                    $cameraRequired = true;
                    $cameraRequestId = $this->cameraVerification->createRequest($executionId, (int)$user->id, 'mobile_camera', [
                        'client_mode' => $filtered['client_mode'] ?? 'mobile_app',
                        'user_agent' => $this->request->userAgent(),
                    ]);
                }
            } catch (\Throwable $e) {
                $this->logger->warning('social.camera_request_failed', [
                    'execution_id' => $executionId,
                    'user_id' => (int)$user->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->success([
            'success' => $success,
            'behavior_score' => $behaviorScore,
            'patterns' => $analysis['patterns'] ?? [],
            'camera_required' => $cameraRequired,
            'camera_request_id' => $cameraRequestId,
            'camera_eligible' => $isMobile,
            'message' => $cameraRequired ? 'برای تکمیل اعتبارسنجی، تأیید دوربین لازم است.' : 'سیگنال رفتار ثبت شد.',
        ]);
    }

    // ─────────────────────────────────────────────────────────────
    // Camera Verification Signal
    // ─────────────────────────────────────────────────────────────

    /**
     * POST /api/social-tasks/camera-verify
     *
     * عکس هرگز ذخیره یا ارسال نمی‌شود.
     * موبایل نتیجه پردازش ML محلی را به صورت امتیاز ارسال می‌کند.
     *
     * Body: {
     *   execution_id: int,
     *   camera_score: int (0–100 — نتیجه ML محلی),
     *   task_type: string,
     *   verified_signals: string[] (مثلاً ['follow_button_visible','username_match'])
     * }
     */
    public function cameraVerify(): void
    {
        $user = $this->currentUser();
        if ($user === null) { $this->error('احراز هویت لازم است', 401); return; }
        $body = $this->request->body();

        $executionId     = int_value($body['execution_id'] ?? 0);
        $cameraScore     = max(0, min(100, int_value($body['camera_score'] ?? 0)));
        /** @var array<string, mixed> $verifiedSignals */
        $verifiedSignals = is_array($body['verified_signals'] ?? null) ? $body['verified_signals'] : [];
        $frameCount      = max(0, min(10, int_value($body['frame_count'] ?? 0)));
        /** @var array<string, mixed> $frameSignals */
        $frameSignals    = is_array($body['frame_signals'] ?? null) ? $body['frame_signals'] : [];
        /** @var array<string, mixed> $clientContext */
        $clientContext   = is_array($body['client_context'] ?? null) ? $body['client_context'] : [];

        if (!$executionId) {
            $this->error('execution_id الزامی است', 400);
        }

        if ($this->cameraVerification) {
            $result = $this->cameraVerification->processResult($executionId, (int)$user->id, $cameraScore, $verifiedSignals, $frameCount, $frameSignals, $clientContext);
            if (empty($result['success'])) {
                $this->error(str_value($result['message'] ?? 'تأیید دوربین انجام نشد'), 422);
            }
            $this->success($result, 'سیگنال دوربین دریافت شد');
        }

        // Fallback compatibility: عکس/اسکرین‌شات ذخیره نمی‌شود؛ فقط score و signal محلی ثبت می‌شود.
        $signals = [
            'camera_score'      => $cameraScore,
            'camera_signals'    => $verifiedSignals,
            'camera_verified_at'=> time(),
        ];

        $this->service->recordBehaviorSignals($executionId, (int)$user->id, $signals);

        $this->success([
            'success' => true,
            'camera_score' => $cameraScore,
            'verified_signals' => $verifiedSignals,
            'message' => 'سیگنال دوربین دریافت شد',
        ]);
    }

    // ─────────────────────────────────────────────────────────────
    // وضعیت Trust کاربر
    // ─────────────────────────────────────────────────────────────

    /**
     * GET /api/social-tasks/trust-status
     */
    public function trustStatus(): void
    {
        $user = $this->currentUser();
        if ($user === null) { $this->error('احراز هویت لازم است', 401); return; }

        $userObj = $this->userService->findById($user->id);
        $trust   = $userObj ? $this->trust->getTrustScore((object)['id' => int_value($userObj->id ?? 0)], ModuleContext::SOCIAL_TASKS) : 50.0;
        $weekly  = []; // To be implemented with new score analytics

        $this->success([
            'trust_score'  => $trust,
            'weekly'       => $weekly,
        ]);
    }

    // ─────────────────────────────────────────────────────────────
    // Private helpers
    // ─────────────────────────────────────────────────────────────
}
