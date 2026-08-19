<?php

declare(strict_types=1);

namespace App\Controllers\User;

use App\Services\User\UserService;
use App\Services\Auth\AuthService;
use App\Controllers\BaseController;
use App\Services\Auth\LoginRiskService;
use App\Validators\LoginRequest;
use App\Constants\SessionKeys;

/**
 * AuthController
 * 
 * مدیریت فرآیندهای احراز هویت (ورود، ثبت‌نام، فراموشی رمز عبور).
 * 
 * SECURITY NOTES:
 * - User enumeration is prevented with constant-time responses
 * - Rate limiting on all authentication endpoints
 * - Session isolation for different auth states
 */
class AuthController extends BaseController
{
    private UserService $userService;
    private AuthService $authService;
    private LoginRiskService $loginRiskService;
    private \App\Services\AntiFraud\FraudGuardService $fraudGuard;
    private \Core\RateLimiter $rateLimiter;
    private \App\Services\EmailService $emailService;
    private \App\Models\SecurityModel $securityModel;
    private \App\Models\UserVerification $userVerification;

    public function __construct(
        \Core\Session $session,
        \Core\Request $request,
        \Core\Response $response,
        \App\Services\Shared\PolicyService $policyService,
        \App\Contracts\LoggerInterface $logger,
        UserService $userService,
        AuthService $authService,
        LoginRiskService $loginRiskService,
        \App\Services\AntiFraud\FraudGuardService $fraudGuard,
        \Core\RateLimiter $rateLimiter,
        \App\Services\EmailService $emailService,
        \App\Models\SecurityModel $securityModel,
        \App\Models\UserVerification $userVerification,
        ?\Core\CSRF $csrf = null
    ) {        $this->userService = $userService;
        $this->authService = $authService;
        $this->loginRiskService = $loginRiskService;
        $this->fraudGuard = $fraudGuard;
        $this->rateLimiter = $rateLimiter;
        $this->emailService = $emailService;
        $this->securityModel = $securityModel;
        $this->userVerification = $userVerification;

        parent::__construct($session, $request, $response, $policyService, $logger, $csrf);
    }

    /**
     * نمایش فرم ورود
     */
    public function showLogin(): void
    {
        // If a previous failed attempt provided an identifier (email), prefer it
        $identifier = $this->session->get('old_email', null);
        // remove one-time old email after reading
        if ($identifier) {
            $this->session->remove('old_email');
        }

        // UX Fix: ایمیل flash شده از تلاش قبلی هم بررسی شود
        $flashValue = $this->session->getFlash('old', []);
        $flashOld = is_array($flashValue) ? $flashValue : [];
        if (!$identifier && is_string($flashOld['email'] ?? null) && $flashOld['email'] !== '') {
            $identifier = $flashOld['email'];
        }

        $captchaIdentifier = is_string($identifier) ? $identifier : null;
        $captchaType = $this->loginRiskService->getCaptchaType('login', null, $captchaIdentifier);
        $this->view('user/login', [
            'title'       => 'ورود به سیستم',
            'captchaType' => $captchaType,
            'old'         => $identifier ? ['email' => $identifier] : [],
        ]);
    }

