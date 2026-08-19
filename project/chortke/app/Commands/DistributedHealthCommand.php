<?php

declare(strict_types=1);

namespace App\Commands;

use App\Contracts\CommandInterface;
use Core\Container;
use App\Services\Health\HealthCheckService;

/**
 * Distributed Systems Health Check (refactored to reuse HealthCheckService).
 * 
 * Usage: php cli.php distributed:health
 * 
 * This command now reuses the centralized probes in HealthCheckService
 * instead of duplicating SQL logic. It still has a standalone PDO fallback
 * for robustness when full bootstrap fails.
 */
class DistributedHealthCommand implements CommandInterface
{
    private Container $container;

    public function __construct(Container $container)
    {
        $this->container = $container;
    }

    /** @param array<string, mixed> $args */
    /** @param array<string, mixed> $args */
    public function run(array $args = []): void
    {
        echo "=== Chortke Distributed Systems Health Check ===\n\n";

        $healthService = null;
        try {
            $healthService = $this->container->make(HealthCheckService::class);
        } catch (\Throwable $e) {
            // Fall through to standalone mode
        }

        if ($healthService instanceof HealthCheckService) {
            // Preferred path: reuse existing service
            $this->printUsingService($healthService);
        } else {
            // Robust standalone fallback (direct PDO)
            $this->printStandalone();
        }

        echo "\n✅ Health check finished at " . date('Y-m-d H:i:s') . "\n";
    }

    private function printUsingService(HealthCheckService $svc): void
    {
        echo "📊 Using centralized HealthCheckService\n\n";

        $outbox = $svc->probeOutbox();
        $outboxPending = is_numeric($outbox['pending'] ?? null) ? (int)$outbox['pending'] : 0;
        $outboxFailed = is_numeric($outbox['failed_or_dlq'] ?? null) ? (int)$outbox['failed_or_dlq'] : 0;
        $outboxStatus = is_string($outbox['status'] ?? null) ? $outbox['status'] : 'unknown';
        printf("📤 Outbox: pending=%d failed/dlq=%d (status: %s)\n", $outboxPending, $outboxFailed, $outboxStatus);

        $dlq = $svc->probeDLQ();
        $dlqTotal = is_numeric($dlq['total_failed_jobs'] ?? null) ? (int)$dlq['total_failed_jobs'] : 0;
        $dlqStatus = is_string($dlq['status'] ?? null) ? $dlq['status'] : 'unknown';
        printf("💀 DLQ (failed_jobs): %d (status: %s)\n", $dlqTotal, $dlqStatus);

        $saga = $svc->probeSaga();
        $sagaRunning = is_numeric($saga['running'] ?? null) ? (int)$saga['running'] : 0;
        $sagaFailed = is_numeric($saga['failed'] ?? null) ? (int)$saga['failed'] : 0;
        $sagaStatus = is_string($saga['status'] ?? null) ? $saga['status'] : 'unknown';
        printf("🔄 Saga: running=%d failed=%d (status: %s)\n", $sagaRunning, $sagaFailed, $sagaStatus);

        $idemp = $svc->probeIdempotency();
        $pendingKeys = is_numeric($idemp['pending_keys'] ?? null) ? (int)$idemp['pending_keys'] : 0;
        printf("🔁 Idempotency pending keys: %d\n", $pendingKeys);

        echo "\n";
    }

    private function printStandalone(): void
    {
        echo "⚠️  Using standalone PDO fallback (HealthCheckService not available)\n\n";

        $pdo = $this->getPdo();
        if (!$pdo) {
            echo "❌ Could not connect to database.\n";
            return;
        }

        // Same queries as before for robustness
        try {
            $pending = $this->queryCount($pdo, "SELECT COUNT(*) FROM outbox_events WHERE status='pending'");
            $bad = $this->queryCount($pdo, "SELECT COUNT(*) FROM outbox_events WHERE status IN ('failed','dlq')");
            printf("📤 Outbox: Pending=%d Failed/DLQ=%d\n", $pending, $bad);
        } catch (\Throwable $e) { echo "📤 Outbox: (error)\n"; }

        try {
            $c = $this->queryCount($pdo, "SELECT COUNT(*) FROM failed_jobs");
            printf("💀 DLQ (failed_jobs): %d\n", $c);
        } catch (\Throwable $e) { echo "💀 DLQ: (error)\n"; }

        try {
            $run = $this->queryCount($pdo, "SELECT COUNT(*) FROM saga_executions WHERE status='running'");
            $fail = $this->queryCount($pdo, "SELECT COUNT(*) FROM saga_executions WHERE status='failed'");
            printf("🔄 Saga: running=%d failed=%d\n", $run, $fail);
        } catch (\Throwable $e) { echo "🔄 Saga: (error)\n"; }

        try {
            $p = $this->queryCount($pdo, "SELECT COUNT(*) FROM idempotency_keys WHERE status='pending'");
            printf("🔁 Idempotency pending: %d\n", $p);
        } catch (\Throwable $e) { echo "🔁 Idempotency: (error)\n"; }
    }

    private function queryCount(\PDO $pdo, string $sql): int
    {
        $statement = $pdo->query($sql);
        if ($statement === false) {
            throw new \RuntimeException('Database health-check query failed to execute.');
        }

        $value = $statement->fetchColumn();
        if (!is_int($value) && !is_string($value)) {
            throw new \UnexpectedValueException('Database health-check count query returned a non-scalar value.');
        }
        if (!is_numeric($value)) {
            throw new \UnexpectedValueException('Database health-check count query returned a non-numeric value.');
        }

        return (int)$value;
    }

    private function getPdo(): ?\PDO
    {
        try {
            $db = $this->container->make(\Core\Database::class);
            if ($db instanceof \Core\Database) {
                return $db->getPdo();
            }
        } catch (\Throwable $e) {
            @error_log('[DistributedHealthCommand\] ' . $e->getMessage());
        }

        $envPath = __DIR__ . '/../../.env';
        if (!file_exists($envPath)) return null;

        $env = parse_ini_file($envPath, false, INI_SCANNER_RAW);
        $host = $env['DB_HOST'] ?? '127.0.0.1';
        $port = $env['DB_PORT'] ?? '3306';
        $db   = $env['DB_NAME'] ?? 'chortke';
        $user = $env['DB_USER'] ?? 'chortke_user';
        $pass = $env['DB_PASS'] ?? 'chortke_pass_123';

        try {
            $dsn = "mysql:host=$host;port=$port;dbname=$db;charset=utf8mb4";
            return new \PDO($dsn, $user, $pass, [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_OBJ,
            ]);
        } catch (\Throwable $e) {
            return null;
        }
    }
}
