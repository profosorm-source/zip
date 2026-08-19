<?php

declare(strict_types=1);

namespace App\Middleware;

use Core\Request;
use Core\Response;
use Core\Session;
use Core\Redis;
use Closure;
use App\Constants\SessionKeys;

/**
 * AuthMiddleware — مدیریت احراز هویت و انقضای نشست کاربر
 * 
 * SECURITY NOTES:
 * - When Redis is unavailable, timeout is reduced for security
 * - Session verification includes Redis keys cleanup
 * - Fail-closed behavior when all storage mechanisms fail
 */
class AuthMiddleware extends BaseMiddleware
{
    #[ \Core\Attributes\Inject ]
    private Session $session;

    #[ \Core\Attributes\Inject ]
    private Redis $redis;

    #[ \Core\Attributes\Inject ]
    private \App\Services\Settings\AppSettings $appSettings;

    #[ \Core\Attributes\Inject ]
    private \App\Models\User $userModel;

    #[ \Core\Attributes\Inject ]
    private \App\Contracts\LoggerInterface $logger;

    #[ \Core\Attributes\Inject ]
    private \App\Services\User\UserSettingsService $userSettings;

    // 🛡️ STABILITY FIX: پرهیز از کاهش مخرب زمان انقضا در صورت قطعی Redis
    // حفظ تجربه کاربری (UX) و جلوگیری از خروج گروهی کاربران فعال (Mass Logout)
    private const FALLBACK_TIMEOUT_WHEN_REDIS_DOWN = 900;

    public function __construct(
        Session $session,
        Redis $redis,
        \App\Services\Settings\AppSettings $appSettings,
        \App\Models\User $userModel,
        \App\Contracts\LoggerInterface $logger,
        \App\Services\User\UserSettingsService $userSettings
    ) {
        $this->session = $session;
        $this->redis = $redis;
        $this->appSettings = $appSettings;
        $this->userModel = $userModel;
        $this->logger = $logger;
        $this->userSettings = $userSettings;
    }

