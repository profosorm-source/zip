<?php

namespace App\Controllers;

use Core\Container;
use Core\Session;
use Core\Request;
use Core\Response;
use App\Services\Shared\PolicyService;
use App\Policies\RolePolicy;
use App\Contracts\LoggerInterface;
use App\Contracts\ValidatorFactoryInterface;

/**
 * BaseController — پایه تمام کنترلرهای پروژه
 *
 * ─── جریان صحیح (تعریف‌شده) ───────────────────────────────────
 *
 *   Container::make(UserController)
 *       └─→ UserController::__construct()          ← هیچ پارامتری لازم نیست
 *               └─→ BaseController::__construct()
 *                       └─→ از Container: Request, Response, Session
 *
 * ─── قرارداد ───────────────────────────────────────────────────
 *   $this->request   → Core\Request   (singleton از Container)
 *   $this->response  → Core\Response  (singleton از Container)
 *   $this->session   → Core\Session   (singleton از Container)
 *
 * ─── تذکر مهم ──────────────────────────────────────────────────
 *   هیچ کنترلری نباید مستقیم از Database یا Model استفاده کند.
 *   وابستگی‌ها باید از طریق Service به Controller تزریق شوند.
 */
abstract class BaseController
{
    use \App\Traits\UsesValidatorFactory;
    
    protected Session  $session;
    protected Request  $request;
    protected Response $response;
    protected PolicyService $policyService;
    protected LoggerInterface $logger;
    protected \Core\CSRF $csrf;
    protected \Core\UrlGenerator $urlGenerator;

    /**
     * وابستگی‌های اجباری کنترلر — Constructor Dependency Injection
     *
     * رفع ریشه‌ای: اگر parameter های null پاس داده شوند (مثلاً توسط
     * کنترلرهای فرزندی که فقط logger را تزریق می‌کنند)، از app() helper
     * و در نتیجه Container، dependency resolve می‌شود.
     *
     * این روش:
     *  ۱. جلوی null بودن property را می‌گیرد (رفع Call to member on null)
     *  ۲. نیاز به lazy accessor متدها را حذف می‌کند (جلوگیری از تصادف نام متد)
     *  ۳. ۲۳۷۱+ دسترسی مستقیم $this->session-> بدون تغییر کار می‌کند
     *  ۴. در تست‌ها همچنان می‌توان mock پاس داد
     */
    public function __construct(
        ?Session $session = null,
        ?Request $request = null,
        ?Response $response = null,
        ?PolicyService $policyService = null,
        ?LoggerInterface $logger = null,
        ?\Core\CSRF $csrf = null,
        ?ValidatorFactoryInterface $validatorFactory = null,
        ?\Core\UrlGenerator $urlGenerator = null
    ) {
        $this->session       = $session       ?? app(Session::class);
        $this->request       = $request       ?? app(Request::class);
        $this->response      = $response      ?? app(Response::class);
        $this->policyService = $policyService ?? app(PolicyService::class);
        $this->logger        = $logger        ?? app(\App\Contracts\LoggerInterface::class);
        $this->csrf = $csrf ?? app(\Core\CSRF::class);
        $this->validatorFactory = $validatorFactory ?? app(ValidatorFactoryInterface::class);
        $this->urlGenerator = $urlGenerator ?? app(\Core\UrlGenerator::class);
    }
    
    /**
     * اعتبارسنجی توکن CSRF
     */
    protected function validateCsrf(): void
    {
        $this->csrf->validate();
    }

    // ─────────────────────────────────────────────────────────────
    // Auth Helpers
    // ─────────────────────────────────────────────────────────────

    /** user_id کاربر لاگین‌شده یا null */
    protected function userId(): ?int
    {
        $id = $this->session->get('user_id');
        return $id ? int_value($id) : null;
    }

    /** اگر لاگین نباشد → redirect به login */
    protected function requireAuth(): void
    {
        if (!(int)$this->userId()) {
            if (is_ajax()) {
                $this->response->error('احراز هویت لازم است', [], 401);
            }
            $this->session->setFlash('error', 'ابتدا وارد حساب کاربری خود شوید.');
            $this->response->redirect(url('login'));
        }
    }



    /** اگر admin نباشد → 403 */
    protected function requireAdmin(): void
    {
        $userId = (int)$this->userId();
        if (!$userId) {
            $this->requireAuth();
            return;
        }

        // استفاده از PolicyService (Sprint 5) برای centralized authorization
        if (!$this->policyService->isAdminById($userId)) {
            if (is_ajax()) {
                $this->response->error('دسترسی غیرمجاز', [], 403);
            }
            $this->response->redirect(url('dashboard'));
        }
    }

    /**
     * L-04: گیتِ «ورود به ناحیهٔ ادمین» — نقشِ محدودِ support را هم می‌پذیرد.
     * فقط برای کنترلرهایی که آگاهانه به support مجاز شده‌اند (تیکت/مدیریت‌پیام/گزارش‌باگ)
     * و در ادامه با authorizeById عملیات را ریزدانه محدود می‌کنند. سایر کنترلرها همچنان
     * از requireAdmin() (فقط admin/super_admin) استفاده می‌کنند.
     */
    protected function requireAdminArea(): void
    {
        $userId = (int)$this->userId();
        if (!$userId) {
            $this->requireAuth();
            return;
        }

        if (!$this->policyService->isAdminAreaById($userId)) {
            if (is_ajax()) {
                $this->response->error('دسترسی غیرمجاز', [], 403);
            }
            $this->response->redirect(url('dashboard'));
        }
    }

