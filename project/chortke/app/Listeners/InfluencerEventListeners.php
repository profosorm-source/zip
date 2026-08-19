<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Contracts\LoggerInterface;
use App\Enums\ModuleContext;
use Core\Container;
use App\Services\Shared\IdempotencyService;

/**
 * InfluencerEventListeners - Centralized event handling for influencer domain
 * 
 * Decouples InfluencerService from WalletServiceInterface, NotificationService, XpService, 
 * ScoreService, and ReferralService by replacing direct method calls with event-driven handlers.
 * 
 * Dependencies are lazy-loaded via Container to avoid constructor bloat.
 */
class InfluencerEventListeners
{
    private Container $container;
    private LoggerInterface $logger;
    private IdempotencyService $idempotencyService;

    public function __construct(Container $container, LoggerInterface $logger, IdempotencyService $idempotencyService) {
        $this->container = $container;
        $this->logger = $logger;
        $this->idempotencyService = $idempotencyService;
    }

    /**
     * Handle influencer.order_created event
     * 
     * Triggers:
     * - Wallet escrow handling (already done in service)
     * - Audit trail and notifications (via other handlers)
     */
    public function handleInfluencerOrderCreated(mixed $event): void
    {
        try {
            $data = $this->extractPayload($event);
            $influencerId = int_value($data['influencer_id'] ?? $data['influencer_user_id'] ?? 0);
            $customerId = int_value($data['customer_id'] ?? 0);

            if (!$influencerId || !$customerId) {
                $this->logger->warning('influencer.order_created event missing required fields', $data);
                return;
            }

            // Wallet invalidation
            $this->invalidateWalletCache($customerId);
            $this->invalidateWalletCache(int_value($data['influencer_user_id'] ?? 0) ?: null);

            // Notify influencer about the new advertising proposal
            if (!empty($data['influencer_user_id'])) {
                $this->notifyUser(
                    int_value($data['influencer_user_id']),
                    'پیشنهاد تبلیغاتی جدید',
                    "یک پیشنهاد تبلیغاتی جدید برای شما ثبت شده است. لطفاً جهت پذیرش یا رد آن به پنل خود مراجعه کنید."
                );
            }

        } catch (\Throwable $e) {
            \App\Services\Sentry\SentryExceptionHandler::captureException($e, null, ['operation' => 'app.Listeners.InfluencerEventListeners.handleInfluencerOrderCreated']);
            $this->logger->error('influencer.order_created handler failed', [
                'error' => $e->getMessage(),
                'event' => $event,
            ]);
        }
    }

    /**
     * Handle influencer.order_completed event
     * 
     * Triggers:
     * - XP award to customer
     * - Score reputation increase for influencer
     * - Wallet deposit for influencer payout
     * - User notifications
     */
    public function handleInfluencerOrderCompleted(mixed $event): void
    {
        try {
            $data = $this->extractPayload($event);
            $orderId = int_value($data['order_id'] ?? 0);
            $customerId = int_value($data['customer_id'] ?? 0);
            $influencerId = int_value($data['influencer_id'] ?? 0);
            $influencerUserId = int_value($data['influencer_user_id'] ?? 0);
            $influencerEarning = float_value($data['influencer_earning'] ?? 0);
            $currency = str_value($data['currency'] ?? 'irt');

            if (!$orderId || !$customerId || !$influencerId || !$influencerUserId) {
                $this->logger->warning('influencer.order_completed event missing required fields', $data);
                return;
            }

            // Award XP to customer
            $this->awardXp($customerId, ModuleContext::YOUTUBE_TASKS, 2.0, "influencer_order_{$orderId}");

            // Increase influencer reputation score
            $reputationPts = int_value($data['reputation_points'] ?? 5);
            $this->applyReputationDelta($influencerId, $reputationPts, 'order_completed', [
                'order_id' => $orderId,
            ]);

            // Deposit influencer earnings to wallet
            if ($influencerEarning > 0) {
                $this->depositToWallet(
                    $influencerUserId,
                    $influencerEarning,
                    $currency,
                    [
                        'type' => 'influencer_order_payout',
                        'description' => "سفارش تبلیغاتی #$orderId مکمل شد",
                        'order_id' => $orderId,
                    ]
                );
            }

            // Invalidate caches
            $this->invalidateWalletCache($customerId);
            $this->invalidateWalletCache($influencerUserId);

            // Notify customer
            $this->notifyUser(
                $customerId,
                'سفارش تبلیغاتی مکمل شد',
                "سفارش شما توسط اینفلوئنسر مکمل شد."
            );

            // Notify influencer
            $this->notifyUser(
                $influencerUserId,
                'پرداخت سفارش',
                "درآمد سفارش #{$orderId} به کیف‌پول شما منتقل شد."
            );

        } catch (\Throwable $e) {
            \App\Services\Sentry\SentryExceptionHandler::captureException($e, null, ['operation' => 'app.Listeners.InfluencerEventListeners.handleInfluencerOrderCompleted']);
            $this->logger->error('influencer.order_completed handler failed', [
                'error' => $e->getMessage(),
                'event' => $event,
            ]);
        }
    }

