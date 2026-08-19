<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\ApiToken;
use App\Models\User;
use App\Contracts\LoggerInterface;
use Core\RateLimiter;

/**
 * ApiTokenService - API Token Management
 * 
 * SECURITY NOTES:
 * - Scope isolation: Only admins can create tokens with 'admin' or '*' scopes
 * - All tokens are HMAC-SHA256 hashed before database storage
 * - Rate limiting on token operations prevents abuse
 */
class ApiTokenService
{
    /**
     * Centralized toObject (root-cause normalization for DB results).
     * Guarantees object (never array/mixed) before any ->prop access.
     */
    private function toObject(mixed $data): ?\stdClass
    {
        if ($data === null || $data === false) return null;
        if ($data instanceof \stdClass) return $data;
        if (is_object($data) || is_array($data)) return (object)(array)$data;
        return (object)[$data];
    }


    private ApiToken $apiTokenModel;
    private User $userModel;
    private RateLimiter $rateLimiter;
    private \App\Services\Auth\TwoFactorService $twoFactorService;

    // HIGH-05 Fix: Define critical scopes that require admin privileges
    private const ADMIN_SCOPES = ['admin', '*'];
    private const SCOPE_HIERARCHY = [
        'read' => 1,
        'write' => 2,
        'admin' => 3,
        '*' => 4,
    ];

    private \App\Contracts\LoggerInterface $logger;
    public function __construct(
        \App\Contracts\LoggerInterface $logger,
        ApiToken $apiTokenModel,
        User $userModel,
        RateLimiter $rateLimiter,
        \App\Services\Auth\TwoFactorService $twoFactorService
    ) {        $this->logger = $logger;

                $this->apiTokenModel = $apiTokenModel;
        $this->userModel = $userModel;
        $this->rateLimiter = $rateLimiter;
        $this->twoFactorService = $twoFactorService;
    }

    /** @return array<string, mixed> */
    public function getTokensForAdmin(
        int $page = 1,
        int $perPage = 30,
        ?string $search = null,
        ?string $statusFilter = null
    ): array {
        $page = max(1, $page);
        $perPage = min(max(1, $perPage), 100);
        $offset = ($page - 1) * $perPage;

        $tokens = $this->apiTokenModel->findAllPaginated($perPage, $offset, $search, $statusFilter);
        $total = $this->apiTokenModel->countAll($search, $statusFilter);
        $stats = $this->apiTokenModel->getStats();

        return [
            'tokens' => $tokens,
            'total' => $total,
            'page' => $page,
            'perPage' => $perPage,
            'stats' => $stats,
            'totalPages' => (int) ceil($total / $perPage),
        ];
    }

    public function revokeToken(int $tokenId): bool
    {
        $this->logger->info('api_token.revoked_by_id', ['token_id' => $tokenId]);
        return $this->apiTokenModel->revokeById($tokenId);
    }

    /** @return array<string, mixed> */
    public function revokeTokenByHashForUser(string $token, int $userId): array
    {
        $ok = $this->apiTokenModel->revokeByHashForUser($token, $userId);

        if (!$ok) {
            return ['success' => false, 'message' => 'توکن یافت نشد یا قبلاً باطل شده', 'status' => 404, 'code' => 'TOKEN_NOT_FOUND'];
        }

        $this->logger->info('api_token.revoked_by_hash', [
            'user_id' => $userId
        ]);

        return ['success' => true];
    }

    /** @return array<int, object> */
    public function listTokensForUser(int $userId): array
    {
        return $this->apiTokenModel->findByUserId($userId) ?: [];
    }

    public function getActiveTokenCountForUser(int $userId): int
    {
        return $this->apiTokenModel->countActiveByUserId($userId);
    }

