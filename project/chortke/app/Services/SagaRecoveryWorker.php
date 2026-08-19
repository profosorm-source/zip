<?php

declare(strict_types=1);

namespace App\Services;

use Core\Database;
use App\Contracts\LoggerInterface;
use Core\Container;

class SagaRecoveryWorker
{


    private Database $db;
    private LoggerInterface $logger;
    private Container $container;

    public function __construct(Database $db, LoggerInterface $logger, Container $container) {
        $this->db = $db;
        $this->logger = $logger;
        $this->container = $container;
    }

    /**
     * اجرای ریکاوری برای تراکنش‌های گیر کرده (Stalled Sagas)
     */
    public function run(int $stalledMinutes = 5, int $limit = 10, int $leaseMinutes = 15): int
    {
        // Root fix (H-04): the previous implementation issued `SELECT ... FOR UPDATE`
        // OUTSIDE of any transaction. Under autocommit the row lock is released the
        // instant the statement returns, so it provided NO real protection: two
        // recovery workers could select the same stalled saga and both run
        // compensation, causing duplicate compensation / double refunds.
        //
        // The reliable fence is an atomic compare-and-swap on `status` (a single
        // conditional UPDATE). Only the worker whose UPDATE affects exactly one row
        // has claimed the saga and may compensate it. Sagas left in the intermediate
        // `recovering` state by a crashed worker are reclaimed once their lease has
        // expired (updated_at older than $leaseMinutes).
        $stmt = $this->db->prepare(
            "SELECT * FROM saga_executions
             WHERE (status = 'started' AND updated_at < DATE_SUB(NOW(), INTERVAL ? MINUTE))
                OR (status = 'recovering' AND updated_at < DATE_SUB(NOW(), INTERVAL ? MINUTE))
             ORDER BY updated_at ASC LIMIT ?"
        );
        $stmt->execute([$stalledMinutes, $leaseMinutes, $limit]);
        $candidates = $stmt->fetchAll(\PDO::FETCH_OBJ);

        if (empty($candidates)) {
            return 0;
        }

        $recoveredCount = 0;

        foreach ($candidates as $saga) {
            $observedStatus = (string)$saga->status;

            // Atomic claim: transition to `recovering` only if the row is still in the
            // exact state we observed. A losing worker sees 0 affected rows and skips.
            if ($observedStatus === 'started') {
                $claimed = $this->db->execute(
                    "UPDATE saga_executions SET status = 'recovering', updated_at = NOW()
                     WHERE id = ? AND status = 'started'",
                    [$saga->id]
                );
            } else {
                $claimed = $this->db->execute(
                    "UPDATE saga_executions SET status = 'recovering', updated_at = NOW()
                     WHERE id = ? AND status = 'recovering'
                       AND updated_at < DATE_SUB(NOW(), INTERVAL ? MINUTE)",
                    [$saga->id, $leaseMinutes]
                );
            }

            if ($claimed !== 1) {
                // Another worker already claimed/renewed this saga: skip to avoid
                // concurrent (duplicate) compensation.
                $this->logger->info("saga.recovery.claim_skipped", [
                    'saga_id' => $saga->id,
                    'observed_status' => $observedStatus,
                ]);
                continue;
            }

            // Fencing token: the `updated_at` value written by our claim. Every further
            // write is guarded by it, so if another worker reclaims the lease our stale
            // writes affect 0 rows and we abort instead of double-compensating.
            $fence = $this->currentFence((string)$saga->id);

            $this->logger->warning("saga.recovery.starting", ['saga_id' => $saga->id, 'name' => $saga->saga_name]);

            try {
                $this->recoverSaga($saga, $fence);
                $recoveredCount++;
            } catch (\Throwable $e) {
                $this->logger->critical("saga.recovery.failed", [
                    'saga_id' => $saga->id,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString()
                ]);
            }
        }

        return $recoveredCount;
    }

    /**
     * Read the current fencing token (updated_at) for a claimed saga.
     */
    private function currentFence(string $sagaId): string
    {
        $stmt = $this->db->prepare("SELECT updated_at FROM saga_executions WHERE id = ?");
        $stmt->execute([$sagaId]);
        $value = $stmt->fetchColumn();
        return (string)($value === false ? '' : ($value ?? ''));
    }

