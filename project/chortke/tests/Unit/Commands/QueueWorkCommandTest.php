<?php

declare(strict_types=1);

namespace Tests\Unit\Commands;

use PHPUnit\Framework\TestCase;
use App\Commands\QueueWorkCommand;
use App\Services\QueueWorker;
use App\Contracts\LoggerInterface;
use Mockery as m;

class QueueWorkCommandTest extends TestCase
{
    /** @var QueueWorker&\Mockery\MockInterface */
    private QueueWorker $workerMock;
    /** @var LoggerInterface&\Mockery\MockInterface */
    private LoggerInterface $loggerMock;
    private QueueWorkCommand $command;

    protected function setUp(): void
    {
        parent::setUp();
        $this->workerMock = m::mock(QueueWorker::class);
        $this->loggerMock = m::mock(LoggerInterface::class);

        // Expect standard info logs
        $this->loggerMock->shouldReceive('info')->andReturn(null);

        $this->command = new QueueWorkCommand(
            $this->workerMock,
            $this->loggerMock
        );
    }

    protected function tearDown(): void
    {
        m::close();
        parent::tearDown();
    }

    /** @test */
    public function it_can_be_instantiated(): void
    {
        $this->assertInstanceOf(QueueWorkCommand::class, $this->command);
    }

    /** @test */
    public function it_parses_argv_correctly_and_calls_worker_work(): void
    {
        $this->workerMock->shouldReceive('work')
            ->once()
            ->with('notifications', 15)
            ->andReturn([
                'processed_jobs' => 8,
                'failed_jobs' => 1
            ]);

        $argv = ['cli.php', 'queue:work', '--queue=notifications', '--limit=15'];

        ob_start();
        $result = $this->command->run($argv);
        $output = str_value(ob_get_clean());

        $this->assertStringContainsString('[queue:work] processed=8 failed=1 (limit=15)', $output);
        $this->assertEquals([
            'processed_jobs' => 8,
            'failed_jobs' => 1
        ], $result);
    }

    /** @test */
    public function it_enforces_limit_range_limits(): void
    {
        // Limit below minimum 1 becomes 1
        $this->workerMock->shouldReceive('work')
            ->once()
            ->with(null, 1)
            ->andReturn(['processed_jobs' => 0, 'failed_jobs' => 0]);

        $argv = ['cli.php', 'queue:work', '--limit=0'];

        ob_start();
        $res1 = $this->command->run($argv);
        ob_get_clean();

        $this->assertEquals(0, $res1['processed_jobs']);

        // Limit above maximum 500 becomes 500
        $this->workerMock->shouldReceive('work')
            ->once()
            ->with(null, 500)
            ->andReturn(['processed_jobs' => 0, 'failed_jobs' => 0]);

        $argv = ['cli.php', 'queue:work', '--limit=999'];

        ob_start();
        $res2 = $this->command->run($argv);
        ob_get_clean();

        $this->assertEquals(0, $res2['processed_jobs']);
    }

    /** @test */
    public function it_handles_worker_exceptions_and_returns_error(): void
    {
        $this->workerMock->shouldReceive('work')
            ->once()
            ->andThrow(new \RuntimeException('Connection failed'));

        $this->loggerMock->shouldReceive('error')
            ->once()
            ->with('queue.command.work.failed', m::on(function ($arg) {
                return $arg['error'] === 'Connection failed';
            }));

        $argv = ['cli.php', 'queue:work'];

        ob_start();
        $result = $this->command->run($argv);
        $output = str_value(ob_get_clean());

        $this->assertArrayHasKey('error', $result);
        $this->assertEquals('Connection failed', $result['error']);
    }
}
