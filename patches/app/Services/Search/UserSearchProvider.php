<?php

declare(strict_types=1);

namespace App\Services\Search;

/**
 * UserSearchProvider - تأمین‌کننده اختصاصی جستجوی عمومی سمت کاربران
 */
class UserSearchProvider extends BaseSearchProvider implements \App\Contracts\SearchProviderInterface
{
    private UserSearchGateway $gateway;

    public function __construct(
        \App\Contracts\CacheInterface $cache,
        \App\Contracts\LoggerInterface $logger,
        UserSearchGateway $gateway,
        ?\App\Services\Settings\AppSettings $appSettings = null
    ) {
        $this->gateway = $gateway;
        parent::__construct($logger, $cache, $appSettings);
    }

    public function supports(string $scope): bool
    {
        return $scope === 'user';
    }

    public function search(\App\Services\Search\SearchQuery $query): \App\Services\Search\SearchResult
    {
        $userId = int_value($query->getFilters()['user_id'] ?? 0);
        if ($userId <= 0) {
            throw new \InvalidArgumentException('User search requires a positive user_id filter.');
        }

        $result = $this->searchUser($query, $userId);
        $itemsValue = array_key_exists('items', $result) ? $result['items'] : [];
        $items = $this->flattenUserItems($itemsValue);
        $total = $result['total'] ?? count($items);
        if (!is_int($total)) {
            throw new \UnexpectedValueException('User search total must be an integer.');
        }

        // searchUser() intentionally keeps its grouped public API. SearchResult
        // has a different contract (list<stdClass>), so retain the grouping in
        // metadata and expose a flat list as its items.
        $metadata = $result;
        $metadata['domains'] = $result['items'] ?? [];
        unset($metadata['items']);

        return new \App\Services\Search\SearchResult($items, $total, $metadata);
    }

