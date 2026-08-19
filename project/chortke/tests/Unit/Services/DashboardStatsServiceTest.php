<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use Mockery as m;
use Core\Cache;
use Core\Database;
use App\Contracts\LoggerInterface;
use App\Contracts\NotificationServiceInterface;
use App\Models\AdvancedAnalytics;
use App\Services\Analytics\AnalyticsService;
use App\Services\Shared\DashboardStatsService;

class DashboardStatsServiceTest extends TestCase
{
    protected function tearDown(): void
    {
        m::close();
        parent::tearDown();
    }

    /**
     * ساخت نمونهی سرویس با وابستگیهای mock.
     */
    private function makeService(
        ?Database $db = null,
        ?Cache $cache = null,
        ?LoggerInterface $logger = null,
        ?NotificationServiceInterface $notificationService = null,
        ?AdvancedAnalytics $advancedAnalytics = null,
        ?AnalyticsService $customTaskAnalytics = null
    ): DashboardStatsService {
        return new DashboardStatsService(
            $cache ?? m::mock(Cache::class),
            $db ?? m::mock(Database::class),
            $logger ?? m::mock(LoggerInterface::class),
            $notificationService ?? m::mock(NotificationServiceInterface::class),
            $advancedAnalytics ?? m::mock(AdvancedAnalytics::class),
            $customTaskAnalytics ?? m::mock(AnalyticsService::class)
        );
    }

    /** @test */
    public function can_be_instantiated(): void
    {
        $service = $this->makeService();
        $this->assertInstanceOf(DashboardStatsService::class, $service);
    }

    /** @test */
    public function get_count_uses_prepared_statement_and_returns_column(): void
    {
        $stmt = m::mock(\PDOStatement::class);
        $stmt->shouldReceive('execute')->once()->withArgs(function ($params) {
            return is_array($params);
        });
        $stmt->shouldReceive('fetchColumn')->once()->andReturn('42');

        $db = m::mock(Database::class);
        $db->shouldReceive('prepare')->once()->andReturn($stmt);

        $service = $this->makeService($db);
        $this->assertSame(42, $service->getCount('users'));
    }

    /** @test */
    public function get_count_returns_zero_when_column_is_empty(): void
    {
        $stmt = m::mock(\PDOStatement::class);
        $stmt->shouldReceive('execute')->once();
        $stmt->shouldReceive('fetchColumn')->once()->andReturn(false);

        $db = m::mock(Database::class);
        $db->shouldReceive('prepare')->once()->andReturn($stmt);

        $service = $this->makeService($db);
        $this->assertSame(0, $service->getCount('users'));
    }

    /** @test */
    public function get_recent_requests_count_returns_zero_on_db_error(): void
    {
        $db = m::mock(Database::class);
        $db->shouldReceive('query')->once()->andThrow(new \RuntimeException('db down'));

        $service = $this->makeService($db);
        $this->assertSame(0, $service->getRecentRequestsCount(5));
    }

    /** @test */
    public function is_database_up_returns_true_when_query_succeeds(): void
    {
        $stmt = m::mock(\PDOStatement::class);

        $db = m::mock(Database::class);
        $db->shouldReceive('query')->once()->andReturn($stmt);

        $service = $this->makeService($db);
        $this->assertTrue($service->isDatabaseUp());
    }

    /** @test */
    public function is_database_up_returns_false_when_query_fails(): void
    {
        $db = m::mock(Database::class);
        $db->shouldReceive('query')->once()->andThrow(new \RuntimeException('down'));

        $service = $this->makeService($db);
        $this->assertFalse($service->isDatabaseUp());
    }

    /** @test */
    public function track_notification_logs_without_throwing(): void
    {
        $captured = null;
        $logger = m::mock(LoggerInterface::class);
        $logger->shouldReceive('info')
            ->once()
            ->with('notification.analytics.track', m::on(function ($ctx) use (&$captured) {
                $captured = $ctx;
                return is_array($ctx);
            }));

        $service = $this->makeService(null, null, $logger);
        $service->trackNotification(['foo' => 'bar']);

        $this->assertSame(['foo' => 'bar'], $captured);
    }
}