    /**
     * پردازش ورود
     */
    public function login(): void
    {
        // CRITICAL-01 Fix: Redundant checkRateLimit removed. AuthService::login now handles 
        // consolidated IP + Identifier rate limiting.

        $data = $this->request->all();
        $email = mb_strtolower(trim($this->request->str('email')), 'UTF-8');
        // CAPTCHA: enforced centrally by CaptchaMiddleware (':login') in routes/auth.php.

        // اعتبارسنجی ورودی با استفاده از FormRequest
        $loginReq = new LoginRequest($data);
        if ($loginReq->fails() || !$loginReq->validate()) {
            $this->session->set('old_email', $email);
            $this->session->setFlash('old', ['email' => $email]);
            $this->session->setFlash('error', 'لطفاً اطلاعات را به درستی وارد کنید.');
            $this->response->redirect(url('login'));
            return;
        }
        $data = $loginReq->validated();

        // 🛡️ گیت ضدتقلب و امنیت هوشمند
        try {
            $user = $this->userService->findByCredentials($email);
        } catch (\Throwable $e) {
            $this->logger->error('auth.login.find_credentials_failed', ['email' => $email, 'error' => $e->getMessage()]);
            $this->session->set('old_email', $email);
            $this->session->setFlash('old', ['email' => $email]);
            $this->session->setFlash('error', 'خطای سیستمی رخ داده است. لطفاً دوباره تلاش کنید.');
            $this->response->redirect(url('login'));
            return;
        }
        $userId = $user ? (int)$user->id : 0;

        // ── LOGIN FLOW FIX: Check verification status BEFORE finishing login ──
        if ($user && empty($user->email_verified_at)) {
            $this->session->set('pending_verification_email', $email);
            $this->session->setFlash('info', 'حساب شما هنوز تایید نشده است. لطفاً کد تایید را وارد کنید.');
            $this->response->redirect(url('email/verify-code'));
            return;
        }
        // ─────────────────────────────────────────────────────────────────────

        try {
            $risk = $this->fraudGuard->checkAction($userId, 'auth.login', [
                'email'      => $email,
                'ip'         => $this->request->ip(),
                'user_agent' => $this->request->userAgent()
            ]);
        } catch (\Throwable $e) {
            $this->logger->warning('auth.login.fraud_guard_skipped', [
                'error' => $e->getMessage(),
                'email' => $email
            ]);
            $risk = ['allowed' => true];
        }

        if (!$risk['allowed']) {
            $this->logger->warning('auth.login_blocked_by_fraud_guard', [
                'email' => $email,
                'reason' => $risk['reason']
            ]);
            $this->session->setFlash('error', 'درخواست ورود به دلیل تشخیص فعالیت غیرمجاز مسدود گردید.');
            $this->response->redirect(url('login'));
            return;
        }

        $remember = ($data['remember'] ?? '') === 'on';
        try {
            $result = $this->authService->login($email, $this->request->str('password'), $remember);
        } catch (\Throwable $e) {
            $this->logger->error('auth.login.service_failed', ['email' => $email, 'error' => $e->getMessage()]);
            $this->session->set('old_email', $email);
            $this->session->setFlash('old', ['email' => $email]);
            $this->session->setFlash('error', 'خطای سیستمی رخ داده است. لطفاً دوباره تلاش کنید.');
            $this->response->redirect(url('login'));
            return;
        }

        if (!$result['success']) {
            $this->loginRiskService->recordFailure('login', null, $this->request->str('email'));
            // UX: حفظ ایمیل در فرم بعد از خطا
            $this->session->set('old_email', $this->request->str('email'));
            $this->session->setFlash('old', ['email' => $this->request->str('email')]);
            if (!empty($result['email_unverified'])) {
                $this->session->set('pending_verification_email', $result['email']);
                $this->session->setFlash('success', 'ایمیل تأیید ارسال شد.');
                $this->response->redirect(url('email/verify-code'));
                return;
            }
            $this->session->setFlash('error', $result['message']);
            $this->response->redirect(url('login'));
            return;
        }

        if (!empty($result['requires_2fa'])) {
            $loginUser = $result['user'] ?? null;
            $this->session->set('pending_2fa_user_id', int_value(is_object($loginUser) ? ($loginUser->id ?? 0) : 0));
            $this->response->redirect(url('verify-2fa'));
            return;
        }

        // CRITICAL-C1 Fix: Redundant regenerate() removed. AuthService::login already calls regenerate(true)
        // to prevent session fixation and ensure 2FA pending state isolation.
        
        $this->loginRiskService->clearFailures('login', null, $this->request->str('email'));
        $this->csrf->regenerate();
        $this->session->setFlash('success', 'خوش آمدید!');
        $this->response->redirect(url('dashboard'));
    }