    /**
     * Persist step progress guarded by the fencing token. Returns the refreshed fence.
     * Throws if the lease was lost (another worker took over), aborting this run so the
     * same step is never compensated twice.
     */
    /** @param list<array<string, mixed>> $executedSteps */
    private function persistProgress(string $sagaId, array $executedSteps, string $fence): string
    {
        $affected = $this->db->execute(
            "UPDATE saga_executions SET executed_steps = ?, updated_at = NOW()
             WHERE id = ? AND status = 'recovering' AND updated_at = ?",
            [json_encode($executedSteps, JSON_UNESCAPED_UNICODE), $sagaId, $fence]
        );

        if ($affected !== 1) {
            throw new \RuntimeException("saga.recovery.lease_lost: another worker holds saga {$sagaId}");
        }

        return $this->currentFence($sagaId);
    }

    /**
     * Finalize the saga status guarded by the fencing token.
     */
    private function finalize(string $sagaId, string $status, string $fence): void
    {
        $affected = $this->db->execute(
            "UPDATE saga_executions SET status = ?, updated_at = NOW()
             WHERE id = ? AND status = 'recovering' AND updated_at = ?",
            [$status, $sagaId, $fence]
        );

        if ($affected !== 1) {
            $this->logger->warning("saga.recovery.finalize_skipped", [
                'saga_id' => $sagaId,
                'intended_status' => $status,
            ]);
        }
    }

    private function recoverSaga(\stdClass $sagaData, string $fence): void
    {
        $payloadDecoded = json_decode(str_value($sagaData->payload ?? ''), true, 512, JSON_THROW_ON_ERROR);
        $stepsDecoded = json_decode(str_value($sagaData->executed_steps ?? ''), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($payloadDecoded) || !is_array($stepsDecoded)) {
            throw new \UnexpectedValueException('Persisted saga payload and steps must be JSON arrays.');
        }
        $payload = $payloadDecoded;
        $executedSteps = [];
        foreach ($stepsDecoded as $step) {
            if (!is_array($step)) {
                throw new \UnexpectedValueException('Every persisted saga step must be an object.');
            }
            $executedSteps[] = $step;
        }

        if (empty($executedSteps)) {
            // هیچ قدمی اجرا نشده، پس چیزی برای جبران نیست. وضعیت را آپدیت می‌کنیم.
            $this->finalize((string)$sagaData->id, 'compensated', $fence);
            return;
        }

        // مراحل را به ترتیب برعکس اجرا می‌کنیم (LIFO)
        // preserve_keys=true so we can mark the original step index as compensated.
        $reversedSteps = array_reverse($executedSteps, true);
        $compensationSuccess = true;

        $originalError = new \RuntimeException("Saga stalled and recovered by worker.");

        foreach ($reversedSteps as $index => $step) {
            // Per-step idempotency: never re-compensate a step that a previous (possibly
            // crashed) recovery run already marked as compensated.
            if (!empty($step['compensated'])) {
                continue;
            }

            if (($step['type'] ?? null) === 'class' && !empty($step['class'])) {
                $className = $step['class'];
                if (!is_string($className)) {
                    throw new \UnexpectedValueException('Saga compensation class must be a non-empty string.');
                }
                if (!class_exists($className)) {
                    $this->logger->error("saga.recovery.class_not_found", ['class' => $className]);
                    $compensationSuccess = false;
                    continue;
                }

                try {
                    // گرفتن نمونه کلاس از Container (برای پشتیبانی از Dependency Injection)
                    /** @var \App\Contracts\SagaCompensatableStep $stepInstance */
                    $stepInstance = $this->container->make($className);
                    $stepInstance->compensate($payload, $step['result'] ?? null, $originalError);

                    // Persist progress under the fence BEFORE moving on, so a crash cannot
                    // cause this step to be compensated twice on the next recovery run.
                    $executedSteps[$index]['compensated'] = true;
                    $fence = $this->persistProgress((string)$sagaData->id, $executedSteps, $fence);

                    $this->logger->info("saga.recovery.compensated_step", ['saga_id' => $sagaData->id, 'step' => $step['name'] ?? $index]);
                } catch (\Throwable $e) {
                    $compensationSuccess = false;
                    $this->logger->critical("saga.recovery.compensation_error", [
                        'saga_id' => $sagaData->id,
                        'step' => $step['name'] ?? $index,
                        'error' => $e->getMessage()
                    ]);
                }
            } else {
                // اگر Closure بوده، امکان ریکاور از روی دیتابیس وجود ندارد!
                $this->logger->error("saga.recovery.cannot_recover_closure", [
                    'saga_id' => $sagaData->id, 
                    'step' => $step['name'] ?? $index
                ]);
                $compensationSuccess = false;
            }
        }

        $newStatus = $compensationSuccess ? 'compensated' : 'failed_compensation';
        $this->finalize((string)$sagaData->id, $newStatus, $fence);

        $this->logger->info("saga.recovery.finished", ['saga_id' => $sagaData->id, 'final_status' => $newStatus]);
    }
}

