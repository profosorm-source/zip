<?php

declare(strict_types=1);

namespace Tests\Unit\Listeners;

use PHPUnit\Framework\TestCase;
use Mockery as m;

use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
/**
 * @group architecture
 */
class ListenersBehaviorTest extends TestCase
{
    // انتظاراتِ Mockery را به ادعای واقعی PHPUnit تبدیل می‌کند،
    // تا ادعای تهی برای دفعِ هشدارِ risky لازم نباشد.
    use MockeryPHPUnitIntegration;

    protected function tearDown(): void { m::close(); parent::tearDown(); }

    // ─── NotificationRequestListener ────────────────────────────

    /** @test */
    public function notification_listener_handles_event_object(): void
    {
        $container = m::mock('Core\\Container');
        $logger = m::mock('App\\Contracts\\LoggerInterface'); $logger->shouldIgnoreMissing();
        $notifService = m::mock('App\\Services\\Notification\\NotificationService');
        $notifService->shouldIgnoreMissing();

        $container->shouldReceive('make')->andReturn($notifService);
        $notifService->shouldReceive('send')->once()->andReturn(1);

        $listener = new \App\Listeners\NotificationRequestListener($container, $logger);

        $event = new \App\Events\NotificationRequestedEvent(42, 'system', 'Test', 'Hello');

        $listener->handle($event);
    }

    /** @test */
    public function notification_listener_handles_array_event(): void
    {
        $container = m::mock('Core\\Container');
        $logger = m::mock('App\\Contracts\\LoggerInterface'); $logger->shouldIgnoreMissing();
        $notifService = m::mock('App\\Services\\Notification\\NotificationService');

        $container->shouldReceive('make')->andReturn($notifService);
        $notifService->shouldReceive('send')->once();

        $listener = new \App\Listeners\NotificationRequestListener($container, $logger);

        $listener->handle([
            'user_id' => 10,
            'type' => 'info',
            'title' => 'Test',
            'message' => 'Array event',
        ]);
    }

    /** @test */
    public function notification_listener_skips_without_user_id(): void
    {
        $container = m::mock('Core\\Container');
        $logger = m::mock('App\\Contracts\\LoggerInterface');
        $logger->shouldReceive('warning')->with('notification.request.missing_user', m::any())->once();

        $listener = new \App\Listeners\NotificationRequestListener($container, $logger);
        $listener->handle(['type' => 'info']); // no user_id
    }

    /** @test */
    public function notification_listener_handles_generic_event(): void
    {
        $container = m::mock('Core\\Container');
        $logger = m::mock('App\\Contracts\\LoggerInterface'); $logger->shouldIgnoreMissing();
        $notifService = m::mock('App\\Services\\Notification\\NotificationService');

        $container->shouldReceive('make')->andReturn($notifService);
        $notifService->shouldReceive('send')->once();

        $listener = new \App\Listeners\NotificationRequestListener($container, $logger);

        $event = new \Core\GenericEvent([
            'user_id' => 5,
            'type' => 'test',
            'title' => 'GenericEvent',
            'message' => 'From outbox',
        ]);

        $listener->handle($event);
    }

    // ─── ScoreProjectionListener ────────────────────────────────

    /** @test */
    public function score_projection_listener_has_outbox_injection(): void
    {
        $ref = new \ReflectionClass(\App\Listeners\ScoreProjectionListener::class);
        $constructor = $ref->getConstructor();
        $this->assertNotNull($constructor);
        $params = $constructor->getParameters();
        $types = array_map(fn($p) => ($p->getType() instanceof \ReflectionNamedType) ? $p->getType()->getName() : '', $params);

        $this->assertContains('App\\Contracts\\OutboxServiceInterface', $types);
        $this->assertContains('App\\Contracts\\LoggerInterface', $types);
    }



    // ─── WalletDepositRequestListener ───────────────────────────

    /** @test */
    public function wallet_deposit_listener_has_outbox(): void
    {
        $ref = new \ReflectionClass(\App\Listeners\WalletDepositRequestListener::class);
        $props = array_map(fn($p) => $p->getName(), $ref->getProperties());
        $this->assertContains('outbox', $props);
    }


}