    /**
     * نمایش فرم ثبت‌نام
     */
    public function showRegister(): void
    {
        $ref = str_value($this->request->query('ref', ''));
        if ($ref && preg_match('/^[A-Za-z0-9_]{4,32}$/', $ref)) {
            $this->session->set('register_referral_code', $ref);
        }

        $this->view('user/register', [
            'referralCode' => $this->session->get('register_referral_code'),
            'captchaType'  => $this->loginRiskService->getCaptchaType('register'),
        ]);
    }

    /**
     * پردازش ثبت‌نام
     */
    public function register(): void
    {
        // CRITICAL-01 Fix: Redundant checkRateLimit removed.

        // CAPTCHA: enforced centrally by CaptchaMiddleware (':register') in routes/auth.php.
        // ── SECURITY: فقط فیلدهای مجاز از POST body خوانده می‌شوند (دفاع لایه Controller) ──
        // لایه دوم دفاع در برابر Mass Assignment — لایه اصلی در UserService::register است.
        $data = $this->request->only([
            'full_name', 'email', 'password', 'password_confirmation',
            'username', 'referral_code_used', 'referral_code', 'mobile',
        ]);
        if (empty($data['referral_code_used']) && !empty($data['referral_code'])) {
            $data['referral_code_used'] = trim(str_value($data['referral_code']));
        }
        if (empty($data['referral_code_used']) && $this->session->get('register_referral_code')) {
            $data['referral_code_used'] = str_value($this->session->get('register_referral_code'));
        }
        unset($data['referral_code']);
        // ─────────────────────────────────────────────────────────────────────────────────

        $errors = $this->authService->validateRegister($data);
        if (!empty($errors)) {
            $this->logger->error('auth.registration.validation_failed', [
                'errors' => $errors,
                'data' => $data
            ]);
            $this->session->setFlash('error', implode('<br>', $errors));
            $this->response->redirect(url('register'));
            return;
        }

        // 🛡️ گیت ضدتقلب و امنیت ثبت‌نام (شناسایی ربات‌ها، ایمیل‌های یک‌بار مصرف و مخرب)
        $risk = $this->fraudGuard->checkAction(0, 'auth.register', [
            'email'      => str_value($data['email'] ?? ''),
            'phone'      => str_value($data['mobile'] ?? ''),
            'ip'         => $this->request->ip(),
            'user_agent' => $this->request->userAgent()
        ]);

        if (!$risk['allowed']) {
            $this->logger->warning('auth.registration_blocked_by_fraud_guard', [
                'email'  => $data['email'] ?? 'unknown',
                'reason' => $risk['reason']
            ]);
            $this->session->setFlash('error', 'امکان ثبت‌نام به دلیل تشخیص رفتارهای مشکوک مسدود گردید.');
            $this->response->redirect(url('register'));
            return;
        }

        $result = $this->authService->register($data);
        if (!$result['success']) {
            $this->session->setFlash('error', $result['message']);
            $this->response->redirect(url('register'));
            return;
        }

        $this->session->remove('register_referral_code');
        
        // CRITICAL-01 Fix: Regenerate session ID immediately after registration
        // to prevent session fixation attacks.
        $this->session->regenerate(true);

        // HIGH-H-13 Fix: Store timestamp to enforce 15-minute expiration for pending verification
        $this->session->set('pending_verification_email', $data['email']);
        $this->session->set('pending_verification_at', time());

        $this->session->setFlash('success', 'ثبت‌نام موفق! لطفاً ایمیل خود را تأیید کنید.');
        $this->response->redirect(url('email/verify-code'));
    }

    /**
     * تأیید ایمیل از طریق لینک (Token)
     */
    public function verifyEmail(): void
    {
        $token = str_value($this->request->query('token'));
        if (!$token) {
            $this->response->redirect(url('login'));
            return;
        }

        // BUGFIX-CTRL-RAW-SQL-2026-06: lookup encapsulated in UserVerification model.
        $hashedToken = hash_hmac('sha256', (string)$token, secure_key());
        $verification = $this->userVerification->findByToken($hashedToken);

        if (!$verification) {
            $this->session->setFlash('error', 'لینک تأیید نامعتبر یا منقضی شده است.');
            $this->response->redirect(url('login'));
            return;
        }

        $this->userService->verifyEmail((int)$verification->user_id);
        $this->session->setFlash('success', 'ایمیل شما با موفقیت تأیید شد.');
        $this->response->redirect(url('dashboard'));
    }

