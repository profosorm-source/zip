<?php

declare(strict_types=1);

namespace App\Commands;

use App\Services\DatabaseAnalyzerService;
use Core\Command;

class DatabaseAnalyzerCommand extends Command
{
    private DatabaseAnalyzerService $analyzer;

    public function __construct(DatabaseAnalyzerService $analyzer)
    {
        $this->analyzer = $analyzer;
    }

    /**
     * @param list<string> $args
     */
    /** @param array<int|string, mixed> $args */
    public function run(array $args = []): void
    {
        $subAction = $args[2] ?? 'overview';

        switch ($subAction) {
            case 'indexes':
                $table = $args[3] ?? '';
                if ($table === '') {
                    $this->error('Usage: php cli.php db:analyze indexes <table_name>');
                    return;
                }
                $this->showIndexRecommendations($table);
                break;

            case 'suggest-indexes':
                $table = $args[3] ?? '';
                if ($table === '') {
                    $this->error('Usage: php cli.php db:analyze suggest-indexes <table_name>');
                    return;
                }
                $this->showSuggestedIndexes($table);
                break;

            case 'query':
                $sql = $args[3] ?? '';
                if ($sql === '') {
                    $this->error('Usage: php cli.php db:analyze query "SELECT ..."');
                    return;
                }
                $this->analyzeQuery($sql);
                break;

            case 'slow-queries':
                $this->showSlowQueries();
                break;

            case 'deadlocks':
                $this->showDeadlockInfo();
                break;

            case 'health':
                $this->showHealth();
                break;

            case 'connections':
                $this->showConnections();
                break;

            case 'optimize':
                $this->runOptimize();
                break;

            case 'overview':
            default:
                $this->showOverview();
                break;
        }
    }

    private function showOverview(): void
    {
        $this->info('═══ Database Health Overview ═══');

        $this->line('');
        $this->info('Connection Pool:');
        try {
            $stats = $this->analyzer->getConnectionStats();
            foreach ((array)$stats as $key => $val) {
                $this->line("  " . str_value($key) . ": " . str_value($val));
            }
        } catch (\Throwable $e) {
            $this->line("  (unavailable)");
        }

        $this->line('');
        $this->info('Health Check:');
        try {
            $health = $this->analyzer->healthCheck();
            foreach ((array)$health as $key => $val) {
                if (is_array($val)) {
                    $strVal = implode(', ', array_map('str_value', $val));
                } else {
                    $strVal = str_value($val);
                }
                $status = !empty($val) ? '✅' : 'ℹ️';
                $this->line("  {$status} {$key}: " . ($strVal !== '' ? $strVal : 'OK'));
            }
        } catch (\Throwable $e) {
            $this->line("  (unavailable)");
        }

        $this->line('');
        $this->info('Table Statistics:');
        try {
            $tables = $this->analyzer->getTableStats();
            foreach ($tables as $row) {
                $item = (object)$row;
                $name = (string)($item->table_name ?? $item->TABLE_NAME ?? '?');
                $rows = (string)($item->row_count ?? $item->TABLE_ROWS ?? '?');
                $size = (string)($item->size_mb ?? $item->SIZE_MB ?? '?');
                $this->line(sprintf("  %-40s %10s rows   %10s", $name, $rows, $size));
            }
        } catch (\Throwable $e) {
            $this->line("  (unavailable)");
        }

        $this->line('');
        $this->info("Available sub-commands:");
        $this->line("  php cli.php db:analyze indexes <table>        — FK-based index recommendations");
        $this->line("  php cli.php db:analyze suggest-indexes <table> — Smart index suggestions");
        $this->line("  php cli.php db:analyze query \"<SQL>\"           — EXPLAIN a query");
        $this->line("  php cli.php db:analyze slow-queries              — List slow queries");
        $this->line("  php cli.php db:analyze deadlocks                 — Check for deadlocks");
        $this->line("  php cli.php db:analyze health                    — Full health check");
        $this->line("  php cli.php db:analyze connections               — Connection pool stats");
        $this->line("  php cli.php db:analyze optimize                  — Run optimization");
    }

