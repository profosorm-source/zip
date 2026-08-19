<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\Auth\OAuthService;
use App\Validators\Requests\OAuthCallbackRequest;
use App\Validators\Requests\OAuthLinkAccountRequest;
use Core\Request;
use Core\Response;
use App\Constants\SessionKeys;

/**
 * OAuthController — Social Login (Google + Facebook)
 */
class OAuthController extends BaseController
{
    private OAuthService $oauthService;
    private \App\Services\Auth\AuthService $authService;
    private \App\Models\User $userModel;

    public function __construct(
        OAuthService $oauthService,
        \App\Services\Auth\AuthService $authService,
        \App\Models\User $userModel,
        ?\App\Contracts\LoggerInterface $logger = null
    ) {
        parent::__construct(null, null, null, null, $logger);
        $this->oauthService = $oauthService;
        $this->authService = $authService;
        $this->userModel = $userModel;
    }

    /**
     * هدایت کاربر به صفحه لاگین گوگل
     */
    public function loginGoogle(): void
    {
        $url = $this->oauthService->getGoogleAuthUrl();
        $this->response->redirectExternal($url);
    }

    /**
     * هدایت کاربر به صفحه لاگین فیسبوک
     */
    public function loginFacebook(): void
    {
        $url = $this->oauthService->getFacebookAuthUrl();
        $this->response->redirectExternal($url);
    }

    /**
     * هندلر بازگشت از گوگل
     */
    public function callbackGoogle(): void
    {
        $callbackRequest = new OAuthCallbackRequest([
            'code'  => $this->request->str('code'),
            'state' => $this->request->str('state'),
        ]);
        if (!$callbackRequest->validate()) {
            $this->jsonError('پارامترهای بازگشتی نامعتبر است', [], 400);
            return;
        }
        $validated = $callbackRequest->validated();
        $code  = str_value($validated['code'] ?? '');
        $state = str_value($validated['state'] ?? '');

        $result = $this->oauthService->handleGoogleCallback($code, $state);

        if ($result['success']) {
            $this->session->regenerate(true);
            $this->csrf->regenerate();
            // 🛡️ Security Hardening: Handling 2FA checkpoints for social logins
            if (!empty($result['requires_2fa'])) {
                $pendingUser = $result['user'] ?? null;
                $pendingUserId = int_value($result['user_id'] ?? (is_object($pendingUser) ? ($pendingUser->id ?? 0) : 0));
                $this->session->set(SessionKeys::PENDING_2FA_USER_ID, $pendingUserId);
                if ($this->request->isAjax()) {
                    $this->jsonSuccess('', ['redirect' => url('verify-2fa')]);
                    return;
                }
                $this->response->redirect(url('verify-2fa'));
                return;
            }

            $message = ($result['is_new'] ?? false) 
                ? 'خوش آمدید! حساب کاربری جدید شما ساخته شد.'
                : 'خوش آمدید!';

            if ($this->request->isAjax()) {
                $this->jsonSuccess($message, ['redirect' => url('dashboard')]);
                return;
            }
            $this->session->setFlash('success', $message);
            $this->response->redirect(url('dashboard'));
            return;
        }

        if (!empty($result['requires_password_confirmation'])) {
            if ($this->request->isAjax()) {
                $this->jsonSuccess(str_value($result['message'] ?? ''), ['redirect' => url('auth/oauth-confirm')]);
                return;
            }
            $this->session->setFlash('warning', $result['message'] ?? '');
            $this->response->redirect(url('auth/oauth-confirm'));
            return;
        }

        if ($this->request->isAjax()) {
            $this->jsonError(str_value($result['message'] ?? 'خطا در لاگین با گوگل'));
            return;
        }
        $this->session->setFlash('error', $result['message'] ?? 'خطا در لاگین با گوگل');
        $this->response->redirect(url('login'));
        return;
    }

