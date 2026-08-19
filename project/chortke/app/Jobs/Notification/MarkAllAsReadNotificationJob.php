<?php

namespace App\Jobs\Notification;

use App\Contracts\JobInterface;

class MarkAllAsReadNotificationJob implements JobInterface
{
    public function __construct(
        private \App\Services\Notification\NotificationTracker $tracker
    ) {}

    public function handle(int $userId): bool
    {

        return $this->tracker->markAllAsRead($userId);
    
    }
}
