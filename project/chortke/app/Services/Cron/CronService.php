<?php

declare(strict_types=1);

namespace App\Services\Cron;

use App\Models\User;
use Core\Database;
use App\Contracts\LoggerInterface;

/**
 * CronService — لایه Service برای عملیات Cron Jobs
 */
class CronService
{

    /** @return list<\stdClass> */
    private function toObjectArray(mixed $rows): array
    {
        if (!is_array($rows)) throw new \UnexpectedValueException('Cron database result must be an array.');
        $result = [];
        foreach ($rows as $row) {
            if ($row instanceof \stdClass) $result[] = $row;
            elseif (is_array($row)) $result[] = (object)$row;
            else throw new \UnexpectedValueException('Cron database row is invalid.');
        }
        return $result;
    }



    private \Core\Database $db;
    private \App\Contracts\LoggerInterface $logger;
    private \App\Services\Gamification\XpService $xpService;
    private \App\Services\Ads\AdsBudgetSettlementService $adsBudgetSettlementService;
    public function __construct(
        \Core\Database $db,
        \App\Contracts\LoggerInterface $logger,
        \App\Services\Gamification\XpService $xpService,
        \App\Services\Ads\AdsBudgetSettlementService $adsBudgetSettlementService
    ) {        $this->db = $db;
        $this->logger = $logger;
        $this->xpService = $xpService;
        $this->adsBudgetSettlementService = $adsBudgetSettlementService;

    }

    /** @return array<string, mixed> */
    public function applyInactivityScoreDecay(): array
    {
        // 🛡️ DATABASE STAMPEDE FIX (N+1 Query Elimination): تجمیع کوئری‌ها و پردازش دسته‌ای
        // حذف ده‌ها هزار کوئری متوالی و انتقال محاسبه زمان آخرین فعالیت به انجین دیتابیس
        $sql = "
            SELECT u.id, u.email, u.level_slug, 
                   COALESCE(MAX(a.created_at), u.created_at) as last_activity
            FROM users u
            LEFT JOIN activity_logs a ON a.user_id = u.id
            WHERE u.status = 'active' AND u.deleted_at IS NULL
            GROUP BY u.id, u.email, u.level_slug, u.created_at
            HAVING DATEDIFF(NOW(), last_activity) >= 1
        ";

        $users = $this->toObjectArray($this->db->fetchAll($sql) ?: []);
        $processed = 0;

        // ✅ N+1 FIX: ساخت شیء User از داده موجود — بدون find() جداگانه
        // XpService.applyDecay فقط به $user->id و $user->level_slug نیاز دارد
        // که هر دو از همان query اولیه موجودند
        $now = new \DateTime();
        if (!is_array($users)) {
            return ['processed' => 0, 'message' => 'No inactive users found'];
        }
        foreach ($users as $userData) {
            $userId       = (int)$userData->id;
            $lastActivity = new \DateTime($userData->last_activity);
            $inactiveDays = $now->diff($lastActivity)->days;

            if ($inactiveDays >= 1) {
                $processed++;
                // ساخت User object از داده‌های موجود
                $user = new User($this->db);
                $user->id = $userId;
                $user->level_slug = $userData->level_slug ?? 'bronze';
                $user->email = $userData->email ?? '';
                $user->status = 'active';
                $this->xpService->applyDecay($user, $inactiveDays);
            }
        }

        return [
            'success' => true,
            'processed_users' => $processed,
            'message' => "بررسی ریزش امتیاز عدم فعالیت برای {$processed} کاربر انجام شد."
        ];
    }

    /**
     * فلاش کردن بافر امتیازات از Redis به Database
     */
    /** @return array<string, mixed> */
    public function flushScoreEventsBuffer(int $batchSize = 1000): array
    {
        try {
            $scoreModel = new \App\Models\Score($this->db);
            $flushed = $scoreModel->flushBuffer($batchSize);
            return [
                'success' => true,
                'message' => "{$flushed} score events flushed to database.",
                'count' => $flushed
            ];
        } catch (\Throwable $e) {
            $this->logger->error('cron.flush_score_events.failed', ['error' => $e->getMessage()]);
            return [
                'success' => false,
                'message' => 'خطا در فلاش کردن بافر امتیازات',
                'error' => $e->getMessage()
            ];
        }
    }

    /** @return list<\stdClass> */
    public function getPendingCryptoDeposits(int $hours = 12, int $limit = 10): array
    {
        return $this->db->fetchAll("SELECT * FROM crypto_deposits WHERE verification_status = 'pending' LIMIT " . (int)$limit);
    }

    public function expireOldAdvertisements(): int
    {
        $result = $this->adsBudgetSettlementService
            ->reconcileLifecycle(200);
        return (is_numeric($result['completed'] ?? null) ? (int)$result['completed'] : 0) + (is_numeric($result['expired'] ?? null) ? (int)$result['expired'] : 0);
    }

    public function deleteOldSessions(int $days = 30): int
    {
        try {
            $stmt = $this->db->prepare("DELETE FROM sessions WHERE last_activity < ?");
            $stmt->execute([time() - ($days * 86400)]);
            return $stmt->rowCount();
        } catch (\Throwable $e) {
            return 0;
        }
    }

    public function deleteExpiredPasswordResets(): int
    {
        try {
            $stmt = $this->db->prepare("DELETE FROM password_resets WHERE expires_at < NOW()");
            $stmt->execute();
            return $stmt->rowCount();
        } catch (\Throwable $e) {
            return 0;
        }
    }

    public function deleteOldActivityLogs(int $days = 90): int
    {
        try {
            $stmt = $this->db->prepare("DELETE FROM activity_logs WHERE created_at < DATE_SUB(NOW(), INTERVAL ? DAY)");
            $stmt->execute([$days]);
            return $stmt->rowCount();
        } catch (\Throwable $e) {
            return 0;
        }
    }

    public function deleteOldSentEmails(int $days = 30): int
    {
        try {
            $stmt = $this->db->prepare("DELETE FROM sent_emails WHERE created_at < DATE_SUB(NOW(), INTERVAL ? DAY)");
            $stmt->execute([$days]);
            return $stmt->rowCount();
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /** @return list<\stdClass> */
    public function getOldRejectedKycRecords(int $days = 60): array
    {
        try {
            return $this->db->fetchAll("SELECT * FROM kyc_verifications WHERE status = 'rejected' AND updated_at < DATE_SUB(NOW(), INTERVAL ? DAY)", [$days]);
        } catch (\Throwable $e) {
            return [];
        }
    }

    public function markKycDocumentsDeleted(int $id): bool
    {
        try {
            return (bool)$this->db->execute("UPDATE kyc_verifications SET verification_image = NULL WHERE id = ?", [$id]);
        } catch (\Throwable $e) {
            return false;
        }
    }

    public function countNewUsers(int $days = 7): int
    {
        try {
            return (int)$this->db->fetchColumn("SELECT COUNT(*) FROM users WHERE created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)", [$days]);
        } catch (\Throwable $e) {
            return 0;
        }
    }

    public function getTransactionVolume(int $days = 7): float
    {
        try {
            return (float)$this->db->fetchColumn("SELECT COALESCE(SUM(amount), 0) FROM transactions WHERE status = 'success' AND created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)", [$days]);
        } catch (\Throwable $e) {
            return 0.0;
        }
    }
}

if (!class_exists('App\Services\CronService', false)) {
    \class_alias(\App\Services\Cron\CronService::class, 'App\Services\CronService');
}