    /**
     * نمایش صفحه وارد کردن کد تأیید ایمیل
     */
    public function showVerifyEmail(): void
    {
        $email = str_value($this->session->get('pending_verification_email'));
        if ($email === '') {
            $this->response->redirect(url('login'));
            return;
        }

        // ── FIX: Update timestamp for users coming from login flow ──
        // If the user is logging in, they should get a fresh 15-minute window to verify
        $createdAt = int_value($this->session->get('pending_verification_at', 0));
        if ($createdAt === 0) {
            $this->session->set('pending_verification_at', time());
            $createdAt = time();
        }

        // بررسی انقضا (فقط برای کسانی که تازه ثبت‌نام کرده‌اند و Session دارند)
        if (time() - $createdAt > 900) { // 15 minutes
            $this->session->remove('pending_verification_email');
            $this->session->remove('pending_verification_at');
            $this->session->setFlash('error', 'مهلت زمانی تأیید به پایان رسیده است. لطفاً دوباره ثبت‌نام کنید.');
            $this->response->redirect(url('register'));
            return;
        }

        $this->view('user/verify-email', [
            'title' => 'تأیید ایمیل',
            'email' => $email
        ]);
    }

    /**
     * پردازش کد تأیید ایمیل
     * 
     * HIGH-H-08 Fix: Prevent user enumeration by using constant-time validation
     * and consistent error messages. Rate limiting happens BEFORE user lookup.
     */
    public function verifyEmailByCode(): void
    {
        $email = str_value($this->session->get('pending_verification_email'));
        if ($email === '') {
            $this->response->redirect(url('login'));
            return;
        }

        $ip = $this->request->ip();
        
        $rateLimitId = "verify_email_attempts:" . hash('sha256', $email);
        
        if (!$this->rateLimiter->attempt($rateLimitId, 5, 15, true)) {
             $this->logger->critical('auth.email_verification.bruteforce_detected', ['email' => $email, 'ip' => $ip]);
             $this->session->destroy();
             $this->session->setFlash('error', 'تعداد تلاش‌های ناموفق بیش از حد مجاز است. نشست شما برای امنیت بیشتر بسته شد.');
             $this->response->redirect(url('login'));
             return;
        }

        $code = trim($this->request->str('code'));
        if (strlen($code) !== 6) {
            $this->session->setFlash('error', 'کد وارد شده باید ۶ رقم باشد.');
            $this->response->redirect(url('email/verify-code'));
            return;
        }

        if (!preg_match('/^[A-Z0-9]{6}$/i', $code)) {
            $this->session->setFlash('error', 'کد وارد شده نامعتبر است.');
            $this->response->redirect(url('email/verify-code'));
            return;
        }

        $user = $this->userService->findByEmail($email);
        
        if (!$user) {
            usleep(random_int(50000, 150000));
            $this->session->setFlash('error', 'کد نامعتبر است یا منقضی شده.');
            $this->response->redirect(url('email/verify-code'));
            return;
        }

        // BUGFIX-CTRL-RAW-SQL-2026-06: OTP lookup moved to UserVerification model.
        $verification = $this->userVerification
            ->findValidCode((int)$user->id, strtoupper((string)$code), 'email');

        if (!$verification) {
            usleep(random_int(50000, 150000));
            $this->session->setFlash('error', 'کد نامعتبر است یا منقضی شده.');
            $this->response->redirect(url('email/verify-code'));
            return;
        }

        // تایید موفق
        $this->userService->verifyEmail((int)$user->id);
        $this->session->remove('pending_verification_email');
        $this->session->remove('pending_verification_at');

        $this->session->setFlash('success', 'ایمیل شما با موفقیت تأیید شد. اکنون می‌توانید وارد شوید.');
        $this->response->redirect(url('login'));
    }

