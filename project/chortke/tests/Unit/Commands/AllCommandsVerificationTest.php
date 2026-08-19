<?php

namespace Tests\Unit\Commands;

use PHPUnit\Framework\TestCase;
use App\Commands\FeatureFlagCommand;
use App\Commands\IdempotencyCommand;
use App\Commands\DLQRetryCommand;
use App\Commands\AnalyticsCacheWarmupCommand;
use App\Commands\AlertRulesBootstrapCommand;
use App\Commands\BackfillSearchProjectionCommand;
use App\Commands\DlqWorkCommand;
use App\Commands\EscrowCleanupCommand;
use App\Commands\MigrationManager;
use App\Commands\ProcessScheduledTasksCommand;
use App\Commands\QueueFailedCommand;
use App\Commands\QueueWorkCommand;
use App\Commands\RateLimitAuditCommand;
use App\Commands\RouteAuditCommand;
use App\Commands\StuckWithdrawalReviewCommand;
use App\Commands\SystemCleanupCommand;
use App\Commands\UpdateTorExitNodesCommand;
use App\Commands\DistributedHealthCommand;
use Mockery as m;

class AllCommandsVerificationTest extends TestCase
{
    protected function tearDown(): void
    {
        m::close();
        parent::tearDown();
    }

    /** @test */
    public function feature_flag_command_is_instantiable(): void
    {
        $logger = m::mock('App\Contracts\LoggerInterface');
        $service = m::mock('App\Services\FeatureFlagService');

        $logger->shouldIgnoreMissing();

        $cmd = new FeatureFlagCommand($logger, $service);
        $this->assertInstanceOf(FeatureFlagCommand::class, $cmd);
    }

    /** @test */
    public function idempotency_command_is_instantiable(): void
    {
        $idempotency = m::mock('App\Services\Shared\IdempotencyService');
        $logger = m::mock('App\Contracts\LoggerInterface');

        $logger->shouldIgnoreMissing();

        $cmd = new IdempotencyCommand($idempotency, $logger);
        $this->assertInstanceOf(IdempotencyCommand::class, $cmd);
    }

    /** @test */
    public function dlq_retry_command_is_instantiable(): void
    {
        $db = m::mock('Core\Database');
        $logger = m::mock('App\Contracts\LoggerInterface');

        $logger->shouldIgnoreMissing();

        $cmd = new DLQRetryCommand($db, $logger);
        $this->assertInstanceOf(DLQRetryCommand::class, $cmd);
    }

    /** @test */
    public function analytics_cache_warmup_command_is_instantiable(): void
    {
        $logger = m::mock('App\Contracts\LoggerInterface');
        $service = m::mock('App\Services\Analytics\AnalyticsService');

        $logger->shouldIgnoreMissing();

        $cmd = new AnalyticsCacheWarmupCommand($service, $logger);
        $this->assertInstanceOf(AnalyticsCacheWarmupCommand::class, $cmd);
    }

    /** @test */
    public function alert_rules_bootstrap_command_is_instantiable(): void
    {
        $db = m::mock('Core\Database');
        $logger = m::mock('Core\Logger');

        $logger->shouldIgnoreMissing();

        $cmd = new AlertRulesBootstrapCommand($db, $logger);
        $this->assertInstanceOf(AlertRulesBootstrapCommand::class, $cmd);
    }

    /** @test */
    public function backfill_search_projection_command_is_instantiable(): void
    {
        $job = m::mock('App\Jobs\BackfillSearchProjectionJob');
        $logger = m::mock('App\Contracts\LoggerInterface');

        $logger->shouldIgnoreMissing();

        $cmd = new BackfillSearchProjectionCommand($job, $logger);
        $this->assertInstanceOf(BackfillSearchProjectionCommand::class, $cmd);
    }

    /** @test */
    public function dlq_work_command_is_instantiable(): void
    {
        $worker = m::mock('App\Services\DlqWorker');
        $logger = m::mock('App\Contracts\LoggerInterface');

        $logger->shouldIgnoreMissing();

        $cmd = new DlqWorkCommand($worker, $logger);
        $this->assertInstanceOf(DlqWorkCommand::class, $cmd);
    }

