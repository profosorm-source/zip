<?php

declare(strict_types=1);

namespace App\Jobs;

use Core\Database;
use App\Contracts\LoggerInterface;

/**
 * AggregateAnalyticsJob
 * تجمیع داده‌های روزانه در جدول analytics_daily_summary برای جلوگیری از بار N+1
 */
class AggregateAnalyticsJob
{
    private Database $db;
    private LoggerInterface $logger;

    public function __construct(Database $db, LoggerInterface $logger) {
        $this->db = $db;
        $this->logger = $logger;
    }

    /** @param array<string, mixed> $data */
    /** @param array<string, mixed> $data */
public function handle(array $data = []): void
    {
        // اصلاح کلیدی معماری همزمانی در تجمیع آمار (Analytics Aggregation Mutex Guard):
        // استفاده از قفل همزمانی کش جهت جلوگیری از اجرای موازی کوئری‌های سنگین COUNT/SUM توسط ورکرهای کرون در کلاسترها
        $lockKey = 'mutex:analytics_aggregation:' . date('Ymd');
        if (!function_exists('cache') || !cache()->lock($lockKey, 300, 2)) {
            $this->logger->info('analytics.aggregation_skipped_locked', ['date' => date('Y-m-d')]);
            return;
        }

        try {
            $date = date('Y-m-d');

            // float→decimal: مجموع درآمد به‌صورت رشتهٔ decimal حفظ می‌شود (بدون گذر از float)
            $revenueIrt  = $this->db->query("SELECT SUM(amount) FROM transactions WHERE DATE(created_at) = ? AND currency = 'IRT' AND status = 'completed'", [$date])->fetchColumn();
            $revenueUsdt = $this->db->query("SELECT SUM(amount) FROM transactions WHERE DATE(created_at) = ? AND currency = 'USDT' AND status = 'completed'", [$date])->fetchColumn();

            // Daily summary collection
            $summary = [
                'date' => $date,
                'new_users' => (int)$this->db->query("SELECT COUNT(*) FROM users WHERE DATE(created_at) = ?", [$date])->fetchColumn(),
                'total_transactions' => (int)$this->db->query("SELECT COUNT(*) FROM transactions WHERE DATE(created_at) = ?", [$date])->fetchColumn(),
                'revenue_irt' => is_numeric($revenueIrt) ? (string)$revenueIrt : '0',
                'revenue_usdt' => is_numeric($revenueUsdt) ? (string)$revenueUsdt : '0',
                'tasks_completed' => (int)$this->db->query("SELECT COUNT(*) FROM custom_tasks WHERE DATE(updated_at) = ? AND status = 'completed'", [$date])->fetchColumn(),
                'active_users' => (int)$this->db->query("SELECT COUNT(DISTINCT user_id) FROM activity_logs WHERE DATE(created_at) = ?", [$date])->fetchColumn(),
                'updated_at' => date('Y-m-d H:i:s')
            ];
            
            // Upsert mechanism (Requires MySQL / MariaDB)
            $sql = "INSERT INTO analytics_daily_summary 
                    (date, new_users, total_transactions, revenue_irt, revenue_usdt, tasks_completed, active_users, updated_at) 
                    VALUES 
                    (?, ?, ?, ?, ?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE 
                    new_users = VALUES(new_users), 
                    total_transactions = VALUES(total_transactions), 
                    revenue_irt = VALUES(revenue_irt), 
                    revenue_usdt = VALUES(revenue_usdt), 
                    tasks_completed = VALUES(tasks_completed), 
                    active_users = VALUES(active_users), 
                    updated_at = VALUES(updated_at)";
            
            $this->db->query($sql, array_values($summary));
            
            $this->logger->info('analytics.aggregated', $summary);
            
        } catch (\Exception $e) {
            $this->logger->error('analytics.aggregation_failed', ['error' => $e->getMessage()]);
            throw $e;
        } finally {
            if (function_exists('cache')) {
                try { cache()->unlock($lockKey); } catch (\Throwable $err) { /* intentional: non-blocking operation */ }
            }
        }
    }
}
