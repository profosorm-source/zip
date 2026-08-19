<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Models\User;
use App\Models\SecurityModel;
use Core\Session;
use Core\Redis;
use App\Services\AuditTrail;
use App\Constants\SessionKeys;
use App\Contracts\LoggerInterface;
use Core\RateLimiter;

class AuthSessionManager
{
    private Session $session;
    private Redis $redis;
    private SessionService $sessionService;
    private AuditTrail $auditTrail;
    private SecurityModel $securityModel;
    private User $userModel;
    private LoggerInterface $logger;
    private RateLimiter $rateLimiter;
    public function __construct(
        Session $session,
        Redis $redis,
        SessionService $sessionService,
        AuditTrail $auditTrail,
        SecurityModel $securityModel,
        User $userModel,
        LoggerInterface $logger,
        RateLimiter $rateLimiter
    ) {
        $this->session = $session;
        $this->redis = $redis;
        $this->sessionService = $sessionService;
        $this->auditTrail = $auditTrail;
        $this->securityModel = $securityModel;
        $this->userModel = $userModel;
        $this->logger = $logger;
        $this->rateLimiter = $rateLimiter;
    }

    /**
     * ROOT-CAUSE HELPER (principled)
     */
    private function toObject(mixed $data): ?\stdClass
    {
        if ($data === null || $data === false) {
            return null;
        }
        if ($data instanceof \stdClass) {
            return $data;
        }
        if (is_object($data) || is_array($data)) {
            return (object)(array)$data;
        }
        return (object)[$data];
    }

    public function createPending2FASession(\stdClass $user, bool $remember = false): void
    {
        $this->session->regenerate(true);
        $this->session->set(SessionKeys::PENDING_2FA_USER_ID, (int)$user->id);
        $this->session->set('pending_2fa_created_at', time());
        $this->session->set('pending_2fa_ip', client_ip());
        // H-03 Fix: preserve the "remember me" intent across the pending 2FA step so the
        // persistent token can be issued ONLY after the second factor is verified.
        $this->session->set('pending_2fa_remember', $remember);
    }

    public function createSession(\stdClass $user, bool $remember = false, bool $twoFactorVerified = false): void
    {
        $this->session->regenerate(true);
        
        $this->session->set(SessionKeys::USER_ID, (int)$user->id);
        $this->session->set(SessionKeys::LOGGED_IN, true);
        $this->session->set(SessionKeys::USER_ROLE, (string)($user->role ?? 'user'));
        $this->session->set('last_activity', (string)time());
        $this->session->set('login_ip', client_ip());
        $this->session->set('login_time', time());
        $this->session->set('user_verify_time', time());
        $this->session->set('last_auth_time', time());

        // H-03 Fix: publish the step-up state consumed by AdvancedFraudMiddleware. Only a
        // verified second factor marks the session as 2FA-verified; a password-only (or
        // remember-cookie) session for a non-2FA account never claims to be 2FA-verified.
        $this->session->set('2fa_verified', $twoFactorVerified);

        if ($remember) {
            $this->createRememberToken((int)$user->id);
        }

        $this->sessionService->recordSession(
            (int)$user->id,
            $this->session->getId(),
            (string)get_user_agent(),
            client_ip(),
            is_scalar(app()->request->header('accept-language')) ? (string)app()->request->header('accept-language') : '',
            is_scalar(app()->request->header('accept-encoding')) ? (string)app()->request->header('accept-encoding') : ''
        );
    }

    public function finalizeSessionAfter2FA(\stdClass $user): void
    {
        // H-03 Fix: capture the persistent-login intent recorded at the password step
        // BEFORE the session is rebuilt, so it can be honored only now that the second
        // factor has actually been verified (proper 2FA binding of the remember token).
        $remember = (bool)$this->session->get('pending_2fa_remember', false);
        $this->session->regenerate(true);
        
        $this->createSession($user, $remember, true);
        $this->session->remove(SessionKeys::PENDING_2FA_USER_ID);
        $this->session->remove('pending_2fa_created_at');
        $this->session->remove('pending_2fa_ip');
        $this->session->remove('pending_2fa_remember');
        
        $identifier = mb_strtolower($user->email ?? (string)$user->username, 'UTF-8');
        
        $this->rateLimiter->clear('login_id:' . hash('sha256', $identifier));
        $this->rateLimiter->clear('login_ip:' . hash('sha256', client_ip()));
        cache()->forget('login_attempts:' . hash('sha256', $identifier));
        
        $this->auditTrail->record('auth.login.2fa_completed', (int)$user->id, [
            'ip' => client_ip(),
            'user_agent' => get_user_agent()
        ]);
    }

    private function createRememberToken(int $userId): void
    {
        $token = bin2hex(random_bytes(32));
        $hashedToken = hash('sha256', $token);
        
        $expiresAt = date('Y-m-d H:i:s', (strtotime('+30 days') ?: time()));
        if (!$this->userModel->storeRememberToken($userId, $hashedToken, $expiresAt)) {
            throw new \RuntimeException('Unable to persist remember token.');
        }
        
        setcookie('remember_token', $token, [
            'expires' => strtotime('+30 days'),
            'path' => '/',
            'domain' => '',
            'secure' => true,
            'httponly' => true,
            'samesite' => 'Strict'
        ]);
    }

