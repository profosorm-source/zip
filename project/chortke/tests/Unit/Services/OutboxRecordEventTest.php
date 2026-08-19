<?php

namespace Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use Mockery as m;
use App\Services\OutboxService;
use App\Contracts\DomainEvent;

class OutboxRecordEventTest extends TestCase
{
    protected function tearDown(): void
    {
        m::close();
        parent::tearDown();
    }

    /** @test */
    public function recordEvent_uses_domain_event_interface_when_available(): void
    {
        $db = m::mock(\Core\Database::class);
        $logger = m::mock(\App\Contracts\LoggerInterface::class);
        $audit = m::mock(\App\Services\AuditTrail::class);

        $logger->shouldIgnoreMissing();
        $audit->shouldIgnoreMissing();

        $stmt = m::mock(\PDOStatement::class);
        $stmt->shouldReceive('execute')->byDefault()->andReturn(true);
        $db->shouldReceive('prepare')->once()->andReturn($stmt);

        $event = new class implements DomainEvent {
            public function aggregateType(): string { return 'wallet'; }
            public function aggregateId(): string { return '42'; }
            public function toPayload(): array { return ['user_id' => 42, 'amount' => '100']; }
        };

        $service = new OutboxService($db, $logger, $audit, m::mock('App\Services\OutboxPublisher'));
        $result = $service->recordEvent($event);

        $this->assertTrue($result);
    }

    /** @test */
    public function recordEvent_falls_back_to_infer_for_non_domain_events(): void
    {
        $db = m::mock(\Core\Database::class);
        $logger = m::mock(\App\Contracts\LoggerInterface::class);
        $audit = m::mock(\App\Services\AuditTrail::class);

        $logger->shouldIgnoreMissing();
        $audit->shouldIgnoreMissing();

        $stmt = m::mock(\PDOStatement::class);
        $stmt->shouldReceive('execute')->byDefault()->andReturn(true);
        $db->shouldReceive('prepare')->once()->andReturn($stmt);

        // Plain object without DomainEvent interface
        $event = new class {
            public int $user_id = 99;
            public string $action = 'test';
        };

        $service = new OutboxService($db, $logger, $audit, m::mock('App\Services\OutboxPublisher'));
        $result = $service->recordEvent($event);

        $this->assertTrue($result);
    }

    /** @test */
    public function recordEvent_with_generic_event_implements_domain_event(): void
    {
        $db = m::mock(\Core\Database::class);
        $logger = m::mock(\App\Contracts\LoggerInterface::class);
        $audit = m::mock(\App\Services\AuditTrail::class);

        $logger->shouldIgnoreMissing();
        $audit->shouldIgnoreMissing();

        $stmt = m::mock(\PDOStatement::class);
        $stmt->shouldReceive('execute')->byDefault()->andReturn(true);
        $db->shouldReceive('prepare')->once()->andReturn($stmt);

        $event = new \Core\GenericEvent([
            'user_id' => 77,
            'event_name' => 'lottery.participated',
        ]);

        $this->assertInstanceOf(DomainEvent::class, $event);

        $service = new OutboxService($db, $logger, $audit, m::mock('App\Services\OutboxPublisher'));
        $result = $service->recordEvent($event);

        $this->assertTrue($result);
        $this->assertEquals('lottery.participated', $event->aggregateType());
        $this->assertEquals('77', $event->aggregateId());
    }

    /** @test */
    public function recordEvent_with_investment_created_event(): void
    {
        $db = m::mock(\Core\Database::class);
        $logger = m::mock(\App\Contracts\LoggerInterface::class);
        $audit = m::mock(\App\Services\AuditTrail::class);

        $logger->shouldIgnoreMissing();
        $audit->shouldIgnoreMissing();

        $stmt = m::mock(\PDOStatement::class);
        $stmt->shouldReceive('execute')->byDefault()->andReturn(true);
        $db->shouldReceive('prepare')->once()->andReturn($stmt);

        $event = new \App\Events\InvestmentCreatedEvent(10, 500, '250.0', 'usdt');

        $this->assertInstanceOf(DomainEvent::class, $event);
        $this->assertEquals('investment', $event->aggregateType());
        $this->assertEquals('500', $event->aggregateId());

        $service = new OutboxService($db, $logger, $audit, m::mock('App\Services\OutboxPublisher'));
        $result = $service->recordEvent($event);

        $this->assertTrue($result);
    }

    /** @test */
    public function recordEvent_with_score_delta_appended_event(): void
    {
        $db = m::mock(\Core\Database::class);
        $logger = m::mock(\App\Contracts\LoggerInterface::class);
        $audit = m::mock(\App\Services\AuditTrail::class);

        $logger->shouldIgnoreMissing();
        $audit->shouldIgnoreMissing();

        $stmt = m::mock(\PDOStatement::class);
        $stmt->shouldReceive('execute')->byDefault()->andReturn(true);
        $db->shouldReceive('prepare')->once()->andReturn($stmt);

        $event = new \App\Events\ScoreDeltaAppendedEvent('user', 42, 'xp', 10.5, 'task_complete');

        $this->assertInstanceOf(DomainEvent::class, $event);
        $this->assertEquals('user', $event->aggregateType());
        $this->assertEquals('42', $event->aggregateId());

        $payload = $event->toPayload();
        $this->assertEquals('xp', $payload['domain']);
        $this->assertEquals(10.5, $payload['delta']);

        $service = new OutboxService($db, $logger, $audit, m::mock('App\Services\OutboxPublisher'));
        $result = $service->recordEvent($event);

        $this->assertTrue($result);
    }
}
