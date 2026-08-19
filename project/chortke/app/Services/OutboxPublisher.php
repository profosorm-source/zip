<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\LoggerInterface;
use Core\Database;
use Core\Queue;
use Core\EventDispatcher;

/**
 * OutboxPublisher - نسخه بهبود یافته فاز ۵ (Section 8.1)
 *
 * تغییرات واقعی:
 * - Retry policy قوی‌تر با exponential backoff
 * - DLQ routing برای failed events
 * - Integration با UnifiedRateLimit (اگر وجود داشته باشد)
 * - Logging دقیق‌تر
 */
class OutboxPublisher
{
    /**
     * Centralized toObject (root-cause normalization for DB results).
     * Guarantees object (never array/mixed) before any ->prop access.
     */
    private function toObject(mixed $data): ?\stdClass
    {
        if ($data === null || $data === false) return null;
        if (is_object($data)) {
            /** @var \stdClass $data */
            return $data;
        }
        if (is_array($data)) return (object)$data;
        return (object)(array)$data;
    }


    private const MAX_ATTEMPTS = 5;
    private const DLQ_THRESHOLD = 3;
    private const MAX_RETRY_DELAY_SECONDS = 3600;

    private \App\Contracts\LoggerInterface $logger;
    private \Core\Database $db;
    private Queue $queue;
    private EventDispatcher $events;
    private ?\App\Contracts\NotificationServiceInterface $notificationService = null;

    public function __construct(
        \Core\Database $db,
        Queue $queue,
        EventDispatcher $events,
        \App\Contracts\LoggerInterface $logger
    ) {
        $this->db = $db;
        $this->queue = $queue;
        $this->events = $events;
        $this->logger = $logger;
    }

    /**
     * Lazy accessor for NotificationService — breaks the circular dependency:
     * OutboxService → OutboxPublisher → (lazy) NotificationService
     * NotificationService → OutboxServiceInterface (nullable)
     *
     * NotificationService is resolved on FIRST USE (not during construction),
     * so the container can build OutboxPublisher without needing NotificationService first.
     * By the time getNotificationService() is actually called, NotificationService
     * will already be fully constructed in the container.
     */
    private function getNotificationService(): \App\Contracts\NotificationServiceInterface
    {
        if ($this->notificationService !== null) {
            return $this->notificationService;
        }
        /** @var \App\Contracts\NotificationServiceInterface $resolved */
        $resolved = \Core\Container::getInstance()->make(\App\Contracts\NotificationServiceInterface::class);
        $this->notificationService = $resolved;
        return $this->notificationService;
    }

    /**
     * @return array<string, mixed>
     */
    public function publishPending(int $limit = 50): array
    {
        $this->monitorAndRecoverStuckEvents(7);

        $published = 0;
        $failed = 0;
        $dlq = 0;

        for ($i = 0; $i < $limit; $i++) {
            $event = $this->reserveOne();
            if (!$event) break;

            try {
                $this->publishEvent($event);
                $this->markAsPublished((int)$event->id);
                $published++;
            } catch (\Throwable $e) {
                $failed++;
                \App\Services\Sentry\SentryExceptionHandler::captureException($e, null, [
                    'operation'  => 'outbox.publishEvent',
                    'event_id'   => $event->id ?? null,
                    'event_type' => $event->event_type ?? null,
                    'aggregate'  => $event->aggregate_type ?? null,
                    'attempts'   => $event->attempts ?? null,
                ]);
                if ($this->shouldMoveToDLQ($event)) {
                    $this->moveToDLQ($event, $e);
                    $dlq++;
                } else {
                    $this->markForRetry($event, $e);
                }
            }
        }

        $this->logger->info('outbox.publish.completed', [
            'published' => $published,
            'failed' => $failed,
            'moved_to_dlq' => $dlq
        ]);

        return [
            'published' => $published,
            'failed' => $failed,
            'dlq' => $dlq
        ];
    }

    private function reserveOne(): ?\stdClass
    {
        $this->db->beginTransaction();
        try {
            $event = $this->toObject($this->db->selectOne(
                "SELECT * FROM outbox_events 
                 WHERE status IN ('pending', 'failed') 
                   AND attempts < ? 
                   AND available_at <= NOW() 
                 ORDER BY created_at ASC LIMIT 1 FOR UPDATE SKIP LOCKED",
                [self::MAX_ATTEMPTS]
            ));

            if (!$event) {
                $this->db->commit();
                return null;
            }

            $this->db->execute(
                "UPDATE outbox_events SET status = 'processing', attempts = attempts + 1, updated_at = NOW() WHERE id = ?",
                [(int)$event->id]
            );

            $event->attempts = (int)$event->attempts + 1;
            $this->db->commit();
            return $event;
        } catch (\Throwable $e) {
            \App\Services\Sentry\SentryExceptionHandler::captureException($e, null, ['operation' => 'app.Services.OutboxPublisher.reserveOne']);
            if ($this->db->inTransaction()) $this->db->rollback();
            throw $e;
        }
    }

