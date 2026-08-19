<?php

declare(strict_types=1);

namespace App\Middleware;

use Core\Request;
use Core\Response;
use Core\Database;
use Core\RateLimiter;
use Closure;

/**
 * ApiAuthMiddleware — احراز هویت و کنترل دسترسی API
 * 
 * SECURITY: All tokens are hashed using HMAC-SHA256 before database lookup
 * to prevent token leakage in case of SQL injection or database compromise.
 */
class ApiAuthMiddleware extends BaseMiddleware
{
    private Database $db;
    private RateLimiter $rateLimiter;
    private \App\Contracts\LoggerInterface $logger;
    private ?bool $secretVersionExpirySupported = null;

    public function __construct(Database $db, RateLimiter $rateLimiter, \App\Contracts\LoggerInterface $logger) {
        $this->db = $db;
        $this->rateLimiter = $rateLimiter;
        $this->logger = $logger;
    }

    public function handle(Request $request, Closure $next, string ...$requiredScopes): mixed
    {
        // CRIT-02 Fix: Extract token BEFORE any other operations
        // This ensures the raw token is never exposed in logs, error messages, or memory dumps
        $rawToken = $this->extractRawToken($request);

        if (!$rawToken) {
            $this->errorResponse('توکن API ارائه نشده', 401, 'MISSING_TOKEN');
            return null;
        }

        // Validate token using all active secrets in config
        $user = $this->validateTokenWithRotation($rawToken, 0);
        if (!$user) {
            $this->errorResponse('توکن نامعتبر یا منقضی شده', 401, 'INVALID_TOKEN');
            return null;
        }

        // HIGH-08 Fix: Extract user_id from the validated token, not from the request body/query
        $requestingUserId = (int)$user->id;
        
        // HIGH-05 Fix: Enforce ownership check for sensitive paths
        if ($this->isSensitivePath($request->uri())) {
            $requestedUserId = int_value($request->get('user_id', 0));
            if ($requestedUserId > 0 && $requestedUserId !== $requestingUserId) {
                $this->errorResponse('دسترسی غیرمجاز: ناهماهنگی در شناسه کاربری', 403, 'OWNERSHIP_VIOLATION');
                return null;
            }
        }

        $allowedStatuses = ['active', '1', 1];
        if (!in_array($user->status, $allowedStatuses, true)) {
            $this->errorResponse('حساب کاربری غیرفعال است', 403, 'ACCOUNT_DISABLED');
            return null;
        }

        // CRITICAL-NEW-03 Fix: Prevent API Token Scope Privilege Escalation
        $tokenScopes = array_filter(explode(',', (string)($user->scopes ?? '')));
        $hasAdminOrWildcardScope = in_array('admin', $tokenScopes, true) || in_array('*', $tokenScopes, true);

        if ($hasAdminOrWildcardScope) {
            $currentUser = $this->db->fetch('SELECT role FROM users WHERE id = ? LIMIT 1', [(int)$user->id]);
            $isUserAdmin = $currentUser && in_array($currentUser->role, ['admin', 'super_admin'], true);

            if (!$isUserAdmin) {
                try {
                    $this->db->query('UPDATE api_tokens SET revoked = 1, revoked_at = NOW() WHERE id = ?', [(int)$user->token_id]);
                } catch (\Throwable $e) {
                    $this->logger->error('api_auth.revoke_failed_on_role_change', ['token_id' => $user->token_id, 'error' => $e->getMessage()]);
                }

                $this->errorResponse('توکن نامعتبر است: دسترسی مدیر لغو شده است.', 403, 'PRIVILEGE_ESCALATION_PREVENTED');
                return null;
            }
        }

        // ✅ بررسی اسکوپ‌های مورد نیاز (Scope Enforcement with hierarchy/inheritance)
        if (!empty($requiredScopes)) {
            foreach ($requiredScopes as $scope) {
                if (!$this->hasScope($tokenScopes, $scope)) {
                    $this->errorResponse('توکن شما اجازه دسترسی به این بخش را ندارد (اسکوپ مورد نیاز: ' . $scope . ')', 403, 'INSUFFICIENT_SCOPE');
                    return null;
                }
            }
        }

        // Rate Limiting
        $rateLimitResult = $this->checkRateLimit($user->id);
        if (!$rateLimitResult['allowed']) {
            return $this->rateLimitResponse($rateLimitResult);
        }

        $request->setUser($user);

        // بروزرسانی آمار استفاده از توکن
        // MEDIUM-01 Fix: Add error handling for stats update
        try {
            $this->db->query(
                "UPDATE api_tokens SET last_used_at = NOW(), use_count = use_count + 1 WHERE id = ?",
                [(int)$user->token_id]
            );
        } catch (\Throwable $e) {
            $this->logger->warning('api_auth.stats_update_failed', [
                'token_id' => $user->token_id,
                'error' => $e->getMessage()
            ]);
        }

        $response = $this->toResponse($next($request));
        
        // MED-04 Fix: Add token expiry header for client-side proactive management
        if (!empty($user->expires_at)) {
            $response->setHeader('X-Token-Expires-At', (string)$user->expires_at);
        }
        
        return $response;
    }
    
