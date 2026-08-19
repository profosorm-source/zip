<?php

declare(strict_types=1);

namespace App\Jobs\Vitrine;

use App\Models\VitrineListing;
use App\Models\VitrineRequest;
use App\Services\VitrineService;
use App\Contracts\OutboxServiceInterface;
use Core\EventDispatcher;
use App\Contracts\LoggerInterface;

class SendVitrineRequestJob
{
    private LoggerInterface $logger;
    private VitrineListing $listing;
    private VitrineRequest $request;
    private VitrineService $vitrineService;
    private EventDispatcher $eventDispatcher;
    private ?OutboxServiceInterface $outbox;

    public function __construct(
        LoggerInterface $logger,
        VitrineListing $listing,
        VitrineRequest $request,
        VitrineService $vitrineService,
        EventDispatcher $eventDispatcher,
        ?OutboxServiceInterface $outbox = null
    ) {
        $this->eventDispatcher = $eventDispatcher;
        $this->logger = $logger;
        $this->listing = $listing;
        $this->request = $request;
        $this->vitrineService = $vitrineService;
        $this->outbox = $outbox;
    }

/**
 * @param array<string, mixed> $data
 * @return array<string, mixed>
 */
public function handle(int $requesterId, int $listingId, array $data): array
    {
        $check = $this->vitrineService->canTrade($requesterId);
        if (!$check['ok']) return ['success' => false, 'message' => $check['message']];

        $listing = $this->listing->find($listingId);
        if (!$listing || $listing->status !== 'active') {
            return ['success' => false, 'message' => 'آگهی فعال نیست.'];
        }
        if ((int) $listing->seller_id === $requesterId) {
            return ['success' => false, 'message' => 'نمی‌توانید به آگهی خود درخواست دهید.'];
        }
        if ($this->request->existsPending($listingId, $requesterId)) {
            return ['success' => false, 'message' => 'درخواست شما قبلاً ثبت شده و در انتظار پاسخ است.'];
        }

        $req = $this->request->createRequest([
            'listing_id'   => $listingId,
            'requester_id' => $requesterId,
            'offer_price'  => (!empty($data['offer_price']) && is_numeric($data['offer_price'])) ? str_value($data['offer_price']) : null,
            'message'      => trim(str_value($data['message'] ?? '')),
        ]);
        if (!$req) return ['success' => false, 'message' => 'خطا در ثبت درخواست.'];

        // اعلان به فروشنده — از Outbox برای تضمین delivery
        if ($this->outbox) {
            $this->outbox->record('vitrine', $listingId, 'notification.requested', [
                'user_id'    => (int)$listing->seller_id,
                'listing_id' => $listingId,
                'request_id' => $req->id ?? null,
                'message'    => 'درخواست خرید جدید برای آگهی شما ثبت شد',
            ]);
        } else {
            $this->eventDispatcher->dispatch('notification.requested', [
                'user_id'    => (int)$listing->seller_id,
                'listing_id' => $listingId,
                'request_id' => $req->id ?? null,
                'message'    => 'درخواست خرید جدید برای آگهی شما ثبت شد',
            ]);
        }

        $this->logger->info('vitrine.request_sent', [
            'listing_id'   => $listingId,
            'requester_id' => $requesterId,
            'offer_price'  => $req->offer_price,
        ]);
        return ['success' => true, 'request' => $req];
    }
}
