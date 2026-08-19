<?php

namespace App\Models;

use Core\Model;
use Core\Database;

class ReferralCommission extends Model {
    protected static string $table = 'referral_commissions';

    /** @param array<int|string, mixed> $params */
    private function fetchOne(string $sql, array $params = []): ?\stdClass
    {
        $stmt = $this->db->query($sql, $params);
        
        return $this->fetchObject($stmt);
    }

    /**
     * @param array<int|string, mixed> $params
     * @return list<\stdClass>
     */
    private function fetchAllRows(string $sql, array $params = []): array
    {
        $stmt = $this->db->query($sql, $params);
        
        return $stmt->fetchAll(\PDO::FETCH_OBJ);
    }

    /** @param array<int|string, mixed> $params */
    private function fetchColumnValue(string $sql, array $params = []): mixed
    {
        $stmt = $this->db->query($sql, $params);
        
        return $stmt->fetchColumn();
    }

    public function find(int $id): ?\stdClass
    {
        $sql = "
            SELECT rc.*,
                referrer.full_name AS referrer_name,
                referrer.email AS referrer_email,
                referred.full_name AS referred_name,
                referred.email AS referred_email
            FROM referral_commissions rc
            LEFT JOIN users referrer ON referrer.id = rc.referrer_id
            LEFT JOIN users referred ON referred.id = rc.referred_user_id
            WHERE rc.id = ?
        ";

        return $this->fetchOne($sql, [$id]);
    }

    public function findByIdempotencyKey(string $key): ?\stdClass
    {
        $sql = "SELECT * FROM referral_commissions WHERE idempotency_key = ? LIMIT 1";
        return $this->fetchOne($sql, [$key]);
    }

