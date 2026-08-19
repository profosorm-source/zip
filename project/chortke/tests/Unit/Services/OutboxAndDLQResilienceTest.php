<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use App\Services\OutboxService;
use App\Services\OutboxPublisher;
use App\Services\AuditTrail;
use Core\Database;
use App\Contracts\LoggerInterface;
use Mockery as m;

/**
 * OutboxAndDLQResilienceTest — تست‌های رفتاری انعطاف‌پذیری رویدادهای Outbox و بازیابی صف مرده (DLQ)
 */
class OutboxAndDLQResilienceTest extends TestCase
{
    /** @var Database&\Mockery\MockInterface */
    private Database $db;
    /** @var LoggerInterface&\Mockery\MockInterface */
    private LoggerInterface $logger;
    /** @var AuditTrail&\Mockery\MockInterface */
    private AuditTrail $auditTrail;
    /** @var OutboxPublisher&\Mockery\MockInterface */
    private OutboxPublisher $outboxPublisher;
    private OutboxService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->db = m::mock(Database::class);
        $this->logger = m::mock(LoggerInterface::class);
        $this->logger->shouldIgnoreMissing();
        $this->auditTrail = m::mock(AuditTrail::class);
        $this->auditTrail->shouldIgnoreMissing();
        $this->outboxPublisher = m::mock(OutboxPublisher::class);

        $this->service = new OutboxService(
            $this->db,
            $this->logger,
            $this->auditTrail,
            $this->outboxPublisher
        );
    }

    protected function tearDown(): void
    {
        m::close();
        parent::tearDown();
    }

    /** @test */
    public function record_outbox_event_validates_and_serializes_payload(): void
    {
        $stmt = m::mock(\PDOStatement::class);
        $stmt->shouldReceive('execute')->once()->andReturn(true);

        $this->db->shouldReceive('prepare')->once()->andReturn($stmt);

        $ok = $this->service->record('financial', 101, 'wallet.credited', [
            'user_id' => 101,
            'amount'  => '500.00',
            'currency' => 'usdt'
        ]);

        $this->assertTrue($ok);
    }

    /** @test */
    public function publish_outbox_events_delegates_to_publisher(): void
    {
        $this->outboxPublisher->shouldReceive('publishPending')->once()->with(10)->andReturn([
            'published' => 1,
            'failed'    => 0,
            'processed' => 1,
        ]);

        $res = $this->service->publishPending(10);

        $this->assertSame(1, $res['published']);
    }
}
