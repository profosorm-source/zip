<?php

declare(strict_types=1);

namespace App\Commands;

use App\Domain\Financial\Services\FinancialEscrowService;
use Core\Database;
use App\Contracts\LoggerInterface;

/**
 * Command: EscrowCleanupCommand - Automatically cleanup/refund expired escrows
 * 
 * Usage: php app.php escrow:cleanup
 */
class EscrowCleanupCommand
{
    private FinancialEscrowService $financialEscrowService;
    private LoggerInterface $logger;

    public function __construct(
        FinancialEscrowService $financialEscrowService,
        Database $db,
        LoggerInterface $logger
    ) {
        $this->financialEscrowService = $financialEscrowService;
        unset($db);
        $this->logger = $logger;
    }

    /** @param array<string, mixed> $argv */

    public function run(array $argv = []): void
    {
        $this->handle();
    }

    public function handle(): int
    {
        $this->logger->info('command.escrow_cleanup.starting');
        try {
            $released = $this->financialEscrowService->releaseExpiredHolds();
            $this->logger->info('command.escrow_cleanup.completed', ['released' => $released]);
            return 0;
        } catch (\Throwable $e) {
            $this->logger->error('command.escrow_cleanup.failed', ['error' => $e->getMessage()]);
            return 1;
        }
    }
}
