<?php

declare(strict_types=1);

namespace App\Services\Health;

use Core\Database;
use Core\Cache;
use App\Services\DatabaseAnalyzerService;
use App\Models\SagaExecution;
use App\Models\FailedJob;

class HealthCheckService
{
    private Database $db;
    private Cache $cache;
    private ?DatabaseAnalyzerService $dbAnalyzer = null;

    public function __construct(Database $db, Cache $cache, ?DatabaseAnalyzerService $dbAnalyzer = null) {
        $this->db = $db;
        $this->cache = $cache;
        $this->dbAnalyzer = $dbAnalyzer;
    }

    /** Liveness Probe */
    /** @return array<string, mixed> */
    public function checkLiveness(): array
    {
        $status = 'ok';
        $checks = [];

        $memoryThreshold = int_value(config('health.thresholds.memory_usage', 85));
        $systemMemoryPercent = $this->getSystemMemoryUsagePercent();

        if ($systemMemoryPercent !== null) {
            $checks['memory'] = [
                'status' => $systemMemoryPercent < $memoryThreshold ? 'ok' : 'error',
                'usage_percent' => $systemMemoryPercent,
                'threshold_percent' => $memoryThreshold
            ];
            if ($systemMemoryPercent >= $memoryThreshold) {
                $status = 'error';
            }
        } else {
            $checks['memory'] = [
                'status' => 'ok',
                'process_memory_mb' => round(memory_get_usage(true) / (1024 * 1024), 2),
                'process_memory_peak_mb' => round(memory_get_peak_usage(true) / (1024 * 1024), 2)
            ];
        }

        return [
            'status' => $status,
            'timestamp' => date('c'),
            'checks' => $checks
        ];
    }

    /** Readiness Probe */
    /** @return array<string, mixed> */
    public function checkReadiness(): array
    {
        $checks = [];
        $status = 'ok';

        $checks['database'] = $this->probeDatabase();
        if (in_array($checks['database']['status'], ['error', 'degraded'], true)) {
            $status = $checks['database']['status'];
        }

        $checks['redis'] = $this->probeRedis();
        if ($checks['redis']['status'] === 'error') {
            $status = 'error';
        }

        $checks['queue'] = $this->probeQueue();
        if ($checks['queue']['status'] === 'degraded' && $status === 'ok') {
            $status = 'degraded';
        }

        $checks['disk'] = $this->probeDisk();
        if ($checks['disk']['status'] === 'error') {
            $status = 'error';
        }

        $checks['circuit_breakers'] = $this->probeCircuitBreakers();
        if ($checks['circuit_breakers']['status'] === 'degraded' && $status === 'ok') {
            $status = 'degraded';
        }

        $checks['external_gateways'] = $this->probeExternalGateways();
        if ($checks['external_gateways']['status'] === 'degraded' && $status === 'ok') {
            $status = 'degraded';
        }

        // NEW Option 3 distributed checks
        $checks['outbox'] = $this->probeOutbox();
        if (in_array($checks['outbox']['status'], ['error', 'degraded'], true) && $status === 'ok') {
            $status = $checks['outbox']['status'];
        }

        $checks['dlq'] = $this->probeDLQ();
        if (in_array($checks['dlq']['status'], ['error', 'degraded'], true) && $status === 'ok') {
            $status = $checks['dlq']['status'];
        }

        $checks['saga'] = $this->probeSaga();
        if (in_array($checks['saga']['status'], ['error', 'degraded'], true) && $status === 'ok') {
            $status = $checks['saga']['status'];
        }

        return [
            'status' => $status,
            'timestamp' => date('c'),
            'checks' => $checks
        ];
    }
    /** @return array<string, mixed> */

