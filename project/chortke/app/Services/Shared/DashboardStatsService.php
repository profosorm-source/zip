<?php

declare(strict_types=1);

namespace App\Services\Shared;

use Core\Database;
use Core\Cache;
use App\Contracts\NotificationServiceInterface;
use App\Models\AdvancedAnalytics;
use App\Contracts\LoggerInterface;


/**
 * DashboardStatsService - سرویس اشتراکی تحلیل داده‌ها و آمارها
 * 
 * این سرویس یک unified facade برای تمام آنالیتیکس است:
 * - تحلیل‌های کلی
 * - تحلیل‌های Referral
 * - تحلیل‌های Notification
 * - تحلیل‌های پیشرفته
 * 
 * NOTE: CustomTask analytics moved to App\Services\Analytics\AnalyticsService
 */
class DashboardStatsService
{
    private \Core\Cache $cache;
    private \Core\Database $db;
    private \App\Contracts\LoggerInterface $logger;
    private NotificationServiceInterface $notificationService;
    private AdvancedAnalytics $advancedAnalytics;
    private \App\Services\Analytics\AnalyticsService $customTaskAnalytics;
    public function __construct(
        \Core\Cache $cache,
        \Core\Database $db,
        \App\Contracts\LoggerInterface $logger,
        NotificationServiceInterface $notificationService,
        AdvancedAnalytics $advancedAnalytics,
        \App\Services\Analytics\AnalyticsService $customTaskAnalytics
    ) {        $this->cache = $cache;
        $this->db = $db;
        $this->logger = $logger;
        $this->notificationService = $notificationService;
        $this->advancedAnalytics = $advancedAnalytics;
        $this->customTaskAnalytics = $customTaskAnalytics;

        // انتقال زیرساخت‌های مشترک به کلاس والد
                $this->notificationService = $notificationService;
        $this->advancedAnalytics = $advancedAnalytics;
        $this->customTaskAnalytics = $customTaskAnalytics;
    }
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


    /**


    /**
     * دریافت آمارهای کلی سیستم
     */
    /** @return array<string, int> */
    public function getGlobalStats(): array
    {
        /** @var array<string, int> $result */
        $result = $this->cache->remember('global_stats', 3600, function() {
            $sql = "SELECT 
                        (SELECT COUNT(*) FROM users) as users_count,
                        (SELECT COUNT(*) FROM custom_tasks WHERE status = ?) as active_tasks";
            $stmt = $this->db->prepare($sql);
            $stmt->execute(['active']);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
            $usersCount = is_array($row) ? ($row['users_count'] ?? 0) : 0;
            $activeTasks = is_array($row) ? ($row['active_tasks'] ?? 0) : 0;

            return [
                'users_count' => is_int($usersCount) || (is_string($usersCount) && is_numeric($usersCount)) ? (int)$usersCount : 0,
                'active_tasks' => is_int($activeTasks) || (is_string($activeTasks) && is_numeric($activeTasks)) ? (int)$activeTasks : 0,
            ];
        });
        return $result;
    }

    /**
     * تحلیل روندها (Trends)
     */
    /** @return array<string, mixed> */
    public function getTrends(string $metric, string $period = 'daily'): array
    {
        $days = $period === 'monthly' ? 90 : ($period === 'weekly' ? 30 : 7);
        
        // Map general metric identifier to physical allowed tables
        $table = $metric === 'users' ? 'users' : 'transactions';
        return $this->getTrendData($table, 'created_at', $days);
    }

    /** @param array<string, mixed> $conditions
     *  @param list<string> $groupByColumns
     *  @return array<string, mixed> */
    public function getTrend(
        string $table,
        string $dateColumn = 'created_at',
        int $days = 30,
        array $conditions = [],
        array $groupByColumns = []
    ): array {
        return [
            'data' => $this->getTrendData($table, $dateColumn, $days, $conditions, $groupByColumns),
        ];
    }

    /** @param array<string, mixed> $conditions */
    public function getCount(string $table, array $conditions = []): int
    {
        $this->validateIdentifier($table);
        [$where, $params] = $this->buildWhere($conditions);

        $sql = "SELECT COUNT(*) as total FROM `{$table}` WHERE {$where}";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        return (int)($stmt->fetchColumn() ?: 0);
    }

