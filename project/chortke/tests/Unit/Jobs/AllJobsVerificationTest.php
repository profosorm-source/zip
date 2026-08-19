<?php

namespace Tests\Unit\Jobs;

use PHPUnit\Framework\TestCase;
use App\Jobs\AggregateAnalyticsJob;
use App\Jobs\Investment\ApplyWeeklyProfitLossJob;
use App\Jobs\BackfillSearchProjectionJob;
use App\Jobs\CacheWarmupJob;
use App\Jobs\EscrowTimeoutJob;
use App\Jobs\InvestmentProfitDistributionJob;
use App\Jobs\LogPerformanceJob;
use App\Jobs\NotificationCleanupJob;
use App\Jobs\PersistBulkInAppNotificationJob;
use Mockery as m;

class AllJobsVerificationTest extends TestCase
{
    protected function tearDown(): void
    {
        m::close();
        parent::tearDown();
    }

    /** @test */
    public function aggregate_analytics_job_is_instantiable(): void
    {
        $db = m::mock('Core\Database');
        $logger = m::mock('App\Contracts\LoggerInterface');

        $logger->shouldIgnoreMissing();

        $job = new AggregateAnalyticsJob($db, $logger);
        $this->assertInstanceOf(AggregateAnalyticsJob::class, $job);
    }

    /** @test */
    public function apply_weekly_profit_loss_job_is_instantiable(): void
    {
        $tradingModel = m::mock('App\Models\TradingRecord');
        $investmentModel = m::mock('App\Models\Investment');
        $appSettings = m::mock('App\Services\Settings\AppSettings');
        $logger = m::mock('App\Contracts\LoggerInterface');
        $queue = m::mock('Core\Queue');

        $logger->shouldIgnoreMissing();

        $job = new ApplyWeeklyProfitLossJob($tradingModel, $investmentModel, $appSettings, $logger, $queue);
        $this->assertInstanceOf(ApplyWeeklyProfitLossJob::class, $job);
    }

    /** @test */
    public function backfill_search_projection_job_is_instantiable(): void
    {
        $db = m::mock('Core\Database');
        $logger = m::mock('App\Contracts\LoggerInterface');
        $indexer = m::mock('App\Services\Search\SearchIndexer');
        $schema = m::mock('App\Services\Search\SchemaInspector');

        $logger->shouldIgnoreMissing();

        $job = new BackfillSearchProjectionJob($db, $indexer, $schema, $logger);
        $this->assertInstanceOf(BackfillSearchProjectionJob::class, $job);
    }

    /** @test */
    public function cache_warmup_job_is_instantiable(): void
    {
        $db = m::mock('Core\Database');
        $cache = m::mock('Core\Cache');
        $logger = m::mock('App\Contracts\LoggerInterface');

        $logger->shouldIgnoreMissing();

        $job = new CacheWarmupJob($db, $cache, $logger);
        $this->assertInstanceOf(CacheWarmupJob::class, $job);
    }

    /** @test */
    public function escrow_timeout_job_is_instantiable(): void
    {
        $escrow = m::mock('App\Domain\Financial\Services\FinancialEscrowService');
        $logger = m::mock('App\Contracts\LoggerInterface');

        $logger->shouldIgnoreMissing();

        $eventDispatcher = m::mock('Core\\EventDispatcher');
        $eventDispatcher->shouldIgnoreMissing();

        $job = new EscrowTimeoutJob($escrow, $logger, $eventDispatcher);
        $this->assertInstanceOf(EscrowTimeoutJob::class, $job);
    }

    /** @test */
    public function investment_profit_distribution_job_is_instantiable(): void
    {
        $service = m::mock('App\Services\InvestmentService');
        $db = m::mock('Core\Database');
        $appSettings = m::mock('App\Services\Settings\AppSettings');
        $logger = m::mock('App\Contracts\LoggerInterface');

        $logger->shouldIgnoreMissing();

        $job = new InvestmentProfitDistributionJob($service, $db, $appSettings, $logger);
        $this->assertInstanceOf(InvestmentProfitDistributionJob::class, $job);
    }

    /** @test */
    public function log_performance_job_is_instantiable(): void
    {
        $db = m::mock('Core\Database');
        $job = new LogPerformanceJob($db);
        $this->assertInstanceOf(LogPerformanceJob::class, $job);
    }

    /** @test */
    public function notification_cleanup_job_is_instantiable(): void
    {
        $db = m::mock('Core\Database');
        $logger = m::mock('App\Contracts\LoggerInterface');

        $logger->shouldIgnoreMissing();

        $job = new NotificationCleanupJob($db, $logger);
        $this->assertInstanceOf(NotificationCleanupJob::class, $job);
    }

    /** @test */
    public function persist_bulk_in_app_notification_job_is_instantiable(): void
    {
        $service = m::mock('App\Services\Notification\NotificationService');
        $job = new PersistBulkInAppNotificationJob($service);
        $this->assertInstanceOf(PersistBulkInAppNotificationJob::class, $job);
    }
}
