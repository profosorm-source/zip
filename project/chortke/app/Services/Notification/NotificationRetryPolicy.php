<?php

declare(strict_types=1);

namespace App\Services\Notification;

use App\Contracts\LoggerInterface;
use Core\Cache;
use Core\CircuitBreaker;

/**
 * Channel-specific retry policy + circuit breaker for notification gateways.
 *
 * Section 8.3 — The actual circuit-breaker is delegated to Core\CircuitBreaker
 * via constructor injection (single source of truth). The legacy cache-based
 * tripping kept here as a *secondary* defense for hot-path attempt throttling
 * (e.g. "don't even try 3 attempts if 5 consecutive failures happened in 60s").
 */
class NotificationRetryPolicy
{
    /** @var array<string, array<string, int>> */
    private array $policies = [
        'fcm' => ['attempts' => 3, 'sleep_ms' => 200, 'circuit_failures' => 5, 'circuit_seconds' => 60],
        'push' => ['attempts' => 2, 'sleep_ms' => 200, 'circuit_failures' => 5, 'circuit_seconds' => 60],
        'sms' => ['attempts' => 2, 'sleep_ms' => 500, 'circuit_failures' => 3, 'circuit_seconds' => 120],
        'email' => ['attempts' => 3, 'sleep_ms' => 1000, 'circuit_failures' => 5, 'circuit_seconds' => 120],
        'log' => ['attempts' => 1, 'sleep_ms' => 0, 'circuit_failures' => 1000, 'circuit_seconds' => 1],
    ];

    private Cache $cache;
    private LoggerInterface $logger;
    private ?CircuitBreaker $circuit;
    public function __construct(
        Cache $cache,
        LoggerInterface $logger,
        ?CircuitBreaker $circuit = null
    ) {        $this->cache = $cache;
        $this->logger = $logger;
        $this->circuit = $circuit;
}

    public function execute(string $channel, callable $operation): bool
    {
        $channel = strtolower(trim((string)$channel));
        $policy = $this->policies[$channel] ?? ['attempts' => 1, 'sleep_ms' => 0, 'circuit_failures' => 5, 'circuit_seconds' => 60];

        if ($this->isCircuitOpen($channel)) {
            $this->logger->warning('notif.circuit_open_skip', ['channel' => $channel]);
            return false;
        }

        // Wrap the entire retry loop in Core\CircuitBreaker when available.
        if ($this->circuit !== null) {
            try {
                return (bool) $this->circuit->call('notif_' . $channel, function () use ($channel, $policy, $operation): bool {
                    return $this->executeRetryLoop($channel, $policy, $operation);
                });
            } catch (\RuntimeException $e) {
                \App\Services\Sentry\SentryExceptionHandler::captureException($e, null, ['operation' => 'app.Services.Notification.NotificationRetryPolicy.execute']);
                // CB is open → propagate same false semantics as legacy path.
                $this->logger->warning('notif.core_cb_open', [
                    'channel' => $channel,
                    'error' => $e->getMessage(),
                ]);
                return false;
            }
        }

        return $this->executeRetryLoop($channel, $policy, $operation);
    }

    /**
     * @param array<string, mixed> $policy
     */
    private function executeRetryLoop(string $channel, array $policy, callable $operation): bool
    {

        $last = null;
        for ($attempt = 1; $attempt <= int_value($policy['attempts']); $attempt++) {
            try {
                $result = (bool) $operation();
                if ($result) {
                    $this->resetFailures($channel);
                    return true;
                }

                $last = new \RuntimeException('Notification channel returned false.');
            } catch (\Throwable $e) {
                \App\Services\Sentry\SentryExceptionHandler::captureException($e, null, ['operation' => 'app.Services.Notification.NotificationRetryPolicy.executeRetryLoop']);
                $last = $e;
            }

            $this->logger->warning('notif.dispatch_attempt_failed', [
                'channel' => $channel,
                'attempt' => $attempt,
                'error' => $last->getMessage(),
            ]);

            if ($attempt < int_value($policy['attempts']) && int_value($policy['sleep_ms']) > 0) {
                usleep(int_value($policy['sleep_ms']) * 1000);
            }
        }

        $this->recordFailure($channel, int_value($policy['circuit_failures']), int_value($policy['circuit_seconds']));
        return false;
    }

    private function isCircuitOpen(string $channel): bool
    {
        return (bool) $this->cache->get("notif_circuit_open:{$channel}", false);
    }

    private function recordFailure(string $channel, int $threshold, int $openSeconds): void
    {
        try {
            $count = $this->cache->increment("notif_failures:{$channel}", 1, max(60, $openSeconds));
            if ($count !== false && (int) $count >= $threshold) {
                $this->cache->setSeconds("notif_circuit_open:{$channel}", true, $openSeconds);
                $this->logger->error('notif.circuit_opened', [
                    'channel' => $channel,
                    'failures' => (int) $count,
                    'seconds' => $openSeconds,
                ]);
            }
        } catch (\Throwable $e) {
            \App\Services\Sentry\SentryExceptionHandler::captureException($e, null, ['operation' => 'app.Services.Notification.NotificationRetryPolicy.recordFailure']);
            $this->logger->warning('notif.circuit_record_failed', [
                'channel' => $channel,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function resetFailures(string $channel): void
    {
        $this->cache->forget("notif_failures:{$channel}");
        $this->cache->forget("notif_circuit_open:{$channel}");
    }
}
