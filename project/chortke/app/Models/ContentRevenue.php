<?php

namespace App\Models;

use Core\Model;
use Core\Database;

class ContentRevenue extends Model {
    private function objectOrNull(mixed $value): ?\stdClass { return $value instanceof \stdClass ? $value : null; }

    private function countFromRow(mixed $row): int {
        if (is_object($row)) { $vars = get_object_vars($row); return is_numeric($vars['total'] ?? null) ? (int)$vars['total'] : 0; }
        if (is_array($row)) { return is_numeric($row['total'] ?? null) ? (int)$row['total'] : 0; }
        return 0;
    }

    protected static string $table = 'content_revenues';

    public const STATUS_PENDING   = 'pending';
    public const STATUS_APPROVED  = 'approved';
    public const STATUS_PAID      = 'paid';
    public const STATUS_CANCELLED = 'cancelled';

    /**
     * ایجاد رکورد درآمد
     * M25: Use column whitelist to prevent unexpected fields from being inserted
     * خروجی: id یا null
     */
    /** @param array<string, mixed> $data */
    public function create(array $data): ?int
    {
        $now = \date('Y-m-d H:i:s');

        // M25: Whitelist of allowed columns for this table
        $allowedColumns = [
            'user_id', 'content_id', 'submission_id', 'period', 'views', 'status',
            'amount', 'total_revenue', 'gross_amount', 'site_share_percent', 'site_share_amount',
            'platform_fee', 'user_share_percent', 'user_share_amount', 'tax_percent', 'tax_amount',
            'net_user_amount', 'currency', 'metadata', 'created_by', 'is_deleted', 'created_at', 'updated_at'
        ];

        if (isset($data['submission_id']) && !isset($data['content_id'])) {
            $data['content_id'] = $data['submission_id'];
        }
        if (isset($data['total_revenue']) && !isset($data['gross_amount'])) {
            $data['gross_amount'] = $data['total_revenue'];
        }
        if (isset($data['net_user_amount']) && !isset($data['amount'])) {
            $data['amount'] = $data['net_user_amount'];
        }

        // Only keep allowed columns
        $sanitizedData = [];
        foreach ($allowedColumns as $col) {
            if (\array_key_exists($col, $data)) {
                $sanitizedData[$col] = $data[$col];
            }
        }

        $sanitizedData['created_at'] = $sanitizedData['created_at'] ?? $now;
        $sanitizedData['updated_at'] = $sanitizedData['updated_at'] ?? $now;
        $sanitizedData['is_deleted'] = $sanitizedData['is_deleted'] ?? 0;

        $columns = \array_keys($sanitizedData);
        $values  = \array_values($sanitizedData);

        $placeholders = \array_fill(0, \count($columns), '?');
        $colsSql = '`' . \implode('`,`', $columns) . '`';

        // L-07: Use static::$table instead of hard-coded 'content_revenues'
        $sql = "INSERT INTO `" . static::$table . "` ({$colsSql}) VALUES (" . \implode(',', $placeholders) . ")";

        $stmt = $this->db->prepare($sql);
        $ok = $stmt->execute($values);

        if (!$ok) return null;

        $id = (int)$this->db->lastInsertId();
        return $id > 0 ? $id : null;
    }

    /** @return ?\stdClass */
    public function find(int $id): ?\stdClass
    {
        $stmt = $this->db->query(
            "SELECT * FROM content_revenues WHERE id = ? AND is_deleted = 0 LIMIT 1",
            [$id]
        );

        $row = $stmt->fetch(\PDO::FETCH_OBJ);
        return $this->objectOrNull($row);
    }

    /** @return ?\stdClass */
    public function findWithDetails(int $id): ?\stdClass
    {
        $stmt = $this->db->query(
            "SELECT cr.*, cs.title as video_title, cs.video_url, cs.platform,
                    u.full_name as user_name, u.email as user_email
             FROM content_revenues cr
             JOIN content_submissions cs ON cr.submission_id = cs.id
             JOIN users u ON cr.user_id = u.id
             WHERE cr.id = ? AND cr.is_deleted = 0
             LIMIT 1",
            [$id]
        );

        $row = $stmt->fetch(\PDO::FETCH_OBJ);
        return $this->objectOrNull($row);
    }

    /** @return list<object> */
    public function getBySubmission(int $submissionId): array
    {
        $stmt = $this->db->query(
            "SELECT * FROM content_revenues
             WHERE submission_id = ? AND is_deleted = 0
             ORDER BY period DESC",
            [$submissionId]
        );

        return $stmt->fetchAll(\PDO::FETCH_OBJ);
    }

