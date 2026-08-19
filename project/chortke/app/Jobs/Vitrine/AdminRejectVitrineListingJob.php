<?php

declare(strict_types=1);

namespace App\Jobs\Vitrine;

use App\Models\VitrineListing;
use App\Contracts\OutboxServiceInterface;
use Core\EventDispatcher;
use App\Contracts\LoggerInterface;

/**
 * AdminRejectVitrineListingJob
 *
 * رد آگهی ویترین توسط ادمین
 * وضعیت: pending → rejected
 */
class AdminRejectVitrineListingJob
{
    private VitrineListing $listing;
    private EventDispatcher $eventDispatcher;
    private LoggerInterface $logger;
    private ?OutboxServiceInterface $outbox;

    public function __construct(
        VitrineListing $listing,
        EventDispatcher $eventDispatcher,
        LoggerInterface $logger,
        ?OutboxServiceInterface $outbox = null
    ) {
        $this->eventDispatcher = $eventDispatcher;
        $this->listing = $listing;
        $this->logger = $logger;
        $this->outbox = $outbox;
    }

    /** @return array<string, mixed> */
public function handle(int $listingId, string $reason, int $adminId): array
    {
        $listing = $this->listing->find($listingId);
        if (!$listing) {
            return ['success' => false, 'message' => 'آگهی یافت نشد.'];
        }

        if ($listing->status !== VitrineListing::STATUS_PENDING) {
            return ['success' => false, 'message' => 'این آگهی در وضعیت قابل رد کردن نیست.'];
        }

        try {
            $this->listing->updateStatus($listingId, VitrineListing::STATUS_REJECTED);

            if ($this->outbox) {
                $this->outbox->record('vitrine', $listingId, 'vitrine.listing_rejected', [
                    'listing_id' => $listingId,
                    'seller_id'  => (int)$listing->seller_id,
                    'reason'     => $reason,
                ]);
            } else {
                $this->eventDispatcher->dispatch('vitrine.listing_rejected', [
                    'listing_id' => $listingId,
                    'seller_id'  => (int)$listing->seller_id,
                    'reason'     => $reason,
                ]);
            }

            $this->logger->info('vitrine.admin_rejected', [
                'listing_id' => $listingId,
                'admin_id'   => $adminId,
                'reason'     => $reason,
            ]);

            return ['success' => true, 'message' => 'آگهی با موفقیت رد شد.'];
        } catch (\Throwable $e) {
            $this->logger->error('vitrine.admin_reject_failed', [
                'listing_id' => $listingId,
                'error'      => $e->getMessage(),
            ]);
            return ['success' => false, 'message' => 'خطا در رد آگهی: ' . $e->getMessage()];
        }
    }
}