    private function probeDatabase(): array
    {
        try {
            $pdo = $this->db->getPdo();
            $startTime = microtime(true);
            $pdo->query("SELECT 1");
            $latency = round((microtime(true) - $startTime) * 1000, 2);

            $result = ['status' => 'ok', 'latency_ms' => $latency];

            // Deep analysis via DatabaseAnalyzerService
            if ($this->dbAnalyzer !== null) {
                try {
                    $slow = $this->dbAnalyzer->getSlowQueries(5);
                    $result['slow_query_count'] = count($slow);
                    if (count($slow) > 20) {
                        $result['status'] = 'degraded';
                        $result['warning'] = count($slow) . ' slow queries detected';
                    }
                } catch (\Throwable) {}

                try {
                    $deadlocks = $this->dbAnalyzer->getDeadlockInfo();
                    if (count($deadlocks) > 0) {
                        $result['deadlocks'] = count($deadlocks);
                        if ($result['status'] === 'ok') {
                            $result['status'] = 'degraded';
                        }
                        $result['warning'] = ($result['warning'] ?? '') . ' ' . count($deadlocks) . ' deadlock(s) detected';
                    }
                } catch (\Throwable) {}
            }

            return $result;
        } catch (\Throwable $e) {
            \App\Services\Sentry\SentryExceptionHandler::captureException($e, null, ['operation' => 'health.probeDatabase']);
            return ['status' => 'error', 'message' => 'Database query failed: ' . $e->getMessage()];
        }
    }
    /** @return array<string, mixed> */

    private function probeRedis(): array
    {
        $redisEnabled = config('redis.enabled', true);
        if (!$redisEnabled || !class_exists('Redis')) {
            return ['status' => 'disabled', 'driver' => 'file'];
        }
        try {
            $redis = new \Redis();
            $hostValue = config('redis.host', '127.0.0.1');
            if (!is_string($hostValue) || $hostValue === '') throw new \UnexpectedValueException('Redis host must be a non-empty string.');
            $redis->connect($hostValue, int_value(config('redis.port', 6379)), float_value(config('redis.timeout', 1.0)));
            $redis->set('health_check_temp', '1', 5);
            $redis->get('health_check_temp');
            $redis->del('health_check_temp');
            return ['status' => 'ok', 'driver' => 'redis'];
        } catch (\Throwable $e) {
            \App\Services\Sentry\SentryExceptionHandler::captureException($e, null, ['operation' => 'health.probeRedis']);
            return ['status' => 'error', 'message' => 'Redis connection failed: ' . $e->getMessage()];
        }
    }
    /** @return array<string, mixed> */

    private function probeQueue(): array
    {
        try {
            $pdo = $this->db->getPdo();
            $stmt1 = $pdo->query("SELECT COUNT(*) FROM queues WHERE reserved_at IS NULL AND available_at <= NOW()"); $pending = $stmt1 === false ? 0 : (int)$stmt1->fetchColumn();
            return ['status' => 'ok', 'pending_jobs' => $pending];
        } catch (\Throwable $e) {
            \App\Services\Sentry\SentryExceptionHandler::captureException($e, null, ['operation' => 'health.probeQueue']);
            return ['status' => 'error', 'message' => 'Queue check failed: ' . $e->getMessage()];
        }
    }

    /** @var array<string, mixed>|null */
    private ?array $lastCircuitBreakerProbe = null;
    /** @return array<string, mixed> */

