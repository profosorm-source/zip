<?php

namespace App\Jobs\Notification;

use App\Contracts\JobInterface;
use App\Services\Notification\NotificationService;
use App\Models\Notification;

class SecurityAlertNotificationJob implements JobInterface
{
    public function __construct(
        private NotificationService $service
    ) {}

    public function handle(int $userId, string $message, string $ip): ?int
    {
        return $this->service->sendFromTemplate(
            $userId,
            'security',
            [
                'message' => $message,
                'ip'      => $ip,
            ],
            Notification::PRIORITY_URGENT,
            url('/profile/security'),
            'بررسی حساب'
        );
    }
}
