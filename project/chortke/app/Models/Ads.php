<?php

declare(strict_types=1);

namespace App\Models;

use Core\Model;
use App\Contracts\AdsRepositoryInterface;
use App\Traits\Filterable;
use Core\ValueObjects\Money;

/**
 * Ads Model - متمرکزکننده تمام انواع تبلیغات در سیستم
 */
class Ads extends Model implements AdsRepositoryInterface
{
    use Filterable;

    protected static string $table = 'ads';

    /**
     * Only normalized adapter payloads reach Ads::create(). These campaign
     * columns are required to persist the same budget/count snapshot that the
     * central escrow manager locked; silently dropping them creates an ad whose
     * database budget disagrees with its locked funds.
     *
     * @var list<string>
     */
    protected array $fillable = [
        'user_id', 'title', 'description', 'type', 'status', 'currency',
        'platform', 'task_type', 'link', 'target_url',
        'price_per_task', 'price_per_click',
        'total_budget', 'remaining_budget', 'spent_budget',
        'total_count', 'remaining_count', 'pending_count', 'completed_count',
        'site_commission_percent', 'created_by',
        'budget', 'reward', 'metadata', 'created_at', 'updated_at',
    ];
    protected static array $searchable = ['ads.title', 'ads.description'];

    /** @var array<string, string|array{0: string, 1: string}> */
    protected static array $filterable = [
        'type' => '=',
        'status' => '=',
        'user_id' => '=',
        'a.type' => ['a.type', '='],
        'a.status' => ['a.status', '=']
    ];

    private function rowString(\stdClass $row, string $field, string $default = ''): string
    {
        $value = get_object_vars($row)[$field] ?? null;
        return is_scalar($value) ? (string)$value : $default;
    }

    private function rowInt(\stdClass $row, string $field, int $default = 0): int
    {
        $value = get_object_vars($row)[$field] ?? null;
        if (is_int($value)) return $value;
        if (is_string($value) && preg_match('/^-?\d+$/', $value) === 1) return (int)$value;
        return $default;
    }

    /** @param array<string, mixed> $filters */
    private function filterString(array $filters, string $field): ?string
    {
        $value = $filters[$field] ?? null;
        if (!is_scalar($value)) return null;
        $value = trim((string)$value);
        return $value === '' ? null : $value;
    }

    /** @param array<string, mixed> $filters */
    private function filterPositiveInt(array $filters, string $field): ?int
    {
        $value = $filters[$field] ?? null;
        if (is_int($value)) return $value > 0 ? $value : null;
        if (is_string($value) && ctype_digit($value)) {
            $integer = (int)$value;
            return $integer > 0 ? $integer : null;
        }
        return null;
    }

    /**
     * یافتن با قفل تراکنشی
     */
    public function findByIdForUpdate(int $id): ?\stdClass
    {
        if (!$this->db->inTransaction()) {
            throw new \RuntimeException("findByIdForUpdate must be called within an active database transaction.");
        }
        $stmt = $this->db->prepare("SELECT * FROM ads WHERE id = ? FOR UPDATE");
        $stmt->execute([$id]);
        $row = $stmt->fetch(\PDO::FETCH_OBJ);
        return $row instanceof \stdClass ? $row : null;
    }

    public function cancelAdRemainingBudget(int $id): bool
    {
        $stmt = $this->db->prepare("UPDATE ads SET remaining_budget = 0, updated_at = NOW() WHERE id = ?");
        return $stmt->execute([$id]);
    }

    public function completeAdAndClearBudget(int $id, bool $softDelete = false): bool
    {
        $sql = "UPDATE ads SET remaining_budget = 0, status = 'completed', updated_at = NOW()";
        if ($softDelete) {
            $sql .= ", deleted_at = NOW()";
        }
        $sql .= " WHERE id = ?";
        $stmt = $this->db->prepare($sql);
        return $stmt->execute([$id]);
    }

