<?php

declare(strict_types=1);

namespace App\Jobs\Vitrine;

use App\Models\VitrineListing;
use App\Contracts\OutboxServiceInterface;
use App\Services\Settings\AppSettings;
use App\Services\VitrineService;
use Core\EventDispatcher;

class CreateVitrineListingJob
{
    private VitrineListing $listing;
    private AppSettings $settings;
    private VitrineService $vitrineService;
    private EventDispatcher $eventDispatcher;
    private ?OutboxServiceInterface $outbox;

    public function __construct(
        VitrineListing $listing,
        AppSettings $settings,
        VitrineService $vitrineService,
        EventDispatcher $eventDispatcher,
        ?OutboxServiceInterface $outbox = null
    ) {
        $this->eventDispatcher = $eventDispatcher;
        $this->listing = $listing;
        $this->settings = $settings;
        $this->vitrineService = $vitrineService;
        $this->outbox = $outbox;
    }

/**
 * @param array<string, mixed> $data
 * @return array<string, mixed>
 */
public function handle(int $userId, array $data): array
    {
        $check = $this->vitrineService->canTrade($userId);
        if (!$check['ok']) return ['success' => false, 'message' => $check['message']];

        // محدودیت تعداد آگهی فعال
        $maxActive = int_value($this->settings->get('vitrine_max_active_per_user', '5'));
        $activeCount = $this->listing->countActiveByUser($userId);
        if ($activeCount >= $maxActive) {
            return ['success' => false, 'message' => "حداکثر {$maxActive} آگهی فعال می‌توانید داشته باشید."];
        }

        $minPrice = str_value($this->settings->get('vitrine_min_price_usdt', '1'));
        $maxPrice = str_value($this->settings->get('vitrine_max_price_usdt', '100000'));
        $price    = str_value($data['price_usdt'] ?? '0');

        if (!is_numeric($price) || bccomp($price, $minPrice, 8) < 0 || bccomp($price, $maxPrice, 8) > 0) {
            return ['success' => false, 'message' => "قیمت باید بین {$minPrice} و {$maxPrice} USDT باشد."];
        }

        $data['seller_id'] = $userId;

        $result = $this->listing->createListing($data);
        if (!$result) {
            return ['success' => false, 'message' => 'خطا در ثبت آگهی. لطفاً دوباره تلاش کنید.'];
        }

        if ($this->outbox) {
            $this->outbox->record('vitrine', (int)($result->id ?? 0), 'vitrine.listing_created', [
                'user_id'  => $userId,
                'amount'   => $price,
                'currency' => 'usdt',
            ]);
        } else {
            $this->eventDispatcher->dispatch('vitrine.listing_created', [
                'user_id'  => $userId,
                'amount'   => $price,
                'currency' => 'usdt',
            ]);
        }

        return ['success' => true, 'listing' => $result];
    }
}
