<?php

namespace App\Jobs\Notification;

use App\Contracts\JobInterface;
use App\Services\Notification\NotificationService;

class KycVerifiedNotificationJob implements JobInterface
{
    public function __construct(
        private NotificationService $service
    ) {}

    public function handle(int $userId): ?int
    {
        return $this->service->sendFromTemplate(
            $userId,
            'kyc_approved',
            [],
            'high',
            url('/dashboard'),
            'ورود به داشبورد'
        );
    }
}
