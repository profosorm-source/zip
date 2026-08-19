<?php

declare(strict_types=1);

namespace App\Jobs\Vitrine;

use App\Models\VitrineListing;
use App\Models\VitrineRequest;
use App\Contracts\OutboxServiceInterface;
use Core\EventDispatcher;
use App\Contracts\LoggerInterface;

/**
 * AcceptVitrineRequestJob
 *
 * پذیرش درخواست خرید توسط فروشنده
 * درخواست: pending → accepted
 * آگهی: active → in_escrow
 * خریدار = requester
 */
class AcceptVitrineRequestJob
{
    private VitrineListing $listing;
    private VitrineRequest $request;
    private EventDispatcher $eventDispatcher;
    private LoggerInterface $logger;
    private ?OutboxServiceInterface $outbox;

    public function __construct(
        VitrineListing $listing,
        VitrineRequest $request,
        EventDispatcher $eventDispatcher,
        LoggerInterface $logger,
        ?OutboxServiceInterface $outbox = null
    ) {
        $this->eventDispatcher = $eventDispatcher;
        $this->listing = $listing;
        $this->request = $request;
        $this->logger = $logger;
        $this->outbox = $outbox;
    }

    /** @return array<string, mixed> */
public function handle(int $sellerId, int $requestId): array
    {
        $req = $this->request->findById($requestId);
        if (!$req) {
            return ['success' => false, 'message' => 'درخواست یافت نشد.'];
        }

        if ((int) $req->seller_id !== $sellerId) {
            return ['success' => false, 'message' => 'دسترسی غیرمجاز. فقط فروشنده می‌تواند درخواست را بپذیرد.'];
        }

        if ($req->status !== VitrineRequest::STATUS_PENDING) {
            return ['success' => false, 'message' => 'این درخواست قبلاً پردازش شده است.'];
        }

        $listing = $this->listing->find((int) $req->listing_id);
        if (!$listing || $listing->status !== VitrineListing::STATUS_ACTIVE) {
            return ['success' => false, 'message' => 'آگهی در وضعیت قابل پذیرش نیست.'];
        }

        $db = \Core\Database::getInstance();

        try {
            // M-03 FIX (root cause): the pending/active checks above are only a read; the three
            // writes then ran unconditionally and without a transaction, so two sellers’ requests
            // (or a double-submitted accept) could both move the same listing to in_escrow and the
            // last writer silently overwrote the buyer. Both transitions are now atomic
            // compare-and-swap statements inside one transaction, so exactly one accept wins.
            $db->beginTransaction();

            $requestClaimed = $db->execute(
                "UPDATE vitrine_requests SET status = ?, updated_at = NOW()
                  WHERE id = ? AND status = ?",
                [VitrineRequest::STATUS_ACCEPTED, $requestId, VitrineRequest::STATUS_PENDING]
            );

            if ($requestClaimed !== 1) {
                $db->rollback();
                return ['success' => false, 'message' => 'این درخواست قبلاً پردازش شده است.'];
            }

            $listingClaimed = $db->execute(
                "UPDATE vitrine_listings
                    SET status = ?, buyer_id = ?, offer_price_usdt = ?, updated_at = NOW()
                  WHERE id = ? AND status = ?",
                [
                    VitrineListing::STATUS_IN_ESCROW,
                    (int) $req->requester_id,
                    $req->offer_price,
                    (int) $req->listing_id,
                    VitrineListing::STATUS_ACTIVE,
                ]
            );

            if ($listingClaimed !== 1) {
                $db->rollback();
                return ['success' => false, 'message' => 'آگهی در وضعیت قابل پذیرش نیست.'];
            }

            // Reject all other pending requests for this listing
            $this->request->rejectOtherPending($requestId, (int) $req->listing_id);

            $db->commit();

            // ✅ Outbox: تضمین delivery حتی در صورت خرابی
            if ($this->outbox) {
                $this->outbox->record('vitrine', (int)$req->listing_id, 'vitrine.request_accepted', [
                    'listing_id'   => (int)$req->listing_id,
                    'request_id'   => $requestId,
                    'seller_id'    => $sellerId,
                    'requester_id' => (int)$req->requester_id,
                    'offer_price'  => $req->offer_price,
                ]);
            } else {
                $this->eventDispatcher->dispatch('vitrine.request_accepted', [
                    'listing_id'   => (int)$req->listing_id,
                    'request_id'   => $requestId,
                    'seller_id'    => $sellerId,
                    'requester_id' => (int)$req->requester_id,
                    'offer_price'  => $req->offer_price,
                ]);
            }

            $this->logger->info('vitrine.request_accepted', [
                'listing_id'   => (int) $req->listing_id,
                'request_id'   => $requestId,
                'seller_id'    => $sellerId,
                'requester_id' => (int) $req->requester_id,
            ]);

            return ['success' => true, 'message' => 'درخواست پذیرفته شد. آگهی وارد مرحله انتقال شد.'];
        } catch (\Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollback();
            }

            $this->logger->error('vitrine.accept_request_failed', [
                'request_id' => $requestId,
                'error'      => $e->getMessage(),
            ]);
            return ['success' => false, 'message' => 'خطا در پذیرش درخواست: ' . $e->getMessage()];
        }
    }
}
