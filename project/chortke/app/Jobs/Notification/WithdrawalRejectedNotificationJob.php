<?php

namespace App\Jobs\Notification;

use App\Contracts\JobInterface;
use App\Services\Notification\NotificationService;

class WithdrawalRejectedNotificationJob implements JobInterface
{
    public function __construct(
        private NotificationService $service
    ) {}

    public function handle(int $userId, string|float $amount, string $reason): ?int
    {
        return $this->service->sendFromTemplate($userId, 'withdrawal_rejected', [
            'amount' => \Core\ValueObjects\Money::fromString((string)$amount)->format(),
            'reason' => $reason,
        ], 'high', url('/wallet/history'), 'مشاهده جزئیات');
    }
}
