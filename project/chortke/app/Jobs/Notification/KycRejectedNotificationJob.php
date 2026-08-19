<?php

namespace App\Jobs\Notification;

use App\Contracts\JobInterface;
use App\Services\Notification\NotificationService;

class KycRejectedNotificationJob implements JobInterface
{
    public function __construct(
        private NotificationService $service
    ) {}

    public function handle(int $userId, string $reason): ?int
    {
        return $this->service->sendFromTemplate(
            $userId,
            'kyc_rejected',
            ['reason' => $reason],
            'urgent',
            url('/kyc/upload'),
            'ارسال مجدد مدارک'
        );
    }
}