    /**
     * @return array<string, mixed>
     */
    private function checkRateLimit(int $userId): array
    {
        $key = 'api:user:' . $userId;
        $maxAttempts = int_value(config('rate_limits.api.authenticated.max_attempts', 200));
        $decayMinutes = int_value(config('rate_limits.api.authenticated.decay_minutes', 1));

        if (!$this->rateLimiter->attempt($key, $maxAttempts, $decayMinutes)) {
            return [
                'allowed' => false,
                'retry_after' => $this->rateLimiter->availableIn($key),
                'limit' => $maxAttempts,
            ];
        }
        
        return ['allowed' => true];
    }
    
    /**
     * @param array<string, mixed> $result
     */
    private function rateLimitResponse(array $result): Response
    {
        $response = new Response();
        $response->setHeader('Retry-After', str_value($result['retry_after'] ?? 60));
        $response->setHeader('X-RateLimit-Limit', str_value($result['limit'] ?? 200));
        $response->setHeader('X-RateLimit-Remaining', '0');
        $response->json([
            'success' => false,
            'message' => 'تعداد درخواست‌های شما بیش از حد مجاز است.',
            'error'   => 'RATE_LIMIT_EXCEEDED',
            'retry_after' => $result['retry_after'] ?? 60,
        ], 429);
        return $response;
    }

    /**
     * CRIT-02 Fix: Extract raw token securely
     * 
     * @param Request $request
     * @return string|null Raw token, or null if invalid
     */
    private function extractRawToken(Request $request): ?string
    {
        $authHeader = $request->header('Authorization') 
            ?? $request->header('authorization');

        if (!is_string($authHeader) || !preg_match('/Bearer\s+(.+)/i', $authHeader, $m)) {
            return null;
        }

        $token = trim($m[1]);
        
        // Validate format first (fast rejection for malformed tokens)
        // Token must be exactly 64 hex characters (32 bytes = 256 bits)
        if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
            $this->logger->warning('api_auth.invalid_token_format', [
                'header_length' => strlen($authHeader),
                'token_length' => strlen((string)$token)
            ]);
            return null;
        }

