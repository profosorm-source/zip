<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use PHPUnit\Framework\TestCase;

/**
 * تست فاز B — Dual Write Elimination (Outbox-First)
 */
/**
 * @group architecture
 */
class OutboxFirstMigrationTest extends TestCase
{


    public function testSendBulkNotificationJobUsesOutboxFirst(): void
    {
        $ref = new \ReflectionClass(\App\Jobs\Notification\SendBulkNotificationJob::class);
        $ctor = $ref->getConstructor();
        $this->assertNotNull($ctor);
        $params = $ctor->getParameters();
        $names = array_map(fn($p) => $p->getName(), $params);

        $this->assertContains('outbox', $names,
            'SendBulkNotificationJob باید outbox inject کنه');
    }


    // ─── Round 2: circuit breaker fallbacks + batch jobs ────────────────






}