    /** @param array<string, mixed> $data */
    public function createCommission(array $data): ?\stdClass
    {
        $now = \date('Y-m-d H:i:s');

        $sql = "
            INSERT INTO referral_commissions
            (referrer_id, referred_user_id, referred_id, amount, source_type, commission_amount, currency, status,
             idempotency_key, context, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ";

        $metadataJson = null;
        if (isset($data['context'])) {
            $metadataJson = \is_array($data['context'])
                ? \json_encode($data['context'], JSON_UNESCAPED_UNICODE)
                : str_value($data['context']);
        }

        $ok = $this->db->query($sql, [
            int_value($data['referrer_id'] ?? 0),
            int_value($data['referred_user_id'] ?? 0),
            int_value($data['referred_user_id'] ?? 0),
            str_value($data['amount'] ?? 0),
            str_value($data['source_type'] ?? 'unknown'),
            str_value($data['commission_amount'] ?? 0),
            str_value($data['currency'] ?? 'irt'),
            str_value($data['status'] ?? 'pending'),
            str_value($data['idempotency_key'] ?? ''),
            $metadataJson,
            $now,
            $now,
        ]);

        $id = (int)$this->db->lastInsertId();
        return $id > 0 ? $this->find($id) : null;
    }

    public function updateStatus(int $id, string $status, ?string $transactionId = null): bool
    {
        $paidAt = ($status === 'paid') ? \date('Y-m-d H:i:s') : null;

        $sql = "
            UPDATE referral_commissions
            SET status = ?, updated_at = NOW()
            WHERE id = ?
        ";

        $stmt = $this->db->query($sql, [$status, $id]);
        return $stmt->rowCount() > 0;
    }

    /**
     * @param array<string, mixed> $filters
     * @return list<\stdClass>
     */
    public function getByReferrer(int $referrerId, array $filters = [], int $limit = 20, int $offset = 0): array
    {
        $limit  = \max(1, (int)$limit);
        $offset = \max(0, (int)$offset);

        $where = ["rc.referrer_id = ?"];
        $params = [$referrerId];

        if (!empty($filters['status'])) {
            $where[] = "rc.status = ?";
            $params[] = $filters['status'];
        }

        if (!empty($filters['source_type'])) {
            $where[] = "rc.source_type = ?";
            $params[] = $filters['source_type'];
        }

        if (!empty($filters['currency'])) {
            $where[] = "rc.currency = ?";
            $params[] = $filters['currency'];
        }

        if (!empty($filters['date_from'])) {
            $where[] = "rc.created_at >= ?";
            $params[] = $filters['date_from'] . ' 00:00:00';
        }

        if (!empty($filters['date_to'])) {
            $where[] = "rc.created_at <= ?";
            $params[] = $filters['date_to'] . ' 23:59:59';
        }

        $whereStr = \implode(' AND ', $where);

        $sql = "
            SELECT rc.*,
                referred.full_name AS referred_name,
                referred.email AS referred_email
            FROM referral_commissions rc
            LEFT JOIN users referred ON referred.id = rc.referred_user_id
            WHERE {$whereStr}
            ORDER BY rc.created_at DESC
            LIMIT ? OFFSET ?
        ";

        $stmt = $this->db->prepare($sql);
        $index = 1;
        foreach ($params as $val) {
            $stmt->bindValue($index++, $val);
        }
        $stmt->bindValue($index++, $limit, \PDO::PARAM_INT);
        $stmt->bindValue($index++, $offset, \PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(\PDO::FETCH_OBJ);
    }

    /** @param array<string, mixed> $filters */
    public function countByReferrer(int $referrerId, array $filters = []): int
    {
        $where = ["referrer_id = ?"];
        $params = [$referrerId];

        if (!empty($filters['status'])) {
            $where[] = "status = ?";
            $params[] = $filters['status'];
        }

        if (!empty($filters['source_type'])) {
            $where[] = "source_type = ?";
            $params[] = $filters['source_type'];
        }

        if (!empty($filters['currency'])) {
            $where[] = "currency = ?";
            $params[] = $filters['currency'];
        }

        $whereStr = \implode(' AND ', $where);
        $sql = "SELECT COUNT(*) FROM referral_commissions WHERE {$whereStr}";

        return int_value($this->fetchColumnValue($sql, $params) ?? 0);
    }

    public function getReferrerStats(int $referrerId): ?\stdClass
    {
        $sql = "
            SELECT
                COALESCE(SUM(CASE WHEN currency='irt' AND status='paid' THEN commission_amount ELSE 0 END), 0) AS total_earned_irt,
                COALESCE(SUM(CASE WHEN currency='usdt' AND status='paid' THEN commission_amount ELSE 0 END), 0) AS total_earned_usdt,
                COALESCE(SUM(CASE WHEN currency='irt' AND status='pending' THEN commission_amount ELSE 0 END), 0) AS pending_irt,
                COALESCE(SUM(CASE WHEN currency='usdt' AND status='pending' THEN commission_amount ELSE 0 END), 0) AS pending_usdt,
                COUNT(CASE WHEN status='paid' THEN 1 END) AS paid_count,
                COUNT(CASE WHEN status='pending' THEN 1 END) AS pending_count,
                COUNT(*) AS total_count
            FROM referral_commissions
            WHERE referrer_id = ?
        ";

        return $this->fetchOne($sql, [$referrerId]);
    }

    /** @return list<\stdClass> */
    public function getReferredUsers(int $referrerId, int $limit = 20, int $offset = 0): array
    {
        $limit  = \max(1, (int)$limit);
        $offset = \max(0, (int)$offset);

        $sql = "
            SELECT
                u.id,
                u.full_name,
                u.email,
                u.created_at AS joined_at,
                u.status,
                COALESCE(SUM(CASE WHEN rc.currency='irt' AND rc.status='paid' THEN rc.commission_amount ELSE 0 END), 0) AS earned_irt,
                COALESCE(SUM(CASE WHEN rc.currency='usdt' AND rc.status='paid' THEN rc.commission_amount ELSE 0 END), 0) AS earned_usdt,
                COUNT(rc.id) AS commission_count
            FROM users u
            LEFT JOIN referral_commissions rc
                ON rc.referred_user_id = u.id AND rc.referrer_id = ?
            WHERE u.referred_by = ? AND u.deleted_at IS NULL
            GROUP BY u.id
            ORDER BY u.created_at DESC
            LIMIT ? OFFSET ?
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(1, $referrerId, \PDO::PARAM_INT);
        $stmt->bindValue(2, $referrerId, \PDO::PARAM_INT);
        $stmt->bindValue(3, $limit, \PDO::PARAM_INT);
        $stmt->bindValue(4, $offset, \PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(\PDO::FETCH_OBJ);
    }

    public function countReferredUsers(int $referrerId): int
    {
        $sql = "SELECT COUNT(*) FROM users WHERE referred_by = ? AND deleted_at IS NULL";
        return int_value($this->fetchColumnValue($sql, [$referrerId]) ?? 0);
    }

    public function todaySignupCount(int $referrerId): int
    {
        $sql = "
            SELECT COUNT(*) FROM users
            WHERE referred_by = ?
              AND DATE(created_at) = CURDATE()
              AND deleted_at IS NULL
        ";
        return int_value($this->fetchColumnValue($sql, [$referrerId]) ?? 0);
    }

    public function todaySignupCountByIp(string $ip): int
    {
        $sql = "
            SELECT COUNT(*) FROM referral_activity_logs
            WHERE action = 'signup'
              AND ip_address = ?
              AND DATE(created_at) = CURDATE()
        ";
        return int_value($this->fetchColumnValue($sql, [$ip]) ?? 0);
    }

    /**
     * @param array<string, mixed> $filters
     * @return list<\stdClass>
     */
    public function adminList(array $filters = [], int $limit = 30, int $offset = 0): array
    {
        $limit  = \max(1, (int)$limit);
        $offset = \max(0, (int)$offset);

        $sql = "SELECT rc.*,
                   ref.full_name AS referrer_name, ref.email AS referrer_email,
                   r.full_name AS referred_name, r.email AS referred_email
            FROM referral_commissions rc
            LEFT JOIN users ref ON ref.id = rc.referrer_id
            LEFT JOIN users r   ON r.id   = rc.referred_user_id
            WHERE 1=1";

        $params = [];

        if (!empty($filters['status'])) {
            $sql .= " AND rc.status = ?";
            $params[] = $filters['status'];
        }
        if (!empty($filters['source_type'])) {
            $sql .= " AND rc.source_type = ?";
            $params[] = $filters['source_type'];
        }
        if (!empty($filters['currency'])) {
            $sql .= " AND rc.currency = ?";
            $params[] = $filters['currency'];
        }
        if (!empty($filters['search'])) {
            $sql .= " AND (
                ref.full_name LIKE ? OR ref.email LIKE ?
                OR r.full_name LIKE ? OR r.email LIKE ?
                OR rc.idempotency_key LIKE ?
            )";
            $s = '%' . $filters['search'] . '%';
            $params[] = $s; $params[] = $s; $params[] = $s; $params[] = $s; $params[] = $s;
        }

        $sql .= " ORDER BY rc.created_at DESC LIMIT ? OFFSET ?";

        $stmt = $this->db->prepare($sql);
        $index = 1;
        foreach ($params as $val) {
            $stmt->bindValue($index++, $val);
        }
        $stmt->bindValue($index++, $limit, \PDO::PARAM_INT);
        $stmt->bindValue($index++, $offset, \PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(\PDO::FETCH_OBJ);
    }

    /** @param array<string, mixed> $filters */
    public function adminCount(array $filters = []): int
    {
        $sql = "SELECT COUNT(*) as total
                FROM referral_commissions rc
                LEFT JOIN users ref ON ref.id = rc.referrer_id
                LEFT JOIN users r   ON r.id = rc.referred_user_id
                WHERE 1=1";

        $params = [];

        if (!empty($filters['status'])) {
            $sql .= " AND rc.status = ?";
            $params[] = $filters['status'];
        }
        if (!empty($filters['source_type'])) {
            $sql .= " AND rc.source_type = ?";
            $params[] = $filters['source_type'];
        }
        if (!empty($filters['currency'])) {
            $sql .= " AND rc.currency = ?";
            $params[] = $filters['currency'];
        }
        if (!empty($filters['search'])) {
            $sql .= " AND (
                ref.full_name LIKE ? OR ref.email LIKE ?
                OR r.full_name LIKE ? OR r.email LIKE ?
                OR rc.idempotency_key LIKE ?
            )";
            $s = '%' . $filters['search'] . '%';
            $params[] = $s; $params[] = $s; $params[] = $s; $params[] = $s; $params[] = $s;
        }

        $stmt = $this->db->query($sql, $params);
        $row = $this->fetchObject($stmt);

        return (int)($row->total ?? 0);
    }

    public function globalStats(): object
    {
        $stmt = $this->db->query("
            SELECT
              COUNT(*) as total,
              SUM(CASE WHEN status='pending' THEN 1 ELSE 0 END) as pending_count,
              SUM(CASE WHEN status='paid' THEN 1 ELSE 0 END) as paid_count,
              COALESCE(SUM(CASE WHEN currency='irt'  AND status='paid' THEN commission_amount ELSE 0 END),0) as total_paid_irt,
              COALESCE(SUM(CASE WHEN currency='usdt' AND status='paid' THEN commission_amount ELSE 0 END),0) as total_paid_usdt
            FROM referral_commissions
        ");

        return $this->fetchObject($stmt) ?? new \stdClass();
    }

    /** @return list<\stdClass> */
    public function topReferrers(string $currency = 'irt', int $limit = 5): array
    {
        $limit = \max(1, (int)$limit);

        $stmt = $this->db->prepare("
            SELECT u.id, u.full_name, u.email,
                   COALESCE(SUM(rc.commission_amount),0) as total_commission
            FROM referral_commissions rc
            JOIN users u ON u.id = rc.referrer_id
            WHERE rc.status='paid' AND rc.currency = ?
            GROUP BY u.id
            ORDER BY total_commission DESC
            LIMIT ?
        ");
        $stmt->bindValue(1, $currency);
        $stmt->bindValue(2, $limit, \PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(\PDO::FETCH_OBJ);
    }

    /** @return list<\stdClass> */
    public function getReferralTrend(int $userId, int $days): array
    {
        $sql = "SELECT DATE(created_at) as date, COUNT(*) as count, SUM(commission_amount) as total_commission
                FROM referral_commissions
                WHERE referrer_id = ? AND created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
                GROUP BY DATE(created_at) ORDER BY date ASC";
        return $this->fetchAllRows($sql, [$userId, $days]);
    }

    public function getConversionRate(int $userId, int $days): ?\stdClass
    {
        $sql = "SELECT COUNT(DISTINCT referred_user_id) as converted,
                       COUNT(DISTINCT click_user_id) as clicked,
                       ROUND(100.0 * COUNT(DISTINCT referred_user_id) / NULLIF(COUNT(DISTINCT click_user_id), 0), 2) as conversion_rate
                FROM referral_clicks rc
                LEFT JOIN referral_commissions r ON rc.referred_user_id = r.referred_user_id
                WHERE rc.referrer_id = ? AND rc.created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)";
        return $this->fetchOne($sql, [$userId, $days]);
    }

    public function getIndirectEarnings(int $userId, string $currency): float
    {
        $sql = "SELECT SUM(commission_amount) as total FROM referral_commissions rc
                WHERE rc.referrer_id IN (
                   SELECT referred_user_id FROM referral_commissions WHERE referrer_id = ?
                ) AND rc.currency = ?";
        $res = $this->fetchOne($sql, [$userId, $currency]);
        return (float)($res->total ?? 0);
    }

    /** @return list<\stdClass> */
    public function getLeaderboard(int $days, int $limit): array
    {
        $sql = "SELECT u.id, u.username, COUNT(DISTINCT rc.referred_user_id) as referrals,
                       SUM(rc.commission_amount) as total_commission
                FROM users u
                LEFT JOIN referral_commissions rc ON u.id = rc.referrer_id
                WHERE rc.commission_date >= DATE_SUB(NOW(), INTERVAL ? DAY)
                GROUP BY u.id ORDER BY total_commission DESC LIMIT ?";
        
        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(1, $days, \PDO::PARAM_INT);
        $stmt->bindValue(2, $limit, \PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(\PDO::FETCH_OBJ);
    }

    public function getTopMonthlyReferrer(): ?\stdClass
    {
        $sql = "SELECT u.id, SUM(rc.commission_amount) as total FROM users u
                LEFT JOIN referral_commissions rc ON u.id = rc.referrer_id
                WHERE MONTH(rc.commission_date) = MONTH(NOW()) GROUP BY u.id ORDER BY total DESC LIMIT 1";
        return $this->fetchOne($sql);
    }
}
