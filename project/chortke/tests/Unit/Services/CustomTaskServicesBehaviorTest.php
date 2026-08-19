<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use Mockery as m;

/**
 * @group architecture
 */
class CustomTaskServicesBehaviorTest extends TestCase
{
    protected function tearDown(): void { m::close(); parent::tearDown(); }

    // ─── AdminCustomTaskService ─────────────────────────────────

    /** @test */
    public function admin_service_has_outbox_injection(): void
    {
        $ref = new \ReflectionClass(\App\Services\CustomTask\AdminCustomTaskService::class);
        $constructor = $ref->getConstructor();
        $this->assertNotNull($constructor);
        $params = $constructor->getParameters();
        $names = array_map(fn($p) => $p->getName(), $params);
        $this->assertContains('outboxService', $names);
    }




    // ─── CustomTaskModerationService ────────────────────────────

    /** @test */
    public function moderation_service_has_outbox(): void
    {
        $ref = new \ReflectionClass(\App\Services\CustomTask\CustomTaskModerationService::class);
        $constructor = $ref->getConstructor();
        $this->assertNotNull($constructor);
        $params = $constructor->getParameters();
        $names = array_map(fn($p) => $p->getName(), $params);
        $this->assertContains('outbox', $names);
    }



}
