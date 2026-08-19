<?php

namespace Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use Mockery as m;

class OutboxServiceTest extends TestCase
{
    protected function tearDown(): void
    {
        m::close();
        parent::tearDown();
    }

    /** @test */
    public function record_inserts_into_outbox_and_calls_audit(): void
    {
        $db = m::mock(\Core\Database::class);
        $logger = m::mock(\App\Contracts\LoggerInterface::class);
        $audit = m::mock(\App\Services\AuditTrail::class);

        $stmt = m::mock(\PDOStatement::class);
        $stmt->shouldReceive('execute')->byDefault()->andReturn(true);

        $db->shouldReceive('prepare')->once()->andReturn($stmt);

        $logger->shouldIgnoreMissing();
        $audit->shouldReceive('record')->once();

        $service = new \App\Services\OutboxService($db, $logger, $audit, m::mock('App\Services\OutboxPublisher'));
        $ref = new \ReflectionClass($service);
        $prop = $ref->getProperty('db');
        $prop->setAccessible(true);
        $prop->setValue($service, $db);

        $res = $service->record('order', 123, 'OrderCreated', ['x' => 1]);

        $this->assertTrue($res);
    }

    /** @test */
    public function record_swallow_audit_errors_and_still_returns_true(): void
    {
        $db = m::mock(\Core\Database::class);
        $logger = m::mock(\App\Contracts\LoggerInterface::class);
        $audit = m::mock(\App\Services\AuditTrail::class);

        $stmt = m::mock(\PDOStatement::class);
        $stmt->shouldReceive('execute')->byDefault()->andReturn(true);

        $db->shouldReceive('prepare')->once()->andReturn($stmt);

        $logger->shouldIgnoreMissing();
        $audit->shouldReceive('record')->once()->andThrow(new \Exception('audit fail'));

        $service = new \App\Services\OutboxService($db, $logger, $audit, m::mock('App\Services\OutboxPublisher'));
        $ref = new \ReflectionClass($service);
        $prop = $ref->getProperty('db');
        $prop->setAccessible(true);
        $prop->setValue($service, $db);

        $res = $service->record('order', 123, 'OrderCreated', ['x' => 1]);

        $this->assertTrue($res);
    }

    /** @test */
    public function record_inserts_outbox_event_and_records_audit(): void
    {
        $db = m::mock(\Core\Database::class);
        $logger = m::mock(\App\Contracts\LoggerInterface::class);
        $auditTrail = m::mock(\App\Services\AuditTrail::class);
        $statement = m::mock(\PDOStatement::class);
        $statement->shouldReceive('execute')->byDefault()->andReturn(true);

        $logger->shouldIgnoreMissing();

        $db->shouldReceive('prepare')
            ->once()
            ->with(m::type('string'))
            ->andReturn($statement);

        // PDOStatement::execute will accept the params array; no need to mock it.

        $auditTrail->shouldReceive('record')
            ->once()
            ->with(
                'outbox.event.recorded',
                null,
                m::on(function ($payload) {
                    return isset($payload['aggregate_type'])
                        && isset($payload['aggregate_id'])
                        && isset($payload['event_type']);
                })
            );

        $service = new \App\Services\OutboxService($db, $logger, $auditTrail, m::mock('App\Services\OutboxPublisher'));
        $ref = new \ReflectionClass($service);
        $prop = $ref->getProperty('db');
        $prop->setAccessible(true);
        $prop->setValue($service, $db);

        $result = $service->record('user', 123, 'user.created', ['name' => 'test'], date('Y-m-d H:i:s'));

        $this->assertTrue($result);
    }
}
