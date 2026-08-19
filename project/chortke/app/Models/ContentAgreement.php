<?php

namespace App\Models;

use Core\Model;

class ContentAgreement extends Model
{
    protected static string $table = 'content_agreements';

    /**
     * ثبت تعهدنامه
     */
    /** @param array<string, mixed> $data */
    public function create(array $data): ?int
    {
        if (isset($data['submission_id']) && !isset($data['content_id'])) {
            $data['content_id'] = $data['submission_id'];
        }
        $data['accepted_at'] = $data['accepted_at'] ?? date('Y-m-d H:i:s');
        $data['agreed_at'] = $data['agreed_at'] ?? $data['accepted_at'];
        $data['is_deleted'] = $data['is_deleted'] ?? 0;

        $allowed = [
            'user_id', 'content_id', 'submission_id', 'agreement_text', 'agreed_at', 'accepted_at',
            'ip_address', 'user_agent', 'device_fingerprint', 'is_deleted', 'updated_at'
        ];
        $insert = [];
        foreach ($allowed as $field) {
            if (array_key_exists($field, $data)) {
                $insert[$field] = $data[$field];
            }
        }
        $insert['updated_at'] = $insert['updated_at'] ?? date('Y-m-d H:i:s');

        $columns = array_keys($insert);
        $placeholders = array_fill(0, count($columns), '?');
        $sql = "INSERT INTO `" . static::$table . "` (`" . implode('`,`', $columns) . "`) VALUES (" . implode(',', $placeholders) . ")";
        $stmt = $this->db->prepare($sql);
        $ok = $stmt->execute(array_values($insert));
        if (!$ok) return null;
        $id = (int)$this->db->lastInsertId();
        return $id > 0 ? $id : null;
    }

    /**
     * یافتن تعهدنامه کاربر برای یک محتوا
     */
    public function findBySubmission(int $submissionId): ?\stdClass
    {
        $row = $this->db->query(
            "SELECT * FROM content_agreements
             WHERE submission_id = ? AND is_deleted = 0
             ORDER BY accepted_at DESC LIMIT 1",
            [$submissionId]
        )->fetch();
        return $row instanceof \stdClass ? $row : null;
    }

    /**
     * تمام تعهدنامه‌های یک کاربر
     * @return list<object>
     */
    public function getByUser(int $userId): array
    {
        return $this->db->query(
            "SELECT ca.*, cs.title as video_title
             FROM content_agreements ca
             JOIN content_submissions cs ON ca.submission_id = cs.id
             WHERE ca.user_id = ? AND ca.is_deleted = 0
             ORDER BY ca.accepted_at DESC",
            [$userId]
        )->fetchAll();
    }
}
