<?php

declare(strict_types=1);

namespace App\Middleware;

use Core\Request;
use Core\Response;
use Core\Session;
use App\Policies\RolePolicy;
use Closure;
use App\Constants\SessionKeys;

/**
 * AdminMiddleware — محدودسازی دسترسی به مدیران سیستم
 *
 * SECURITY-FIX: حذف کامل backdoor تست (پارامتر مخرب + debug log file)
 * که به هر کاربر ناشناس امکان دور زدن احراز هویت را می‌داد.
 *
 * جریان اعتبارسنجی:
 *   1. بررسی 2FA pending
 *   2. بررسی وجود session معتبر (USER_ID + LOGGED_IN)
 *   3. DB re-validation هر ۱۵ ثانیه (از Redis یا session)
 *   4. بررسی نقش از طریق RolePolicy::isAdmin()
 */
class AdminMiddleware extends BaseMiddleware
{
    private \App\Contracts\LoggerInterface $logger;
    private \App\Models\User $userModel;
    private Session $session;

    public function __construct(Session $session, \App\Models\User $userModel, \App\Contracts\LoggerInterface $logger) {
        $this->session   = $session;
        $this->userModel = $userModel;
        $this->logger    = $logger;
    }

    public function handle(Request $request, Closure $next): mixed
    {
        $session = $this->session;

        // ── 1. بررسی 2FA pending ─────────────────────────────────────────────
        // CRITICAL-NEW-02: جلوگیری از session confusion bypass
        if ($session->has(SessionKeys::PENDING_2FA_USER_ID)) {
            if ($session->get('admin_pending_2fa')) {
                $response = new Response();
                $response->redirect(url('/admin/verify-2fa'));
                return $response;
            }
            // Non-admin pending 2FA نباید به پنل ادمین دسترسی داشته باشد
            $session->destroy();
            $response = new Response();
            $response->redirect(url('login'));
            return $response;
        }

        // ── 2. بررسی وجود session معتبر ──────────────────────────────────────
        if (!$session->has(SessionKeys::USER_ID) || !$session->get(SessionKeys::LOGGED_IN)) {
            $response = new Response();
            if ($request->isAjax()) {
                $response->json(['success' => false, 'message' => 'لطفاً ابتدا وارد شوید.'], 401);
            }
            $session->setFlash('error', 'لطفاً ابتدا وارد حساب کاربری خود شوید.');
            $response->redirect(url('login'));
        }

        $userId = int_value($session->get(SessionKeys::USER_ID));
        $role   = str_value($session->get(SessionKeys::USER_ROLE) ?? '');

        // ── 3. DB re-validation هر ۱۵ ثانیه ─────────────────────────────────
        // HIGH-09: زمان آخرین تأیید از Redis خوانده می‌شود تا session manipulation ممکن نباشد
        $redis          = app(\Core\Redis::class);
        $redisAvailable = $redis && $redis->isAvailable();
        $verifyRedisKey = "admin_verify:{$userId}";

        $lastVerify = 0;
        if ($redisAvailable) {
            try { $lastVerify = int_value($redis->get($verifyRedisKey)); } catch (\Throwable $e) {
            // non-critical middleware cleanup — log only
            @error_log('[AdminMiddleware] cleanup failed: ' . $e->getMessage());
        }
        }
        if ($lastVerify === 0) {
            $lastVerify = int_value($session->get('admin_verify_time', 0));
        }

        // H-01 / L-04 (بازطراحی): حذف پولینگِ ۱۵ ثانیه‌ای. تصمیمِ کاربر: در هر
        // درخواست یک‌بار نقش/وضعیت مستقیم از DB خوانده می‌شود (یک lookup سبک روی کلید
        // اصلی) تا revoke/بنِ نقش فوری اعمال شود. enforcementِ ریزدانهٔ هر عمل توسط AdminPermissionGuard.
        try {
                $user = $this->userModel->find($userId);

                if (!$user || !RolePolicy::isAdmin($user->role ?? '')) {
                    $this->logger->warning('admin.middleware.access_denied', [
                        'user_id' => $userId,
                        'reason'  => $user ? 'insufficient_role' : 'user_not_found',
                    ]);

                    $res403 = new Response();
                    if ($request->isAjax()) {
                        $res403->json(['success' => false, 'message' => 'دسترسی شما منقضی یا محدود شده است.'], 403);
                    }
                    ob_start();
                    try { view('errors/403'); } catch (\Throwable $e) {
            // non-critical middleware cleanup — log only
            @error_log('[AdminMiddleware] cleanup failed: ' . $e->getMessage());
        }
                    $content = ob_get_clean();
                    $res403->setStatusCode(403);
                    $res403->setContent($content ?: '403 Forbidden');
                    return $res403;
                }

                // همگام‌سازی session با DB و تازه‌سازی زمان تأیید
                $session->set(SessionKeys::USER_ROLE, $user->role);
                $session->set(SessionKeys::LOGGED_IN, true);

                $now = time();
                $session->set('admin_verify_time', $now);
                if ($redisAvailable) {
                    try { $redis->set($verifyRedisKey, (string)$now, 600); } catch (\Throwable $e) {
            // non-critical middleware cleanup — log only
            @error_log('[AdminMiddleware] cleanup failed: ' . $e->getMessage());
        }
                }

                $role = $user->role;

            } catch (\Core\Exceptions\HttpResponseException $e) {
                // HttpResponseException از json()/redirect() داخل بلاک if بالا آمده —
                // نباید swallow شود؛ مستقیماً re-throw می‌شود تا router آن را مدیریت کند.
                throw $e;
            } catch (\Throwable $e) {
                $this->logger->error('admin.middleware.db_error', ['error' => $e->getMessage()]);
                $session->destroy();
                $response = new Response();
                $response->redirect(url('login'));
            }

        // ── 4. بررسی نهایی نقش ───────────────────────────────────────────────
        if (!RolePolicy::isAdmin($role)) {
            $response = new Response();
            if ($request->isAjax()) {
                $response->json(['success' => false, 'message' => 'دسترسی غیرمجاز'], 403);
            }

            ob_start();
            view('errors/403');
            $content = ob_get_clean();

            $response->setStatusCode(403);
            $response->setContent($content ?: '403 Forbidden');
            return $response;
        }

        return $this->toResponse($next($request));
    }
}