    /**
     * Create a new API token for a user
     * 
     * HIGH-05 Fix: Strict scope isolation - only admins can create tokens with
     * privileged scopes ('admin', '*'). Regular users can only get basic scopes.
     */
    /** @return array<string, mixed> */
    public function createTokenForUser(int $userId, string $name, int $expiresIn, string $scope = 'read'): array
    {
        if ($name === '') {
            return ['success' => false, 'message' => 'نام توکن الزامی است'];
        }

        $activeCount = $this->getActiveTokenCountForUser($userId);
        if ($activeCount >= 10) {
            return [
                'success' => false,
                'message' => 'حداکثر تعداد توکن‌های فعال (10) به حد خود رسیده است',
                'code' => 'TOKEN_LIMIT_REACHED'
            ];
        }

        $token = bin2hex(random_bytes(32));
        $refreshToken = bin2hex(random_bytes(32));
        $expiresAt = $expiresIn > 0
            ? date('Y-m-d H:i:s', (strtotime("+{$expiresIn} days") ?: time()))
            : null;

        $name = trim((string)$name);
        $name = $name === '' ? 'api-token-' . date('Ymd') : mb_substr($name, 0, 80);

        // MEDIUM-M7 Fix: Robust scope validation for multiple scopes
$requestedScopes = array_filter(array_map('trim', explode(',', (string)$scope)));
        $userForScope = $this->toObject($this->userModel->findById($userId));
        if (!$userForScope) {
            return ['success' => false, 'message' => 'کاربر یافت نشد.', 'status' => 404, 'code' => 'USER_NOT_FOUND'];
        }
        if ($this->containsPrivilegedScope($requestedScopes) && (!isset($userForScope->id) || !in_array($userForScope->role ?? '', ['admin', 'super_admin'], true))) {
            return [
                'success' => false,
                'message' => 'کاربر عادی مجاز به ایجاد توکن با دسترسی مدیریتی نیست',
                'code' => 'ADMIN_SCOPE_FORBIDDEN',
            ];
        }
        $finalScopes = $this->validateAndFilterScopes($requestedScopes, $userId);

        if (empty($finalScopes)) {
            $finalScopes = ['read'];
        }

        $scope = implode(',', $finalScopes);

        $this->apiTokenModel->createToken($userId, $token, $name, $scope, $expiresAt, $refreshToken);

        $this->logger->info('api_token.created_for_user', [
            'user_id' => $userId,
            'name' => $name,
            'scope' => $scope
        ]);

        return [
            'success' => true,
            'payload' => [
                'token' => $token,
                'refresh_token' => $refreshToken,
                'name' => $name,
                'scopes' => $scope,
                'expires_at' => $expiresAt,
            ],
        ];
    }

    /** @param list<string> $requestedScopes */
    private function containsPrivilegedScope(array $requestedScopes): bool
    {
        foreach ($requestedScopes as $scope) {
            if (in_array(mb_strtolower(trim((string)$scope)), self::ADMIN_SCOPES, true)) {
                return true;
            }
        }
        return false;
    }

    /**
     * HIGH-05 Fix: Validate and filter scopes based on user role
     * 
     * Only users with admin/super_admin roles can have:
     * - 'admin' scope
     * - '*' (wildcard) scope
     * 
     * Regular users are limited to: read, write
     * 
     * @param list<string> $requestedScopes Scopes requested by the user
     * @param int $userId User ID to check role
     * @return list<string> Filtered scopes that user is allowed to have
     */
    private function validateAndFilterScopes(array $requestedScopes, int $userId): array
    {
        // Get user role
        $user = $this->toObject($this->userModel->findById($userId));
        $isAdmin = $user && in_array($user->role ?? '', ['admin', 'super_admin'], true);
        
        $finalScopes = [];
        foreach ($requestedScopes as $scope) {
            $scope = mb_strtolower(trim((string)$scope));
            
            // Validate scope format (alphanumeric and few special chars only)
            if (!preg_match('/^[a-z0-9_.*-]{1,40}$/', $scope)) {
                continue; // Skip invalid scope formats
            }
            
            // HIGH-05 Fix: Block privileged scopes for non-admins
            if (in_array($scope, self::ADMIN_SCOPES, true) && !$isAdmin) {
                $this->logger->warning('api_token.scope_blocked', [
                    'user_id' => $userId,
                    'scope' => $scope,
                    'reason' => 'non_admin_forbidden'
                ]);
                continue; // Skip this scope, don't add it
            }
            
            // Check for scope hierarchy - deny if trying to get higher privilege than needed
            if (!$isAdmin && isset(self::SCOPE_HIERARCHY[$scope]) && self::SCOPE_HIERARCHY[$scope] >= self::SCOPE_HIERARCHY['admin']) {
                $this->logger->warning('api_token.scope_hierarchy_violation', [
                    'user_id' => $userId,
                    'scope' => $scope,
                    'user_role' => $user->role ?? 'unknown'
                ]);
                continue;
            }
            
            // Only allow known scopes
            if (in_array($scope, ApiToken::ALLOWED_SCOPES, true)) {
                $finalScopes[] = $scope;
            }
        }
        
        return array_unique($finalScopes);
    }

