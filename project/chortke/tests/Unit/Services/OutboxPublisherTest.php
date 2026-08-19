<?php

namespace Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use App\Services\OutboxPublisher;
use Mockery as m;

class OutboxPublisherTest extends TestCase
{
    /** @var \Core\Database&\Mockery\MockInterface */
    private \Core\Database $db;
    /** @var \Core\Queue&\Mockery\MockInterface */
    private \Core\Queue $queue;
    /** @var \Core\EventDispatcher&\Mockery\MockInterface */
    private \Core\EventDispatcher $events;
    /** @var \App\Contracts\LoggerInterface&\Mockery\MockInterface */
    private \App\Contracts\LoggerInterface $logger;
    private OutboxPublisher $publisher;

    protected function setUp(): void
    {
        parent::setUp();
        $this->db = m::mock('Core\Database');
        $this->queue = m::mock('Core\Queue');
        $this->events = m::mock('Core\EventDispatcher');
        $this->logger = m::mock('App\Contracts\LoggerInterface');

        $this->logger->shouldIgnoreMissing();
        $this->db->shouldReceive('beginTransaction')->byDefault();
        $this->db->shouldReceive('commit')->byDefault();
        $this->db->shouldReceive('rollBack')->byDefault();
        $this->db->shouldReceive('inTransaction')->byDefault()->andReturn(false);

        $this->publisher = new OutboxPublisher(
            $this->db,
            $this->queue,
            $this->events,

            $this->logger
        );
    }

    protected function tearDown(): void
    {
        m::close();
        parent::tearDown();
    }

    /** @test */
    public function publisher_can_be_instantiated(): void
    {
        $this->assertInstanceOf(OutboxPublisher::class, $this->publisher);
    }

    /** @test */
    public function publish_pending_processes_reserved_events(): void
    {
        // 1. Mock Zombie Recovery query with flexible quote matching
        $this->db->shouldReceive('execute')
            ->with(m::pattern('/status = .pending./'), m::any())
            ->once()
            ->andReturn(0);

        // 2. Mock Accumulation check query
        $this->db->shouldReceive('fetchColumn')
            ->with(m::pattern('/SELECT COUNT\(\*\) FROM outbox_events/'))
            ->once()
            ->andReturn(10); // 10 pending events (under 50 threshold, so no alert)

        // 3. Mock reserveOne()
        $eventMock = (object)[
            'id' => 101,
            'event_type' => 'user.registered',
            'payload' => json_encode(['user_id' => 12]),
            'attempts' => 0
        ];

        // First reserveOne call returns our event
        $this->db->shouldReceive('selectOne')->once()->andReturn($eventMock);

        // Expect state update of reserved event
        $this->db->shouldReceive('execute')
            ->with(m::pattern('/status = .processing./'), [101])
            ->once();

        // 4. Expect event publishing
        $this->events->shouldReceive('dispatchOrFail')
            ->with('user.registered', ['user_id' => 12])
            ->once();

        // 5. Expect mark as published
        $this->db->shouldReceive('execute')
            ->with(m::pattern('/status = .published./'), [101])
            ->once();

        $result = $this->publisher->publishPending(1);

        $this->assertEquals(1, $result['published']);
        $this->assertEquals(0, $result['failed']);
    }

    /** @test */
    public function publish_pending_moves_failed_events_to_dlq_on_threshold(): void
    {
        $this->db->shouldReceive('execute')->byDefault();
        $this->db->shouldReceive('fetchColumn')->byDefault()->andReturn(0);

        // Reserve an event with 3 attempts (reaches DLQ threshold of 3)
        $eventMock = (object)[
            'id' => 102,
            'event_type' => 'user.registered',
            'payload' => json_encode(['user_id' => 12]),
            'attempts' => 2 // will become 3 in reserveOne
        ];

        $this->db->shouldReceive('selectOne')->once()->andReturn($eventMock);

        // Event publishing throws exception
        $this->events->shouldReceive('dispatchOrFail')
            ->andThrow(new \RuntimeException('Connection timeout'));

        // Expect moving to DLQ (status = 'dlq')
        $this->db->shouldReceive('execute')
            ->with(m::pattern('/status = .dlq./'), m::any())
            ->once();

        $result = $this->publisher->publishPending(1);

        $this->assertEquals(0, $result['published']);
        $this->assertEquals(1, $result['failed']);
        $this->assertEquals(1, $result['dlq']);
    }
}
