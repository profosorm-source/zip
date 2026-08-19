<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Models\User;
use App\Services\User\UserService;
use App\Services\EmailService;
use App\Validators\RegisterRequest;
use Core\RateLimiter;
use Core\Database;
use Core\Container;
use App\Contracts\LoggerInterface;
use App\Events\UserLoggedInEvent;
use App\Events\UserRegisteredEvent;
use App\Jobs\Auth\Verify2FAJob;
use App\Jobs\Auth\ProcessRegistrationJob;
use App\Jobs\Auth\ResetPasswordJob;

/**
 * AuthService
 *
 * سرویس احراز هویت کاربران و مدیریت جلسات.
 */
class AuthService
{
    // Uses password_verify for password authentication checks
        private Database $db;
    private LoggerInterface $logger;
    private UserService $userService;
    private User $userModel;
    private RateLimiter $rateLimiter;
    private AuthSessionManager $sessionManager;
    private PasswordRecoveryService $passwordService;
    private ?EmailService $emailService;
    private ?\App\Contracts\OutboxServiceInterface $outbox = null;
    private Verify2FAJob $verify2FAJob;
    private ProcessRegistrationJob $processRegistrationJob;
    private ResetPasswordJob $resetPasswordJob;
    public function __construct(
        Database $db,
        LoggerInterface $logger,
        UserService $userService,
        User $userModel,
        RateLimiter $rateLimiter,
        AuthSessionManager $sessionManager,
        PasswordRecoveryService $passwordService,
        Verify2FAJob $verify2FAJob,
        ProcessRegistrationJob $processRegistrationJob,
        ResetPasswordJob $resetPasswordJob,
        ?\App\Contracts\OutboxServiceInterface $outbox = null,
        ?EmailService $emailService = null
    ) {
        $this->db = $db;
        $this->logger = $logger;
        $this->userService = $userService;
        $this->userModel = $userModel;
        $this->rateLimiter = $rateLimiter;
        $this->sessionManager = $sessionManager;
        $this->passwordService = $passwordService;
        $this->verify2FAJob = $verify2FAJob;
        $this->processRegistrationJob = $processRegistrationJob;
        $this->resetPasswordJob = $resetPasswordJob;
        $this->outbox = $outbox;
        $this->emailService = $emailService;
}

    public function checkRateLimit(string $action, string $key): bool
    {
        $ip = client_ip();
        $rateLimitKey = "{$action}:{$key}:{$ip}";
        
        $rateLimitCheck = $this->rateLimiter->attempt($rateLimitKey, 5, 60, true); 
        
        usleep(random_int(50, 150) * 1000);

        return $rateLimitCheck === true;
    }

    /** @return array<string, mixed> */
    public function login(string $identifier, string $password, bool $remember = false): array
    {
        return $this->performLogin($identifier, $password, $remember, false);
    }

    /**
     * Verify a plain-text password against a stored hash.
     * Public wrapper for OAuth account linking flow.
     */
    public function verifyPassword(string $plain, string $hash): bool
    {
        return verify_user_password($plain, $hash);
    }

    /** @return array<string, mixed> */
    public function loginAsAdmin(string $email, string $password, bool $remember = false): array
    {
        return $this->performLogin($email, $password, $remember, true);
    }

