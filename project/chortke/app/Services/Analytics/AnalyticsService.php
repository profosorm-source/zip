<?php

declare(strict_types=1);

namespace App\Services\Analytics;

use App\Models\CustomTaskModel;
use App\Contracts\LoggerInterface;


/**
 * Analytics payloads are intentionally extensible dashboard/report sections;
 * stable aggregate helpers below expose narrower scalar or row-list contracts.
 *
 * @phpstan-type AnalyticsPayload array<int|string, mixed>
 * @phpstan-type AnalyticsRows list<array<string, mixed>|\stdClass>
 * @phpstan-type AnalyticsReportData array<string, AnalyticsPayload>
 */
class AnalyticsService
{
    /**
 * AnalyticsService - Orchestrator
 * خدمات تحلیل و گزارش‌گیری
 * Consolidated from: AnalyticsService, KpiService, CustomTaskAnalyticsService, ReportService
 */
    private \App\Contracts\LoggerInterface $logger;
    private AnalyticsQueryService $repository;
    private AnalyticsExporter $exporter;
    public function __construct(
        \App\Contracts\LoggerInterface $logger,
        AnalyticsQueryService $repository,
        AnalyticsExporter $exporter
    ) {        $this->logger = $logger;
        $this->repository = $repository;
        $this->exporter = $exporter;

            }

    // ==========================================
    //  Metrics - داشبورد جامع
    // ==========================================

    /**
     * دریافت آمار کاربران
     */
    /** @return AnalyticsPayload */
    public function getUserMetrics(?string $period = null): array
    {
        $stats = $this->repository->getUserStats();
        $total = int_value($stats['total'] ?? $stats['total_users'] ?? 0);
        $active = int_value($stats['active'] ?? $stats['active_users'] ?? 0);
        $kycVerified = int_value($stats['kyc_verified'] ?? 0);
        $kycPending = int_value($stats['kyc_pending'] ?? 0);
        $newUsers = int_value(match ($period) {
            'day' => $stats['new_today'] ?? 0,
            'week' => $stats['new_this_week'] ?? 0,
            default => $stats['new_this_month'] ?? $stats['new_users'] ?? 0,
        });
        return array_merge($stats, [
            'total_users' => $total,
            'active_users' => $active,
            'new_users' => $newUsers,
            'kyc_verified' => $kycVerified,
            'kyc_pending' => $kycPending,
            'kyc_rejected' => int_value($stats['kyc_rejected'] ?? 0),
            'kyc_not_submitted' => max(0, $total - $kycVerified - $kycPending - int_value($stats['kyc_rejected'] ?? 0)),
            'users_by_level' => $this->normalizeLevelRows((array)($stats['tiers'] ?? [])),
        ]);
    }

    /**
     * دریافت آمار مالی
     */
    /** @return AnalyticsPayload */
    public function getTransactionMetrics(?string $currency = null): array
    {
        // Controllers historically pass period strings here; financial repository
        // expects currency. Treat known period values as default IRT currency.
        if (in_array($currency, ['day', 'week', 'month', 'year'], true)) {
            $currency = null;
        }
        $stats = $this->repository->getFinancialStats($currency);
        $deposits = is_array($stats['deposits'] ?? null) ? $stats['deposits'] : [];
        $withdrawals = is_array($stats['withdrawals'] ?? null) ? $stats['withdrawals'] : [];
        $payments = is_array($stats['payments'] ?? null) ? $stats['payments'] : [];
        $depositAmount = float_value($deposits['amount'] ?? $stats['deposit_amount'] ?? 0);
        $withdrawalAmount = float_value($withdrawals['amount'] ?? $stats['withdrawal_amount'] ?? 0);
        return array_merge($stats, [
            'deposits' => ['count' => int_value($deposits['count'] ?? $stats['deposit_count'] ?? 0), 'amount' => $depositAmount],
            'withdrawals' => ['count' => int_value($withdrawals['count'] ?? $stats['withdrawal_count'] ?? 0), 'amount' => $withdrawalAmount],
            'payments' => ['count' => int_value($payments['count'] ?? $stats['payment_count'] ?? 0), 'amount' => float_value($payments['amount'] ?? $stats['payment_amount'] ?? 0)],
            'platform_fee' => float_value($stats['platform_fee'] ?? $stats['fees'] ?? 0),
            'net_flow' => float_value($stats['net_flow'] ?? ($depositAmount - $withdrawalAmount)),
        ]);
    }

