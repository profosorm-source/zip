<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Contracts\LoggerInterface;
use App\Services\AdminDashboard\DashboardQueryService;
use App\Services\PerformanceOptimizationService;
use Core\Database;
use PHPUnit\Framework\TestCase;
use Tests\Fixtures\CorruptDashboardCache;

final class AdminDashboardContractBehaviorTest extends TestCase
{
    public function test_corrupt_dashboard_cache_fails_fast_before_database_query(): void
    {
        $cache = new CorruptDashboardCache();
        $database = $this->createMock(Database::class);
        $database->expects($this->never())->method('fetch');
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('error')->with(
            'dashboard.query.contract_failed',
            $this->isType('array')
        );
        $service = new DashboardQueryService(
            $cache,
            $database,
            $logger,
            $this->createMock(PerformanceOptimizationService::class)
        );

        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('Dashboard cache');
        $service->getDashboardData(1);
    }
}
