<?php

declare(strict_types=1);

namespace App\Models;

use Core\Model;

/**
 * KpiStatistics Model — KPI Dashboard Data Access Layer
 * 
 * مسئولیت: محاسبه KPI های سیستم و داشبورد کلی.
 * استفاده می‌شود در: AnalyticsQueryService
 */
class KpiStatistics extends Model
{
    /**
     * نام جدول (الزام قرارداد Core\\Model).
     * این مدل عمدتاً از کوئری‌های خام استفاده می‌کند، اما برای سازگاری با
     * متدهای پایه‌ی Model، جدول مرجع آن تعریف می‌شود.
     */
    protected static string $table = 'users';

    public function __construct(\Core\Database $db) {
        parent::__construct($db);
    }

    /**
     * نرخ رشد کاربران ماهانه
     */
    /** @return list<array<string, mixed>> */
    public function getMonthlyUserGrowth(int $months = 12): array
    {
        $stmt = $this->db->prepare(
            "SELECT 
                DATE_FORMAT(created_at, '%Y-%m') as month,
                COUNT(*) as new_users
             FROM users
             WHERE created_at >= DATE_SUB(NOW(), INTERVAL ? MONTH)
             GROUP BY DATE_FORMAT(created_at, '%Y-%m')
             ORDER BY month DESC"
        );
        $stmt->execute([$months]);
        return $this->fetchAssocList($stmt);
    }

    /**
     * نرخ تحقق KYC
     */
    public function getKycCompletionRate(): float
    {
        $stmt = $this->db->prepare(
            "SELECT 
                SUM(CASE WHEN kyc_status = 'verified' THEN 1 ELSE 0 END) / COUNT(*) * 100 as rate
             FROM users
             WHERE created_at > DATE_SUB(NOW(), INTERVAL 1 YEAR)"
        );
        $stmt->execute();
        return (float) ($stmt->fetchColumn() ?? 0);
    }

    /**
     * میانگین مدت زمان تحقق KYC (ساعت)
     */
    public function getAverageKycVerificationTime(): float
    {
        $stmt = $this->db->prepare(
            "SELECT AVG(TIMESTAMPDIFF(HOUR, created_at, updated_at)) as avg_hours
             FROM kyc_verification
             WHERE status = 'verified'"
        );
        $stmt->execute();
        return (float) ($stmt->fetchColumn() ?? 0);
    }

