<?php

namespace App\Jobs\Notification;

use App\Contracts\JobInterface;
use App\Models\Notification;

class RecordClickNotificationJob implements JobInterface
{
    public function __construct(
        private Notification $model
    ) {}

    public function handle(int $notificationId, int $userId): bool
    {
        return $this->model->recordClick($notificationId, $userId);
    }
}