    private function showIndexRecommendations(string $table): void
    {
        $this->info("FK-based index recommendations for: {$table}");
        try {
            $recs = $this->analyzer->getIndexRecommendations($table);
            if (empty($recs)) {
                $this->line("  No recommendations.");
                return;
            }
            foreach ($recs as $rec) {
                $col = str_value($rec['column'] ?? '');
                $sug = str_value($rec['suggestion'] ?? '');
                $this->line(sprintf("  %-30s → %s", $col, $sug));
            }
        } catch (\Throwable $e) {
            $this->error("Error: " . $e->getMessage());
        }
    }

    private function showSuggestedIndexes(string $table): void
    {
        $this->info("Smart index suggestions for: {$table}");
        try {
            $suggestions = $this->analyzer->suggestIndexes($table);
            if (empty($suggestions)) {
                $this->line("  No suggestions.");
                return;
            }
            foreach ($suggestions as $s) {
                $idxName = str_value($s['index_name'] ?? '');
                $score = str_value($s['score'] ?? '-');
                $this->line(sprintf("  %-40s (score: %s)", $idxName, $score));
            }
        } catch (\Throwable $e) {
            $this->error("Error: " . $e->getMessage());
        }
    }

    private function analyzeQuery(string $sql): void
    {
        $this->info("Analyzing query...");
        try {
            $result = $this->analyzer->analyzeQuery($sql);
            $json = json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            $this->line($json === false ? 'Unable to encode query analysis.' : $json);
        } catch (\Throwable $e) {
            $this->error("Error: " . $e->getMessage());
        }
    }

    private function showSlowQueries(): void
    {
        $this->info('Recent Slow Queries:');

        try {
            $queries = $this->analyzer->getSlowQueries();
            if ($queries === []) {
                $this->line('  No slow-query data is available.');
                return;
            }

            $json = json_encode($queries, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            $this->line($json === false ? '  Unable to encode slow-query data.' : $json);
        } catch (\Throwable $e) {
            $this->error('Error: ' . $e->getMessage());
        }
    }

    private function showDeadlockInfo(): void
    {
        $this->info('Recent InnoDB Deadlocks:');

        try {
            $deadlocks = $this->analyzer->getDeadlockInfo();
            if ($deadlocks === []) {
                $this->line('  No recent deadlocks detected.');
                return;
            }

            $json = json_encode($deadlocks, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            $this->line($json === false ? '  Unable to encode deadlock data.' : $json);
        } catch (\Throwable $e) {
            $this->error('Error: ' . $e->getMessage());
        }
    }

    private function showHealth(): void
    {
        $this->info('Database Health Check:');
        try {
            $health = $this->analyzer->healthCheck();
            foreach ((array)$health as $key => $val) {
                $status = $val ? '✅' : '❌';
                $this->line("  {$status} {$key}" . ($val ? '' : ' — NEEDS ATTENTION'));
            }
        } catch (\Throwable $e) {
            $this->error("Error: " . $e->getMessage());
        }
    }

    private function showConnections(): void
    {
        $this->info('Connection Pool Stats:');
        try {
            $stats = $this->analyzer->getConnectionStats();
            foreach ((array)$stats as $key => $val) {
                $this->line("  " . str_value($key) . ": " . str_value($val));
            }
        } catch (\Throwable $e) {
            $this->error("Error: " . $e->getMessage());
        }
    }

    private function runOptimize(): void
    {
        $this->info('Running database optimization...');
        try {
            $result = $this->analyzer->optimizeDatabase();
            $json = json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            $this->line($json === false ? 'Unable to encode optimization result.' : $json);
        } catch (\Throwable $e) {
            $this->error("Error: " . $e->getMessage());
        }
    }
}