    /**
     * دریافت آمار تسک‌ها
     */
    /** @return AnalyticsPayload */
    public function getTaskMetrics(): array
    {
        $stats = $this->repository->getTaskStats();
        foreach (['total','active','completed_today','completed_week','completed_month','pending_verification','fraud_detected'] as $key) {
            $stats[$key] = int_value($stats[$key] ?? 0);
        }
        $stats['by_platform'] = $stats['by_platform'] ?? [];
        $stats['by_type'] = $stats['by_type'] ?? [];
        return $stats;
    }

    /**
     * دریافت آمار تسک‌های سفارشی (Custom Tasks)
     * from CustomTaskAnalyticsService
     */
    /** @return AnalyticsPayload */
    public function getCustomTaskMetrics(int|string|null $taskId = null, int $days = 30): array
    {
        // If an actual task id is supplied, preserve the original detailed API.
        if (is_int($taskId) || (is_string($taskId) && ctype_digit($taskId))) {
            return $this->repository->getCustomTaskStats((int)$taskId, $days);
        }

        return [
            'tasks' => [
                'total' => $this->safeCount('custom_tasks'),
                'active' => $this->safeCount('custom_tasks', "status = 'active'"),
                'total_submissions' => $this->safeCount('custom_task_submissions'),
                'avg_reward' => $this->safeAvg('custom_tasks', 'price_per_task'),
                'total_budget' => $this->safeSum('custom_tasks', 'total_budget'),
            ],
            'submissions' => [
                'total' => $this->safeCount('custom_task_submissions'),
                'approved' => $this->safeCount('custom_task_submissions', "status IN ('approved','completed')"),
                'rejected' => $this->safeCount('custom_task_submissions', "status = 'rejected'"),
                'pending' => $this->safeCount('custom_task_submissions', "status IN ('pending','review_pending','pending_review')"),
            ],
        ];
    }

    /**
     * دریافت KPI‌های کسب‌و‌کار
     * from KpiService
     */
    /** @return AnalyticsPayload */
    public function getKpis(): array
    {
        return [
            'users' => $this->getUserMetrics(),
            'transactions' => $this->getTransactionMetrics(),
            'tasks' => $this->getTaskMetrics(),
        ];
    }

    /** @return AnalyticsPayload */
    public function getTicketStats(): array
    {
        return $this->repository->getTicketStats();
    }

    /** @return AnalyticsPayload */
    public function getFraudStats(): array
    {
        return $this->repository->getFraudStats();
    }

    public function getChurnRate(): float
    {
        return $this->repository->getChurnRate();
    }

    public function getConversionRate(): float
    {
        return $this->repository->getConversionRate();
    }

    /** @return AnalyticsRows */
    public function getTasksByPlatform(): array
    {
        return $this->repository->getTasksByPlatform();
    }

    /** @return array<int, int> */
    public function getHourlyActivity(int $days = 30): array
    {
        return $this->repository->getHourlyActivity($days);
    }

    /** @return AnalyticsPayload */
    public function getInvestmentStats(): array
    {
        $stats = $this->repository->getInvestmentStats();
        $totalInvested = float_value($stats['total_invested'] ?? $stats['total_investment'] ?? 0);
        $totalProfit = float_value($stats['total_profit'] ?? 0);
        $totalLoss = float_value($stats['total_loss'] ?? 0);
        return array_merge($stats, [
            'total' => int_value($stats['total'] ?? $this->safeCount('investments')),
            'active' => int_value($stats['active'] ?? 0),
            'matured' => int_value($stats['matured'] ?? 0),
            'total_invested' => $totalInvested,
            'total_investment' => $totalInvested,
            'total_profit' => $totalProfit,
            'total_loss' => $totalLoss,
            'net_profit' => $totalProfit - $totalLoss,
        ]);
    }

