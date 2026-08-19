<?php

declare(strict_types=1);

namespace App\Jobs\Notification;

use App\Models\Notification;
use App\Services\Notification\NotificationPolicyService;
use App\Contracts\LoggerInterface;
use App\Services\Notification\NotificationTracker;
use Core\Queue;
use App\Contracts\OutboxServiceInterface;
use Core\Database;

/**
 * SendToAllNotificationJob
 *
 * ارسال اعلان همگانی به تمام کاربران فعال.
 * از همان الگوی bulk-insert + cache-invalidation + push مشابه SendToAdminsNotificationJob استفاده می‌کند.
 */
class SendToAllNotificationJob
{
    private Database $db;
    private NotificationPolicyService $policyService;
    private LoggerInterface $logger;
    private NotificationTracker $tracker;
    private Queue $queue;
    private OutboxServiceInterface $outbox;

    private const BATCH_SIZE = 500;

    public function __construct(
        Database $db,
        NotificationPolicyService $policyService,
        LoggerInterface $logger,
        NotificationTracker $tracker,
        Queue $queue,
        OutboxServiceInterface $outbox
    ) {
        $this->db = $db;
        $this->policyService = $policyService;
        $this->logger = $logger;
        $this->tracker = $tracker;
        $this->queue = $queue;
        $this->outbox = $outbox;
    }

    /** @param array<string, mixed> $data */
    /** @return array<string, mixed> */
/** @param array<string, mixed> $data */
/**
 * @param array<string, mixed>|null $data
 * @return array<string, mixed>
 */
public function handle(
        string  $title,
        string  $message,
        string  $type       = 'system',
        ?string $actionUrl  = null,
        ?string $actionText = null,
        string  $priority   = 'normal',
        ?array  $data       = null,
        ?string $scheduledAt = null
    ): array {
        $type    = $this->sanitizeType($type);
        $title   = mb_substr(trim(strip_tags($title)), 0, 255);
        $message = mb_substr(trim(strip_tags($message)), 0, 1200);

        $totalSent  = 0;
        $batchCount = 0;
        $offset     = 0;

        while (true) {
            $userIds = $this->db->fetchAll(
                "SELECT id FROM users WHERE deleted_at IS NULL ORDER BY id ASC LIMIT ? OFFSET ?",
                [self::BATCH_SIZE, $offset]
            );
            if (empty($userIds)) break;

            $ids = array_map(fn($row) => (int) (is_object($row) ? $row->id : $row['id']), $userIds);
            $sentInBatch = $this->processBatch($ids, $type, $title, $message, $data, $actionUrl, $actionText, $priority, $scheduledAt);
            $totalSent  += $sentInBatch;
            $batchCount++;
            $offset     += self::BATCH_SIZE;
        }

        return ['sent' => $totalSent, 'batches' => $batchCount];
    }

        /** @param list<int> $userIds */
    /** @param list<int> $userIds */
/**
 * @param list<int> $userIds
 * @param array<string, mixed>|null $data
 */
private function processBatch(
        array $userIds, string $type, string $title, string $message,
        ?array $data, ?string $actionUrl, ?string $actionText, string $priority, ?string $scheduledAt
    ): int {
        $this->policyService->prefetchPreferences($userIds);

        $inAppRecords  = [];
        $pushUserIds   = [];
        $allowedIds    = [];

        foreach ($userIds as $uid) {
            if (!$this->checkRate($uid)) continue;
            $allowedIds[] = $uid;

            if ($this->policyService->canSendInApp($uid, $type)) {
                $sched = $this->policyService->resolveScheduledTime($uid, $priority, $scheduledAt);
                $inAppRecords[] = ['user_id' => $uid, 'scheduled_at' => $sched];
            }
            if ($this->policyService->canSendPush($uid, $type)) {
                $pushUserIds[] = $uid;
            }
        }

        $inserted = 0;
        if (!empty($inAppRecords)) {
            $inserted = $this->bulkInsert($inAppRecords, $type, $title, $message, $data, $actionUrl, $actionText, $priority);
        }

        if (!empty($allowedIds)) {
            try { $this->tracker->invalidateUnreadCacheBulk($allowedIds); } catch (\Throwable $e) {
                $this->logger->warning('notif.all_bulk_cache_failed', ['error' => $e->getMessage()]);
            }
        }

        if (!empty($pushUserIds)) {
            $this->dispatchPush($pushUserIds, $title, $message, $data, $actionUrl);
        }

        return $inserted;
    }