    /**
     * Handle influencer.order_rejected event
     * 
     * Triggers:
     * - Wallet refund to customer
     * - Score reputation decrease for influencer
     * - User notifications
     */
    public function handleInfluencerOrderRejected(mixed $event): void
    {
        try {
            $data = $this->extractPayload($event);
            $orderId = int_value($data['order_id'] ?? 0);
            $customerId = int_value($data['customer_id'] ?? 0);
            $influencerId = int_value($data['influencer_id'] ?? 0);
            $influencerUserId = int_value($data['influencer_user_id'] ?? 0);
            $refundAmount = float_value($data['refund_amount'] ?? 0);
            $currency = str_value($data['currency'] ?? 'irt');
            $reason = str_value($data['reason'] ?? 'نامشخص');

            if (!$orderId || !$customerId || !$influencerId) {
                $this->logger->warning('influencer.order_rejected event missing required fields', $data);
                return;
            }

            // Refund to customer
            if ($refundAmount > 0) {
                $this->depositToWallet(
                    $customerId,
                    $refundAmount,
                    $currency,
                    [
                        'type' => 'influencer_order_refund',
                        'description' => "بازپرداخت سفارش رد شده #$orderId",
                        'order_id' => $orderId,
                    ]
                );
            }

            // Decrease influencer reputation
            $reputationPenalty = int_value($data['reputation_penalty'] ?? 3);
            $this->applyReputationDelta($influencerId, -$reputationPenalty, 'order_rejected', [
                'order_id' => $orderId,
                'reason' => $reason,
            ]);

            // Invalidate caches
            $this->invalidateWalletCache($customerId);
            if ($influencerUserId) {
                $this->invalidateWalletCache($influencerUserId);
            }

            // Notify customer
            $this->notifyUser(
                $customerId,
                'سفارش رد شد',
                "سفارش شما توسط اینفلوئنسر رد شد. دلیل: {$reason}"
            );

            // Notify influencer
            if ($influencerUserId) {
                $this->notifyUser(
                    $influencerUserId,
                    'سفارش رد شد',
                    "سفارش #{$orderId} توسط سیستم رد شد."
                );
            }

        } catch (\Throwable $e) {
            \App\Services\Sentry\SentryExceptionHandler::captureException($e, null, ['operation' => 'app.Listeners.InfluencerEventListeners.handleInfluencerOrderRejected']);
            $this->logger->error('influencer.order_rejected handler failed', [
                'error' => $e->getMessage(),
                'event' => $event,
            ]);
        }
    }

