<?php

declare(strict_types=1);

namespace App\Models;

use Core\Model;
use Core\Database;
use App\Traits\Filterable;

/**
 * SocialTaskModel - Core Model for Social Ads, acting as a backward-compatible proxy wrapper for executions & analytics.
 */
class SocialTaskModel extends Model
{
    use Filterable;

    protected static string $table = 'ads';
    protected static array $searchable = ['sa.title', 'sa.description'];

    /** @var array<string, array{string, string}> */
    protected static array $filterable = [
        'platform' => ['sa.platform', '='],
        'task_type' => ['sa.task_type', '='],
        'min_reward' => ['sa.price_per_task', '>='],
        'max_reward' => ['sa.price_per_task', '<='],
        'budget_cap' => ['sa.price_per_task', '<='],
        'status' => ['sa.status', '='],
    ];

    private SocialTaskExecutionModel $executionModel;
    private SocialTaskAnalyticsModel $analyticsModel;

    public function __construct(
        Database $db, 
        SocialTaskExecutionModel $executionModel, 
        SocialTaskAnalyticsModel $analyticsModel
    ) {
        parent::__construct($db);
        $this->executionModel = $executionModel;
        $this->analyticsModel = $analyticsModel;
    }

    // --- Ads & Tasks (Core) ---

    /**
     * Retrieves globally active tasks utilizing robust Filterable architecture
     */
    /**
     * @param array<string, mixed> $filters
     * @param list<string> $excludedPlatforms
     * @return list<\stdClass>
     */
    public function getActiveAds(int $userId, array $filters, string $orderBy, int $limit, array $excludedPlatforms = []): array
    {
        $query = $this->db->table('ads as sa')
            ->select('sa.*', 'u.full_name AS advertiser_name')
            ->selectRawUnsafe('COALESCE(ut.trust_score, 50) AS advertiser_trust')
            ->leftJoin('users as u', 'u.id', '=', 'sa.user_id')
            ->leftJoin('social_user_trust as ut', 'ut.user_id', '=', 'sa.user_id');
        
        // 1. Core base conditions
        $query->where('sa.status', '=', 'active')
              ->whereRawUnsafe('(sa.total_count - sa.completed_count) > 0');

        // 2. Excluded platform logic
        if (!empty($excludedPlatforms) && (!isset($filters['platform']) || $filters['platform'] !== 'youtube')) {
            $query->whereNotIn('sa.platform', $excludedPlatforms);
        }

        // 3. Dynamic anti-redundancy subquery (Prevent showing completed tasks)
        $query->whereRawUnsafe("NOT EXISTS (
            SELECT 1 FROM social_task_executions ste
            WHERE ste.ad_id = sa.id
              AND ste.executor_id = ?
              AND ste.status NOT IN ('expired','cancelled')
        )", [$userId]);

        // 4. Text searching injection helper (built-in Core\Model method)
        if (!empty($filters['search'])) {
            $query = $this->applySearch($query, str_value($filters['search']));
        }

        // 5. Structural Dynamic Filters applied automatically by Trait!
        $query = $this->applyFilters($query, $filters);
        
        // 6. Sort Order Sanitization (Safety First)
        $allowedSorts = [
            'sa.price_per_task desc' => 'sa.price_per_task DESC',
            'sa.price_per_task asc'  => 'sa.price_per_task ASC',
            'sa.created_at desc'     => 'sa.created_at DESC',
            'sa.created_at asc'      => 'sa.created_at ASC',
            'advertiser_trust desc'  => 'advertiser_trust DESC',
        ];

        $orderByClean = \strtolower(\trim((string)$orderBy));
        if (\str_contains($orderByClean, 'rand')) {
            // MED-22: Eliminate resource-draining ORDER BY RAND() table scans.
            // Fetch total match volume to compute a fast, safe random offset, bypassing full filesort.
            $countQuery = clone $query;
            $total = (int)$countQuery->count();
            if ($total > $limit) {
                $randomOffset = \random_int(0, $total - $limit);
                $query->offset($randomOffset);
            }
            // Enforce basic PK sort to exploit lightning-fast indexed sequential scanning
            $query->orderBy('sa.id', 'ASC');
        } elseif (isset($allowedSorts[$orderByClean])) {
            [$col, $dir] = explode(' ', $allowedSorts[$orderByClean]);
            $query->orderBy($col, $dir);
        } else {
            // Enforce safe default ordering if match fails
            $query->orderBy('sa.created_at', 'DESC');
        }

        return $query->limit($limit)->get();
    }

    public function getAdById(int $adId, bool $forUpdate = false): ?\stdClass
    {
        $sql = "SELECT * FROM ads WHERE id = ?";
        if ($forUpdate) $sql .= " FOR UPDATE";
        return $this->db->fetch($sql, [$adId]);
    }

