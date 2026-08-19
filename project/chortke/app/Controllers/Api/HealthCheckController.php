<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Controllers\BaseController;
use App\Services\Health\HealthCheckService;

class HealthCheckController extends BaseApiController
{
    private HealthCheckService $healthService;
    private \Core\Cache $cache;

    public function __construct(HealthCheckService $healthService, \Core\Cache $cache, ?\App\Contracts\LoggerInterface $logger = null) {
        parent::__construct(null, null, null, null, $logger);
        $this->healthService = $healthService;
        $this->cache = $cache;
    }

    /** @param array<string, mixed> $data */
    protected function jsonResponse(array $data, int $statusCode = 200): void
    {
        $this->response->json($data, $statusCode);
    }

    /**
     * Liveness Probe
     */
    public function live(): void
    {
        $this->validateAccess();

        try {
            $result = $this->healthService->checkLiveness();
        } catch (\Throwable $e) {
            $this->jsonResponse([
                'status' => 'degraded',
                'message' => 'Liveness check degraded: ' . $e->getMessage(),
                'timestamp' => date('c'),
            ], 200);
            return;
        }

        $statusCode = $result['status'] === 'error' ? 503 : 200;

        $this->response->setHeader('Cache-Control', 'no-store, no-cache, must-revalidate');
        $this->response->setHeader('Pragma', 'no-cache');
        $this->jsonResponse($result, $statusCode);
    }

    /**
     * Readiness Probe
     */
    public function ready(): void
    {
        $this->validateAccess();

        $ttl = int_value(config('health.ready_cache_ttl', 5));
        $cacheKey = 'health:ready:snapshot';

        $result = null;
        try {
            $cache = $this->cache;
            if ($ttl > 0) {
                $cached = $cache->get($cacheKey);
                if (is_array($cached) && isset($cached['_cached_at']) && (time() - (int)$cached['_cached_at']) < $ttl) {
                    $result = $cached;
                    $result['_from_cache'] = true;
                }
            }
        } catch (\Throwable $cacheErr) {
            $this->logger->warning('health.cache_unavailable', ['error' => $cacheErr->getMessage()]);
        }

        if ($result === null) {
            $result = $this->healthService->checkReadiness();
            $result['_cached_at'] = time();
            $result['_from_cache'] = false;
            if ($ttl > 0) {
                try { $cache->put($cacheKey, $result, max(1, (int)ceil($ttl / 60))); } catch (\Throwable $e) {
            $this->logger->warning('healthcheckcontroller.operation_failed', ['error' => $e->getMessage()]);
        }
            }
        }

        $degradedIsReady = (bool) config('health.degraded_is_ready', false);
        $isError    = $result['status'] === 'error';
        $isDegraded = $result['status'] === 'degraded';

        if ($isError || ($isDegraded && !$degradedIsReady)) {
            $statusCode = 503;
        } else {
            $statusCode = 200;
        }

        $this->response->setHeader('Cache-Control', 'no-store, no-cache, must-revalidate');
        $this->response->setHeader('Pragma', 'no-cache');
        $this->jsonResponse($result, $statusCode);
    }

    private function validateAccess(): void
    {
        $allowedIpsConfig = config('health.allowed_ips', ['127.0.0.1', '::1']);
        $allowedIps = is_array($allowedIpsConfig) ? array_filter($allowedIpsConfig) : ['127.0.0.1', '::1'];
        $token = str_value(config('health.check_token', ''));
        
        $clientIp = $this->request->ip();
        // 🛡️ Security Fix (Issue #8): Token MUST be passed in HTTP Headers only, NEVER in query parameters
        $requestToken = str_value($this->request->header('X-Health-Token', $this->request->header('X-Monitoring-Token', '')));
        if ($requestToken === '' && str_starts_with(str_value($this->request->header('Authorization', '')), 'Bearer ')) {
            $requestToken = substr(str_value($this->request->header('Authorization', '')), 7);
        }
        
        // 🛡️ Security Fix (Issue #7): Fail-closed - an empty allowlist does NOT grant public access!
        $isIpAllowed = !empty($allowedIps) && in_array($clientIp, $allowedIps, true);
        $isTokenValid = !empty($token) && $requestToken !== '' && hash_equals($token, $requestToken);
        
        if (!$isIpAllowed && !$isTokenValid) {
            $this->jsonResponse(['status' => 'error', 'message' => 'Unauthorized Access'], 403);
            exit;
        }
    }

    /**
     * Distributed Systems Health (Outbox, DLQ, Saga, Idempotency) - Option 3 consolidated.
     * Route: GET /health/distributed
     */
    public function distributed(): void
    {
        $this->validateAccess();

        $result = [
            'status' => 'ok',
            'service' => 'chortke-distributed-health',
            'timestamp' => date('c'),
            'checks' => [
                'outbox' => $this->healthService->probeOutbox(),
                'dlq' => $this->healthService->probeDLQ(),
                'saga' => $this->healthService->probeSaga(),
                'idempotency' => $this->healthService->probeIdempotency(),
            ]
        ];

        $hasError = false;
        foreach ($result['checks'] as $check) {
            if (($check['status'] ?? 'ok') === 'error') {
                $hasError = true;
                break;
            }
        }
        if ($hasError) {
            $result['status'] = 'error';
        }

        $this->response->setHeader('Cache-Control', 'no-store, no-cache, must-revalidate');
        $this->jsonResponse($result, $hasError ? 503 : 200);
    }

    /**
     * Distributed Metrics (JSON + Prometheus).
     * Route: GET /metrics/distributed
     */
    public function metrics(): void
    {
        // M-34: metrics reveal queue/cache/DB internals and must use the same
        // IP/token gate as live/ready/distributed health endpoints.
        $this->validateAccess();

        $metrics = $this->healthService->collectDistributedMetrics();

        $accept = str_value($this->request->header('accept'));
        if (strpos($accept, 'text/plain') !== false || strpos($accept, 'prometheus') !== false) {
            header('Content-Type: text/plain; version=0.0.4; charset=utf-8');
            echo $this->formatPrometheus($metrics);
            return;
        }

        $this->jsonResponse([
            'service' => 'chortke-distributed-metrics',
            'timestamp' => date('c'),
            'metrics' => $metrics,
        ]);
    }

    /** @param array<string, mixed> $metrics */
    private function formatPrometheus(array $metrics): string
    {
        $out = [];
        foreach ((array)$metrics as $name => $value) {
            $cleanName = 'chortke_' . preg_replace('/[^a-zA-Z0-9_]/', '_', $name);
            $out[] = "# HELP {$cleanName} {$name}";
            $out[] = "# TYPE {$cleanName} gauge";
            $out[] = "{$cleanName} " . str_value($value);
        }
        return implode("\n", $out) . "\n";
    }
}
