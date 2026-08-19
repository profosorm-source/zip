<?php
declare(strict_types=1);

namespace App\Models;

use Core\Model;

/**
 * MessageModerationModel
 */
class MessageModerationModel extends Model
{
    /**
     * نام جدول (الزام قرارداد Core\\Model).
     * این مدل عمدتاً از کوئری‌های خام استفاده می‌کند، اما برای سازگاری با
     * متدهای پایه‌ی Model، جدول مرجع آن تعریف می‌شود.
     */
    protected static string $table = 'direct_messages';

    /**
     * دریافت پیام‌های یک کاربر برای بررسی تاریخچه (بدون اطلاعات حساس طرف مقابل)
     */
    /** @return list<\stdClass> */
    public function getUserMessages(int $senderId, int $limit = 10): array
    {
        $sql = "SELECT id, message, created_at, is_encrypted 
                FROM direct_messages 
                WHERE sender_id = ? 
                ORDER BY created_at DESC 
                LIMIT ?";
        
        return $this->db->fetchAll($sql, [$senderId, $limit]);
    }

    /**
     * بروزرسانی وضعیت گزارش
     */
    public function updateReportStatus(int $reportId, string $status, int $adminId): bool
    {
        $sql = "UPDATE message_reports 
                SET status = ?, admin_id = ?, updated_at = NOW() 
                WHERE id = ?";
        
        return (bool)$this->db->query($sql, [$status, $adminId, $reportId]);
    }

    /**
     * دریافت کاربران مسدود شده
     */
    /** @return list<array<string, mixed>> */
    public function getBlockedUsers(int $limit, int $offset): array
    {
        $sql = "SELECT ub.blocker_id, ub.blocked_id,
                       blocker.full_name as blocker_name, blocker.email as blocker_email,
                       blocked.full_name as blocked_name, blocked.email as blocked_email
                FROM user_blocks ub
                JOIN users blocker ON ub.blocker_id = blocker.id
                JOIN users blocked ON ub.blocked_id = blocked.id
                ORDER BY ub.blocker_id DESC
                LIMIT ? OFFSET ?";
        
        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(1, $limit, \PDO::PARAM_INT);
        $stmt->bindValue(2, $offset, \PDO::PARAM_INT);
        $stmt->execute();
        
        return $this->fetchAssocList($stmt);
    }
}
