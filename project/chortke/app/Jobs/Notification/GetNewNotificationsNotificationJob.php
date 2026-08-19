<?php

namespace App\Jobs\Notification;

use App\Contracts\JobInterface;

class GetNewNotificationsNotificationJob implements JobInterface
{
    public function __construct(
        private \App\Services\Notification\NotificationTracker $tracker
    ) {}

    /** @return array{success: bool, notifications: list<\stdClass>, unread_count: int, last_id?: int} */
    public function handle(int $userId, int $lastId, int $limit = 20): array
    {

        if ($lastId === 0) {
            return ['success' => true, 'notifications' => [], 'unread_count' => $this->tracker->getUnreadCount($userId)];
        }
        $notifications = $this->tracker->getNewNotificationsAfterId($userId, $lastId, $limit);
        if (empty($notifications)) {
            return ['success' => true, 'notifications' => [], 'unread_count' => $this->tracker->getUnreadCount($userId)];
        }
        return [
            'success'       => true,
            'notifications' => $notifications,
            'unread_count'  => $this->tracker->getUnreadCount($userId),
            'last_id'       => end($notifications)->id,
        ];
    
    }
}
