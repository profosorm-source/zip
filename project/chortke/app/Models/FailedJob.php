<?php

declare(strict_types=1);

namespace App\Models;

use Core\Model;

/**
 * FailedJob Model — Dead Letter Queue
 * 
 * جدول: failed_jobs
 */
class FailedJob extends Model
{
    protected static string $table = 'failed_jobs';

    /**
     * Fetch recent failed jobs.
     */
    /** @return list<\stdClass> */
    public function fetchRecent(int $limit = 50): array
    {
        return $this->db->fetchAll(
            "SELECT * FROM failed_jobs ORDER BY failed_at DESC LIMIT ?",
            [$limit]
        ) ?: [];
    }

    /**
     * Fetch failed jobs for a specific queue.
     */
    /** @return list<\stdClass> */
    public function fetchByQueue(string $queue, int $limit = 50): array
    {
        return $this->db->fetchAll(
            "SELECT * FROM failed_jobs WHERE queue = ? ORDER BY failed_at DESC LIMIT ?",
            [$queue, $limit]
        ) ?: [];
    }

    /**
     * Re-queue a failed job and remove from failed_jobs.
     */
    public function retry(int $id): bool
    {
        $job = $this->find($id);
        if (!$job) return false;

        $this->db->execute(
            "INSERT INTO jobs (queue, payload, attempts, reserved_at, available_at, created_at)
             VALUES (?, ?, 0, NULL, UNIX_TIMESTAMP(), UNIX_TIMESTAMP())",
            [is_string($job->queue ?? null) ? $job->queue : 'default', is_string($job->payload ?? null) ? $job->payload : '{}']
        );

        $this->db->execute("DELETE FROM failed_jobs WHERE id = ?", [$id]);
        return true;
    }

    /**
     * Forget (delete) a failed job without retrying.
     */
    public function forget(int $id): bool
    {
        return (bool) $this->db->execute("DELETE FROM failed_jobs WHERE id = ?", [$id]);
    }

    /**
     * Purge failed jobs older than N days.
     */
    public function purge(int $days = 30): int
    {
        return (int) $this->db->execute(
            "DELETE FROM failed_jobs WHERE failed_at < DATE_SUB(NOW(), INTERVAL ? DAY)",
            [$days]
        );
    }

    /**
     * Count total failed jobs.
     */
    public function count(): int
    {
        return (int) $this->db->fetchColumn("SELECT COUNT(*) FROM failed_jobs");
    }
}
