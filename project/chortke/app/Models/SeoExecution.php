<?php

namespace App\Models;

use Core\Model;
use Core\Database;

/**
 * SeoExecution — اجرای تسک توسط Worker
 * جدول: seo_executions
 */
class SeoExecution extends Model
{
    protected static string $table = 'seo_executions';

    public int     $id;
    public int     $ad_id;              // seo_ads.id
    public int     $user_id;            // انجام‌دهنده
    public float   $time_score;         // 0-30
    public float   $scroll_score;       // 0-25
    public float   $interaction_score;  // 0-25
    public float   $quality_score;      // 0-20
    public float   $final_score;        // 0-100
    public float   $payout_amount;      // محاسبه شده
    public string  $status;             // started|completed|rejected|fraud
    public ?string $engagement_data;    // JSON
    public ?string $fraud_flags;        // JSON
    public string  $ip_address;
    public ?string $device_fingerprint;
    public ?string $session_id = null;
    public ?string $target_keyword = null;
    public ?string $rejection_reason = null;
    public ?string $cancel_reason = null;
    public ?int $fraud_score = null;
    public ?string $score_breakdown = null;
    public string  $started_at;
    public ?string $completed_at;
    public string  $created_at;
    public ?string $updated_at;

    // --------------------------------------------------------
    // READ
    // --------------------------------------------------------