    /** @return array<string, mixed> */
    public function revokeTokenById(int $userId, int $tokenId): array
    {
        $ok = $this->apiTokenModel->revokeForUser($tokenId, $userId);

        if (!$ok) {
            return ['success' => false, 'message' => 'توکن یافت نشد یا قبلاً باطل شده است', 'status' => 404, 'code' => 'TOKEN_NOT_FOUND'];
        }

        $this->logger->info('api_token.revoked_by_user', [
            'user_id' => $userId,
            'token_id' => $tokenId
        ]);

        return ['success' => true];
    }

    /**
     * دریافت IP کلاینت
     */
    private function clientIp(): string
    {
        return function_exists('client_ip') ? client_ip() : 'unknown';
    }

    /**
     * Issue token via credentials (email/password)
     * 
     * HIGH-H-05 Fix: Requires 2FA if user has it enabled
     * HIGH-05 Fix: Strict scope isolation enforced here as well
     */
    /** @return array<string, mixed> */
    public function issueToken(string $email, string $password, string $name, string $scopes, string $otp = ''): array
    {
        // MED-11: Rate limiting check (10 attempts per 60 seconds per IP)
        $ip = $this->clientIp();
        $ipKey = 'token_issue:' . $ip;
        if (!$this->rateLimiter->attempt($ipKey, 10, 1)) {
            return [
                'success' => false,
                'message' => 'تعداد تلاش‌های زیادی برای صدور توکن. لطفاً بعداً تلاش کنید',
                'status' => 429,
                'code' => 'RATE_LIMITED',
            ];
        }

        // HIGH-05 Fix: Per-identifier rate limiting to prevent password spray
        $identifierKey = 'token_issue_id:' . hash('sha256', mb_strtolower((string)$email));
        if (!$this->rateLimiter->attempt($identifierKey, 5, 5)) {
            $this->logger->warning('api_token.issue.throttled_by_id', ['email' => $email, 'ip' => $ip]);
            return [
                'success' => false,
                'message' => 'تعداد تلاش‌های ناموفق بیش از حد مجاز است. لطفاً ۵ دقیقه دیگر تلاش کنید.',
                'status' => 429,
                'code' => 'RATE_LIMITED_IDENTIFIER',
            ];
        }

        if ($email === '' || $password === '') {
            return [
                'success' => false,
                'validation' => [
                    'email' => $email === '' ? 'ایمیل الزامی است' : null,
                    'password' => $password === '' ? 'رمز الزامی است' : null,
                ],
            ];
        }

        $user = $this->toObject($this->userModel->findByEmail($email));
        if (!$user) { return ['success' => false, 'message' => 'ایمیل یا رمز عبور اشتباه است', 'status' => 401, 'code' => 'INVALID_CREDENTIALS']; }
        $passwordValid = verify_user_password($password, $user->password, (int)$user->id);

        if (!$passwordValid) {
            return [
                'success' => false,
                'message' => 'ایمیل یا رمز عبور اشتباه است',
                'status' => 401,
                'code' => 'INVALID_CREDENTIALS',
            ];
        }

        // HIGH-H-05 Fix: Enforce 2FA check for API token issuance
        if (!empty($user->two_factor_enabled)) {
            if (empty($otp)) {
                return [
                    'success' => false,
                    'message' => 'کد 2FA الزامی است',
                    'code' => 'REQUIRES_2FA',
                    'status' => 403
                ];
            }
            if (!$this->twoFactorService->verifyTOTPCode($user->two_factor_secret, $otp, (int)$user->id)) {
                return [
                    'success' => false,
                    'message' => 'کد 2FA نامعتبر است',
                    'code' => 'INVALID_2FA',
                    'status' => 403
                ];
            }
        }

        // HIGH-H-07 Fix: Enforce account status check (locked, banned, suspended)
        if (in_array($user->status, ['locked', 'banned', 'suspended'], true)) {
            return [
                'success' => false,
                'message' => 'حساب کاربری شما غیرفعال یا مسدود شده است',
                'status' => 403,
                'code' => 'ACCOUNT_DISABLED',
            ];
        }

        if ((string)$user->status !== 'active') {
            return [
                'success' => false,
                'message' => 'حساب کاربری فعال نیست',
                'status' => 403,
                'code' => 'ACCOUNT_INACTIVE',
            ];
        }

        // MED-07 Fix: Ensure email is verified before issuing tokens
        if (empty($user->email_verified_at)) {
            return [
                'success' => false,
                'message' => 'ایمیل شما تایید نشده است. لطفاً ابتدا ایمیل خود را تایید کنید.',
                'status' => 403,
                'code' => 'EMAIL_UNVERIFIED',
            ];
        }

        $token = bin2hex(random_bytes(32));
        $refreshToken = bin2hex(random_bytes(32));
        $expiresAt = date('Y-m-d H:i:s', (strtotime('+30 days') ?: time()));

        $name = trim((string)$name);
        if ($name === '') {
            $name = 'api-token-' . date('Ymd');
        }
        $name = mb_substr($name, 0, 80);

$requestedScopes = array_filter(array_map('trim', explode(',', (string)preg_replace('/[^a-z0-9,.:_-]/i', '', trim((string)$scopes)))));
        if ($this->containsPrivilegedScope($requestedScopes) && !in_array($user->role ?? '', ['admin', 'super_admin'], true)) {
            return [
                'success' => false,
                'message' => 'کاربر عادی مجاز به ایجاد توکن با دسترسی مدیریتی نیست',
                'status' => 403,
                'code' => 'ADMIN_SCOPE_FORBIDDEN',
            ];
        }
        $finalScopes = $this->validateAndFilterScopes($requestedScopes, (int)$user->id);

        $finalScopes = array_unique($finalScopes);
        $scopes = !empty($finalScopes) ? implode(',', $finalScopes) : 'read';

        $this->apiTokenModel->createToken($user->id, $token, $name, $scopes, $expiresAt, $refreshToken);

        // Clear rate limit on success
        $this->rateLimiter->clear($identifierKey);

        return [
            'success' => true,
            'payload' => [
                'token' => $token,
                'refresh_token' => $refreshToken,
                'type' => 'Bearer',
                'expires_at' => $expiresAt,
                'name' => $name,
                'scopes' => $scopes,
            ],
        ];
    }