        return $token;
    }

    /**
     * Validate token using multi-version secret rotation
     * 
     * @param string $rawToken Raw token extracted from Request
     * @param int $requestingUserId Optional user ID for ownership verification
     * @return \stdClass|null User object if valid, null otherwise
     */
    private function validateTokenWithRotation(string $rawToken, int $requestingUserId = 0): ?\stdClass
    {
        $secretsValue = config('security.api.secrets', []);
        if (!is_array($secretsValue)) throw new \UnexpectedValueException('security.api.secrets must be an array.');
        $secrets = $secretsValue;
        if (empty($secrets)) {
            // Fallback to legacy constant
            $legacySecret = \defined('SECURITY_API_TOKEN_SECRET') ? SECURITY_API_TOKEN_SECRET : null;
            if ($legacySecret) {
                $secrets = ['v2' => $legacySecret];
            }
        }

        // Sort secrets so the current/newest version is tried first
        $currentVersionValue = config('security.api.current_secret_version', 'v2');
        if (!is_string($currentVersionValue) || $currentVersionValue === '') throw new \UnexpectedValueException('API secret version must be a string.');
        $currentVersion = $currentVersionValue;
        
        // Build ordered secrets list
        $orderedSecrets = [];
        if (isset($secrets[$currentVersion])) {
            $orderedSecrets[$currentVersion] = $secrets[$currentVersion];
        }
        foreach ((array)$secrets as $version => $secret) {
            if ($version !== $currentVersion) {
                $orderedSecrets[$version] = $secret;
            }
        }

        foreach ((array)$orderedSecrets as $version => $secret) {
            if (empty($secret) || strlen((string)$secret) < 32) {
                continue;
            }

            // Hash the token using this secret version
            $hashedToken = hash_hmac('sha256', $rawToken, $secret);

            // Validate against DB with this hash and matching version
            $user = $this->validateTokenByHashAndVersion($hashedToken, $version, $requestingUserId);
            if ($user) {
                // Rotate/migrate token if it was hashed with an older secret version
                if ($version !== $currentVersion && isset($orderedSecrets[$currentVersion])) {
                    $newHashedToken = hash_hmac('sha256', $rawToken, $orderedSecrets[$currentVersion]);
                    try {
                        $this->db->query(
                            "UPDATE api_tokens SET token = ?, secret_version = ? WHERE id = ?",
                            [$newHashedToken, $currentVersion, (int)$user->token_id]
                        );
                        $this->logger->info('api_auth.token_rotated', [
                            'token_id' => $user->token_id,
                            'old_version' => $version,
                            'new_version' => $currentVersion
                        ]);
                    } catch (\Throwable $e) {
                        $this->logger->error('api_auth.token_rotation_failed', [
                            'token_id' => $user->token_id,
                            'error' => $e->getMessage()
                        ]);
                    }
                }
                // Found a valid match! Clean up raw token immediately
                unset($rawToken);
                return $user;
            }
        }

        unset($rawToken);
        return null;
    }

    /**
     * Check if token scopes satisfy a required scope, taking into account hierarchy/inheritance.
     */
    /**
     * @param list<string> $tokenScopes
     */
    private function hasScope(array $tokenScopes, string $requiredScope): bool
    {
        if (in_array('*', $tokenScopes, true)) {
            return true;
        }

        if (in_array($requiredScope, $tokenScopes, true)) {
            return true;
        }

        // 🛡️ Global Scope Hierarchy Fix (Issue #7):
        // Global 'read' scope covers any *.read scope
        // Global 'write' scope covers any *.write or *.read scope
        if (in_array('read', $tokenScopes, true) && str_ends_with($requiredScope, '.read')) {
            return true;
        }
        if (in_array('write', $tokenScopes, true) && (str_ends_with($requiredScope, '.write') || str_ends_with($requiredScope, '.read'))) {
            return true;
        }

        // Colon vs dot compatibility (e.g. 'wallet:read' satisfies 'wallet.read')
        $colonScope = str_replace('.', ':', $requiredScope);
        $dotScope   = str_replace(':', '.', $requiredScope);
        if (in_array($colonScope, $tokenScopes, true) || in_array($dotScope, $tokenScopes, true)) {
            return true;
        }

        // Entity hierarchy (e.g. user.write inherits user.read, or user.*)
        if (str_ends_with($requiredScope, '.read')) {
            $base = substr($requiredScope, 0, -5);
            if (in_array($base . '.write', $tokenScopes, true) || in_array($base . ':write', $tokenScopes, true) || in_array($base . '.*', $tokenScopes, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Validate token with specific hash and secret version
     */
    private function validateTokenByHashAndVersion(string $hashedToken, string $version, int $requestingUserId = 0): ?\stdClass
    {
        // Verify token hash is properly formatted (additional safety check)
        if (!preg_match('/^[a-f0-9]{64}$/', $hashedToken)) {
            $this->logger->warning('api_auth.invalid_hashed_token_format');
            return null;
        }
        
        // MEDIUM-01 Fix: Check negative cache in Redis before DB query to prevent denial of service (DoS) from invalid token floods
        $redis = app(\Core\Redis::class);
        $redisAvailable = $redis && $redis->isAvailable();
        if ($redisAvailable) {
            try {
                if ($redis->get("token_revoked:{$hashedToken}")) {
                    return null;
                }
            } catch (\Throwable $e) {
            // intentional: optional auth operation — continues with degraded mode
            @error_log('[ApiAuthMiddleware] optional op failed: ' . $e->getMessage());
        }
        }

        // ✅ Use the pre-hashed token and secret version directly in the query
        $query = "SELECT u.*, at.id AS token_id, at.scopes
                  FROM api_tokens at
                  JOIN users u ON u.id = at.user_id
                  WHERE at.token = ? 
                    AND at.secret_version = ?
                    AND (at.expires_at IS NULL OR at.expires_at > NOW()) 
                    AND at.revoked = 0";
        
        $params = [$hashedToken, $version];
        
        if ($requestingUserId > 0) {
            $query .= " AND at.user_id = ?";
            $params[] = $requestingUserId;
        }
        
        if ($this->isSecretVersionExpirySupported()) {
            $query .= " AND (at.secret_version_expires_at IS NULL OR at.secret_version_expires_at > NOW())";
        }

        $query .= " LIMIT 1";
        
        // Use prepared statements to prevent SQL injection
        $result = $this->db->fetch($query, $params) ?: null;

        // Populate negative cache if token is invalid or revoked to save DB resources
        if ($result === null && $redisAvailable) {
            try {
                $redis->set("token_revoked:{$hashedToken}", "1", 3600); // negative cache for 1 hour
            } catch (\Throwable $e) {
            // intentional: optional auth operation — continues with degraded mode
            @error_log('[ApiAuthMiddleware] optional op failed: ' . $e->getMessage());
        }
        }

        return $result;
    }

    private function isSecretVersionExpirySupported(): bool
    {
        if ($this->secretVersionExpirySupported !== null) {
            return $this->secretVersionExpirySupported;
        }

        try {
            $row = $this->db->fetch("SHOW COLUMNS FROM api_tokens LIKE 'secret_version_expires_at'");
            $this->secretVersionExpirySupported = (bool)$row;
        } catch (\Throwable $e) {
            $this->secretVersionExpirySupported = false;
        }

        return $this->secretVersionExpirySupported;
    }

    /**
     * بررسی آیا مسیر جاری حساس است و نیاز به مالکیت مستقیم دارد
     * 
     * Fix M2: مسیرهای حساس مانند پرداخت، برداشت و تغییر حساب
     * باید مالکیت توکن بر اساس user_id اجباری باشد
     */
    private function isSensitivePath(string $uri): bool
    {
        // HIGH-H4 Fix: Expanded and configurable list of sensitive paths requiring strict ownership
        $sensitivePaths = config('security.api.sensitive_paths', [
            '/api/payment',
            '/api/withdrawal',
            '/api/wallet',
            '/api/account',
            '/api/profile/update',
            '/api/admin',
            '/api/security',
            '/api/2fa',
            '/api/token',
        ]);

        foreach ((array)$sensitivePaths as $path) {
            if (str_starts_with($uri, str_value($path))) {
                return true;
            }
        }

        return false;
    }

    private function errorResponse(string $message, int $code, string $errorType): void
    {
        $response = new Response();
        $response->json([
            'success' => false,
            'message' => $message,
            'error'   => $errorType,
        ], $code);
    }

    protected function toResponse(mixed $result): Response
    {
        if ($result instanceof Response) {
            return $result;
        }
        $response = new Response();
        $response->setContent(str_value($result));
        return $response;
    }
}