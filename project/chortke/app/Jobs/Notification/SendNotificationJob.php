<?php

namespace App\Jobs\Notification;

use App\Contracts\JobInterface;
use App\Services\Notification\NotificationPreferenceService;
use App\Contracts\LoggerInterface;
use Core\RateLimiter;
use Core\Database;
use App\Models\Notification;

class SendNotificationJob implements JobInterface
{
    public function __construct(
        private NotificationPreferenceService $preferenceService,
        private LoggerInterface $logger,
        private RateLimiter $rateLimiter,
        private Database $db,
        private \App\Services\Notification\NotificationTracker $tracker
    ) {}

    /** @param array<string, mixed> $data */
    /** @param array<string, mixed> $data */
public function handle(
        int $userId,
        string $type,
        string $title,
        string $message,
        ?array $data = null,
        ?string $actionUrl = null,
        ?string $actionText = null,
        string $priority = 'normal',
        ?string $expiresAt = null,
        ?string $imageUrl = null,
        ?string $groupKey = null,
        ?string $scheduledAt = null
    ): ?int
    {
        try {
            if (!$this->preferenceService->isNotificationEnabled($userId, $type)) {
                return null;
            }

            if (!$this->rateLimiter->attempt("notif_limit:{$userId}:{$type}", 5, 60)) {
                $this->logger->warning('notification.rate_limited', ['user_id' => $userId, 'type' => $type]);
                return null;
            }

            // We need a way to persist the notification.
            $model = new Notification($this->db);
            
            $notifId = $model->create([
                'user_id'      => $userId,
                'type'         => $type,
                'title'        => $title,
                'message'      => $message,
                'data'         => $data,
                'action_url'   => $actionUrl,
                'action_text'  => $actionText,
                'priority'     => $priority,
                'expires_at'   => $expiresAt,
                'image_url'    => $imageUrl,
                'group_key'    => $groupKey ?? $type,
                'scheduled_at' => $scheduledAt,
                'channel'      => 'in_app',
            ]);

            if ($notifId && $scheduledAt === null) {
                $this->tracker->invalidateUnreadCache($userId);
            }

            return $notifId === false ? null : $notifId;
        } catch (\Throwable $e) {
            $this->logger->error('notification.send_failed', [
                'user_id' => $userId,
                'error'   => $e->getMessage()
            ]);
            return null;
        }
    }
}
