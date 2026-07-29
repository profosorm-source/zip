<?php

declare(strict_types=1);

namespace Core;

/**
 * CircuitBreaker — Atomic Implementation
 *
 * State Machine: CLOSED → OPEN → HALF_OPEN → CLOSED
 *
 * Failure counter: Cache::increment() (Lua atomic — بدون race condition)
 * State: جداگانه ذخیره میشه (status + opened_at)
 *
 * کلیدهای Redis:
 *   circuit_breaker:{service}:failures  → int (atomic counter, TTL=timeout)
 *   circuit_breaker:{service}:status    → string: closed|open|half_open
 *   circuit_breaker:{service}:opened_at → int (timestamp)
 */
class CircuitBreaker implements \App\Contracts\CircuitBreakerInterface
{
    private Cache $cache;
    private int $failureThreshold = 5;
    private int $retryTimeoutSeconds = 60;
    private Logger $logger;
    private EventDispatcher $eventDispatcher;
    private array $config;

    public function __construct(Cache $cache, Logger $logger, EventDispatcher $eventDispatcher) {
        $this->cache = $cache;
        $this->logger = $logger;
        $this->eventDispatcher = $eventDispatcher;
        $this->config = is_array(config('circuit_breaker', [])) ? config('circuit_breaker', []) : [];
        $this->failureThreshold = (int)($this->config['failure_threshold'] ?? 5);
        $this->retryTimeoutSeconds = (int)($this->config['retry_timeout_seconds'] ?? 60);
    }

    private function getThreshold(string $serviceName): int
    {
        return (int)($this->config[$serviceName]['threshold'] ?? $this->failureThreshold);
    }

    private function getTimeout(string $serviceName): int
    {
        return (int)($this->config[$serviceName]['timeout'] ?? $this->retryTimeoutSeconds);
    }

    public function call(string $serviceName, callable $operation, ?callable $fallback = null)
    {
        $timeout = $this->getTimeout($serviceName);
        $threshold = $this->getThreshold($serviceName);

        $status = $this->getStatus($serviceName);

        // ── OPEN: reject fast ──
        if ($status === 'open') {
            $openedAt = (int)$this->cache->get($this->key($serviceName, 'opened_at'), 0);

            if (time() - $openedAt < $timeout) {
                $this->logger->warning('circuit_breaker.open', ['service' => $serviceName]);

                if ($fallback !== null) {
                    return $fallback(new \Core\Exceptions\CircuitBreakerOpenException($serviceName));
                }

                throw new \Core\Exceptions\CircuitBreakerOpenException($serviceName);
            }

            // Timeout expired → transition to HALF_OPEN
            $this->setStatus($serviceName, 'half_open', $timeout);
            $status = 'half_open';
        }

        // ── CLOSED or HALF_OPEN: attempt operation ──
        try {
            $result = $operation();

            // Success → reset to CLOSED
            if ($status !== 'closed') {
                $this->eventDispatcher->dispatch('circuit_breaker.closed', ['service' => $serviceName]);
            }

            $this->reset($serviceName);

            return $result;
        } catch (\Throwable $e) {
            $this->recordFailure($serviceName, $threshold, $timeout, $status, $e);

            if ($fallback !== null) {
                return $fallback($e);
            }

            throw $e;
        }
    }

    /**
     * Atomic failure recording — Cache::increment (Lua script)
     */
    private function recordFailure(string $serviceName, int $threshold, int $timeout, string $currentStatus, \Throwable $e): void
    {
        $failureKey = $this->key($serviceName, 'failures');

        // Atomic increment — Lua script داخل Cache::increment
        $failures = $this->cache->increment($failureKey, 1, $timeout);

        if ($failures === false) {
            // Cache unavailable — fail-open (اجازه بده ادامه بده)
            $this->logger->warning('circuit_breaker.counter_failed', ['service' => $serviceName]);
            return;
        }

        $this->logger->error('circuit_breaker.failure', [
            'service' => $serviceName,
            'failures' => $failures,
            'threshold' => $threshold,
            'status' => $currentStatus,
            'error' => $e->getMessage(),
        ]);

        // Trip to OPEN if threshold reached
        if ($failures >= $threshold && $currentStatus !== 'open') {
            $this->setStatus($serviceName, 'open', $timeout);
            $this->cache->putSeconds($this->key($serviceName, 'opened_at'), time(), $timeout * 2);

            $this->logger->critical('circuit_breaker.tripped_open', [
                'service' => $serviceName,
                'failures' => $failures,
                'threshold' => $threshold,
            ]);

            $this->eventDispatcher->dispatch('circuit_breaker.opened', [
                'service' => $serviceName,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Reset circuit to CLOSED state
     */
    private function reset(string $serviceName): void
    {
        $this->setStatus($serviceName, 'closed', 3600);
        $this->cache->forget($this->key($serviceName, 'failures'));
        $this->cache->forget($this->key($serviceName, 'opened_at'));
    }

    /**
     * Get current circuit status
     */
    private function getStatus(string $serviceName): string
    {
        return (string)($this->cache->get($this->key($serviceName, 'status'), 'closed'));
    }

    /**
     * Set circuit status
     */
    private function setStatus(string $serviceName, string $status, int $ttlSeconds): void
    {
        $this->cache->putSeconds($this->key($serviceName, 'status'), $status, $ttlSeconds * 2);
    }

    /**
     * Build cache key
     */
    private function key(string $serviceName, string $suffix): string
    {
        return "circuit_breaker:{$serviceName}:{$suffix}";
    }

    public function isOpen(string $serviceName): bool
    {
        return $this->getStatus($serviceName) === 'open';
    }

    public function isAvailable(string $serviceName): bool
    {
        return !$this->isOpen($serviceName);
    }

    public function reportSuccess(string $serviceName): void
    {
        $this->reset($serviceName);
    }

    public function reportFailure(string $serviceName): void
    {
        $threshold = $this->getThreshold($serviceName);
        $timeout = $this->getTimeout($serviceName);
        $status = $this->getStatus($serviceName);
        $this->recordFailure($serviceName, $threshold, $timeout, $status, new \RuntimeException('Reported failure'));
    }
}
