<?php

declare(strict_types=1);

namespace App\Jobs\Auth;

use App\Services\User\UserService;

class ProcessRegistrationJob
{
    #[ \Core\Attributes\Inject ]
    private UserService $userService;

    #[ \Core\Attributes\Inject ]
    private ?\App\Contracts\OutboxServiceInterface $outbox = null;

    #[ \Core\Attributes\Inject ]
    private \App\Contracts\WalletServiceInterface $walletService;

    #[ \Core\Attributes\Inject ]
    private \App\Contracts\LoggerInterface $logger;

    #[ \Core\Attributes\Inject ]
    private \App\Models\User $userModel;

    #[ \Core\Attributes\Inject ]
    private \App\Services\Shared\ReferralService $referralService;

    public function __construct(
        UserService $userService,
        \App\Contracts\WalletServiceInterface $walletService,
        \App\Contracts\LoggerInterface $logger,
        \App\Models\User $userModel,
        \App\Services\Shared\ReferralService $referralService,
        ?\App\Contracts\OutboxServiceInterface $outbox = null
    ) {
        $this->userService = $userService;
        $this->walletService = $walletService;
        $this->logger = $logger;
        $this->userModel = $userModel;
        $this->referralService = $referralService;
        if ($outbox !== null) {
            $this->outbox = $outbox;
        }
    }

/**
 * @param array<string, mixed> $data
 * @return array<string, mixed>
 */
public function handle(array $data): array
    {
        try {
            $result = $this->userService->register($data);
        } catch (\InvalidArgumentException $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        } catch (\Throwable $e) {
            $this->logger->error('auth.registration.failed', ['error' => $e->getMessage()]);
            return ['success' => false, 'message' => 'ثبت‌نام با خطا مواجه شد.'];
        }

        if (!$result) {
            return ['success' => false, 'message' => 'ثبت‌نام با خطا مواجه شد.'];
        }

        $userId = int_value($result['id'] ?? 0);

        // GUARANTEE: Create wallet immediately to prevent financial paralysis
        try {
            $this->walletService->getOrCreateWallet($userId);
        } catch (\Throwable $e) {
            $this->logger->critical('wallet.creation_failed', ['user_id' => $userId, 'error' => $e->getMessage()]);
        }

        // RF-01: Apply signup referral bonus (fixed amount) once the referred user and wallet exist.
        try {
            $createdUser = $this->userModel->find($userId);
            $referrerId = (int)($createdUser->referred_by ?? 0);
            if ($referrerId > 0) {
                $this->referralService->awardSignupBonus(int_value($userId), 'irt');
            }
        } catch (\Throwable $e) {
            $this->logger->error('referral.signup_bonus_failed', ['user_id' => $userId, 'error' => $e->getMessage()]);
        }

        // Dispatch UserRegisteredEvent for other welcome flows (emails, bonuses, etc.)
        \Core\EventDispatcher::getInstance()->dispatch(
            \App\Events\UserRegisteredEvent::class, 
            new \App\Events\UserRegisteredEvent(
                int_value($userId), 
                str_value($data['email'] ?? ''), 
                client_ip()
            )
        );

        $this->outbox?->record('auth', int_value($userId), 'auth.register', [
            'user_id' => int_value($userId),
            'email' => str_value($data['email'] ?? ''),
            'ip' => client_ip(),
        ]);

        return ['success' => true, 'message' => 'ثبت‌نام با موفقیت انجام شد.', 'user' => $this->userModel->find($userId)];
    }

}
