<?php

declare(strict_types=1);

namespace App\Jobs\Vitrine;

use App\Models\VitrineListing;
use App\Contracts\WalletServiceInterface;
use App\Contracts\OutboxServiceInterface;
use Core\EventDispatcher;
use App\Contracts\LoggerInterface;

/**
 * AdminRefundVitrineListingJob
 *
 * بازپرداخت آگهی ویترین توسط ادمین
 * وضعیت: in_escrow/disputed → cancelled
 * وجه به خریدار برگشت داده می‌شود
 */
class AdminRefundVitrineListingJob
{
    private VitrineListing $listing;
    private WalletServiceInterface $wallet;
    private EventDispatcher $eventDispatcher;
    private LoggerInterface $logger;
    private ?OutboxServiceInterface $outbox;
    private ?\App\Services\EscrowService $escrowService;

    public function __construct(
        VitrineListing $listing,
        WalletServiceInterface $wallet,
        EventDispatcher $eventDispatcher,
        LoggerInterface $logger,
        ?OutboxServiceInterface $outbox = null,
        ?\App\Services\EscrowService $escrowService = null
    ) {
        $this->eventDispatcher = $eventDispatcher;
        $this->listing = $listing;
        $this->wallet = $wallet;
        $this->logger = $logger;
        $this->outbox = $outbox;
        $this->escrowService = $escrowService;
    }

    /** @return array<string, mixed> */
public function handle(int $listingId, int $adminId): array
    {
        $listing = $this->listing->find($listingId);
        if (!$listing) {
            return ['success' => false, 'message' => 'آگهی یافت نشد.'];
        }

        $refundableStatuses = [
            VitrineListing::STATUS_IN_ESCROW,
            VitrineListing::STATUS_DISPUTED,
        ];

        if (!in_array($listing->status, $refundableStatuses, true)) {
            return ['success' => false, 'message' => 'این آگهی در وضعیت قابل بازپرداخت نیست.'];
        }

        $buyerId = (int) ($listing->buyer_id ?? 0);
        $amount  = (string) ($listing->offer_price_usdt ?? $listing->price_usdt ?? '0');
        $currency = $listing->currency ?? 'usdt';

        $db = \Core\Database::getInstance();

        // M-01 FIX (root cause): the listing state was read, then money was moved, then the
        // status was overwritten unconditionally and outside any transaction. Two concurrent
        // admin refunds therefore both passed the status check and both credited the buyer.
        // The refund is now fenced by an atomic compare-and-swap on the listing status: only
        // the caller whose UPDATE affects exactly one row owns the refund.
        $claimed = $db->execute(
            "UPDATE vitrine_listings SET status = ?, updated_at = NOW()
              WHERE id = ? AND status IN (?, ?)",
            [
                VitrineListing::STATUS_CANCELLED,
                $listingId,
                VitrineListing::STATUS_IN_ESCROW,
                VitrineListing::STATUS_DISPUTED,
            ]
        );

        if ($claimed !== 1) {
            $this->logger->warning('vitrine.admin_refund.claim_skipped', [
                'listing_id' => $listingId,
                'admin_id'   => $adminId,
            ]);
            return ['success' => false, 'message' => 'این آگهی هم‌اکنون توسط فرایند دیگری پردازش شده است.'];
        }

        try {
            $escrow = $db->fetch(
                "SELECT * FROM escrow_transactions WHERE order_id = ? AND order_type = 'vitrine_listing' ORDER BY id DESC LIMIT 1",
                [(string)$listingId]
            );
            $escrowRefundable = $escrow !== null
                && in_array((string)($escrow->status ?? ''), ['pending', 'in_escrow', 'disputed'], true);

            // M-01 FIX: previously the buyer was credited from the wallet AND the escrow row was
            // flipped to `refunded` — for an escrow-backed listing that released the held amount
            // twice (double refund). Exactly one settlement path runs now: the escrow service
            // when the funds are actually held in escrow, a direct deposit only otherwise.
            if ($escrowRefundable) {
                $escrowService = $this->escrowService ?? app(\App\Services\EscrowService::class);
                $refund = $escrowService->refundFunds(
                    (int)$escrow->id,
                    $buyerId,
                    'بازپرداخت ادمین ویترین',
                    // M-tier FIX: record WHICH admin initiated the refund (audit trail).
                    'admin:' . $adminId,
                    'vitrine_admin_refund_' . $listingId
                );
                if (empty($refund['ok'])) {
                    throw new \RuntimeException(
                        is_string($refund['error'] ?? null) ? $refund['error'] : 'بازگشت وجه امانی ناموفق بود'
                    );
                }
            } elseif ($buyerId > 0 && $amount !== '0') {
                $this->wallet->deposit($buyerId, $amount, $currency, [
                    'type'        => 'vitrine_admin_refund',
                    'description' => "بازپرداخت آگهی #{$listingId} توسط ادمین",
                    'listing_id'  => $listingId,
                    'idempotency_key' => 'vitrine_admin_refund_' . $listingId,
                ]);
            }

            if ($this->outbox) {
                $this->outbox->record('vitrine', $listingId, 'vitrine.admin_refunded', [
                    'listing_id' => $listingId,
                    'buyer_id'   => $buyerId,
                    'amount'     => $amount,
                ]);
            } else {
                $this->eventDispatcher->dispatch('vitrine.admin_refunded', [
                    'listing_id' => $listingId,
                    'buyer_id'   => $buyerId,
                    'amount'     => $amount,
                ]);
            }

            $this->logger->info('vitrine.admin_refunded', [
                'listing_id' => $listingId,
                'admin_id'   => $adminId,
                'buyer_id'   => $buyerId,
                'amount'     => $amount,
            ]);

            return ['success' => true, 'message' => 'بازپرداخت با موفقیت انجام شد.'];
        } catch (\Throwable $e) {
            // M-01 FIX: release the claim so the refund can be retried instead of leaving the
            // listing cancelled while the money was never returned.
            $db->execute(
                "UPDATE vitrine_listings SET status = ?, updated_at = NOW() WHERE id = ? AND status = ?",
                [(string)$listing->status, $listingId, VitrineListing::STATUS_CANCELLED]
            );

            $this->logger->error('vitrine.admin_refund_failed', [
                'listing_id' => $listingId,
                'error'      => $e->getMessage(),
            ]);
            return ['success' => false, 'message' => 'خطا در بازپرداخت: ' . $e->getMessage()];
        }
    }
}
