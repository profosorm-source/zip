<?php

declare(strict_types=1);

namespace App\Contracts;

/**
 * قرارداد Domain Event
 *
 * هر Event بیزینسی که از طریق Outbox منتشر میشه باید این interface رو implement کنه.
 * این تضمین میکنه که OutboxService بدون حدس و fallback، aggregate و payload رو استخراج کنه.
 */
interface DomainEvent
{
    /**
     * نوع aggregate (مثلاً 'wallet', 'investment', 'score')
     */
    public function aggregateType(): string;

    /**
     * شناسه aggregate (مثلاً user_id, order_id)
     */
    public function aggregateId(): string;

    /**
     * داده‌های event به صورت آرایه — برای ذخیره در outbox_events.payload
     * @return array<string, mixed>
     */
    public function toPayload(): array;
}
