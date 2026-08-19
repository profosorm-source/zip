<?php

namespace App\Jobs\Notification;

use App\Contracts\JobInterface;
use App\Services\Notification\NotificationService;

class WithdrawalApprovedNotificationJob implements JobInterface
{
    public function __construct(
        private NotificationService $service
    ) {}

    public function handle(int $userId, string|float $amount, string $currency): ?int
    {
        return $this->service->sendFromTemplate($userId, 'withdrawal', [
            'amount'   => \Core\ValueObjects\Money::fromString((string)$amount)->format(),
            'currency' => strtoupper((string)$currency),
        ], 'high', url('/wallet/history'), 'مشاهده تاریخچه');
    }
}