    /** @return list<object> */
    public function getByUser(int $userId, int $limit = 20, int $offset = 0): array
    {
        $limit  = \max(1, (int)$limit);
        $offset = \max(0, (int)$offset);

        $stmt = $this->db->prepare(
            "SELECT cr.*, cs.title as video_title, cs.platform
             FROM content_revenues cr
             JOIN content_submissions cs ON cr.submission_id = cs.id
             WHERE cr.user_id = :user_id AND cr.is_deleted = 0
             ORDER BY cr.period DESC
             LIMIT :limit OFFSET :offset"
        );
        $stmt->bindValue(':user_id', $userId, \PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, \PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, \PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(\PDO::FETCH_OBJ) ?: [];
    }

    public function countByUser(int $userId): int
    {
        $stmt = $this->db->query(
            "SELECT COUNT(*) as total
             FROM content_revenues
             WHERE user_id = ? AND is_deleted = 0",
            [$userId]
        );

        $row = $stmt->fetch(\PDO::FETCH_OBJ);
        return $this->countFromRow($row);
    }

    public function getTotalUserRevenue(int $userId, ?string $status = null): float
    {
        $sql = "SELECT COALESCE(SUM(net_user_amount), 0) as total
                FROM content_revenues
                WHERE user_id = ? AND is_deleted = 0";
        $params = [$userId];

        if ($status) {
            $sql .= " AND status = ?";
            $params[] = $status;
        }

        $stmt = $this->db->query($sql, $params);
        $row = $stmt->fetch(\PDO::FETCH_OBJ);

        $total = $this->countFromRow($row);
        return (float)$total;
    }

    /**
     * @param array<string, mixed> $filters
     * @return list<object>
     */
    public function getAll(array $filters = [], int $limit = 20, int $offset = 0): array
    {
        $limit  = \max(1, (int)$limit);
        $offset = \max(0, (int)$offset);

        $sql = "SELECT cr.*, cs.title as title, cs.title as video_title, cs.platform,
                       u.full_name as user_name
                FROM content_revenues cr
                JOIN content_submissions cs ON cr.submission_id = cs.id
                JOIN users u ON cr.user_id = u.id
                WHERE cr.is_deleted = 0";
        
        $params = [];

        if (!empty($filters['status'])) {
            $sql .= " AND cr.status = :status";
            $params['status'] = $filters['status'];
        }
        if (!empty($filters['user_id'])) {
            $sql .= " AND cr.user_id = :user_id";
            $params['user_id'] = is_numeric($filters['user_id']) ? (int)$filters['user_id'] : 0;
        }
        if (!empty($filters['period'])) {
            $sql .= " AND cr.period = :period";
            $params['period'] = $filters['period'];
        }

        $sql .= " ORDER BY cr.created_at DESC LIMIT :limit OFFSET :offset";

        $stmt = $this->db->prepare($sql);
        foreach ((array)$params as $key => $value) {
            $stmt->bindValue(':' . $key, $value);
        }
        $stmt->bindValue(':limit', $limit, \PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, \PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(\PDO::FETCH_OBJ) ?: [];
    }

    /** @param array<string, mixed> $filters */
    public function countAll(array $filters = []): int
    {
        $sql = "SELECT COUNT(*) as total
                FROM content_revenues cr
                JOIN content_submissions cs ON cr.submission_id = cs.id
                WHERE cr.is_deleted = 0";
        $params = [];

        if (!empty($filters['status'])) {
            $sql .= " AND cr.status = ?";
            $params[] = $filters['status'];
        }
        if (!empty($filters['user_id'])) {
            $sql .= " AND cr.user_id = ?";
            $params[] = is_numeric($filters['user_id']) ? (int)$filters['user_id'] : 0;
        }
        if (!empty($filters['period'])) {
            $sql .= " AND cr.period = ?";
            $params[] = $filters['period'];
        }

        $stmt = $this->db->query($sql, $params);
        $row = $stmt->fetch(\PDO::FETCH_OBJ);

        return $this->countFromRow($row);
    }

    public function existsForPeriod(int $submissionId, string $period): bool
    {
        $stmt = $this->db->query(
            "SELECT COUNT(*) as total
             FROM content_revenues
             WHERE submission_id = ? AND period = ? AND is_deleted = 0",
            [$submissionId, $period]
        );

        $row = $stmt->fetch(\PDO::FETCH_OBJ);
        return $this->countFromRow($row) > 0;
    }

    /** @param array<string, mixed> $data */
    public function update(int $id, array $data): bool
    {
        if (empty($data)) return false;

        // Whitelist allowed fields
        $allowedFields = [
            'status', 'total_revenue', 'gross_amount', 'site_share_percent', 'site_share_amount',
            'platform_fee', 'user_share_percent', 'user_share_amount', 'tax_percent', 'tax_amount',
            'net_user_amount', 'currency', 'metadata', 'admin_notes',
            'reviewed_by', 'reviewed_at', 'paid_at', 'paid_by_admin', 'transaction_id', 'is_deleted'
        ];

        $fields = [];
        $values = [];

        foreach ((array)$data as $k => $v) {
            if (\in_array($k, $allowedFields, true)) {
                $fields[] = "`{$k}` = ?";
                $values[] = $v;
            }
        }

        if (empty($fields)) {
            return false;
        }

        $fields[] = "`updated_at` = NOW()";
        $values[] = $id;

        $sql = "UPDATE content_revenues
                SET " . \implode(', ', $fields) . "
                WHERE id = ? AND is_deleted = 0";

        $stmt = $this->db->query($sql, $values);

        return $stmt->rowCount() > 0;
    }

    /** @return object */
    public function getFinancialStats(): object
    {
        $stmt = $this->db->query(
            "SELECT
                COUNT(*) as total_records,
                COALESCE(SUM(total_revenue), 0) as total_revenue,
                COALESCE(SUM(site_share_amount), 0) as total_site_share,
                COALESCE(SUM(net_user_amount), 0) as total_user_paid,
                COALESCE(SUM(tax_amount), 0) as total_tax,
                SUM(CASE WHEN status = 'pending' THEN net_user_amount ELSE 0 END) as pending_amount,
                SUM(CASE WHEN status = 'paid' THEN net_user_amount ELSE 0 END) as paid_amount
             FROM content_revenues WHERE is_deleted = 0"
        );

        return $this->objectOrNull($stmt->fetch(\PDO::FETCH_OBJ)) ?? (object)[];
    }

    /**
     * دریافت رکورد درآمد با قفل FOR UPDATE
     */
    /** @return ?\stdClass */
    public function findForUpdate(int $id): ?\stdClass
    {
        $row = $this->db->query("SELECT * FROM content_revenues WHERE id = ? FOR UPDATE", [$id])->fetch(\PDO::FETCH_OBJ);
        return $this->objectOrNull($row);
    }
}