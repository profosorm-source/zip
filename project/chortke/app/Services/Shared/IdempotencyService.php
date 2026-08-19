<?php

declare(strict_types=1);

namespace App\Services\Shared;

use App\Contracts\LoggerInterface;
use App\Services\DistributedLockService;
use Core\Exceptions\TransientException;
use Core\IdempotencyKey;
use Core\TransactionWrapper;

/**
 * IdempotencyService — تنها نقطه ورود به سیستم Idempotency در لایه Application
 *
 * همه سرویس‌های مالی باید از این کلاس استفاده کنند نه مستقیماً از Core\IdempotencyKey.
 *
 * متدها:
 *   execute()               — ساده‌ترین حالت: فقط idempotency بدون lock
 *   executeWithTransaction() — idempotency + DB transaction با retry
 *   executeWithLock()        — idempotency + distributed lock + DB transaction (برای wallet/withdrawal)
 */
class IdempotencyService
{
    private IdempotencyKey $idempotencyKey;
    private TransactionWrapper $transactionWrapper;
    private LoggerInterface $logger;
    private ?DistributedLockService $lockService;

    public function __construct(
        IdempotencyKey $idempotencyKey,
        TransactionWrapper $transactionWrapper,
        LoggerInterface $logger,
        ?DistributedLockService $lockService = null
    ) {
        $this->idempotencyKey   = $idempotencyKey;
        $this->transactionWrapper = $transactionWrapper;
        $this->logger           = $logger;
        $this->lockService      = $lockService;
    }

    /**
     * ساده‌ترین حالت: فقط idempotency — بدون distributed lock یا transaction retry.
     *
     * @template T
     * @param string        $scope
     * @param int           $actorId
     * @param array<string, mixed> $payload       داده‌هایی که عمل را منحصربه‌فرد می‌کنند
     * @param callable(): T $callback
     * @param string|null   $explicitKey   در صورت ارسال، جایگزین payload-based key می‌شود
     * @return T
     */
    public function execute(
        string $scope,
        int $actorId,
        array $payload,
        callable $callback,
        ?string $explicitKey = null
    ): mixed {
        $key = $this->resolveKey($scope, $payload, $explicitKey);
        return $this->idempotencyKey->run($scope, $actorId, $key, $callback, $payload);
    }

    /**
     * idempotency + DB transaction با automatic retry برای deadlock.
     *
     * @template T
     * @param string        $scope
     * @param int           $actorId
     * @param array<string, mixed> $payload
     * @param callable(): T $callback
     * @param string|null   $explicitKey
     * @param int           $maxRetries
     * @return T
     */
    public function executeWithTransaction(
        string $scope,
        int $actorId,
        array $payload,
        callable $callback,
        ?string $explicitKey = null,
        int $maxRetries = 3
    ): mixed {
        $key = $this->resolveKey($scope, $payload, $explicitKey);

        return $this->transactionWrapper->runIdempotentWithRetry(
            $this->idempotencyKey,
            $scope . '_' . $key,
            $actorId,
            $scope,
            $callback,
            $maxRetries
        );
    }

    /**
     * کامل‌ترین حالت: idempotency + distributed lock + DB transaction با retry.
     *
     * برای عملیات حساس مالی (wallet deposit/withdraw، withdrawal request) که:
     *   - باید concurrent requests روی یک user_id بلاک شوند
     *   - باید deadlock retry داشته باشند
     *   - باید idempotent باشند
     *
     * @template T
     * @param string                 $scope
     * @param int                    $actorId
     * @param array<string, mixed> $payload
     * @param callable(): T          $callback
     * @param string|null            $explicitKey
     * @param string                 $lockResource   نام resource برای distributed lock (مثلاً "wallet:mut:42")
     * @param int                    $lockTtl        TTL قفل بر حسب ثانیه
     * @param int                    $lockWaitTimeout حداکثر انتظار برای قفل (ثانیه)
     * @param int                    $dbRetries
     * @return T
     * @throws TransientException    اگر قفل در زمان مشخص گرفته نشود
     */
    public function executeWithLock(
        string $scope,
        int $actorId,
        array $payload,
        callable $callback,
        ?string $explicitKey = null,
        string $lockResource = '',
        int $lockTtl = 15,
        int $lockWaitTimeout = 10,
        int $dbRetries = 3
    ): mixed {
        $key = $this->resolveKey($scope, $payload, $explicitKey);

        // 🛡️ Architectural Fix: Acquire distributed lock FIRST before opening DB transaction on idempotency_keys
        // This prevents lock contention & lock wait timeouts between DB transactions and Redis locks!
        $operation = function () use ($scope, $actorId, $key, $callback, $payload, $dbRetries) {
            return $this->idempotencyKey->run($scope, $actorId, $key, function () use ($callback, $dbRetries) {
                return $this->transactionWrapper->runWithRetry($callback, $dbRetries);
            }, $payload);
        };

        if ($lockResource === '' || $this->lockService === null) {
            return $operation();
        }

        try {
            return $this->lockService->synchronized($lockResource, $operation, $lockTtl, $lockWaitTimeout);
        } catch (\RuntimeException $lockError) {
            if (str_contains($lockError->getMessage(), 'Failed to acquire lock')) {
                // Lock backend در دسترس نیست → fallback به DB-only (بدون distributed lock)
                $this->logger->warning('idempotency.lock_unavailable_fallback', [
                    'scope'    => $scope,
                    'actor_id' => $actorId,
                    'resource' => $lockResource,
                    'error'    => $lockError->getMessage(),
                ]);
                return $operation();
            }
            throw new TransientException(
                'سیستم در حال حاضر شلوغ است، لطفاً لحظاتی بعد تلاش کنید',
                503,
                $lockError
            );
        }
    }

    // ─── Helper ──────────────────────────────────────────────────────────────

    /** @param array<string, mixed> $payload */
    private function resolveKey(string $scope, array $payload, ?string $explicitKey): string
    {
        if ($explicitKey !== null && $explicitKey !== '') {
            return $explicitKey;
        }
        return $this->idempotencyKey->keyFromPayload($scope, $payload);
    }

    // ─── Admin / Maintenance helpers ─────────────────────────────────────────

    /**
     * حذف کلیدهای منقضی‌شده از جدول idempotency_keys.
     *
     * @param bool $dryRun  اگر true باشد فقط تعداد را برمی‌گرداند بدون حذف
     * @return int          تعداد ردیف‌های حذف‌شده (یا قابل‌حذف در حالت dry-run)
     */
    public function cleanup(bool $dryRun = false): int
    {
        return $this->idempotencyKey->cleanup($dryRun);
    }

    /**
     * آمار کلیدهای ثبت‌شده به تفکیک وضعیت.
     */
    /** @return array<string, mixed> */
    public function getStats(): array
    {
        return $this->idempotencyKey->getStats();
    }

    // ─── Delegation helpers ───────────────────────────────────────────────────

    /** @param array<string, mixed> $context */
    public function generateFromPayload(string $action, array $context): string
    {
        return IdempotencyKey::generateFromPayload($action, $context);
    }

    /** @param array<string, mixed> $payload */
    public function keyFromPayload(string $scope, array $payload): string
    {
        return $this->idempotencyKey->keyFromPayload($scope, $payload);
    }
}
