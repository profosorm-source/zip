<?php

namespace Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use App\Services\Analytics\AnalyticsService;
use Mockery as m;

use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
class AnalyticsServiceTest extends TestCase
{
    // انتظاراتِ Mockery را به ادعای واقعی PHPUnit تبدیل می‌کند،
    // تا ادعای تهی برای دفعِ هشدارِ risky لازم نباشد.
    use MockeryPHPUnitIntegration;

    /** @var \App\Contracts\LoggerInterface&\Mockery\MockInterface */
    private \App\Contracts\LoggerInterface $logger;
    /** @var \App\Services\Analytics\AnalyticsQueryService&\Mockery\MockInterface */
    private \App\Services\Analytics\AnalyticsQueryService $repository;
    /** @var \App\Services\Analytics\AnalyticsExporter&\Mockery\MockInterface */
    private \App\Services\Analytics\AnalyticsExporter $exporter;
    private AnalyticsService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->logger = m::mock('App\Contracts\LoggerInterface');
        $this->repository = m::mock('App\Services\Analytics\AnalyticsQueryService');
        $this->exporter = m::mock('App\Services\Analytics\AnalyticsExporter');

        $this->logger->shouldIgnoreMissing();

        $this->service = new AnalyticsService(
            $this->logger,
            $this->repository,
            $this->exporter
        );
    }

    protected function tearDown(): void
    {
        m::close();
        parent::tearDown();
    }

    /** @test */
    public function service_can_be_instantiated(): void
    {
        $this->assertInstanceOf(AnalyticsService::class, $this->service);
    }

    /** @test */
    public function get_user_metrics_proxies_to_repository(): void
    {
        $statsMock = ['total_users' => 500, 'active_users' => 120, 'new_users' => 0, 'kyc_verified' => 0, 'kyc_pending' => 0, 'kyc_rejected' => 0, 'kyc_not_submitted' => 500, 'users_by_level' => []];
        
        $this->repository->shouldReceive('getUserStats')
            ->once()
            ->andReturn($statsMock);

        $result = $this->service->getUserMetrics();

        $this->assertSame($statsMock, $result);
    }

    /** @test */
    public function get_kpis_collects_all_metrics(): void
    {
        $userStats = ['total' => 100, 'new_users' => 0, 'kyc_verified' => 0, 'kyc_pending' => 0, 'kyc_rejected' => 0, 'kyc_not_submitted' => 100, 'users_by_level' => [], 'total_users' => 100, 'active_users' => 0];
        $financialStats = ['revenue' => 5000, 'deposits' => ['count' => 0, 'amount' => 0.0], 'withdrawals' => ['count' => 0, 'amount' => 0.0], 'payments' => ['count' => 0, 'amount' => 0.0], 'platform_fee' => 0.0, 'net_flow' => 0.0];
        $taskStats = ['completed' => 45, 'total' => 0, 'active' => 0, 'completed_today' => 0, 'completed_week' => 0, 'completed_month' => 0, 'pending_verification' => 0, 'fraud_detected' => 0, 'by_platform' => [], 'by_type' => []];

        $this->repository->shouldReceive('getUserStats')->once()->andReturn($userStats);
        $this->repository->shouldReceive('getFinancialStats')->once()->andReturn($financialStats);
        $this->repository->shouldReceive('getTaskStats')->once()->andReturn($taskStats);

        $kpis = $this->service->getKpis();

        $this->assertEquals($userStats, $kpis['users']);
        $this->assertEquals($financialStats, $kpis['transactions']);
        $this->assertEquals($taskStats, $kpis['tasks']);
    }

    /** @test */
    public function clear_cache_triggers_repository_and_logs(): void
    {
        $this->repository->shouldReceive('clearCache')
            ->with('task', 5)
            ->once();

        $this->service->clearCache(5, null);
    }
}