    /**
     * نمایش صفحه ارسال مجدد ایمیل تأیید
     */
    public function showResendVerification(): void
    {
        $this->view('user/verify-email', [
            'title' => 'ارسال مجدد ایمیل تأیید',
            'resend_mode' => true
        ]);
    }

    /**
     * ارسال مجدد ایمیل تأیید
     */
    public function resendVerification(): void
    {
        $email = str_value($this->session->get('pending_verification_email'));
        $genericMsg = 'در صورت وجود حساب، ایمیل ارسال شد.';
        
        if (!$email) {
            $this->session->setFlash('info', $genericMsg);
            $this->response->redirect(url('login'));
            return;
        }

        // CRITICAL-04 Fix: Using RateLimiter directly to match correct signature and behavior
        $ip = $this->request->ip();
        $rateLimitKey = "resend_email:" . hash('sha256', "{$email}:{$ip}");
        
        if (!$this->rateLimiter->attempt($rateLimitKey, 3, 120, true)) {
            $this->session->setFlash('error', 'لطفاً چند دقیقه صبر کنید و سپس دوباره تلاش کنید.');
            $this->response->redirect(url('email/verify-code'));
            return;
        }

        $user = $this->userService->findByEmail($email);
        
        // HIGH-H-08 Fix: Rotate verification token on resend to prevent use of leaked tokens
        if ($user && empty($user->email_verified_at)) {
            $newToken = bin2hex(random_bytes(32));
            $hashedToken = hash_hmac('sha256', strtoupper(substr($newToken, 0, 6)), secure_key());
            
            $this->userService->update((int)$user->id, ['email_verification_token' => $hashedToken]);

            // BUGFIX-CTRL-RAW-SQL-2026-06: upsert encapsulated in UserVerification model.
            $otpCode = strtoupper(substr($newToken, 0, 6));
            $expiresAt = date('Y-m-d H:i:s', (strtotime('+1 hour') ?: time()));
            $this->userVerification
                ->upsertOtp((int)$user->id, $newToken, $otpCode, $expiresAt, 'email');
            
            $this->emailService->sendVerificationEmail((int)$user->id, $newToken);
            $this->session->set('pending_verification_at', time());
        }

        // Always return success to prevent enumeration
        $this->session->setFlash('success', $genericMsg);
        $this->response->redirect(url('email/verify-code'));
    }

    /**
     * نمایش فرم فراموشی رمز عبور
     */
    public function showForgotPassword(): void
    {
        $this->view('auth/forgot-password', ['title' => 'فراموشی رمز عبور']);
    }

    /**
     * پردازش فراموشی رمز عبور
     */
    public function forgotPassword(): void
    {
        $email = $this->request->str('email');
        $ip = $this->request->ip();
        $genericMsg = 'در صورت وجود حساب، لینک بازیابی ارسال شد.';

        // CRITICAL-03 Fix: Combined IP + Email rate limiting using non-exception pattern
        $emailKey = hash('sha256', mb_strtolower(trim((string)$email)));
        $rateLimitIp = "forgot_pwd_ip:" . hash('sha256', $ip);
        $rateLimitEmail = "forgot_pwd_email:{$emailKey}";

        if (!$this->rateLimiter->attempt($rateLimitIp, 5, 60, true) || 
            !$this->rateLimiter->attempt($rateLimitEmail, 3, 3600, true)) {
            
            $this->session->setFlash('error', 'تعداد درخواست‌های بازیابی بیش از حد مجاز است. لطفاً بعداً تلاش کنید.');
            $this->response->redirect(url('forgot-password'));
            return;
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            // Anti-enumeration: still show success but don't process
            $this->session->setFlash('success', $genericMsg);
            $this->response->redirect(url('login'));
            return;
        }

        $result = $this->authService->requestPasswordReset($email);
        $this->session->setFlash('success', $genericMsg);
        $this->response->redirect(url('login'));
    }

