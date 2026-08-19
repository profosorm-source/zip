<?php

declare(strict_types=1);

namespace Tests\Unit\Listeners;

use PHPUnit\Framework\TestCase;
use Mockery as m;

/**
 * تست‌های Migration Outbox برای Listeners
 */
/**
 * @group architecture
 */
class OutboxMigrationListenersTest extends TestCase
{
    protected function tearDown(): void
    {
        m::close();
        parent::tearDown();
    }

    /** @test */
    public function score_projection_listener_has_outbox(): void
    {
        $ref = new \ReflectionClass(\App\Listeners\ScoreProjectionListener::class);
        $constructor = $ref->getConstructor();
        $this->assertNotNull($constructor);
        $params = $constructor->getParameters();
        $paramTypes = array_map(function($p) {
            $t = $p->getType();
            return $t instanceof \ReflectionNamedType ? $t->getName() : '';
        }, $params);

        $this->assertContains('App\\Contracts\\OutboxServiceInterface', $paramTypes);
    }



    /** @test */
    public function wallet_deposit_listener_has_outbox(): void
    {
        $ref = new \ReflectionClass(\App\Listeners\WalletDepositRequestListener::class);
        $props = $ref->getProperties();
        $propNames = array_map(fn($p) => $p->getName(), $props);

        $this->assertContains('outbox', $propNames);
    }
}
