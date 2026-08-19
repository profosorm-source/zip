<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Models\User;
use App\Models\SecurityModel;
use App\Services\User\UserService;
use App\Services\EmailService;
use Core\RateLimiter;
use App\Contracts\LoggerInterface;

class PasswordRecoveryService
{
    private static ?string $cachedDummyHash = null;

    private SecurityModel $securityModel;
    private User $userModel;
    private UserService $userService;
    private RateLimiter $rateLimiter;
    private LoggerInterface $logger;
        private ?EmailService $emailService;
    private ?\App\Contracts\OutboxServiceInterface $outbox = null;
    public function __construct(
        SecurityModel $securityModel,
        User $userModel,
        UserService $userService,
        RateLimiter $rateLimiter,
        LoggerInterface $logger,
        ?\App\Contracts\OutboxServiceInterface $outbox = null,
        ?EmailService $emailService = null
    ) {        $this->securityModel = $securityModel;
        $this->userModel = $userModel;
        $this->userService = $userService;
        $this->rateLimiter = $rateLimiter;
        $this->logger = $logger;
        $this->outbox = $outbox;
        $this->emailService = $emailService;
}

    public function verifyPassword(string $password, string $hash, ?int $userId = null): bool
    {
        if ($password === '') return false;

        $inputPassword = base64_encode(hash('sha384', $password, true));
        
        if (password_verify($inputPassword, $hash)) {
            return true;
        }

        if (password_verify($password, $hash)) {
            if ($userId) {
                // Rehash با فرمت جدید (sha384 pre-hash) — رمز plaintext هرگز ذخیره نمیشه
                try {
                    $newHash = password_hash(
                        base64_encode(hash('sha384', $password, true)),
                        PASSWORD_BCRYPT
                    );
                    $this->outbox?->record('auth', $userId, 'auth.rehash_password', [
                        'user_id' => $userId,
                        'reason' => 'legacy_format_migration',
                    ]);
                    // آپدیت مستقیم hash جدید
                    $this->userModel->update($userId, ['password' => $newHash]);
                } catch (\Throwable $e) {
                    // اگر rehash فیل شد، login نباید فیل بشه
                    $this->logger->warning('auth.rehash_failed', [
                        'user_id' => $userId,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
            return true;
        }

        return false;
    }

    /**
     * @return array<string, mixed>
     */
    public function requestPasswordReset(string $email): array
    {
        $ip = client_ip();
        $rateLimitKey = "pw_reset:" . hash('sha256', "{$email}:{$ip}");
        
        if (!$this->rateLimiter->attempt($rateLimitKey, 3, 60, true)) {
            $seconds = $this->rateLimiter->availableIn($rateLimitKey);
            $minutes = (int)ceil($seconds / 60);
            
            $this->logger->warning('auth.password_reset.rate_limited', [
                'email' => $email,
                'ip' => $ip
            ]);
            
            return [
                'success' => false, 
                'message' => "????? ??????????? ??????? ??? ?? ?? ???? ???. ????? {$minutes} ????? ???? ?????? ????."
            ];
        }

        $user = $this->userModel->findByEmail($email);
        $genericMsg = '??? ??? ????? ?? ????? ??? ??? ????? ???? ??????? ???? ??? ????? ??????.';

        // L-22 Fix: توکن بازنشانی فقط برای کاربر موجود و فعال ساخته می‌شود؛
        // پاسخ عمومی و تأخیر زمانی یکسان حفظ می‌شود تا enumeration رخ ندهد.
        $isActiveUser = $user && (($user->status ?? 'active') === 'active');

        if ($isActiveUser) {
            $token = bin2hex(random_bytes(32));
            $this->securityModel->createPasswordResetToken($email, $token);
            if ($this->emailService) {
                $this->emailService->sendPasswordResetEmail((int)$user->id, $token);
            }
            $this->logger->activity('auth.password_reset.requested', '??????? ??????? ??? ????', (int)$user->id);
        } else {
            // کاربر ناموجود/غیرفعال: هیچ توکنی ساخته نمی‌شود؛ فقط تأخیر مشابه برای یکسانی زمان.
            usleep(random_int(100000, 300000));
        }

        return ['success' => true, 'message' => $genericMsg];
    }

    public function validatePasswordResetToken(string $token): bool
    {
        $timeout = int_value(config('auth.password_reset_ttl', 3600));
        $record = $this->securityModel->findPasswordResetByToken($token, $timeout);
        return $record !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function resetPassword(string $token, string $newPassword, ?string $email = null): array
    {
        $timeout = int_value(config('auth.password_reset_ttl', 3600));
        // 🛡️ Atomic Consumption Fix (Issue #8)
        $record = $this->securityModel->findAndConsumePasswordResetToken($token, $timeout);
        
        if (!$record) {
            return ['success' => false, 'message' => 'توکن بازنشانی رمز عبور نامعتبر یا منقضی شده است.'];
        }

        if ($email !== null && mb_strtolower($record->email, 'UTF-8') !== mb_strtolower($email, 'UTF-8')) {
            $this->logger->critical('auth.password_reset.email_mismatch', [
                'token' => $token,
                'expected' => $record->email,
                'provided' => $email
            ]);
            return ['success' => false, 'message' => 'ایمیل واردشده با درخواست بازنشانی مطابقت ندارد.'];
        }

        $user = $this->userModel->findByEmail($record->email);
        if (!$user) {
            $this->securityModel->deletePasswordResetByEmail($record->email);
            return ['success' => false, 'message' => 'کاربر مربوط به این درخواست یافت نشد.'];
        }

        // رمز خام (plaintext) مستقیم به changePassword داده میشه
        $this->userService->changePassword((int)$user->id, $newPassword);
        $this->securityModel->deletePasswordResetByEmail($record->email);

        // 🛡️ Security Hardening (Issue #4): Invalidate ALL active web sessions & API tokens
        try {
            $sessionService = app(\App\Services\Auth\SessionService::class);
            $sessionService->invalidateAllUserSessions((int)$user->id);

            $apiTokenModel = app(\App\Models\ApiToken::class);
            $apiTokenModel->revokeAllForUser((int)$user->id);
        } catch (\Throwable $e) {
            $this->logger->error('auth.password_reset.session_invalidation_failed', [
                'user_id' => $user->id,
                'error' => $e->getMessage()
            ]);
        }

        $this->logger->activity('auth.password_reset.completed', 'بازنشانی رمز عبور با موفقیت انجام شد', (int)$user->id);
        return ['success' => true, 'message' => 'رمز عبور با موفقیت تغییر یافت. تمامی نشست‌های فعال و توکن‌های قبلی باطل شدند.'];
    }

    public function getDummyHash(): string
    {
        if (self::$cachedDummyHash === null) {
            self::$cachedDummyHash = password_hash(bin2hex(random_bytes(16)), PASSWORD_BCRYPT);
        }
        return self::$cachedDummyHash;
    }
}
