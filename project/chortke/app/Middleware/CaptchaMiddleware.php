<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Services\CaptchaService;
use App\Services\Auth\LoginRiskService;
use Core\Request;
use Core\Response;
use Closure;

/**
 * CaptchaMiddleware — اعمالِ متمرکز و ریسک‌محورِ کپچا روی فرم‌های حساس (ورود/ثبت‌نام).
 *
 * ROOT-FIX (Single Enforcement Path): منطقی که پیش‌تر به‌صورت تکراری داخل
 * AuthController::login()/register() تکرار شده بود، اینجا به یک نقطهٔ واحد منتقل شد:
 *   - تصمیم ریسک‌محور با LoginRiskService::getCaptchaType() (null = کپچا لازم نیست)
 *   - پشتیبانی از recaptcha_v2 (فیلد g-recaptcha-response) و انواع math/image/behavioral
 *   - ثبت شکست با LoginRiskService::recordFailure() برای تشدید تدریجیِ سختی (حلقهٔ ضدتقلب)
 *
 * context از طریق سینتکس پارامتریِ Pipeline پاس داده می‌شود، مثال:
 *   CaptchaMiddleware::class . ':login'   یا   CaptchaMiddleware::class . ':register'
 */
class CaptchaMiddleware extends BaseMiddleware
{
    private CaptchaService $captchaService;
    private LoginRiskService $loginRiskService;

    public function __construct(CaptchaService $captchaService, LoginRiskService $loginRiskService)
    {
        $this->captchaService = $captchaService;
        $this->loginRiskService = $loginRiskService;
    }

    public function handle(Request $request, Closure $next, ?string $context = null): Response
    {
        // فقط روی POST و زمانی که کپچا فعال است اعمال می‌شود.
        if ($request->method() !== 'POST' || !$this->captchaService->isEnabled()) {
            return $this->toResponse($next($request));
        }

        $context = ($context === 'register') ? 'register' : 'login';

        // شناسهٔ ریسک: در ورود، ایمیلِ نرمال‌شده مبنای امتیازدهی است؛ در ثبت‌نام شناسه‌ای در دست نیست.
        $identifier = null;
        if ($context === 'login') {
            $email = mb_strtolower(trim($request->str('email')), 'UTF-8');
            $identifier = $email !== '' ? $email : null;
        }

        $captchaType = $this->loginRiskService->getCaptchaType($context, null, $identifier);
        if ($captchaType === null) {
            // ریسک پایین: نیازی به کپچا نیست.
            return $this->toResponse($next($request));
        }

        $captchaToken    = trim($request->str('captcha_token'));
        $captchaResp     = trim($request->str('captcha_response'));
        $recaptchaResp   = trim($request->str('g-recaptcha-response'));
        $behavioralState = trim($request->str('behavioral_state'));

        if ($captchaType === 'recaptcha_v2') {
            $passed = ($recaptchaResp !== '' && $this->captchaService->verify('', '', $recaptchaResp));
        } else {
            $isBehavioral = ($captchaType === 'behavioral');
            $passed = !(
                $captchaToken === ''
                || (!$isBehavioral && $captchaResp === '')
                || !$this->captchaService->verify($captchaToken, $captchaResp, null, $behavioralState)
            );
        }

        if (!$passed) {
            $this->loginRiskService->recordFailure($context, null, $identifier);
            return $this->reject($request, $context, $identifier);
        }

        return $this->toResponse($next($request));
    }

    /**
     * پاسخِ ردِ کپچا — سازگار با AJAX (JSON 422) و درخواست معمول (redirect به فرم مبدأ).
     * توجه: Response::json()/redirect() از طریق send() یک HttpResponseException می‌اندازند
     * که در Router::dispatch() گرفته و منتشر می‌شود؛ لذا return پس از آن‌ها اجرا نمی‌شود.
     */
    private function reject(Request $request, string $context, ?string $identifier): Response
    {
        $response = new Response();

        if ($request->isAjax()) {
            $response->json([
                'success' => false,
                'message' => 'کد امنیتی اشتباه است. لطفاً دوباره تلاش کنید.',
            ], 422);
            return $response;
        }

        if ($context === 'login' && $identifier !== null) {
            session()->set('old_email', $identifier);
            session()->setFlash('old', ['email' => $identifier]);
        }
        session()->setFlash('error', 'کپچا اشتباه است.');

        $response->redirect(url($context === 'register' ? 'register' : 'login'));
        return $response;
    }
}
