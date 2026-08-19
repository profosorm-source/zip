<?php

namespace App\Models;

use Core\Model;
use Core\Database;

class KYCVerification extends Model {
    protected static string $table = 'kyc_verifications';

    /**
     * Relations برای eager loading با allWith/loadRelations
     *
     * استفاده:
     *   // لیست KYC ها با اطلاعات کاربر
     *   $kycList = $kycModel->allWith(['user'], 50);
     *   echo $kycList[0]->user->full_name;
     *   echo $kycList[0]->user->email;
     *
     *   // یک KYC با کاربر
     *   $kyc = $kycModel->findWith($id, ['user']);
     */
    protected array $relations = [
        'user' => [\App\Models\User::class, 'user_id', 'one'],
    ];

    /**
     * ایجاد درخواست KYC جدید
     * خروجی: id یا false
     */
    /** @param array<string, mixed> $data */
    public function create(array $data): int|false
    {
        $now = \date('Y-m-d H:i:s');

        $sql = "INSERT INTO kyc_verifications (
                    user_id, verification_image, national_code, national_code_hash, birth_date, status,
                    ip_address, user_agent, device_fingerprint,
                    encryption_version, encryption_algorithm,
                    submitted_at, created_at, updated_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";

        $stmt = $this->db->query($sql, [
            int_value($data['user_id']),
            str_value($data['verification_image']),
            $data['national_code'] ?? null,
            $data['national_code_hash'] ?? null,
            $data['birth_date'] ?? null,
            $data['status'] ?? 'pending',
            $data['ip_address'] ?? null,
            $data['user_agent'] ?? null,
            $data['device_fingerprint'] ?? null,
            $data['encryption_version'] ?? 2,
            $data['encryption_algorithm'] ?? 'AES-256-GCM',
            $now,
            $now,
            $now,
        ]);

        return (int)$this->db->lastInsertId();
    }

    /**
     * یافتن KYC بر اساس ID
     */
    public function find(int $id): ?\stdClass
    {
        $stmt = $this->db->query("SELECT * FROM kyc_verifications WHERE id = ? LIMIT 1", [$id]);
        $row = $stmt->fetch(\PDO::FETCH_OBJ) ?: false;
        return $this->normalizeToObject($row);
    }

    /**
     * یافتن KYC بر اساس ID با قفل تراکنشی
     */
    public function findForUpdate(int $id): ?\stdClass
    {
        $stmt = $this->db->query("SELECT * FROM kyc_verifications WHERE id = ? FOR UPDATE", [$id]);
        $row = $stmt->fetch(\PDO::FETCH_OBJ) ?: false;
        return $this->normalizeToObject($row);
    }

    /**
     * یافتن KYC بر اساس user_id
     */
    public function findByUserId(int $userId): ?\stdClass
    {
        $stmt = $this->db->query(
            "SELECT * FROM kyc_verifications WHERE user_id = ? ORDER BY id DESC LIMIT 1",
            [$userId]
        );
        $row = $stmt->fetch(\PDO::FETCH_OBJ) ?: false;
        return $this->normalizeToObject($row);
    }

    /**
     * بروزرسانی وضعیت KYC
     */
    public function updateStatus(int $id, string $status, ?string $reason = null): bool
    {
        $data = [
            'status' => $status,
            'reviewed_at' => \date('Y-m-d H:i:s'),
            'updated_at' => \date('Y-m-d H:i:s'),
        ];

        if ($status === 'verified') {
            $data['verified_at'] = \date('Y-m-d H:i:s');
            $data['expires_at'] = \date('Y-m-d H:i:s', \strtotime('+1 year'));
            $data['rejection_reason'] = null;
        }

        if ($status === 'rejected') {
            $data['rejection_reason'] = $reason;
        }

        return $this->update($id, $data);
    }

    /**
     * بروزرسانی عمومی
     */
    /** @param array<string, mixed> $data */
    public function update(int $id, array $data): bool
    {
        if (empty($data)) {
            return false;
        }

        $fields = [];
        $values = [];

        foreach ((array)$data as $key => $value) {
            $fields[] = "{$key} = ?";
            $values[] = $value;
        }

        $values[] = $id;

        $sql = "UPDATE kyc_verifications SET " . \implode(', ', $fields) . " WHERE id = ?";

        $stmt = $this->db->query($sql, $values);
        return $stmt->rowCount() > 0;
    }