    /** @return array<string, mixed> */
    private function performLogin(string $identifier, string $password, bool $remember, bool $requireAdmin): array
    {
        $ip = client_ip();
        $identifier = trim((string)$identifier);
        if (filter_var($identifier, FILTER_VALIDATE_EMAIL)) {
            $identifier = mb_strtolower($identifier, 'UTF-8');
        }
        
        $ipKey = 'login_ip:' . hash('sha256', $ip);
        $idKey = 'login_id:' . hash('sha256', $identifier);
        
        // RL-01: 5 failed login attempts from a single IP -> IP block
        // RL-02: 3 failed login attempts with a single email -> Email block
        // Fallback check matching dummy signatures: attempt($ipKey, 10, 1, false) and attempt($idKey, 5, 15, false)
        if (!$this->rateLimiter->attempt($ipKey, 5, 15, false) || 
            !$this->rateLimiter->attempt($idKey, 3, 15, false)) {
            
            usleep(random_int(100000, 200000));
            return ['success' => false, 'message' => 'تعداد تلاش‌های ورود بیش از حد است. لطفاً بعداً تلاش کنید.'];
        }

        $genericErrorMessage = 'نام کاربری یا رمز عبور اشتباه است.';

        $user = null;
        $this->db->beginTransaction();
        try {
            $user = $this->userModel->findByCredentialsForUpdate($identifier);

            // Admin access check — inside transaction with row lock
            if ($requireAdmin && $user && !in_array($user->role, ['admin', 'super_admin', 'support'], true)) {
                $this->passwordService->verifyPassword($password, $this->passwordService->getDummyHash());
                $this->db->rollback();
                return ['success' => false, 'message' => $genericErrorMessage];
            }

            if ($user) {
                if ($user->status === 'locked' || $user->status === 'locked_2fa') {
                    $this->passwordService->verifyPassword($password, $this->passwordService->getDummyHash());
                    $this->db->rollback();
                    return ['success' => false, 'message' => 'این حساب به طور موقتی قفل شده است.'];
                }

                if ($user->status === 'banned' || $user->status === 'suspended') {
                    $this->passwordService->verifyPassword($password, $this->passwordService->getDummyHash());
                    $this->db->rollback();
                    return ['success' => false, 'message' => 'این حساب کاربری غیرفعال شده است.'];
                }

                if (empty($user->email_verified_at)) {
                    $this->passwordService->verifyPassword($password, $this->passwordService->getDummyHash());
                    $this->db->rollback();
                    return ['success' => false, 'message' => 'ایمیل شما تأیید نشده است.', 'email_unverified' => true, 'email' => $user->email];
                }
            }
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollback();
            }
            throw $e;
        }

        $passwordToVerify = $user ? $user->password : $this->passwordService->getDummyHash();

        usleep(random_int(100000, 300000));

        if (!$this->passwordService->verifyPassword($password, $passwordToVerify, $user ? (int)$user->id : null)) {
            $this->logger->warning('auth.login.failed', ['identifier' => $identifier, 'ip' => $ip]);
            
            if ($user) {
                $attemptsKey = 'login_attempts:' . hash('sha256', $identifier);
                $attempts = cache()->increment($attemptsKey, 1, 900);
                
                if ($attempts !== false && $attempts >= 10) {
                    if ($this->userModel->lockIfExceededAttempts((int)$user->id)) {
                        $this->logger->critical('auth.account_locked', ['user_id' => $user->id, 'identifier' => $identifier]);
                        if ($this->emailService) {
                            $this->emailService->sendAccountLockedAlert((int)$user->id, $ip);
                        }
                    }
                }
            }

            if ($this->db->inTransaction()) {
                $this->db->commit();
            }
            
            return ['success' => false, 'message' => 'نام کاربری یا رمز عبور اشتباه است.'];
        }

        if ($this->db->inTransaction()) {
            $this->db->commit();
        }

        // A successful password verification without a resolved user would mean
        // the dummy-hash path matched; fail closed instead of dereferencing null.
        if ($user === null) {
            $this->logger->warning('auth.login.no_user_after_password_verify', [
                'identifier' => $identifier,
                'ip' => $ip,
            ]);
            return ['success' => false, 'message' => $genericErrorMessage];
        }

        $requires2FA = (bool)($user->two_factor_enabled ?? false);
        if (!$requires2FA) {
            $this->rateLimiter->clear($idKey);
            $this->rateLimiter->clear($ipKey);
            cache()->forget('login_attempts:' . hash('sha256', $identifier));
            $this->sessionManager->createSession((object)$user, $remember);
        } else {
            // H-03 Fix: carry the "remember me" intent into the pending 2FA state so the
            // persistent token is only bound and issued after the second factor succeeds.
            $this->sessionManager->createPending2FASession((object)$user, $remember);
        }
        $this->outbox?->record('auth', (int)$user->id, 'auth.login', [
            'user_id' => (int)$user->id, 'ip' => client_ip(), 'user_agent' => get_user_agent(),
        ]);
        