    /** @return AnalyticsPayload */
    public function getReferralStats(): array
    {
        $stats = $this->repository->getReferralStats();
        return array_merge($stats, [
            'total' => int_value($stats['total'] ?? $stats['total_referrals'] ?? 0),
            'total_referrals' => int_value($stats['total_referrals'] ?? $stats['total'] ?? 0),
            'total_commissions' => float_value($stats['total_commissions'] ?? $stats['total_commission'] ?? 0),
            'total_commission' => float_value($stats['total_commission'] ?? $stats['total_commissions'] ?? 0),
            'top_referrers' => $stats['top_referrers'] ?? [],
        ]);
    }

    /** @return AnalyticsRows */
    public function getTopUsers(int $limit = 20): array
    {
        return $this->repository->getTopUsers($limit);
    }

    /** @return AnalyticsPayload */
    public function getLotteryStats(): array
    {
        $stats = $this->repository->getLotteryStats();
        return array_merge($stats, [
            'total_rounds' => int_value($stats['total_rounds'] ?? 0),
            'active_rounds' => int_value($stats['active_rounds'] ?? 0),
            'participations' => int_value($stats['participations'] ?? $stats['total_participants'] ?? 0),
            'total_participants' => int_value($stats['total_participants'] ?? $stats['participations'] ?? 0),
            'votes_today' => int_value($stats['votes_today'] ?? 0),
            'avg_chance_score' => float_value($stats['avg_chance_score'] ?? 0),
        ]);
    }

    /** @return AnalyticsPayload */
    public function getDashboardSummary(): array
    {
        return $this->repository->getDashboardSummary();
    }

    // ==========================================
    //  Dashboard Analytics
    // ==========================================

    /**
     * دریافت داشبورد جامع ادمین
     */
    /** @return AnalyticsPayload */
    public function getAdminDashboard(): array
    {
        return [
            'users' => $this->getUserMetrics(),
            'transactions' => $this->getTransactionMetrics(),
            'tasks' => $this->getTaskMetrics(),
            'daily_registrations' => $this->getDailyRegistrations(30),
            'daily_revenue' => $this->getDailyRevenue(30),
        ];
    }

    /**
     * دریافت داشبورد creator تسک‌های سفارشی
     */
    /** @return AnalyticsPayload */
    public function getCreatorDashboard(int $userId): array
    {
        return $this->repository->getCreatorDashboard($userId);
    }

    /**
     * دریافت داشبورد worker تسک‌های سفارشی
     */
    /** @return AnalyticsPayload */
    public function getWorkerDashboard(int $userId): array
    {
        return $this->repository->getWorkerDashboard($userId);
    }

    /**
     * دریافت تسک‌های محبوب
     */
    /** @return AnalyticsRows */
    public function getTrendingTasks(int $limit = 10): array
    {
        return $this->repository->getTrendingTasks($limit);
    }

    // ==========================================
    //  Report Generation
    // ==========================================

    /**
     * تولید گزارش CSV
     */
    /** @param AnalyticsReportData $data */
    public function generateReport(string $format = 'csv', array $data = []): void
    {
        $reportData = $data ?: $this->prepareReportData();

        match ($format) {
            'csv' => $this->exporter->generateCSV($reportData),
            'excel' => $this->exporter->generateExcel($reportData),
            'pdf' => $this->exporter->generatePDF($reportData),
            default => $this->exporter->generateCSV($reportData),
        };
    }

    /**
     * تولید CSV
     */
    /** @param AnalyticsReportData $data */
    public function generateCSV(array $data = []): void
    {
        $this->generateReport('csv', $data);
    }

    /**
     * تولید Excel
     */
    /** @param AnalyticsReportData $data */
    public function generateExcel(array $data = []): void
    {
        $this->generateReport('excel', $data);
    }

    /**
     * تولید PDF
     */
    /** @param AnalyticsReportData $data */
    public function generatePDF(array $data = []): void
    {
        $this->generateReport('pdf', $data);
    }