    public function find(int $id): ?self
    {
        $stmt = $this->db->prepare("SELECT * FROM seo_executions WHERE id = ?");
        $stmt->execute([$id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ? $this->hydrate((array)$row) : null;
    }

    /** یافتن با قفل تراکنشی */
    public function findByIdForUpdate(int $id): ?self
    {
        $stmt = $this->db->prepare("SELECT * FROM seo_executions WHERE id = ? FOR UPDATE");
        $stmt->execute([$id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ? $this->hydrate((array)$row) : null;
    }

    /** پیدا کردن با تایید مالکیت */
    public function findByUser(int $id, int $userId): ?self
    {
        $stmt = $this->db->prepare(
            "SELECT * FROM seo_executions WHERE id = ? AND user_id = ?"
        );
        $stmt->execute([$id, $userId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ? $this->hydrate((array)$row) : null;
    }

    /** تاریخچه اجراها توسط یک کاربر */
    /** @return list<\stdClass> */
    public function getByUser(int $userId, int $limit = 20, int $offset = 0): array
    {
        $stmt = $this->db->prepare(
            "SELECT e.*, a.title AS ad_title, a.keyword
             FROM seo_executions e
             LEFT JOIN ads a ON a.id = e.ad_id
             WHERE e.user_id = ?
             ORDER BY e.created_at DESC
             LIMIT ? OFFSET ?"
        );
        $stmt->bindValue(1, $userId, \PDO::PARAM_INT);
        $stmt->bindValue(2, $limit, \PDO::PARAM_INT);
        $stmt->bindValue(3, $offset, \PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(\PDO::FETCH_OBJ);
    }

    /** شمارش کل اجراها توسط کاربر */
    public function countByUser(int $userId): int
    {
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) FROM seo_executions WHERE user_id = ?"
        );
        $stmt->execute([$userId]);
        return (int)$stmt->fetchColumn();
    }

    /** اجراهای امروز کاربر */
    public function countByUserToday(int $userId): int
    {
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) FROM seo_executions
             WHERE user_id = ? AND DATE(created_at) = CURDATE()"
        );
        $stmt->execute([$userId]);
        return (int)$stmt->fetchColumn();
    }

    /** بررسی تکراری بودن (یک کاربر برای یک آگهی در روز) */
    public function existsByAdAndUserToday(int $adId, int $userId): bool
    {
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) FROM seo_executions
             WHERE ad_id = ? AND user_id = ? AND execution_date = CURDATE()"
        );
        $stmt->execute([$adId, $userId]);
        return (int)$stmt->fetchColumn() > 0;
    }

    /** محدودیت ساعتی کاربر */
    public function countByUserLastHour(int $userId): int
    {
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) FROM seo_executions
             WHERE user_id = ? AND created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)"
        );
        $stmt->execute([$userId]);
        return (int)$stmt->fetchColumn();
    }

    /** محدودیت IP ساعتی */
    public function countByIPLastHour(string $ip): int
    {
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) FROM seo_executions
             WHERE ip_address = ? AND created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)"
        );
        $stmt->execute([$ip]);
        return (int)$stmt->fetchColumn();
    }

    /** آمار کاربر */
    public function getUserStats(int $userId): object
    {
        $stmt = $this->db->prepare(
            "SELECT
                COUNT(*) AS total_executions,
                COUNT(CASE WHEN status = 'completed' THEN 1 END) AS completed,
                COALESCE(SUM(CASE WHEN status = 'completed' THEN payout_amount ELSE 0 END), 0) AS total_earned,
                COALESCE(AVG(CASE WHEN status = 'completed' THEN final_score END), 0) AS avg_score
             FROM seo_executions
             WHERE user_id = ?"
        );
        $stmt->execute([$userId]);
        return $this->fetchObject($stmt) ?? (object)[
            'total_executions' => 0,
            'completed' => 0,
            'total_earned' => 0,
            'avg_score' => 0
        ];
    }

    /** آمار آگهی برای تبلیغ‌دهنده */
    public function getAdStats(int $adId): object
    {
        $stmt = $this->db->prepare(
            "SELECT
                COUNT(*) AS total_executions,
                COUNT(CASE WHEN status = 'completed' THEN 1 END) AS completed,
                COALESCE(SUM(CASE WHEN status = 'completed' THEN payout_amount ELSE 0 END), 0) AS total_spent,
                COALESCE(AVG(CASE WHEN status = 'completed' THEN final_score END), 0) AS avg_score,
                COUNT(CASE WHEN fraud_flags IS NOT NULL THEN 1 END) AS fraud_count
             FROM seo_executions
             WHERE ad_id = ?"
        );
        $stmt->execute([$adId]);
        return $this->fetchObject($stmt) ?? (object)[
            'total_executions' => 0,
            'completed' => 0,
            'total_spent' => 0,
            'avg_score' => 0,
            'fraud_count' => 0
        ];
    }

    // --------------------------------------------------------
    // WRITE
    // --------------------------------------------------------

    /** @param array<string, mixed> $d */
    public function createExecution(array $d): ?self
    {
        $stmt = $this->db->prepare(
            "INSERT INTO seo_executions
             (ad_id, user_id, status, ip_address, device_fingerprint, session_id, target_keyword, started_at, execution_date)
             VALUES (?, ?, 'started', ?, ?, ?, ?, NOW(), CURDATE())"
        );
        
        $ok = $stmt->execute([
            int_value($d['ad_id'] ?? 0),
            int_value($d['user_id'] ?? 0),
            $d['ip_address'] ?? get_client_ip(),
            $d['device_fingerprint'] ?? null,
            $d['session_id'] ?? null,
            $d['target_keyword'] ?? null,
        ]);

        return $ok ? $this->find((int)$this->db->lastInsertId()) : null;
    }

    /**
     * Server-authoritative seconds elapsed since the execution started.
     * Uses the DB clock (TIMESTAMPDIFF vs NOW()) so it is timezone-safe and cannot
     * be influenced by the client. Root fix (H-10).
     */
    public function secondsSinceStart(int $id): int
    {
        $stmt = $this->db->prepare(
            "SELECT TIMESTAMPDIFF(SECOND, started_at, NOW()) FROM seo_executions WHERE id = ?"
        );
        $stmt->execute([$id]);
        $val = $stmt->fetchColumn();
        return ($val === false || $val === null) ? 0 : max(0, (int)$val);
    }

    /** تکمیل اجرا با امتیازها */
    /** @param array<string, mixed> $scores */
    public function complete(int $id, array $scores, string $payout): bool
    {
        $engagementData = is_array($scores['engagement_data'] ?? null) ? $scores['engagement_data'] : [];
        $this->validateEngagementData($engagementData);

        $stmt = $this->db->prepare(
            "UPDATE seo_executions
             SET time_score = ?,
                 scroll_score = ?,
                 interaction_score = ?,
                 quality_score = ?,
                 final_score = ?,
                 payout_amount = ?,
                 engagement_data = ?,
                 score_breakdown = ?,
                 fraud_score = ?,
                 client_mode = ?,
                 status = 'completed',
                 completed_at = NOW(),
                 updated_at = NOW()
             WHERE id = ? AND status = 'started'"
        );

        return $stmt->execute([
            float_value($scores['time_score'] ?? 0),
            float_value($scores['scroll_score'] ?? 0),
            float_value($scores['interaction_score'] ?? 0),
            float_value($scores['quality_score'] ?? 0),
            float_value($scores['final_score'] ?? 0),
            str_value($payout),
            json_encode($engagementData, JSON_UNESCAPED_UNICODE),
            json_encode($scores, JSON_UNESCAPED_UNICODE),
            int_value($scores['fraud_score'] ?? 0),
            str_value($engagementData['client_mode'] ?? 'web'),
            $id
        ]);
    }

    /** @param array<string, mixed> $data */
    private function validateEngagementData(array $data): void
    {
        $required = ['duration', 'scroll_depth', 'interactions'];
        foreach ($required as $field) {
            if (!isset($data[$field]) || !is_numeric($data[$field])) {
                throw new \InvalidArgumentException("Missing or invalid {$field}");
            }
        }
        
        // Range validation
        if ($data['duration'] < 0 || $data['duration'] > 3600) {
            throw new \InvalidArgumentException('Invalid duration');
        }
        
        if ($data['scroll_depth'] < 0 || $data['scroll_depth'] > 100) {
            throw new \InvalidArgumentException('Invalid scroll_depth');
        }
    }

    /** علامت‌گذاری به عنوان تقلب */
    /** @param array<string, mixed> $flags */
    public function markAsFraud(int $id, array $flags): bool
    {
        // L-26 Fix: guard روی وضعیت تا برچسب‌گذاری تکراری audit/score را چندبار تغییر ندهد (idempotent).
        $stmt = $this->db->prepare(
            "UPDATE seo_executions
             SET status = 'fraud',
                 fraud_flags = ?,
                 fraud_score = 100,
                 updated_at = NOW()
             WHERE id = ? AND status <> 'fraud'"
        );

        $stmt->execute([json_encode($flags), $id]);
        // فقط وقتی true که رکورد واقعاً برای اولین بار fraud شده باشد.
        return $stmt->rowCount() > 0;
    }

    /** رد شدن */
    public function reject(int $id, string $reason): bool
    {
        $stmt = $this->db->prepare(
            "UPDATE seo_executions
             SET status = 'rejected',
                 rejection_reason = ?,
                 fraud_flags = ?,
                 updated_at = NOW()
             WHERE id = ?"
        );

        return $stmt->execute([$reason, json_encode(['reason' => $reason], JSON_UNESCAPED_UNICODE), $id]);
    }

    // --------------------------------------------------------
    // PRIVATE
    // --------------------------------------------------------

