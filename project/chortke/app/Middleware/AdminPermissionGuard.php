<?php

declare(strict_types=1);

namespace App\Middleware;

use Core\Request;
use Core\Response;
use Core\Session;
use Closure;
use App\Services\Shared\PolicyService;
use App\Contracts\LoggerInterface;

/**
 * AdminPermissionGuard — گاردِ متمرکزِ deny-by-default پنل مدیریت (RBAC)
 * -------------------------------------------------------------------
 * ریشهٔ مشکل (M-10): اکثرِ مسیرهای ادمین فقط AdminMiddleware (نقش = admin)
 * داشتند و هیچ کنترلِ مجوزِ ریزدانه نداشتند — یعنی هر کاربرِ admin
 * به همهٔ عملیات‌های مالی/امنیتی/مدیریتی دسترسی داشت.
 *
 * راه‌حل ریشه‌ای: این گارد به گروه middleware‌های $admin در routes/admin.php افزوده
 * می‌شود و برای هر درخواست، مجوزِ لازم را از نقشهٔ متمرکزِ
 * config/admin_permissions.php (بر اساس Controller@action مسیرِ جاری) استخراج
 * می‌کند. اگر مسیر در نقشه نباشد یا کاربر مجوز نداشته باشد، دسترسی
 * رد می‌شود (deny-by-default / fail-closed).
 *
 * super_admin همیشه بایپس می‌شود تا هیچ‌گاه مالکِ سیستم قفل نشود.
 */
class AdminPermissionGuard extends BaseMiddleware
{
    private Session $session;
    private PolicyService $policy;
    private LoggerInterface $logger;

    /** @var array<string, array{default?: string, methods?: array<string, string>}>|null */
    private static ?array $mapCache = null;

    public function __construct(Session $session, PolicyService $policy, LoggerInterface $logger)
    {
        $this->session = $session;
        $this->policy = $policy;
        $this->logger = $logger;
    }

    public function handle(Request $request, Closure $next): mixed
    {
        // منبع user_id: انتزاعِ Session (دقیقاً هم‌راستا با PermissionMiddleware::hasPermission)
        $userId = int_value($this->session->get('user_id'));

        // ① super_admin → دسترسی کامل (ضدِ قفل‌شدنِ مالک)
        if ($userId > 0 && $this->policy->isSuperAdminById($userId)) {
            return $this->toResponse($next($request));
        }

        // ② تعیین permission لازم بر اساس Controller@action مسیرِ جاری
        $required = $this->resolveRequiredPermission($request);

        if ($required === null) {
            // deny-by-default: مسیرِ نگاشت‌نشده → رد و ثبت در لاگ جهت پیگیری
            $this->logger->warning('rbac.admin_route_unmapped', [
                'user_id' => $userId,
                'uri'     => $request->uri(),
                'method'  => $request->method(),
            ]);
            return $this->deny($request);
        }

        // ③ بررسی مجوز (DB-authoritative از طریق PolicyService)
        if ($userId <= 0 || !$this->policy->authorizeById($required, $userId)) {
            $this->logger->warning('rbac.admin_permission_denied', [
                'user_id'    => $userId,
                'permission' => $required,
                'uri'        => $request->uri(),
            ]);
            return $this->deny($request);
        }

        return $this->toResponse($next($request));
    }

    /**
     * استخراج permission لازم از نقشهٔ متمرکز بر اساس action مسیرِ جاری.
     * action توسط Router روی Request قرار داده شده (attribute: _resolved_action).
     */
    private function resolveRequiredPermission(Request $request): ?string
    {
        $action = $request->getAttribute('_resolved_action');

        // فقط اکشنِ [ControllerClass, 'method'] قابل نگاشت است. Closure / نامشخص → null (deny)
        if (!is_array($action) || count($action) !== 2) {
            return null;
        }

        [$class, $method] = $action;
        if (!is_string($class) || !is_string($method)) {
            return null;
        }

        // کلیدِ نقشه = نامِ کوتاهِ کلاس (مستقل از aliasهای use)
        $pos   = strrpos($class, '\\');
        $short = $pos === false ? $class : substr($class, $pos + 1);

        $map = self::loadMap();
        if (!isset($map[$short])) {
            return null;
        }

        $entry = $map[$short];
        if (isset($entry['methods'][$method])) {
            return $entry['methods'][$method];
        }

        return $entry['default'] ?? null;
    }

    /**
     * بارگذاری نقشهٔ مجوزها (با کش ایستا در طول عمرِ پروسه).
     * @return array<string, array{default?: string, methods?: array<string, string>}>
     */
    private static function loadMap(): array
    {
        if (self::$mapCache === null) {
            $path = dirname(__DIR__, 2) . '/config/admin_permissions.php';
            /** @var array<string, array{default?: string, methods?: array<string, string>}> $map */
            $map = is_file($path) ? (array) require $path : [];
            self::$mapCache = $map;
        }
        return self::$mapCache;
    }

    /**
     * پاسخِ ۴۰۳ امن (هم‌راستا با PermissionMiddleware).
     */
    private function deny(Request $request): Response
    {
        $response = new Response();

        if ($request->isAjax()) {
            $response->json([
                'success' => false,
                'message' => config('messages.permission.forbidden') ?? 'دسترسی غیرمجاز',
            ], 403);
            return $response;
        }

        ob_start();
        view('errors/403');
        $content = ob_get_clean();

        $response->setStatusCode(403);
        $response->setContent($content ?: '403 Forbidden');
        return $response;
    }
}
