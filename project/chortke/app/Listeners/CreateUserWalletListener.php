<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\UserRegisteredEvent;
use App\Contracts\WalletServiceInterface;
use App\Contracts\LoggerInterface;

class CreateUserWalletListener
{
    private WalletServiceInterface $walletService;
    private ?LoggerInterface $logger;

    public function __construct(WalletServiceInterface $walletService, ?LoggerInterface $logger = null) {
        $this->walletService = $walletService;
        $this->logger = $logger;
    }

    public function handle(UserRegisteredEvent $event): void
    {
        $userId = (int) $event->userId;
        

        try {
            // Create default wallet for the new user
            // The wallet service handles the creation and initial balance
            $this->walletService->getOrCreateWallet($userId);
            
            $this->logger?->info('wallet.created_on_registration', [
                'user_id' => $userId,
                'status' => 'success'
            ]);
        } catch (\Throwable $e) {
            \App\Services\Sentry\SentryExceptionHandler::captureException($e, null, ['operation' => 'app.Listeners.CreateUserWalletListener.handle']);
            $this->logger?->critical('wallet.creation_failed_on_registration', [
                'user_id' => $userId,
                'error' => $e->getMessage(),
                'exception' => get_class($e)
            ]);
            
            // We don't throw an exception here to prevent the registration process from failing
            // But we log it as critical so it can be fixed via a background job or admin panel
        }
    }
}
