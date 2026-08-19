<?php

declare(strict_types=1);

namespace App\Jobs\Vitrine;

use App\Contracts\LoggerInterface;
use App\Contracts\OutboxServiceInterface;
use App\Services\EscrowService;
use Core\Database;
use Core\EventDispatcher;

/**
 * ProcessExpiredVitrineEscrowsJob
 *
 * پردازش اسکروهای منقضی شده ویترین - وجه را به خریدار برمی‌گرداند
 * و وضعیت اسکرو را به refunded تغییر می‌دهد.
 */
class ProcessExpiredVitrineEscrowsJob
{
    private Database $db;
    private LoggerInterface $logger;
    private EventDispatcher $eventDispatcher;
    private ?OutboxServiceInterface $outbox;

    public function __construct(
        Database $db,
        LoggerInterface $logger,
        EventDispatcher $eventDispatcher,
        ?OutboxServiceInterface $outbox = null
    ) {
        $this->eventDispatcher = $eventDispatcher;
        $this->db = $db;
        $this->logger = $logger;
        $this->outbox = $outbox;
    }

    /** @return array<string, mixed> */
public function handle(): array
    {
        $processed = 0;
        $failed = 0;

        try {
            // یافتن اسکروهای منقضی شده ویترین
            $stmt = $this->db->query(
                "SELECT e.*, l.expires_at 
                 FROM escrow_transactions e
                 JOIN vitrine_listings l ON l.id = e.order_id
                 WHERE e.order_type = 'vitrine_listing'
                   AND e.status = 'in_escrow'
                   AND l.expires_at < NOW()
                   AND l.expires_at IS NOT NULL
                 ORDER BY l.expires_at ASC
                 LIMIT 50"
            );

            $expiredEscrows = $stmt->fetchAll(\PDO::FETCH_OBJ);

            foreach ($expiredEscrows as $escrow) {
                try {
                    $financialEscrow = app(\App\Domain\Financial\Services\FinancialEscrowService::class);
                    $result = $financialEscrow->refundEscrowToBuyer(
                        (int) $escrow->id,
                        (int) $escrow->buyer_id,
                        'انقضای آگهی ویترین و استرداد وجه',
                        'system_cron',
                        'vitrine_expiry_refund:' . (int)$escrow->id
                    );

                    if ($result['ok'] ?? false) {
                        $processed++;

                        if ($this->outbox) {
                            $this->outbox->record('vitrine', (int) $escrow->order_id, 'vitrine.escrow_expired', [
                                'escrow_id' => $escrow->id,
                                'buyer_id' => $escrow->buyer_id,
                                'amount' => $escrow->amount,
                            ]);
                        } else {
                            $this->eventDispatcher->dispatch('vitrine.escrow_expired', [
                                'escrow_id' => $escrow->id,
                                'buyer_id' => $escrow->buyer_id,
                                'amount' => $escrow->amount,
                            ]);
                        }
                    } else {
                        $failed++;
                    }
                } catch (\Throwable $e) {
                    $failed++;
                    $this->logger->error('vitrine.escrow_expiry_failed', [
                        'escrow_id' => $escrow->id,
                        'error' => $e->getMessage()
                    ]);
                }
            }

            $this->logger->info('vitrine.expired_escrows_processed', [
                'processed' => $processed,
                'failed' => $failed,
            ]);

            return [
                'success' => true,
                'processed' => $processed,
                'failed' => $failed,
            ];
        } catch (\Throwable $e) {
            $this->logger->error('vitrine.expired_escrows_job_failed', [
                'error' => $e->getMessage(),
            ]);
            return ['success' => false, 'message' => 'خطای سیستمی: ' . $e->getMessage()];
        }
    }
}