    /** @test */
    public function escrow_cleanup_command_is_instantiable(): void
    {
        $db = m::mock('Core\Database');
        $logger = m::mock('App\Contracts\LoggerInterface');
        $escrow = m::mock('App\Domain\Financial\Services\FinancialEscrowService');

        $logger->shouldIgnoreMissing();

        $cmd = new EscrowCleanupCommand($escrow, $db, $logger);
        $this->assertInstanceOf(EscrowCleanupCommand::class, $cmd);
    }

    /** @test */
    public function migration_manager_command_is_instantiable(): void
    {
        $db = m::mock('Core\Database');
        $cmd = new MigrationManager($db);
        $this->assertInstanceOf(MigrationManager::class, $cmd);
    }

    /** @test */
    public function process_scheduled_tasks_command_is_instantiable(): void
    {
        $deletion = m::mock('App\Services\User\AccountDeletionService');
        $export = m::mock('App\Services\DataExportService');
        $escrow = m::mock('App\Domain\Financial\Services\FinancialEscrowService');
        $db = m::mock('Core\Database');
        $logger = m::mock('App\Contracts\LoggerInterface');

        $logger->shouldIgnoreMissing();

        $cmd = new ProcessScheduledTasksCommand($deletion, $export, $escrow, $db, $logger);
        $this->assertInstanceOf(ProcessScheduledTasksCommand::class, $cmd);
    }

    /** @test */
    public function queue_failed_command_is_instantiable(): void
    {
        $db = m::mock('Core\Database');
        $queue = m::mock('Core\Queue');
        $logger = m::mock('App\Contracts\LoggerInterface');

        $logger->shouldIgnoreMissing();

        $cmd = new QueueFailedCommand($db, $queue, $logger);
        $this->assertInstanceOf(QueueFailedCommand::class, $cmd);
    }

    /** @test */
    public function queue_work_command_is_instantiable(): void
    {
        $worker = m::mock('App\Services\QueueWorker');
        $logger = m::mock('App\Contracts\LoggerInterface');

        $logger->shouldIgnoreMissing();

        $cmd = new QueueWorkCommand($worker, $logger);
        $this->assertInstanceOf(QueueWorkCommand::class, $cmd);
    }

    /** @test */
    public function rate_limit_audit_command_is_instantiable(): void
    {
        $logger = m::mock('App\Contracts\LoggerInterface');

        $logger->shouldIgnoreMissing();

        $cmd = new RateLimitAuditCommand($logger);
        $this->assertInstanceOf(RateLimitAuditCommand::class, $cmd);
    }

    /** @test */
    public function route_audit_command_is_instantiable(): void
    {
        $logger = m::mock('App\Contracts\LoggerInterface');

        $logger->shouldIgnoreMissing();

        $cmd = new RouteAuditCommand();
        $this->assertInstanceOf(RouteAuditCommand::class, $cmd);
    }

    /** @test */
    public function stuck_withdrawal_review_command_is_instantiable(): void
    {
        $recon = m::mock('App\Services\ReconciliationService');
        $admin = m::mock('App\Services\Withdrawal\WithdrawalAdminService');
        $logger = m::mock('App\Contracts\LoggerInterface');

        $logger->shouldIgnoreMissing();

        $cmd = new StuckWithdrawalReviewCommand($recon, $admin, $logger);
        $this->assertInstanceOf(StuckWithdrawalReviewCommand::class, $cmd);
    }

    /** @test */
    public function system_cleanup_command_is_instantiable(): void
    {
        $cmd = new SystemCleanupCommand();
        $this->assertInstanceOf(SystemCleanupCommand::class, $cmd);
    }

    /** @test */
    public function update_tor_exit_nodes_command_is_instantiable(): void
    {
        $service = m::mock('App\Services\AntiFraud\TorListUpdater');
        $logger = m::mock('App\Contracts\LoggerInterface');

        $logger->shouldIgnoreMissing();

        $cmd = new UpdateTorExitNodesCommand($service);
        $this->assertInstanceOf(UpdateTorExitNodesCommand::class, $cmd);
    }

    /** @test */
    public function distributed_health_command_is_instantiable_with_its_container_dependency(): void
    {
        $container = m::mock('Core\Container');

        $cmd = new DistributedHealthCommand($container);

        $this->assertInstanceOf(DistributedHealthCommand::class, $cmd);
    }
}
