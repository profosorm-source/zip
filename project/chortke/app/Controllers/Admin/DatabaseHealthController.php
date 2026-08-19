<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Services\DatabaseAnalyzerService;

class DatabaseHealthController extends BaseAdminController
{
    private DatabaseAnalyzerService $dbAnalyzer;

    public function __construct(
        DatabaseAnalyzerService $dbAnalyzer,
        ?\App\Contracts\LoggerInterface $logger = null
    ) {
        parent::__construct(null, null, null, null, $logger);
        $this->dbAnalyzer = $dbAnalyzer;
    }

    public function index(): void
    {
        $slowQueries  = [];
        $deadlocks    = [];
        $tableStats   = [];
        $healthChecks = [];
        $indexRecs    = [];
        $connStats    = [];
        $error        = null;

        try {
            $slowQueries  = $this->dbAnalyzer->getSlowQueries(30);
        } catch (\Throwable $e) {
            $error = 'خطا در واکشی کوئری‌های کند: ' . $e->getMessage();
        }

        try {
            $deadlocks = $this->dbAnalyzer->getDeadlockInfo();
        } catch (\Throwable $e) {
            logger()->warning('db_health.deadlocks_failed', ['error' => $e->getMessage()]);
        }

        try {
            $tableStats = $this->dbAnalyzer->getTableStats();
        } catch (\Throwable $e) {
            logger()->warning('db_health.table_stats_failed', ['error' => $e->getMessage()]);
        }

        try {
            $healthChecks = $this->dbAnalyzer->healthCheck();
        } catch (\Throwable $e) {
            logger()->warning('db_health.health_check_failed', ['error' => $e->getMessage()]);
        }

        try {
            $connStats = $this->dbAnalyzer->getConnectionStats();
        } catch (\Throwable $e) {
            logger()->warning('db_health.conn_stats_failed', ['error' => $e->getMessage()]);
        }

        // Get index recommendations for top tables (largest first)
        $topTables = array_slice($tableStats, 0, 5);
        try {
        foreach ($topTables as $tbl) {
            $tblObj = (object)$tbl;
            $tblName = (string)($tblObj->table_name ?? $tblObj->TABLE_NAME ?? '');
            if ($tblName) {
                $recs = $this->dbAnalyzer->getIndexRecommendations($tblName);
                if (!empty($recs)) {
                    $indexRecs[$tblName] = $recs;
                }
            }
        }
        } catch (\Throwable $e) {
            logger()->warning('db_health.index_recs_failed', ['error' => $e->getMessage()]);
        }

        $this->view('admin/database-health', [
            'title'         => 'سلامت دیتابیس',
            'slowQueries'   => $slowQueries,
            'deadlocks'     => $deadlocks,
            'tableStats'    => $tableStats,
            'healthChecks'  => $healthChecks,
            'indexRecs'     => $indexRecs,
            'connStats'     => $connStats,
            'error'         => $error,
        ]);
    }
}
