<?php

declare(strict_types=1);

namespace App\Models;

use Core\Model;
use Core\Database;

/**
 * AdTubeExecutionModel — Execution records for AdTube (video watch) ads.
 * Separate from social_task_executions due to domain-specific metrics (watch_time, progress).
 */
class AdTubeExecutionModel extends Model
{
    protected static string $table = 'adtube_views';

    public function __construct(Database $db) {
        parent::__construct($db);
    }

    /**
     * Find or create a pending execution for user+ad.
     * Returns the existing execution if one is already pending/watching.
     */
    /** @param array<string, mixed> $context */
    public function findOrCreate(int $adId, int $executorId, array $context = []): ?\stdClass
    {
        try {
            $this->db->beginTransaction();
            // اصلاح کلیدی معماری همزمانی در پخش ویدیو (AdTube Mobile Concurrency Lock):
            // قفل‌گذاری اتمیک ردیف پخش جهت جلوگیری از ایجاد رکوردهای تکراری در اثر بازنشانی سوکت‌های پخش ویدیو در موبایل
            $existing = $this->db->fetch(
                "SELECT * FROM adtube_views WHERE ad_id = ? AND executor_id = ? AND status IN ('pending','watching') LIMIT 1 FOR UPDATE",
                [$adId, $executorId]
            );
            if ($existing) {
                $this->db->commit();
                return $existing;
            }

            $this->db->query(
                "INSERT INTO adtube_views (ad_id, executor_id, status, ip_address, user_agent, created_at, updated_at)
                 VALUES (?, ?, 'pending', ?, ?, NOW(), NOW())",
                [
                    $adId, $executorId,
                    $context['ip'] ?? (get_client_ip()),
                    $context['user_agent'] ?? (get_user_agent())
                ]
            );
            $newRecord = $this->db->fetch("SELECT * FROM adtube_views WHERE id = ?", [(int)$this->db->lastInsertId()]);
            $this->db->commit();
            return $newRecord;
        } catch (\Throwable $e) {
            $this->db->rollback();
            throw $e;
        }
    }

    public function findById(int $id): ?\stdClass
    {
        return $this->db->fetch("SELECT * FROM adtube_views WHERE id = ?", [$id]);
    }

    public function findByIdWithAd(int $id, int $executorId): ?\stdClass
    {
        return $this->db->fetch(
            "SELECT av.*, a.title as ad_title, a.description as ad_description, a.link as video_link,
                    a.price_per_task as reward_amount, a.currency as reward_currency, a.total_count, a.completed_count
             FROM adtube_views av
             JOIN ads a ON a.id = av.ad_id
             WHERE av.id = ? AND av.executor_id = ?
             LIMIT 1",
            [$id, $executorId]
        );
    }

    /**
     * Start watching (transition from pending to watching).
     */
    public function startWatching(int $executionId): bool
    {
        $stmt = $this->db->query(
            "UPDATE adtube_views SET status = 'watching', started_at = NOW(), updated_at = NOW()
             WHERE id = ? AND status = 'pending'",
            [$executionId]
        );
        return $stmt->rowCount() > 0;
    }

    /**
     * Submit completed watch with metrics.
     */
    public function submitCompleted(int $executionId, int $watchTime, int $progressPercent, float $playbackSpeed): bool
    {
        $stmt = $this->db->query(
            "UPDATE adtube_views
             SET status = 'completed', watch_time = ?, progress_percent = ?, playback_speed = ?,
                 completed_at = NOW(), updated_at = NOW()
             WHERE id = ? AND status IN ('watching','pending')",
            [$watchTime, $progressPercent, $playbackSpeed, $executionId]
        );
        return $stmt->rowCount() > 0;
    }

    /**
     * Mark as rejected (fraud or insufficient watch).
     */
    public function markRejected(int $executionId, string $reason): bool
    {
        $stmt = $this->db->query(
            "UPDATE adtube_views SET status = 'rejected', reject_reason = ?, updated_at = NOW() WHERE id = ?",
            [$reason, $executionId]
        );
        return $stmt->rowCount() > 0;
    }

    /**
     * Executor history (completed/rejected for a user).
     */
    /** @return list<object> */
    public function getHistory(int $executorId, int $limit = 20, int $offset = 0): array
    {
        return $this->db->fetchAll(
            "SELECT av.*, a.title as ad_title, a.link as video_link
             FROM adtube_views av
             JOIN ads a ON a.id = av.ad_id
             WHERE av.executor_id = ? AND av.status IN ('completed','rejected')
             ORDER BY av.updated_at DESC
             LIMIT ? OFFSET ?",
            [$executorId, $limit, $offset]
        ) ?: [];
    }

    /**
     * Count active executions for a user (pending or watching).
     */
    public function countActiveForUser(int $executorId): int
    {
        return (int)($this->db->fetch(
            "SELECT COUNT(*) as c FROM adtube_views WHERE executor_id = ? AND status IN ('pending','watching')",
            [$executorId]
        )->c ?? 0);
    }

    /**
     * Count completed executions for a user today.
     */
    public function countCompletedToday(int $executorId): int
    {
        return (int)($this->db->fetch(
            "SELECT COUNT(*) as c FROM adtube_views
             WHERE executor_id = ? AND status = 'completed' AND DATE(completed_at) = CURDATE()",
            [$executorId]
        )->c ?? 0);
    }
}
