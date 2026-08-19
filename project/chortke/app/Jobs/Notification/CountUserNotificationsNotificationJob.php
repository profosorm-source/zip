<?php

namespace App\Jobs\Notification;

use App\Contracts\JobInterface;

class CountUserNotificationsNotificationJob implements JobInterface
{
    public function __construct(
        private \App\Services\Notification\NotificationTracker $tracker
    ) {}

    public function handle(int $userId, bool $onlyUnread = false): int
    {

        return $this->tracker->countUserNotifications($userId, $onlyUnread);
    
    }
}
