<?php

declare(strict_types=1);

namespace App\Models;

use Core\Model;

/**
 * Dispute Model - مدل اشتراکی مدیریت اختلافات
 * 
 * این مدل برای تمامی ماژول‌ها (Task, Influencer, Order, etc) استفاده می‌شود.
 * جدول: disputes
 */
class Dispute extends Model
{
    private function objectOrNull(mixed $value): ?\stdClass
    {
        if (!is_object($value)) return null;
        /** @var \stdClass $value */ // خروجی PDO::FETCH_OBJ همواره stdClass است
        return $value;
    }

    protected static string $table = 'disputes';

    // ┌─────────────────────────────────────────────────────────────┐
    // │ Status Constants
    // └─────────────────────────────────────────────────────────────┘
    public const STATUS_OPEN = 'open';
    public const STATUS_OPEN_PEER = 'open_peer';
    public const STATUS_UNDER_REVIEW = 'under_review';
    public const STATUS_RESOLVED_PEER = 'resolved_peer';
    public const STATUS_ESCALATED = 'escalated';
    public const STATUS_RESOLVED_ADMIN = 'resolved_admin';
    public const STATUS_RESOLVED_EXECUTOR = 'resolved_for_executor';
    public const STATUS_RESOLVED_ADVERTISER = 'resolved_for_advertiser';
    public const STATUS_CLOSED = 'closed';

