<?php

declare(strict_types=1);

namespace App\Jobs\Auth;

/**
 * Verify2FAJob
 * 
 * پردازش تایید کد دو مرحله‌ای.
 */
class Verify2FAJob
{
    private \App\Contracts\LoggerInterface $logger;
    private \App\Models\User $userModel;
    private \App\Services\Auth\TwoFactorService $twoFactorService;
    private \App\Services\Auth\AuthSessionManager $sessionManager;
    private ?\App\Contracts\OutboxServiceInterface $outbox = null;

    public function __construct(
        \App\Contracts\LoggerInterface $logger,
        \App\Models\User $userModel,
        \App\Services\Auth\TwoFactorService $twoFactorService,
        \App\Services\Auth\AuthSessionManager $sessionManager,
        ?\App\Contracts\OutboxServiceInterface $outbox = null
    ) {
        $this->logger = $logger;
        $this->userModel = $userModel;
        $this->twoFactorService = $twoFactorService;
        $this->sessionManager = $sessionManager;
        $this->outbox = $outbox;
    }

    /** @return array<string, mixed> */
public function handle(string $code): array
    {
        $pendingUserId = $this->sessionManager->getPending2FAUserId();
        if (!$pendingUserId) {
            return ['success' => false, 'message' => 'درخواست احراز هویت یافت نشد.'];
        }

        $createdAt = $this->sessionManager->getPending2FACreatedAt();
        if (time() - $createdAt > 600) { 
            $this->sessionManager->destroySession();
            return ['success' => false, 'message' => 'زمان کد تایید به پایان رسیده است.'];
        }
        
        $pendingIp = $this->sessionManager->getPending2FAIp();
        $currentIp = client_ip();
        
        // Normalize IPs for subnet comparison
        $normalize = function(string $ip): string {
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
                $packed = inet_pton($ip);
                if ($packed === false) return $ip;
                $normalized = inet_ntop(substr($packed, 0, 8) . str_repeat("\x00", 8));
                return $normalized === false ? $ip : $normalized;
            }
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                $pos = strrpos($ip, '.');
                return $pos !== false ? substr($ip, 0, $pos) : $ip;
            }
            return $ip;
        };

        $pendingSubnet = $normalize($pendingIp ?? '');
        $currentSubnet = $normalize($currentIp);
        
        if ($pendingSubnet !== $currentSubnet) {
            $this->logger->warning('auth.2fa.ip_mismatch', [
                'pending_ip' => $pendingIp,
                'current_ip' => $currentIp,
                'user_id' => $pendingUserId
            ]);
        }

        $user = $this->userModel->find((int)$pendingUserId);
        if (!$user instanceof \stdClass || !$user->two_factor_enabled) {
            $this->sessionManager->destroySession();
            return ['success' => false, 'message' => 'تنظیمات امنیتی یافت نشد.'];
        }

        if ($user->status === 'locked' || $user->status === 'locked_2fa') {
            $this->sessionManager->destroySession();
            return ['success' => false, 'message' => 'حساب کاربری قفل شده است.'];
        }

        if (!$this->twoFactorService->verifyCode($user->two_factor_secret, $code, (int)$user->id)) {
            $this->logger->warning('auth.2fa_failed', ['user_id' => (int)$user->id]);
            
            $freshUser = $this->userModel->find((int)$user->id);
            if ($freshUser && $freshUser->status === 'locked_2fa') {
                $this->sessionManager->destroySession();
                return ['success' => false, 'message' => 'حساب شما به دلیل تلاش‌های ناموفق قفل شد.'];
            }
            
            return ['success' => false, 'message' => 'کد تایید اشتباه است.'];
        }

        $this->sessionManager->finalizeSessionAfter2FA($user);

        $this->outbox?->record('auth', (int)$user->id, 'auth.login', [
            'user_id' => (int)$user->id,
            'ip' => client_ip(),
            'user_agent' => get_user_agent(),
        ]);

        return [
            'success' => true,
            'message' => 'ورود با موفقیت انجام شد.',
            'user' => $user,
        ];
    }
}