    /** بررسی permission خاص */
    protected function requirePermission(string $permission): void
    {
        $userId = (int)$this->userId();
        if (!$userId) {
            $this->requireAuth();
            return;
        }

        // استفاده از PolicyService (Sprint 5)
        if (!$this->policyService->authorizeById($permission, $userId)) {
            if (is_ajax()) {
                $this->response->error('مجوز کافی ندارید', [], 403);
            }
            $this->session->setFlash('error', 'مجوز کافی ندارید.');
            $this->back();
        }
    }

    // ─────────────────────────────────────────────────────────────
    // Response Helpers
    // ─────────────────────────────────────────────────────────────

    /** @param array<string, mixed> $data */
    protected function json(bool $success, string $message = '', array $data = [], int $code = 200): void
    {
        // H10 Fix: تجمیع متد اختصاصی کنترلر در شیء Response مرکزی جهت فعال شدن معماری Exception-based
        $this->response->json([
            'success' => $success,
            'message' => $message,
            'data'    => $data,
        ], $code);
        // متد بالا اتوماتیک HttpResponseException شلیک کرده و اجرا را متوقف می‌کند.
    }

    /** @param array<string, mixed> $data */
    protected function jsonSuccess(string $message = '', array $data = []): void
    {
        $this->json(true, $message, $data, 200);
    }

    /** @param array<string, mixed> $data */
    protected function jsonError(string $message, array $data = [], int $code = 422): void
    {
        $this->json(false, $message, $data, $code);
    }

    /** redirect به صفحه قبلی (یا fallback) */
   protected function back(string $fallback = '/'): void {
    $ref = str_value($this->request->header('referer', ''));
    
    // اعتبارسنجی origin دقیق؛ prefix comparison می‌تواند
    // https://example.test.attacker را به اشتباه same-origin بداند.
    if ($ref !== '') {
        $scheme = parse_url($ref, PHP_URL_SCHEME);
        $host = parse_url($ref, PHP_URL_HOST);
        $port = parse_url($ref, PHP_URL_PORT);
        if (is_string($scheme) && is_string($host)) {
            $refererOrigin = strtolower($scheme . '://' . $host . (is_int($port) ? ':' . $port : ''));
            if (hash_equals(strtolower($this->urlGenerator->origin()), $refererOrigin)) {
                $this->response->redirect($ref);
            }
        }
    }

    $this->response->redirect($this->urlGenerator->to($fallback));
}

    /** flash + redirect ترکیبی */
    protected function redirectWithError(string $message, string $to = ''): void
    {
        $this->session->setFlash('error', $message);
        $to ? $this->response->redirect(url($to)) : $this->back();
    }

    protected function redirectWithSuccess(string $message, string $to = ''): void
    {
        $this->session->setFlash('success', $message);
        $to ? $this->response->redirect(url($to)) : $this->back();
    }

    /** render view با داده */
    /** @param array<string, mixed> $data */
    protected function view(string $template, array $data = []): string
    {
        $output = view($template, $data);
        return $output;
    }

    /**
     * Helper برای استخراج امن page و limit/perPage جهت محاسبه offset (حل باگ Finding #18 & #19)
     * @return array{0: int, 1: int, 2: int} [$page, $limit, $offset]
     */
    protected function pageParams(int $defaultLimit = 20, int $maxLimit = 100): array
    {
        $page  = $this->request->page();
        $limit = $this->request->limit('limit', $defaultLimit, $maxLimit);
        $offset = ($page - 1) * $limit;
        return [$page, $limit, $offset];
    }
    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    protected function validateRequest(string $formRequestClass, array $data = []): array
    {
        if (empty($data)) {
            $data = $this->request->all();
        }

        if (!class_exists($formRequestClass)) {
            throw new \InvalidArgumentException("کلاس اعتبارسنجی {$formRequestClass} یافت نشد.");
        }

        /** @var \App\Validators\BaseFormRequest $request */
        $request = new $formRequestClass($data);

        if (!$request->validate()) {
            $errors = $request->errors();

            if ($this instanceof \App\Controllers\Api\BaseApiController || is_ajax()) {
                // ارسال پاسخ استاندارد JSON در صورت درخواست AJAX
                $this->json(false, 'داده‌های ورودی نامعتبر است', ['errors' => $errors], 422);
                return [];  // Exit execution to prevent validated() from running
            } else {
                $firstError = 'داده‌های ورودی نامعتبر است';
                if (is_array($errors) && $errors !== []) {
                    $first = reset($errors);
                    if (is_string($first)) {
                        $firstError = $first;
                    } elseif (is_array($first)) {
                        $candidate = reset($first);
                        if (is_string($candidate)) {
                            $firstError = $candidate;
                        }
                    }
                }
                $this->session->setFlash('error', $firstError);
                $this->session->setFlash('errors', $errors);
                $this->session->setFlash('old', $data);
                $this->back();
                return [];  // Exit execution after redirect
            }
        }

        return $request->validated();
    }
}
