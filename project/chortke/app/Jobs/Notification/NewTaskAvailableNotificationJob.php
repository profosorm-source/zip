<?php

namespace App\Jobs\Notification;

use App\Contracts\JobInterface;
use App\Services\Notification\NotificationService;
use App\Models\Notification;

class NewTaskAvailableNotificationJob implements JobInterface
{
    public function __construct(
        private NotificationService $service
    ) {}

    public function handle(int $userId, string $taskTitle): ?int
    {
        return $this->service->sendFromTemplate(
            $userId,
            'task',
            [
                'task_title' => $taskTitle,
            ],
            Notification::PRIORITY_NORMAL,
            url('/tasks'),
            'مشاهده تسک‌ها',
            'task_available'
        );
    }
}
