<?php

namespace App\Controllers\Api;

use App\Services\ApiTokenService;
use App\Validators\Requests\IssueTokenRequest;
use Core\RateLimiter;

/**
 * API\TokenController - مدیریت API Token
 *
 * POST /api/v1/auth/token    → دریافت token با credentials
 * POST /api/v1/auth/revoke   → باطل کردن token
 * GET  /api/v1/auth/tokens   → لیست tokenهای فعال (نیاز به auth)
 */
class TokenController extends BaseApiController
{
    private ApiTokenService $service;
    private \Core\RateLimiter $rateLimiter;

    public function __construct(ApiTokenService $service, \Core\RateLimiter $rateLimiter, ?\App\Contracts\LoggerInterface $logger = null) {
        parent::__construct(null, null, null, null, $logger);
        $this->service = $service;
        $this->rateLimiter = $rateLimiter;
    }

    /**
     * دریافت API Token با email/password
     * این endpoint نیاز به middleware auth ندارد
     */
    public function issue(): void
    {
        $data = $this->request->body();

        $formRequest = new IssueTokenRequest($data);
        if (!$formRequest->validate()) {
            $this->validationError($formRequest->errors());
            // unreachable — validationError throws, but for static analysis:
            return;
        }
        $validated = $formRequest->validated();
        $email    = strtolower(trim(str_value($validated['email'] ?? '')));
        $password = str_value($validated['password'] ?? '');

        $key = $this->issueRateLimitKey($email);
        if (!$this->rateLimiter->attempt($key, 8, 10, true)) {
            $this->error('تعداد تلاش بیش از حد مجاز است. چند دقیقه دیگر تلاش کنید', 429, 'RATE_LIMITED');
            return;
        }

        $result = $this->service->issueToken(
            $email,
            $password,
            trim(str_value($data['token_name'] ?? '')),
            trim(str_value($data['scopes'] ?? 'read')),
            trim(str_value($data['otp'] ?? ''))
        );

        if (!$result['success']) {

            if (!empty($result['validation'])) {
                $this->validationError(is_array($result['validation']) ? $result['validation'] : []);
                return;
            }

            $this->error(str_value($result['message']), int_value($result['status'] ?? 400), str_value($result['code'] ?? 'TOKEN_ERROR'));
            return;
        }

        $this->clearIssueRateLimit($email);
        $this->success($result['payload'], 'توکن با موفقیت صادر شد', 201);
    }

    /**
     * نوسازی توکن API (Refresh Token Rotation)
     * این endpoint نیاز به middleware auth ندارد
     */
    public function refresh(): void
    {
        $data = $this->request->body();
        $refreshToken = trim(str_value($data['refresh_token'] ?? ''));

        if ($refreshToken === '') {
            $this->error('توکن نوسازی الزامی است', 400, 'MISSING_REFRESH_TOKEN');
            return;
        }

        $result = $this->service->refreshToken($refreshToken);

        if (!$result['success']) {
            $this->error(str_value($result['message']), int_value($result['status'] ?? 401), str_value($result['code'] ?? 'INVALID_REFRESH_TOKEN'));
            return;
        }

        $this->success($result['payload'], 'توکن با موفقیت نوسازی شد', 200);
    }

    /**
     * باطل کردن token جاری
     */
    public function revoke(): void
    {
        $userId = (int)$this->userId();
        if (!$userId) {
            $this->error('احراز هویت نشده', 401);
            return;
        }

        $authHeader = str_value($this->request->header('Authorization') ?? '');
        $token      = str_starts_with($authHeader, 'Bearer ') ? trim(substr($authHeader, 7)) : null;

        if (!$token) {
            $this->error('توکن یافت نشد', 400);
            return;
        }

        $result = $this->service->revokeTokenByHashForUser($token, $userId);

        if (!$result['success']) {
            $this->error(str_value($result['message']), int_value($result['status'] ?? 404), str_value($result['code'] ?? 'TOKEN_NOT_FOUND'));
            return;
        }

        $this->success(null, 'توکن با موفقیت باطل شد');
    }

    /**
     * لیست tokenهای فعال کاربر
     */
    public function list(): void
    {
        $tokens = $this->service->listTokensForUser((int)$this->userId());
        $this->success($tokens);
    }

    /**
     * باطل کردن یک token خاص
     */
    public function revokeById(): void
    {
        $userId = (int)$this->userId();
        $tokenId = $this->request->int('id');

        if (!$tokenId) {
            $this->error('ID توکن الزامی است', 400);
        }

        $result = $this->service->revokeTokenById($userId, $tokenId);

        if (!$result['success']) {
            $this->error(str_value($result['message']), int_value($result['status'] ?? 404), str_value($result['code'] ?? 'TOKEN_NOT_FOUND'));
            return;
        }

        $this->success(null, 'توکن باطل شد');
    }
	
    private function issueRateLimitKey(string $email): string
    {
        $ip = $this->request->ip();
        return 'api_token_issue_rl_' . hash_hmac('sha256', $ip . '|' . mb_strtolower(str_value($email)), str_value(config('app.key')));
    }

    private function clearIssueRateLimit(string $email): void
    {
        $this->rateLimiter->clear($this->issueRateLimitKey($email));
    }

}
