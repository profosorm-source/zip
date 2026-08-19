<?php

namespace App\Controllers\User;

use App\Controllers\BaseController;
use App\Services\CaptchaService;
use App\Services\Auth\AuthService;
use App\Services\User\UserService;

/**
 * BaseUserController — پایه تمام کنترلرهای پنل کاربر
 *
 * ─── سلسله مراتب ────────────────────────────────────────────────
 *
 *   Container::make(SomeUserController)
 *       └─→ SomeController::__construct(...services)
 *               └─→ parent::__construct(null, null, null, null, $logger)   ← بدون پارامتر
 *                       └─→ BaseController::__construct()
 *                               └─→ از Container: Request, Response, Session
 *
 * ─── قرارداد ───────────────────────────────────────────────────
 *   AuthService / UserService / CaptchaService از Container گرفته می‌شوند
 *   (نه از پارامتر constructor — چون همه فرزندها parent() بدون آرگومان صدا می‌زنند)
 */
abstract class BaseUserController extends BaseController
{
    protected AuthService $authService;
    protected UserService $userService;
    protected CaptchaService $captchaService;

    #[\Core\Attributes\Inject]
    protected \Core\Container $container;

    /**
     * وابستگی‌ها از طریق Constructor Dependency Injection
     * Container خودکار این dependencies را resolve می‌کند (Auto-wiring)
     * 
     * توجه: اگر parameters نادیده گرفته شوند، Container خودش resolve می‌کند
     */
    public function __construct(
        ?\Core\Session $session = null,
        ?\Core\Request $request = null,
        ?\Core\Response $response = null,
        ?\App\Services\Shared\PolicyService $policyService = null,
        ?\App\Contracts\LoggerInterface $logger = null,
        ?AuthService $authService = null,
        ?UserService $userService = null,
        ?CaptchaService $captchaService = null,
        ?\Core\CSRF $csrf = null
    ) {
        // Parent خودش resolveFromContainer را برای null params فراخوانی می‌کند
        parent::__construct($session, $request, $response, $policyService, $logger, $csrf);
        
        $this->authService = $authService ?? $this->resolveFromContainer(AuthService::class);
        $this->userService = $userService ?? $this->resolveFromContainer(UserService::class);
        $this->captchaService = $captchaService ?? $this->resolveFromContainer(CaptchaService::class);
    }
    
    /**
     * Helper method برای resolve کردن dependencies از Container تزریق‌شده
     * @template T of object
     * @param class-string<T> $class
     * @return T
     */
    protected function resolveFromContainer(string $class): object
    {
        $container = $this->container ?? \Core\Container::getInstance();
        return $container->make($class);
    }

    /** user_id کاربر لاگین‌شده یا null */
    protected function userId(): ?int
    {
        $id = $this->session->get('user_id');
        return $id ? int_value($id) : null;
    }

    /**
     * SEC-2 fix (BUGFIX-FALLBACK-USER-2026-06):
     *
     * Resolve the currently logged-in user as an object or send the visitor
     * back to login. This replaces the old anti-pattern scattered across
     * EscrowController, InvestmentController, ProfileController, TicketController,
     * TransferController where, on a failed DB lookup, a *hard-coded user* with
     *   ['id' => 1, 'email' => 'architect@chortke.ir', ...]
     * was assigned to $userObj, causing two distinct production hazards:
     *
     *   1. PII / spoofing: every error-state page leaked PII of the demo
     *      record to whichever real user happened to be viewing.
     *   2. Authorization confusion: any downstream code that trusted
     *      $userObj->id would silently operate against user #1
     *      (typically the platform owner/admin) instead of the actual
     *      session principal.
     *
     * This helper is the single canonical resolution point. It MUST NOT
     * fabricate any object on failure — callers either get a real DB row
     * or the request is terminated.
     */
    protected function loadCurrentUserOrRedirect(?\App\Services\User\UserService $userService = null): \stdClass
    {
        $userId = (int)$this->userId();
        if (!$userId) {
            $this->session->setFlash('error', 'ابتدا وارد حساب کاربری خود شوید.');
            $this->response->redirect(url('login'));
            exit;
        }

        $svc = $userService ?? $this->userService ?? $this->resolveFromContainer(\App\Services\User\UserService::class);

        // UserService exposes both find() and findById() in different parts
        // of the codebase; prefer findById() when available, fall back to find().
        $user = method_exists($svc, 'findById')
            ? $svc->findById($userId)
            : (method_exists($svc, 'find') ? $svc->find($userId) : null);

        if (!$user || !is_object($user)) {
            // The session points to a user that no longer exists in the DB
            // (deleted, soft-deleted, or DB lookup failure). Treat as a
            // hostile / corrupted session: clear it and send the user back
            // to login with an explicit error code so ops can correlate.
            $this->logger->warning('user.session_orphan', [
                    'session_user_id' => $userId,
                    'controller'      => static::class,
                ]);
            $this->session->destroy();
            $this->session->setFlash('error', 'نشست شما منقضی شده است. لطفاً دوباره وارد شوید.');
            $this->response->redirect(url('login?error=session_orphan'));
            exit;
        }

        return $user;
    }

    /** اگر لاگین نباشد → redirect به login */
    protected function requireAuth(): void
    {
        if (!(int)$this->userId()) {
            if (function_exists('is_ajax') && is_ajax()) {
                $this->response->error('احراز هویت لازم است', [], 401);
                exit;
            }
            $this->session->setFlash('error', 'ابتدا وارد حساب کاربری خود شوید.');
            $this->response->redirect(url('login'));
            exit;
        }

        $this->checkSessionTimeout();
    }

    /**
     * 🛡️ NEW-07: بررسی مهلت زمان غیرفعالی نشست کاربر (Session Inactivity Timeout)
     */
    protected function checkSessionTimeout(): void
    {
        $lastActivity = $this->session->get('last_activity');
        $timeout = 7200; // ۲ ساعت مهلت غیرفعالی به ثانیه
        
        if ($lastActivity && (time() - int_value($lastActivity) > $timeout)) {
            $userId = (int)$this->userId();
            $this->logger->info('session.timeout', ['user_id' => $userId]);
            
            // خروج ایمن و تخریب نشست
            $this->authService->logout();
            $this->session->destroy();
            
            if (function_exists('is_ajax') && is_ajax()) {
                $this->response->error('نشست شما به دلیل عدم فعالیت منقضی شده است.', [], 401);
                exit;
            }
            
            $this->session->setFlash('error', 'نشست شما به دلیل عدم فعالیت منقضی شده است. لطفاً مجدداً وارد شوید.');
            $this->response->redirect(url('login'));
            exit;
        }
        
        $this->session->set('last_activity', time());
    }
}
