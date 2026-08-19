<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\Notification\NotificationService;

/**
 * PersistBulkInAppNotificationJob - پردازش و درج غیرهمزمان نوتیفیکیشن‌های درون‌برنامه‌ای در دیتابیس
 */
class PersistBulkInAppNotificationJob
{
    private NotificationService $service;

    public function __construct(NotificationService $service) {
        $this->service = $service;
    }

    /**
     * اجرای تسک ثبت نوتیفیکیشن در دیتابیس برای دسته‌ای از کاربران
     */
    /** @param array<string, mixed> $data */
    /** @param array<string, mixed> $data */
public function handle(array $data): void
    {
        $userIds = is_array($data['user_ids'] ?? null) ? array_map(static fn($v) => int_value($v), $data['user_ids']) : [];
        $type = str_value($data['type'] ?? '');
        $title = str_value($data['title'] ?? '');
        $message = str_value($data['message'] ?? '');
        $extraData = is_array($data['data'] ?? null) ? $data['data'] : null;
        $actionUrl = $data['action_url'] === null ? null : str_value($data['action_url']);
        $actionText = $data['action_text'] === null ? null : str_value($data['action_text']);
        $priority = str_value($data['priority'] ?? 'normal');
        $scheduledAt = $data['scheduled_at'] === null ? null : str_value($data['scheduled_at']);

        if (empty($userIds) || empty($title) || empty($message)) {
            return;
        }

        // 🚀 Bulk pre-fetch preferences to avoid N+1 database queries
        $this->service->prefetchPreferences($userIds);

        foreach ($userIds as $uid) {
            $this->service->processSinglePersist(
                $uid,
                $type,
                $title,
                $message,
                $extraData,
                $actionUrl,
                $actionText,
                $priority,
                $scheduledAt
            );
        }
    }
}