    public function cancelUserCustomTasks(int $userId): bool
    {
        // Notice: This matches the old behavior of querying custom_tasks table
        $stmt = $this->db->prepare("UPDATE custom_tasks SET status = 'cancelled', updated_at = NOW() WHERE user_id = ? AND status NOT IN ('completed', 'cancelled')");
        return $stmt->execute([$userId]);
    }

    /** @return list<\stdClass> */
    public function getByAdvertiser(int $userId, int $limit = 20, int $offset = 0, ?string $type = null, ?string $status = null): array
    {
        $q = $this->db->table(static::$table)
            ->where('user_id', '=', $userId);
            
        $this->applyFilters($q, [
            'type' => $type,
            'status' => $status
        ]);

        if ($type === 'custom_task') {
            $q->select('ads.*')
              ->selectRaw('(SELECT COUNT(s.id) FROM custom_task_submissions s WHERE s.task_id = ads.id) as submission_count');
        }

        return $q->orderBy('created_at', 'DESC')
            ->limit($limit)
            ->offset($offset)
            ->get();
    }

    /**
     * دریافت آگهی بر اساس شناسه و کاربر
     */
    public function findByIdAndUser(int $id, int $userId): ?\stdClass
    {
        return $this->db->table(static::$table)
            ->where('id', '=', $id)
            ->where('user_id', '=', $userId)
            ->first() ?: null;
    }

    /**
     * بروزرسانی وضعیت آگهی توسط مالک
     */
    public function updateStatusByUser(int $id, int $userId, string $status): bool
    {
        return $this->db->table(static::$table)
            ->where('id', '=', $id)
            ->where('user_id', '=', $userId)
            ->update([
                'status' => $status,
                'updated_at' => date('Y-m-d H:i:s')
            ]) > 0;
    }

    /**
     * لیست ادمین با فیلتر نوع و وضعیت
     */
    /** @return list<\stdClass> */
    public function adminList(string $type = '', string $status = '', int $limit = 30, int $offset = 0): array
    {
        $q = $this->db->table(static::$table . ' as a')
            ->select('a.*', 'u.full_name as user_name', 'u.email as user_email')
            ->leftJoin('users as u', 'u.id', '=', 'a.user_id');
            
        $this->applyFilters($q, [
            'a.type' => $type,
            'a.status' => $status
        ]);
        
        return $q->orderBy('a.created_at', 'DESC')
            ->limit($limit)
            ->offset($offset)
            ->get();
    }

    /**
     * شمارش ادمین با فیلتر نوع و وضعیت
     */
    public function adminCount(string $type = '', string $status = ''): int
    {
        $q = $this->db->table(static::$table);
            
        $this->applyFilters($q, [
            'type' => $type,
            'status' => $status
        ]);
        
        return $q->count();
    }

    /**
     * جستجوی ادمین/ماژول برای آگهی‌ها و تسک‌ها از طریق مدل Ads.
     */
    /**
         * @param array<string, mixed> $filters
         * @return array{items: list<\stdClass>, total: int}
         */
    public function searchAdminTasks(string $q = '', array $filters = [], int $limit = 20, int $offset = 0): array
    {
        $limit = max(1, min(100, $limit));
        $offset = max(0, $offset);

        $where = ['a.deleted_at IS NULL'];
        $params = [];

        $type = $this->filterString($filters, 'type');
        if ($type !== null) {
            $type = strtolower($type);
            if ($type === 'social' || $type === 'social_task') {
                $where[] = "a.type IN ('social', 'social_task')";
            } elseif ($type === 'custom' || $type === 'custom_task') {
                $where[] = 'a.type = ?';
                $params[] = 'custom_task';
            } else {
                $where[] = 'a.type = ?';
                $params[] = $type;
            }
        }
        $status = $this->filterString($filters, 'status');
        if ($status !== null) {
            $where[] = 'a.status = ?';
            $params[] = $status;
        }
        $platform = $this->filterString($filters, 'platform');
        if ($platform !== null) {
            $where[] = 'a.platform = ?';
            $params[] = $platform;
        }
        $userId = $this->filterPositiveInt($filters, 'user_id');
        if ($userId !== null) {
            $where[] = 'a.user_id = ?';
            $params[] = $userId;
        }
        if ($q !== '') {
            $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q) . '%';
            $where[] = '(a.title LIKE ? OR a.description LIKE ? OR a.keyword LIKE ?)';
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }

        $whereSql = implode(' AND ', $where);
        $items = $this->db->fetchAll(
            "SELECT a.*, u.full_name AS user_name, u.email AS user_email
             FROM ads a
             LEFT JOIN users u ON u.id = a.user_id
             WHERE {$whereSql}
             ORDER BY a.created_at DESC
             LIMIT {$limit} OFFSET {$offset}",
            $params
        ) ?: [];
        $total = (int)$this->db->fetchColumn("SELECT COUNT(*) FROM ads a WHERE {$whereSql}", $params);

        return ['items' => $items, 'total' => $total];
    }

    /**
     * جستجوی تبلیغات SEO فعال
     * M08: Fixed LIKE injection with proper escaping
     */
    /** @return list<\stdClass> */
    public function getActiveForSearch(string $keyword, int $limit = 5): array
    {
        $now = date('Y-m-d H:i:s');
        // M08: Use escapeLikeValue() to prevent wildcard injection
        $escaped = $this->escapeLikeValue($keyword);
        $likeKeyword = '%' . $escaped . '%';
        
        return $this->db->table(static::$table)
            ->where('type', '=', 'seo')
            ->where('status', '=', 'active')
            ->where('remaining_budget', '>', 0)
            ->whereRaw('(deadline IS NULL OR deadline > ?)', [$now])
            ->where('keyword', 'LIKE', $likeKeyword)
            ->orderBy('price_per_click', 'DESC')
            ->orderBy('created_at', 'ASC')
            ->limit($limit)
            ->get();
    }

    /**
     * Atomically deduct a decimal monetary click cost. Counter updates remain
     * integer/float metrics, while budget never passes through float.
     */
    public function deductClick(int $id, string $amount): bool
    {
        if (bccomp($amount, '0', 8) < 0) {
            throw new \InvalidArgumentException("Deduction amount cannot be negative: {$amount}");
        }
        if (!$this->db->inTransaction()) {
            throw new \RuntimeException("deductClick must be executed within an active database transaction.");
        }
        $stmt = $this->db->prepare("SELECT id, remaining_budget, status, currency FROM `" . static::$table . "` WHERE id = ? AND status = 'active' FOR UPDATE");
        $stmt->execute([$id]);
        $row = $this->fetchObject($stmt);
        if ($row === null) return false;
        $currentRemaining = $this->rowString($row, 'remaining_budget');
        if ($currentRemaining === '') return false;
        $currency = $this->rowString($row, 'currency', 'irt');
        if (bccomp($currentRemaining, '0', 8) <= 0) {
            $this->db->prepare("UPDATE `" . static::$table . "` SET status = 'exhausted', updated_at = NOW() WHERE id = ?")->execute([$id]);
            return false;
        }
        $newRemaining = Money::fromString($currentRemaining, $currency)->subtract(Money::fromString($amount, $currency))->getAmount();
        if (bccomp($newRemaining, '0', 8) < 0) $newRemaining = '0';
        $newStatus = bccomp($newRemaining, '0', 8) <= 0 ? 'exhausted' : 'active';
        $success = $this->db->prepare("UPDATE `" . static::$table . "` SET clicks_count = clicks_count + 1, remaining_budget = ?, status = ?, updated_at = NOW() WHERE id = ?")->execute([$newRemaining, $newStatus, $id]);
        if ($success) return true;
        throw new \RuntimeException("Failed to update ad budget. Ad ID: {$id}, Amount: {$amount}");
    }

    /**
     * دریافت بنرهای فعال بر اساس موقعیت (Placement)
     */
    /** @return list<\stdClass> */
    /** @return list<\stdClass> */
    public function getActiveBannersByPlacement(string $placement, int $limit = 5): array
    {
        $now = date('Y-m-d H:i:s');
        
        // M43: Replaced MySQL ORDER BY RAND() with application-layer shuffle to optimize large dataset queries.
        $banners = $this->db->table(static::$table)
            ->where('type', '=', 'banner')
            ->where('placement', '=', $placement)
            ->where('status', '=', 'active')
            ->whereRaw('(start_date IS NULL OR start_date <= ?)', [$now])
            ->whereRaw('(end_date IS NULL OR end_date >= ?)', [$now])
            ->orderBy('sort_order', 'ASC')
            ->limit(100) // Cap fetched list size to prevent large-volume overhead
            ->get();
            
        if (!empty($banners)) {
            \shuffle($banners);
            return \array_slice($banners, 0, $limit);
        }
        
        return [];
    }

    /**
     * افزایش شمارنده نمایش به صورت گروهی برای بهینه‌سازی عملکرد N+1
     */
    /** @param list<int> $ids */
    public function bulkIncrementImpressions(array $ids, int $step = 1): bool
    {
        if (empty($ids)) return true;
        
        // ✅ H-04: Validate all IDs are positive integers
        $ids = array_filter($ids, fn($id) => is_int($id) && $id > 0);
        if (empty($ids)) return false;
        
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $sql = "UPDATE `" . static::$table . "` 
                SET impressions = impressions + ?,
                    ctr = CASE WHEN impressions > 0 THEN ROUND((clicks / (impressions + ?)) * 100, 2) ELSE 0 END 
                WHERE id IN ($placeholders)";
                
        $params = array_merge([$step, $step], $ids);
        return $this->db->prepare($sql)->execute($params);
    }

    /**
     * افزایش شمارنده نمایش (Impression) به صورت اتمیک
     */
    public function incrementImpression(int $id): bool
    {
        return $this->bulkIncrementImpressions([$id]);
    }

    /**
     * ثبت کلیک و آپدیت آمار CTR به صورت ترنزکشنال
     */
    public function registerInteractionClick(int $id, ?int $userId, string $ip): bool
    {
        if (!$this->db->inTransaction()) {
            throw new \RuntimeException("registerInteractionClick must be executed within an active database transaction.");
        }

        // قفل ردیف برای ثبت کلیک
        $stmt = $this->db->prepare("SELECT id, clicks, impressions FROM `" . static::$table . "` WHERE id = ? FOR UPDATE");
        $stmt->execute([$id]);
        $ad = $this->fetchObject($stmt);

        if ($ad === null) {
            return false;
        }

        // ثبت رکورد کلیک در لاگ سیستم
        $stmt = $this->db->prepare("INSERT INTO banner_clicks (banner_id, user_id, ip_address, clicked_at) VALUES (?, ?, ?, NOW())");
        $stmt->execute([$id, $userId, $ip]);

        // بروزرسانی شمارنده کلی در جدول تبلیغات
        $newClicks = $this->rowInt($ad, 'clicks') + 1;
        $impressions = $this->rowInt($ad, 'impressions');
        $newCtr = $impressions > 0 ? \round(($newClicks / $impressions) * 100, 2) : 0.0;

        $stmt = $this->db->prepare("UPDATE `" . static::$table . "` SET clicks = ?, ctr = ?, updated_at = NOW() WHERE id = ?");
        $stmt->execute([$newClicks, $newCtr, $id]);

        return true;
    }

    /**
     * دریافت لیست تسک‌های سفارشی در دسترس برای کاربران انجام‌دهنده (Worker)
     */
    /**
         * @param array<string, mixed> $filters
         * @return list<\stdClass>
         */
    public function getAvailableCustomTasks(int $workerId, array $filters = [], int $limit = 20, int $offset = 0, string $type = 'custom_task'): array
    {
        $now = date('Y-m-d H:i:s');
        $q = $this->db->table(static::$table . ' as a')
            ->select('a.*', 'u.full_name as creator_name')
            ->selectRaw('(a.total_count - a.completed_count - a.pending_count) as remaining_slots')
            ->leftJoin('users as u', 'u.id', '=', 'a.user_id')
            ->where('a.type', '=', $type)
            ->where('a.status', '=', 'active')
            ->where('a.user_id', '!=', $workerId)
            ->where('a.remaining_budget', '>', 0)
            ->whereRaw('(a.total_count - a.completed_count - a.pending_count) > 0')
            ->whereRaw('(a.end_date IS NULL OR a.end_date > ?)', [$now])
            ->whereNull('a.deleted_at');

        if (!empty($filters['task_type'])) {
            $q->where('a.task_type', '=', $filters['task_type']);
        }
        
        if (!empty($filters['platform'])) {
            $q->where('a.platform', '=', $filters['platform']);
        }

        return $q->orderBy('a.is_active', 'DESC') // First sticky/active
                 ->orderBy('a.created_at', 'DESC')
                 ->limit($limit)
                 ->offset($offset)
                 ->get();
    }

    /**
     * شمارش تسک‌های سفارشی فعال و در دسترس
     */
    /** @param array<string, mixed> $filters */
    public function countAvailableCustomTasks(int $workerId, array $filters = [], string $type = 'custom_task'): int
    {
        $now = date('Y-m-d H:i:s');
        $q = $this->db->table(static::$table)
            ->where('type', '=', $type)
            ->where('status', '=', 'active')
            ->where('user_id', '!=', $workerId)
            ->where('remaining_budget', '>', 0)
            ->whereRaw('(total_count - completed_count - pending_count) > 0')
            ->whereRaw('(end_date IS NULL OR end_date > ?)', [$now])
            ->whereNull('deleted_at');

        if (!empty($filters['task_type'])) {
            $q->where('task_type', '=', $filters['task_type']);
        }

        return $q->count();
    }

    /**
     * افزایش شمارنده در حال انجام (برای جلوگیری از دریافت بیش از ظرفیت)
     */
    public function incrementPendingCount(int $id): bool
    {
        $stmt = $this->db->prepare("UPDATE `" . static::$table . "` SET pending_count = COALESCE(pending_count,0) + 1, remaining_count = GREATEST(COALESCE(remaining_count,total_count) - 1, 0) WHERE id = ?");
        return $stmt->execute([$id]);
    }

    /**
     * Re-consume one freed slot, used when a rejected/disputed custom task submission
     * is later resolved in favor of the worker.
     */
    public function decrementAdSlots(int $id): bool
    {
        $stmt = $this->db->prepare("UPDATE `" . static::$table . "` SET remaining_count = GREATEST(COALESCE(remaining_count,0) - 1, 0), updated_at = NOW() WHERE id = ?");
        return $stmt->execute([$id]);
    }

    /**
     * کاهش شمارنده در حال انجام
     */
    public function decrementPendingCount(int $id): bool
    {
        $stmt = $this->db->prepare("UPDATE `" . static::$table . "` SET pending_count = GREATEST(0, COALESCE(pending_count,0) - 1), remaining_count = COALESCE(remaining_count,0) + 1 WHERE id = ?");
        return $stmt->execute([$id]);
    }

    /**
     * Complete a custom task while deducting its decimal reward from budget.
     */
    public function incrementCustomTaskCompletion(int $id, string $costAmount, bool $shouldDecrementPending = true): bool
    {
        if (bccomp($costAmount, '0', 8) < 0) throw new \InvalidArgumentException('Custom-task cost cannot be negative');
        if (!$this->db->inTransaction()) throw new \RuntimeException("incrementCustomTaskCompletion must be executed within an active database transaction.");
        $stmt = $this->db->prepare("SELECT id, remaining_budget, completed_count, pending_count, total_count, status, currency FROM `" . static::$table . "` WHERE id = ? FOR UPDATE");
        $stmt->execute([$id]);
        $task = $this->fetchObject($stmt);
        if ($task === null) return false;
        $remainingBudget = $this->rowString($task, 'remaining_budget');
        if ($remainingBudget === '') return false;
        $currency = $this->rowString($task, 'currency', 'irt');
        $newRemainingBudget = Money::fromString($remainingBudget, $currency)->subtract(Money::fromString($costAmount, $currency))->getAmount();
        if (bccomp($newRemainingBudget, '0', 8) < 0) $newRemainingBudget = '0';
        $newCompletedCount = $this->rowInt($task, 'completed_count') + 1;
        $pendingCount = $this->rowInt($task, 'pending_count');
        $newPendingCount = $shouldDecrementPending ? max(0, $pendingCount - 1) : $pendingCount;
        $totalCount = $this->rowInt($task, 'total_count');
        $newStatus = bccomp($newRemainingBudget, '0', 8) <= 0 || ($totalCount > 0 && $newCompletedCount >= $totalCount) ? 'completed' : $this->rowString($task, 'status', 'active');
        return $this->db->prepare("UPDATE `" . static::$table . "` SET completed_count = ?, pending_count = ?, remaining_budget = ?, status = ?, updated_at = NOW() WHERE id = ?")->execute([$newCompletedCount, $newPendingCount, $newRemainingBudget, $newStatus, $id]);
    }

    /**
     * منقضی کردن تبلیغات قدیمی که بودجه ندارند یا تاریخشان گذشته است
     */
    public function expireOldAdvertisements(int $chunkSize = 1000): int
    {
        $totalExpired = 0;
        $maxIterations = 100;
        $now = date('Y-m-d H:i:s');
        
        for ($i = 0; $i < $maxIterations; $i++) {
            $sql = "UPDATE `" . static::$table . "` 
                    SET `status` = 'completed', `updated_at` = ? 
                    WHERE `status` = 'active' 
                      AND ((end_date IS NOT NULL AND end_date < ?) 
                           OR (remaining_count IS NOT NULL AND remaining_count <= 0) 
                           OR remaining_budget <= 0)
                    LIMIT ?";
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute([$now, $now, $chunkSize]);
            
            $affected = $stmt->rowCount();
            $totalExpired += $affected;
            
            if ($affected < $chunkSize) {
                break;
            }
            
            usleep(50000); 
        }
        
        return $totalExpired;
    }
    /** @return array<string, string> */
    public function taskTypes(): array
    {
        return [
            'signup'  => 'ثبت‌نام',
            'install' => 'نصب برنامه',
            'review'  => 'نظر دادن',
            'vote'    => 'رأی دادن',
            'follow'  => 'دنبال کردن',
            'join'    => 'عضویت',
            'custom'  => 'سفارشی',
        ];
    }

    /** @return array<string, string> */
    public function proofTypes(): array
    {
        return [
            'screenshot' => 'اسکرین‌شات',
            'text'       => 'متن',
            'video'      => 'ویدیو',
            'code'       => 'کد رفرال',
            'file'       => 'فایل',
        ];
    }

    /** @return array<string, string> */
    public function statusLabels(): array
    {
        return [
            'draft'          => 'پیشنویس',
            'pending_review' => 'در انتظار بررسی',
            'active'         => 'فعال',
            'paused'         => 'متوقف',
            'completed'      => 'تکمیل‌شده',
            'rejected'       => 'رد شده',
            'expired'        => 'منقضی',
        ];
    }

    /** @return array<string, string> */
    public function statusClasses(): array
    {
        return [
            'draft'          => 'badge-secondary',
            'pending_review' => 'badge-warning',
            'active'         => 'badge-success',
            'paused'         => 'badge-info',
            'completed'      => 'badge-primary',
            'rejected'       => 'badge-danger',
            'expired'        => 'badge-danger',
        ];
    }

    /** @return array<string, string> */
    public function submissionStatusLabels(): array
    {
        return [
            'in_progress' => 'در حال انجام',
            'submitted'   => 'ارسال شده',
            'approved'    => 'تایید شده',
            'rejected'    => 'رد شده',
            'expired'     => 'منقضی شده',
            'disputed'    => 'در اختلاف',
        ];
    }

    /**
     * جستجوی ادمین با JOIN کامل کاربر (user email/full_name).
     * جایگزین adminSearchAds() در AdminAdsController که از getDb() استفاده می‌کرد.
     */
    /**
         * @param array<string, mixed> $filters
         * @return array{items: list<\stdClass>, total: int}
         */
    public function searchAdminWithUser(
        string $q = '',
        array $filters = [],
        int $limit = 30,
        int $offset = 0
    ): array {
        $limit  = max(1, min(100, $limit));
        $offset = max(0, $offset);

        $where  = ['a.deleted_at IS NULL'];
        $params = [];

        $typeFilter = $this->filterString($filters, 'type');
        if ($typeFilter !== null) {
            $where[] = 'a.type = ?';
            $params[] = $typeFilter;
        }
        $statusFilter = $this->filterString($filters, 'status');
        if ($statusFilter !== null) {
            $where[] = 'a.status = ?';
            $params[] = $statusFilter;
        }
        $userIdFilter = $this->filterPositiveInt($filters, 'user_id');
        if ($userIdFilter !== null) {
            $where[] = 'a.user_id = ?';
            $params[] = $userIdFilter;
        }
        $dateFrom = $this->filterString($filters, 'date_from');
        if ($dateFrom !== null) {
            $where[] = 'DATE(a.created_at) >= ?';
            $params[] = $dateFrom;
        }
        $dateTo = $this->filterString($filters, 'date_to');
        if ($dateTo !== null) {
            $where[] = 'DATE(a.created_at) <= ?';
            $params[] = $dateTo;
        }

        if ($q !== '') {
            $like     = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q) . '%';
            $where[]  = '(a.title LIKE ? OR a.description LIKE ? OR a.keyword LIKE ? OR u.email LIKE ? OR u.full_name LIKE ?)';
            array_push($params, $like, $like, $like, $like, $like);
        }

        $whereSql = implode(' AND ', $where);

        $items = $this->db->fetchAll(
            "SELECT a.*, u.full_name AS user_name, u.email AS user_email
             FROM ads a
             LEFT JOIN users u ON u.id = a.user_id
             WHERE {$whereSql}
             ORDER BY a.created_at DESC
             LIMIT {$limit} OFFSET {$offset}",
            $params
        ) ?: [];

        $total = (int)$this->db->fetchColumn(
            "SELECT COUNT(*) FROM ads a LEFT JOIN users u ON u.id = a.user_id WHERE {$whereSql}",
            $params
        );

        return ['items' => $items, 'total' => $total];
    }

    // ── Favorites (Task Bookmarks) ────────────────────────────────────────

    public function isTaskFavorited(int $userId, int $adId): bool
    {
        return (bool)$this->db->fetchColumn(
            "SELECT COUNT(*) FROM user_favorites WHERE user_id = ? AND target_id = ? AND target_type = 'ad'",
            [$userId, $adId]
        );
    }

    public function addToFavorites(int $userId, int $adId): bool
    {
        try {
            $this->db->query(
                "INSERT IGNORE INTO user_favorites (user_id, target_id, target_type, created_at) VALUES (?, ?, 'ad', NOW())",
                [$userId, $adId]
            );
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    public function removeFromFavorites(int $userId, int $adId): bool
    {
        try {
            $this->db->execute(
                "DELETE FROM user_favorites WHERE user_id = ? AND target_id = ? AND target_type = 'ad'",
                [$userId, $adId]
            );
            return true;
        } catch (\Throwable) {
            return false;
        }
    }
}