    /**
     * هندلر بازگشت از فیسبوک
     */
    public function callbackFacebook(): void
    {
        $callbackRequest = new OAuthCallbackRequest([
            'code'  => $this->request->str('code'),
            'state' => $this->request->str('state'),
        ]);
        if (!$callbackRequest->validate()) {
            $this->jsonError('پارامترهای بازگشتی نامعتبر است', [], 400);
            return;
        }
        $validated = $callbackRequest->validated();
        $code  = str_value($validated['code'] ?? '');
        $state = str_value($validated['state'] ?? '');

        $result = $this->oauthService->handleFacebookCallback($code, $state);

        if ($result['success']) {
            $this->session->regenerate(true);
            $this->csrf->regenerate();
            // 🛡️ Security Hardening: Handling 2FA checkpoints for social logins
            if (!empty($result['requires_2fa'])) {
                $pendingUser = $result['user'] ?? null;
                $pendingUserId = int_value($result['user_id'] ?? (is_object($pendingUser) ? ($pendingUser->id ?? 0) : 0));
                $this->session->set(SessionKeys::PENDING_2FA_USER_ID, $pendingUserId);
                if ($this->request->isAjax()) {
                    $this->jsonSuccess('', ['redirect' => url('verify-2fa')]);
                    return;
                }
                $this->response->redirect(url('verify-2fa'));
                return;
            }

            $message = ($result['is_new'] ?? false) 
                ? 'خوش آمدید! حساب کاربری جدید شما ساخته شد.'
                : 'خوش آمدید!';

            if ($this->request->isAjax()) {
                $this->jsonSuccess($message, ['redirect' => url('dashboard')]);
                return;
            }
            $this->session->setFlash('success', $message);
            $this->response->redirect(url('dashboard'));
            return;
        }

        if (!empty($result['requires_password_confirmation'])) {
            if ($this->request->isAjax()) {
                $this->jsonSuccess(str_value($result['message'] ?? ''), ['redirect' => url('auth/oauth-confirm')]);
                return;
            }
            $this->session->setFlash('warning', $result['message'] ?? '');
            $this->response->redirect(url('auth/oauth-confirm'));
            return;
        }

        if ($this->request->isAjax()) {
            $this->jsonError(str_value($result['message'] ?? 'خطا در لاگین با فیسبوک'));
            return;
        }
        $this->session->setFlash('error', $result['message'] ?? 'خطا در لاگین با فیسبوک');
        $this->response->redirect(url('login'));
        return;
    }

    /**
     * لیست حساب‌های اجتماعی متصل
     */
    public function listAccounts(): void
    {
        $this->requireAuth();
        $this->requirePermission('user.manage_social_accounts');

        $userId = (int)$this->userId();
        $accounts = $this->oauthService->getLinkedAccounts($userId);
        
        $this->jsonSuccess('', ['accounts' => $accounts]);
    }

    /**
     * اتصال حساب جدید
     */
    public function linkAccount(): void
    {
        $this->requireAuth();
        $this->requirePermission('user.manage_social_accounts');

        $provider = $this->request->str('provider');

        if (empty($provider)) {
            $this->jsonError('انتخاب سرویس‌دهنده الزامی است');
            return;
        }

        // CRIT-05 Fix: Redirect to OAuth flow instead of accepting user_data directly
        $url = $this->oauthService->getAuthUrlForLinking($provider, (int)$this->userId());
        
        if ($this->request->isAjax()) {
            $this->jsonSuccess('Redirecting to ' . $provider, ['redirect' => $url]);
            return;
        }
        
        $this->response->redirect($url);
    }

    /**
     * قطع اتصال حساب
     */
    public function unlinkAccount(): void
    {
        $this->requireAuth();
        $this->requirePermission('user.manage_social_accounts');

        $provider = $this->request->str('provider');
        if (empty($provider)) {
            $this->jsonError('انتخاب سرویس‌دهنده الزامی است');
            return;
        }

        $userId = (int)$this->userId();
        // بررسی محدودیت‌های حذف (اختیاری در اینجا، منطق در سرویس است)
        $result = $this->oauthService->unlinkSocialAccount($userId, $provider);

        if ($result['success']) {
            $this->jsonSuccess(str_value($result['message'] ?? 'اتصال حساب قطع شد'));
            return;
        }
        $this->jsonError(str_value($result['message'] ?? 'خطا در قطع اتصال'));
    }