/** @param array<int|string, mixed> $row */
    private function hydrate(array $row): self
    {
        $o = clone $this;
        $o->id                  = int_value($row['id'] ?? 0);
        $o->ad_id               = int_value($row['ad_id'] ?? 0);
        $o->user_id             = int_value($row['user_id'] ?? 0);
        $o->time_score          = float_value($row['time_score']         ?? 0);
        $o->scroll_score        = float_value($row['scroll_score']       ?? 0);
        $o->interaction_score   = float_value($row['interaction_score']  ?? 0);
        $o->quality_score       = float_value($row['quality_score']      ?? 0);
        $o->final_score         = float_value($row['final_score']        ?? 0);
        $o->payout_amount       = float_value($row['payout_amount']      ?? 0);
        $o->status              =         $row['status'];
        $o->engagement_data     =         $row['engagement_data']     ?? null;
        $o->fraud_flags         =         $row['fraud_flags']         ?? null;
        $o->ip_address          =         $row['ip_address'];
        $o->device_fingerprint  =         $row['device_fingerprint']  ?? null;
        $o->session_id          =         $row['session_id']          ?? null;
        $o->target_keyword      =         $row['target_keyword']      ?? null;
        $o->rejection_reason    =         $row['rejection_reason']    ?? null;
        $o->cancel_reason       =         $row['cancel_reason']       ?? null;
        $o->fraud_score         = isset($row['fraud_score']) ? int_value($row['fraud_score']) : null;
        $o->score_breakdown     =         $row['score_breakdown']     ?? null;
        $o->started_at          =         $row['started_at'];
        $o->completed_at        =         $row['completed_at']        ?? null;
        $o->created_at          =         $row['created_at'];
        $o->updated_at          =         $row['updated_at']          ?? null;
        return $o;
    }
}
