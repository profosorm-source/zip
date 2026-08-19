<?php

declare(strict_types=1);

namespace App\Services\SocialTask;

use Core\Database;

/**
 * RatingService — مدیریت نظرات و امتیازات تسک‌های شبکه اجتماعی (admin facing)
 */
class RatingService
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

    public function __construct(Database $db) {
        $this->db = $db;
    }

    /** @return list<\stdClass> */
    public function getPendingReviews(int $limit, int $offset): array
    {
        return $this->db->query(
            "SELECT r.*, a.title as ad_title, u.full_name as rater_name, u.email as rater_email
             FROM ratings r
             LEFT JOIN ads a ON a.id = r.ad_id AND r.ad_type = 'social_task'
             LEFT JOIN users u ON u.id = r.user_id
             WHERE r.status = 'pending' AND r.ad_type = 'social_task'
             ORDER BY r.created_at DESC LIMIT ? OFFSET ?",
            [$limit, $offset]
        )->fetchAll(\PDO::FETCH_OBJ) ?: [];
    }

    /** @return array<string, mixed> */
    public function getReviewStats(): array
    {
        $row = $this->toObject($this->db->fetch(
            "SELECT 
                COUNT(*) as total,
                SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
                SUM(CASE WHEN status = 'approved' THEN 1 ELSE 0 END) as approved,
                SUM(CASE WHEN status = 'rejected' THEN 1 ELSE 0 END) as rejected
             FROM ratings WHERE ad_type = 'social_task'"
        ));
        return [
            'total' => (int)($row->total ?? 0),
            'pending' => (int)($row->pending ?? 0),
            'approved' => (int)($row->approved ?? 0),
            'rejected' => (int)($row->rejected ?? 0),
            'pending_reviews' => (int)($row->pending ?? 0),
            'approved_reviews' => (int)($row->approved ?? 0),
            'rejected_reviews' => (int)($row->rejected ?? 0),
        ];
    }

    public function getReviewById(int $id): ?object
    {
        return $this->db->fetch(
            "SELECT r.*, a.title as ad_title, u.full_name as rater_name, u.email as rater_email
             FROM ratings r
             LEFT JOIN ads a ON a.id = r.ad_id AND r.ad_type = 'social_task'
             LEFT JOIN users u ON u.id = r.user_id
             WHERE r.id = ? AND r.ad_type = 'social_task'",
            [$id]
        ) ?: null;
    }

    /** @return array<string, mixed> */
    public function moderateReview(int $reviewId, string $status, int $adminId): array
    {
        if (!in_array($status, ['approved', 'rejected'], true)) {
            return ['success' => false, 'message' => 'وضعیت نامعتبر'];
        }
        $this->db->query(
            "UPDATE ratings SET status = ?, moderated_by = ?, moderated_at = NOW(), updated_at = NOW() WHERE id = ?",
            [$status, $adminId, $reviewId]
        );
        return ['success' => true, 'message' => 'نظر با موفقیت ' . ($status === 'approved' ? 'تأیید' : 'رد') . ' شد'];
    }
}