    /**
     * جستجوی سراسری کاربر روی تمام بخش‌های مرتبط با او (Global User Search)
     *
     * @return array<string, mixed>
     */
    public function searchUser(SearchQuery $query, int $userId): array
    {
        if ($userId <= 0) {
            throw new \InvalidArgumentException('User search requires a positive user_id.');
        }

        $term = $query->getTerm() ?? '';
        $this->logSearch('user_global', $term, $userId);

        $limit = $query->getLimit();
        $offset = $query->getOffset();

        $cacheKey = "global_search_user:{$userId}:" . md5($term . ':' . $limit . ':' . $offset);
        $tags = $this->searchTags('search:user', "search:user:{$userId}");

        $cached = $this->cacheGet($cacheKey, $tags);
        if ($cached !== null) {
            $this->validateUserSearchPayload($cached);
            return $cached;
        }

        $q = $this->sanitize($term);
        if (mb_strlen($q, 'UTF-8') < 2) {
            return ['total' => 0];
        }

        // لیست تمام ماژول‌هایی که یک کاربر مجاز است جستجو کند
        $domains = [
            'transactions',
            'tickets',
            'ads',
            'tasks',
            'vitrines',
            'contents',
            'direct_messages',
            'withdrawals',
            'manual_deposits',
            'crypto_deposits',
            'referrals',
            'kyc',
            'bank_cards',
            'user_levels',
            'score_history',
            'notifications',
            'audit_trail',
        ];

        /** @var array<string, list<\stdClass>> $results */
        $results = [];
        $total = 0;

        foreach ($domains as $domain) {
            $domainResult = $this->searchDomain($domain, $q, $userId, [], $limit, $offset);
            if (!array_key_exists('items', $domainResult)) {
                throw new \UnexpectedValueException("Search domain {$domain} result is missing items.");
            }
            $domainItems = $this->requireObjectList($domainResult['items'], "Search domain {$domain}");
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
     * جستجوی سراسری توسط یک کاربر در یک دامین خاص
     *
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    public function searchDomain(
        string $domain,
        string $query,
        int $userId,
        array $filters = [],
        int $limit = 20,
        int $offset = 0
    ): array {
        if ($domain === '') {
            throw new \InvalidArgumentException('User search domain must be non-empty.');
        }
        if ($userId <= 0) {
            throw new \InvalidArgumentException('User search requires a positive user_id.');
        }

        $this->logSearch("user_{$domain}", $query, $userId);

        $filters['user_id'] = $userId;

        $cacheKey = $this->generateCacheKey("user_{$domain}_{$userId}", $filters, $limit, $offset) . ':' . md5($query);
        $tags = $this->searchTags("search:user", "search:user:{$userId}", "search:domain:{$domain}");

        $cached = $this->cacheGet($cacheKey, $tags);
        if ($cached !== null) {
            return $this->normalizeDomainResult($cached, $domain);
        }

        $q = $this->sanitize($query);
        $searchQuery = new SearchQuery($q, $filters, $limit, $offset);
        $results = $this->gateway->searchRegistered($domain, $searchQuery)->toArray();
        $results = $this->normalizeDomainResult($results, $domain);

        $ttl = $this->getCacheTTL('search');
        $this->cacheSetSeconds($cacheKey, $results, $ttl, $tags);

        return $results;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function validateUserSearchPayload(array $payload): void
    {
        if (!array_key_exists('items', $payload) && !array_key_exists('total', $payload)) {
            throw new \UnexpectedValueException('User search cache payload is missing items and total.');
        }

        if (array_key_exists('items', $payload)) {
            $this->flattenUserItems($payload['items']);
        }
        if (array_key_exists('total', $payload) && !is_int($payload['total'])) {
            throw new \UnexpectedValueException('User search cache total must be an integer.');
        }
    }

    /** @return list<\stdClass> */
    private function flattenUserItems(mixed $value): array
    {
        if (!is_array($value)) {
            throw new \UnexpectedValueException('User search result items must be an array.');
        }

        /** @var list<\stdClass> $items */
        $items = [];
        if (array_is_list($value)) {
            foreach ($value as $item) {
                if (!$item instanceof \stdClass) {
                    throw new \UnexpectedValueException('User search result items must be stdClass values.');
                }
                $items[] = $item;
            }
            return $items;
        }

        foreach ($value as $key => $item) {
            if (!is_string($key)) {
                throw new \UnexpectedValueException('User search result groups must use string keys.');
            }
            foreach ($this->requireObjectList($item, "User search domain {$key}") as $row) {
                $items[] = $row;
            }
        }

        return $items;
    }

    /** @return list<\stdClass> */
    private function requireObjectList(mixed $value, string $context): array
    {
        if (!is_array($value) || !array_is_list($value)) {
            throw new \UnexpectedValueException("{$context} items must be a list.");
        }

        /** @var list<\stdClass> $items */
        $items = [];
        foreach ($value as $key => $row) {
            if (!is_int($key) || !$row instanceof \stdClass) {
                throw new \UnexpectedValueException("{$context} items must be a list of stdClass values.");
            }
            $items[] = $row;
        }
        return $items;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{items: list<\stdClass>, total: int, metadata: array<string, mixed>}
     */
    private function normalizeDomainResult(array $payload, string $domain): array
    {
        if (!array_key_exists('items', $payload)) {
            throw new \UnexpectedValueException("Search domain {$domain} result is missing items.");
        }
        $items = $this->requireObjectList($payload['items'], "Search domain {$domain}");

        $total = array_key_exists('total', $payload) ? $payload['total'] : count($items);
        if (!is_int($total) || $total < 0) {
            throw new \UnexpectedValueException("Search domain {$domain} total must be a non-negative integer.");
        }

        $metadata = array_key_exists('metadata', $payload) ? $payload['metadata'] : [];
        $metadata = $this->requireStringKeyedArray(
            $metadata,
            "Search domain {$domain} metadata must be an associative array."
        );

        return ['items' => $items, 'total' => $total, 'metadata' => $metadata];
    }
}
