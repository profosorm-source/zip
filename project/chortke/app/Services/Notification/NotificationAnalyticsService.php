<?php

declare(strict_types=1);

namespace App\Services\Notification;

use App\Models\Notification;
use Core\Cache;
use App\Contracts\LoggerInterface;

class NotificationAnalyticsService
{
    private const ANALYTICS_CACHE_PREFIX = 'notif_analytics:';
    private const ANALYTICS_CACHE_TTL = 15;

    private \App\Contracts\CacheInterface $cache;
    private Notification $notificationModel;
    public function __construct(
        \App\Contracts\CacheInterface $cache,
        Notification $notificationModel
    ) {        $this->cache = $cache;
        $this->notificationModel = $notificationModel;

        
    }

    /** @return array<string, mixed> */
    public function getAnalyticsOverview(int $days = 30): array
    {
        $value = $this->cache->remember(
            self::ANALYTICS_CACHE_PREFIX . "overview:{$days}",
            self::ANALYTICS_CACHE_TTL,
            fn() => $this->notificationModel->getOverviewStats($days) ?: []
        );
        return $this->requireMap($value, 'overview');
    }

    /** @return list<\stdClass> */
    public function getAnalyticsByType(int $days = 30): array
    {
        $value = $this->cache->remember(
            self::ANALYTICS_CACHE_PREFIX . "by_type:{$days}",
            self::ANALYTICS_CACHE_TTL,
            fn() => $this->notificationModel->getAdminStatsByType($days) ?: []
        );
        return $this->requireObjectList($value, 'by_type');
    }

    /** @return list<\stdClass> */
    public function getAnalyticsDailyTrend(int $days = 30): array
    {
        $value = $this->cache->remember(
            self::ANALYTICS_CACHE_PREFIX . "daily:{$days}",
            self::ANALYTICS_CACHE_TTL,
            fn() => $this->notificationModel->getDailyStats($days) ?: []
        );
        return $this->requireObjectList($value, 'daily');
    }

    /** @return list<\stdClass> */
    public function getAnalyticsSegmentStats(int $days = 30): array
    {
        $value = $this->cache->remember(
            self::ANALYTICS_CACHE_PREFIX . "segment:{$days}",
            self::ANALYTICS_CACHE_TTL,
            fn() => $this->notificationModel->getStatsBySegment($days) ?: []
        );
        return $this->requireObjectList($value, 'segment');
    }

    /** @return array<string, mixed> */
    public function getAnalyticsFunnelStats(int $days = 30): array
    {
        $value = $this->cache->remember(
            self::ANALYTICS_CACHE_PREFIX . "funnel:{$days}",
            self::ANALYTICS_CACHE_TTL,
            fn() => $this->notificationModel->getFunnelStats($days) ?: []
        );
        return $this->requireMap($value, 'funnel');
    }

    /** @return array<string, mixed> */
    public function getAnalyticsFatigueReport(int $threshold = 20): array
    {
        $value = $this->cache->remember(
            self::ANALYTICS_CACHE_PREFIX . "fatigue:{$threshold}",
            self::ANALYTICS_CACHE_TTL,
            function () use ($threshold) {
                $users = $this->notificationModel->getHighUnreadUsers($threshold, 50);
                $summary = $this->notificationModel->getFatigueSummary($threshold);

                return [
                    'summary' => $summary ?: [],
                    'users'   => $users ?: [],
                ];
            }
        );
        return $this->requireMap($value, 'fatigue');
    }
    /** @return array<string, mixed> */
    private function requireMap(mixed $value, string $key): array
    {
        if (!is_array($value)) throw new \UnexpectedValueException("Notification analytics {$key} cache must contain an array.");
        return $value;
    }

    /** @return list<\stdClass> */
    private function requireObjectList(mixed $value, string $key): array
    {
        if (!is_array($value)) throw new \UnexpectedValueException("Notification analytics {$key} cache must contain an array.");
        foreach ($value as $row) {
            if (!$row instanceof \stdClass) throw new \UnexpectedValueException("Notification analytics {$key} rows must be stdClass.");
        }
        return array_values($value);
    }

}
