<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\LoggerInterface;
use App\Services\AuditTrail;
use Core\Database;

/**
 * Transactional outbox writer (نسخه ارتقایافته و مقاوم در برابر تصادم شناسه‌ها).
 *
 * این سرویس فقط event را در همان transaction دیتابیس ثبت می‌کند. انتشار واقعی در
 * OutboxPublisher انجام می‌شود تا transaction مالی به queue/notification وابسته نباشد.
 */
class OutboxService implements \App\Contracts\OutboxServiceInterface
{


    private AuditTrail $auditTrail;
    private \Core\Database $db;
    private \App\Contracts\LoggerInterface $logger;
    private \App\Services\OutboxPublisher $outboxPublisher;

    public function __construct(
        \Core\Database $db,
        \App\Contracts\LoggerInterface $logger,
        AuditTrail $auditTrail,
        \App\Services\OutboxPublisher $outboxPublisher
    ) {
        $this->db = $db;
        $this->logger = $logger;
        $this->auditTrail = $auditTrail;
        $this->outboxPublisher = $outboxPublisher;
    }

    public function record(
        string $aggregateType,
        string|int $aggregateId,
        string $eventType,
        array $payload = [],
        ?string $availableAt = null
    ): bool {
        $aggregateType = $this->sanitizeToken($aggregateType, 80);
        $eventType = $this->sanitizeToken($eventType, 120);
        $aggregateId = mb_substr((string)$aggregateId, 0, 128);

        if ($aggregateType === '' || $eventType === '' || $aggregateId === '') {
            throw new \InvalidArgumentException('Invalid outbox event identity.');
        }

        $stmt = $this->db->prepare(
            "INSERT INTO outbox_events
             (aggregate_type, aggregate_id, event_type, payload, status, attempts, available_at, created_at)
             VALUES (?, ?, ?, ?, 'pending', 0, ?, NOW())"
        );

        $res = $stmt->execute([
            $aggregateType,
            $aggregateId,
            $eventType,
            json_encode($payload, JSON_UNESCAPED_UNICODE),
            $availableAt ?? date('Y-m-d H:i:s'),
        ]);

        if ($res) {
            try {
                $this->auditTrail->record(
                    'outbox.event.recorded',
                    null,
                    [
                        'aggregate_type' => $aggregateType,
                        'aggregate_id' => $aggregateId,
                        'event_type' => $eventType,
                        'payload' => $payload,
                        'available_at' => $availableAt ?? date('Y-m-d H:i:s')
                    ]
                );
            } catch (\Throwable $e) {
                $this->logger->warning('outbox.audit.record.failed', ['error' => $e->getMessage()]);
            }
        }

        return $res;
    }

    /**
     * @return array<string, mixed>
     */
    public function publishPending(int $limit = 50): array
    {
        return $this->outboxPublisher->publishPending($limit);
    }

    public function recordEvent(object $event, ?string $availableAt = null): bool
    {
        $eventClass = get_class($event);

        if ($event instanceof \App\Contracts\DomainEvent) {
            return $this->record(
                $event->aggregateType(),
                $event->aggregateId(),
                $eventClass,
                $event->toPayload(),
                $availableAt
            );
        }

        $this->logger->info('outbox.recordEvent.fallback', [
            'event_class' => $eventClass,
            'hint' => 'Consider implementing App\\Contracts\\DomainEvent',
        ]);

        $aggregateType = $this->inferAggregateType($eventClass);
        $aggregateId = $this->inferAggregateId($event);
        $payload = method_exists($event, 'toPayload')
            ? $event->toPayload()
            : (method_exists($event, 'getData') ? $event->getData() : (array)$event);

        return $this->record($aggregateType, $aggregateId, $eventClass, $payload, $availableAt);
    }

    private function inferAggregateType(string $eventClass): string
    {
        $base = basename(str_replace('\\', '/', $eventClass));
        $base = (string) preg_replace('/Event$/', '', $base);
        $snake = strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', $base));
        $parts = explode('_', $snake);
        return $parts[0] ?? 'general';
    }

    /**
     * ─── رفع باگ تصادم شناسه‌های 0 در جدول Outbox (Aggregate ID Collision Fix) ───
     * جلوگیری از بازگشت شناسه ثابت '0':
     * در صورتی که رویداد فاقد کلیدهای استاندارد باشد، به‌جای بازگرداندن '0' که منجر به تصادم قفل
     * در OutboxPublisher می‌شود، یک هش یکتا و دترمینستیک بر مبنای محتوای رویداد تولید می‌شود.
     */
    private function inferAggregateId(object $event): string
    {
        $data = method_exists($event, 'getData') ? $event->getData() : (array)$event;
        
        foreach (['user_id', 'userId', 'id', 'aggregate_id', 'wallet_id', 'investment_id', 'tracking_code', 'authority', 'card_id'] as $key) {
            if (isset($data[$key]) && (string)$data[$key] !== '') {
                return (string)$data[$key];
            }
        }
        
        // تولید هش منحصربه‌فرد و پایدار جهت مهار انباشت قفل روی شناسه 0
        return 'fallback_' . substr(md5((string)json_encode($data)), 0, 16);
    }

    private function sanitizeToken(string $value, int $max): string
    {
        $value = preg_replace('/[^A-Za-z0-9_.:-]/', '_', trim((string)$value)) ?? '';
        return mb_substr($value, 0, $max);
    }
}
