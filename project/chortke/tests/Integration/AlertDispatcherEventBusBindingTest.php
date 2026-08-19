<?php

declare(strict_types=1);

namespace Tests\Integration;

use PHPUnit\Framework\TestCase;
use Mockery as m;

/**
 * Regression test for BUGFIX-LISTENER-DI-2026-06.
 *
 * If AlertDispatcher is constructed during early bootstrap (before the
 * Core\EventDispatcher singleton binding is registered), the container
 * auto-wires a transient EventDispatcher and caches it inside the
 * AlertDispatcher instance. Listeners are later registered on the *real*
 * singleton, so the constructor-injected dispatcher receives none of them
 * and AlertDispatcher::dispatch() silently logs `alert.no_listeners` for
 * every alert in production.
 *
 * The fix is that AlertDispatcher re-resolves the canonical
 * EventDispatcher via EventDispatcher::getInstance() at use time and
 * heals its own internal reference on first use.
 */
/**
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class AlertDispatcherEventBusBindingTest extends TestCase
{
    protected function tearDown(): void { m::close(); parent::tearDown(); }

    /** @test */
    public function source_uses_active_event_dispatcher_accessor_in_dispatch(): void
    {
        $src = $this->readFile(
            dirname(__DIR__, 2) . '/app/Services/Sentry/Alerting/AlertDispatcher.php'
        );

        $this->assertStringContainsString(
            'private function activeEventDispatcher()',
            $src,
            'AlertDispatcher must expose a re-resolution helper.'
        );
        $this->assertStringContainsString(
            'EventDispatcher::getInstance()',
            $src,
            'Helper must re-resolve the canonical singleton instance.'
        );
        // dispatch() must use the helper, not the raw injected property
        $this->assertRegExp(
            '/function\s+dispatch\([^)]*\).*?activeEventDispatcher\(\)/s',
            $src,
            'dispatch() must obtain the bus via activeEventDispatcher().'
        );
    }

    /** @test */
    public function constructor_does_not_lock_to_a_specific_event_dispatcher_forever(): void
    {
        // White-box assertion: the property assignment exists but is not
        // the source of truth for runtime resolution — it can be healed
        // when the canonical singleton differs.
        $src = $this->readFile(
            dirname(__DIR__, 2) . '/app/Services/Sentry/Alerting/AlertDispatcher.php'
        );
        $this->assertStringContainsString(
            '$this->eventDispatcher = $canonical;',
            $src,
            'AlertDispatcher must heal its internal reference to the canonical bus.'
        );
    }

    /** @test */
    public function rebinding_emits_observable_log_for_ops(): void
    {
        $src = $this->readFile(
            dirname(__DIR__, 2) . '/app/Services/Sentry/Alerting/AlertDispatcher.php'
        );
        $this->assertStringContainsString(
            'alert.dispatcher.eventbus_rebound',
            $src,
            'Rebind must be observable so this regression is detected in production.'
        );
    }

    /**
     * @test
     * End-to-end semantics: build a stale AlertDispatcher with a
     * throwaway EventDispatcher (simulating early-bootstrap injection),
     * then verify it heals to the canonical singleton when dispatch() runs.
     */
    public function dispatch_uses_canonical_event_dispatcher_even_when_constructor_was_given_a_stale_one(): void
    {
        // Suppress unexpected output from logger/event-dispatcher during test
        $this->expectOutputRegex('/.*/');

        // Reset the EventDispatcher singleton for a clean slate
        $ref = new \ReflectionClass(\Core\EventDispatcher::class);
        $prop = $ref->getProperty('instance');
        $prop->setAccessible(true);
        $prop->setValue(null, null);

        $container = \Core\Container::getInstance();

        // Mock dependencies that AlertDispatcher needs — we don't need the full bootstrap
        $queue = m::mock(\Core\Queue::class);
        $queue->shouldIgnoreMissing();

        $logger = m::mock(\Core\Logger::class);
        $logger->shouldIgnoreMissing();

        $sentryModel = m::mock(\App\Models\SentryModel::class);
        $sentryModel->shouldIgnoreMissing();

        $appSettings = m::mock(\App\Services\Settings\AppSettings::class);
        $appSettings->shouldIgnoreMissing();
        $appSettings->shouldReceive('get')->andReturn(true);

        // Register a real EventDispatcher singleton in the container
        $canonical = new \Core\EventDispatcher($queue);
        $container->instance(\Core\EventDispatcher::class, $canonical);

        // Register a listener on the canonical bus
        $callCount = 0;
        $canonical->listen('alert.requested', function ($event) use (&$callCount) {
            $callCount++;
        });

        // Simulate early-bootstrap: build AlertDispatcher with a STALE dispatcher
        $stale = new \Core\EventDispatcher($queue);
        $alertDispatcher = new \App\Services\Sentry\Alerting\AlertDispatcher(
            $sentryModel,
            $logger,
            $stale,            // ← stale, no listeners
            $appSettings
        );

        // Sanity: before dispatch the internal ref is the stale one
        $internalRef = new \ReflectionClass($alertDispatcher);
        $prop = $internalRef->getProperty('eventDispatcher');
        $prop->setAccessible(true);
        $this->assertSame($stale, $prop->getValue($alertDispatcher),
            'Pre-condition: AlertDispatcher should be holding the stale dispatcher.');

        // Act
        $result = $alertDispatcher->dispatch([
            'severity' => 'critical',
            'title'    => 'unit-test-alert',
            'message'  => 'irrelevant',
        ]);

        // Assert: listener fired exactly once → AlertDispatcher used the canonical bus
        $this->assertTrue($result, 'dispatch() must succeed when canonical bus has listeners.');
        $this->assertSame(1, $callCount, 'Listener on canonical bus must have been invoked.');

        // And the stale reference was healed
        $this->assertSame($canonical, $prop->getValue($alertDispatcher),
            'AlertDispatcher must have rebound to the canonical EventDispatcher.');
    }
    private function readFile(string $path): string
    {
        $source = file_get_contents($path);
        $this->assertIsString($source);
        return $source;
    }

}
