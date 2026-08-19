<?php

declare(strict_types=1);

namespace App\Services\Search;

use App\Contracts\LoggerInterface;
use App\Contracts\CacheInterface;
use App\Services\Settings\AppSettings;

/**
 * BaseSearchProvider - کلاس پایه تأمین‌کنندگان جستجو بدون Service Locator
 */
abstract class BaseSearchProvider
{
    protected ?AppSettings $appSettings;
    protected CacheInterface $cache;

    protected const CACHE_TTL_SECONDS = 300;
    protected const CACHE_TTL_MINUTES = 5;
    protected const DEFAULT_LIMIT = 20;
    protected const MAX_LIMIT = 100;

    protected \App\Contracts\LoggerInterface $logger;
    public function __construct(
        \App\Contracts\LoggerInterface $logger,
        CacheInterface $cache,
        ?AppSettings $appSettings = null
    ) {        $this->logger = $logger;
        $this->cache = $cache;

                $this->appSettings = $appSettings;
    }

    // ─────────────────────────────────────────────────────────────
    // Shared Helpers
    // ─────────────────────────────────────────────────────────────

    protected function sanitize(string $q): string
    {
        $q = trim(mb_substr($q, 0, 100));
        return trim((string)preg_replace('/[%_\\x5C]/', '\\$0', $q));
    }

    /** @param array<string, mixed> $filters */
    protected function generateCacheKey(string $module, array $filters, int $limit, int $offset): string
    {
        $filterHash = md5((string)json_encode($filters));
        return "search:{$module}:{$filterHash}:{$limit}:{$offset}";
    }

    /**
     * @param list<string> $tags
     * @return array<string, mixed>|null
     */
    protected function cacheGet(string $key, array $tags = []): ?array
    {
        $value = empty($tags) ? $this->cache->get($key) : $this->cache->tags($tags)->get($key);
        if ($value === null) return null;
        if (!is_array($value)) {
            throw new \UnexpectedValueException('Search cache entries must be arrays.');
        }
        return $value;
    }

    /** @param list<string> $tags */
    protected function cacheSetSeconds(string $key, mixed $value, int $seconds, array $tags = []): bool
    {
        if (empty($tags)) {
            return $this->cache->set($key, $value, $seconds);
        }

        return $this->cache->tags($tags)->set($key, $value, $seconds);
    }

    protected function getCacheTTL(string $scope): int
    {
        $default = match ($scope) {
            'transactions', 'live_transactions', 'direct_messages', 'user_dms', 'user_transactions' => 15,
            'tickets', 'ads', 'banners', 'influencers', 'tasks', 'user_tickets', 'user_ads', 'user_tasks' => 120,
            'system_settings', 'settings', 'lottery', 'prediction', 'seo', 'seo_ad', 'coupons' => 3600,
            default => 300,
        };

        if ($this->appSettings) {
            return int_value($this->appSettings->get('search.cache_ttl', $default));
        }

        return int_value(config('search.cache_ttl', $default));
    }

    /** @return list<string> */
    protected function searchTags(string ...$tags): array
    {
        return array_values(array_unique(array_filter(array_merge(['search'], $tags))));
    }

    protected function logSearch(string $type, string $query, ?int $userId): void
    {
        $this->logger->info('search.performed', [
            'type' => $type,
            'query' => $query,
            'user_id' => $userId,
            'timestamp' => date('Y-m-d H:i:s')
        ]);
    }

    /** @return array<string, mixed> */
    protected function emptyGlobalResult(): array
    {
        return [
            'users' => [], 'transactions' => [], 'tickets' => [],
            'withdrawals' => [], 'deposits' => [], 'ads' => [], 'total' => 0
        ];
    }

    /** @return array<string, mixed> */
    protected function emptyUserResult(): array
    {
        return [
            'transactions' => [], 'tickets' => [], 'ads' => [], 'tasks' => [], 'total' => 0
        ];
    }
}