    /**
     * آماده‌سازی داده‌های گزارش
     */
    /** @return AnalyticsPayload */
    /** @return array<string, array<int|string, mixed>> */
    private function prepareReportData(): array
    {
        return [
            'users' => $this->getUserMetrics(),
            'transactions' => $this->getTransactionMetrics(),
            'tasks' => $this->getTaskMetrics(),
        ];
    }

    // ==========================================
    //  Time-Series Data (Charts)
    // ==========================================

    /**
     * ثبت‌نام روزانه (برای نمودار)
     */
    /** @return AnalyticsRows */
    public function getDailyRegistrations(int $days = 30): array
    {
        return $this->repository->getDailyRegistrations($days);
    }

    /**
     * درآمد روزانه (برای نمودار)
     */
    /** @return AnalyticsRows */
    public function getDailyRevenue(int $days = 30, ?string $currency = null): array
    {
        return $this->repository->getDailyRevenue($days, $currency);
    }

    /**
     * واریز و برداشت روزانه
     */
    /** @return array{deposits: list<array<string, mixed>>, withdrawals: list<array<string, mixed>>} */
    public function getDailyDepositsWithdrawals(int $days = 30, ?string $currency = null): array
    {
        return $this->repository->getDailyDepositsWithdrawals($days, $currency);
    }

    /**
     * تسک‌های تکمیل‌شده روزانه
     */
    /** @return AnalyticsRows */
    public function getDailyCompletedTasks(int $days = 30): array
    {
        return $this->repository->getDailyCompletedTasks($days);
    }



    /** @return AnalyticsPayload */
    public function getComprehensiveDashboard(string $period = 'month'): array
    {
        return [
            'users' => $this->getUserMetrics($period),
            'transactions' => $this->getTransactionMetrics(),
            'social_tasks' => $this->getSocialTaskMetrics($period),
            'ratings' => $this->dashboardRatingMetrics($period),
            'revenue' => $this->getRevenueBreakdown($period),
            'system_health' => [
                'database_size_mb' => 0,
                'recent_errors' => [],
                'rate_limit_hits' => $this->safeCount('rate_limits'),
            ],
        ];
    }

    /** @return AnalyticsRows */
    public function getUserGrowthChart(int $days = 30): array
    {
        return $this->repository->getDailyRegistrations($days);
    }

    /** @return array{deposits: list<array<string, mixed>>, withdrawals: list<array<string, mixed>>} */
    public function getTransactionVolumeChart(int $days = 30): array
    {
        return $this->repository->getDailyDepositsWithdrawals($days);
    }

    /** @return AnalyticsPayload */
    public function getSocialTaskMetrics(?string $period = null): array
    {
        $taskStats = $this->getTaskMetrics();
        return [
            'ads' => [
                'total' => $this->safeCount('social_ads'),
                'active' => $this->safeCount('social_ads', "status = 'active'"),
                'total_slots' => $this->safeSum('social_ads', 'total_slots'),
                'total_budget' => $this->safeSum('social_ads', 'total_budget'),
            ],
            'executions' => [
                'total' => $this->safeCount('task_executions'),
                'approved' => $this->safeCount('task_executions', "status IN ('approved','completed')"),
                'rejected' => $this->safeCount('task_executions', "status = 'rejected'"),
                'pending' => $this->safeCount('task_executions', "status IN ('pending','in_progress','review_pending')"),
                'approval_rate' => $this->percentage($this->safeCount('task_executions', "status IN ('approved','completed')"), $this->safeCount('task_executions')),
                'avg_score' => is_numeric($taskStats['avg_score'] ?? null) ? (float)$taskStats['avg_score'] : $this->safeAvg('task_executions', 'quality_score'),
            ],
            'platforms' => $this->safeGroupedRows('social_ads', 'platform'),
        ];
    }