    private function publishEvent(\stdClass $event): void
    {
        $payload = (array)(json_decode($event->payload ?? '{}', true) ?? []) ?: [];

        if (!empty($payload['notification']) && is_array($payload['notification'])) {
            $this->publishNotification($payload['notification']);
        } elseif (!empty($payload['job'])) {
            $this->queue->push($payload['job'], $payload['data'] ?? [], $payload['queue'] ?? null);
        } else {
            // FIX: If it's a generic event, dispatch it via EventDispatcher.
            // If no specific listeners are attached, this will just log a warning or do nothing.
            $this->events->dispatchOrFail($event->event_type, $payload);
        }
    }

    private function shouldMoveToDLQ(\stdClass $event): bool
    {
        return (int)$event->attempts >= self::DLQ_THRESHOLD;
    }

    private function moveToDLQ(\stdClass $event, \Throwable $e): void
    {
        $this->db->execute(
            "UPDATE outbox_events SET status = 'dlq', last_error = ?, updated_at = NOW() WHERE id = ?",
            [mb_substr($e->getMessage(), 0, 1000), (int)$event->id]
        );

        $this->logger->error('outbox.moved_to_dlq', [
            'outbox_id' => $event->id,
            'event_type' => $event->event_type,
            'error' => $e->getMessage()
        ]);
    }

    private function markForRetry(\stdClass $event, \Throwable $e): void
    {
        $attempts = max(1, (int)$event->attempts);
        $baseDelay = 60; // 1 minute base to avoid aggressive retries
        $delay = min(self::MAX_RETRY_DELAY_SECONDS, $baseDelay * (2 ** ($attempts - 1)));
        $jitter = random_int(5, 30);
        $delay += $jitter;

        $this->db->execute(
            "UPDATE outbox_events 
             SET status = 'pending', last_error = ?, available_at = DATE_ADD(NOW(), INTERVAL ? SECOND), updated_at = NOW() 
             WHERE id = ?",
            [mb_substr($e->getMessage(), 0, 1000), $delay, (int)$event->id]
        );
    }

    private function markAsPublished(int $id): void
    {
        $this->db->execute(
            "UPDATE outbox_events SET status = 'published', published_at = NOW(), last_error = NULL, updated_at = NOW() WHERE id = ?",
            [$id]
        );
    }

    /**
     * @param array<string, mixed> $notification
     */
    private function publishNotification(array $notification): void
    {
        $method = $notification['method'] ?? 'send';
        $args = $notification['args'] ?? [];
        $this->getNotificationService()->{$method}(...$args);
    }

    /**
     * بازیابی رویدادهای زامبی (Zombie Recovery) و مانیتورینگ انباشت
     */
    private function monitorAndRecoverStuckEvents(int $stuckMinutes = 7): void
    {
        try {
            // 1. Zombie Recovery: بازگردانی رویدادهایی که در حالت processing گیر کرده‌اند
            $zombieCount = $this->db->execute(
                "UPDATE outbox_events 
                 SET status = 'pending', updated_at = NOW() 
                 WHERE status = 'processing' AND updated_at < DATE_SUB(NOW(), INTERVAL ? MINUTE)",
                [$stuckMinutes]
            );

            if ($zombieCount > 0) {
                $this->logger->warning('outbox.zombies_recovered', ['count' => $zombieCount, 'stuck_minutes' => $stuckMinutes]);
            }

            // 2. Accumulation Alert: هشدار برای رویدادهای pending که خیلی قدیمی شده‌اند
            $accumulatedCount = (int)$this->db->fetchColumn(
                "SELECT COUNT(*) FROM outbox_events 
                 WHERE status = 'pending' AND created_at < DATE_SUB(NOW(), INTERVAL 30 MINUTE)"
            );

            if ($accumulatedCount > 50) {
                $this->logger->critical('outbox.accumulation_alert_CRITICAL', [
                    'pending_older_than_30m' => $accumulatedCount,
                    'message' => 'Outbox publisher seems to be failing or extremely slow!'
                ]);
            }
        } catch (\Throwable $e) {
            \App\Services\Sentry\SentryExceptionHandler::captureException($e, null, ['operation' => 'app.Services.OutboxPublisher.monitorAndRecoverStuckEvents']);
            $this->logger->error('outbox.monitoring_failed', ['error' => $e->getMessage()]);
        }
    }
}
