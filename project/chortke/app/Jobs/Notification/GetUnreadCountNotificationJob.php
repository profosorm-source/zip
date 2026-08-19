<?php

namespace App\Jobs\Notification;

use App\Contracts\JobInterface;

class GetUnreadCountNotificationJob implements JobInterface
{
    public function __construct(
        private \App\Services\Notification\NotificationTracker $tracker
    ) {}

    public function handle(int $userId): int
    {

        return $this->tracker->getUnreadCount($userId);
    
    }
}
