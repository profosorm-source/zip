<?php

namespace App\Jobs\Notification;

use App\Contracts\JobInterface;
use App\Models\Notification;
use App\Services\Notification\NotificationPolicyService;
use App\Contracts\LoggerInterface;
use App\Services\Notification\NotificationTracker;
use Core\Queue;
use App\Contracts\OutboxServiceInterface;

class SendToAdminsNotificationJob implements JobInterface
{
    public function __construct(
        private Notification $model,
        private NotificationPolicyService $policyService,
        private LoggerInterface $logger,
        private NotificationTracker $tracker,
        private Queue $queue,
        private OutboxServiceInterface $outbox
    ) {}

    /** @param array<string, mixed> $data */
    /** @param array<string, mixed> $data */
public function handle(string $type, string $title, string $message, ?array $data = null, string $priority = 'normal'): int
    {

        $type = trim(str_value($type));
        $type = str_value(preg_replace('/[^A-Za-z0-9_]/', '', $type));
        if ($type === '') {
            $type = 'system';
        }

        $title = mb_substr(trim(strip_tags(str_value($title))), 0, 255);
        $message = mb_substr(trim(strip_tags(str_value($message))), 0, 1200);

        $adminIds = $this->model->getAdminUsersIds();
        if (empty($adminIds)) {
            return 0;
        }

        // 1. Prefetch preferences for all admins in a single database request
        $this->policyService->prefetchPreferences($adminIds);

        $actionUrl = $data['action_url'] ?? null;
        $actionText = $data['action_text'] ?? null;

        // Sanitize values
        $title = $this->sanitizeNotificationText($title);
        $message = $this->sanitizeNotificationText($message);
        $actionUrl = $this->sanitizeUrl($actionUrl === null ? null : str_value($actionUrl));
        $actionText = $this->sanitizeNotificationText($actionText === null ? null : str_value($actionText));
        $groupKey = $this->sanitizeNotificationText($type);

        $inAppRecords = [];
        $pushAdminIds = [];
        $allowedAdminIds = [];

        foreach ($adminIds as $adminId) {
            $adminId = (int)$adminId;

            // Rate Limit Check
            if (!$this->checkRateLimit($adminId)) {
                $this->logger->info('notif.admin_rate_limited', ['user_id' => $adminId, 'type' => $type]);
                continue;
            }

            $allowedAdminIds[] = $adminId;

            // In-app check
            if ($this->policyService->canSendInApp($adminId, $type)) {
                $scheduledAt = $this->policyService->resolveScheduledTime($adminId, $priority, null);
                
                $inAppRecords[] = [
                    'user_id' => $adminId,
                    'scheduled_at' => $scheduledAt,
                ];
            }

            // Push check
            if ($this->policyService->canSendPush($adminId, $type)) {
                $pushAdminIds[] = $adminId;
            }
        }

        $insertedCount = 0;

        // 2. Perform BULK INSERT for all allowed in-app notifications
        if (!empty($inAppRecords)) {
            try {
                $now = date('Y-m-d H:i:s');
                $placeholders = [];
                $params = [];
                $dataJson = $data ? json_encode($data, JSON_UNESCAPED_UNICODE) : null;

                foreach ($inAppRecords as $record) {
                    $placeholders[] = '(?, ?, ?, ?, ?, ?, ?, ?, 0, 0, ?, ?, ?, ?, ?)';
                    $params[] = $record['user_id'];
                    $params[] = $type;
                    $params[] = $title;
                    $params[] = $message;
                    $params[] = $dataJson;
                    $params[] = $actionUrl;
                    $params[] = $actionText;
                    $params[] = $priority;
                    $params[] = null; // expires_at
                    $params[] = $now; // created_at
                    $params[] = $record['scheduled_at'];
                    $params[] = Notification::CHANNEL_IN_APP;
                    $params[] = $groupKey;
                }

                $sql = "INSERT INTO notifications 
                        (user_id, type, title, message, data, action_url, action_text, priority, is_read, is_archived, expires_at, created_at, scheduled_at, channel, group_key)
                        VALUES " . implode(', ', $placeholders);

                $this->model->getDb()->query($sql, $params);
                $insertedCount = count($inAppRecords);
            } catch (\Throwable $e) {
                $this->logger->error('notif.admins_bulk_insert_failed', ['error' => $e->getMessage()]);
                throw $e;
            }
        }

        // 3. Perform BULK CACHE INVALIDATION
        if (!empty($allowedAdminIds)) {
            try {
                $this->tracker->invalidateUnreadCacheBulk($allowedAdminIds);
            } catch (\Throwable $e) {
                $this->logger->error('notif.admins_bulk_cache_invalidation_failed', ['error' => $e->getMessage()]);
                throw $e;
            }
        }

        // 4. Perform BULK FCM PUSH DISPATCH
        if (!empty($pushAdminIds)) {
            try {
                $fcmPayload = [
                    'channel' => 'fcm',
                    'user_ids' => $pushAdminIds,
                    'title' => $title,
                    'message' => $message,
                    'data' => $data,
                    'action_url' => $actionUrl,
                ];

                // Outbox-First
                try {
                    $this->outbox->record('notification', 0, 'notification.admins_fcm', [
                        'job' => \App\Jobs\ProcessNotificationJob::class,
                        'notification' => $fcmPayload,
                    ]);
                } catch (\Throwable $outboxErr) {
                    // Fallback: queue مستقیم
                    $this->queue->pushUnique(
                        \App\Jobs\ProcessNotificationJob::class,
                        $fcmPayload,
                        'notif:admins_fcm:' . md5($title . implode(',', $pushAdminIds)),
                        null, 0, 86400
                    );
                }
            } catch (\Throwable $e) {
                $this->logger->error('notif.admins_bulk_push_failed', ['error' => $e->getMessage()]);
                throw $e;
            }
        }

        return $insertedCount;
    
    }