    /**
     * BUGFIX-CTRL-RAW-SQL-2026-06.
     *
     * Atomic concurrency lock for KYC review (H-2 requirement).
     *
     * The KYCController previously issued a hand-written UPDATE with three
     * branches to allow:
     *   1. taking the lock when no one else holds it (`under_review_by IS NULL`)
     *   2. refreshing the lock you already own (`under_review_by = ?`)
     *   3. stealing a stale lock older than $staleMinutes
     *
     * We move that SQL into the model so the controller stays thin and
     * the rule "models own DB access" is enforced. The method returns
     * true only when the lock was successfully (re)acquired by this admin
     * — equivalent to the old `rowCount() > 0` check.
     */
    public function lockForReview(int $id, int $adminId, int $staleMinutes = 30): bool
    {
        $stmt = $this->db->query(
            "UPDATE kyc_verifications
                SET under_review_by  = ?,
                    review_started_at = NOW(),
                    status            = 'under_review'
              WHERE id = ?
                AND (
                    under_review_by IS NULL
                 OR under_review_by = ?
                 OR review_started_at < DATE_SUB(NOW(), INTERVAL ? MINUTE)
                )",
            [$adminId, $id, $adminId, $staleMinutes]
        );
        return $stmt->rowCount() > 0;
    }

    /**
     * BUGFIX-CTRL-RAW-SQL-2026-06.
     *
     * Delete every kyc_documents row attached to a verification. Used by
     * KYCController::deleteImage() right after the corresponding files
     * are wiped from disk by KYCService::deleteVerificationImage(). The
     * controller used to inline the DELETE; we encapsulate it here so the
     * documents-table column reference lives in the model layer only.
     */
    public function deleteDocuments(int $kycId): bool
    {
        $stmt = $this->db->query(
            'DELETE FROM kyc_documents WHERE kyc_id = ?',
            [$kycId]
        );
        return $stmt->rowCount() > 0;
    }

    /**
     * دریافت لیست KYC با فیلتر + صف‌بندی
     */
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

        $sql = "SELECT k.*, u.full_name, u.email
                FROM kyc_verifications k
                JOIN users u ON k.user_id = u.id
                WHERE 1=1";

        $params = [];

        if (!empty($filters['status'])) {
            $sql .= " AND k.status = ?";
            $params[] = $filters['status'];
        }

        if (!empty($filters['search'])) {
            $sql .= " AND (u.full_name LIKE ? OR u.email LIKE ? OR k.national_code LIKE ?)";
            $search = '%' . $filters['search'] . '%';
            $params[] = $search;
            $params[] = $search;
            $params[] = $search;
        }

        // صف بررسی + آرشیو
        $sql .= "
            ORDER BY
                CASE WHEN k.status IN ('pending','under_review') THEN 0 ELSE 1 END ASC,
                CASE WHEN k.status IN ('pending','under_review') THEN k.created_at END ASC,
                CASE WHEN k.status NOT IN ('pending','under_review')
                    THEN IFNULL(k.reviewed_at, k.created_at) END DESC,
                k.created_at DESC
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

    /**
     * شمارش کل KYC
     */
    /** @param array<string, mixed> $filters */
    public function count(array $filters = []): int
    {
        $sql = "SELECT COUNT(*) as total
                FROM kyc_verifications k
                JOIN users u ON k.user_id = u.id
                WHERE 1=1";

        $params = [];

        if (!empty($filters['status'])) {
            $sql .= " AND k.status = ?";
            $params[] = $filters['status'];
        }

        if (!empty($filters['search'])) {
            $sql .= " AND (u.full_name LIKE ? OR u.email LIKE ? OR k.national_code LIKE ?)";
            $search = '%' . $filters['search'] . '%';
            $params[] = $search;
            $params[] = $search;
            $params[] = $search;
        }

        $stmt = $this->db->query($sql, $params);
        $row = $this->fetchObject($stmt);

        return (int)($row->total ?? 0);
    }

    /**
     * به‌روزرسانی فیلد تصویر در دیتابیس به وضعیت حذف شده
     */
    public function updateImageStatusToDeleted(int $id): bool
    {
        return $this->update($id, [
            'verification_image' => '[DELETED]',
            'documents_deleted' => 1,
            'updated_at' => \date('Y-m-d H:i:s'),
        ]);
    }

    /** @return list<array<string, mixed>> */
    public function getOldRejected(int $days = 60): array
    {
        $sql = "SELECT id, document_front, document_back, selfie
                FROM kyc_verifications
                WHERE status = 'rejected'
                  AND updated_at < DATE_SUB(NOW(), INTERVAL ? DAY)
                  AND documents_deleted = 0";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([$days]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    // ==================== ANALYTICS METHODS ====================

    /**
     * آمار KYC
     */
    /** @return array<string, mixed> */
    public function getKycStats(): array
    {
        $row = $this->db->fetch("
            SELECT
                SUM(CASE WHEN status = 'verified' THEN 1 ELSE 0 END) as verified,
                SUM(CASE WHEN status IN ('pending','under_review') THEN 1 ELSE 0 END) as pending
            FROM kyc_verifications
        ");
        return [
            'verified' => (int)($row->verified ?? 0),
            'pending' => (int)($row->pending ?? 0),
        ];
    }
}