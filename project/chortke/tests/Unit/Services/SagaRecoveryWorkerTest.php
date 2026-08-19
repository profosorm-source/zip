<?php

namespace Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use App\Services\SagaRecoveryWorker;
use Mockery as m;

class SagaRecoveryWorkerTest extends TestCase
{
    /** @var \Core\Database&\Mockery\MockInterface */
    private \Core\Database $db;
    /** @var \App\Contracts\LoggerInterface&\Mockery\MockInterface */
    private \App\Contracts\LoggerInterface $logger;
    /** @var \Core\Container&\Mockery\MockInterface */
    private \Core\Container $container;
    private SagaRecoveryWorker $worker;

    protected function setUp(): void
    {
        parent::setUp();
        $this->db = m::mock('Core\Database');
        $this->logger = m::mock('App\Contracts\LoggerInterface');
        $this->container = m::mock('Core\Container');

        $this->logger->shouldIgnoreMissing();

        $this->worker = new SagaRecoveryWorker($this->db, $this->logger, $this->container);
    }

    protected function tearDown(): void
    {
        m::close();
        parent::tearDown();
    }

    /** @test */
    public function worker_can_be_instantiated(): void
    {
        $this->assertInstanceOf(SagaRecoveryWorker::class, $this->worker);
    }

    /** @test */
    public function run_returns_zero_when_no_stalled_sagas(): void
    {
        $stmt = m::mock(\PDOStatement::class);
        $stmt->shouldReceive('execute')->once();
        $stmt->shouldReceive('fetchAll')->once()->andReturn([]);

        $this->db->shouldReceive('prepare')
            ->with(m::pattern('/SELECT \* FROM saga_executions/'))
            ->once()
            ->andReturn($stmt);

        $result = $this->worker->run();

        $this->assertEquals(0, $result);
    }

    /** @test */
    public function run_compensates_stalled_saga_with_no_steps_immediately(): void
    {
        $sagaMock = (object)[
            'id' => 123,
            'status' => 'started',
            'saga_name' => 'wallet_transfer',
            'payload' => json_encode(['amount' => 100]),
            'executed_steps' => json_encode([]) // empty executed steps
        ];

        // Candidate fetch
        $stmtFetch = m::mock(\PDOStatement::class);
        $stmtFetch->shouldReceive('execute')->once();
        $stmtFetch->shouldReceive('fetchAll')->once()->andReturn([$sagaMock]);

        $this->db->shouldReceive('prepare')
            ->with(m::pattern('/SELECT \* FROM saga_executions/'))
            ->once()
            ->andReturn($stmtFetch);

        // Fencing-token read (updated_at) after the atomic claim
        $stmtFence = m::mock(\PDOStatement::class);
        $stmtFence->shouldReceive('execute')->once();
        $stmtFence->shouldReceive('fetchColumn')->once()->andReturn('2026-01-01 00:00:00');

        $this->db->shouldReceive('prepare')
            ->with(m::pattern('/SELECT updated_at FROM saga_executions/'))
            ->once()
            ->andReturn($stmtFence);

        // Atomic claim (started -> recovering) and the fenced finalize both go through execute().
        $this->db->shouldReceive('execute')
            ->with(m::pattern("/SET status = 'recovering'/"), [123])
            ->once()
            ->andReturn(1);

        $this->db->shouldReceive('execute')
            ->with(m::pattern('/SET status = \?/'), ['compensated', '123', '2026-01-01 00:00:00'])
            ->once()
            ->andReturn(1);

        $result = $this->worker->run();

        $this->assertEquals(1, $result);
    }
}