    /**
     * نمایش فرم تنظیم مجدد رمز عبور
     */
    public function showResetPassword(): void
    {
        $token = str_value($this->request->get('token'));
        if (!$token) {
            // Check if token is already in session (from previous redirect)
            $token = $this->session->get('pw_reset_token');
        }

        if (!$token) {
            $this->response->redirect(url('login'));
            return;
        }

        // CRITICAL-02 Fix: Move token to session and redirect to remove it from URL
        if ($this->request->get('token')) {
            $this->session->set('pw_reset_token', $token);
            $this->response->redirect(url('reset-password'));
            return;
        }

        // HIGH-02 Fix: Validate token existence and expiry before showing the form
        if (!$this->authService->validatePasswordResetToken(str_value($token))) {
            $this->session->remove('pw_reset_token');
            $this->session->setFlash('error', 'لینک بازیابی نامعتبر یا منقضی شده است.');
            $this->response->redirect(url('forgot-password'));
            return;
        }

        // HIGH-06 Fix: Prevent password reset token leakage in Referer header
        $this->response->setHeader('Referrer-Policy', 'no-referrer');
        
        $this->view('auth/reset-password', ['token' => $token]);
    }

    /**
     * پردازش تنظیم مجدد رمز عبور
     */
    public function resetPassword(): void
    {
        $data = $this->request->all();
        // CRITICAL-02 Fix: Use token from session if missing in request
        if (empty($data['token'])) {
            $data['token'] = $this->session->get('pw_reset_token');
        }

        $validator = $this->validatorFactory()->make($data, [
            'token'            => 'required',
            'password'         => 'required|min:8',
            'password_confirm' => 'required|same:password',
        ]);

        if ($validator->fails()) {
            $this->session->setFlash('error', 'رمز عبور معتبر وارد کنید.');
            $redirectUrl = !empty($data['token']) ? url('reset-password') : url('forgot-password');
            $this->response->redirect($redirectUrl);
            return;
        }

        // CRITICAL-01 Fix: Retrieve bound email directly from the database token record to prevent token confusion
        $timeout = int_value(config('auth.password_reset_ttl', 3600));
        $token = str_value($data['token'] ?? '');
        $record = $this->securityModel->findPasswordResetByToken($token, $timeout);
        $boundEmail = $record ? $record->email : null;

        $result = $this->authService->resetPassword($token, $this->request->str('password'), $boundEmail);
        if (!$result['success']) {
            $this->session->setFlash('error', $result['message']);
            $this->response->redirect(url('forgot-password'));
            return;
        }

        // Cleanup password reset session keys
        $this->session->remove('pw_reset_token');
        $this->csrf->regenerate();

        $this->session->setFlash('success', 'رمز عبور با موفقیت تغییر یافت.');
        $this->response->redirect(url('login'));
    }

    /**
     * خروج از سیستم
     */
    public function logout(): void
    {
        // MED-02 Fix: Enforce POST + CSRF for logout
        if (!$this->request->isPost()) {
            $this->response->redirect(url('dashboard'));
            return;
        }
        
        try {
            $this->csrf->validate();
        } catch (\Throwable $e) {
            $this->logger->warning('auth.logout.csrf_failed', [
                'ip' => $this->request->ip(),
                'error' => $e->getMessage()
            ]);
            $this->session->setFlash('error', 'درخواست نامعتبر (CSRF).');
            $this->response->redirect(url('dashboard'));
            return;
        }

        // HIGH-01 Fix: Verify session owner and support logout_all
        $userId = int_value($this->session->get(SessionKeys::USER_ID, 0));
        if ($userId <= 0) {
            $this->response->redirect(url('login'));
            return;
        }

        if ($this->request->post('logout_all') === '1') {
            // HIGH-01 Fix: Invalidate all sessions including Redis keys
            $this->authService->logoutAll($userId);
        } else {
            $this->authService->logout();
        }

        $this->session->setFlash('success', 'با موفقیت خارج شدید.');
        $this->response->redirect(url('login'));
    }
}