        /** @param array<string, mixed> $records */
    /** @param array<string, mixed> $records */
/**
 * @param list<array<string, mixed>> $records
 * @param array<string, mixed>|null $data
 */
private function bulkInsert(array $records, string $type, string $title, string $message, ?array $data, ?string $actionUrl, ?string $actionText, string $priority): int
    {
        $now       = date('Y-m-d H:i:s');
        $dataJson  = $data ? json_encode($data, JSON_UNESCAPED_UNICODE) : null;
        $groupKey  = md5($type . $title);
        $placeholders = [];
        $params       = [];

        foreach ($records as $r) {
            $placeholders[] = '(?, ?, ?, ?, ?, ?, ?, ?, 0, 0, ?, ?, ?, ?, ?)';
            $params[] = $r['user_id'];
            $params[] = $type;
            $params[] = $title;
            $params[] = $message;
            $params[] = $dataJson;
            $params[] = $actionUrl;
            $params[] = $actionText;
            $params[] = $priority;
            $params[] = null;
            $params[] = $now;
            $params[] = $r['scheduled_at'];
            $params[] = Notification::CHANNEL_IN_APP;
            $params[] = $groupKey;
        }

        $sql = "INSERT INTO notifications 
                (user_id, type, title, message, data, action_url, action_text, priority, is_read, is_archived, expires_at, created_at, scheduled_at, channel, group_key)
                VALUES " . implode(', ', $placeholders);

        $this->db->execute($sql, $params);
        return count($records);
    }

    /**
     * @param list<int> $userIds
     * @param array<string, mixed>|null $data
     */
    private function dispatchPush(array $userIds, string $title, string $message, ?array $data, ?string $actionUrl): void
    {
        try {
            $this->outbox->record('notification', 0, 'notification.all_users_fcm', [
                'job'          => \App\Jobs\ProcessNotificationJob::class,
                'notification' => [
                    'channel'    => 'fcm',
                    'user_ids'   => $userIds,
                    'title'      => $title,
                    'message'    => $message,
                    'data'       => $data,
                    'action_url' => $actionUrl,
                ],
            ]);
        } catch (\Throwable $e) {
            $this->queue->pushUnique(
                \App\Jobs\ProcessNotificationJob::class,
                ['channel' => 'fcm', 'user_ids' => $userIds, 'title' => $title, 'message' => $message, 'data' => $data, 'action_url' => $actionUrl],
                'notif:all_fcm:' . md5($title . implode(',', array_slice($userIds, 0, 3))),
                null, 0, 86400
            );
        }
    }

    private function checkRate(int $userId): bool
    {
        try {
            $last = $this->db->fetchColumn(
                "SELECT MAX(created_at) FROM notifications WHERE user_id = ? AND created_at > DATE_SUB(NOW(), INTERVAL 60 SECOND)",
                [$userId]
            );
            return $last === false || $last === null;
        } catch (\Throwable $e) {
            $this->logger->warning('notif.all_rate_check_failed', ['user_id' => $userId, 'error' => $e->getMessage()]);
            return true;
        }
    }

    private function sanitizeType(string $type): string
    {
        $type = trim((string)$type);
        $type = (string)preg_replace('/[^A-Za-z0-9_]/', '', $type);
        return $type !== '' ? $type : 'system';
    }
}
