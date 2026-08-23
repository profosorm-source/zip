<?php

declare(strict_types=1);

namespace App\Services\Search;


/**
 * 🚀 UPG-01: AdminSearchProvider - تأمین‌کننده اختصاصی جستجوی ادمین و پنل مدیریت
 */
class AdminSearchProvider extends BaseSearchProvider implements \App\Contracts\SearchProviderInterface
{
    private AdminSearchGateway $gateway;
    public function __construct(
        \App\Contracts\CacheInterface $cache,
        \App\Contracts\LoggerInterface $logger,
        AdminSearchGateway $gateway,
        ?\App\Services\Settings\AppSettings $appSettings = null
    ) {        $this->gateway = $gateway;

        parent::__construct($logger, $cache, $appSettings);
    }

    public function supports(string $scope): bool
    {
        return $scope === 'admin' || str_starts_with($scope, 'admin_module:');
    }

    public function search(\App\Services\Search\SearchQuery $query): \App\Services\Search\SearchResult
    {
        $filters = $query->getFilters();
        $scope = array_key_exists('scope', $filters) ? $filters['scope'] : 'admin';
        if (!is_string($scope) || $scope === '') {
            throw new \InvalidArgumentException('Admin search scope must be a non-empty string.');
        }

        if (str_starts_with($scope, 'admin_module:')) {
            $module = str_replace('admin_module:', '', $scope);
            if ($module === '') {
                throw new \InvalidArgumentException('Admin module search scope must name a module.');
            }
            $result = $this->searchRegisteredModule(
                $module,
                $query->getTerm() ?? '',
                $query->getFilters(),
                $query->getLimit(),
                $query->getOffset()
            );
        } else {
            $result = $this->searchAdmin($query);
        }

        $items = $this->itemsFromAdminPayload($result);
        $total = array_key_exists('total', $result) ? $result['total'] : count($items);
        if (!is_int($total) || $total < 0) {
            throw new \UnexpectedValueException('Admin search total must be a non-negative integer.');
        }
        return new \App\Services\Search\SearchResult($items, $total, $result);
    }