    /** @param array<string, mixed> $conditions
     *  @return array<string, mixed> */
    public function getDistribution(
        string $table,
        string $column,
        array $conditions = [],
        int $limit = 10
    ): array {
        return $this->advancedAnalytics->getDistributionData($table, $column, $conditions, $limit);
    }

    /** @param array<string, mixed> $conditions
     *  @param list<string> $selectColumns
     *  @return array<string, mixed> */
    public function getAggregates(string $table, array $conditions = [], array $selectColumns = []): array
    {
        $this->validateIdentifier($table);
        
        $cleanSelect = [];
        if (empty($selectColumns)) {
            $cleanSelect[] = 'COUNT(*) as total';
        } else {
            foreach ($selectColumns as $col) {
                $this->validateIdentifier($col);
                $cleanSelect[] = "`{$col}`";
            }
        }
        
        $select = implode(', ', $cleanSelect);
        [$where, $params] = $this->buildWhere($conditions);

        $sql = "SELECT {$select} FROM `{$table}` WHERE {$where}";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        $result = $stmt->fetch(
            \PDO::FETCH_ASSOC
        );

        /** @var array<string, mixed> $aggregates */
        $aggregates = $result ?: [];
        return $aggregates;
    }

    public function exportToCsv(int $adId, int $userId): string
    {
        // Crucial ownership validation to prevent IDOR
        $ad = $this->toObject($this->db->table('seo_ads')
            ->where('id', '=', $adId)
            ->first());

        if (!$ad || !isset($ad->id) || (int)($ad->user_id ?? 0) !== $userId) {
            throw new \InvalidArgumentException("Access denied: The requesting user does not own the requested ad resources.");
        }

        $sql = "SELECT e.id, e.created_at, e.status, e.payout_amount, e.final_score
                FROM seo_executions e
                INNER JOIN seo_ads a ON e.ad_id = a.id
                WHERE e.ad_id = ? AND a.user_id = ?
                ORDER BY e.created_at DESC";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([$adId, $userId]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        $handle = fopen('php://memory', 'rw');
        if ($handle === false) {
            return '';
        }

        $headers = ['id', 'created_at', 'status', 'payout_amount', 'final_score'];
        fputcsv($handle, $headers);

        foreach ($rows as $row) {
            fputcsv($handle, [
                $row['id'],
                $row['created_at'],
                $row['status'],
                $row['payout_amount'],
                $row['final_score'],
            ]);
        }

        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        return $csv ?: '';
    }

