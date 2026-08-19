<?php

namespace App\Models;

use Core\Model;
use Core\Database;

class InvestmentWithdrawal extends Model {
    protected static string $table = 'investment_withdrawals';

    public const STATUS_PENDING   = 'pending';
    public const STATUS_APPROVED  = 'approved';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_REJECTED  = 'rejected';
    public const STATUS_CANCELLED = 'cancelled';

    public const TYPE_PROFIT_ONLY = 'profit_only';
    public const TYPE_FULL_CLOSE  = 'full_close';
    public const TYPE_FULL        = 'full_close';

    /**
     * ایجاد درخواست برداشت سرمایه‌گذاری
     * خروجی: id یا null
     */
    /** @param array<string, mixed> $data */
    public function create(array $data): ?int
    {
        $now = \date('Y-m-d H:i:s');

        $data['created_at'] = $data['created_at'] ?? $now;
        $data['updated_at'] = $data['updated_at'] ?? $now;
        $data['is_deleted'] = $data['is_deleted'] ?? 0;

        $allowed = [
            'user_id', 'investment_id', 'amount', 'type', 'withdrawal_type', 'status', 'request_id',
            'rejection_reason', 'processed_at', 'processed_by', 'created_at', 'updated_at', 'is_deleted'
        ];

        $filtered = [];
        foreach ($allowed as $k) {
            if (\array_key_exists($k, $data)) {
                $filtered[$k] = $data[$k];
            }
        }

        $columns = \array_keys($filtered);
        $values  = \array_values($filtered);

        $placeholders = \array_fill(0, \count($columns), '?');
        $colsSql = '`' . \implode('`,`', $columns) . '`';

        $sql = "INSERT INTO `investment_withdrawals` ({$colsSql}) VALUES (" . \implode(',', $placeholders) . ")";

        $stmt = $this->db->prepare($sql);
        $ok = $stmt->execute($values);

        if (!$ok) {
            return null;
        }

        $id = (int)$this->db->lastInsertId();
        return $id > 0 ? $id : null;
    }

    public function find(int $id): ?\stdClass
    {
        $stmt = $this->db->query(
            "SELECT * FROM investment_withdrawals WHERE id = ? AND is_deleted = 0 LIMIT 1",
            [$id]
        );

        return $this->fetchObject($stmt);
    }

    public function findWithDetails(int $id): ?\stdClass
    {
        $stmt = $this->db->query(
            "SELECT iw.*, i.amount as invest_amount, u.full_name as user_name
             FROM investment_withdrawals iw
             JOIN investments i ON iw.investment_id = i.id
             JOIN users u ON iw.user_id = u.id
             WHERE iw.id = ? AND iw.is_deleted = 0
             LIMIT 1",
             [$id]
        );

        return $this->fetchObject($stmt);
    }

    /** @return list<\stdClass> */
    public function getByUser(int $userId, int $limit = 20, int $offset = 0): array
    {
        $limit  = \max(1, (int)$limit);
        $offset = \max(0, (int)$offset);

        $stmt = $this->db->prepare(
            "SELECT * FROM investment_withdrawals
             WHERE user_id = :user_id AND is_deleted = 0
             ORDER BY created_at DESC
             LIMIT :limit OFFSET :offset"
        );
        $stmt->bindValue(':user_id', $userId, \PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, \PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, \PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(\PDO::FETCH_OBJ) ?: [];
    }

    /**
     * آیا درخواست pending/approved دارد
     */
    public function hasPending(int $userId): bool
    {
        $stmt = $this->db->query(
            "SELECT COUNT(*) as total
             FROM investment_withdrawals
             WHERE user_id = ?
               AND status IN (?, ?)
               AND is_deleted = 0",
            [$userId, self::STATUS_PENDING, self::STATUS_APPROVED]
        );

        $row = $this->fetchObject($stmt);
        return (int)($row->total ?? 0) > 0;
    }

    /** @param array<string, mixed> $filters */
    /** @return list<\stdClass> */
    /** @param array<string, mixed> $filters */
    /**
     * @param array<string, mixed> $filters
     * @return list<\stdClass>
     */
    public function getAll(array $filters = [], int $limit = 20, int $offset = 0): array
    {
        $limit  = \max(1, (int)$limit);
        $offset = \max(0, (int)$offset);

        $sql = "SELECT iw.*, u.full_name as user_name, i.amount as invest_amount
                FROM investment_withdrawals iw
                JOIN users u ON iw.user_id = u.id
                JOIN investments i ON iw.investment_id = i.id
                WHERE iw.is_deleted = 0";

        $params = [];

        if (!empty($filters['status'])) {
            $sql .= " AND iw.status = :status";
            $params['status'] = $filters['status'];
        }

        if (!empty($filters['user_id'])) {
            $sql .= " AND iw.user_id = :user_id";
            $params['user_id'] = int_value($filters['user_id']);
        }

        $sql .= " ORDER BY iw.created_at DESC LIMIT :limit OFFSET :offset";

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
                FROM investment_withdrawals iw
                WHERE iw.is_deleted = 0";

        $params = [];

        if (!empty($filters['status'])) {
            $sql .= " AND iw.status = ?";
            $params[] = $filters['status'];
        }

        if (!empty($filters['user_id'])) {
            $sql .= " AND iw.user_id = ?";
            $params[] = int_value($filters['user_id']);
        }

        $stmt = $this->db->query($sql, $params);
        $row = $this->fetchObject($stmt);

        return (int)($row->total ?? 0);
    }

    /** @param array<string, mixed> $data */
    public function update(int $id, array $data): bool
    {
        if (empty($data)) {
            return false;
        }

        $fields = [];
        $params = [];

        $allowed = ['status', 'amount', 'type', 'rejection_reason', 'processed_at', 'processed_by', 'is_deleted', 'updated_at'];
        foreach ($allowed as $k) {
            if (\array_key_exists($k, $data)) {
                $fields[] = "`{$k}` = ?";
                $params[] = $data[$k];
            }
        }

        if (empty($fields)) {
            return false;
        }

        $params[] = $id;

        $sql = "UPDATE investment_withdrawals SET " . implode(', ', $fields) . " WHERE id = ?";
        $stmt = $this->db->prepare($sql);
        $ok = $stmt->execute($params);

        return (bool)$ok;
    }

    public function findByRequestId(string $requestId): ?\stdClass
    {
        $stmt = $this->db->prepare("
            SELECT *
            FROM investment_withdrawals
            WHERE request_id = ?
            LIMIT 1
        ");
        $stmt->execute([$requestId]);
        return $this->fetchObject($stmt);
    }

    public function findForUpdate(int $id): ?\stdClass
    {
        if (!$this->db->inTransaction()) {
            throw new \RuntimeException('InvestmentWithdrawal::findForUpdate() requires an active transaction.');
        }

        $stmt = $this->db->prepare("
            SELECT *
            FROM investment_withdrawals
            WHERE id = ?
            LIMIT 1
            FOR UPDATE
        ");
        $stmt->execute([$id]);

        return $this->fetchObject($stmt);
    }
}