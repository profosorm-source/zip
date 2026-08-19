<?php

declare(strict_types=1);

namespace Tests\Integration\Search;

use Core\Application;
use Core\EventDispatcher;
use PHPUnit\Framework\TestCase;

final class SearchEventRegistrationRuntimeTest extends TestCase
{
    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_critical_projection_events_have_runtime_listeners_and_orphan_name_does_not(): void
    {
        $dispatcher=Application::getInstance()->container->make(EventDispatcher::class);
        foreach ([
            'wallet.deposit.completed','wallet.withdraw.completed','withdrawal.created','ticket.created',
            'direct_message.sent','content.created','crypto_deposit.completed','bank_card.created',
            'escrow.created','social_task.created','kyc.approved','prediction.created','lottery.created','investment.created',
            'social_task.deleted','prediction.deleted','lottery.deleted','coupon.deleted','investment.deleted','bank_card.deleted',
            \App\Events\SettingsUpdated::class,
            \App\Events\WithdrawalCreatedEvent::class,
            \App\Events\WithdrawalApprovedEvent::class,
            'investment.profit_applied',
        ] as $event) {
            $this->assertNotSame([], $dispatcher->getListeners($event), "Runtime listener missing for {$event}");
        }
        $this->assertSame([], $dispatcher->getListeners('direct_message.created'));
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_settings_updated_listener_executes_and_invalidates_distributed_cache(): void
    {
        $this->expectOutputRegex('/.*/');
        $container = Application::getInstance()->container;
        $dispatcher = $container->make(EventDispatcher::class);
        $cache = $container->make(\Core\Cache::class);
        $key = 'system:settings:v2';

        try {
            $this->assertSame('redis', $cache->driver(), 'Integration runtime must exercise the real Redis cache path.');
            $this->assertTrue($cache->putSeconds($key, ['sentinel' => true], 60));
            $this->assertSame(['sentinel' => true], $cache->get($key));

            $applicationUrlBeforeDispatch = config('app.url');
            $dispatcher->dispatchOrFail(
                \App\Events\SettingsUpdated::class,
                new \App\Events\SettingsUpdated(['site.name'])
            );

            $this->assertNull($cache->get($key));
            $this->assertSame(
                $applicationUrlBeforeDispatch,
                config('app.url'),
                'Database settings invalidation must not erase immutable application configuration.'
            );
        } finally {
            $cache->forget($key);
        }
    }
}