    /**
     * Handle influencer.order_refunded event
     * 
     * Triggers:
     * - Partial or full refund to customer
     * - User notifications
     */
    public function handleInfluencerOrderRefunded(mixed $event): void
    {
        try {
            $data = $this->extractPayload($event);
            $orderId = int_value($data['order_id'] ?? 0);
            $customerId = int_value($data['customer_id'] ?? 0);
            $refundAmount = float_value($data['refund_amount'] ?? 0);
            $currency = str_value($data['currency'] ?? 'irt');

            if (!$orderId || !$customerId) {
                $this->logger->warning('influencer.order_refunded event missing required fields', $data);
                return;
            }

            // Refund to customer
            if ($refundAmount > 0) {
                $this->depositToWallet(
                    $customerId,
                    $refundAmount,
                    $currency,
                    [
                        'type' => 'influencer_order_refund',
                        'description' => "بازپرداخت سفارش #$orderId",
                        'order_id' => $orderId,
                    ]
                );
            }

            // Invalidate cache
            $this->invalidateWalletCache($customerId);

            // Notify customer
            $this->notifyUser(
                $customerId,
                'بازپرداخت سفارش',
                "مبلغ بازپرداختی به کیف‌پول شما منتقل شد."
            );

        } catch (\Throwable $e) {
            \App\Services\Sentry\SentryExceptionHandler::captureException($e, null, ['operation' => 'app.Listeners.InfluencerEventListeners.handleInfluencerOrderRefunded']);
            $this->logger->error('influencer.order_refunded handler failed', [
                'error' => $e->getMessage(),
                'event' => $event,
            ]);
        }
    }

    /**
     * Handle influencer.profile_verified event
     * 
     * Triggers:
     * - Bonus XP award
     * - User notification
     */
    public function handleInfluencerProfileVerified(mixed $event): void
    {
        try {
            $data = $this->extractPayload($event);
            $userId = int_value($data['user_id'] ?? 0);

            if (!$userId) {
                $this->logger->warning('influencer.profile_verified event missing user_id', $data);
                return;
            }

            // Award verification bonus XP
            $this->awardXp($userId, ModuleContext::YOUTUBE_TASKS, 5.0, "influencer_verified");

            // Notify user
            $this->notifyUser(
                $userId,
                'تایید پروفایل اینفلوئنسر',
                "پروفایل اینفلوئنسری شما تایید شد. اکنون می‌توانید سفارشات دریافت کنید."
            );

        } catch (\Throwable $e) {
            \App\Services\Sentry\SentryExceptionHandler::captureException($e, null, ['operation' => 'app.Listeners.InfluencerEventListeners.handleInfluencerProfileVerified']);
            $this->logger->error('influencer.profile_verified handler failed', [
                'error' => $e->getMessage(),
                'event' => $event,
            ]);
        }
    }

    // ========== HELPER METHODS ==========