    /** @param array<string, mixed> $extraData */
    public function updateAdStatus(int $adId, string $status, array $extraData = []): bool
    {
        $updates = ["status = ?", "updated_at = NOW()"];
        $params = [$status];

        $allowedExtraKeys = ['reject_reason', 'reviewed_by', 'reviewed_at'];

        foreach ((array)$extraData as $key => $value) {
            if (!\in_array($key, $allowedExtraKeys, true)) {
                throw new \InvalidArgumentException("Invalid or restricted update column: " . $key);
            }
            $updates[] = "`{$key}` = ?";
            $params[] = $value;
        }

        $params[] = $adId;
        return (bool)$this->db->query(
            "UPDATE ads SET " . implode(', ', $updates) . " WHERE id = ?",
            $params
        );
    }

    public function decrementAdSlots(int $adId): int
    {
        $result = $this->db->query(
            "UPDATE ads SET remaining_count = remaining_count - 1 
             WHERE id = ? AND remaining_count > 0",
            [$adId]
        );
        return $result->rowCount();
    }

    public function incrementAdSlots(int $adId, int $count = 1): bool
    {
        return (bool)$this->db->query(
            "UPDATE ads SET remaining_count = remaining_count + ? WHERE id = ?",
            [$count, $adId]
        );
    }

    // --- Delegated Executions & Camera Methods ---

    public function getExecutionById(int $id, bool $forUpdate = false): ?\stdClass
    {
        return $this->executionModel->getExecutionById($id, $forUpdate);
    }

    public function getExecutionWithAd(int $executionId, int $userId, bool $forUpdate = false): ?\stdClass
    {
        return $this->executionModel->getExecutionWithAd($executionId, $userId, $forUpdate);
    }

    public function getExecutionWithAdForAdvertiser(int $executionId, int $advertiserId, bool $forUpdate = false): ?\stdClass
    {
        return $this->executionModel->getExecutionWithAdForAdvertiser($executionId, $advertiserId, $forUpdate);
    }

    /** @param array<string, mixed> $data */
    public function createExecution(array $data): int
    {
        return $this->executionModel->createExecution($data);
    }

    /** @param array<string, mixed> $data */
    public function updateExecutionStatus(int $id, string $status, array $data = []): bool
    {
        return $this->executionModel->updateExecutionStatus($id, $status, $data);
    }

    public function updateExecutionBehavior(int $id, string $behaviorData): bool
    {
        return $this->executionModel->updateExecutionBehavior($id, $behaviorData);
    }

    public function updateExecutionBehaviorJson(int $id, int $cameraScore, string $verifiedSignals): bool
    {
        return $this->executionModel->updateExecutionBehaviorJson($id, $cameraScore, $verifiedSignals);
    }

    public function getBehaviorData(int $id): ?string
    {
        return $this->executionModel->getBehaviorData($id);
    }

    public function flagExecution(int $id, string $note): bool
    {
        return $this->executionModel->flagExecution($id, $note);
    }

    public function getRecentExecutionsByIp(string $ip, int $excludeUserId, int $hours = 24): int
    {
        return $this->executionModel->getRecentExecutionsByIp($ip, $excludeUserId, $hours);
    }

    public function getSharedFingerprintUsers(string $fingerprint, int $excludeUserId): int
    {
        return $this->executionModel->getSharedFingerprintUsers($fingerprint, $excludeUserId);
    }

    public function getRapidTaskStats(int $userId, int $minutes = 10): ?\stdClass
    {
        return $this->executionModel->getRapidTaskStats($userId, $minutes);
    }

    /** @param list<string> $statusList */
    public function getCameraRequest(int $executionId, array $statusList): ?\stdClass
    {
        return $this->executionModel->getCameraRequest($executionId, $statusList);
    }

    /** @param array<string, mixed> $data */
    public function createCameraRequest(array $data): int
    {
        return $this->executionModel->createCameraRequest($data);
    }

    public function getPendingCameraRequest(int $executionId): ?\stdClass
    {
        return $this->executionModel->getPendingCameraRequest($executionId);
    }

    public function getCameraRequestForUser(int $executionId, int $userId): ?\stdClass
    {
        return $this->executionModel->getCameraRequestForUser($executionId, $userId);
    }

    public function updateCameraRequestResult(int $id, int $score, string $signals): bool
    {
        return $this->executionModel->updateCameraRequestResult($id, $score, $signals);
    }

    public function expireCameraRequests(int $executionId): bool
    {
        return $this->executionModel->expireCameraRequests($executionId);
    }

    public function getCameraStats(): ?\stdClass
    {
        return $this->executionModel->getCameraStats();
    }

    // --- Delegated Analytics & Trust Methods ---

    /** @param array<string, mixed> $data */
    public function createRating(array $data): int
    {
        return $this->analyticsModel->createRating($data);
    }

    public function getAvgRating(int $userId, string $raterType): ?\stdClass
    {
        return $this->analyticsModel->getAvgRating($userId, $raterType);
    }

    /** @return list<\stdClass> */
    public function getUserRatingHistory(int $userId, string $raterType, int $limit): array
    {
        return $this->analyticsModel->getUserRatingHistory($userId, $raterType, $limit);
    }

    /** @return list<\stdClass> */
    public function getPendingRatings(int $limit, int $offset): array
    {
        return $this->analyticsModel->getPendingRatings($limit, $offset);
    }