    public const OPEN_STATUSES = [self::STATUS_OPEN, self::STATUS_OPEN_PEER, self::STATUS_UNDER_REVIEW, self::STATUS_ESCALATED];
    public const CLOSED_STATUSES = [self::STATUS_RESOLVED_PEER, self::STATUS_RESOLVED_ADMIN, self::STATUS_RESOLVED_EXECUTOR, self::STATUS_RESOLVED_ADVERTISER, self::STATUS_CLOSED];
    /** @return ?\stdClass */
    public function find(int $id): ?\stdClass
    {
        $stmt = $this->db->prepare("
            SELECT d.*, 
                   cu.full_name AS customer_name,
                   ou.full_name AS other_party_name
            FROM disputes d
            LEFT JOIN users cu ON cu.id = d.user_id
            LEFT JOIN users ou ON ou.id = d.target_user_id
            WHERE d.id = ?
            LIMIT 1
        ");
        $stmt->execute([$id]);
        return $this->objectOrNull($stmt->fetch(\PDO::FETCH_OBJ));
    }

    /** @return list<\stdClass> */
    public function getByUser(int $userId, int $limit = 20, int $offset = 0): array
    {
        $stmt = $this->db->prepare("
            SELECT d.*, 
                   cu.full_name AS customer_name,
                   ou.full_name AS other_party_name
            FROM disputes d
            LEFT JOIN users cu ON cu.id = d.user_id
            LEFT JOIN users ou ON ou.id = d.target_user_id
            WHERE d.user_id = ? OR d.target_user_id = ?
            ORDER BY d.created_at DESC
            LIMIT ? OFFSET ?
        ");
        $stmt->execute([$userId, $userId, $limit, $offset]);
        return $stmt->fetchAll(\PDO::FETCH_OBJ) ?: [];
    }

    public function countByUser(int $userId): int
    {
        $stmt = $this->db->prepare("
            SELECT COUNT(*) FROM disputes WHERE user_id = ? OR target_user_id = ?
        ");
        $stmt->execute([$userId, $userId]);
        return (int) $stmt->fetchColumn();
    }

    /**
     * تعداد اختلاف‌های «باز» (هنوز به سرانجام نرسیده) یک کاربر — برای بج نوار کناری.
     * BUGFIX-SIDEBAR-DB-IN-VIEW: منطق را از views/partials/user/sidebar.php به اینجا منتقل کردیم.
     */
    public function countOpenByUser(int $userId): int
    {
        $stmt = $this->db->prepare("
            SELECT COUNT(*) FROM disputes
            WHERE (user_id = ? OR target_user_id = ?)
              AND status NOT IN ('resolved','closed','resolved_advertiser','resolved_executor','admin_closed')
        ");
        $stmt->execute([$userId, $userId]);
        return (int) $stmt->fetchColumn();
    }

    /** @param array<string, mixed> $data */
    public function createDispute(array $data): ?\stdClass
    {
        $stmt = $this->db->prepare("
            INSERT INTO disputes
                (ref_type, ref_id, user_id, target_user_id, reason, status, role, peer_deadline, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
        ");

        $ok = $stmt->execute([
            $data['ref_type'],
            $data['ref_id'],
            $data['user_id'],
            $data['target_user_id'] ?? null,
            $data['reason'],
            $data['status'] ?? self::STATUS_OPEN,
            $data['role'] ?? 'customer',
            $data['peer_deadline'] ?? null,
        ]);

        return $ok ? $this->find((int)$this->db->lastInsertId()) : null;
    }

    public function update(int $id, array $data): bool
    {
        $allowed = [
            'status', 'peer_deadline', 'resolution_note',
            'admin_decision', 'admin_id', 'admin_note',
            'penalty_amount', 'penalty_currency', 'penalty_target',
            'site_tax_amount', 'refund_percent', 'resolved_at', 'resolved_by'
        ];
        $fields = [];
        $values = [];
        foreach ($allowed as $f) {
            if (\array_key_exists($f, $data)) {
                $fields[] = "{$f} = ?";
                $values[] = $data[$f];
            }
        }
        if (empty($fields)) {
            return false;
        }
        $fields[] = "updated_at = NOW()";
        $values[] = $id;
        $stmt = $this->db->prepare(
            "UPDATE disputes SET " . \implode(', ', $fields) . " WHERE id = ?"
        );
        return $stmt->execute($values);
    }

    public function addMessage(int $disputeId, int $userId, string $message, ?string $attachment = null, ?string $role = null): bool
    {
        $stmt = $this->db->prepare("
            INSERT INTO dispute_messages (dispute_id, user_id, role, message, attachment, created_at)
            VALUES (?, ?, ?, ?, ?, NOW())
        ");
        return $stmt->execute([$disputeId, $userId, $role, $message, $attachment]);
    }

    /** @return list<\stdClass> */
    public function getMessages(int $disputeId): array
    {
        $stmt = $this->db->prepare("
            SELECT m.*, u.full_name AS sender_name
            FROM dispute_messages m
            LEFT JOIN users u ON u.id = m.user_id
            WHERE m.dispute_id = ?
            ORDER BY m.created_at ASC
        ");
        $stmt->execute([$disputeId]);
        return $stmt->fetchAll(\PDO::FETCH_OBJ);
    }

    /**
     * Check if there's an open dispute for a task submission
     */
    /**
     * Find dispute by ref_type and ref_id
     */
    /** @return ?\stdClass */
    public function findByRef(string $refType, int $refId): ?\stdClass
    {
        $stmt = $this->db->prepare("
            SELECT d.*,
                   cu.full_name AS customer_name,
                   ou.full_name AS other_party_name
            FROM disputes d
            LEFT JOIN users cu ON cu.id = d.user_id
            LEFT JOIN users ou ON ou.id = d.target_user_id
            WHERE d.ref_type = ? AND d.ref_id = ?
            ORDER BY d.created_at DESC
            LIMIT 1
        ");
        $stmt->execute([$refType, $refId]);
        return $this->objectOrNull($stmt->fetch(\PDO::FETCH_OBJ));
    }

    /**
     * Find dispute specifically for an order
     */
    /** @return ?\stdClass */
    public function findByOrderId(int $orderId): ?\stdClass
    {
        $stmt = $this->db->prepare("
            SELECT d.*,
                   cu.full_name AS customer_name,
                   ou.full_name AS other_party_name
            FROM disputes d
            LEFT JOIN users cu ON cu.id = d.user_id
            LEFT JOIN users ou ON ou.id = d.target_user_id
            WHERE d.ref_id = ? AND d.ref_type IN ('order', 'story_order', 'influencer_order', 'influencer')
            ORDER BY d.created_at DESC
            LIMIT 1
        ");
        $stmt->execute([$orderId]);
        return $this->objectOrNull($stmt->fetch(\PDO::FETCH_OBJ));
    }

    // ──────────────────────────────────────
    // لیست ادمین
    // ──────────────────────────────────────

    /**
     * @param array<string, mixed> $filters
     * @return list<\stdClass>
     */
    public function adminList(array $filters = [], int $limit = 30, int $offset = 0): array
    {
        $where = ['1=1'];
        $params = [];

        if (!empty($filters['status'])) {
            $where[] = "d.status = ?";
            $params[] = $filters['status'];
        }
        if (!empty($filters['ref_type'])) {
            if (is_array($filters['ref_type'])) {
                $placeholders = \implode(',', \array_fill(0, \count($filters['ref_type']), '?'));
                $where[] = "d.ref_type IN ({$placeholders})";
                foreach ($filters['ref_type'] as $rt) {
                    $params[] = $rt;
                }
            } else {
                $where[] = "d.ref_type = ?";
                $params[] = $filters['ref_type'];
            }
        }
        if (!empty($filters['search'])) {
            // M31: Use addcslashes to escape wildcard characters
            $searchValue = is_string($filters['search']) ? $filters['search'] : '';
            $escaped = addcslashes($searchValue, '%_\\');
            $s = '%' . $escaped . '%';
            $where[] = "(cu.full_name LIKE ? OR ou.full_name LIKE ?)";
            $params[] = $s;
            $params[] = $s;
        }

        $whereStr = \implode(' AND ', $where);
        $stmt = $this->db->prepare("
            SELECT d.*,
                   cu.full_name AS customer_name,
                   ou.full_name AS other_party_name
            FROM disputes d
            LEFT JOIN users cu ON cu.id = d.user_id
            LEFT JOIN users ou ON ou.id = d.target_user_id
            WHERE {$whereStr}
            ORDER BY d.created_at DESC
            LIMIT ? OFFSET ?
        ");
        $params[] = $limit;
        $params[] = $offset;
        $stmt->execute($params);
        return $stmt->fetchAll(\PDO::FETCH_OBJ);
    }

    /** @param array<string, mixed> $filters */
    public function adminCount(array $filters = []): int
    {
        $where = ['1=1'];
        $params = [];

        if (!empty($filters['status'])) {
            $where[] = "d.status = ?";
            $params[] = $filters['status'];
        }
        if (!empty($filters['ref_type'])) {
            if (is_array($filters['ref_type'])) {
                $placeholders = \implode(',', \array_fill(0, \count($filters['ref_type']), '?'));
                $where[] = "d.ref_type IN ({$placeholders})";
                foreach ($filters['ref_type'] as $rt) {
                    $params[] = $rt;
                }
            } else {
                $where[] = "d.ref_type = ?";
                $params[] = $filters['ref_type'];
            }
        }
        if (!empty($filters['search'])) {
            // M31: Use addcslashes to escape wildcard characters
            $searchValue = is_string($filters['search']) ? $filters['search'] : '';
            $escaped = addcslashes($searchValue, '%_\\');
            $s = '%' . $escaped . '%';
            $where[] = "(cu.full_name LIKE ? OR ou.full_name LIKE ?)";
            $params[] = $s;
            $params[] = $s;
        }

        $whereStr = \implode(' AND ', $where);
        $stmt = $this->db->prepare("
            SELECT COUNT(*) FROM disputes d
            LEFT JOIN users cu ON cu.id = d.user_id
            LEFT JOIN users ou ON ou.id = d.target_user_id
            WHERE {$whereStr}
        ");
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    }

    // ┌─────────────────────────────────────────────────────────────┐
    // │ State Machine Validation
    // └─────────────────────────────────────────────────────────────┘

    private const TRANSITIONS = [
        self::STATUS_OPEN => [self::STATUS_UNDER_REVIEW, self::STATUS_RESOLVED_EXECUTOR, self::STATUS_RESOLVED_ADVERTISER, self::STATUS_CLOSED],
        self::STATUS_UNDER_REVIEW => [self::STATUS_RESOLVED_EXECUTOR, self::STATUS_RESOLVED_ADVERTISER, self::STATUS_CLOSED],
        self::STATUS_OPEN_PEER => [self::STATUS_RESOLVED_PEER, self::STATUS_ESCALATED],
        self::STATUS_RESOLVED_PEER => [self::STATUS_CLOSED],
        self::STATUS_ESCALATED => [self::STATUS_RESOLVED_ADMIN],
        self::STATUS_RESOLVED_ADMIN => [self::STATUS_CLOSED],
        self::STATUS_CLOSED => [],
        self::STATUS_RESOLVED_EXECUTOR => [],
        self::STATUS_RESOLVED_ADVERTISER => [],
    ];

    public function canTransitionTo(string $currentStatus, string $targetStatus): bool
    {
        if (!isset(self::TRANSITIONS[$currentStatus])) {
            return false;
        }
        return \in_array($targetStatus, self::TRANSITIONS[$currentStatus], true);
    }

    // ┌─────────────────────────────────────────────────────────────┐
    // │ Ownership & Validation Methods
    // └─────────────────────────────────────────────────────────────┘

    public function getSafe(int $id): ?\stdClass
    {
        if ($id <= 0) {
            return null;
        }
        return $this->find($id);
    }

    public function isParty(int $disputeId, int $userId): bool
    {
        $dispute = $this->getSafe($disputeId);
        if (!$dispute) {
            return false;
        }
        $vars = get_object_vars($dispute);
        $ownerId = is_numeric($vars['user_id'] ?? null) ? (int)$vars['user_id'] : 0;
        $targetId = is_numeric($vars['target_user_id'] ?? null) ? (int)$vars['target_user_id'] : 0;
        return $ownerId === $userId || $targetId === $userId;
    }

    public function getUnreadMessageCount(int $disputeId, int $userId): int
    {
        $result = $this->db->prepare(
            "SELECT COUNT(*) FROM dispute_messages 
             WHERE dispute_id = ? AND user_id != ? AND is_read = 0"
        );
        $result->execute([$disputeId, $userId]);
        return (int)$result->fetchColumn();
    }

    /**
     * جزئیات کامل dispute از نوع custom_task_submission با JOIN های لازم.
     *
     * @param int      $disputeId
     * @param int|null $userId    اگر null نباشد، بررسی می‌شود که user طرف دعوا باشد
     * @return object|null
     */
    /** @return ?\stdClass */
    public function findDetailWithSubmission(int $disputeId, ?int $userId = null): ?\stdClass
    {
        $userCondition = $userId !== null
            ? "AND (d.user_id = {$userId} OR d.target_user_id = {$userId})"
            : '';

        $stmt = $this->db->prepare("
            SELECT d.*,
                   s.id              AS submission_id,
                   s.status          AS submission_status,
                   s.reward_amount,
                   s.reward_currency,
                   s.proof_text,
                   s.proof_url,
                   s.proof_code,
                   s.proof_file,
                   s.rejection_reason,
                   s.worker_id,
                   a.title           AS task_title,
                   a.description     AS task_description,
                   a.user_id         AS advertiser_id,
                   w.full_name       AS worker_name,
                   adv.full_name     AS advertiser_name
            FROM disputes d
            INNER JOIN custom_task_submissions s
                ON s.id = d.ref_id AND d.ref_type = 'custom_task_submission'
            LEFT JOIN ads a ON a.id = s.task_id
            LEFT JOIN users w ON w.id = s.worker_id
            LEFT JOIN users adv ON adv.id = a.user_id
            WHERE d.id = ? {$userCondition}
            LIMIT 1
        ");
        $stmt->execute([$disputeId]);
        return $this->objectOrNull($stmt->fetch(\PDO::FETCH_OBJ));
    }
    /**
     * Get Persian label for a dispute status.
     */
    public function statusLabel(string $status): string
    {
        return match($status) {
            'open'               => 'باز',
            'peer_resolution'     => 'در حال گفتگو',
            'escalated_to_admin'  => 'ارجاع به مدیر',
            'resolved'            => 'حل شده',
            'closed'              => 'بسته شده',
            'cancelled'           => 'لغو شده',
            default               => $status,
        };
    }

    /**
     * بستن/حل اختلافِ ارجاع‌شده (ref_type + ref_id) — برای دامنه‌هایی که
     * settlement مخصوصِ خودشان را دارند (مثل ویترین) و فقط باید وضعیت
     * پرونده‌ی یکپارچه را بسته علامت بزنند.
     * @param int|null $winnerUserId طرفِ برنده (در صورت وجود)
     * @return bool
     */
    public function resolveByRef(string $refType, int $refId, int $adminId, string $verdict, ?int $winnerUserId = null): bool
    {
        $stmt = $this->db->query(
            "UPDATE disputes
             SET status = 'resolved_admin',
                 admin_id = ?,
                 admin_decision = ?,
                 resolved_by = ?,
                 resolved_at = NOW(),
                 updated_at = NOW()
             WHERE ref_type = ? AND ref_id = ? AND status != 'resolved_admin'",
            [$adminId, $verdict, $adminId, $refType, $refId]
        );
        return $stmt->rowCount() > 0;
    }
}
