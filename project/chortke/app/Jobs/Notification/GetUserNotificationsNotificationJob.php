<?php

namespace App\Jobs\Notification;

use App\Contracts\JobInterface;

class GetUserNotificationsNotificationJob implements JobInterface
{
    public function __construct(
        private \App\Services\Notification\NotificationTracker $tracker
    ) {}

    /** @return list<\stdClass> */
public function handle(int $userId, bool $onlyUnread = false, int $limit = 20, int $offset = 0): array
    {

        return $this->tracker->getUserNotifications($userId, $onlyUnread, $limit, $offset);
    
    }
}
