<?php

namespace App\Controllers\User;

use App\Controllers\User\BaseUserController;
use App\Services\ApiTokenService;

class ApiTokenController extends BaseUserController
{
    private ApiTokenService $apiTokenService;

    public function __construct(
        ApiTokenService $apiTokenService,
        \Core\Session $session,
        \Core\Request $request,
        \Core\Response $response,
        \App\Services\Shared\PolicyService $policyService,
        \App\Contracts\LoggerInterface $logger,
        \App\Services\Auth\AuthService $authService,
        \App\Services\User\UserService $userService,
        \App\Services\CaptchaService $captchaService
    ) {
        parent::__construct($session, $request, $response, $policyService, $logger, $authService, $userService, $captchaService);
        $this->apiTokenService = $apiTokenService;
    }

    /** لیست توکن‌های کاربر */
    public function index(): void
    {
        $this->requireAuth();

        $userId = (int)$this->userId();
        $tokens = $this->apiTokenService->listTokensForUser($userId);
        $newToken = $this->session->getFlash('new_api_token');

        $this->view('user.api-tokens.index', [
            'title'    => 'توکن‌های API',
            'tokens'   => $tokens,
            'newToken' => $newToken,
        ]);
    }

    /** ساخت توکن جدید */
    public function create(): void
    {
        $this->requireAuth();
        $this->validateCsrf();

        $userId = (int)$this->userId();
        $name      = trim(str_value($this->request->post('name') ?? ''));
        $expiresIn = $this->request->int('expires_in', 30);

        if ($name === '') {
            $this->redirectWithError('نام توکن الزامی است', '/api-tokens');
            return;
        }

        $count = $this->apiTokenService->getActiveTokenCountForUser($userId);
        if ($count >= 10) {
            $this->redirectWithError('حداکثر ۱۰ توکن فعال مجاز است. ابتدا یکی را باطل کنید.', '/api-tokens');
            return;
        }

        $scope = trim(str_value($this->request->post('scope') ?? 'read'));
        $result = $this->apiTokenService->createTokenForUser($userId, $name, $expiresIn, $scope);
        if (!$result['success']) {
            $this->redirectWithError(str_value($result['message'] ?? 'خطا در ایجاد توکن'), '/api-tokens');
            return;
        }

        $payload = is_array($result['payload'] ?? null) ? $result['payload'] : [];
        $this->session->setFlash('new_api_token', str_value($payload['token'] ?? '')); 
        $this->redirectWithSuccess('توکن با موفقیت ساخته شد', '/api-tokens');
    }

    /** باطل کردن توکن */
    public function revoke(): void
    {
        $this->requireAuth();
        $this->validateCsrf();

        $userId = (int)$this->userId();
        $id = int_value($this->request->param('id') ?? $this->request->get('id')); 

        $result = $this->apiTokenService->revokeTokenById($userId, $id);
        if ($result['success']) {
            $this->jsonSuccess('توکن باطل شد');
            return;
        }

        $this->jsonError(str_value($result['message'] ?? 'توکن یافت نشد'), [], int_value($result['status'] ?? 404));
    }
}
