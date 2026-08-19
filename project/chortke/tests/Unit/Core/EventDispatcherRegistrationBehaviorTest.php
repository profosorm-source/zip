<?php

declare(strict_types=1);

namespace Tests\Unit\Core;

use App\Events\SettingsUpdated;
use Core\Event;
use Core\EventDispatcher;
use Core\Queue;
use InvalidArgumentException;
use Mockery as m;
use PHPUnit\Framework\TestCase;

final class EventDispatcherRegistrationBehaviorTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->expectOutputRegex('/.*/');
    }

    protected function tearDown(): void
    {
        m::close();
        parent::tearDown();
    }

    public function test_exact_and_wildcard_listeners_share_one_stable_priority_order(): void
    {
        $dispatcher = $this->dispatcher();
        $execution = [];

        $dispatcher->listen('wallet.updated', static function () use (&$execution): void {
            $execution[] = 'exact-low';
        }, 10);
        $dispatcher->listenPattern('wallet.*', static function () use (&$execution): void {
            $execution[] = 'pattern-high-first';
        }, 20);
        $dispatcher->listen('wallet.updated', static function () use (&$execution): void {
            $execution[] = 'exact-high-second';
        }, 20);

        $dispatcher->dispatch('wallet.updated');

        $this->assertSame(
            ['pattern-high-first', 'exact-high-second', 'exact-low'],
            $execution
        );
    }

    public function test_typed_event_is_dispatched_without_being_downgraded_to_generic_event(): void
    {
        $dispatcher = $this->dispatcher();
        $received = null;

        $dispatcher->listen(SettingsUpdated::class, static function (SettingsUpdated $event) use (&$received): void {
            $received = $event;
        });

        $event = new SettingsUpdated(['site.name']);
        $dispatcher->dispatch(SettingsUpdated::class, $event);

        $this->assertSame($event, $received);
        $this->assertSame(['site.name'], $received->changedKeys);
        $this->assertSame(['changed_keys' => ['site.name']], $received->getData());
    }

    public function test_duplicate_listener_registration_is_idempotent(): void
    {
        $dispatcher = $this->dispatcher();
        $calls = 0;
        $listener = static function () use (&$calls): void {
            $calls++;
        };

        $dispatcher->listen('user.updated', $listener);
        $dispatcher->listen('user.updated', $listener);
        $dispatcher->dispatch('user.updated');

        $this->assertSame(1, $calls);
        $this->assertCount(1, $dispatcher->getListeners('user.updated'));
    }

    public function test_stopped_event_prevents_later_listener_execution(): void
    {
        $dispatcher = $this->dispatcher();
        $execution = [];

        $dispatcher->listen('workflow.stopped', static function (Event $event) use (&$execution): void {
            $execution[] = 'stopper';
            $event->stopPropagation();
        }, 100);
        $dispatcher->listen('workflow.stopped', static function () use (&$execution): void {
            $execution[] = 'must-not-run';
        }, 0);

        $dispatcher->dispatch('workflow.stopped');

        $this->assertSame(['stopper'], $execution);
    }

    /**
     * @dataProvider invalidListenerProvider
     * @param array<mixed>|string $listener
     */
    public function test_invalid_listener_is_rejected_during_registration(array|string $listener): void
    {
        $this->expectException(InvalidArgumentException::class);

        $method = new \ReflectionMethod(EventDispatcher::class, 'listen');
        $method->invoke($this->dispatcher(), 'invalid.listener', $listener);
    }

    /** @return array<string, array{0: array<mixed>|string}> */
    public function invalidListenerProvider(): array
    {
        return [
            'empty array' => [[]],
            'missing class' => [['Missing\\Listener\\Class', 'handle']],
            'missing method' => [[\stdClass::class, 'handle']],
            'class without handle' => [\stdClass::class],
            'empty method name' => [[\stdClass::class, '']],
        ];
    }

    /**
     * @dataProvider invalidEventNameProvider
     */
    public function test_invalid_event_name_is_rejected(string $eventName): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->dispatcher()->listen($eventName, static function (): void {});
    }

    /** @return array<string, array{0: string}> */
    public function invalidEventNameProvider(): array
    {
        return [
            'empty' => [''],
            'surrounding whitespace' => [' wallet.updated '],
            'embedded whitespace' => ['wallet updated'],
            'control character' => ["wallet.updated\n"],
        ];
    }

    private function dispatcher(): EventDispatcher
    {
        return new EventDispatcher(m::mock(Queue::class));
    }
}
