<?php

namespace App\Models;

use Core\Model;
use Core\Database;

class UserLevelHistory extends Model {
    protected static string $table = 'user_level_history';
    /**
     * تولید امضای دیجیتال برای امنیت و عدم دستکاری تاریخچه تغییرات
     */
    public function generateSignature(int $userId, ?string $fromLevel, string $toLevel, string $changeType, ?string $reason, ?string $metadata, ?string $ipAddress): string
    {
        $payload = \implode('|', [
            $userId,
            $fromLevel ?? '',
            $toLevel,
            $changeType,
            $reason ?? '',
            $metadata ?? '',
            $ipAddress ?? '',
        ]);
        return \hash_hmac('sha256', $payload, \secure_key());
    }

    /**
     * اعتبارسنجی امضای دیجیتال تاریخچه تغییر سطح
     */
    public function verifySignature(\stdClass $row): bool
    {
        if (empty($row->signature)) {
            return false;
        }
        $expected = $this->generateSignature(
            (int)$row->user_id,
            $row->from_level,
            $row->to_level,
            $row->change_type,
            $row->reason,
            $row->metadata,
            $row->ip_address
        );
        return \hash_equals($expected, $row->signature);
    }

    /**
     * ثبت تغییر سطح
     * @param array<string, mixed> $data
     */
    public function createHistory(array $data): ?\stdClass
    {
        $userId = is_scalar($data['user_id'] ?? null) ? (int)$data['user_id'] : 0;
        $fromLevel = isset($data['from_level']) && is_scalar($data['from_level']) ? (string)$data['from_level'] : null;
        $toLevel = isset($data['to_level']) && is_scalar($data['to_level']) ? (string)$data['to_level'] : '';
        $changeType = isset($data['change_type']) && is_scalar($data['change_type']) ? (string)$data['change_type'] : '';
        if ($toLevel === '' || $changeType === '') {
            throw new \InvalidArgumentException('to_level and change_type are required scalar values');
        }
        $reason = isset($data['reason']) && is_scalar($data['reason']) ? (string)$data['reason'] : null;
        $metadata = isset($data['metadata']) ? (string)\json_encode($data['metadata'], JSON_UNESCAPED_UNICODE) : null;
        $ipAddress = isset($data['ip_address']) && is_scalar($data['ip_address']) ? (string)$data['ip_address'] : '127.0.0.1';

        $signature = $this->generateSignature($userId, $fromLevel, $toLevel, $changeType, $reason, $metadata, $ipAddress);

        $stmt = $this->db->prepare("
            INSERT INTO user_level_history 
            (user_id, from_level, to_level, change_type, reason, metadata, ip_address, signature)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");

        $result = $stmt->execute([
            $userId,
            $fromLevel,
            $toLevel,
            $changeType,
            $reason,
            $metadata,
            $ipAddress,
            $signature,
        ]);

        if (!$result) return null;
        return $this->find((int) $this->db->lastInsertId());
    }

    /**
     * یافتن
     */
    public function find(int $id): ?\stdClass
    {
        $stmt = $this->db->prepare("SELECT * FROM user_level_history WHERE id = ?");
        $stmt->execute([$id]);
        $result = $stmt->fetch(\PDO::FETCH_OBJ);
        if ($result instanceof \stdClass) {
            $result->is_valid = $this->verifySignature($result);
            return $result;
        }
        return null;
    }

    /**
     * تاریخچه کاربر
     * @return list<\stdClass>
     */
    public function getByUser(int $userId, int $limit = 20, int $offset = 0): array
    {
        $stmt = $this->db->prepare("
            SELECT h.*,
                   fl.name AS from_level_name,
                   tl.name AS to_level_name
            FROM user_level_history h
            LEFT JOIN user_levels fl ON fl.slug = h.from_level
            LEFT JOIN user_levels tl ON tl.slug = h.to_level
            WHERE h.user_id = ?
            ORDER BY h.created_at DESC
            LIMIT ? OFFSET ?
        ");
        $stmt->execute([$userId, $limit, $offset]);
        $rows = $stmt->fetchAll(\PDO::FETCH_OBJ);
        foreach ($rows as $row) {
            $row->is_valid = $this->verifySignature($row);
        }
        return $rows;
    }

    /**
     * لیست ادمین
     * @param array<string, mixed> $filters
     * @return list<\stdClass>
     */
    public function adminList(array $filters = [], int $limit = 30, int $offset = 0): array
    {
        $where = ["1=1"];
        $params = [];

        if (!empty($filters['user_id'])) {
            $where[] = "h.user_id = ?";
            $params[] = $filters['user_id'];
        }
        if (!empty($filters['change_type'])) {
            $where[] = "h.change_type = ?";
            $params[] = $filters['change_type'];
        }
        if (!empty($filters['to_level'])) {
            $where[] = "h.to_level = ?";
            $params[] = $filters['to_level'];
        }

        $whereStr = \implode(' AND ', $where);

        $stmt = $this->db->prepare("
            SELECT h.*, 
                   u.full_name AS user_name,
                   u.email AS user_email,
                   fl.name AS from_level_name,
                   tl.name AS to_level_name
            FROM user_level_history h
            LEFT JOIN users u ON u.id = h.user_id
            LEFT JOIN user_levels fl ON fl.slug = h.from_level
            LEFT JOIN user_levels tl ON tl.slug = h.to_level
            WHERE {$whereStr}
            ORDER BY h.created_at DESC
            LIMIT ? OFFSET ?
        ");
        $params[] = $limit;
        $params[] = $offset;
        $stmt->execute($params);
        $rows = $stmt->fetchAll(\PDO::FETCH_OBJ);
        foreach ($rows as $row) {
            $row->is_valid = $this->verifySignature($row);
        }
        return $rows;
    }

    /**
     * تعداد
     * @param array<string, mixed> $filters
     */
    public function adminCount(array $filters = []): int
    {
        $where = ["1=1"];
        $params = [];

        if (!empty($filters['user_id'])) {
            $where[] = "h.user_id = ?";
            $params[] = $filters['user_id'];
        }
        if (!empty($filters['change_type'])) {
            $where[] = "h.change_type = ?";
            $params[] = $filters['change_type'];
        }
        if (!empty($filters['to_level'])) {
            $where[] = "h.to_level = ?";
            $params[] = $filters['to_level'];
        }

        $whereStr = \implode(' AND ', $where);

        $stmt = $this->db->prepare("SELECT COUNT(*) FROM user_level_history h WHERE {$whereStr}");
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    }
}