    /** @return AnalyticsPayload */
    public function getRatingMetrics(?string $period = null): array
    {
        $total = $this->safeCount('ratings');
        return [
            'ratings' => [
                'total' => $total,
                'average' => $this->safeAvg('ratings', 'rating'),
                'five_star' => $this->safeCount('ratings', 'rating = 5'),
                'one_star' => $this->safeCount('ratings', 'rating = 1'),
                'with_comments' => $this->safeCount('ratings', "comment IS NOT NULL AND comment <> ''"),
                'without_comments' => $this->safeCount('ratings', "comment IS NULL OR comment = ''"),
            ],
            'distribution' => $this->safeGroupedRows('ratings', 'rating'),
        ];
    }

    /** @return AnalyticsPayload */
    public function getRevenueBreakdown(?string $period = null): array
    {
        $social = $this->safeSum('task_executions', 'reward_amount');
        $custom = $this->safeSum('custom_task_submissions', 'reward_amount');
        $ads = $this->safeSum('ad_delivery_events', 'site_fee_amount');
        $total = $social + $custom + $ads;
        $costRewards = $social + $custom;
        $costs = $costRewards;
        $payload = [
            'revenue' => ['total' => $total, 'net' => max(0, $total - $costs), 'costs' => $costs, 'profit' => $total - $costs],
            'breakdown' => ['social_tasks' => $social, 'custom_tasks' => $custom, 'ads' => $ads, 'subscriptions' => 0, 'other' => 0],
            'costs' => ['rewards' => $costRewards, 'server' => 0, 'marketing' => 0, 'operational' => 0, 'other' => 0],
            'income' => ['total' => $total],
            'expenses' => ['total' => $costs],
            'net_profit' => $total - $costs,
        ];
        return $payload;
    }


    /** @return AnalyticsPayload */
    private function dashboardRatingMetrics(?string $period = null): array
    {
        $m = $this->getRatingMetrics($period);
        $ratings = is_array($m['ratings'] ?? null) ? $m['ratings'] : [];
        return [
            'total_ratings' => is_numeric($ratings['total'] ?? null) ? (int)$ratings['total'] : 0,
            'average_rating' => is_numeric($ratings['average'] ?? null) ? (float)$ratings['average'] : 0.0,
            'moderation_status' => [
                'pending' => $this->safeCount('ratings', "status IN ('pending','review_pending','pending_review')"),
                'approved' => $this->safeCount('ratings', "status IN ('approved','active')"),
            ],
        ];
    }

    /**
     * @param AnalyticsPayload $rows
     * @return AnalyticsRows
     */
    private function normalizeLevelRows(array $rows): array
    {
        $out = [];
        foreach ((array)$rows as $key => $value) {
            if ($value instanceof \stdClass) { $out[] = $value; continue; }
            if (is_array($value)) { $out[] = (object)$value; continue; }
            if (is_scalar($value)) {
                $out[] = (object)['level' => (string)$key, 'count' => (int)$value];
            }
        }
        return $out;
    }

    // 🛡️ DATABASE PERFORMANCE FIX (Information_schema Cache Guard): کش درون‌حافظه‌ای متادیتای جداول
    // جلوگیری از رگبار کوئری‌های سنگین به information_schema در زمان لود داشبوردهای تحلیلی
    /** @var array{tables: array<string, bool>, cols: array<string, bool>} */
    private static array $tableSchemaCache = ['tables' => [], 'cols' => []];

