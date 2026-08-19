<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Contracts\LoggerInterface;
use App\Services\Shared\DashboardStatsService;

/**
 * MetricsController - Exposes Prometheus metrics
 */
class MetricsController extends \App\Controllers\BaseController
{
    private DashboardStatsService $metricsService;

    private \Core\Redis $redis;
    private \Core\Queue $queue;
    private \Core\CircuitBreaker $circuitBreaker;

    public function __construct(
        LoggerInterface $logger,
        DashboardStatsService $metricsService,
        ?\Core\Redis $redis = null,
        ?\Core\Queue $queue = null,
        ?\Core\CircuitBreaker $circuitBreaker = null
    ) {
        parent::__construct(null, null, null, null, $logger);
        $this->redis          = $redis          ?? app(\Core\Redis::class);
        $this->queue          = $queue          ?? app(\Core\Queue::class);
        $this->circuitBreaker = $circuitBreaker ?? app(\Core\CircuitBreaker::class);
        $this->metricsService = $metricsService;
    }

    public function metrics(): void
    {
        // 1. IP Whitelisting / Token Authorization
        $allowedIpsConfig = config('health.allowed_ips', ['127.0.0.1', '::1']);
        $allowedIps = is_array($allowedIpsConfig) ? $allowedIpsConfig : [];
        $clientIp = $this->request->ip();
        $token = $this->request->query('token', '');
        $expectedToken = config('health.check_token');

        $isIpAllowed = in_array($clientIp, $allowedIps, true);
        $isTokenValid = !empty($expectedToken) && hash_equals(str_value($expectedToken), str_value($token));

        if (!$isIpAllowed && !$isTokenValid) {
            $this->response->setStatusCode(403);
            $this->response->setContent('Forbidden');
            return;
        }

        header('Content-Type: text/plain; charset=utf-8');

        // Total HTTP Requests
        $requestsCount = $this->metricsService->getRecentRequestsCount(5);
        
        echo "# HELP app_requests_total Total HTTP requests in last 5 min\n";
        echo "# TYPE app_requests_total counter\n";
        echo "app_requests_total " . $requestsCount . "\n";

        // Application Errors
        $errorCount = $this->metricsService->getRecentErrorsCount('error', 5);
        $criticalCount = $this->metricsService->getRecentErrorsCount('critical', 5);

        echo "# HELP app_errors_total Application errors\n";
        echo "# TYPE app_errors_total counter\n";
        echo "app_errors_total{level=\"error\"} " . $errorCount . "\n";
        echo "app_errors_total{level=\"critical\"} " . $criticalCount . "\n";

        // Active Users
        $activeUsers = $this->metricsService->getActiveUsersCount();
        
        echo "# HELP app_active_users Current active users\n";
        echo "# TYPE app_active_users gauge\n";
        echo "app_active_users " . $activeUsers . "\n";

        // DB Status
        echo "# HELP app_db_up Database availability\n";
        echo "# TYPE app_db_up gauge\n";

        if ($this->metricsService->isDatabaseUp()) {
            echo "app_db_up 1\n";
        } else {
            echo "app_db_up 0\n";
        }

        // 🛡️ FIX P1 (Redis Monitoring): metrics کامل Redis برای observability
        // شامل memory usage، evictions، connected clients، session keys
        $this->emitRedisMetrics();
    }