    /**
     * Sanitize notification text: strip tags, trim, limit length.
     */
    private function sanitizeNotificationText(?string $text): string
    {
        if ($text === null || $text === '') {
            return '';
        }
        $text = strip_tags($text);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = trim((string)$text);
        return mb_substr($text, 0, 1000);
    }

    /**
     * Sanitize URL: validate and ensure http/https scheme.
     */
    private function sanitizeUrl(?string $url): ?string
    {
        if ($url === null || $url === '') {
            return null;
        }
        $url = trim((string)$url);
        $sanitized = filter_var($url, FILTER_SANITIZE_URL);
        if ($sanitized === false || !filter_var($sanitized, FILTER_VALIDATE_URL)) {
            return null;
        }
        if (!preg_match('/^https?:\/\//i', $sanitized)) {
            return null;
        }
        return $sanitized;
    }

    /**
     * Rate-limit admin notifications to prevent alert storms.
     * Uses model's cache for storing last-sent timestamps.
     */
    private function checkRateLimit(int $adminId): bool
    {
        try {
            $cacheKey = 'notif_admin_rate:' . $adminId;
            $db = $this->model->getDb();
            
            // Check last notification time from a lightweight approach
            // Allow at most 1 notification every 60 seconds per admin
            $lastSent = $db->fetchColumn(
                "SELECT MAX(created_at) FROM notifications 
                 WHERE user_id = ? AND created_at > DATE_SUB(NOW(), INTERVAL 60 SECOND)",
                [$adminId]
            );
            
            return $lastSent === false || $lastSent === null;
        } catch (\Throwable $e) {
            // Fail-open: if we can't check rate limit, allow the notification
            $this->logger->warning('notif.admin_rate_limit_check_failed', [
                'admin_id' => $adminId,
                'error'    => $e->getMessage(),
            ]);
            return true;
        }
    }
}
