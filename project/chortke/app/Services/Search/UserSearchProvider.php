<?php

declare(strict_types=1);

namespace App\Services\Search;

/**
 * 🚀 UPG-01: UserSearchProvider - تأمین‌کننده اختصاصی جستجوی عمومی سمت کاربران
 */
class UserSearchProvider extends BaseSearchProvider implements \App\Contracts\SearchProviderInterface
{
    private UserSearchGateway $gateway;
    public function __construct(
        \App\Contracts\CacheInterface $cache,
        \App\Contracts\LoggerInterface $logger,
        UserSearchGateway $gateway,
        ?\App\Services\Settings\AppSettings $appSettings = null
    ) {        $this->gateway = $gateway;

        parent::__construct($logger, $cache, $appSettings);
    }

    public function supports(string $scope): bool
    {
        return $scope === 'user';
    }

    public function search(\App\Services\Search\SearchQuery $query): \App\Services\Search\SearchResult
    {
        $userId = int_value($query->getFilters()['user_id'] ?? 0);
        $result = $this->searchUser($query, $userId);

        $items = is_array($result['items'] ?? null) ? $result['items'] : [];
        $itemsCount = is_array($items) ? count($items) : 0;
        return new \App\Services\Search\SearchResult(
            $items,
            int_value($result['total'] ?? $itemsCount),
            $result
        );
    }

    /**
     * جستجوی سراسری کاربر روی تمام بخش‌های مرتبط با او (Global User Search)
     */
    /** @return array<string, mixed> */
    public function searchUser(SearchQuery $query, int $userId): array
    {
        $term = $query->getTerm() ?? '';
        $this->logSearch('user_global', $term, $userId);

        $limit = $query->getLimit();
        $offset = $query->getOffset();

        $cacheKey = "global_search_user:{$userId}:" . md5($term . ':' . $limit . ':' . $offset);
        $tags = $this->searchTags('search:user', "search:user:{$userId}");
        
        $cached = $this->cacheGet($cacheKey, $tags);
        if ($cached !== null) {
            return $cached;
        }

        $q = $this->sanitize($term);
        if (mb_strlen($q, 'UTF-8') < 2) {
            return ['total' => 0];
        }

        // لیست تمام ماژول‌هایی که یک کاربر مجاز است جستجو کند (پوشش تمام Missing Domains)
        $domains = [
            'transactions', 'tickets', 'ads', 'tasks', 'vitrines', 'contents', 'direct_messages',
            'withdrawals', 'manual_deposits', 'crypto_deposits', 'referrals', 'kyc', 'bank_cards', 
            'user_levels', 'score_history', 'notifications', 'audit_trail'
        ];

        $results = [];
        $total = 0;

        foreach ($domains as $domain) {
            // مقادیر limit را کوچک در نظر می‌گیریم تا سرچ سراسری سریع باشد
            $domainResult = $this->searchDomain($domain, $q, $userId, [], $limit, $offset);
            $domainItems = $domainResult['items'] ?? [];
            if (!is_array($domainItems)) {
                throw new \UnexpectedValueException("Search domain {$domain} returned non-array items.");
            }
            if ($domainItems !== []) {
                $results[$domain] = $domainItems;
                $total += count($domainItems);
            }
        }

        $finalResult = ['items' => $results, 'total' => $total];

        $ttl = $this->getCacheTTL('search');
        $this->cacheSetSeconds($cacheKey, $finalResult, $ttl, $tags);

        return $finalResult;
    }

    /**
     * جستجوی سراسری توسط یک کاربر در یک دامین خاص (مثلا withdrawals, tickets, ...)
     */
    /**
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    public function searchDomain(string $domain, string $query, int $userId, array $filters = [], int $limit = 20, int $offset = 0): array
    {
        $this->logSearch("user_{$domain}", $query, $userId);

        $filters['user_id'] = $userId;
        
        $cacheKey = $this->generateCacheKey("user_{$domain}_{$userId}", $filters, $limit, $offset) . ':' . md5($query);
        $tags = $this->searchTags("search:user", "search:user:{$userId}", "search:domain:{$domain}");
        
        $cached = $this->cacheGet($cacheKey, $tags);
        if ($cached !== null) {
            return $cached;
        }

        $q = $this->sanitize($query);

        // 🚀 استفاده از شیء SearchQuery برای استانداردسازی
        $searchQuery = new SearchQuery($q, $filters, $limit, $offset);
        
        $results = $this->gateway->searchRegistered($domain, $searchQuery)->toArray();

        $ttl = $this->getCacheTTL('search');
        $this->cacheSetSeconds($cacheKey, $results, $ttl, $tags);

        return $results;
    }
}
