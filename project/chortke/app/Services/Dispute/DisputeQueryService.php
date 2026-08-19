<?php

declare(strict_types=1);

namespace App\Services\Dispute;

use App\Models\Dispute;
use App\Contracts\LoggerInterface;
use Core\Database;

/**
 * سرویس Query اختلافات — فقط خواندن
 */
class DisputeQueryService
{
    /**
     * Centralized toObject (root-cause normalization for DB results).
     * Guarantees object (never array/mixed) before any ->prop access.
     */
    private function toObject(mixed $data): ?object
    {
        if ($data === null || $data === false) return null;
        if (is_object($data)) return $data;
        if (is_array($data)) return (object)$data;
        return (object)(array)$data;
    }


    private Database $db;
    private Dispute $disputeModel;

    public function __construct(Database $db, Dispute $disputeModel) {
        $this->db = $db;
        $this->disputeModel = $disputeModel;
    }

    /** @return list<\stdClass> */
    public function getUserDisputes(int $userId, int $limit = 20, int $offset = 0): array
    {
        return $this->disputeModel->getByUser($userId, $limit, $offset);
    }

    public function countUserDisputes(int $userId): int
    {
        return $this->disputeModel->countByUser($userId);
    }

    public function find(int $id): ?\stdClass
    {
        return $this->disputeModel->getSafe($id);
    }

    /** @return list<\stdClass> */
    public function getMessages(int $disputeId): array
    {
        return $this->disputeModel->getMessages($disputeId);
    }

    /**
     * لیست اختلافات custom_task یک کاربر با JOIN کامل.
     * جایگزین Raw SQL در CustomTaskController::disputes()
     */
    /** @return list<\stdClass> */
    public function getCustomTaskDisputesByUser(int $userId, int $limit = 100, int $offset = 0): array
    {
        $limit  = max(1, min(500, $limit));
        $offset = max(0, $offset);

        return $this->db->fetchAll(
            "SELECT d.*, a.title AS task_title, s.status AS submission_status,
                    u.full_name AS other_party_name
             FROM disputes d
             INNER JOIN custom_task_submissions s
                 ON s.id = d.ref_id AND d.ref_type = 'custom_task_submission'
             LEFT JOIN ads a ON a.id = s.task_id
             LEFT JOIN users u ON u.id = d.target_user_id
             WHERE d.user_id = ? OR d.target_user_id = ?
             ORDER BY d.created_at DESC
             LIMIT {$limit} OFFSET {$offset}",
            [$userId, $userId]
        ) ?: [];
    }

    /**
     * لیست یکپارچه‌ی اختلافات برای پنل ادمین — همه‌ی ref_typeها
     * (custom_task_submission، order/story_order/influencer_order/influencer، vitrine_listing)
     * با JOIN‌های مناسب و ستون‌های عام ref_title / raised_by_role.
     * اگر $refType خالی باشد همه‌ی ماژول‌ها نمایش داده می‌شوند.
     * @param array<int, mixed> $params
     * @return list<\stdClass>
     */
    public function unifiedAdminDisputeList(string $whereSql, array $params, int $limit, int $offset): array
    {
        $sql = "SELECT d.*,
                       CASE d.ref_type
                         WHEN 'custom_task_submission' THEN a.title
                         WHEN 'vitrine_listing' THEN vl.title
                         ELSE so.caption
                       END AS task_title,
                       u.full_name AS raiser_name,
                       CASE d.ref_type
                         WHEN 'custom_task_submission' THEN
                            CASE WHEN d.user_id = s.worker_id THEN 'worker' ELSE 'advertiser' END
                         WHEN 'vitrine_listing' THEN 'party'
                         ELSE 'customer'
                       END AS raised_by_role,
                       s.status AS submission_status
                FROM disputes d
                LEFT JOIN custom_task_submissions s
                    ON s.id = d.ref_id AND d.ref_type = 'custom_task_submission'
                LEFT JOIN ads a
                    ON a.id = s.task_id AND d.ref_type = 'custom_task_submission'
                LEFT JOIN story_orders so
                    ON so.id = d.ref_id AND d.ref_type IN ('order','story_order','influencer_order','influencer')
                LEFT JOIN vitrine_listings vl
                    ON vl.id = d.ref_id AND d.ref_type = 'vitrine_listing'
                LEFT JOIN users u ON u.id = d.user_id
                WHERE {$whereSql}
                ORDER BY d.created_at DESC
                LIMIT {$limit} OFFSET {$offset}";
        return $this->db->fetchAll($sql, $params) ?: [];
    }

    /** @param array<int, mixed> $params */
    public function unifiedAdminDisputeCount(string $whereSql, array $params): int
    {
        $row = $this->toObject($this->db->fetch(
            "SELECT COUNT(*) AS c
             FROM disputes d
             WHERE {$whereSql}",
            $params
        ));
        return (int)($row->c ?? 0);
    }

}