    private function probeCircuitBreakers(): array
    {
        // ── Cache result to avoid double-probe from probeExternalGateways ──
        if ($this->lastCircuitBreakerProbe !== null) {
            return $this->lastCircuitBreakerProbe;
        }

        // ── لیست سرویس‌هایی که Circuit Breaker دارند ──────────────────────
        // کلیدها = نامی که به CircuitBreaker::call() پاس داده می‌شود
        $services = [
            // Payment Gateways
            'payment_gateway:zarinpal',
            'payment_gateway:idpay',
            'payment_gateway:nextpay',
            'payment_gateway:dgpay',
            // Crypto
            'crypto_api',
            'crypto_explorer_btc',
            'crypto_explorer_eth',
            'crypto_explorer_usdt',
            // KYC
            'deepface_kyc',
            // Banking
            'jibit',
            // Notifications
            'fcm_oauth',
            'fcm',
            'notif_sms',
            'notif_telegram',
            'log_notif_telegram',
        ];

        $cbConfigValue = config('circuit_breaker', []);
        if (!is_array($cbConfigValue)) throw new \UnexpectedValueException('circuit_breaker config must be an array.');
        $cbConfig = $cbConfigValue;
        $overallOk   = true;
        $servicesInfo = [];

        foreach ($services as $name) {
            $statusKey   = "circuit_breaker:{$name}:status";
            $failuresKey = "circuit_breaker:{$name}:failures";
            $openedAtKey = "circuit_breaker:{$name}:opened_at";

            $status       = str_value($this->cache->get($statusKey, 'closed'));
            $failures     = int_value($this->cache->get($failuresKey, 0));
            $openedAt     = int_value($this->cache->get($openedAtKey, 0));

            // خواندن threshold و timeout از config
            $serviceConfigValue = $cbConfig[$name] ?? [];
            if (!is_array($serviceConfigValue)) throw new \UnexpectedValueException("Circuit breaker {$name} config must be an array.");
            $threshold = int_value($serviceConfigValue['threshold'] ?? $cbConfig['failure_threshold'] ?? 5);
            $timeout = int_value($serviceConfigValue['timeout'] ?? $cbConfig['retry_timeout_seconds'] ?? 60);

            $info = [
                'status'   => $status,
                'failures' => $failures,
                'threshold' => $threshold,
                'timeout'   => $timeout,
            ];

            // اضافه کردن زمان باز شدن مدار برای سرویس‌های OPEN
            if ($status === 'open' && $openedAt > 0) {
                $info['opened_at']      = date('c', $openedAt);
                $info['seconds_in_open'] = time() - $openedAt;
                $info['retry_after']    = max(0, $timeout - (time() - $openedAt));
            }

            $servicesInfo[$name] = $info;

            // هر سرویس OPEN → degraded
            if ($status === 'open') {
                $overallOk = false;
            }
        }

        $this->lastCircuitBreakerProbe = [
            'status'   => $overallOk ? 'ok' : 'degraded',
            'services' => $servicesInfo,
        ];

        return $this->lastCircuitBreakerProbe;
    }
    /** @return array<string, mixed> */

    private function probeDisk(): array
    {
        return ['status' => 'ok'];
    }
    /** @return array<string, mixed> */

    private function probeExternalGateways(): array
    {
        // probeCircuitBreakers وضعیت gateway‌های خارجی رو از قبل نشون می‌ده
        // اینجا فقط جدول خلاصه از gateway‌هایی که OPEN هستن رو می‌سازیم
        $cb = $this->probeCircuitBreakers();
        $openGateways = [];

        $services = is_array($cb['services'] ?? null) ? $cb['services'] : [];
        foreach ($services as $name => $info) {
            if (is_array($info) && ($info['status'] ?? null) === 'open' && str_starts_with((string)$name, 'payment_gateway:')) {
                $openGateways[$name] = [
                    'status'       => 'open',
                    'retry_after'  => $info['retry_after'] ?? 0,
                ];
            }
        }

        $status = count($openGateways) > 0 ? 'degraded' : 'ok';

        return [
            'status'          => $status,
            'open_gateways'   => $openGateways,
            'total_monitored' => count(array_filter(
                array_keys($services),
                fn(int|string $k) => str_starts_with((string)$k, 'payment_gateway:')
            )),
        ];
    }

    /**
     * Reserved for future system-level memory monitoring.
     * Currently always returns null (liveness probe uses process memory instead).
     *
     * @return null
     */
    private function getSystemMemoryUsagePercent(): ?float
    {
        $meminfo = '/proc/meminfo';
        if (!is_readable($meminfo)) {
            return null;
        }
        $contents = file_get_contents($meminfo);
        if ($contents === false) {
            return null;
        }
        preg_match('/^MemTotal:\s+(\d+)\s+kB$/m', $contents, $totalMatch);
        preg_match('/^MemAvailable:\s+(\d+)\s+kB$/m', $contents, $availableMatch);
        if (!isset($totalMatch[1], $availableMatch[1]) || (int)$totalMatch[1] <= 0) {
            return null;
        }
        return round((1 - ((int)$availableMatch[1] / (int)$totalMatch[1])) * 100, 2);
    }