    private function validateIdentifier(string $identifier): void
    {
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $identifier)) {
            throw new \InvalidArgumentException("Potential SQL injection vector blocked: invalid database identifier '{$identifier}'.");
        }
    }

    /** @param array<string, mixed> $conditions
     *  @return array{0: string, 1: array<int, mixed>} */
    private function buildWhere(array $conditions): array
    {
        $where = [];
        $params = [];

        foreach ((array)$conditions as $column => $value) {
            $this->validateIdentifier($column);

            if (is_array($value) && count($value) === 2 && strtoupper($value[0]) === 'IN' && is_array($value[1])) {
                if (empty($value[1])) {
                    $where[] = '0=1';
                    continue;
                }

                $placeholders = implode(', ', array_fill(0, count($value[1]), '?'));
                $where[] = "`{$column}` IN ({$placeholders})";
                $params = array_merge($params, $value[1]);
                continue;
            }

            if ($value === null) {
                $where[] = "`{$column}` IS NULL";
                continue;
            }

            $where[] = "`{$column}` = ?";
            $params[] = $value;
        }

        return [empty($where) ? '1=1' : implode(' AND ', $where), $params];
    }

    // ═══════════════════════════════════════════════════════════════════════
    // Delegation Methods — هدایت درخواست‌ها به سرویس‌های مختلف
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * تحلیل Custom Task
     *
     * @return array<int|string, mixed>
     */
    public function getCustomTaskStats(): array
    {
        return $this->customTaskAnalytics->getStats();
    }

    /**
     * تحلیل Referral
     */
    /** @return array<string, mixed> */
    public function getReferralStats(): array
    {
        try {
            $total = (int)($this->db->fetchColumn("SELECT COUNT(*) FROM referral_commissions") ?: 0);
            $paid = (int)($this->db->fetchColumn("SELECT COUNT(*) FROM referral_commissions WHERE status = 'paid'") ?: 0);
            $pending = (int)($this->db->fetchColumn("SELECT COUNT(*) FROM referral_commissions WHERE status = 'pending'") ?: 0);
            $paidAmount = (string)($this->db->fetchColumn("SELECT COALESCE(SUM(amount), 0) FROM referral_commissions WHERE status = 'paid'") ?: '0');

            return [
                'total_commissions' => $total,
                'paid_commissions' => $paid,
                'pending_commissions' => $pending,
                'paid_amount' => $paidAmount,
            ];
        } catch (\Throwable $e) {
            $this->logger->warning('shared_analytics.referral_stats_failed', ['error' => $e->getMessage()]);
            return [
                'total_commissions' => 0,
                'paid_commissions' => 0,
                'pending_commissions' => 0,
                'paid_amount' => '0',
            ];
        }
    }

    /**
     * تحلیل Notification
     */
    /** @return array<string, mixed> */
    public function getNotificationStats(): array
    {
        return $this->notificationService->getAnalyticsOverview();
    }

    /**
     * تحلیل پیشرفته
     */
    /** @return array<string, mixed> */
    public function getAdvancedStats(): array
    {
        return [
            'users_retention' => $this->advancedAnalytics->getRetentionRateData('users', 'id', 'created_at'),
            'transactions_descriptive' => $this->advancedAnalytics->getDescriptiveStatsData('transactions', 'amount'),
        ];
    }

    /**
     * تحلیل روند (پیشرفته)
     */
    /** @param array<string, mixed> $conditions
     *  @param list<string> $groupByColumns
     *  @return array<string, mixed> */
    public function getTrendData(
        string $table,
        string $dateColumn = 'created_at',
        int $days = 30,
        array $conditions = [],
        array $groupByColumns = []
    ): array {
        return $this->advancedAnalytics->getTrendData($table, $dateColumn, $days, $conditions, $groupByColumns);
    }

    /**
     * مقایسه دو بازه زمانی
     */
    /** @param array<string, mixed> $conditions
     *  @return array<string, mixed> */
    public function comparePeriods(
        string $table,
        string $dateColumn = 'created_at',
        int $currentPeriodDays = 7,
        array $conditions = []
    ): array {
        $currentData = $this->advancedAnalytics->getPeriodStatsData($table, $dateColumn, 0, $currentPeriodDays, $conditions);
        $previousData = $this->advancedAnalytics->getPeriodStatsData($table, $dateColumn, $currentPeriodDays, $currentPeriodDays, $conditions);

        return [
            'current' => $currentData,
            'previous' => $previousData,
            'change_percentage' => $previousData['total'] > 0
                ? round((($currentData['total'] - $previousData['total']) / $previousData['total']) * 100, 2)
                : null,
        ];
    }

    /**
     * نوتیفیکیشن: تحلیل آمار
     */
    /** @param array<string, mixed> $data */
    public function trackNotification(array $data): void
    {
        // Tracking is now handled directly in NotificationService
        $this->logger->info('notification.analytics.track', $data);
    }

    /**
     * نوتیفیکیشن: دریافت آمار
     */
    /** @return array<string, mixed> */
    public function getNotificationMetrics(): array
    {
        return $this->notificationService->getAnalyticsFunnelStats();
    }

    // ═══════════════════════════════════════════════════════════════════════
    // System Metrics (Prometheus / Health Checks)
    // ═══════════════════════════════════════════════════════════════════════

    public function getRecentRequestsCount(int $minutes = 5): int
    {
        try {
            return (int)$this->db->query("SELECT COUNT(*) FROM activity_logs WHERE created_at >= DATE_SUB(NOW(), INTERVAL ? MINUTE)", [$minutes])->fetchColumn();
        } catch (\Throwable $e) {
            return 0;
        }
    }

    public function getRecentErrorsCount(string $level = 'error', int $minutes = 5): int
    {
        try {
            return (int)$this->db->query("SELECT COUNT(*) FROM system_logs WHERE level = ? AND created_at >= DATE_SUB(NOW(), INTERVAL ? MINUTE)", [$level, $minutes])->fetchColumn();
        } catch (\Throwable $e) {
            return 0;
        }
    }

    public function getActiveUsersCount(): int
    {
        try {
            return (int)$this->db->query("SELECT COUNT(*) FROM users WHERE status = 1")->fetchColumn();
        } catch (\Throwable $e) {
            return 0;
        }
    }

    public function isDatabaseUp(): bool
    {
        try {
            $this->db->query("SELECT 1");
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }
}
