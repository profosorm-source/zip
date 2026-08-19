<?php

namespace App\Jobs\Notification;

use App\Contracts\JobInterface;
use App\Models\Notification;

class FindForUserNotificationJob implements JobInterface
{
    public function __construct(
        private Notification $model
    ) {}

    public function handle(int $notificationId, int $userId): ?\stdClass
    {
        $notification = $this->model->find($notificationId);
        if (!$notification) {
            return null;
        }
        /** @var \stdClass $notification */
        if ((int)$notification->user_id !== $userId) {
            return null;
        }
        return $notification;
    }
}
