<?php

declare(strict_types=1);

namespace App\Jobs\Vitrine;

use App\Models\VitrineListing;
use App\Contracts\OutboxServiceInterface;
use Core\EventDispatcher;
use App\Contracts\LoggerInterface;

/**
 * AdminApproveVitrineListingJob
 *
 * تأیید آگهی ویترین توسط ادمین
 * وضعیت: pending → active
 */
class AdminApproveVitrineListingJob
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
public function handle(int $listingId, int $adminId): array
    {
        $listing = $this->listing->find($listingId);
        if (!$listing) {
            return ['success' => false, 'message' => 'آگهی یافت نشد.'];
        }

        if ($listing->status !== VitrineListing::STATUS_PENDING) {
            return ['success' => false, 'message' => 'این آگهی در وضعیت قابل تایید نیست.'];
        }

        try {
            $this->listing->updateStatus($listingId, VitrineListing::STATUS_ACTIVE);

            if ($this->outbox) {
                $this->outbox->record('vitrine', $listingId, 'vitrine.listing_approved', [
                    'listing_id' => $listingId,
                    'seller_id'  => (int)$listing->seller_id,
                    'admin_id'   => $adminId,
                ]);
            } else {
                $this->eventDispatcher->dispatch('vitrine.listing_approved', [
                    'listing_id' => $listingId,
                    'seller_id'  => (int)$listing->seller_id,
                    'admin_id'   => $adminId,
                ]);
            }

            $this->logger->info('vitrine.admin_approved', [
                'listing_id' => $listingId,
                'admin_id'   => $adminId,
            ]);

            return ['success' => true, 'message' => 'آگهی با موفقیت تایید شد.'];
        } catch (\Throwable $e) {
            $this->logger->error('vitrine.admin_approve_failed', [
                'listing_id' => $listingId,
                'error'      => $e->getMessage(),
            ]);
            return ['success' => false, 'message' => 'خطا در تایید آگهی: ' . $e->getMessage()];
        }
    }
}