    /**
     * نسبت فعالیت کاربران
     */
    public function getUserActivityRatio(): float
    {
        $stmt = $this->db->prepare(
            "SELECT 
                COUNT(DISTINCT user_id) / (SELECT COUNT(*) FROM users WHERE created_at > DATE_SUB(NOW(), INTERVAL 30 DAY)) * 100 as ratio
             FROM activity_logs
             WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)"
        );
        $stmt->execute();
        return (float) ($stmt->fetchColumn() ?? 0);
    }

    /**
     * متوسط تراکنش به ازای هر کاربر
     */
    public function getAverageTransactionPerUser(): float
    {
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) / (SELECT COUNT(DISTINCT user_id) FROM transactions) as avg
             FROM transactions"
        );
        $stmt->execute();
        return (float) ($stmt->fetchColumn() ?? 0);
    }

    /**
     * آمار تسک‌ها
     */
    /** @return array<string, mixed> */
    public function getTaskStats(): array
    {
        $stmt = $this->db->prepare(
            "SELECT 
                COUNT(*) as total,
                COALESCE(SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END), 0) as active,
                COALESCE(SUM(CASE WHEN status = 'completed' AND DATE(updated_at) = CURDATE() THEN 1 ELSE 0 END), 0) as completed_today,
                COALESCE(SUM(CASE WHEN status = 'completed' AND updated_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) THEN 1 ELSE 0 END), 0) as completed_week,
                COALESCE(SUM(CASE WHEN status = 'completed' AND updated_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) THEN 1 ELSE 0 END), 0) as completed_month,
                COALESCE(SUM(CASE WHEN status = 'pending_review' THEN 1 ELSE 0 END), 0) as pending_verification,
                COALESCE(SUM(CASE WHEN status = 'fraud_detected' THEN 1 ELSE 0 END), 0) as fraud_detected
             FROM ads WHERE deleted_at IS NULL"
        );
        $stmt->execute();
        $stats = $this->fetchAssoc($stmt);

        $platforms = $this->db->prepare(
            "SELECT platform, COUNT(*) as count FROM ads WHERE deleted_at IS NULL GROUP BY platform ORDER by count DESC"
        );
        $platforms->execute();
        $stats['by_platform'] = $platforms->fetchAll(\PDO::FETCH_ASSOC) ?: [];

        $types = $this->db->prepare(
            "SELECT type, COUNT(*) as count FROM ads WHERE deleted_at IS NULL GROUP BY type ORDER by count DESC"
        );
        $types->execute();
        $stats['by_type'] = $types->fetchAll(\PDO::FETCH_ASSOC) ?: [];

        return $stats;
    }

    /**
     * آمار تیکت‌ها
     */
    /** @return array<string, mixed> */
    public function getTicketStats(): array
    {
        $open = 0;
        $in_progress = 0;
        $total = 0;
        $avg_response_hours = 2.5;

        try {
            $stmt = $this->db->prepare(
                "SELECT 
                    COUNT(*) as total,
                    SUM(CASE WHEN status = 'open' THEN 1 ELSE 0 END) as open,
                    SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as in_progress,
                    AVG(CASE WHEN last_reply_at IS NOT NULL THEN TIMESTAMPDIFF(HOUR, created_at, last_reply_at) ELSE NULL END) as avg_hours
                 FROM tickets"
            );
            $stmt->execute();
            $res = $this->fetchAssoc($stmt);
            if ($res !== []) {
                $total = int_value($res['total'] ?? 0);
                $open = int_value($res['open'] ?? 0);
                $in_progress = int_value($res['in_progress'] ?? 0);
                $avg_response_hours = round(float_value($res['avg_hours'] ?? 2.5), 1);
            }
        } catch (\Throwable $e) {
            @error_log('[KPI] ticket_stats query failed: ' . $e->getMessage());
        }

        return [
            'open' => $open,
            'in_progress' => $in_progress,
            'total' => $total,
            'avg_response_hours' => $avg_response_hours ?: 2.5
        ];
    }

    /**
     * آمار کلاهبرداری
     */
    /** @return array<string, mixed> */
    public function getFraudStats(): array
    {
        $suspicious_users = 0;
        $blocked_today = 0;
        $silent_blacklisted = 0;
        $fraud_tasks_month = 0;
        $reports = 0;
        $detected = 0;

        try {
            $suspicious_users = (int)($this->db->query("SELECT COUNT(DISTINCT user_id) FROM user_fraud_flags")->fetchColumn() ?: 0);
        } catch (\Throwable $e) {
            @error_log('[KPI] suspicious_users query failed: ' . $e->getMessage());
        }

        try {
            $blocked_today = (int)($this->db->query("SELECT COUNT(*) FROM users WHERE status IN ('banned', 'suspended') AND DATE(created_at) = CURDATE()")->fetchColumn() ?: 0);
        } catch (\Throwable $e) {
            @error_log('[KPI] blocked_today query failed: ' . $e->getMessage());
        }

        try {
            $silent_blacklisted = (int)($this->db->query("SELECT COUNT(*) FROM blacklist")->fetchColumn() ?: 0);
        } catch (\Throwable $e) {
            @error_log('[KPI] silent_blacklisted query failed: ' . $e->getMessage());
        }

        try {
            $fraud_tasks_month = (int)($this->db->query("SELECT COUNT(*) FROM fraud_reports WHERE status = 'pending' AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)")->fetchColumn() ?: 0);
        } catch (\Throwable $e) {
            @error_log('[KPI] fraud_tasks_month query failed: ' . $e->getMessage());
        }

        try {
            $stmt = $this->db->prepare(
                "SELECT 
                    SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as reports,
                    SUM(CASE WHEN status = 'reviewed' THEN 1 ELSE 0 END) as detected
                 FROM fraud_reports"
            );
            $stmt->execute();
            $res = $this->fetchAssoc($stmt);
            if ($res !== []) {
                $reports = int_value($res['reports'] ?? 0);
                $detected = int_value($res['detected'] ?? 0);
            }
        } catch (\Throwable $e) {
            // fallback logic if query fails
        }

        return [
            'suspicious_users' => $suspicious_users,
            'blocked_today' => $blocked_today,
            'silent_blacklisted' => $silent_blacklisted,
            'fraud_tasks_month' => $fraud_tasks_month,
            'reports' => $reports,
            'detected' => $detected
        ];
    }

    /**
     * نسبت تسک‌های تکمیل شده
     */
    public function getTaskCompletionRate(): float
    {
        $stmt = $this->db->prepare(
            "SELECT 
                SUM(CASE WHEN status = 'approved' THEN 1 ELSE 0 END) / COUNT(*) * 100 as rate
             FROM custom_tasks"
        );
        $stmt->execute();
        return (float) ($stmt->fetchColumn() ?? 0);
    }

    /**
     * میانگین زمان تسک (ساعت)
     */
    public function getAverageTaskDuration(): float
    {
        $stmt = $this->db->prepare(
            "SELECT AVG(TIMESTAMPDIFF(HOUR, created_at, updated_at)) as avg_hours
             FROM custom_tasks
             WHERE status IN ('completed', 'approved')"
        );
        $stmt->execute();
        return (float) ($stmt->fetchColumn() ?? 0);
    }

    /**
     * نسبت تغییر کاربران (Churn Rate)
     */
    public function getChurnRate(): float
    {
        $stmt = $this->db->prepare(
            "SELECT 
                (SUM(CASE WHEN status IN (2,3) OR deleted_at IS NOT NULL THEN 1 ELSE 0 END) / COUNT(*) * 100) as rate
             FROM users"
        );
        $stmt->execute();
        return (float) ($stmt->fetchColumn() ?? 0);
    }

    /**
     * نسبت تبدیل (Conversion Rate)
     */
    public function getConversionRate(): float
    {
        $stmt = $this->db->prepare(
            "SELECT 
                (COUNT(CASE WHEN te.status = 'approved' THEN 1 END) / COUNT(DISTINCT a.id) * 100) as rate
             FROM ads a
             LEFT JOIN social_task_executions te ON a.id = te.ad_id
             WHERE a.deleted_at IS NULL"
        );
        $stmt->execute();
        return (float) ($stmt->fetchColumn() ?? 0);
    }

    /**
     * تسک‌ها بر اساس پلتفرم
     */
    /** @return list<array<string, mixed>> */
    public function getTasksByPlatform(): array
    {
        $stmt = $this->db->prepare(
            "SELECT platform, COUNT(*) as count 
             FROM ads WHERE deleted_at IS NULL 
             GROUP BY platform ORDER BY count DESC"
        );
        $stmt->execute();
        return $this->fetchAssocList($stmt);
    }

    /**
     * فعالیت ساعتی
     */
    /** @return array<int, int> */
    public function getHourlyActivity(int $days = 30): array
    {
        $stmt = $this->db->prepare(
            "SELECT HOUR(created_at) as hour, COUNT(*) as count
             FROM social_task_executions
             WHERE created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
             GROUP BY HOUR(created_at)
             ORDER BY hour ASC"
        );
        $stmt->execute([$days]);
        $rows = $this->fetchAssocList($stmt);

        $result = array_fill(0, 24, 0);
        foreach ($rows as $row) {
            $hour = int_value($row['hour'] ?? 0);
            $result[$hour] = int_value($row['count'] ?? 0);
        }

        return $result;
    }

    /**
     * آمار سرمایه‌گذاری
     */
    /** @return array<string, mixed> */
    public function getInvestmentStats(): array
    {
        $stmt = $this->db->prepare(
            "SELECT 
                COALESCE(SUM(amount), 0) as total_investment,
                SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as active,
                SUM(CASE WHEN status = 'matured' THEN 1 ELSE 0 END) as matured,
                COALESCE(SUM(profit), 0) as total_profit
             FROM investments WHERE deleted_at IS NULL"
        );
        $stmt->execute();
        return $this->fetchAssoc($stmt);
    }

    /**
     * آمار ارجاع (Referral Stats)
     */
    /** @return array<string, mixed> */
    public function getReferralStats(): array
    {
        $stmt = $this->db->prepare(
            "SELECT 
                (SELECT COUNT(*) FROM users WHERE referred_by IS NOT NULL) as total,
                (SELECT COUNT(*) FROM users WHERE referred_by IS NOT NULL) as total_referrals,
                (SELECT COALESCE(SUM(commission_amount), 0) FROM referral_commissions WHERE status IN ('paid','approved','pending')) as total_commissions,
                (SELECT COALESCE(SUM(commission_amount), 0) FROM referral_commissions WHERE status IN ('paid','approved','pending')) as total_commission"
        );
        $stmt->execute();
        return $this->fetchAssoc($stmt);
    }

    /**
     * کاربران برتر
     */
    /** @return list<array<string, mixed>> */
    public function getTopUsers(int $limit = 20): array
    {
        $stmt = $this->db->prepare(
            "SELECT u.id, u.full_name, u.email, u.level_slug,
                    COALESCE(SUM(CASE WHEN t.status = 'completed' AND t.type IN ('task_reward','reward','earning') THEN t.amount ELSE 0 END), 0) as task_earnings,
                    COALESCE((SELECT SUM(rc.commission_amount) FROM referral_commissions rc WHERE rc.referrer_id = u.id AND rc.status IN ('paid','approved','pending')), 0) as commission_earnings,
                    (COALESCE(SUM(CASE WHEN t.status = 'completed' AND t.type IN ('task_reward','reward','earning') THEN t.amount ELSE 0 END), 0)
                     + COALESCE((SELECT SUM(rc.commission_amount) FROM referral_commissions rc WHERE rc.referrer_id = u.id AND rc.status IN ('paid','approved','pending')), 0)) as total_amount
             FROM users u
             LEFT JOIN transactions t ON t.user_id = u.id
             WHERE u.deleted_at IS NULL
             GROUP BY u.id, u.full_name, u.email, u.level_slug
             ORDER BY total_amount DESC
             LIMIT ?"
        );
        $stmt->bindValue(1, $limit, \PDO::PARAM_INT);
        $stmt->execute();
        return $this->fetchAssocList($stmt);
    }

    /**
     * آمار لاتاری
     */
    /** @return array<string, mixed> */
    public function getLotteryStats(): array
    {
        $stmt = $this->db->prepare(
            "SELECT 
                COALESCE(SUM(CASE WHEN lr.deleted_at IS NULL AND COALESCE(lr.is_deleted,0) = 0 THEN 1 ELSE 0 END), 0) as total_rounds,
                COALESCE(SUM(CASE WHEN lr.status = 'active' AND lr.deleted_at IS NULL AND COALESCE(lr.is_deleted,0) = 0 THEN 1 ELSE 0 END), 0) as active_rounds,
                COALESCE(SUM(CASE WHEN COALESCE(lp.is_deleted,0) = 0 THEN 1 ELSE 0 END), 0) as participations,
                COALESCE(SUM(CASE WHEN COALESCE(lp.is_deleted,0) = 0 THEN 1 ELSE 0 END), 0) as total_participants,
                0 as votes_today,
                COALESCE(AVG(lp.chance_score), 0) as avg_chance_score
             FROM lottery_rounds lr
             LEFT JOIN lottery_participations lp ON lr.id = lp.round_id"
        );
        $stmt->execute();
        return $this->fetchAssoc($stmt);
    }

    /**
     * ثبت‌نام روزانه
     */
    /** @return list<array<string, mixed>> */
    public function getDailyRegistrations(int $days = 30): array
    {
        $stmt = $this->db->prepare(
            "SELECT DATE(created_at) as date, COUNT(*) as count 
             FROM users WHERE created_at >= DATE_SUB(NOW(), INTERVAL ? DAY) AND deleted_at IS NULL
             GROUP BY DATE(created_at) ORDER BY date ASC"
        );
        $stmt->execute([$days]);
        return $this->fetchAssocList($stmt);
    }

    /**
     * درآمد روزانه
     */
    /** @return list<array<string, mixed>> */
    public function getDailyRevenue(int $days = 30, ?string $currency = null): array
    {
        $curr = strtoupper($currency ?: 'IRT');
        $stmt = $this->db->prepare(
            "SELECT DATE(created_at) as date, COALESCE(SUM(amount), 0) as total
             FROM transactions 
             WHERE type IN ('commission_site','tax','fee') AND status = 'completed' AND currency = ?
             AND created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
             GROUP BY DATE(created_at) ORDER BY date ASC"
        );
        $stmt->execute([$curr, $days]);
        return $this->fetchAssocList($stmt);
    }

    /**
     * واریز و برداشت روزانه
     */
    /** @return array{deposits: list<array<string, mixed>>, withdrawals: list<array<string, mixed>>} */
    public function getDailyDepositsWithdrawals(int $days = 30, ?string $currency = null): array
    {
        $curr = strtoupper($currency ?: 'IRT');
        $deposits = $this->db->prepare(
            "SELECT DATE(created_at) as date, COALESCE(SUM(amount), 0) as total
             FROM transactions WHERE type = 'deposit' AND status = 'completed' AND currency = ?
             AND created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
             GROUP BY DATE(created_at) ORDER BY date ASC"
        );
        $deposits->execute([$curr, $days]);

        $withdrawals = $this->db->prepare(
            "SELECT DATE(created_at) as date, COALESCE(SUM(amount), 0) as total
             FROM transactions WHERE type = 'withdraw' AND status = 'completed' AND currency = ?
             AND created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
             GROUP BY DATE(created_at) ORDER BY date ASC"
        );
        $withdrawals->execute([$curr, $days]);

        return [
            'deposits' => $deposits->fetchAll(\PDO::FETCH_ASSOC) ?: [],
            'withdrawals' => $withdrawals->fetchAll(\PDO::FETCH_ASSOC) ?: []
        ];
    }

    /**
     * تسک‌های تکمیل‌شده روزانه
     */
    /** @return list<array<string, mixed>> */
    public function getDailyCompletedTasks(int $days = 30): array
    {
        $stmt = $this->db->prepare(
            "SELECT date, SUM(count) as count FROM (
                SELECT DATE(completed_at) as date, COUNT(*) as count FROM social_task_executions WHERE status = 'completed' AND completed_at >= DATE_SUB(NOW(), INTERVAL ? DAY) GROUP BY DATE(completed_at)
                UNION ALL
                SELECT DATE(completed_at) as date, COUNT(*) as count FROM seo_executions WHERE status = 'completed' AND completed_at >= DATE_SUB(NOW(), INTERVAL ? DAY) GROUP BY DATE(completed_at)
                UNION ALL
                SELECT DATE(approved_at) as date, COUNT(*) as count FROM custom_task_submissions WHERE status = 'approved' AND approved_at >= DATE_SUB(NOW(), INTERVAL ? DAY) GROUP BY DATE(approved_at)
            ) combined
            GROUP BY date ORDER BY date ASC"
        );
        $stmt->execute([$days, $days, $days]);
        return $this->fetchAssocList($stmt);
    }

    /**
     * دریافت KPI های کلیدی
     */
    /** @return array<string, mixed> */
    public function getKeyKpis(): array
    {
        return [
            'kyc_completion_rate' => $this->getKycCompletionRate(),
            'user_activity_ratio' => $this->getUserActivityRatio(),
            'task_completion_rate' => $this->getTaskCompletionRate(),
            'churn_rate' => $this->getChurnRate(),
            'conversion_rate' => $this->getConversionRate(),
            'monthly_user_growth' => $this->getMonthlyUserGrowth(12),
        ];
    }

    /**
     * داشبورد خلاصه
     */
    /** @return array<string, mixed> */
    public function getDashboardSummary(): array
    {
        return [
            'key_kpis' => $this->getKeyKpis(),
            'kyc_completion_rate' => $this->getKycCompletionRate(),
            'avg_kyc_time_hours' => $this->getAverageKycVerificationTime(),
            'avg_transaction_per_user' => $this->getAverageTransactionPerUser(),
            'avg_task_duration' => $this->getAverageTaskDuration(),
            'churn_rate' => $this->getChurnRate(),
            'conversion_rate' => $this->getConversionRate(),
        ];
    }
}
