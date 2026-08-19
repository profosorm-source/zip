<?php

declare(strict_types=1);

namespace App\Services;

use Core\Database;
use App\Contracts\LoggerInterface;

/**
 * DatabaseAnalyzerService - Specialized DBA and Query Analysis tools
 * 
 * Extracted from PerformanceOptimizationService to adhere to SRP.
 * Focuses strictly on database health, indexing, and EXPLAIN analysis.
 */
class DatabaseAnalyzerService
{

    private float $slowQueryThreshold = 1.0;

    private \Core\Database $db;
    private \App\Contracts\LoggerInterface $logger;
    public function __construct(
        \Core\Database $db,
        \App\Contracts\LoggerInterface $logger
    ) {        $this->db = $db;
        $this->logger = $logger;

        
        $this->slowQueryThreshold = float_value(config('logging.performance.slow_query_threshold', 1.0));
    }

    /** @return list<array<string, mixed>> */
    public function getIndexRecommendations(string $table): array
    {
        try {
            $result = $this->db->query(
                "SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE 
                 FROM INFORMATION_SCHEMA.COLUMNS
                 WHERE TABLE_NAME = ? AND TABLE_SCHEMA = DATABASE()",
                [$table]
            )->fetchAll() ?? [];

            $recommendations = [];
            foreach ($result as $col) {
                $columnName = $col->COLUMN_NAME;
                if (strpos($columnName, '_id') !== false) {
                    $recommendations[] = ['column' => $columnName, 'reason' => 'Foreign key', 'suggestion' => "ALTER TABLE `{$table}` ADD INDEX idx_{$columnName} (`{$columnName}`);"];
                }
                if (in_array($columnName, ['status', 'state', 'active'], true)) {
                    $recommendations[] = ['column' => $columnName, 'reason' => 'Status column', 'suggestion' => "ALTER TABLE `{$table}` ADD INDEX idx_{$columnName} (`{$columnName}`);"];
                }
                if (in_array($columnName, ['created_at', 'updated_at'], true)) {
                    $recommendations[] = ['column' => $columnName, 'reason' => 'Timestamp', 'suggestion' => "ALTER TABLE `{$table}` ADD INDEX idx_{$columnName} (`{$columnName}`);"];
                }
            }
            return $recommendations;
        } catch (\Exception $e) {
            $this->logger->error('performance.index_recommendations.failed', ['error' => $e->getMessage()]);
            return [];
        }
    }

    /** @return array<string, mixed> */
    public function getConnectionStats(): array
    {
        try {
            $status = $this->db->query("SHOW STATUS LIKE 'Threads%'")->fetchAll(\PDO::FETCH_ASSOC) ?: [];
            $stats = [];
            if (is_array($status) && count($status) > 0) {
                foreach ($status as $row) {
                    $name = $row['Variable_name'] ?? null;
                    if (is_string($name)) $stats[$name] = $row['Value'] ?? null;
                }
            }
            return $stats;
        } catch (\Exception $e) {
            $this->logger->error('performance.connection_stats.failed', ['error' => $e->getMessage()]);
            return [];
        }
    }

    /** @return array<int, array<string, mixed>> */
    public function getCachingRecommendations(): array
    {
        return [
            ['entity' => 'Search Results', 'ttl' => 300, 'reason' => 'Frequently searched', 'cache_key' => 'search:{module}:{hash}'],
            ['entity' => 'User Profiles', 'ttl' => 900, 'reason' => 'Accessed frequently', 'cache_key' => 'profile:user:{user_id}'],
            ['entity' => 'Settings', 'ttl' => 3600, 'reason' => 'Admin settings rarely change', 'cache_key' => 'setting:{setting_key}'],
            ['entity' => 'Statistics', 'ttl' => 600, 'reason' => 'Expensive aggregation queries', 'cache_key' => 'stat:{type}:{period}'],
            ['entity' => 'Categories/Taxonomies', 'ttl' => 86400, 'reason' => 'Static data', 'cache_key' => 'taxonomy:{type}']
        ];
    }

