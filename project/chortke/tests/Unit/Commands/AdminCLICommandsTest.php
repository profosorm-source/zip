<?php

namespace Tests\Unit\Commands;

use PHPUnit\Framework\TestCase;
use App\Commands\OutboxPublishCommand;
use App\Commands\BackfillSearchProjectionCommand;
use App\Commands\DLQRetryCommand;
use App\Commands\StuckWithdrawalReviewCommand;
use App\Commands\DatabaseAnalyzerCommand;
use Mockery as m;

class AdminCLICommandsTest extends TestCase
{
    protected function tearDown(): void
    {
        m::close();
        parent::tearDown();
    }

    /** @test */
    public function outbox_publish_command_works_correctly(): void
    {
        $publisher = m::mock('App\Services\OutboxPublisher');
        $logger = m::mock('App\Contracts\LoggerInterface');

        $publisher->shouldReceive('publishPending')
            ->with(50)
            ->once()
            ->andReturn(['published' => 12, 'failed' => 0]);

        $logger->shouldIgnoreMissing();

        $cmd = new OutboxPublishCommand($publisher, $logger);

        ob_start();
        $cmd->run(['--limit=50']);
        $output = str_value(ob_get_clean());

        $this->assertStringContainsString('published=12 failed=0', $output);
    }

    /** @test */
    public function backfill_search_projection_command_handles_parameters(): void
    {
        $job = m::mock('App\Jobs\BackfillSearchProjectionJob');
        $logger = m::mock('App\Contracts\LoggerInterface');

        $job->shouldReceive('handle')
            ->with(['source' => 'transactions', 'batch_size' => 100])
            ->once();

        $logger->shouldIgnoreMissing();

        $cmd = new BackfillSearchProjectionCommand($job, $logger);

        ob_start();
        $cmd->run(['--source=transactions', '--batch=100']);
        $output = str_value(ob_get_clean());

        $this->assertStringContainsString('starting (source=transactions, batch=100)', $output);
    }

    /** @test */
    public function dlq_retry_command_is_instantiable_and_runs_purge(): void
    {
        $db = m::mock('Core\Database');
        $logger = m::mock('App\Contracts\LoggerInterface');

        $db->shouldReceive('execute')
            ->once()
            ->andReturn(5); // 5 deleted

        $logger->shouldIgnoreMissing();

        $cmd = new DLQRetryCommand($db, $logger);

        ob_start();
        $cmd->execute(['purge', '15']); // purge older than 15 days
        $output = str_value(ob_get_clean());

        $this->assertStringContainsString('Purged 5 failed jobs permanently.', $output);
    }

    /** @test */
    public function stuck_withdrawal_review_command_scans_correctly(): void
    {
        $reconciliation = m::mock('App\Services\ReconciliationService');
        $withdrawalAdmin = m::mock('App\Services\Withdrawal\WithdrawalAdminService');
        $logger = m::mock('App\Contracts\LoggerInterface');

        $reconciliation->shouldReceive('flagStuckWithdrawals')
            ->with(120, 200)
            ->once()
            ->andReturn([
                'scanned' => 50,
                'flagged' => 5,
                'notified' => 2,
                'skipped' => 0
            ]);

        $logger->shouldIgnoreMissing();

        $cmd = new StuckWithdrawalReviewCommand($reconciliation, $withdrawalAdmin, $logger);

        ob_start();
        $cmd->run(['cli.php', 'withdrawals:review:scan', '--minutes=120', '--limit=200']);
        $output = str_value(ob_get_clean());

        $this->assertStringContainsString('scanned=50 flagged=5 notified=2 skipped=0', $output);
    }

    /** @test */
    public function database_analyzer_command_displays_slow_queries(): void
    {
        $analyzer = m::mock('App\Services\DatabaseAnalyzerService');

        $analyzer->shouldReceive('getSlowQueries')
            ->once()
            ->andReturn([[
                'sql_text' => 'SELECT * FROM users',
                'query_count' => 4,
                'avg_time_ms' => 1250.5,
            ]]);

        $cmd = new DatabaseAnalyzerCommand($analyzer);

        ob_start();
        $cmd->run(['cli.php', 'db:analyze', 'slow-queries']);
        $output = str_value(ob_get_clean());

        $this->assertStringContainsString('Recent Slow Queries', $output);
        $this->assertStringContainsString('SELECT * FROM users', $output);
    }

    /** @test */
    public function database_analyzer_command_displays_deadlocks(): void
    {
        $analyzer = m::mock('App\Services\DatabaseAnalyzerService');

        $analyzer->shouldReceive('getDeadlockInfo')
            ->once()
            ->andReturn([[
                'detected_at' => '2026-07-26 22:00:00',
                'summary' => 'Transaction rollback caused by lock conflict',
            ]]);

        $cmd = new DatabaseAnalyzerCommand($analyzer);

        ob_start();
        $cmd->run(['cli.php', 'db:analyze', 'deadlocks']);
        $output = str_value(ob_get_clean());

        $this->assertStringContainsString('Recent InnoDB Deadlocks', $output);
        $this->assertStringContainsString('Transaction rollback caused by lock conflict', $output);
    }
}
