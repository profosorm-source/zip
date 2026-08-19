<?php

declare(strict_types=1);

namespace App\Jobs\Notification;

use App\Services\Notification\NotificationService;

/**
 * ProcessSinglePersistNotificationJob
 *
 * Delegate to NotificationService for single-user notification persistence.
 * All rate-limit, scheduling, and persistence logic lives in the service.
 */
class ProcessSinglePersistNotificationJob
{
    private NotificationService $service;

    public function __construct(NotificationService $service) {
        $this->service = $service;
    }

    /** @param array<string, mixed> $data */
    /** @param array<string, mixed> $data */
public function handle(
        int $uid, string $type, string $title, string $message,
        ?array $data, ?string $actionUrl, ?string $actionText, string $priority, ?string $scheduledAt
    ): bool {
        // Delegate all 3 steps to NotificationService (private methods accessible via public wrapper)
        return $this->service->persistSingleNotification(
            $uid, $type, $title, $message,
            $data, $actionUrl, $actionText, $priority, $scheduledAt
        );
    }
}
