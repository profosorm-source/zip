<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use Mockery as m;

/**
 * @group architecture
 */
class BulkOperationsServiceBehaviorTest extends TestCase
{
    protected function tearDown(): void { m::close(); parent::tearDown(); }

    /** @test */
    public function has_outbox_injection(): void
    {
        $ref = new \ReflectionClass(\App\Services\BulkOperationsService::class);
        $constructor = $ref->getConstructor();
        $this->assertNotNull($constructor);
        $params = $constructor->getParameters();
        $names = array_map(fn($p) => $p->getName(), $params);
        $this->assertContains('outbox', $names);
    }



    /** @test */
    public function outbox_is_nullable(): void
    {
        $ref = new \ReflectionClass(\App\Services\BulkOperationsService::class);
        $prop = $ref->getProperty('outbox');
        $prop->setAccessible(true);
        $type = $prop->getType();
        $this->assertNotNull($type);
        $this->assertTrue($type->allowsNull());
    }
}