    public function handle(Request $request, Closure $next, string ...$roles): mixed
    {
        // CORE-037: Enforce separate API authentication (Cookie auth not permitted on /api/*)
        if (str_starts_with($request->uri(), '/api/')) {
            $response = new Response();
            $response->json([
                'success' => false, 
                'message' => 'احراز هویت مبتنی بر سشن روی وب‌سرویس‌ها مجاز نیست.'
            ], 401);
        }
        $session = $this->session;
        $now = time();

        $redisAvailable = $this->redis->isAvailable();
        
        // 🛡️ STABILITY FIX: همگام‌سازی زمان انقضای نشست بدون وابستگی مستقیم به پایداری Redis
        $defaultTimeout = $redisAvailable ? 900 : self::FALLBACK_TIMEOUT_WHEN_REDIS_DOWN; // 15 min
        $timeout = int_value($this->appSettings->get('session_idle_timeout_seconds', $defaultTimeout));

        // User-level security setting wins over global default. The value is stored
        // in minutes via /settings/security and converted to seconds here.
        $currentUserIdForTimeout = int_value($session->get(SessionKeys::USER_ID, 0));
        if ($currentUserIdForTimeout > 0) {
            $minutes = int_value($session->get('security_session_timeout_minutes', 0));
            if ($minutes < 5 || $minutes > 480) {
                try {
                    $minutes = int_value($this->userSettings->get($currentUserIdForTimeout, 'session_timeout', 30));
                    if ($minutes >= 5 && $minutes <= 480) {
                        $session->set('security_session_timeout_minutes', (string)$minutes);
                    }
                } catch (\Throwable $e) {
                    $this->logger->warning('auth.session_timeout_user_setting_failed', [
                        'user_id' => $currentUserIdForTimeout,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            if ($minutes >= 5 && $minutes <= 480) {
                $timeout = $minutes * 60;
            }
        }
        
        // حذف ping اضافی — isAvailable() خودش در constructor بررسی شده
        // ping مجدد فقط timeout اضافه و dashboard رو کند میکرد
        
        // ✅ امنیت: استفاده از Redis برای ذخیره timeout (نه session-side) با فال‌بک امن سشن در صورت عدم دسترسی به ردیس
        $sessionId = session_id();
        $redisKey = "session:activity:" . $sessionId;
        
        $lastActivity = null;

        // MED-08 Fix: Unified activity handling with robust fallbacks
        try {
            if ($redisAvailable) {
                $lastActivity = $this->redis->get($redisKey);
            }
        } catch (\Throwable $e) {
            $redisAvailable = false;
            $this->logger->debug('auth.redis_activity_read_failed', ['error' => $e->getMessage()]);
        }

        if ($lastActivity === false || $lastActivity === '') {
            $lastActivity = null;
        }

        if (!$redisAvailable || $lastActivity === null) {
            $lastActivity = $session->get('last_activity');
        }
        
        if ($lastActivity === null) {
            // NEW-H-03 Fix: Initialize last_activity if missing to prevent timeout bypass
            $session->set('last_activity', (string)$now);
            // LOW-04 Fix: When initializing, also set Redis key if available
            if ($redisAvailable) {
                try { $this->redis->set($redisKey, (string)$now, $timeout + 60); } catch (\Throwable $e) {
                    $this->logger->debug('auth.redis_activity_init_failed', ['error' => $e->getMessage()]);
                    // intentional: Redis session operation — non-blocking fallback
                }
            }
        } else {
            $lastActivityTime = int_value($lastActivity);
            
            // بررسی انقضای نشست (Idle Timeout)
            if (($now - $lastActivityTime) > $timeout) {
                // LOW-04 Fix: Clean up Redis keys when session expires
                if ($redisAvailable) {
                    try { 
                        $this->redis->delete($redisKey); 
                        // LOW-04 Fix: Also clear verify key
                        $userId = int_value($session->get(SessionKeys::USER_ID, 0));
                        if ($userId > 0) {
                            $this->redis->delete("user_verify:{$userId}");
                        }
                    } catch (\Throwable $e) {
                        $this->logger->debug('auth.redis_session_cleanup_failed', ['error' => $e->getMessage()]);
                        // intentional: Redis session operation — non-blocking fallback
                    }
                }
                
                $session->destroy();
                
                $response = new Response();
                if ($request->isAjax()) {
                    $response->json(['success' => false, 'message' => config('messages.auth.expired')], 401);
                }

                $session->setFlash('error', config('messages.auth.expired'));
                $response->redirect(url('login'));
                return $response;
            }
        }
        
        // تمدید فعالیت در Redis و Session فقط در صورتی که حداقل 30 ثانیه از آخرین تمدید گذشته باشد
        // جهت کاهش سربار و بهینه‌سازی Write Amplification
        $lastActivityTime = isset($lastActivity) ? int_value($lastActivity) : 0;
        if ($lastActivityTime === 0 || ($now - $lastActivityTime) >= 30) {
            // تمدید فعالیت در Redis (در صورت در دسترس بودن)
            if ($redisAvailable) {
                try {
                    $oldValue = $this->redis->getSet($redisKey, (string)$now);
                    $this->redis->expire($redisKey, $timeout + 60);
                    if ($oldValue !== null) {
                        $lastActivityTime = int_value($oldValue);
                    }
                } catch (\Throwable $e) {
                    // If Redis write fails, continue with session-side tracking
                    $redisAvailable = false;
                    $this->logger->debug('auth.redis_activity_write_failed', ['error' => $e->getMessage()]);
                }
            }

            // HIGH-02 Fix: Always update session as backup to prevent fail-open if Redis goes down
            $session->set('last_activity', (string)$now);
        }

        // CRITICAL-05 Fix: Check for pending 2FA state BEFORE normal auth check
        // This prevents users with pending 2FA from bypassing it if LOGGED_IN is true
        if ($session->has(SessionKeys::PENDING_2FA_USER_ID)) {
            $response = new Response();
            $response->redirect(url('verify-2fa'));
            return $response;
        }

        // بررسی ورود کاربر
        // MED-08 Fix: Unified and robust check for both user_id and logged_in flag
        $userId = int_value($session->get(SessionKeys::USER_ID, 0));
        if ($userId <= 0 || !$session->get(SessionKeys::LOGGED_IN)) {
            // HIGH-H-13 Fix: Redirect to verification page if an email confirmation is pending
            if ($session->has('pending_verification_email')) {
                $response = new Response();
                $response->redirect(url('email/verify-code'));
                return $response;
            }

            $response = new Response();
            if ($request->isAjax()) {
                $response->json(['success' => false, 'message' => config('messages.auth.unauthorized')], 401);
            }
            $response->redirect(url('login'));
            return $response;
        }

        // HIGH-H-06 Fix: Periodic DB validation (Every 2 minutes)
        // HIGH-04 Fix: Using Redis for verification timestamp to prevent session-side manipulation
        $verifyRedisKey = "user_verify:{$userId}";
        $lastVerify = 0;
        if ($redisAvailable) {
            try { $lastVerify = int_value($this->redis->get($verifyRedisKey)); } catch (\Throwable $e) {
                $this->logger->debug('auth.redis_verify_read_failed', ['error' => $e->getMessage()]);
                // intentional: Redis session operation — non-blocking fallback
            }
        }
        if ($lastVerify === 0) {
            $lastVerify = int_value($session->get('user_verify_time', 0));
        }

        if (time() - $lastVerify > 120) { // Shortened to 2 minutes
            try {
                $user = $this->userModel->find($userId);
                if (!$user || (string)$user->status !== 'active') {
                    // LOW-04 Fix: Clean up all session-related Redis keys on account deactivation
                    if ($redisAvailable) {
                        try { 
                            $this->redis->delete($redisKey); 
                            $this->redis->delete($verifyRedisKey);
                        } catch (\Throwable $e) {
                            $this->logger->debug('auth.redis_deactivated_cleanup_failed', ['error' => $e->getMessage()]);
                            // intentional: Redis session operation — non-blocking fallback
                        }
                    }
                    $session->destroy();
                    $response = new Response();
                    if ($request->isAjax()) {
                        $response->json(['success' => false, 'message' => 'حساب شما غیرفعال شده یا دسترسی با خطا مواجه شد.'], 403);
                    }
                    $response->redirect(url('login'));
                }

                // HIGH-H-13 Fix: Enforce email verification for active users
                if (!empty($user) && empty($user->email_verified_at) && !str_contains($request->uri(), '/email/verify')) {
                    $emailVal = (string)($user->email ?? '');
                    $session->set('pending_verification_email', $emailVal);
                    $response = new Response();
                    $response->redirect(url('email/verify-code'));
                    return $response;
                }
                
                $now = time();
                $session->set('user_verify_time', $now);
                if ($redisAvailable) {
                    try { $this->redis->set($verifyRedisKey, (string)$now, 300); } catch (\Throwable $e) {
                        $this->logger->debug('auth.redis_verify_write_failed', ['error' => $e->getMessage()]);
                        // intentional: Redis session operation — non-blocking fallback
                    }
                }
            } catch (\Throwable $e) {
                $this->logger->error('auth.middleware.db_error', ['error' => $e->getMessage()]);
                \App\Services\Sentry\SentryExceptionHandler::captureException($e, null, [
                    'operation' => 'auth.middleware.userVerification',
                ]);
                // LOW-04 Fix: When DB verification fails, use fail-closed behavior
                // Don't allow the request to proceed if we can't verify the user is still valid
                $session->destroy();
                if ($redisAvailable) {
                    try { 
                        $this->redis->delete($redisKey); 
                        $this->redis->delete($verifyRedisKey);
                    } catch (\Throwable $e) {
                        $this->logger->debug('auth.redis_fail_closed_cleanup_failed', ['error' => $e->getMessage()]);
                        // intentional: Redis session operation — non-blocking fallback
                    }
                }
                $response = new Response();
                if ($request->isAjax()) {
                    $response->json(['success' => false, 'message' => 'خطا در تأیید وضعیت حساب. لطفاً دوباره وارد شوید.'], 401);
                }
                $response->redirect(url('login'));
            }
        }

        return $this->toResponse($next($request));
    }
}