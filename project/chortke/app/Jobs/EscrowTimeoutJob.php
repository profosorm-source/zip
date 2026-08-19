<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Contracts\LoggerInterface;
use App\Contracts\OutboxServiceInterface;
use App\Domain\Financial\Services\FinancialEscrowService;
use Core\EventDispatcher;

class EscrowTimeoutJob
{
    private FinancialEscrowService $escrowService;
    private LoggerInterface $logger;
    private EventDispatcher $eventDispatcher;
    private ?OutboxServiceInterface $outbox;

    public function __construct(
        FinancialEscrowService $escrowService,
        LoggerInterface $logger,
        EventDispatcher $eventDispatcher,
        ?OutboxServiceInterface $outbox = null
    ) {
        $this->eventDispatcher = $eventDispatcher;
        $this->escrowService = $escrowService;
        $this->logger = $logger;
        $this->outbox = $outbox;
    }

    /** @param array<string, mixed> $data */
    /** @param array<string, mixed> $data */
public function handle(array $data = []): void
    {
        try {
            $released = $this->escrowService->releaseExpiredHolds();
            if ($released > 0) {
                $this->logger->info('escrow.timeout_released', ['released' => $released]);
                // escrow.auto_released از طریق Outbox — تضمین delivery
                if ($this->outbox) {
                    try { $this->outbox->record('escrow', 'auto', 'escrow.auto_released', ['count' => $released, 'timestamp' => date('Y-m-d H:i:s')]); } catch (\Throwable $e) {}
                } else {
                    try { $this->eventDispatcher->dispatch('escrow.auto_released', ['count' => $released, 'timestamp' => date('Y-m-d H:i:s')]); } catch (\Throwable $e) {}
                }
                // cache.invalidate — internal، مستقیم dispatch کافی است
                try { $this->eventDispatcher->dispatch('cache.invalidate', ['key' => 'escrow']); } catch (\Throwable $e) {}
            } else {
                $this->logger->info('escrow.timeout_nothing_to_release', []);
            }
        } catch (\Throwable $e) {
            $this->logger->error('escrow.timeout_failed', ['error' => $e->getMessage()]);
            \App\Services\Sentry\SentryExceptionHandler::captureException($e, null, [
                'operation' => 'escrow.timeout_job',
            ]);
        }
    }
}
