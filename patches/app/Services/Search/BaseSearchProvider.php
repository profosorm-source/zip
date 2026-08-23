<?php

declare(strict_types=1);

namespace App\Services\Search;

use App\Contracts\CacheInterface;
use App\Contracts\LoggerInterface;
use App\Services\Settings\AppSettings;

/**
 * BaseSearchProvider - کلاس پایه تأمین‌کنندگان جستجو بدون Service Locator
 */
abstract class BaseSearchProvider
{
    protected ?AppSettings $appSettings;
    protected CacheInterface $cache;
    protected LoggerInterface $logger;

    protected const CACHE_TTL_SECONDS = 300;
    protected const CACHE_TTL_MINUTES = 5;
    protected const DEFAULT_LIMIT = 20;
    protected const MAX_LIMIT = 100;

    public function __construct(
        LoggerInterface $logger,
        CacheInterface $cache,
        ?AppSettings $appSettings = null
    ) {
        $this->logger = $logger;
        $this->cache = $cache;
        $this->appSettings = $appSettings;
    }

    // ---------------------------------------------------------------------
    // Shared helpers
    // ---------------------------------------------------------------------

    protected function sanitize(string $q): string
    {
        $q = trim(mb_substr($q, 0, 100));
        $escaped = preg_replace('/[%_\\x5C]/', '\\$0', $q);
        return is_string($escaped) ? trim($escaped) : '';
    }

    /** @param array<string, mixed> $filters */
    protected function generateCacheKey(string $module, array $filters, int $limit, int $offset): string
    {
        $encoded = json_encode($filters, JSON_THROW_ON_ERROR);
        return 'search:' . $module . ':' . md5($encoded) . ":{$limit}:{$offset}";
    }

    /**
     * Cache is an untyped boundary. Every search cache value must at least be
     * a string-keyed map; accepting an arbitrary scalar here would make a
     * later provider dereference an invalid producer result.
     *
     * @param list<string> $tags
     * @return array<string, mixed>|null
     */
    protected function cacheGet(string $key, array $tags = []): ?array
    {
        $value = empty($tags)
            ? $this->cache->get($key)
            : $this->cache->tags($tags)->get($key);

        if ($value === null) {
            return null;
        }

        return $this->requireStringKeyedArray($value, 'Search cache entries must be arrays.');
    }

    /**
     * Validate a cache/adapter payload at the first boundary where it enters
     * the search layer. This deliberately does not coerce values: producer
     * bugs must remain visible instead of becoming an apparently valid result.
     *
     * @return array<string, mixed>
     */
    protected function requireStringKeyedArray(mixed $value, string $message): array
    {
        if (!is_array($value)) {
            throw new \UnexpectedValueException($message);
        }

        /** @var array<string, mixed> $result */
        $result = [];
        foreach ($value as $key => $item) {
            if (!is_string($key)) {
                throw new \UnexpectedValueException($message);
            }
            $result[$key] = $item;
        }

        return $result;
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

        if ($this->appSettings !== null) {
            return int_value($this->appSettings->get('search.cache_ttl', $default));
        }

        return int_value(config('search.cache_ttl', $default));
    }

    /** @return list<string> */
    protected function searchTags(string ...$tags): array
    {
        $allTags = array_merge(['search'], $tags);
        $filtered = array_filter($allTags, static fn(string $tag): bool => $tag !== '');
        return array_values(array_unique($filtered));
    }

    protected function logSearch(string $type, string $query, ?int $userId): void
    {
        $this->logger->info('search.performed', [
            'type' => $type,
            'query' => $query,
            'user_id' => $userId,
            'timestamp' => date('Y-m-d H:i:s'),
        ]);
    }

    /** @return array<string, mixed> */
    protected function emptyGlobalResult(): array
    {
        return [
            'users' => [],
            'transactions' => [],
            'tickets' => [],
            'withdrawals' => [],
            'deposits' => [],
            'ads' => [],
            'total' => 0,
        ];
    }

    /** @return array<string, mixed> */
    protected function emptyUserResult(): array
    {
        return [
            'transactions' => [],
            'tickets' => [],
            'ads' => [],
            'tasks' => [],
            'total' => 0,
        ];
    }
}
