<?php

declare(strict_types=1);

namespace App\Contracts;

interface OutboxServiceInterface
{
    /**
     * Records an event in the transactional outbox.
     *
     * @param string $aggregateType
     * @param string|int $aggregateId
     * @param string $eventType
     * @param array<string, mixed> $payload
     * @param string|null $availableAt
     * @return bool
     */
    public function record(
        string $aggregateType,
        string|int $aggregateId,
        string $eventType,
        array $payload = [],
        ?string $availableAt = null
    ): bool;

    /**
     * فاز ۱ - ثبت Domain Event بدون نیاز به دانش aggregate
     */
    public function recordEvent(object $event, ?string $availableAt = null): bool;
}
