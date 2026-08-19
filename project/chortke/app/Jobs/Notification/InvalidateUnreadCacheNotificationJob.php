<?php

namespace App\Jobs\Notification;

use App\Contracts\JobInterface;

class InvalidateUnreadCacheNotificationJob implements JobInterface
{
    public function __construct(
        private \App\Services\Notification\NotificationTracker $tracker
    ) {}

    public function handle(int $userId): void
    {

        $this->tracker->invalidateUnreadCache($userId);
    
    }
}