    // === Option 3 Distributed Probes (consolidated) ===
    /** @return array<string, mixed> */

    public function probeOutbox(): array
    {
        try {
            $pdo = $this->db->getPdo();
            $stmt2 = $pdo->query("SELECT COUNT(*) FROM outbox_events WHERE status='pending'"); $pending = $stmt2 === false ? 0 : (int)$stmt2->fetchColumn();
            $stmt3 = $pdo->query("SELECT COUNT(*) FROM outbox_events WHERE status IN ('failed','dlq')"); $failed = $stmt3 === false ? 0 : (int)$stmt3->fetchColumn();
            return ['status' => ($pending > 80 || $failed > 20 ? 'degraded' : 'ok'), 'pending' => $pending, 'failed_or_dlq' => $failed];
        } catch (\Throwable $e) {
            \App\Services\Sentry\SentryExceptionHandler::captureException($e, null, ['operation' => 'health.probeOutbox']);
            return ['status' => 'error', 'message' => $e->getMessage()];
        }
    }
    /** @return array<string, mixed> */

    public function probeDLQ(): array
    {
        try {
            $pdo = $this->db->getPdo();
            $stmt4 = $pdo->query("SELECT COUNT(*) FROM failed_jobs"); $total = $stmt4 === false ? 0 : (int)$stmt4->fetchColumn();
            return ['status' => ($total > 25 ? 'degraded' : 'ok'), 'total_failed_jobs' => $total];
        } catch (\Throwable $e) {
            \App\Services\Sentry\SentryExceptionHandler::captureException($e, null, ['operation' => 'health.probeDLQ']);
            return ['status' => 'error', 'message' => $e->getMessage()];
        }
    }
    /** @return array<string, mixed> */

    public function probeSaga(): array
    {
        try {
            $pdo = $this->db->getPdo();
            $stmt5 = $pdo->query("SELECT COUNT(*) FROM saga_executions WHERE status='running'"); $running = $stmt5 === false ? 0 : (int)$stmt5->fetchColumn();
            $stmt6 = $pdo->query("SELECT COUNT(*) FROM saga_executions WHERE status='failed'"); $failed = $stmt6 === false ? 0 : (int)$stmt6->fetchColumn();
            return ['status' => ($failed > 5 ? 'degraded' : 'ok'), 'running' => $running, 'failed' => $failed];
        } catch (\Throwable $e) {
            \App\Services\Sentry\SentryExceptionHandler::captureException($e, null, ['operation' => 'health.probeSaga']);
            return ['status' => 'error', 'message' => $e->getMessage()];
        }
    }
    /** @return array<string, mixed> */

    public function probeIdempotency(): array
    {
        try {
            $pdo = $this->db->getPdo();
            $stmt7 = $pdo->query("SELECT COUNT(*) FROM idempotency_keys WHERE status='pending'"); $pending = $stmt7 === false ? 0 : (int)$stmt7->fetchColumn();
            return ['status' => 'ok', 'pending_keys' => $pending];
        } catch (\Throwable $e) {
            \App\Services\Sentry\SentryExceptionHandler::captureException($e, null, ['operation' => 'health.probeIdempotency']);
            return ['status' => 'error', 'message' => $e->getMessage()];
        }
    }
    /** @return array<string, mixed> */

    public function collectDistributedMetrics(): array
    {
        $m = [];
        try {
            $pdo = $this->db->getPdo();
            $stmt = $pdo->query("SELECT status, COUNT(*) as c FROM outbox_events GROUP BY status");
            if ($stmt) {
                $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
                foreach ($rows as $r) { $m['outbox_' . $r['status']] = intval($r['c']); }
            }
            $stmt2 = $pdo->query("SELECT COUNT(*) FROM failed_jobs");
            $m['failed_jobs_total'] = $stmt2 ? (int)$stmt2->fetchColumn() : 0;
        } catch (\Throwable $e) {
            @error_log('[HealthCheckService\] ' . $e->getMessage());
        }
        return $m;
    }
}