    /**
     * 🛡️ FIX P1: انتشار Prometheus metrics مربوط به Redis
     * در صورت عدم دسترسی به Redis، metricها با مقدار 0 یا unavailable منتشر می‌شوند
     * (Prometheus بهتر است metric را با مقدار 0 ببیند تا اصلاً نبیند — جلوگیری از false alert)
     */
    private function emitRedisMetrics(): void
    {
        $redisUp = 0;
        $memoryUsed = 0;
        $memoryMax = 0;
        $memoryPct = 0.0;
        $evictedKeys = 0;
        $connectedClients = 0;
        $sessionKeys = 0;
        $cacheDriver = 'file';

        try {
            $cache = \Core\Cache::getInstance();
            $cacheDriver = $cache->driver();
            $redis = $cache->redis();

            if ($redis && $cacheDriver === 'redis') {
                // PING test
                $redis->ping();
                $redisUp = 1;

                // Memory metrics
                $memory = $redis->info('memory');
                $memoryUsed = (int)($memory['used_memory'] ?? 0);
                $memoryMax = (int)($memory['maxmemory'] ?? 0);
                if ($memoryMax > 0) {
                    $memoryPct = round(($memoryUsed / $memoryMax) * 100, 2);
                }

                // Evictions (counter)
                $stats = $redis->info('stats');
                $evictedKeys = (int)($stats['evicted_keys'] ?? 0);

                // Connected clients
                $clients = $redis->info('clients');
                $connectedClients = (int)($clients['connected_clients'] ?? 0);

                // Session keys — count keys matching 'chortke:session:*' via SCAN (non-blocking)
                $sessionKeys = $this->countSessionKeys($redis);
            }
        } catch (\Throwable $e) {
            // Redis unavailable — metrics stay at 0 / fallback values
        }

        echo "# HELP redis_up Redis availability\n";
        echo "# TYPE redis_up gauge\n";
        echo "redis_up " . $redisUp . "\n";

        echo "# HELP redis_memory_used_bytes Memory used by Redis\n";
        echo "# TYPE redis_memory_used_bytes gauge\n";
        echo "redis_memory_used_bytes " . $memoryUsed . "\n";

        echo "# HELP redis_memory_max_bytes Configured maxmemory (0 = unlimited)\n";
        echo "# TYPE redis_memory_max_bytes gauge\n";
        echo "redis_memory_max_bytes " . $memoryMax . "\n";

        echo "# HELP redis_memory_usage_percent Memory usage as percentage of maxmemory\n";
        echo "# TYPE redis_memory_usage_percent gauge\n";
        echo "redis_memory_usage_percent " . $memoryPct . "\n";

        echo "# HELP redis_evicted_keys_total Total keys evicted by maxmemory policy since startup\n";
        echo "# TYPE redis_evicted_keys_total counter\n";
        echo "redis_evicted_keys_total " . $evictedKeys . "\n";

        echo "# HELP redis_connected_clients Number of connected clients\n";
        echo "# TYPE redis_connected_clients gauge\n";
        echo "redis_connected_clients " . $connectedClients . "\n";

        echo "# HELP redis_session_keys Approximate count of active session keys\n";
        echo "# TYPE redis_session_keys gauge\n";
        echo "redis_session_keys " . $sessionKeys . "\n";

        echo "# HELP redis_cache_driver Active cache driver (redis or file)\n";
        echo "# TYPE redis_cache_driver gauge\n";
        // 1 if Redis, 0 if file fallback (useful for distributed-system monitoring)
        echo "redis_cache_driver{driver=\"" . $cacheDriver . "\"} " . ($cacheDriver === 'redis' ? 1 : 0) . "\n";

        // 🛡️ Phase 2 (N+1 Tracking): metrics counters از Database::getTrackingStats()
        $this->emitQueryTrackingMetrics();
    }

    /**
     * 🛡️ Phase 2: انتشار Prometheus metrics مربوط به query tracking
     * کمک می‌کند monitoring کنیم که آیا Sentry tracking فعال است و چه مقدار overhead اضافه می‌کند
     */
    private function emitQueryTrackingMetrics(): void
    {
        try {
            $stats = \Core\Database::getTrackingStats();

            echo "# HELP db_query_tracking_enabled Is Sentry query tracking active in this process\n";
            echo "# TYPE db_query_tracking_enabled gauge\n";
            echo "db_query_tracking_enabled " . ($stats['enabled'] ? 1 : 0) . "\n";

            echo "# HELP db_query_tracking_sample_rate Configured sample rate (0.0 to 1.0)\n";
            echo "# TYPE db_query_tracking_sample_rate gauge\n";
            echo "db_query_tracking_sample_rate " . $stats['sample_rate'] . "\n";

            echo "# HELP db_queries_tracked_total Queries forwarded to Sentry (in this process)\n";
            echo "# TYPE db_queries_tracked_total counter\n";
            echo "db_queries_tracked_total " . $stats['tracked'] . "\n";

            echo "# HELP db_queries_skipped_total Queries skipped due to sample rate (in this process)\n";
            echo "# TYPE db_queries_skipped_total counter\n";
            echo "db_queries_skipped_total " . $stats['skipped'] . "\n";

            echo "# HELP db_query_tracking_errors_total Errors during Sentry forwarding (in this process)\n";
            echo "# TYPE db_query_tracking_errors_total counter\n";
            echo "db_query_tracking_errors_total " . $stats['errors'] . "\n";
        } catch (\Throwable $e) {
            // Silent — metrics endpoint must not fail
        }
    }

    /**
     * شمارش تقریبی session keys با SCAN (non-blocking)
     * در keyspace بزرگ، ممکن است cursorهای متعددی نیاز باشد
     */
    private function countSessionKeys(\Redis $redis): int
    {
        $count = 0;
        try {
            $iterator = null;
            $pattern = 'chortke:session:*';
            // محدود به ۱۰۰۰ کلید برای جلوگیری از overhead در metrics endpoint
            $maxIterations = 10;
            while ($maxIterations-- > 0) {
                $keys = $redis->scan($iterator, $pattern, 200);
                if ($keys === false || $keys === []) {
                    break;
                }
                $count += count($keys);
                if ($iterator === 0 || $iterator === '0') {
                    break;
                }
            }
        } catch (\Throwable $e) {
            // best-effort count
        }
        return $count;
    }

