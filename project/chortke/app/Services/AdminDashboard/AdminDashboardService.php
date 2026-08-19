<?php
declare(strict_types=1);

namespace App\Services\AdminDashboard;

use App\Contracts\LoggerInterface;
/**
 * AdminDashboardService (Orchestrator)
 * 
 * پس از تجزیه، این کلاس درخواست‌ها را به سرویس‌های تخصصی‌تر هدایت می‌کند
 * تا پایداری و اصل SRP رعایت شود و تغییری در کنترلرها نیاز نباشد.
 */
class AdminDashboardService
{
    private DashboardQueryService $queryService;
    private SystemMonitoringService $monitoringService;

    public function __construct(
        DashboardQueryService $queryService,
        SystemMonitoringService $monitoringService
    ) {
                $this->queryService = $queryService;
        $this->monitoringService = $monitoringService;
    }

    /** @return array<string, mixed> */
    public function getDashboardData(int $userId): array
    {
        return $this->queryService->getDashboardData($userId);
    }

    /** @return list<\stdClass> */
    public function getAdminAccessLog(int $limit = 10): array
    {
        return $this->queryService->getAdminAccessLog($limit);
    }

    /** @return array<string, mixed> */
    public function getRecentActivity(string $type = 'all', int $limit = 20, int $page = 1): array
    {
        return $this->queryService->getRecentActivity($type, $limit, $page);
    }

    /** @return array<string, mixed> */
    public function getSystemStatus(): array
    {
        return $this->monitoringService->getSystemStatus();
    }

}

