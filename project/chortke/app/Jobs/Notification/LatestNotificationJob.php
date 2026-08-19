<?php

namespace App\Jobs\Notification;

use App\Contracts\JobInterface;

class LatestNotificationJob implements JobInterface
{
    public function __construct(
        private \App\Services\Notification\NotificationTracker $tracker
    ) {}

    /** @return list<\stdClass> */
public function handle(int $userId, int $limit = 10): array
    {

        return $this->tracker->getLatestForUser($userId, $limit);
    
    }
}
