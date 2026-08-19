<?php

namespace Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use App\Services\MaintenanceService;
use App\Services\BackupService;
use App\Services\SitemapService;
use Mockery as m;

class MaintenanceAndAuxiliaryTest extends TestCase
{
    protected function tearDown(): void
    {
        m::close();
        parent::tearDown();
    }

    /** @test */
    public function maintenance_service_runs_retention_and_archival(): void
    {
        $db = m::mock('Core\Database');
        $logger = m::mock('App\Contracts\LoggerInterface');
        $backupService = m::mock('App\Services\BackupService');

        $logger->shouldIgnoreMissing();
        
        $db->shouldReceive('beginTransaction')->byDefault();
        $db->shouldReceive('rollBack')->byDefault();
        $db->shouldReceive('commit')->byDefault();
        $db->shouldReceive('inTransaction')->byDefault()->andReturn(false);

        $pdoMock = m::mock(\PDO::class);
        $pdoMock->shouldReceive('lastInsertId')->andReturn('0');
        $db->shouldReceive('getPdo')->byDefault()->andReturn($pdoMock);

        // 1. Retention queries mock
        $stmtSessions = m::mock(\PDOStatement::class);
        $stmtSessions->shouldReceive('rowCount')->once()->andReturn(12);

        $stmtLogs = m::mock(\PDOStatement::class);
        $stmtLogs->shouldReceive('rowCount')->once()->andReturn(200);

        $db->shouldReceive('query')
            ->with("DELETE FROM user_sessions WHERE last_activity < DATE_SUB(NOW(), INTERVAL 30 DAY)")
            ->once()
            ->andReturn($stmtSessions);

        $db->shouldReceive('query')
            ->with("DELETE FROM activity_logs WHERE created_at < DATE_SUB(NOW(), INTERVAL 90 DAY)")
            ->once()
            ->andReturn($stmtLogs);

        // 2. Archival queries mock — batch-based archive to avoid long locks
        $db->shouldReceive('query')
            ->with("CREATE TABLE IF NOT EXISTS transactions_archive LIKE transactions")
            ->once();

        $db->shouldReceive('fetchAll')
            ->with(m::pattern('/SELECT id\s+FROM transactions/s'))
            ->once()
            ->andReturn([(object)['id' => 10], (object)['id' => 11]]);

        $stmtArchive = m::mock(\PDOStatement::class);
        $stmtArchive->shouldReceive('rowCount')->once()->andReturn(2);
        $db->shouldReceive('query')
            ->with(m::pattern('/INSERT INTO transactions_archive\s+SELECT \*/s'), [10, 11])
            ->once()
            ->andReturn($stmtArchive);

        $stmtTransactions = m::mock(\PDOStatement::class);
        $stmtTransactions->shouldReceive('rowCount')->once()->andReturn(2);

        $db->shouldReceive('query')
            ->with(m::pattern('/DELETE FROM transactions WHERE id IN/s'), [10, 11])
            ->once()
            ->andReturn($stmtTransactions); // mock batch deletes

        // 3. Backup cleanup mock
        $backupService->shouldReceive('cleanupOldBackups')->with(30)->once()->andReturn(['deleted' => 2]);

        // 4. System-cleanup dependencies (منطقِ ادغام‌شدهٔ SystemCleanupJob) — best-effort و بی‌اثر بر assertionهای retention/archival
        $systemLog = m::mock('App\Models\SystemLog');
        $securityLog = m::mock('App\Models\SecurityLog');
        $performanceLog = m::mock('App\Models\PerformanceLog');
        $idempotencyService = m::mock('App\Services\Shared\IdempotencyService');
        $systemLog->shouldReceive('deleteOlderThanChunked')->andReturn(0);
        $securityLog->shouldReceive('deleteOlderThanChunked')->andReturn(0);
        $performanceLog->shouldReceive('deleteOlderThanChunked')->andReturn(0);
        $idempotencyService->shouldReceive('cleanup')->andReturn(0);
        $db->shouldReceive('execute')->byDefault()->andReturn(0);
        $db->shouldReceive('fetchAll')->byDefault()->andReturn([]);

        $service = new MaintenanceService(
            $db,
            $logger,
            $backupService,
            $systemLog,
            $securityLog,
            $performanceLog,
            $idempotencyService,
            new \Core\PathResolver(dirname(__DIR__, 3))
        );

        $results = $service->runDailyMaintenance();

        $retention = $results['retention'] ?? null;
        $this->assertIsArray($retention);
        $this->assertEquals('success', $retention['status']);
        $this->assertEquals(12, $retention['cleared_sessions']);
        $this->assertEquals(200, $retention['cleared_logs']);
    }

    /** @test */
    public function backup_service_returns_correct_backups_and_stats(): void
    {
        $logger = m::mock('App\Contracts\LoggerInterface');
        $backupLogModel = m::mock('App\Models\BackupLog');

        $logger->shouldIgnoreMissing();

        $backupsMock = [
            ['id' => 1, 'file_path' => 'backup_1.sql', 'size_bytes' => 1024]
        ];

        $backupLogModel->shouldReceive('getRecentBackups')
            ->with(50, 0)
            ->once()
            ->andReturn($backupsMock);

        $statsMock = [
            'total_backups' => 1,
            'total_size' => 1048576, // 1 MB
            'last_backup' => '2026-06-03 12:00:00',
            'first_backup' => '2026-06-03 12:00:00'
        ];
        $backupLogModel->shouldReceive('getStats')->once()->andReturn($statsMock);

        $service = new BackupService($logger, $backupLogModel, new \Core\PathResolver(dirname(__DIR__, 3)));

        $listResult = $service->getBackups();
        $this->assertTrue($listResult['success']);
        $backups = $listResult['backups'] ?? null;
        $this->assertIsArray($backups);
        $this->assertCount(1, $backups);

        $statsResult = $service->getBackupStats();
        $this->assertTrue($statsResult['success']);
        $this->assertEquals(1, $statsResult['total_backups']);
        $this->assertEquals('1 MB', $statsResult['total_size']);
    }

    /** @test */
    public function sitemap_service_generates_valid_xml(): void
    {
        $cache = m::mock('Core\Cache');
        $pageModel = m::mock('App\Models\Page');
        $appSettings = m::mock('App\Services\Settings\AppSettings');

        $appSettings->shouldReceive('get')->with('site_url')->andReturn('https://chortke.com');

        $pageMock = (object)[
            'id' => 1,
            'slug' => 'about',
            'is_active' => true,
            'updated_at' => '2026-06-03 12:00:00'
        ];
        $pageModel->shouldReceive('getAll')->once()->andReturn([$pageMock]);

        $service = new SitemapService(
            $cache,
            $pageModel,
            new \Core\UrlGenerator('http://127.0.0.1:8090', null, null, 'testing'),
            new \Core\PathResolver(dirname(__DIR__, 3))
        );

        $xml = $service->generate();

        $this->assertStringContainsString('<?xml version="1.0" encoding="UTF-8"?>', $xml);
        $this->assertStringContainsString('<urlset', $xml);
        $this->assertStringContainsString('http://127.0.0.1:8090/pages/about', $xml);
    }
}
