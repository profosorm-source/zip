<?php

namespace App\Jobs\Notification;

use App\Contracts\JobInterface;
use App\Services\Notification\NotificationService;
use App\Models\Notification;

class InvestmentCompletedNotificationJob implements JobInterface
{
    public function __construct(
        private NotificationService $service
    ) {}

    public function handle(int $userId, float $profit, float $total): ?int
    {
        return $this->service->sendFromTemplate(
            $userId,
            'investment_completed',
            [
                'profit' => \Core\ValueObjects\Money::fromString((string)($profit))->format(),
                'total'  => \Core\ValueObjects\Money::fromString((string)($total))->format(),
            ],
            Notification::PRIORITY_HIGH,
            url('/investments'),
            'مشاهده سرمایه‌گذاری‌ها'
        );
    }
}
