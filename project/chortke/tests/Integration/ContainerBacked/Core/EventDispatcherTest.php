<?php

namespace Tests\Integration\ContainerBacked\Core;

use PHPUnit\Framework\TestCase;
use Core\Queue;
use Core\Database;
use Core\EventDispatcher;
use Core\Event;
use Mockery as m;

/**
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class EventDispatcherTest extends TestCase
{
    private \Core\Container $container;

    protected function setUp(): void
    {
        parent::setUp();
        ini_set('error_log', sys_get_temp_dir() . '/chortke-phpunit-error.log');
        $this->expectOutputRegex('/.*/');
        $this->container = \Core\Container::getInstance();

    }

    protected function tearDown(): void
    {
        // فقط mock هایی که خودمون register کردیم رو forget کن
        // Database رو forget نکن — بقیه تست‌ها بهش نیاز دارن

        m::close();
        parent::tearDown();
    }

    /** @test */
    public function it_continues_to_propagate_events_if_one_listener_throws_exception(): void
    {
        $queueMock = m::mock(Queue::class);
        $dbMock = m::mock(Database::class);
        $loggerMock = m::mock(\App\Contracts\LoggerInterface::class);

        $this->container->instance(Queue::class, $queueMock);
        $this->container->instance(Database::class, $dbMock);
        $this->container->instance(\App\Contracts\LoggerInterface::class, $loggerMock);

        $dispatcher = new EventDispatcher($queueMock);

        $firstExecuted = false;
        $secondExecuted = false;

        // Register first listener that throws an exception
        $dispatcher->listen('test.event', function ($event) use (&$firstExecuted) {
            $firstExecuted = true;
            throw new \RuntimeException("Something went wrong in listener 1");
        });

        // Register second listener that should execute successfully
        $dispatcher->listen('test.event', function ($event) use (&$secondExecuted) {
            $secondExecuted = true;
        });

        // The logger should receive the error call
        $loggerMock->shouldReceive('error')->with('event.listener_failed', m::on(function ($args) {
            return $args['event'] === 'test.event' &&
                   $args['listener'] === 'closure' &&
                   str_contains($args['error'], 'Something went wrong in listener 1');
        }))->once();

        // The logger should receive info calls (allow any number due to optional auditing dispatch loops)
        $loggerMock->shouldReceive('info')->with('event.dispatched', m::any());

        // The database should receive an insert into event_failures
        $dbMock->shouldReceive('execute')->with(
            m::on(function ($sql) {
                return str_contains($sql, 'INSERT INTO event_failures');
            }),
            m::on(function ($bindings) {
                return $bindings[0] === 'test.event' &&
                       $bindings[1] === 'closure' &&
                       str_contains($bindings[3], 'Something went wrong in listener 1');
            })
        )->once();

        $dispatcher->dispatch('test.event', ['some' => 'data']);

        $this->assertTrue($firstExecuted);
        $this->assertTrue($secondExecuted);
    }

    /** @test */
    public function it_continues_execution_even_if_logger_throws_exception(): void
    {
        $queueMock = m::mock(Queue::class);
        $dbMock = m::mock(Database::class);
        $loggerMock = m::mock(\App\Contracts\LoggerInterface::class);

        $this->container->instance(Queue::class, $queueMock);
        $this->container->instance(Database::class, $dbMock);
        $this->container->instance(\App\Contracts\LoggerInterface::class, $loggerMock);

        $dispatcher = new EventDispatcher($queueMock);

        $executed = false;

        $dispatcher->listen('test.event', function ($event) use (&$executed) {
            $executed = true;
        });

        // Logger throws an exception when info is called
        $loggerMock->shouldReceive('info')->andThrow(new \RuntimeException("Logger is broken"));

        $dispatcher->dispatch('test.event', ['some' => 'data']);

        $this->assertTrue($executed);
    }

    /** @test */
    public function it_gracefully_handles_missing_event_name_in_queued_event(): void
    {
        $queueMock = m::mock(Queue::class);
        $loggerMock = m::mock(\App\Contracts\LoggerInterface::class);

        $this->container->instance(Queue::class, $queueMock);
        $this->container->instance(\App\Contracts\LoggerInterface::class, $loggerMock);

        $dispatcher = new EventDispatcher($queueMock);

        $loggerMock->shouldReceive('warning')->with('event.queue.missing_payload', m::any())->once();

        $dispatcher->processQueuedEvent(['id' => 123, 'data' => []]);

        $this->assertTrue(true); // Avoid PHPUnit risky test warning
    }

    /** @test */
    public function it_safely_dlqs_reconstruction_failures_in_process_queued_event(): void
    {
        $queueMock = m::mock(Queue::class);
        $dbMock = m::mock(Database::class);
        $loggerMock = m::mock(\App\Contracts\LoggerInterface::class);

        $this->container->instance(Queue::class, $queueMock);
        $this->container->instance(Database::class, $dbMock);
        $this->container->instance(\App\Contracts\LoggerInterface::class, $loggerMock);

        $dispatcher = new EventDispatcher($queueMock);

        // NonExistent class → reconstructEvent returns null → GenericEvent fallback with warning
        $job = [
            'id' => 999,
            'data' => [
                'event_name' => 'test.queued.event',
                'event_class' => 'NonExistentEventClassThatWillFail',
                'event_data' => ['key' => 'value']
            ]
        ];

        // The logger should receive warning for downgraded reconstruction
        $loggerMock->shouldReceive('warning')->with('event.queue.reconstruction_downgraded', m::on(function ($args) {
            return $args['event_name'] === 'test.queued.event' && $args['job_id'] === 999;
        }))->once();

        // GenericEvent will be dispatched but no listeners registered → info log for dispatch
        $loggerMock->shouldReceive('info')->withAnyArgs()->zeroOrMoreTimes();
        $loggerMock->shouldReceive('warning')->withAnyArgs()->zeroOrMoreTimes();

        $dispatcher->processQueuedEvent($job);

        $this->assertTrue(true); // Avoid PHPUnit risky test warning
    }

    /** @test */
    public function it_handles_missing_event_class_gracefully(): void
    {
        $queueMock = m::mock(Queue::class);
        $loggerMock = m::mock(\App\Contracts\LoggerInterface::class);

        $this->container->instance(Queue::class, $queueMock);
        $this->container->instance(\App\Contracts\LoggerInterface::class, $loggerMock);

        $dispatcher = new EventDispatcher($queueMock);

        // No event_class → falls back to GenericEvent with warning
        $job = [
            'id' => 777,
            'data' => [
                'event_name' => 'test.no_class.event',
                'event_data' => ['foo' => 'bar']
            ]
        ];

        // Should log warning for reconstruction downgrade (null class)
        $loggerMock->shouldReceive('warning')->with('event.queue.reconstruction_downgraded', m::on(function ($args) {
            return $args['event_name'] === 'test.no_class.event' && $args['job_id'] === 777;
        }))->once();

        $loggerMock->shouldReceive('info')->withAnyArgs()->zeroOrMoreTimes();
        $loggerMock->shouldReceive('warning')->withAnyArgs()->zeroOrMoreTimes();

        $dispatcher->processQueuedEvent($job);

        $this->assertTrue(true);
    }
}