    /**
     * Refresh an API token using Refresh Token Rotation (RTR)
     * One-time use: Revokes the old token pair and issues a new pair.
     */
    /** @return array<string, mixed> */
    public function refreshToken(string $plainRefreshToken): array
    {
        if (empty($plainRefreshToken)) {
            return [
                'success' => false,
                'message' => 'توکن نوسازی ارائه نشده است',
                'status' => 400,
                'code' => 'MISSING_REFRESH_TOKEN',
            ];
        }

        // 🛡️ SECURITY & MOBILE FIX (RTR Grace Period): پنجره ارفاقی ۶۰ ثانیه‌ای
        // جلوگیری از خروج ناخواسته کاربران موبایل (Flutter/React Native) در اثر درخواست‌های هم‌زمان
        $graceKey = 'rtr_grace:' . hash('sha256', $plainRefreshToken);
        if ($cachedPayload = cache()->get($graceKey)) {
            $this->logger->info('api_token.refresh.grace_period_used', ['ip' => $this->clientIp()]);
            return [
                'success' => true,
                'payload' => $cachedPayload,
            ];
        }

        $details = $this->toObject($this->apiTokenModel->findByRefreshToken($plainRefreshToken));
        if (!$details || empty($details->row)) {
            // M-25 FIX: complete refresh-token-reuse detection. A refresh token not found among the
            // ACTIVE tokens might simply be bogus — but it might also be a previously-rotated (now
            // revoked) token being replayed, which is the classic signature of refresh-token theft
            // (the legitimate client already rotated it, so a second presentation outside the grace
            // window means someone else has a copy). Previously both cases returned a bland
            // "invalid" and the live descendant token kept working. Now we look the token up
            // including revoked rows; if it maps to a revoked token we treat it as a breach and
            // revoke the entire token family for that user, forcing re-authentication everywhere.
            $replay = $this->toObject($this->apiTokenModel->findAnyByRefreshToken($plainRefreshToken));
            if ($replay && !empty($replay->row) && (int)($replay->row->revoked ?? 0) === 1) {
                $compromisedUserId = (int)($replay->row->user_id ?? 0);
                $revokedCount = $compromisedUserId > 0
                    ? $this->apiTokenModel->revokeAllForUser($compromisedUserId)
                    : 0;
                $this->logger->warning('api_token.refresh.reuse_detected', [
                    'ip' => $this->clientIp(),
                    'user_id' => $compromisedUserId,
                    'revoked_tokens' => $revokedCount,
                ]);
                return [
                    'success' => false,
                    'message' => 'به دلیل تشخیص استفاده مجدد از توکن نوسازی، همه نشست‌ها باطل شدند. لطفاً دوباره وارد شوید.',
                    'status' => 401,
                    'code' => 'REFRESH_TOKEN_REUSE_DETECTED',
                ];
            }

            $this->logger->warning('api_token.refresh.invalid_or_reused', [
                'ip' => $this->clientIp()
            ]);
            return [
                'success' => false,
                'message' => 'توکن نوسازی نامعتبر یا منقضی شده است',
                'status' => 401,
                'code' => 'INVALID_REFRESH_TOKEN',
            ];
        }

        $row = $details->row;
        $userId = (int)$row->user_id;

        // Verify user status
        $userStatus = (string)($row->user_status ?? '');
        if (in_array($userStatus, ['locked', 'banned', 'suspended'], true)) {
            return [
                'success' => false,
                'message' => 'حساب کاربری شما غیرفعال یا مسدود شده است',
                'status' => 403,
                'code' => 'ACCOUNT_DISABLED',
            ];
        }

        if ($userStatus !== 'active') {
            return [
                'success' => false,
                'message' => 'حساب کاربری فعال نیست',
                'status' => 403,
                'code' => 'ACCOUNT_INACTIVE',
            ];
        }

        // Revoke old token pair (Refresh Token Rotation enforcement)
        $oldTokenId = (int)($row->id ?? 0);
        if ($oldTokenId <= 0) {
            return [
                'success' => false,
                'message' => 'توکن نوسازی نامعتبر است',
                'status' => 401,
                'code' => 'INVALID_REFRESH_TOKEN',
            ];
        }

        // Atomic Compare-And-Swap revocation to prevent race condition (Finding #5)
        $wasRevokedByUs = $this->apiTokenModel->revokeByIdIfActive($oldTokenId);
        if (!$wasRevokedByUs) {
            // Concurrent request raced and rotated this token first! Check grace cache.
            usleep(50000); // 50ms brief pause for winner to populate cache
            if ($cachedPayload = cache()->get($graceKey)) {
                return [
                    'success' => true,
                    'payload' => $cachedPayload,
                ];
            }
            return [
                'success' => false,
                'message' => 'توکن نوسازی قبلاً استفاده شده است',
                'status' => 401,
                'code' => 'REFRESH_TOKEN_ALREADY_USED',
            ];
        }

        $this->logger->info('api_token.rotated', ['old_token_id' => $oldTokenId, 'user_id' => $userId]);

        // Issue new token pair
        $newToken = bin2hex(random_bytes(32));
        $newRefreshToken = bin2hex(random_bytes(32));
        $newExpiresAt = date('Y-m-d H:i:s', (strtotime('+30 days') ?: time()));
        $scopes = strval($row->scopes ?? 'read');
        $name = strval($row->name ?? 'api-token-refreshed');

        $this->apiTokenModel->createToken($userId, $newToken, $name, $scopes, $newExpiresAt, $newRefreshToken);

        $payload = [
            'token' => $newToken,
            'refresh_token' => $newRefreshToken,
            'type' => 'Bearer',
            'expires_at' => $newExpiresAt,
            'name' => $name,
            'scopes' => $scopes,
        ];

        // ذخیره زوج توکن جدید در کش به مدت ۱ دقیقه (Grace Period)
        cache()->put($graceKey, $payload, 1);

        return [
            'success' => true,
            'payload' => $payload,
        ];
    }