    public function getRatingById(int $id): ?\stdClass
    {
        return $this->analyticsModel->getRatingById($id);
    }

    public function updateRatingStatus(int $id, string $status, int $adminId): bool
    {
        return $this->analyticsModel->updateRatingStatus($id, $status, $adminId);
    }

    public function getRatingStats(): ?\stdClass
    {
        return $this->analyticsModel->getRatingStats();
    }

    /** @return list<\stdClass> */
    public function getRatingHistoryFull(int $userId, string $column, int $limit, int $offset): array
    {
        return $this->analyticsModel->getRatingHistoryFull($userId, $column, $limit, $offset);
    }

    public function hasUserRated(int $executionId, int $raterId, string $raterType): bool
    {
        return $this->analyticsModel->hasUserRated($executionId, $raterId, $raterType);
    }

    public function updateUserStats(int $userId, float $rating, int $count, string $type): bool
    {
        return $this->analyticsModel->updateUserStats($userId, $rating, $count, $type);
    }

    public function getUserTrust(int $userId, bool $forUpdate = false): ?\stdClass
    {
        return $this->analyticsModel->getUserTrust($userId, $forUpdate);
    }

    public function upsertUserTrust(int $userId, float $score): bool
    {
        return $this->analyticsModel->upsertUserTrust($userId, $score);
    }

    /** @param array<string, mixed> $data */
    public function recordTrustAdjustment(array $data): bool
    {
        return $this->analyticsModel->recordTrustAdjustment($data);
    }

    /** @param array<string, mixed> $data */
    public function saveTrustSnapshot(array $data): bool
    {
        return $this->analyticsModel->saveTrustSnapshot($data);
    }

    public function getExecutorStats(int $userId): ?\stdClass
    {
        return $this->analyticsModel->getExecutorStats($userId);
    }

    public function getAdvertiserAdStats(int $adId, int $advertiserId): ?\stdClass
    {
        return $this->analyticsModel->getAdvertiserAdStats($adId, $advertiserId);
    }

    public function getWeeklyExecutionStats(int $userId): ?\stdClass
    {
        return $this->analyticsModel->getWeeklyExecutionStats($userId);
    }

    /** @return list<\stdClass> */
    public function getRecentActiveExecutors(int $days = 7): array
    {
        return $this->analyticsModel->getRecentActiveExecutors($days);
    }

    /** @return list<\stdClass> */
    public function getExecutorHistory(int $userId, int $limit, int $offset): array
    {
        return $this->analyticsModel->getExecutorHistory($userId, $limit, $offset);
    }

    public function getMedianReward(): float
    {
        return $this->analyticsModel->getMedianReward();
    }

    /** @return list<\stdClass> */
    public function getByAdvertiser(int $userId, int $limit = 20, int $offset = 0): array
    {
        return $this->db->fetchAll(
            "SELECT * FROM ads 
             WHERE user_id = ? AND type = 'social_task' 
             ORDER BY created_at DESC 
             LIMIT ? OFFSET ?",
            [$userId, $limit, $offset]
        );
    }

    public function countByAdvertiser(int $userId): int
    {
        return (int)$this->db->fetchColumn(
            "SELECT COUNT(*) FROM ads WHERE user_id = ? AND type = 'social_task'",
            [$userId]
        );
    }

    public function countExecutorHistory(int $userId): int
    {
        return (int)$this->db->fetchColumn(
            "SELECT COUNT(*) FROM social_task_executions WHERE executor_id = ?",
            [$userId]
        );
    }

    /** @return array{0: list<\stdClass>, 1: int} */
    public function getAvailableTasks(int $limit, int $offset): array
    {
        $params = [$limit, $offset];
        $ads = $this->db->fetchAll(
            "SELECT sa.*, u.full_name AS advertiser_name,
                    COALESCE(ut.trust_score, 50) AS advertiser_trust
             FROM ads sa
             LEFT JOIN users u ON u.id = sa.user_id
             LEFT JOIN social_user_trust ut ON ut.user_id = sa.user_id
             WHERE sa.type = 'social_task'
               AND sa.status = 'active'
               AND (sa.total_count - sa.completed_count) > 0
             ORDER BY sa.created_at DESC
             LIMIT ? OFFSET ?",
            $params
        ) ?: [];
        $total = (int)$this->db->fetchColumn(
            "SELECT COUNT(*) FROM ads
             WHERE type = 'social_task' AND status = 'active' AND (total_count - completed_count) > 0"
        );
        return [$ads, $total];
    }

    /** @return list<\stdClass> */
    public function getExecutionsByAd(int $adId, int $limit = 20, int $offset = 0): array
    {
        return $this->executionModel->getExecutionsByAd($adId, $limit, $offset);
    }



    // --- Transactions ---

    public function beginTransaction(): void
    {
        $this->db->beginTransaction();
    }

    public function commit(): void
    {
        $this->db->commit();
    }

    public function rollback(): void
    {
        $this->db->rollback();
    }
}
