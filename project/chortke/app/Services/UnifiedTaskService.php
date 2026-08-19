<?php

declare(strict_types=1);

namespace App\Services;

use Core\Database;
use App\Contracts\LoggerInterface;

/**
 * UnifiedTaskService - هاب مرکزی مدیریت و فیلترینگ یکپارچه انواع تسک‌ها (SEO, Social, Custom)
 */
class UnifiedTaskService
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




    private \Core\Database $db;
    public function __construct(
        \Core\Database $db
    ) {        $this->db = $db;

        
        }

    /**
     * دریافت تسک‌های معتبر و انجام نشده برای کاربر به صورت تجمیعی
     */
    /**
     * @param array<string, mixed> $filters
     * @return list<\stdClass>
     */
    public function getTasksForExecutor(int $userId, array $filters = [], int $limit = 30, int $offset = 0): array
    {
        // ۱. فیلترهای پایه: آگهی فعال، دارای ظرفیت و از انواع مجاز
        $where = [
            "a.status = 'active'",
            "a.deleted_at IS NULL",
            "a.type IN ('seo', 'social', 'social_task', 'custom_task')",
            "((a.type = 'seo' AND COALESCE(a.remaining_budget, a.budget, a.total_budget, 0) >= GREATEST(COALESCE(NULLIF(a.min_payout, 0), NULLIF(a.price_per_click, 0), NULLIF(a.price_per_task, 0), 1), 1)) OR (a.type IN ('social', 'social_task', 'custom_task') AND COALESCE(a.remaining_count, (COALESCE(a.total_count,0) - COALESCE(a.completed_count,0) - COALESCE(a.pending_count,0)), 0) > 0))"
        ];
        $params = [];

        // انجام‌دهنده نباید تسک/آگهی خودش را در بازار اجرای تسک ببیند.
        $where[] = "COALESCE(a.user_id, 0) <> ?";
        $params[] = $userId;

        // ۲. استثنا قائل شدن برای Adtube (طبق دستور کاربر: یوتیوب باید از لیست اصلی مستثنی باشد و جدا مدیریت شود)
        $where[] = "(a.platform != 'youtube' OR a.platform IS NULL)";

        $where[] = "NOT EXISTS (SELECT 1 FROM social_task_executions WHERE ad_id = a.id AND executor_id = ? AND status NOT IN ('cancelled','expired','rejected'))";
        $where[] = "NOT EXISTS (SELECT 1 FROM seo_executions WHERE ad_id = a.id AND user_id = ? AND execution_date = CURDATE())";
        $where[] = "NOT EXISTS (SELECT 1 FROM custom_task_submissions WHERE task_id = a.id AND worker_id = ? AND status NOT IN ('expired','cancelled','rejected'))";
        $params[] = $userId;
        $params[] = $userId;
        $params[] = $userId;

        // ۴. اعمال فیلترهای درخواستی کاربر (Smart Filters)
        if (!empty($filters['type'])) {
            $type = strtolower(trim(str_value($filters['type'])));
            if ($type === 'social' || $type === 'social_task') {
                $where[] = "a.type IN ('social', 'social_task')";
            } elseif ($type === 'custom' || $type === 'custom_task') {
                $where[] = "a.type = ?";
                $params[] = 'custom_task';
            } else {
                $where[] = "a.type = ?";
                $params[] = $type;
            }
        }

        if (!empty($filters['platform'])) {
            $where[] = "a.platform = ?";
            $params[] = $filters['platform'];
        }

        if (!empty($filters['min_price'])) {
            $where[] = "a.price_per_task >= ?";
            $params[] = float_value($filters['min_price']);
        }

        if (!empty($filters['max_price'])) {
            $where[] = "a.price_per_task <= ?";
            $params[] = float_value($filters['max_price']);
        }

        if (!empty($filters['q'])) {
            $where[] = "(a.title LIKE ? OR a.description LIKE ?)";
            $sanitizedQ = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], str_value($filters['q']));
            $like = '%' . $sanitizedQ . '%';
            $params[] = $like;
            $params[] = $like;
        }

        // ۵. مرتب‌سازی هوشمند (Smart Ordering)
        $orderBy = "a.created_at DESC";
        if (!empty($filters['sort'])) {
            switch ($filters['sort']) {
                case 'highest_price':
                    $orderBy = "a.price_per_task DESC";
                    break;
                case 'lowest_price':
                    $orderBy = "a.price_per_task ASC";
                    break;
                case 'oldest':
                    $orderBy = "a.created_at ASC";
                    break;
            }
        }

        $whereSql = implode(" AND ", $where);

        // اصلاح کلیدی معماری موبایل (Cursor-Based Pagination Guard):
        // در صورت ارسال شناسه نشانگر (cursor) از سوی اپلیکیشن موبایل، عملگر سنگین OFFSET لغو شده و واکشی بر اساس نشانگر انجام می‌شود
        if (!empty($filters['cursor'])) {
            $cursorId = int_value($filters['cursor']);
            $operator = str_contains($orderBy, 'ASC') ? '>' : '<';
            $whereSql .= " AND a.id {$operator} {$cursorId}";
            $sql = "SELECT a.*, u.full_name as advertiser_name
                    FROM ads a
                    LEFT JOIN users u ON u.id = a.user_id
                    WHERE {$whereSql}
                    ORDER BY {$orderBy}
                    LIMIT {$limit}";
        } else {
            $sql = "SELECT a.*, u.full_name as advertiser_name
                    FROM ads a
                    LEFT JOIN users u ON u.id = a.user_id
                    WHERE {$whereSql}
                    ORDER BY {$orderBy}
                    LIMIT {$limit} OFFSET {$offset}";
        }

        return $this->db->fetchAll($sql, $params);
    }

    /**
     * شمارش تعداد کل تسک‌های قابل نمایش برای صفحه‌بندی
     */
    /** @param array<string, mixed> $filters */
    public function countTasksForExecutor(int $userId, array $filters = []): int
    {
        $where = [
            "a.status = 'active'",
            "a.deleted_at IS NULL",
            "a.type IN ('seo', 'social', 'social_task', 'custom_task')",
            "((a.type = 'seo' AND COALESCE(a.remaining_budget, a.budget, a.total_budget, 0) >= GREATEST(COALESCE(NULLIF(a.min_payout, 0), NULLIF(a.price_per_click, 0), NULLIF(a.price_per_task, 0), 1), 1)) OR (a.type IN ('social', 'social_task', 'custom_task') AND COALESCE(a.remaining_count, (COALESCE(a.total_count,0) - COALESCE(a.completed_count,0) - COALESCE(a.pending_count,0)), 0) > 0))"
        ];
        $params = [];

        // انجام‌دهنده نباید تسک/آگهی خودش را در بازار اجرای تسک ببیند.
        $where[] = "COALESCE(a.user_id, 0) <> ?";
        $params[] = $userId;

        $where[] = "(a.platform != 'youtube' OR a.platform IS NULL)";

        $where[] = "NOT EXISTS (SELECT 1 FROM social_task_executions WHERE ad_id = a.id AND executor_id = ? AND status NOT IN ('cancelled','expired','rejected'))";
        $where[] = "NOT EXISTS (SELECT 1 FROM seo_executions WHERE ad_id = a.id AND user_id = ? AND execution_date = CURDATE())";
        $where[] = "NOT EXISTS (SELECT 1 FROM custom_task_submissions WHERE task_id = a.id AND worker_id = ? AND status NOT IN ('expired','cancelled','rejected'))";
        $params[] = $userId;
        $params[] = $userId;
        $params[] = $userId;

        if (!empty($filters['type'])) {
            $type = strtolower(trim(str_value($filters['type'])));
            if ($type === 'social' || $type === 'social_task') {
                $where[] = "a.type IN ('social', 'social_task')";
            } elseif ($type === 'custom' || $type === 'custom_task') {
                $where[] = "a.type = ?";
                $params[] = 'custom_task';
            } else {
                $where[] = "a.type = ?";
                $params[] = $type;
            }
        }
        if (!empty($filters['platform'])) {
            $where[] = "a.platform = ?";
            $params[] = $filters['platform'];
        }
        if (!empty($filters['min_price'])) {
            $where[] = "a.price_per_task >= ?";
            $params[] = float_value($filters['min_price']);
        }
        if (!empty($filters['max_price'])) {
            $where[] = "a.price_per_task <= ?";
            $params[] = float_value($filters['max_price']);
        }
        if (!empty($filters['q'])) {
            $where[] = "(a.title LIKE ? OR a.description LIKE ?)";
            $sanitizedQ = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], str_value($filters['q']));
            $like = '%' . $sanitizedQ . '%';
            $params[] = $like;
            $params[] = $like;
        }

        $whereSql = implode(" AND ", $where);
        $sql = "SELECT COUNT(*) FROM ads a WHERE {$whereSql}";

        $result = $this->toObject($this->db->fetch($sql, $params));
        // Extract count safely
        $count = 0;
        if ($result) {
            $arr = (array)$result;
            $count = (int)reset($arr);
        }
        return $count;
    }

    /**
     * دریافت لیست پلتفرم‌های موجود جهت اعمال در فرم‌های فیلترینگ
     */
    /** @return list<\stdClass> */
    public function getAvailablePlatforms(): array
    {
        return $this->db->fetchAll("SELECT DISTINCT platform FROM ads WHERE platform IS NOT NULL AND platform != 'youtube'");
    }

    /**
     * executor کے اعدادوشمار حاصل کریں
     */
    /** @return array<string, mixed> */
    public function getExecutorStats(int $userId): array
    {
        $stats = $this->toObject($this->db->fetch("
            SELECT 
                (SELECT COUNT(*) FROM social_task_executions WHERE executor_id = ? AND status IN ('approved','completed')) as social_done,
                (SELECT COUNT(*) FROM seo_executions WHERE user_id = ? AND status = 'completed') as seo_done,
                (SELECT COUNT(*) FROM custom_task_submissions WHERE worker_id = ? AND status = 'approved') as custom_done,
                (SELECT COUNT(*) FROM social_task_executions WHERE executor_id = ? AND status IN ('pending', 'in_progress', 'submitted')) as social_pending,
                (SELECT COUNT(*) FROM seo_executions WHERE user_id = ? AND status IN ('started', 'processing', 'pending')) as seo_pending,
                (SELECT COUNT(*) FROM custom_task_submissions WHERE worker_id = ? AND status IN ('in_progress', 'submitted', 'pending')) as custom_pending,
                (SELECT COALESCE(SUM(price_per_task * remaining_count), 0) FROM ads WHERE type IN ('seo', 'social', 'social_task', 'custom_task') AND status = 'active') as available_earnings
        ", [$userId, $userId, $userId, $userId, $userId, $userId]));

        $socialDone = (int)($stats->social_done ?? 0);
        $seoDone = (int)($stats->seo_done ?? 0);
        $customDone = (int)($stats->custom_done ?? 0);
        $totalCompleted = $socialDone + $seoDone + $customDone;

        $socialPending = (int)($stats->social_pending ?? 0);
        $seoPending = (int)($stats->seo_pending ?? 0);
        $customPending = (int)($stats->custom_pending ?? 0);
        $totalPending = $socialPending + $seoPending + $customPending;

        return [
            'total_completed' => $totalCompleted,
            'social_completed' => $socialDone,
            'seo_completed' => $seoDone,
            'custom_completed' => $customDone,
            'pending_total' => $totalPending,
            'social_pending' => $socialPending,
            'seo_pending' => $seoPending,
            'custom_pending' => $customPending,
            'available_earnings' => float_value($stats->available_earnings ?? 0),
        ];
    }
}