    /**
     * Award XP to a user
     */
    private function awardXp(int $userId, ModuleContext $context, float $amount, string $idempotencyKey): void
    {
        try {
            $userService = $this->container->make('App\Services\User\UserService');
            $user = $userService->findById($userId);

            if (!$user) {
                return;
            }

            $userIdVal = (int)($user->id ?? $userId);
            $xpService = $this->container->make('App\Services\Gamification\XpService');
            $xpService->award($userIdVal, $context, $amount, $idempotencyKey);

        } catch (\Throwable $e) {
            \App\Services\Sentry\SentryExceptionHandler::captureException($e, null, ['operation' => 'app.Listeners.InfluencerEventListeners.awardXp']);
            $this->logger->warning('XP award failed', [
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Apply reputation delta to influencer profile
     *
     * @param array<string, mixed> $metadata
     */
    private function applyReputationDelta(int $profileId, int $delta, string $reason, array $metadata = []): void
    {
        try {
            $scoreService = $this->container->make('App\Services\ScoreService');
            $scoreService->applyDelta('profile', $profileId, 'reputation', $delta, $reason, $metadata);

        } catch (\Throwable $e) {
            \App\Services\Sentry\SentryExceptionHandler::captureException($e, null, ['operation' => 'app.Listeners.InfluencerEventListeners.applyReputationDelta']);
            $this->logger->warning('reputation score update failed', [
                'profile_id' => $profileId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Deposit to wallet
     *
     * @param array<string, mixed> $metadata
     */
    private function depositToWallet(int $userId, float $amount, string $currency, array $metadata = []): void
    {
        try {
            $walletService = $this->container->make('App\Contracts\WalletServiceInterface');

            $outbox = null;
            try {
                $outbox = $this->container->make(\App\Services\OutboxService::class);
            } catch (\Throwable $e) {
                \App\Services\Sentry\SentryExceptionHandler::captureException($e, null, ['operation' => 'app.Listeners.InfluencerEventListeners.depositToWallet']);
                // no outbox available
            }

            $aggregateValue = $metadata['order_id'] ?? $userId;
            $aggId = is_int($aggregateValue) || is_string($aggregateValue) ? $aggregateValue : $userId;
            $idemKey = ($metadata['order_id'] ?? $userId) . ':influencer_deposit';
            $payload = [
                'user_id' => $userId,
                'amount' => (string)$amount,
                'currency' => $currency,
                'metadata' => array_merge($metadata, ['idempotency_key' => $idemKey]),
            ];

            if ($outbox) {
                $ok = $outbox->record('influencer', $aggId, 'wallet.deposit.requested', $payload);
                if (empty($ok)) {
                    $this->logger->error('influencer.deposit outbox record failed', ['user_id' => $userId, 'amount' => $amount]);
                }
                return;
            }

            $result = $this->idempotencyService->executeWithTransaction(
                'wallet.deposit',
                $userId,
                $payload,
                function () use ($walletService, $userId, $amount, $currency, $payload) {
                    return $walletService->deposit($userId, (string)$amount, $currency, $payload['metadata']);
                },
                (is_string($payload['metadata']['idempotency_key'] ?? null) ? $payload['metadata']['idempotency_key'] : null)
            );

            if (empty($result['success'])) {
                $this->logger->error('wallet deposit failed', [
                    'user_id' => $userId,
                    'amount' => $amount,
                    'error' => $result['message'] ?? 'Unknown error',
                ]);
            }

        } catch (\Throwable $e) {
            \App\Services\Sentry\SentryExceptionHandler::captureException($e, null, ['operation' => 'app.Listeners.InfluencerEventListeners.depositToWallet']);
            $this->logger->error('wallet deposit exception', [
                'user_id' => $userId,
                'amount' => $amount,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Invalidate wallet cache for a user
     */
    /** @return array<string, mixed> */
    private function extractPayload(mixed $event): array
    {
        if ($event instanceof \Core\Event) return $event->getData();
        if (is_object($event) && method_exists($event, 'getData')) {
            $data = $event->getData();
            return is_array($data) ? $data : [];
        }
        if (!is_array($event)) return [];
        $data = $event['data'] ?? $event;
        return is_array($data) ? $data : [];
    }

    private function invalidateWalletCache(?int $userId): void
    {
        if (!$userId) {
            return;
        }

        try {
            $cacheService = $this->container->make('App\Services\Cache\CacheInvalidationService');
            $cacheService->invalidateWallet($userId);
        } catch (\Throwable $e) {
            \App\Services\Sentry\SentryExceptionHandler::captureException($e, null, ['operation' => 'app.Listeners.InfluencerEventListeners.invalidateWalletCache']);
            $this->logger->warning('wallet cache invalidation failed', [
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Send notification to user
     */
    private function notifyUser(int $userId, string $title, string $message, string $type = 'influencer_update'): void
    {
        try {
            $notificationService = $this->container->make('App\Contracts\NotificationServiceInterface');
            $notificationService->send($userId, $type, $title, $message);
        } catch (\Throwable $e) {
            \App\Services\Sentry\SentryExceptionHandler::captureException($e, null, ['operation' => 'app.Listeners.InfluencerEventListeners.notifyUser']);
            $this->logger->warning('notification sending failed', [
                'user_id' => $userId,
                'title' => $title,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
