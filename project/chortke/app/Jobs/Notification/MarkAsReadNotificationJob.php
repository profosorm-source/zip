<?php

namespace App\Jobs\Notification;

use App\Contracts\JobInterface;

class MarkAsReadNotificationJob implements JobInterface
{
    public function __construct(
        private \App\Services\Notification\NotificationTracker $tracker
    ) {}

    public function handle(int $notificationId, int $userId): bool
    {

        return $this->tracker->markAsRead($notificationId, $userId);
    
    }
}
