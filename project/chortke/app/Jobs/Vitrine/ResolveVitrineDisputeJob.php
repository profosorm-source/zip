<?php

declare(strict_types=1);

namespace App\Jobs\Vitrine;

use App\Models\VitrineListing;
use App\Services\VitrineService;
use Core\Database;
use App\Contracts\OutboxServiceInterface;
use Core\EventDispatcher;
use App\Contracts\LoggerInterface;

class ResolveVitrineDisputeJob
{
    private Database $db;
    private LoggerInterface $logger;
    private VitrineListing $listing;
    private VitrineService $vitrineService;
    private EventDispatcher $eventDispatcher;
    private \App\Services\SagaOrchestrator $sagaOrchestrator;
    private ?OutboxServiceInterface $outbox;
    private ?\App\Services\EscrowService $escrowService;

    public function __construct(
        Database $db,
        LoggerInterface $logger,
        VitrineListing $listing,
        VitrineService $vitrineService,
        EventDispatcher $eventDispatcher,
        \App\Services\SagaOrchestrator $sagaOrchestrator,
        ?OutboxServiceInterface $outbox = null,
        ?\App\Services\EscrowService $escrowService = null
    ) {
        $this->eventDispatcher = $eventDispatcher;
        $this->db = $db;
        $this->logger = $logger;
        $this->listing = $listing;
        $this->vitrineService = $vitrineService;
        $this->sagaOrchestrator = $sagaOrchestrator;
        $this->outbox = $outbox;
        $this->escrowService = $escrowService;
    }

    /** @return array<string, mixed> */
public function handle(int $listingId, string $winner, int $adminId): array
    {
        $listing = $this->listing->find($listingId);
        if (!$listing) return ['success' => false, 'message' => 'آگهی یافت نشد.'];

        // M-02 FIX (root cause): the job accepted ANY listing state and any $winner value,
        // so an already sold/cancelled listing could be “resolved” again (double settlement)
        // and a malformed decision fell through to the buyer-refund branch. Validate the
        // decision domain and the listing state before touching money.
        if (!in_array($winner, ['seller', 'buyer'], true)) {
            $this->logger->warning('vitrine.dispute_resolve.invalid_decision', [
                'listing_id' => $listingId,
                'winner'     => $winner,
                'admin_id'   => $adminId,
            ]);
            return ['success' => false, 'message' => 'نتیجهٔ حل اختلاف نامعتبر است.'];
        }

        $resolvableStatuses = [
            \App\Models\VitrineListing::STATUS_DISPUTED,
            \App\Models\VitrineListing::STATUS_IN_ESCROW,
        ];
        if (!in_array((string)$listing->status, $resolvableStatuses, true)) {
            $this->logger->warning('vitrine.dispute_resolve.invalid_state', [
                'listing_id' => $listingId,
                'status'     => (string)$listing->status,
                'admin_id'   => $adminId,
            ]);
            return ['success' => false, 'message' => 'این آگهی در وضعیت قابل حل‌اختلاف نیست.'];
        }

        if ($winner === 'seller') {
            $result = $this->vitrineService->releaseFundsToSeller($listing, 'admin_resolve');
        } else {
            $amount = $listing->offer_price_usdt ?? $listing->price_usdt;
            $this->db->beginTransaction();
            try {
                $saga = $this->sagaOrchestrator;
                $saga->setSaga('vitrine_resolve_dispute', ['listing_id' => $listingId]);

                $escrowService = $this->escrowService ?? app(\App\Services\EscrowService::class);

                $saga->addStep(
                    'refund_buyer',
                    function () use ($listing, $amount, $listingId, $escrowService) {
                        $escrow = $escrowService->getByOrder((string)$listingId, 'vitrine_listing');
                        if ($escrow) {
                            $financialEscrow = app(\App\Domain\Financial\Services\FinancialEscrowService::class);
                            $refund = $financialEscrow->refundEscrowToBuyer(
                                (int)$escrow->id,
                                (int)$listing->buyer_id,
                                'حل اختلاف به نفع خریدار',
                                'admin',
                                'vitrine_dispute_refund:' . (int)$escrow->id
                            );
                            if (empty($refund['ok'])) {
                                throw new \RuntimeException(str_value($refund['error'] ?? 'بازگشت وجه اختلاف ویترین ناموفق بود'));
                            }
                        }

                        if ($this->outbox) {
                            $this->outbox->record('vitrine', $listingId, 'vitrine.refund_requested', [
                                'buyer_id'   => (int)$listing->buyer_id,
                                'amount'     => $amount,
                                'listing_id' => $listingId,
                            ]);
                        } else {
                            $this->eventDispatcher->dispatch('vitrine.refund_requested', [
                                'buyer_id'   => (int)$listing->buyer_id,
                                'amount'     => $amount,
                                'listing_id' => $listingId,
                            ]);
                        }
                        return true;
                    },
                    function (\Throwable $e) use ($listingId) {
                        $this->logger->warning('saga.compensating.vitrine_refund_buyer', ['listing_id' => $listingId]);
                    }
                )->addStep(
                    'cancel_listing',
                    function () use ($listingId, $resolvableStatuses) {
                        // M-02 FIX: compare-and-swap instead of an unconditional overwrite, so a
                        // concurrent resolution/settlement cannot be clobbered silently.
                        $affected = $this->db->execute(
                            "UPDATE vitrine_listings SET status = ?, updated_at = NOW()
                              WHERE id = ? AND status IN (?, ?)",
                            [
                                \App\Models\VitrineListing::STATUS_CANCELLED,
                                $listingId,
                                $resolvableStatuses[0],
                                $resolvableStatuses[1],
                            ]
                        );
                        if ($affected !== 1) {
                            throw new \RuntimeException('وضعیت آگهی در حین حل اختلاف توسط فرایند دیگری تغییر کرد.');
                        }
                        return true;
                    },
                    function () {}
                );

                $saga->execute();
                $this->db->commit();
            } catch (\Throwable $e) {
                if ($this->db->inTransaction()) {
                    $this->db->rollback();
                }
                $this->logger->error('vitrine.dispute_resolve_failed', [
                    'listing_id' => $listingId,
                    'winner'     => $winner,
                    'admin_id'   => $adminId,
                    'error'      => $e->getMessage(),
                ]);
                return ['success' => false, 'message' => 'خطای سیستمی.'];
            }

            $result = ['success' => true];
        }

        if ($this->outbox) {
            $this->outbox->record('vitrine', $listingId, 'vitrine.dispute_resolved', [
                'admin_id'   => $adminId,
                'listing_id' => $listingId,
                'decision'   => $winner,
            ]);
        } else {
            $this->eventDispatcher->dispatch('vitrine.dispute_resolved', [
                'admin_id'   => $adminId,
                'listing_id' => $listingId,
                'decision'   => $winner,
            ]);
        }

        $this->logger->info('vitrine.dispute_resolved', [
            'listing_id' => $listingId,
            'winner'     => $winner,
            'admin_id'   => $adminId,
        ]);

        return $result;
    }
}
