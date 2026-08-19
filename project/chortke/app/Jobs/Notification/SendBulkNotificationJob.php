<?php

declare(strict_types=1);

namespace App\Jobs\Notification;

class SendBulkNotificationJob
{
    private ?\App\Contracts\OutboxServiceInterface $outbox;
    private \Core\Queue $queue;

    public function __construct(
        \Core\Queue $queue,
        ?\App\Contracts\OutboxServiceInterface $outbox = null
    ) {
        $this->outbox = $outbox;
        $this->queue = $queue;
    }

/**
 * @param list<int> $userIds
 * @param array<string, mixed> $data
 */
public function handle(array $userIds, string $type, string $title, string $message, array $data = [], ?string $actionUrl = null): int
    {
        if (empty($userIds)) return 0;
        
        $chunks = array_chunk($userIds, 100);
        
        foreach ($chunks as $chunk) {
            $persistPayload = [
                'user_ids' => $chunk,
                'type' => $type,
                'title' => $title,
                'message' => $message,
                'data' => $data,
                'action_url' => $actionUrl,
                'action_text' => null,
                'priority' => 'normal',
                'scheduled_at' => null,
            ];

            $fcmPayload = [
                'channel' => 'fcm',
                'user_ids' => $chunk,
                'title' => $title,
                'message' => $message,
                'data' => $data,
                'action_url' => $actionUrl,
            ];

            // Outbox-First: fan-out از طریق outbox
            if ($this->outbox) {
                $this->outbox->record('notification', 0, 'notification.bulk_persist', [
                    'job' => \App\Jobs\PersistBulkInAppNotificationJob::class,
                    'notification' => $persistPayload,
                ]);
                $this->outbox->record('notification', 0, 'notification.bulk_fcm', [
                    'job' => \App\Jobs\ProcessNotificationJob::class,
                    'notification' => $fcmPayload,
                ]);
            } else {
                // Fallback: queue مستقیم
                $this->queue->push(\App\Jobs\PersistBulkInAppNotificationJob::class, $persistPayload);
                $this->queue->push(\App\Jobs\ProcessNotificationJob::class, $fcmPayload);
            }
        }
        
        return count($userIds);
    }
}
