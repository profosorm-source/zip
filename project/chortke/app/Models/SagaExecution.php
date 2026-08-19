<?php

declare(strict_types=1);

namespace App\Models;

use Core\Model;

/**
 * SagaExecution Model
 * 
 * جدول: saga_executions
 */
class SagaExecution extends Model
{
    protected static string $table = 'saga_executions';

    public const STATUS_RUNNING   = 'running';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED    = 'failed';

    /**
     * Create a new saga execution record.
     */
    /** @param array<string, mixed> $payload */
    public function createSaga(string $id, string $sagaName, array $payload = []): bool
    {
        return (bool) $this->db->execute(
            "INSERT INTO saga_executions (id, saga_name, status, payload, executed_steps, created_at, updated_at)
             VALUES (?, ?, ?, ?, '[]', NOW(), NOW())",
            [$id, $sagaName, self::STATUS_RUNNING, json_encode($payload, JSON_UNESCAPED_UNICODE)]
        );
    }

    /**
     * Update executed steps.
     */
    /** @param array<string, mixed> $steps */
    public function updateSteps(string $id, array $steps): bool
    {
        return (bool) $this->db->execute(
            "UPDATE saga_executions SET executed_steps = ?, updated_at = NOW() WHERE id = ?",
            [json_encode($steps, JSON_UNESCAPED_UNICODE), $id]
        );
    }

    /**
     * Mark saga as completed.
     */
    public function markCompleted(string $id): bool
    {
        return (bool) $this->db->execute(
            "UPDATE saga_executions SET status = ?, updated_at = NOW() WHERE id = ?",
            [self::STATUS_COMPLETED, $id]
        );
    }

    /**
     * Mark saga as failed.
     */
    public function markFailed(string $id): bool
    {
        return (bool) $this->db->execute(
            "UPDATE saga_executions SET status = ?, updated_at = NOW() WHERE id = ?",
            [self::STATUS_FAILED, $id]
        );
    }

    /**
     * Get running sagas count.
     */
    public function countRunning(): int
    {
        return (int) $this->db->fetchColumn(
            "SELECT COUNT(*) FROM saga_executions WHERE status = ?",
            [self::STATUS_RUNNING]
        );
    }

    /**
     * Get failed sagas count.
     */
    public function countFailed(): int
    {
        return (int) $this->db->fetchColumn(
            "SELECT COUNT(*) FROM saga_executions WHERE status = ?",
            [self::STATUS_FAILED]
        );
    }
}