    /**
     * H-03 Fix: A persistent "remember me" cookie must NEVER bypass the second factor.
     * For a 2FA-enabled account we only re-establish the pending 2FA state and require the
     * code again; a full session is created solely for accounts that have no second factor.
     *
     * @return array{status: string, user?: \stdClass} status = invalid|requires_2fa|authenticated
     */
    public function verifyByRememberToken(string $token): array
    {
        $hashedToken = hash('sha256', $token);
        $user = $this->toObject($this->userModel->findByRememberToken($hashedToken));
        if (!$user) { return ['status' => 'invalid']; }
        
        if (strtotime((string)($user->remember_expires_at ?? '')) < time()) {
            return ['status' => 'invalid'];
        }

        // H-03 Fix: never auto-login a disabled/locked account from a stale cookie.
        $accountStatus = (string)($user->status ?? '');
        if (in_array($accountStatus, ['locked', 'locked_2fa', 'banned', 'suspended'], true)) {
            return ['status' => 'invalid'];
        }

        // H-03 Fix: a 2FA-enabled account must still clear the second factor. Re-establish
        // the pending 2FA state (carrying the persistent intent) instead of logging in.
        if (!empty($user->two_factor_enabled)) {
            $this->createPending2FASession($user, true);
            $this->logger->info('auth.remember.requires_2fa', ['user_id' => (int)$user->id]);
            return ['status' => 'requires_2fa', 'user' => $user];
        }

        $this->session->regenerate(true);
        $this->createSession($user, true);
        
        return ['status' => 'authenticated', 'user' => $user];
    }

    public function logout(): void
    {
        $sessionId = $this->session->getId();
        $userId = $this->session->get(SessionKeys::USER_ID);
        
        if ($userId) {
            $this->logger->activity('auth.logout', '???? ?????', int_value($userId));
            
            $dbSession = $this->securityModel->findSessionBySessionId($sessionId);
            if ($dbSession) {
                $this->sessionService->terminateSession(int_value($dbSession->id), int_value($userId));
            }
        }

        try {
            if ($this->redis->isAvailable()) {
                $this->redis->delete("session:activity:{$sessionId}");
            }
        } catch (\Throwable $e) {
            $this->logger->error('auth.logout.redis_clear_failed', ['error' => $e->getMessage()]);
        }

        $this->clearRememberCookie();
        $this->session->destroy();
    }

    public function logoutAll(int $userId): void
    {
        $this->logger->activity('auth.logout_all', 'خروج از همه دستگاه‌ها', $userId);
        
        $sessions = $this->sessionService->getActiveSessions($userId);
        
        foreach ($sessions as $session) {
            try {
                $sessId = (string)($session->session_id ?? '');
                if ($sessId !== '') {
                    $this->session->destroyById($sessId);
                    if ($this->redis->isAvailable()) {
                        $this->redis->delete("session:activity:{$sessId}");
                        $this->redis->delete("CHORTKE_SESSION:{$sessId}");
                    }
                }
            } catch (\Throwable $e) {
                $this->logger->error('auth.logout_all.redis_clear_failed', [
                    'user_id' => $userId,
                    'session_id' => $session->session_id ?? 'unknown',
                    'error' => $e->getMessage()
                ]);
            }
        }
        
        $this->securityModel->deactivateUserSessions($userId);
        $this->userModel->revokeRememberToken($userId);
        
        // Revoke all API Tokens for the user on logout all
        try {
            $apiTokenModel = app(\App\Models\ApiToken::class);
            $apiTokenModel->revokeAllForUser($userId);
        } catch (\Throwable $e) {
            $this->logger->error('auth.logout_all.api_tokens_revoke_failed', ['user_id' => $userId, 'error' => $e->getMessage()]);
        }

        $this->clearRememberCookie();
        $this->session->destroy();
    }

    public function clearRememberCookie(): void
    {
        if (isset($_COOKIE['remember_token'])) {
            setcookie('remember_token', '', [
                'expires' => time() - 3600,
                'path' => '/',
                'domain' => '',
                'secure' => true,
                'httponly' => true,
                'samesite' => 'Strict'
            ]);
        }
    }


    public function check(): bool
    {
        return $this->session->get(SessionKeys::LOGGED_IN) === true;
    }

    public function user(): ?\stdClass
    {
        if (!$this->check()) return null;

        $userId = int_value($this->session->get(SessionKeys::USER_ID));
        $user = $this->toObject($this->userModel->find($userId));
        if (!$user) { 
        return null;
        }

        return $user;
    }

    public function getPending2FAUserId(): ?int
    {
        return $this->session->get(SessionKeys::PENDING_2FA_USER_ID) ? int_value($this->session->get(SessionKeys::PENDING_2FA_USER_ID)) : null;
    }

    public function getPending2FACreatedAt(): int
    {
        $createdAt = int_value($this->session->get('pending_2fa_created_at', 0));
        if ($createdAt === 0 && $this->session->get('admin_pending_2fa')) {
            $createdAt = int_value($this->session->get('admin_pending_2fa_created', 0));
        }
        return $createdAt;
    }

    public function getPending2FAIp(): ?string
    {
        $pendingIp = $this->session->get('pending_2fa_ip');
        if (empty($pendingIp) && $this->session->get('admin_pending_2fa')) {
            $pendingIp = $this->session->get('admin_pending_2fa_ip');
        }
        return is_string($pendingIp) && $pendingIp !== '' ? $pendingIp : null;
    }

    public function destroySession(): void
    {
        $this->session->destroy();
    }
}