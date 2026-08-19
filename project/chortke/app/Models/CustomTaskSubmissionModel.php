<?php

declare(strict_types=1);

namespace App\Models;

use Core\Model;

/**
 * CustomTaskSubmissionModel - Custom Task Submissions Data Access Layer
 */
class CustomTaskSubmissionModel extends Model
{
    protected static string $table = 'custom_task_submissions';

    public function submission_find(int $id): ?\stdClass
    {
        $stmt = $this->db->prepare("
            SELECT s.*, ct.title AS task_title, ct.user_id AS creator_id, ct.proof_type,
                   w.full_name AS worker_name, w.email AS worker_email
            FROM custom_task_submissions s
            LEFT JOIN ads ct ON ct.id = s.task_id
            LEFT JOIN users w ON w.id = s.worker_id
            WHERE s.id = ?
        ");
        $stmt->execute([$id]);
        $r = $stmt->fetch(\PDO::FETCH_OBJ);
        return $r ? (object) $r : null;
    }

    public function submission_findById(int $id): ?\stdClass
    {
        return $this->submission_find($id);
    }

    public function submission_findByIdForUpdate(int $id): ?\stdClass
    {
        if (!$this->db->inTransaction()) {
            throw new \RuntimeException("submission_findByIdForUpdate must be called within an active database transaction.");
        }
        $stmt = $this->db->prepare("SELECT * FROM custom_task_submissions WHERE id = ? FOR UPDATE");
        $stmt->execute([$id]);
        $r = $stmt->fetch(\PDO::FETCH_OBJ);
        return $r ? (object) $r : null;
    }

    /** @param array<string, mixed> $d */
    public function submission_create(array $d): ?\stdClass
    {
        try {
            $this->db->beginTransaction();

            // 1. Lock custom task to prevent concurrent workers from exceeding limits
            $stmt = $this->db->prepare("SELECT id FROM ads WHERE id = ? FOR UPDATE");
            $stmt->execute([$d['task_id']]);
            $task = $stmt->fetch(\PDO::FETCH_OBJ);

            if (!$task) {
                $this->db->rollback();
                return null;
            }

            // 2. Lock duplicate check in transaction (lock actual rows to prevent concurrent TOCTOU races)
            $stmt = $this->db->prepare("
                SELECT id FROM custom_task_submissions
                WHERE task_id = ? AND worker_id = ? AND status NOT IN ('expired','rejected')
                FOR UPDATE
            ");
            $stmt->execute([$d['task_id'], $d['worker_id']]);
            $existing = $stmt->fetch(\PDO::FETCH_OBJ);

            if ($existing) {
                $this->db->rollback();
                return null;
            }

            $ip = $d['worker_ip'] ?? '127.0.0.1';
            $fingerprint = $d['worker_fingerprint'] ?? 'unknown';

            $stmt = $this->db->prepare("
                INSERT INTO custom_task_submissions
                (task_id, worker_id, user_id, deadline_at, status, reward_amount, reward_currency,
                 idempotency_key, worker_ip, worker_fingerprint)
                VALUES (?,?,?,?,?,?,?,?,?,?)
            ");
            $result = $stmt->execute([
                $d['task_id'], 
                $d['worker_id'], 
                $d['worker_id'], 
                $d['deadline_at'],
                $d['status'] ?? 'in_progress', 
                $d['reward_amount'] ?? 0,
                $d['reward_currency'] ?? 'irt', 
                $d['idempotency_key'] ?? \uniqid('idemp_', true),
                $ip,
                $fingerprint,
            ]);
            
            if (!$result) {
                $this->db->rollback();
                return null;
            }

            $lastId = (int)$this->db->lastInsertId();
            $this->db->commit();

            return $this->submission_find($lastId);

        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollback();
            }
            return null;
        }
    }

    /** @param array<string, mixed> $data */
    public function submission_update(int $id, array $data): bool
    {
        $fields = []; 
        $values = [];
        
        $allowed = [
            'proof_url', 'proof_text', 'proof_code', 'proof_file', 'proof_file_hash', 'proof_data',
            'submitted_at', 'approved_at', 'reviewed_at', 'reviewed_by', 'auto_approved_at',
            'status', 'rejection_reason', 'reward_paid', 'reward_transaction_id', 'paid_amount', 'resolution_type', 'resolution_note', 'metadata', 'dispute_id'
        ];
        
        foreach ($allowed as $f) {
            if (\array_key_exists($f, $data)) {
                $fields[] = "{$f} = ?";
                $values[] = $data[$f];
            }
        }
        
        if (empty($fields)) return false;
        
        $values[] = $id;
        $stmt = $this->db->prepare("UPDATE custom_task_submissions SET " . \implode(', ', $fields) . " WHERE id = ?");
        return $stmt->execute($values);
    }

    public function submission_hasWorkerDone(int $taskId, int $workerId): bool
    {
        $stmt = $this->db->prepare("
            SELECT COUNT(*) FROM custom_task_submissions
            WHERE task_id = ? AND worker_id = ? AND status NOT IN ('expired','rejected')
        ");
        $stmt->execute([$taskId, $workerId]);
        return (int) $stmt->fetchColumn() > 0;
    }

    public function submission_todayCount(int $workerId): int
    {
        $stmt = $this->db->prepare("
            SELECT COUNT(*) FROM custom_task_submissions
            WHERE worker_id = ? AND DATE(created_at) = CURDATE()
        ");
        $stmt->execute([$workerId]);
        return (int) $stmt->fetchColumn();
    }

    public function submission_isDuplicateImage(string $hash, int $taskId): bool
    {
        $stmt = $this->db->prepare("
            SELECT COUNT(*) FROM custom_task_submissions
            WHERE proof_file_hash = ? AND task_id = ? AND status != 'rejected'
        ");
        $stmt->execute([$hash, $taskId]);
        return (int) $stmt->fetchColumn() > 0;
    }

    /** @return list<object> */
    public function submission_getByTask(int $taskId, ?string $status = null, int $limit = 30, int $offset = 0): array
    {
        $where = ["s.task_id = ?"];
        $params = [$taskId];
        
        if ($status) { 
            $where[] = "s.status = ?"; 
            $params[] = $status; 
        }
        
        $whereStr = \implode(' AND ', $where);
        
        $stmt = $this->db->prepare("
            SELECT s.*, w.full_name AS worker_name, w.email AS worker_email
            FROM custom_task_submissions s
            LEFT JOIN users w ON w.id = s.worker_id
            WHERE {$whereStr} 
            ORDER BY s.created_at DESC 
            LIMIT ? OFFSET ?
        ");
        
        $params[] = $limit; 
        $params[] = $offset;
        $stmt->execute($params);
        return $stmt->fetchAll(\PDO::FETCH_OBJ);
    }

    /** @return list<object> */
    public function submission_getByWorker(int $workerId, ?string $status = null, int $limit = 20, int $offset = 0): array
    {
        $where = ["s.worker_id = ?"];
        $params = [$workerId];
        
        if ($status) { 
            $where[] = "s.status = ?"; 
            $params[] = $status; 
        }
        
        $whereStr = \implode(' AND ', $where);
        
        $stmt = $this->db->prepare("
            SELECT s.*, ct.title AS task_title, ct.price_per_task, ct.currency
            FROM custom_task_submissions s
            LEFT JOIN ads ct ON ct.id = s.task_id
            WHERE {$whereStr} 
            ORDER BY s.created_at DESC 
            LIMIT ? OFFSET ?
        ");
        
        $params[] = $limit; 
        $params[] = $offset;
        $stmt->execute($params);
        return $stmt->fetchAll(\PDO::FETCH_OBJ);
    }

    /** @return list<object> */
    /** @return list<\stdClass> */
    public function submission_getExpiredSubmissions(): array
    {
        $stmt = $this->db->prepare("
            SELECT id, task_id FROM custom_task_submissions
            WHERE status = 'in_progress' AND deadline_at <= NOW()
        ");
        $stmt->execute();
        return $stmt->fetchAll(\PDO::FETCH_OBJ);
    }

    /** @return list<\stdClass> */
    public function submission_getUnreviewedSubmissions(int $hours = 48): array
    {
        $stmt = $this->db->prepare("
            SELECT id, task_id FROM custom_task_submissions
            WHERE status = 'submitted' AND submitted_at <= DATE_SUB(NOW(), INTERVAL ? HOUR)
        ");
        $stmt->execute([$hours]);
        return $stmt->fetchAll(\PDO::FETCH_OBJ);
    }

    public function submission_findWithTask(int $id): ?\stdClass
    {
        $stmt = $this->db->prepare("
            SELECT s.*, ct.currency, ct.user_id AS creator_id
            FROM custom_task_submissions s
            LEFT JOIN ads ct ON ct.id = s.task_id
            WHERE s.id = ?
        ");
        $stmt->execute([$id]);
        $result = $stmt->fetch(\PDO::FETCH_OBJ);
        return $result ? (object) $result : null;
    }

    public function submission_markExpired(int $id): bool
    {
        $stmt = $this->db->prepare("UPDATE custom_task_submissions SET status = 'expired' WHERE id = ?");
        return $stmt->execute([$id]);
    }

    public function submission_markApproved(int $id): bool
    {
        $stmt = $this->db->prepare("UPDATE custom_task_submissions SET status = 'approved', reviewed_at = NOW() WHERE id = ?");
        return $stmt->execute([$id]);
    }

    public function submission_markRewardPaid(int $id): bool
    {
        $stmt = $this->db->prepare("UPDATE custom_task_submissions SET reward_paid = 1 WHERE id = ?");
        return $stmt->execute([$id]);
    }

    public function submission_checkIdempotency(string $key): bool
    {
        $stmt = $this->db->prepare("SELECT id FROM custom_task_submissions WHERE idempotency_key = ?");
        $stmt->execute([$key]);
        return $stmt->fetch() !== false;
    }

    /** @return list<object> */
    /** @return list<\stdClass> */
    public function getOldSubmissionsForAutoApproval(int $hoursOld = 48): array
    {
        // اصلاح کلیدی معماری همزمانی در تایید خودکار تسک‌ها (Auto-Approval Concurrency Guard):
        // استفاده از قفل‌گذاری اتمیک FOR UPDATE جهت جلوگیری از اجرای موازی تایید توسط ورکرهای کرون و واریز مضاعف پاداش به مجریان
        $stmt = $this->db->prepare("
            SELECT s.*, ct.title AS task_title, ct.user_id AS creator_id, ct.proof_type,
                   w.full_name AS worker_name, w.email AS worker_email
            FROM custom_task_submissions s
            LEFT JOIN ads ct ON ct.id = s.task_id
            LEFT JOIN users w ON w.id = s.worker_id
            WHERE s.status = 'submitted'
            AND s.submitted_at <= DATE_SUB(NOW(), INTERVAL ? HOUR)
            AND NOT EXISTS (
                SELECT 1 FROM disputes d
                WHERE d.ref_type = 'custom_task_submission'
                  AND d.ref_id = s.id
                  AND d.status IN ('open', 'open_peer', 'under_review', 'escalated')
            ) FOR UPDATE
        ");
        $stmt->execute([$hoursOld]);
        return $stmt->fetchAll(\PDO::FETCH_OBJ);
    }

    /** @return list<object> */
    public function getAdminSubmissionStats(): array
    {
        $stmt = $this->db->prepare("
            SELECT
                s.status,
                COUNT(*) as count,
                AVG(TIMESTAMPDIFF(MINUTE, s.submitted_at, s.reviewed_at)) as avg_review_time
            FROM custom_task_submissions s
            GROUP BY s.status
        ");
        $stmt->execute();
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * لیست اجراهای یک worker با اطلاعات تسک (برای صفحه mySubmissions).
     * جایگزین Raw SQL در CustomTaskController::mySubmissions()
     */
    /** @return list<object> */
    public function submission_getByWorkerWithTask(int $workerId, int $limit = 100, int $offset = 0): array
    {
        $limit  = max(1, min(500, $limit));
        $offset = max(0, $offset);
        $stmt   = $this->db->prepare("
            SELECT s.*, a.title AS task_title, a.description AS task_description
            FROM custom_task_submissions s
            LEFT JOIN ads a ON a.id = s.task_id
            WHERE s.worker_id = ?
            ORDER BY s.created_at DESC
            LIMIT {$limit} OFFSET {$offset}
        ");
        $stmt->execute([$workerId]);
        return $stmt->fetchAll(\PDO::FETCH_OBJ) ?: [];
    }

    /**
     * یافتن submission با اطلاعات کامل تسک برای ارسال مدرک (proof).
     * جایگزین Raw SQL در CustomTaskController::findSubmissionForProof()
     */
    public function submission_findForProof(int $submissionId, int $workerId): ?\stdClass
    {
        $stmt = $this->db->prepare("
            SELECT s.*,
                   a.title            AS task_title,
                   a.description      AS task_description,
                   a.proof_type,
                   a.proof_description,
                   a.proof_schema,
                   a.price_per_task,
                   a.currency,
                   a.user_id          AS advertiser_id
            FROM custom_task_submissions s
            LEFT JOIN ads a ON a.id = s.task_id
            WHERE s.id = ? AND s.worker_id = ?
            LIMIT 1
        ");
        $stmt->execute([$submissionId, $workerId]);
        $row = $stmt->fetch(\PDO::FETCH_OBJ);
        return $row ? (object) $row : null;
    }
}
