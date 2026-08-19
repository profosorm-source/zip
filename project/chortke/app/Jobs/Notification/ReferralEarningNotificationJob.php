<?php

namespace App\Jobs\Notification;

use App\Contracts\JobInterface;
use App\Services\Notification\NotificationService;
use App\Models\Notification;

class ReferralEarningNotificationJob implements JobInterface
{
    public function __construct(
        private NotificationService $service
    ) {}

    public function handle(int $userId, float $amount, string $referredUserName): ?int
    {
        return $this->service->sendFromTemplate(
            $userId,
            'referral',
            [
                'referred_user' => $referredUserName,
                'amount'        => \Core\ValueObjects\Money::fromString((string)($amount))->format(),
            ],
            Notification::PRIORITY_NORMAL,
            url('/referral'),
            'مشاهده زیرمجموعه‌ها',
            'referral_earning'
        );
    }
}