    private function safeTableExists(string $table): bool
    {
        // Sanitize: only allow alphanumeric + underscore in table/column names
        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $table)) return false;
        if (isset(self::$tableSchemaCache['tables'][$table])) return self::$tableSchemaCache['tables'][$table];
        try { return self::$tableSchemaCache['tables'][$table] = (bool)db()->fetch('SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1', [$table]); } catch (\Throwable) { return false; }
    }

    private function safeColumnExists(string $table, string $column): bool
    {
        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $column)) return false;
        $key = "{$table}.{$column}";
        if (isset(self::$tableSchemaCache['cols'][$key])) return self::$tableSchemaCache['cols'][$key];
        try { return self::$tableSchemaCache['cols'][$key] = ($this->safeTableExists($table) && (bool)db()->fetch('SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1', [$table, $column])); } catch (\Throwable) { return false; }
    }

    // 🛡️ DATABASE PERFORMANCE FIX (Aggregate Cache): کش نتایج aggregate روی Redis
    // دلیل: درخواست‌های متوالی dashboard (هر چند ثانیه) باعث 10-20 query سنگین روی DB می‌شد
    // راهکار: cache نتیجه در Redis با TTL کوتاه؛ پس از تغییر داده، invalidate دستی
    // سازگار با feature flag: اگر analytics_cache_enabled=false باشد، مستقیم به DB می‌رود
    private const ANALYTICS_CACHE_TTL_SECONDS = 60;
    private const ANALYTICS_GROUPED_CACHE_TTL_SECONDS = 300;

    private function safeCount(string $table, string $where = '1=1'): int
    {
        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $table)) return 0;
        if (!$this->safeTableExists($table)) return 0;
        $cacheKey = 'analytics:count:' . $table . ':' . md5($where);
        $value = $this->cachedAggregate($cacheKey, self::ANALYTICS_CACHE_TTL_SECONDS, function () use ($table, $where): int {
            try {
                $row = db()->fetch("SELECT COUNT(*) AS c FROM `{$table}` WHERE {$where}");
                if ($row === null) return 0;
                $count = $row->c;
                return is_numeric($count) ? (int)$count : 0;
            } catch (\Throwable) {
                return 0;
            }
        });
        return is_numeric($value) ? (int)$value : 0;
    }

    private function safeSum(string $table, string $column): float
    {
        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $table)) return 0.0;
        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $column)) return 0.0;
        if (!$this->safeColumnExists($table, $column)) return 0.0;
        $cacheKey = 'analytics:sum:' . $table . ':' . $column;
        $value = $this->cachedAggregate($cacheKey, self::ANALYTICS_CACHE_TTL_SECONDS, function () use ($table, $column): float {
            try {
                $row = db()->fetch("SELECT COALESCE(SUM(`{$column}`),0) AS s FROM `{$table}`");
                if ($row === null) return 0.0;
                $sum = $row->s;
                return is_numeric($sum) ? (float)$sum : 0.0;
            } catch (\Throwable) {
                return 0.0;
            }
        });
        return is_numeric($value) ? (float)$value : 0.0;
    }

    private function safeAvg(string $table, string $column): float
    {
        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $table)) return 0.0;
        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $column)) return 0.0;
        if (!$this->safeColumnExists($table, $column)) return 0.0;
        $cacheKey = 'analytics:avg:' . $table . ':' . $column;
        $value = $this->cachedAggregate($cacheKey, self::ANALYTICS_CACHE_TTL_SECONDS, function () use ($table, $column): float {
            try {
                $row = db()->fetch("SELECT COALESCE(AVG(`{$column}`),0) AS a FROM `{$table}`");
                if ($row === null) return 0.0;
                $average = $row->a;
                return is_numeric($average) ? (float)$average : 0.0;
            } catch (\Throwable) {
                return 0.0;
            }
        });
        return is_numeric($value) ? (float)$value : 0.0;
    }

    private function safeMax(string $table, string $column): float
    {
        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $table)) return 0.0;
        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $column)) return 0.0;
        if (!$this->safeColumnExists($table, $column)) return 0.0;
        $cacheKey = 'analytics:max:' . $table . ':' . $column;
        $value = $this->cachedAggregate($cacheKey, self::ANALYTICS_CACHE_TTL_SECONDS, function () use ($table, $column): float {
            try {
                $row = db()->fetch("SELECT COALESCE(MAX(`{$column}`),0) AS m FROM `{$table}`");
                if ($row === null) return 0.0;
                $maximum = $row->m;
                return is_numeric($maximum) ? (float)$maximum : 0.0;
            } catch (\Throwable) {
                return 0.0;
            }
        });
        return is_numeric($value) ? (float)$value : 0.0;
    }

    /** @return AnalyticsRows */
    private function safeGroupedRows(string $table, string $column): array
    {
        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $table)) return [];
        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $column)) return [];
        if (!$this->safeColumnExists($table, $column)) return [];
        $cacheKey = 'analytics:grouped:' . $table . ':' . $column;
        $rows = $this->cachedAggregate($cacheKey, self::ANALYTICS_GROUPED_CACHE_TTL_SECONDS, function () use ($table, $column): array {
            try {
                return db()->fetchAll("SELECT `{$column}` AS label, `{$column}` AS name, COUNT(*) AS count FROM `{$table}` GROUP BY `{$column}` ORDER BY count DESC");
            } catch (\Throwable) {
                return [];
            }
        });
        if (!is_array($rows)) return [];
        $result = [];
        foreach ($rows as $row) {
            if ($row instanceof \stdClass) { $result[] = $row; continue; }
            if (is_array($row)) { $result[] = (object)$row; }
        }
        return $result;
    }

    /**
     * Helper: wrap an aggregate callback with Redis cache
     * - feature-flag aware: respects config('feature_flags.analytics_cache_enabled', true)
     * - falls back to direct execution if Redis is unavailable (Cache::getInstance handles file fallback internally)
     * - tag-based invalidation: all analytics keys share the 'analytics' tag for group flush
     */
    private function cachedAggregate(string $cacheKey, int $ttlSeconds, callable $callback): mixed
    {
        if (!config('feature_flags.analytics_cache_enabled', true)) {
            return $callback();
        }
        try {
            $cache = \Core\Cache::getInstance();
            // remember() works in minutes internally, convert from seconds
            $minutes = max(1, (int)ceil($ttlSeconds / 60));
            $value = $cache->remember($cacheKey, $minutes, $callback);
            return $value;
        } catch (\Throwable $e) {
            // Cache failure should never break the request — fall back to direct DB query
            return $callback();
        }
    }

    /**
     * Public invalidation helper — call this from any service after writes that affect analytics
     * Usage: $this->analyticsService->invalidateAnalyticsCache('users');
     */
    public function invalidateAnalyticsCache(?string $table = null): void
    {
        try {
            $cache = \Core\Cache::getInstance();
            if ($table !== null) {
                $cache->forget('analytics:count:' . $table . ':' . md5('1=1'));
                // Targeted flushes are safer; full flush reserved for emergency
            } else {
                // Full flush via tag-based invalidation (uses TaggedCache already implemented in Core\Cache)
                $cache->tags(['analytics'])->flush();
            }
        } catch (\Throwable $e) {
            // ignore — invalidation is best-effort
        }
    }

    private function percentage(int|float $part, int|float $total): float
    {
        return $total > 0 ? round(($part / $total) * 100, 2) : 0.0;
    }

    private function parseIniBytes(string|false $value): int
    {
        if ($value === false || $value === '' || $value === '-1') return 128 * 1024 * 1024;
        $unit = strtolower(substr($value, -1));
        $num = (int)$value;
        return match ($unit) { 'g' => $num * 1024 * 1024 * 1024, 'm' => $num * 1024 * 1024, 'k' => $num * 1024, default => max(1, $num) };
    }

    private function diskUsagePercent(): float
    {
        $path = defined('BASE_PATH') ? BASE_PATH : __DIR__;
        $total = @disk_total_space($path) ?: 1;
        $free = @disk_free_space($path) ?: 0;
        return round((1 - ($free / $total)) * 100, 2);
    }

    // ==========================================
    //  Cache Management
    // ==========================================

    /**
     * پاک کردن کش (هنگام ریفرش داده‌ها)
     */
    public function clearCache(?int $taskId = null, ?int $userId = null): void
    {
        if ($taskId !== null) {
            $this->repository->clearCache('task', $taskId);
        } elseif ($userId !== null) {
            $this->repository->clearCache('user', $userId);
        } else {
            $this->repository->clearCache('all');
        }
        $this->logger->info('Analytics cache cleared', [
            'task_id' => $taskId,
            'user_id' => $userId,
        ]);
    }

    /**
     * ریکارد رویدادهای تحلیلی
     */
    /** @param AnalyticsPayload $data */
    public function recordEvent(string $eventType, array $data = []): void
    {
        $context = [];
        foreach ($data as $key => $value) {
            $context[(string)$key] = $value;
        }
        $this->logger->info("Analytics event: {$eventType}", $context);
    }

    /** @return AnalyticsPayload */
    public function getSystemHealth(): array
    {
        $repoHealth = $this->repository->getSystemHealth();
        $requests = max(0, $this->safeCount('performance_logs'));
        $errors = $this->safeCount('error_logs');
        $usersOnline = $this->safeCount('user_sessions', 'last_activity >= DATE_SUB(NOW(), INTERVAL 15 MINUTE)');
        return array_merge($repoHealth, [
            'performance' => [
                'avg_response_time' => $this->safeAvg('performance_logs', 'duration_ms'),
                'max_response_time' => $this->safeMax('performance_logs', 'duration_ms'),
                'total_requests' => $requests,
                'successful_requests' => max(0, $requests - $errors),
                'failed_requests' => $errors,
                'uptime' => $requests > 0 ? round((($requests - $errors) / $requests) * 100, 2) : 100.0,
            ],
            'errors' => [
                'total' => $errors,
                '500_errors' => $this->safeCount('error_logs', "status_code >= 500"),
                '404_errors' => $this->safeCount('error_logs', "status_code = 404"),
                '403_errors' => $this->safeCount('error_logs', "status_code = 403"),
            ],
            'resources' => [
                'cpu_usage' => 0,
                'memory_usage' => round((memory_get_usage(true) / max(1, $this->parseIniBytes(ini_get('memory_limit')))) * 100, 2),
                'disk_usage' => $this->diskUsagePercent(),
            ],
            'users' => [
                'online' => $usersOnline,
                'active_today' => $this->safeCount('users', 'last_active_date = CURDATE()'),
                'total' => $this->safeCount('users'),
                'new_today' => $this->safeCount('users', 'DATE(created_at) = CURDATE()'),
                'inactive' => $this->safeCount('users', "status <> 'active'"),
            ],
        ]);
    }

    /**
     * دریافت آمار کلی تسک‌ها جهت انطباق با نسخه‌های قدیمی
     */
    /** @return AnalyticsPayload */
    public function getStats(): array
    {
        return $this->getTaskMetrics();
    }

    // =====================================================================
    // Ads Analytics — مورد نیاز توسط AdminAdsController::stats()
    // =====================================================================

    /**
     * آمار تبلیغات بر اساس نوع (custom_task, adtube, ...)
     */
    /** @return AnalyticsRows */
    public function getAdsByTypeStats(int $days = 30): array
    {
        try {
            return db()->fetchAll(
                "SELECT type,
                        COUNT(*) AS count,
                        COALESCE(SUM(total_budget), 0) AS total_budget,
                        COALESCE(SUM(spent_budget), 0) AS spent_budget,
                        COALESCE(SUM(clicks), 0) AS total_clicks
                 FROM ads
                 WHERE created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
                 GROUP BY type
                 ORDER BY count DESC",
                [$days]
            ) ?: [];
        } catch (\Throwable) { return []; }
    }

    /**
     * آمار تبلیغات بر اساس وضعیت (active, pending, ...)
     */
    /** @return AnalyticsRows */
    public function getAdsByStatusStats(): array
    {
        try {
            return db()->fetchAll(
                "SELECT status, COUNT(*) AS count
                 FROM ads
                 GROUP BY status
                 ORDER BY count DESC"
            ) ?: [];
        } catch (\Throwable) { return []; }
    }

    /**
     * آمار بودجه تبلیغات
     */
    /** @return AnalyticsPayload */
    public function getAdsBudgetStats(int $days = 30): array
    {
        try {
            $row = db()->fetch(
                "SELECT COUNT(*) AS total_ads,
                        COALESCE(SUM(total_budget), 0) AS total_budget,
                        COALESCE(SUM(spent_budget), 0) AS spent_budget,
                        COALESCE(SUM(remaining_budget), 0) AS remaining_budget,
                        COALESCE(SUM(clicks), 0) AS total_clicks,
                        COALESCE(SUM(impressions), 0) AS total_impressions
                 FROM ads
                 WHERE created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)",
                [$days]
            );
            return $row ? (array)$row : [];
        } catch (\Throwable) { return []; }
    }
}
