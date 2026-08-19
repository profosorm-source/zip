<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Contracts\LoggerInterface;
use App\Contracts\WalletServiceInterface;
use App\Services\OutboxService;
use App\Services\Shared\IdempotencyService;
use Core\EventDispatcher;
use Core\Container;
use Core\Database;

class WalletDepositRequestListener
{
    private WalletServiceInterface $walletService;
    private LoggerInterface $logger;
    private OutboxService $outbox;
    private IdempotencyService $idempotencyService;
    private \Core\Database $db;

    public function __construct(
        WalletServiceInterface $walletService,
        LoggerInterface $logger,
        OutboxService $outbox,
        IdempotencyService $idempotencyService,
        \Core\Database $db
    ) {
        $this->walletService = $walletService;
        $this->logger = $logger;
        $this->outbox = $outbox;
        $this->idempotencyService = $idempotencyService;
        $this->db = $db;
    }

    public function handle(mixed $event): void
    {
        $payload = [];

        if ($event instanceof \Core\Event) {
            $payload = (array)$event->getData();
        } elseif (is_array($event)) {
            $payload = $event;
        }

        $userId = isset($payload['user_id']) ? (int)$payload['user_id'] : null;
        $amount = isset($payload['amount']) ? (string)$payload['amount'] : null;
        $currency = isset($payload['currency']) ? (string)$payload['currency'] : 'irt';
        $metadata = isset($payload['metadata']) && is_array($payload['metadata']) ? $payload['metadata'] : [];

        $retryCount = isset($metadata['retry_count']) ? (int)$metadata['retry_count'] : 0;

        if (empty($userId) || $amount === null) {
            $this->logger->warning('wallet.deposit.async.invalid_payload', [
                'payload' => $payload,
            ]);
            return;
        }

        if ($this->shouldSkipDuplicateContentRevenueDeposit($payload, $metadata, (int)$userId)) {
            return;
        }

        try {
            $depositPayload = [
                'user_id' => $userId,
                'amount' => $amount,
                'currency' => $currency,
                'metadata' => $metadata,
            ];

            $depositResult = $this->idempotencyService->executeWithTransaction(
                'wallet.deposit',
                $userId,
                $depositPayload,
                function () use ($userId, $amount, $currency, $metadata) {
                    return $this->walletService->deposit($userId, $amount, $currency, $metadata);
                },
                $metadata['idempotency_key'] ?? null
            );
            if (empty($depositResult['success'])) {
                $this->logger->warning('wallet.deposit.async.failed', [
                    'user_id' => $userId,
                    'amount' => $amount,
                    'currency' => $currency,
                    'metadata' => $metadata,
                    'result' => $depositResult,
                ]);

                if ($retryCount < 3) {
                    $retryCount++;
                    $metadata['retry_count'] = $retryCount;
                    $payload = [
                        'user_id' => $userId,
                        'amount' => $amount,
                        'currency' => $currency,
                        'metadata' => $metadata,
                    ];
                    $this->logger->info('wallet.deposit.async.retrying', ['attempt' => $retryCount, 'user_id' => $userId]);
                    $this->outbox->record('wallet', (string)$userId, 'wallet.deposit.requested', $payload);
                } else {
                    try {
                        $this->outbox->record('wallet', (string)$userId, 'wallet.deposit.requested', [
                            'user_id' => $userId,
                            'amount' => $amount,
                            'currency' => $currency,
                            'metadata' => $metadata,
                            'last_result' => $depositResult,
                        ]);
                    } catch (\Throwable $e) {
                        \App\Services\Sentry\SentryExceptionHandler::captureException($e, null, ['operation' => 'app.Listeners.WalletDepositRequestListener.handle']);
                        $this->logger->error('wallet.deposit.async.outbox_failed', ['error' => $e->getMessage()]);
                    }
                    $this->logger->error('wallet.deposit.async.dead_lettered', ['user_id' => $userId, 'amount' => $amount]);
                }
            } else {
                $this->logger->info('wallet.deposit.async.completed', [
                    'user_id' => $userId,
                    'amount' => $amount,
                    'currency' => $currency,
                    'metadata' => $metadata,
                ]);
            }
        } catch (\Throwable $e) {
            \App\Services\Sentry\SentryExceptionHandler::captureException($e, null, ['operation' => 'app.Listeners.WalletDepositRequestListener.handle']);
            $this->logger->error('wallet.deposit.async.exception', [
                'user_id' => $userId,
                'amount' => $amount,
                'currency' => $currency,
                'metadata' => $metadata,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            if ($retryCount < 3) {
                $retryCount++;
                $metadata['retry_count'] = $retryCount;
                $payload = [
                    'user_id' => $userId,
                    'amount' => $amount,
                    'currency' => $currency,
                    'metadata' => $metadata,
                ];
                $this->logger->info('wallet.deposit.async.retrying_after_exception', ['attempt' => $retryCount, 'user_id' => $userId]);
                $this->outbox->record('wallet', (string)$userId, 'wallet.deposit.requested', $payload);
            } else {
                try {
                    $this->outbox->record('wallet', (string)$userId, 'wallet.deposit.requested', [
                        'user_id' => $userId,
                        'amount' => $amount,
                        'currency' => $currency,
                        'metadata' => $metadata,
                        'error' => $e->getMessage(),
                    ]);
                } catch (\Throwable $oe) {
                    \App\Services\Sentry\SentryExceptionHandler::captureException($oe, null, ['operation' => 'app.Listeners.WalletDepositRequestListener.handle']);
                    $this->logger->error('wallet.deposit.async.outbox_failed', ['error' => $oe->getMessage()]);
                }
                $this->logger->error('wallet.deposit.async.dead_lettered_exception', ['user_id' => $userId, 'error' => $e->getMessage()]);
            }
        }
    }
    /**
     * محافظ سازگاری برای outboxهای legacy محتوا.
     *
     * از فاز ۲ Content، پرداخت درآمد از مسیر ContentService::payRevenue انجام می‌شود
     * و همان‌جا transaction_id روی content_revenues ثبت می‌گردد. اگر یک outbox قدیمی
     * از نوع wallet.deposit.requested بعد از پرداخت مستقیم پردازش شود، این guard مانع
     * واریز دوباره به کیف پول می‌شود. اگر رکورد درآمد هنوز transaction ندارد، مسیر
     * legacy را بلاک نمی‌کند تا داده‌های قدیمیِ پرداخت‌نشده کور نشوند.
     */
    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $metadata
     */
    private function shouldSkipDuplicateContentRevenueDeposit(array $payload, array $metadata, int $userId): bool
    {
        $type = str_value($metadata['type'] ?? $payload['type'] ?? '');
        $idempotencyKey = str_value($metadata['idempotency_key'] ?? $payload['idempotency_key'] ?? '');
        $revenueId = int_value($metadata['revenue_id'] ?? $metadata['reference_id'] ?? $payload['revenue_id'] ?? $payload['reference_id'] ?? 0);

        if ($type !== 'content_revenue' && !str_starts_with($idempotencyKey, 'content_revenue_payment_')) {
            return false;
        }

        if (str_starts_with($idempotencyKey, 'content_revenue_payment_')) {
            $this->logger->warning('wallet.deposit.async.content_revenue_direct_payment_event_skipped', [
                'user_id' => $userId,
                'revenue_id' => $revenueId ?: null,
                'idempotency_key' => $idempotencyKey,
                'reason' => 'Content revenue direct-payment idempotency key must not be processed through wallet.deposit.requested',
            ]);
            return true;
        }

        if ($revenueId <= 0) {
            return false;
        }

        try {
            $db = $this->db;
            $revenue = $db->fetch("SELECT id, status, transaction_id FROM content_revenues WHERE id = ? LIMIT 1", [$revenueId]);

            if ($revenue && !empty($revenue->transaction_id)) {
                $this->logger->warning('wallet.deposit.async.content_revenue_duplicate_skipped', [
                    'user_id' => $userId,
                    'revenue_id' => $revenueId,
                    'transaction_id' => $revenue->transaction_id,
                    'reason' => 'content revenue already has a direct wallet transaction',
                ]);
                return true;
            }

            // L-12 FIX: the old prefix wildcard ('%content_revenue_payment_1%') also matched
            // unrelated keys such as content_revenue_payment_15, so a legitimate small revenue
            // deposit could be rejected as a duplicate. `transactions.idempotency_key` is a real
            // UNIQUE-indexed column (populated by WalletMutationService), so the duplicate check
            // is an exact, index-backed equality instead of a substring/JSON scan.
            $existingTx = (int)$db->fetchColumn(
                "SELECT COUNT(*) FROM transactions WHERE user_id = ? AND idempotency_key = ?",
                [$userId, 'content_revenue_payment_' . $revenueId]
            );

            if ($existingTx > 0) {
                $this->logger->warning('wallet.deposit.async.content_revenue_duplicate_tx_skipped', [
                    'user_id' => $userId,
                    'revenue_id' => $revenueId,
                    'reason' => 'direct content revenue transaction already exists',
                ]);
                return true;
            }
        } catch (\Throwable $e) {
            \App\Services\Sentry\SentryExceptionHandler::captureException($e, null, ['operation' => 'app.Listeners.WalletDepositRequestListener.shouldSkipDuplicateContentRevenueDeposit']);
            $this->logger->error('wallet.deposit.async.content_revenue_guard_failed', [
                'user_id' => $userId,
                'revenue_id' => $revenueId,
                'error' => $e->getMessage(),
            ]);
        }

        return false;
    }

}

