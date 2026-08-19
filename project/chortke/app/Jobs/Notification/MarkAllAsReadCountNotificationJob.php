<?php

namespace App\Jobs\Notification;

use App\Contracts\JobInterface;

class MarkAllAsReadCountNotificationJob implements JobInterface
{
    public function __construct(
        private \App\Services\Notification\NotificationTracker $tracker
    ) {}

    public function handle(int $userId): int
    {

        // برای سازگاری، ابتدا رکوردها را آپدیت کرده و بعد خروجی را بازمی‌گرداند
        $this->tracker->markAllAsRead($userId);
        return 1; // ساده‌سازی برای حفظ API
    
    }
}
