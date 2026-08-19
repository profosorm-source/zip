<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\EscrowReleasedEvent;
use App\Contracts\WalletServiceInterface;
use App\Services\Shared\IdempotencyService;
use App\Services\Notification\NotificationService;
use App\Services\AuditTrail;
use App\Contracts\LoggerInterface;
use Core\Container;

/**
 * EscrowListener - Handles escrow release events
 * 
 * Decouples escrow management from:
 * - Wallet operations
 * - User notifications
 * - Audit logging
 * - Ledger updates
 */
class EscrowListener
{
    private Container $container;
    private LoggerInterface $logger;

    public function __construct(Container $container, LoggerInterface $logger) {
        $this->container = $container;
        $this->logger = $logger;
    }

    /**
     * Handle escrow.released event
     * 
     * Does not mutate wallets: settlement already happened transactionally.
     * Writes audit/notification projections only
     */
    public function handle(EscrowReleasedEvent $event): void
    {
        try {
            $rawData = $event->getData();
            $data = is_array($rawData) ? $rawData : [];

            // FIX: Listener may run from HTTP request OR CLI/Job context.
            // app()->request is only valid in HTTP context.
            $correlationId = null;
            if (PHP_SAPI !== 'cli' && function_exists('app')) {
                try { $correlationId = app()->request->header('x-request-id'); } catch (\Throwable $e) {
                    \App\Services\Sentry\SentryExceptionHandler::captureException($e, null, ['operation' => 'app.Listeners.EscrowListener.handle']);
            $this->logger->warning('escrowlistener.operation_failed', ['error' => $e->getMessage()]);
        }
            }
            $correlationId = $correlationId ?? ($data['correlation_id'] ?? 'cli-' . bin2hex(random_bytes(4)));

            $escrowId = int_value($data['escrow_id'] ?? 0);
            $recipientId = int_value($data['recipient_id'] ?? $data['seller_id'] ?? $data['user_id'] ?? 0);
            $amount = str_value($data['amount'] ?? 0);
            $currency = str_value($data['currency'] ?? 'irt');

            if (!$escrowId || !$recipientId) {
                $this->logger->warning('escrow.released event missing required data', $data);
                return;
            }

            // Financial settlement is synchronous and transactional in FinancialEscrowService/EscrowService.
            // This listener is deliberately side-effect free for balances: async delivery must never pay twice.
            // Log to audit trail
            $auditTrail = $this->container->make(AuditTrail::class);
            $auditTrail->logEvent([
                'user_id' => $recipientId,
                'action' => 'escrow.released',
                'resource_id' => $escrowId,
                'metadata' => [
                    'amount' => $amount,
                    'currency' => $currency,
                    'wallet_transaction_id' => null,
                    'correlation_id' => $correlationId,
                ]
            ]);

            // Notification is asynchronous through the outbox. Financial payout
            // is intentionally absent from this listener; it already happened in
            // the settlement transaction.
            $outbox = $this->container->make(\App\Contracts\OutboxServiceInterface::class);
            $outbox->record('escrow', int_value($escrowId), 'notification.requested', [
                'user_id' => int_value($recipientId),
                'type' => 'system',
                'title' => 'وجه امانی تسویه شد',
                'message' => "مبلغ $amount $currency از escrow #$escrowId تسویه شد.",
                'data' => [
                    'escrow_id' => $escrowId,
                    'amount' => $amount,
                    'currency' => $currency,
                    'correlation_id' => $correlationId,
                ],
            ]);

        } catch (\Throwable $e) {
            \App\Services\Sentry\SentryExceptionHandler::captureException($e, null, ['operation' => 'app.Listeners.EscrowListener.handle']);
            $this->logger->error('escrow.released listener failed', [
                'error' => $e->getMessage(),
                'event' => $event->getData()
            ]);
        }
    }
}