    /**
     * Comprehensive System Health Check
     * Validates infrastructure dependencies and returns JSON status
     */
    public function health(): void
    {
        // IP/Token auth
        $allowedIpsConfig = config('health.allowed_ips', ['127.0.0.1', '::1']);
        $allowedIps = is_array($allowedIpsConfig) ? $allowedIpsConfig : [];
        $clientIp = $this->request->ip();
        $token = $this->request->query('token', '');
        $expectedToken = config('health.check_token');

        $isIpAllowed = in_array($clientIp, $allowedIps, true);
        $isTokenValid = !empty($expectedToken) && hash_equals(str_value($expectedToken), str_value($token));

        if (!$isIpAllowed && !$isTokenValid) {
            http_response_code(403);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['status' => 'error', 'message' => 'Forbidden']);
            exit;
        }

        header('Content-Type: application/json; charset=utf-8');

        $health = [
            'status' => 'pass',
            'timestamp' => date('Y-m-d\TH:i:sP'),
            'checks' => [],
            'metrics' => []
        ];

        // 1. Database
        $dbStatus = $this->metricsService->isDatabaseUp() ? 'pass' : 'fail';
        $health['checks']['database'] = ['status' => $dbStatus];
        if ($dbStatus === 'fail') $health['status'] = 'fail';

        // 2. Redis
        $redisStatus = 'fail';
        try {
            $redis = $this->redis;
            if ($redis->isAvailable()) {
                $client = $redis->getClient();
                if ($client !== null) {
                    $client->ping();
                    $redisStatus = 'pass';
                }
            }
        } catch (\Throwable $e) {
            $health['checks']['redis']['error'] = $e->getMessage();
        }
        $health['checks']['redis']['status'] = $redisStatus;

        // 3. Queue Depth & DLQ
        $queueStatus = 'pass';
        try {
            $queue = $this->queue;
            $report = $queue->getQueueStatusReport();
            
            $totalPending = 0;
            $totalFailed = 0;
            foreach ($report as $stats) {
                if (!is_array($stats)) {
                    continue;
                }
                $totalPending += int_value($stats['pending'] ?? $stats['pending_jobs'] ?? 0);
                $totalFailed += int_value($stats['failed'] ?? $stats['failed_jobs'] ?? 0);
            }
            
            $health['metrics']['queue'] = [
                'total_pending' => $totalPending,
                'total_failed' => $totalFailed,
                'details' => $report
            ];

            // Alerting integration: if DLQ is accumulating too fast
            if ($totalFailed > 100) {
                $queueStatus = 'warn';
                $health['checks']['queue']['message'] = 'High number of jobs in Dead Letter Queue (DLQ)';
                // Integrates with Sentry / Logger
                $this->logger->critical('dlq.accumulation.alert', ['total_failed' => $totalFailed]);
            }
        } catch (\Throwable $e) {
            $queueStatus = 'fail';
            $health['checks']['queue']['error'] = $e->getMessage();
        }
        $health['checks']['queue']['status'] = $queueStatus;

        // 4. Disk Space Check
        $diskStatus = 'pass';
        try {
            $diskFree = disk_free_space('/');
            $diskTotal = disk_total_space('/');
            if ($diskFree !== false && $diskTotal !== false) {
                $freePercentage = ($diskFree / $diskTotal) * 100;
                $health['metrics']['disk'] = [
                    'free_bytes' => $diskFree,
                    'total_bytes' => $diskTotal,
                    'free_percentage' => round($freePercentage, 2)
                ];
                
                if ($freePercentage < 10) {
                    $diskStatus = 'warn';
                    $health['checks']['disk']['message'] = 'Low disk space (< 10%)';
                    $this->logger->critical('system.disk.low_space', ['free_percentage' => round($freePercentage, 2)]);
                }
            }
        } catch (\Throwable $e) {
            $this->logger->warning('metrics.operation_failed', ['error' => $e->getMessage()]);
        }
        $health['checks']['disk']['status'] = $diskStatus;

        // 5. External Services Health & Circuit Breakers
        try {
            $cb = $this->circuitBreaker;
            // Get states of registered services in circuit breaker
            $cbStates = [];
            foreach (['sms_gateway', 'fcm', 'payment_gateway', 'bank_api'] as $service) {
                if ($cb->isOpen($service)) {
                    $cbStates[$service] = 'OPEN (Failing)';
                    if ($health['status'] === 'pass') {
                        $health['status'] = 'warn'; // degrade overall health
                    }
                } else {
                    $cbStates[$service] = 'CLOSED (Healthy)';
                }
            }
            $health['checks']['circuit_breakers'] = [
                'status' => 'pass',
                'states' => $cbStates
            ];
        } catch (\Throwable $e) {
            $this->logger->warning('metrics.operation_failed', ['error' => $e->getMessage()]);
        }

        // Overall HTTP status code based on health
        if ($health['status'] === 'fail') {
            http_response_code(503);
        }

        echo json_encode($health, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        exit;
    }
}
