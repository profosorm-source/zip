<?php

namespace App\Models;

use Core\Model;
use Core\Database;

class TicketMessage extends Model {
    protected static string $table = 'ticket_messages';

    protected array $fillable = ['ticket_id', 'user_id', 'message', 'attachments', 'is_admin', 'ip_address', 'created_at'];

/* -------------------------
     * Helpers (DB wrappers)
     * ------------------------- */
    /** @param array<int|string, mixed> $params */
    private function fetchOne(string $sql, array $params = []): ?\stdClass
    {
        $stmt = $this->db->query($sql, $params);
        
        $row = $stmt->fetch(\PDO::FETCH_OBJ);
        return $row instanceof \stdClass ? $row : null;
    }

    /** @return list<\stdClass> */
    /** @param array<int|string, mixed> $params */
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
    private function execBool(string $sql, array $params = []): bool
    {
        $stmt = $this->db->query($sql, $params);

        return $stmt->rowCount() > 0;
    }

    /**
     * ایجاد پیام جدید
     */
    /** @param array<string, mixed> $data */
    public function create(array $data): ?int
    {
        $sql = "INSERT INTO ticket_messages
                (ticket_id, user_id, message, attachments, is_admin, ip_address, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW())";

        $attachments = null;
        if (isset($data['attachments'])) {
            $attachments = \is_array($data['attachments'])
                ? \json_encode($data['attachments'], JSON_UNESCAPED_UNICODE)
                : str_value($data['attachments']);
        }

        $ip = \function_exists('get_client_ip') ? get_client_ip() : null;

        $ok = $this->db->query($sql, [
            int_value($data['ticket_id'] ?? 0),
            int_value($data['user_id'] ?? 0),
            str_value($data['message'] ?? ''),
            $attachments,
            (int)!empty($data['is_admin']), // 0/1
            $ip,
        ]);

        return (int)$this->db->lastInsertId();
    }

    /**
     * دریافت پیام‌های تیکت
     */
    /** @return list<\stdClass> */
    public function getByTicketId(int $ticketId): array
    {
        $sql = "SELECT tm.*, u.full_name, u.email
                FROM ticket_messages tm
                JOIN users u ON tm.user_id = u.id
                WHERE tm.ticket_id = ?
                ORDER BY tm.created_at ASC";

        return $this->fetchAllRows($sql, [(int)$ticketId]);
    }

    /**
     * پیامی که دارای پیوست با نام فایل داده‌شده است را برمی‌گرداند.
     */
    public function findByAttachmentFilename(string $filename): ?\stdClass
    {
        $filename = (string)basename($filename);
        if ($filename === '') {
            return null;
        }
        $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $filename) . '%';
        return $this->fetchOne(
            "SELECT tm.*, u.full_name, u.email
             FROM ticket_messages tm
             JOIN users u ON tm.user_id = u.id
             WHERE tm.attachments LIKE ?
             ORDER BY tm.created_at DESC
             LIMIT 1",
            [$like]
        );
    }

    /**
     * علامت‌گذاری پیام‌های طرف مقابل به عنوان خوانده شده
     * اگر viewer ادمین باشد => پیام‌های کاربر (is_admin=0) خوانده شود
     * اگر viewer کاربر باشد => پیام‌های ادمین (is_admin=1) خوانده شود
     */
    public function markAsRead(int $ticketId, bool $viewerIsAdmin = false): bool
    {
        $senderIsAdmin = $viewerIsAdmin ? 0 : 1;

        $sql = "UPDATE ticket_messages
                SET is_read = 1, read_at = NOW(), updated_at = NOW()
                WHERE ticket_id = ?
                  AND is_admin = ?
                  AND is_read = 0";

        return $this->execBool($sql, [(int)$ticketId, (int)$senderIsAdmin]);
    }

    /**
     * شمارش پیام‌های خوانده نشده
     * - برای ادمین: پیام‌های کاربران (is_admin=0) که هنوز read نشده‌اند
     * - برای کاربر: پیام‌های ادمین برای تیکت‌های خودش که هنوز read نشده‌اند
     */
    public function countUnread(int $userId, bool $forAdmin = false): int
    {
        if ($forAdmin) {
            $sql = "SELECT COUNT(*) as count
                    FROM ticket_messages tm
                    JOIN tickets t ON tm.ticket_id = t.id
                    WHERE tm.is_admin = 0
                      AND tm.is_read = 0";
            $row = $this->fetchOne($sql);
        } else {
            $sql = "SELECT COUNT(*) as count
                    FROM ticket_messages tm
                    JOIN tickets t ON tm.ticket_id = t.id
                    WHERE t.user_id = ?
                      AND tm.is_admin = 1
                      AND tm.is_read = 0";
            $row = $this->fetchOne($sql, [(int)$userId]);
        }

        return (int)($row->count ?? 0);
    }
}