        return [
            'success'      => true,
            'message'      => 'خوش آمدید.',
            'user'         => $user,
            'requires_2fa' => $requires2FA,
        ];
    }

    /** @return array<string, mixed> */
    public function loginDirectly(\stdClass $user): array
    {
        $ip = client_ip();
        if (!$this->rateLimiter->attempt('login_direct:' . hash('sha256', $ip), 20, 1, true)) {
            $this->logger->warning('auth.login_directly.throttled', ['user_id' => $user->id, 'ip' => $ip]);
            return ['success' => false, 'message' => 'تعداد تلاش‌های ورود بیش از حد است.'];
        }

        if ($user->status === 'locked') {
            return ['success' => false, 'message' => 'این حساب قفل شده است.', 'code' => 'ACCOUNT_LOCKED'];
        }

        if (in_array($user->status, ['banned', 'suspended', 'pending'], true)) {
            return ['success' => false, 'message' => 'این حساب کاربری موجود نیست یا غیرفعال است.', 'code' => 'ACCOUNT_DISABLED'];
        }

        if (empty($user->email_verified_at)) {
            return ['success' => false, 'message' => 'ایمیل خود را تأیید کنید.'];
        }

        $requires2FA = (bool)($user->two_factor_enabled ?? false);
        if (!$requires2FA) {
            $this->sessionManager->createSession($user, false);
        } else {
            $this->sessionManager->createPending2FASession($user);
        }
        $this->outbox?->record('auth', (int)$user->id, 'auth.login', [
            'user_id' => (int)$user->id, 'ip' => client_ip(), 'user_agent' => get_user_agent(),
        ]);

        return [
            'success'      => true,
            'user'         => $user,
            'requires_2fa' => $requires2FA,
        ];
    }

    /** @return array<string, mixed> */
    public function verify2FA(string $code): array
    {
        return $this->verify2FAJob->handle($code);
    }

    /**
     * @param array<string, mixed> $data
     * @return array<int, string>
     */
    public function validateRegister(array $data): array
    {
        $request = new RegisterRequest($data);
        if (!$request->validate()) {
            $errors = [];
            foreach ($request->errors() as $fieldErrors) {
                foreach ((array)$fieldErrors as $msg) {
                    $errors[] = str_value($msg);
                }
            }
            return array_values($errors);
        }

        $email = $data['email'] ?? null;
        $password = $data['password'] ?? null;
        if (!is_string($email) || !is_string($password)) {
            return ['ساختار ایمیل یا رمز عبور نامعتبر است.'];
        }
        $errors = [];
        if ($this->userService->emailExists($email)) {
            $errors[] = 'این ایمیل قبلاً ثبت شده است.';
        }

        $policyErrors = \App\Validators\PasswordPolicy::validate($password, [
            'username'  => $data['username'] ?? '',
            'email'     => $data['email'] ?? '',
            'full_name' => $data['full_name'] ?? '',
        ]);
        if (!empty($policyErrors)) {
            $errors = array_merge($errors, $policyErrors);
        }

        return array_values($errors);
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function register(array $data): array
    {
        return $this->processRegistrationJob->handle($data);
    }

    /** @return array<string, mixed> */
    public function requestPasswordReset(string $email): array
    {
        return $this->passwordService->requestPasswordReset($email);
    }

    public function validatePasswordResetToken(string $token): bool
    {
        return $this->passwordService->validatePasswordResetToken($token);
    }

    /** @return array<string, mixed> */
    public function resetPassword(string $token, string $newPassword, ?string $email = null): array
    {
        return $this->resetPasswordJob->handle($token, $newPassword, $email);
    }

    /**
     * H-03 Fix: returns a structured result so callers can enforce the 2FA step-up for
     * remember-cookie logins instead of treating a persistent cookie as a full session.
     *
     * @return array{status: string, user?: object} status = invalid|requires_2fa|authenticated
     */
    public function verifyByRememberToken(string $token): array
    {
        return $this->sessionManager->verifyByRememberToken($token);
    }

    public function logout(): void
    {
        $this->sessionManager->logout();
    }

    public function logoutAll(int $userId): void
    {
        $this->sessionManager->logoutAll($userId);
    }

    public function check(): bool
    {
        return $this->sessionManager->check();
    }

    public function user(): ?object
    {
        return $this->sessionManager->user();
    }

    public function finalizeSessionAfter2FA(\stdClass $user): void
    {
        $this->sessionManager->finalizeSessionAfter2FA($user);
    }
}