    /** @return array<string, mixed> */
    public function optimizeDatabase(): array
    {
        try {
            $tables = $this->db->query(
                "SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES 
                 WHERE TABLE_SCHEMA = DATABASE()"
            )->fetchAll() ?? [];

            $results = [];
            foreach ($tables as $table) {
                $tableName = $table->TABLE_NAME;
                $this->db->query("ANALYZE TABLE `{$tableName}`");
                $this->db->query("OPTIMIZE TABLE `{$tableName}`");
                $results[] = $tableName;
            }

            $this->logger->info('performance.database_optimized', ['tables' => count($results)]);
            return ['ok' => true, 'optimized_tables' => $results, 'count' => count($results)];
        } catch (\Exception $e) {
            $this->logger->error('performance.optimization.failed', ['error' => $e->getMessage()]);
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /** @return list<array<string, mixed>> */
    public function getTableStats(): array
    {
        try {
            $stats = $this->db->query(
                "SELECT TABLE_NAME, TABLE_ROWS as row_count, ROUND(((data_length + index_length) / 1024 / 1024), 2) as size_mb
                 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() ORDER BY data_length DESC"
            )->fetchAll() ?? [];

            return array_map(function ($stat) {
                return ['table' => $stat->TABLE_NAME, 'rows' => $stat->row_count, 'size_mb' => $stat->size_mb];
            }, $stats);
        } catch (\Exception $e) {
            $this->logger->error('performance.table_stats.failed', ['error' => $e->getMessage()]);
            return [];
        }
    }

    /**
     * @param array<int|string, mixed> $params
     * @return array<string, mixed>
     */
    public function executeWithAnalysis(string $sql, array $params = [], bool $analyze = true): array
    {
        $startTime = microtime(true);
        $stmt = $this->db->query($sql, $params);
        $results = $stmt->fetchAll(\PDO::FETCH_OBJ) ?? [];
        $executionTime = microtime(true) - $startTime;

        $analysis = null;
        if ($analyze && $executionTime > $this->slowQueryThreshold) {
            $analysis = $this->analyzeQuery($sql);
        }

        return [
            'results' => $results,
            'execution_time' => $executionTime,
            'is_slow' => $executionTime > $this->slowQueryThreshold,
            'analysis' => $analysis,
        ];
    }

    /** @return array<string, mixed> */
    public function analyzeQuery(string $sql): array
    {
        try {
            if (!preg_match('/^\s*SELECT\s+/i', trim((string)$sql))) {
                return ['error' => 'EXPLAIN only works with SELECT queries', 'explain' => [], 'issues' => [], 'suggestions' => [], 'needs_optimization' => false];
            }
            $stmt = $this->db->query('EXPLAIN ' . $sql);
            $explain = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?? [];
            
            $issues = [];
            $suggestions = [];
            
            foreach ($explain as $row) {
                if (isset($row['type']) && $row['type'] === 'ALL') {
                    $issues[] = "Full table scan on table: {$row['table']}";
                    $suggestions[] = "Consider adding index on: {$row['table']}";
                }
                if (isset($row['Extra']) && strpos($row['Extra'], 'Using filesort') !== false) {
                    $issues[] = "Using filesort (expensive sorting)";
                    $suggestions[] = "Add index to support ORDER BY";
                }
                if (isset($row['Extra']) && strpos($row['Extra'], 'Using temporary') !== false) {
                    $issues[] = "Using temporary table";
                    $suggestions[] = "Optimize query to avoid temporary tables";
                }
                if (isset($row['rows']) && $row['rows'] > 10000) {
                    $issues[] = "Examining too many rows: {$row['rows']}";
                    $suggestions[] = "Add more specific WHERE clauses or indexes";
                }
            }
            
            return ['explain' => $explain, 'issues' => $issues, 'suggestions' => $suggestions, 'needs_optimization' => !empty($issues)];
        } catch (\Throwable $e) {
            return ['error' => 'analysis_failed', 'message' => $e->getMessage()];
        }
    }

    /** @return array<int, array<string, mixed>> */
    public function suggestIndexes(string $table): array
    {
        try {
            $columns = $this->db->query(
                "SELECT COLUMN_NAME, COLUMN_KEY, DATA_TYPE
                 FROM INFORMATION_SCHEMA.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?",
                [$table]
            )->fetchAll() ?? [];

            $suggestions = [];
            foreach ($columns as $col) {
                $name = $col->COLUMN_NAME;
                if (in_array($name, ['created_at', 'updated_at', 'deleted_at', 'last_seen'], true)) {
                    $suggestions[] = [
                        'column' => $name,
                        'reason' => 'Timestamp/indexed audit field',
                        'suggestion' => "ALTER TABLE `{$table}` ADD INDEX idx_{$name} (`{$name}`);",
                    ];
                }

                if (strpos($name, '_id') !== false || $name === 'user_id' || $name === 'account_id') {
                    $suggestions[] = [
                        'column' => $name,
                        'reason' => 'Foreign key or lookup field',
                        'suggestion' => "ALTER TABLE `{$table}` ADD INDEX idx_{$name} (`{$name}`);",
                    ];
                }

                if (in_array($name, ['status', 'state', 'active'], true)) {
                    $suggestions[] = [
                        'column' => $name,
                        'reason' => 'Frequently filtered status field',
                        'suggestion' => "ALTER TABLE `{$table}` ADD INDEX idx_{$name} (`{$name}`);",
                    ];
                }
            }

            return array_values($suggestions);
        } catch (\Throwable $e) {
            $this->logger->error('performance.suggest_indexes.failed', ['table' => $table, 'error' => $e->getMessage()]);
            return [];
        }
    }

    /**
     * Get recent slow queries from performance_schema or slow query log.
     */
    /** @return list<array<string, mixed>> */
    public function getSlowQueries(int $limit = 20): array
    {
        $limit = max(1, min(100, $limit));

        try {
            // Optional observability probe: use PDO directly so missing
            // performance_schema grants do not get logged as application DB errors.
            $pdo = $this->db->getPdo();
            $stmt = $pdo->prepare(
                "SELECT DIGEST_TEXT AS sql_text,
                        COUNT_STAR AS query_count,
                        ROUND(AVG_TIMER_WAIT / 1000000000, 2) AS avg_time_ms,
                        ROUND(MAX_TIMER_WAIT / 1000000000, 2) AS max_time_ms
                 FROM performance_schema.events_statements_summary_by_digest
                 WHERE DIGEST_TEXT IS NOT NULL
                   AND AVG_TIMER_WAIT > 1000000000
                 ORDER BY AVG_TIMER_WAIT DESC
                 LIMIT :limit"
            );
            $stmt->bindValue(':limit', $limit, \PDO::PARAM_INT);
            $stmt->execute();
            $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
            if ($rows !== []) {
                return $rows;
            }
        } catch (\Throwable) {
            // performance_schema can be unavailable or restricted for app DB users.
        }

        try {
            $stmt = $this->db->getPdo()->query("SHOW VARIABLES LIKE 'long_query_time'");
            if ($stmt === false) return [];
            $row = $stmt->fetch(\PDO::FETCH_ASSOC) ?: false;
            $threshold = is_array($row) ? ($row['Value'] ?? $row['VARIABLE_VALUE'] ?? 'unknown') : 'unknown';
            return [[
                'slow_query_threshold_seconds' => $threshold ?: 'unknown',
                'note' => 'Slow query log enabled but no queries captured in this interval.',
            ]];
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Check for recent InnoDB deadlocks.
     */
    /** @return array<int, array<string, mixed>> */
    public function getDeadlockInfo(): array
    {
        try {
            // Optional privileged probe; use PDO directly to avoid noisy DB error logs
            // when PROCESS privilege is not granted to the application user.
            $stmt = $this->db->getPdo()->query("SHOW ENGINE INNODB STATUS");
            if ($stmt === false) return [];
            $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
            if (empty($rows)) return [];
            
            $status = (string)($rows[0]['Status'] ?? $rows[0]['status'] ?? '');
            $deadlocks = [];
            
            // Parse "LATEST DETECTED DEADLOCK" section
            if (preg_match('/LATEST DETECTED DEADLOCK\n-+\n(.*?)(?:
-+
|\z)/s', $status, $m)) {
                $deadlockTime = '';
                if (preg_match('/(\d{4}-\d{2}-\d{2}\s+\d{2}:\d{2}:\d{2})/', $m[1], $tm)) {
                    $deadlockTime = $tm[1];
                }
                $deadlocks[] = [
                    'detected_at' => $deadlockTime ?: 'unknown',
                    'summary' => substr(explode("
", trim($m[1]))[4] ?? '', 0, 200),
                ];
            }
            return $deadlocks;
        } catch (\Throwable) {
            return [];
        }
    }

    /** @return array<string, mixed> */
    public function healthCheck(): array
    {
        $issues = [];
        $recommendations = [];
        try {
            $tables = $this->db->query("SHOW TABLES")->fetchAll(\PDO::FETCH_COLUMN) ?: [];
            if (is_array($tables) && count($tables) > 0) {
                foreach ($tables as $table) {
                    $tableName = is_string($table) ? $table : '';
                    if ($tableName === '') continue;
                    $keys = $this->db->query("SHOW KEYS FROM `{$tableName}` WHERE Key_name = 'PRIMARY'")->fetchAll(\PDO::FETCH_ASSOC) ?: [];
                    if (empty($keys)) {
                        $issues[] = "Table `{$tableName}` has no primary key";
                        $recommendations[] = "Add primary key to `{$tableName}`";
                    }
                }
            }
            $largeTables = $this->db->query("SELECT TABLE_NAME, TABLE_ROWS FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_ROWS > 10000")->fetchAll(\PDO::FETCH_ASSOC) ?? [];
            foreach ($largeTables as $tableInfo) {
                $indexes = $this->db->query("SHOW INDEXES FROM `{$tableInfo['TABLE_NAME']}`")->fetchAll(\PDO::FETCH_ASSOC) ?? [];
                if (count($indexes) <= 1) {
                    $issues[] = "Large table `{$tableInfo['TABLE_NAME']}` ({$tableInfo['TABLE_ROWS']} rows) has minimal indexing";
                }
            }
            $myisamTables = $this->db->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND ENGINE = 'MyISAM'")->fetchAll(\PDO::FETCH_COLUMN) ?? [];
            if (!empty($myisamTables)) {
                $issues[] = "Found MyISAM tables: " . implode(', ', $myisamTables);
            }
        } catch (\Throwable $e) {
            $issues[] = "Health check failed: " . $e->getMessage();
        }
        return ['healthy' => empty($issues), 'issues' => $issues, 'recommendations' => $recommendations, 'checked_at' => date('Y-m-d H:i:s')];
    }
}
