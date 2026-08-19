<?php

declare(strict_types=1);

namespace App\Jobs\Vitrine;

use App\Models\VitrineListing;
use App\Services\VitrineService;

class ConfirmVitrineDeliveryJob
{
    private VitrineListing $listing;
    private VitrineService $vitrineService;

    public function __construct(
        VitrineListing $listing,
        VitrineService $vitrineService
    ) {
        $this->listing = $listing;
        $this->vitrineService = $vitrineService;
    }

    /** @return array<string, mixed> */
public function handle(int $buyerId, int $listingId): array
    {
        $listing = $this->listing->find($listingId);
        if (!$listing || (int) $listing->buyer_id !== $buyerId || $listing->status !== 'in_escrow') {
            return ['success' => false, 'message' => 'عملیات غیرمجاز.'];
        }

        return $this->vitrineService->releaseFundsToSeller($listing, 'buyer_confirm');
    }
}