    /**
     * نمایش صفحه تأیید رمز عبور برای اتصال OAuth
     */
    public function showConfirmPassword(): void
    {
        $pendingValue = $this->session->get('oauth_pending_link');
        $pending = is_array($pendingValue) ? $pendingValue : [];
        $createdAt = int_value($pending['created_at'] ?? 0);
        if (!$pending || empty($pending['email']) || empty($pending['provider']) || $createdAt <= 0 || (time() - $createdAt) > 600) {
            $this->session->remove('oauth_pending_link');
            $this->response->redirect(url('login'));
            return;
        }

        $this->view('auth/oauth-confirm', [
            'title'    => 'تأیید رمز عبور برای اتصال حساب',
            'email'    => $pending['email'],
            'provider' => $pending['provider']
        ]);
    }

    /**
     * پردازش تأیید رمز عبور و اتصال OAuth
     */
    public function confirmPassword(): void
    {
        $pendingValue = $this->session->get('oauth_pending_link');
        $pending = is_array($pendingValue) ? $pendingValue : [];
        $createdAt = int_value($pending['created_at'] ?? 0);
        if (!$pending || empty($pending['email']) || empty($pending['provider']) || empty($pending['data']) || $createdAt <= 0 || (time() - $createdAt) > 600) {
            $this->session->remove('oauth_pending_link');
            if ($this->request->isAjax()) {
                $this->jsonError('نشست تأیید منقضی یا نامعتبر است');
                return;
            }
            $this->session->setFlash('error', 'نشست تأیید منقضی یا نامعتبر است');
            $this->response->redirect(url('login'));
            return;
        }

        $password = $this->request->str('password');
        if (empty($password)) {
            if ($this->request->isAjax()) {
                $this->jsonError('وارد کردن رمز عبور الزامی است');
                return;
            }
            $this->session->setFlash('error', 'وارد کردن رمز عبور الزامی است');
            $this->response->redirect(url('auth/oauth-confirm'));
            return;
        }

        // Verify password using AuthService
        $authService = $this->authService;
        $userModel = $this->userModel;
        $user = $userModel->findByEmail(str_value($pending['email']));

        if (!$user || !$authService->verifyPassword($password, $user->password)) {
            if ($this->request->isAjax()) {
                $this->jsonError('رمز عبور وارد شده اشتباه است');
                return;
            }
            $this->session->setFlash('error', 'رمز عبور وارد شده اشتباه است');
            $this->response->redirect(url('auth/oauth-confirm'));
            return;
        }

        // Confirm user status before logging in
        if (in_array($user->status, ['locked', 'banned', 'suspended', 'locked_2fa'], true)) {
            $this->session->remove('oauth_pending_link');
            $msg = 'حساب کاربری شما مسدود، قفل یا غیرفعال شده است.';
            if ($this->request->isAjax()) {
                $this->jsonError($msg);
                return;
            }
            $this->session->setFlash('error', $msg);
            $this->response->redirect(url('login'));
            return;
        }

        // Link the social account
        $linkResult = $this->oauthService->linkSocialAccountSafe((int)$user->id, str_value($pending['provider'] ?? ''), is_array($pending['data'] ?? null) ? $pending['data'] : []);
        if (!$linkResult['success']) {
            if ($this->request->isAjax()) {
                $this->jsonError(str_value($linkResult['message']));
                return;
            }
            $this->session->setFlash('error', $linkResult['message']);
            $this->response->redirect(url('auth/oauth-confirm'));
            return;
        }

        // Clean up pending session variable
        $this->session->remove('oauth_pending_link');

        // Login the user via direct login method (handles sessions, events and 2FA perfectly)
        $loginResult = $authService->loginDirectly($user);
        if (!$loginResult['success']) {
            $msg = str_value($loginResult['message'] ?? 'خطا در ورود به حساب کاربری');
            if ($this->request->isAjax()) {
                $this->jsonError($msg);
                return;
            }
            $this->session->setFlash('error', $msg);
            $this->response->redirect(url('login'));
            return;
        }

        $requires2FA = !empty($loginResult['requires_2fa']);
        $redirectUrl = $requires2FA ? url('verify-2fa') : url('dashboard');
        
        if ($this->request->isAjax()) {
            $this->jsonSuccess('حساب کاربری متصل و ورود موفقیت‌آمیز بود.', ['redirect' => $redirectUrl]);
            return;
        }

        $this->session->setFlash('success', 'حساب کاربری متصل و ورود موفقیت‌آمیز بود.');
        $this->response->redirect($redirectUrl);
    }
}