    /**
     * جستجوی سراسری ادمین در کل جداول سیستم
     */
    /** @return array<string, mixed> */
    public function searchAdmin(SearchQuery $query): array
    {
        $this->logSearch('admin', $query->getTerm() ?? '', null);

        $limit = $query->getLimit();
        $offset = $query->getOffset();
        $term = $query->getTerm() ?? '';

        $cacheKey = "global_search_admin:" . md5($term . ':' . $limit . ':' . $offset);
        $tags = $this->searchTags('search:admin');

        // Admin search must be real-time to avoid data staleness.
        $cached = $this->cacheGet($cacheKey, $tags);
        if ($cached !== null) {
            return $this->normalizeGlobalPayload($cached);
        }

        $q = $this->sanitize($term);
        if (mb_strlen($q, 'UTF-8') < 2) {
            return $this->emptyGlobalResult();
        }

        $qObj = clone $query; // Clone to avoid modifying original reference

        /** @var array<string, list<\stdClass>|int> $results */
        $results = [
            'users' => $this->searchUsers($q, $limit),
            'transactions' => $this->searchTransactions($q, $limit),
            'tickets' => $this->searchTicketsGlobal($q, $limit),
            'withdrawals' => $this->searchWithdrawals($q, $limit),
            'deposits' => $this->searchDeposits($q, $limit),
            'ads' => $this->searchAds($q, $limit),
            'kyc' => $this->itemsFromResult($this->gateway->searchRegistered('kyc', $qObj)),
            'bank_cards' => $this->itemsFromResult($this->gateway->searchRegistered('bank_cards', $qObj)),
            'contents' => $this->itemsFromArrayResult($this->gateway->searchContent($q, [], $limit, 0)),
            'influencers' => $this->itemsFromArrayResult($this->gateway->searchInfluencersAdmin($q, [], $limit, 0)),
            'investments' => $this->itemsFromArrayResult($this->gateway->searchInvestments($q, [], $limit, 0)),
            'bug_reports' => $this->itemsFromResult($this->gateway->searchRegistered('bug_report', $qObj)),
            'escrows' => $this->itemsFromResult($this->gateway->searchRegistered('escrow', $qObj)),
        ];

        $total = 0;
        foreach ($results as $items) {
            if (is_array($items)) {
                $total += count($items);
            }
        }
        $results['total'] = $total;
        $results = $this->normalizeGlobalPayload($results);

        // Force Admin Search cache TTL to 0 or very short (1 second) to prevent staleness
        $this->cacheSetSeconds($cacheKey, $results, 1, $tags);

        return $results;
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    public function searchBanners(string $q, array $filters = [], int $limit = self::DEFAULT_LIMIT, int $offset = 0): array
    {
        $this->logSearch('banners', $q, null);
        $limit = min($limit, self::MAX_LIMIT);
        $offset = max(0, $offset);

        $cacheKey = $this->generateCacheKey('banners', array_merge(['q' => $q], $filters), $limit, $offset);
        $tags = $this->searchTags('search:admin', 'search:banners');
        $cached = $this->cacheGet($cacheKey, $tags);
        if ($cached !== null) return $this->normalizeItemResultPayload($cached, 'banners');

        $q = $this->sanitize($q);
        $result = $this->normalizeItemResultPayload(
            $this->gateway->searchBanners($q, $filters, $limit, $offset),
            'banners'
        );

        $this->cacheSetSeconds($cacheKey, $result, $this->getCacheTTL('banners'), $tags);
        return $result;
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    public function searchContent(string $q, array $filters = [], int $limit = self::DEFAULT_LIMIT, int $offset = 0): array
    {
        $this->logSearch('content', $q, null);
        $limit = min($limit, self::MAX_LIMIT);
        $offset = max(0, $offset);

        $cacheKey = $this->generateCacheKey('content', array_merge(['q' => $q], $filters), $limit, $offset);
        $tags = $this->searchTags('search:admin', 'search:content');
        $cached = $this->cacheGet($cacheKey, $tags);
        if ($cached !== null) return $this->normalizeItemResultPayload($cached, 'content');

        $q = $this->sanitize($q);
        $result = $this->normalizeItemResultPayload(
            $this->gateway->searchContent($q, $filters, $limit, $offset),
            'content'
        );

        $this->cacheSetSeconds($cacheKey, $result, $this->getCacheTTL('seo'), $tags);
        return $result;
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    public function searchContentForExport(string $q, array $filters = [], int $limit = 1000, int $offset = 0): array
    {
        $this->logSearch('content_export', $q, null);
        $limit = max(1, min(5000, $limit));
        $offset = max(0, $offset);

        $cacheKey = $this->generateCacheKey('content_export', array_merge(['q' => $q], $filters), $limit, $offset);
        $tags = $this->searchTags('search:admin', 'search:content', 'export:content');
        $cached = $this->cacheGet($cacheKey, $tags);
        if ($cached !== null) return $this->normalizeItemResultPayload($cached, 'content_export');

        $q = $this->sanitize($q);
        $result = $this->normalizeItemResultPayload(
            $this->gateway->searchContentExport($q, $filters, $limit, $offset),
            'content_export'
        );

        $this->cacheSetSeconds($cacheKey, $result, $this->getCacheTTL('seo'), $tags);
        return $result;
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    public function searchTokens(string $q, array $filters = [], int $limit = self::DEFAULT_LIMIT, int $offset = 0): array
    {
        $this->logSearch('tokens', $q, null);
        $limit = min($limit, self::MAX_LIMIT);
        $offset = max(0, $offset);

        $cacheKey = $this->generateCacheKey('tokens', array_merge(['q' => $q], $filters), $limit, $offset);
        $tags = $this->searchTags('search:admin', 'search:tokens');
        $cached = $this->cacheGet($cacheKey, $tags);
        if ($cached !== null) return $this->normalizeItemResultPayload($cached, 'tokens');

        $q = $this->sanitize($q);
        $result = $this->normalizeItemResultPayload(
            $this->gateway->searchTokens($q, $filters, $limit, $offset),
            'tokens'
        );

        $this->cacheSetSeconds($cacheKey, $result, $this->getCacheTTL('settings'), $tags);
        return $result;
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    public function searchEmails(string $q, array $filters = [], int $limit = self::DEFAULT_LIMIT, int $offset = 0): array
    {
        $this->logSearch('emails', $q, null);
        $limit = min($limit, self::MAX_LIMIT);
        $offset = max(0, $offset);

        $cacheKey = $this->generateCacheKey('emails', array_merge(['q' => $q], $filters), $limit, $offset);
        $tags = $this->searchTags('search:admin', 'search:emails');
        $cached = $this->cacheGet($cacheKey, $tags);
        if ($cached !== null) return $this->normalizeItemResultPayload($cached, 'emails');

        $q = $this->sanitize($q);
        $result = $this->normalizeItemResultPayload(
            $this->gateway->searchEmails($q, $filters, $limit, $offset),
            'emails'
        );

        $this->cacheSetSeconds($cacheKey, $result, $this->getCacheTTL('transactions'), $tags);
        return $result;
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    public function searchInvestments(string $q, array $filters = [], int $limit = self::DEFAULT_LIMIT, int $offset = 0): array
    {
        $this->logSearch('investments', $q, null);
        $limit = min($limit, self::MAX_LIMIT);
        $offset = max(0, $offset);

        $cacheKey = $this->generateCacheKey('investments', array_merge(['q' => $q], $filters), $limit, $offset);
        $tags = $this->searchTags('search:admin', 'search:investments');
        $cached = $this->cacheGet($cacheKey, $tags);
        if ($cached !== null) return $this->normalizeItemResultPayload($cached, 'investments');

        $q = $this->sanitize($q);
        $result = $this->normalizeItemResultPayload(
            $this->gateway->searchInvestments($q, $filters, $limit, $offset),
            'investments'
        );

        $this->cacheSetSeconds($cacheKey, $result, $this->getCacheTTL('lottery'), $tags);
        return $result;
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    public function searchTickets(string $q, array $filters = [], int $limit = self::DEFAULT_LIMIT, int $offset = 0): array
    {
        $this->logSearch('tickets', $q, null);
        $limit = min($limit, self::MAX_LIMIT);
        $offset = max(0, $offset);

        $cacheKey = $this->generateCacheKey('tickets', array_merge(['q' => $q], $filters), $limit, $offset);
        $tags = $this->searchTags('search:admin', 'search:tickets');
        $cached = $this->cacheGet($cacheKey, $tags);
        if ($cached !== null) return $this->normalizeItemResultPayload($cached, 'tickets');

        $q = $this->sanitize($q);
        $result = $this->normalizeItemResultPayload(
            $this->gateway->searchTicketsAdmin($q, $filters, $limit, $offset),
            'tickets'
        );

        $this->cacheSetSeconds($cacheKey, $result, $this->getCacheTTL('tickets'), $tags);
        return $result;
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    public function searchInfluencers(string $q, array $filters = [], int $limit = self::DEFAULT_LIMIT, int $offset = 0): array
    {
        $this->logSearch('influencers', $q, null);
        $limit = min($limit, self::MAX_LIMIT);
        $offset = max(0, $offset);

        $cacheKey = $this->generateCacheKey('influencers', array_merge(['q' => $q], $filters), $limit, $offset);
        $tags = $this->searchTags('search:admin', 'search:influencers');
        $cached = $this->cacheGet($cacheKey, $tags);
        if ($cached !== null) return $this->normalizeItemResultPayload($cached, 'influencers');

        $q = $this->sanitize($q);
        $result = $this->normalizeItemResultPayload(
            $this->gateway->searchInfluencersAdmin($q, $filters, $limit, $offset),
            'influencers'
        );

        $this->cacheSetSeconds($cacheKey, $result, $this->getCacheTTL('influencers'), $tags);
        return $result;
    }


    /**
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    public function searchRegisteredModule(string $module, string $q, array $filters = [], int $limit = self::DEFAULT_LIMIT, int $offset = 0): array
    {
        if ($module === '') {
            throw new \InvalidArgumentException('Admin search module must be non-empty.');
        }

        $this->logSearch('admin_registered:' . $module, $q, null);
        $limit = min($limit, self::MAX_LIMIT);
        $offset = max(0, $offset);

        $cacheKey = $this->generateCacheKey('registered:' . $module, array_merge(['q' => $q], $filters), $limit, $offset);
        $tags = $this->searchTags('search:admin', 'search:' . $module);
        $cached = $this->cacheGet($cacheKey, $tags);
        if ($cached !== null) return $this->normalizeItemResultPayload($cached, "registered:{$module}");

        $searchResult = $this->gateway->searchRegistered($module, $this->sanitize($q), $filters, $limit, $offset);
        $result = $this->normalizeItemResultPayload($searchResult->toArray(), "registered:{$module}");
        $this->cacheSetSeconds($cacheKey, $result, $this->getCacheTTL($module), $tags);
        return $result;
    }

    /** @return list<string> */
    public function registeredModules(): array
    {
        return $this->gateway->registeredModules();
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function normalizeGlobalPayload(array $payload): array
    {
        if (!array_key_exists('total', $payload) || !is_int($payload['total']) || $payload['total'] < 0) {
            throw new \UnexpectedValueException('Admin global search total must be a non-negative integer.');
        }

        foreach ($payload as $domain => $value) {
            if (!is_string($domain)) {
                throw new \UnexpectedValueException('Admin global search keys must be strings.');
            }
            if ($domain === 'total') {
                continue;
            }
            if ($domain === 'metadata') {
                $this->requireStringKeyedArray(
                    $value,
                    'Admin global search metadata must be an associative array.'
                );
                continue;
            }
            if (!is_array($value)) {
                throw new \UnexpectedValueException('Admin global search domains must contain arrays.');
            }
            $this->itemsFromPayload($value);
        }

        return $payload;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{items: list<\stdClass>, total: int, metadata: array<string, mixed>}
     */
    private function normalizeItemResultPayload(array $payload, string $context): array
    {
        if (!array_key_exists('items', $payload)) {
            throw new \UnexpectedValueException("Search {$context} payload is missing items.");
        }
        $items = $this->itemsFromPayload($payload['items']);

        $total = array_key_exists('total', $payload) ? $payload['total'] : count($items);
        if (!is_int($total) || $total < 0) {
            throw new \UnexpectedValueException("Search {$context} total must be a non-negative integer.");
        }

        $metadata = array_key_exists('metadata', $payload) ? $payload['metadata'] : [];
        $metadata = $this->requireStringKeyedArray(
            $metadata,
            "Search {$context} metadata must be an associative array."
        );

        return ['items' => $items, 'total' => $total, 'metadata' => $metadata];
    }

    /**
     * @param array<string, mixed> $payload
     * @return list<\stdClass>
     */
    private function itemsFromAdminPayload(array $payload): array
    {
        if (array_key_exists('items', $payload)) {
            return $this->normalizeItemResultPayload($payload, 'admin search')['items'];
        }

        // The global admin response is grouped by domain. Flatten it only for
        // SearchResult; the public searchAdmin() array remains grouped.
        $items = [];
        foreach ($payload as $domain => $value) {
            if ($domain === 'total' || $domain === 'metadata') {
                continue;
            }
            if (!is_array($value)) {
                throw new \UnexpectedValueException('Admin search domain items must be arrays.');
            }
            foreach ($this->itemsFromPayload($value) as $item) {
                $items[] = $item;
            }
        }
        return $items;
    }

    /** @return list<\stdClass> */
    private function itemsFromResult(\App\Services\Search\SearchResult $result): array
    {
        return $result->getItems();
    }

    /**
     * @param array<string, mixed> $payload
     * @return list<\stdClass>
     */
    private function itemsFromArrayResult(array $payload): array
    {
        return $this->normalizeItemResultPayload($payload, 'array result')['items'];
    }

    /** @return list<\stdClass> */
    private function itemsFromPayload(mixed $value): array
    {
        if (!is_array($value) || !array_is_list($value)) {
            throw new \UnexpectedValueException('Search result items must be a list.');
        }

        /** @var list<\stdClass> $items */
        $items = [];
        foreach ($value as $item) {
            if (!$item instanceof \stdClass) {
                throw new \UnexpectedValueException('Search result items must be stdClass values.');
            }
            $items[] = $item;
        }
        return $items;
    }

    // Internal delegators

    /** @return list<\stdClass> */
    private function searchUsers(string $q, int $limit): array
    {
        return $this->itemsFromResult($this->gateway->quickSearchUsers(new SearchQuery($q, [], $limit, 0)));
    }

    /** @return list<\stdClass> */
    private function searchTransactions(string $q, int $limit): array
    {
        return $this->itemsFromResult($this->gateway->quickSearchTransactions(new SearchQuery($q, [], $limit, 0)));
    }

    /** @return list<\stdClass> */
    private function searchTicketsGlobal(string $q, int $limit): array
    {
        return $this->itemsFromResult($this->gateway->quickSearchTickets(new SearchQuery($q, [], $limit, 0)));
    }

    /** @return list<\stdClass> */
    private function searchWithdrawals(string $q, int $limit): array
    {
        return $this->itemsFromResult($this->gateway->quickSearchWithdrawals(new SearchQuery($q, [], $limit, 0)));
    }

    /** @return list<\stdClass> */
    private function searchDeposits(string $q, int $limit): array
    {
        return $this->gateway->quickSearchDeposits(new SearchQuery($q, [], $limit, 0)); // Already returns array of items
    }

    /** @return list<\stdClass> */
    private function searchAds(string $q, int $limit): array
    {
        return $this->itemsFromResult($this->gateway->quickSearchAds(new SearchQuery($q, [], $limit, 0)));
    }

    /** @return array{items: list<\stdClass>, total: int, metadata: array<string, mixed>} */
    public function quickSearchAds(string $q, ?int $userId, int $limit): array
    {
        $filters = $userId ? ['user_id' => $userId] : [];
        return $this->normalizeItemResultPayload(
            $this->gateway->quickSearchAds(new SearchQuery($q, $filters, $limit, 0))->toArray(),
            'quick_ads'
        );
    }

    /** @return array{items: list<\stdClass>, total: int, metadata: array<string, mixed>} */
    public function quickSearchSubmissions(string $q, ?int $userId, int $limit): array
    {
        return $this->normalizeItemResultPayload(
            $this->gateway->quickSearchSubmissions($q, $userId, $limit),
            'quick_submissions'
        );
    }
}
