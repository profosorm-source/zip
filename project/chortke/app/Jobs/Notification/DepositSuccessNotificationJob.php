<?php

namespace App\Jobs\Notification;

use App\Contracts\JobInterface;
use App\Services\Notification\NotificationService;

class DepositSuccessNotificationJob implements JobInterface
{
    public function __construct(
        private NotificationService $service
    ) {}

    public function handle(int $userId, float $amount, string $currency): ?int
    {
        return $this->service->sendFromTemplate($userId, 'deposit', [
            'amount'   => \Core\ValueObjects\Money::fromString((string)($amount))->format(),
            'currency' => strtoupper((string)$currency),
        ], 'high', url('/wallet'), 'مشاهده کیف پول');
    }
}