    public function revokeAllExpiredTokens(): int
    {
        return $this->apiTokenModel->revokeAllExpired();
    }

    /**
     * @param array<string, mixed> $filters
     * @return array{items: list<\stdClass>, total: int}
     */
    public function searchTokens(string $q, array $filters, int $limit, int $offset): array
    {
        $query = $this->apiTokenModel->query()
            ->select('api_tokens.*', 'u.full_name', 'u.email')
            ->leftJoin('users as u', 'u.id', '=', 'api_tokens.user_id');

        if (!empty($q)) {
            // HIGH-01 Fix: فرآیند پاکسازی کاراکترهای ویژه مانند % و _ جهت ممانعت از اسکن سنگین جداول (DoS)
            $escapedQ = addcslashes(trim((string)$q), '%_');
            $like = "%{$escapedQ}%";
            
            $query->where(function($sub) use ($like, $q) {
                $sub->where('api_tokens.name', 'LIKE', $like)
                    ->orWhere('api_tokens.token', '=', $q)
                    ->orWhere('u.email', 'LIKE', $like);
            });
        }

        // HIGH-01 & HIGH-02: اعتبارسنجی status و escape امن
        $allowedStatuses = ['active', 'revoked', 'expired'];
        if (!empty($filters['status']) && in_array($filters['status'], $allowedStatuses, true)) {
            $query->where('api_tokens.status', '=', $filters['status']);
        }

        return [
            'total' => $query->count(),
            'items' => (clone $query)->orderBy('api_tokens.created_at', 'DESC')
                                     ->limit($limit)->offset($offset)->get()
        ];
    }
}