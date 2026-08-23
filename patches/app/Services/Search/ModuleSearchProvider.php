<?php

declare(strict_types=1);

namespace App\Services\Search;

/**
 * ModuleSearchProvider - تأمین‌کننده اختصاصی جستجوهای ماژولار با Tagged Cache
 */
class ModuleSearchProvider extends BaseSearchProvider implements \App\Contracts\SearchProviderInterface
{
    private ModuleSearchGateway $gateway;
    private AdminSearchGateway $adminSearchGateway;
    private ?\App\Services\Cache\CacheInvalidationService $cacheInvalidation;

    public function __construct(
        \App\Contracts\CacheInterface $cache,
        \App\Contracts\LoggerInterface $logger,
        ModuleSearchGateway $gateway,
        AdminSearchGateway $adminSearchGateway,
        ?\App\Services\Cache\CacheInvalidationService $cacheInvalidation = null,
        ?\App\Services\Settings\AppSettings $appSettings = null
    ) {
        $this->gateway = $gateway;
        $this->adminSearchGateway = $adminSearchGateway;
        $this->cacheInvalidation = $cacheInvalidation;

        parent::__construct($logger, $cache, $appSettings);
    }

    public function supports(string $scope): bool
    {
        return $scope === 'module';
    }

    public function search(\App\Services\Search\SearchQuery $query): \App\Services\Search\SearchResult
    {
        $filters = $query->getFilters();
        $modulesRaw = array_key_exists('modules', $filters) ? $filters['modules'] : [];
        if (!is_array($modulesRaw) || !array_is_list($modulesRaw)) {
            throw new \InvalidArgumentException('Module search modules must be a list of strings.');
        }

        /** @var list<string> $modules */
        $modules = [];
        foreach ($modulesRaw as $module) {
            if (!is_string($module) || $module === '') {
                throw new \InvalidArgumentException('Module search modules must be a list of strings.');
            }
            $modules[] = $module;
        }

        $filters['q'] = $query->getTerm() ?? '';
        $grouped = $this->searchModules(
            $modules,
            $filters,
            $query->getLimit(),
            $query->getOffset()
        );

        /** @var list<\stdClass> $items */
        $items = [];
        $total = 0;
        foreach ($grouped as $module => $moduleResult) {
            $normalized = $this->normalizeModuleResult($moduleResult, $module);
            foreach ($normalized['items'] as $row) {
                $items[] = $row;
            }
            $total += $normalized['total'];
        }

        return new \App\Services\Search\SearchResult(
            $items,
            $total,
            ['modules' => $grouped]
        );
    }

    /**
     * جستجوی اختصاصی ماژول‌های سیستم
     *
     * @param list<string> $modules
     * @param array<string, mixed> $filters
     * @return array<string, array{items: list<\stdClass>, total: int, metadata: array<string, mixed>}>
     */
    public function searchModules(
        array $modules,
        array $filters = [],
        int $limit = self::DEFAULT_LIMIT,
        int $offset = 0
    ): array {
        if (!array_is_list($modules)) {
            throw new \InvalidArgumentException('Module search modules must be a list of strings.');
        }
        foreach ($modules as $module) {
            if (!is_string($module) || $module === '') {
                throw new \InvalidArgumentException('Module search modules must be a list of strings.');
            }
        }

        $filtersJson = json_encode($filters, JSON_UNESCAPED_UNICODE);
        $this->logSearch('module', is_string($filtersJson) ? $filtersJson : '{}', null);

        $limit = max(1, min($limit, self::MAX_LIMIT));
        $offset = max(0, $offset);

        /** @var array<string, array{items: list<\stdClass>, total: int, metadata: array<string, mixed>}> $results */
        $results = [];
        $registeredModules = $this->adminSearchGateway->registeredModules();

        foreach ($modules as $module) {
            if (!in_array($module, $registeredModules, true)) {
                continue;
            }

            $cacheKey = $this->generateCacheKey($module, $filters, $limit, $offset);
            $tags = $this->searchTags('search:module', "search:{$module}", $module);
            $cached = $this->cacheGet($cacheKey, $tags);

            if ($cached !== null) {
                $results[$module] = $this->normalizeModuleResult($cached, $module);
                continue;
            }

            $term = array_key_exists('q', $filters) ? $filters['q'] : '';
            if (!is_string($term)) {
                throw new \InvalidArgumentException('Module search query must be a string.');
            }

            $payload = $this->gateway->searchRegistered($module, $term, $filters, $limit, $offset);
            $payload = $this->normalizeModuleResult($payload, $module);

            $ttl = $this->getCacheTTL('search');
            $this->cacheSetSeconds($cacheKey, $payload, $ttl, $tags);
            $results[$module] = $payload;
        }

        return $results;
    }

    /**
     * پاک‌سازی کش ماژول‌ها
     */
    public function invalidateModuleCache(string $module): void
    {
        if (!in_array($module, $this->adminSearchGateway->registeredModules(), true)) {
            return;
        }

        try {
            if ($this->cacheInvalidation !== null) {
                $this->cacheInvalidation->invalidateModuleSearch($module);
            } else {
                $this->cache->tags([$module])->flush();
                $this->cache->tags(["search:{$module}"])->flush();
            }
            $this->logger->info('search.cache_invalidated', [
                'module' => $module,
                'driver' => $this->cache->driver(),
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('search.cache_invalidation_failed', [
                'module' => $module,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{items: list<\stdClass>, total: int, metadata: array<string, mixed>}
     */
    private function normalizeModuleResult(array $payload, string $module): array
    {
        if (!array_key_exists('items', $payload)) {
            throw new \UnexpectedValueException("Search module {$module} result is missing items.");
        }

        $rawItems = $payload['items'];
        if (!is_array($rawItems) || !array_is_list($rawItems)) {
            throw new \UnexpectedValueException("Search module {$module} items must be a list.");
        }

        /** @var list<\stdClass> $items */
        $items = [];
        foreach ($rawItems as $key => $item) {
            if (!is_int($key) || !$item instanceof \stdClass) {
                throw new \UnexpectedValueException("Search module {$module} items must be a list of stdClass values.");
            }
            $items[] = $item;
        }

        $total = array_key_exists('total', $payload) ? $payload['total'] : count($items);
        if (!is_int($total) || $total < 0) {
            throw new \UnexpectedValueException("Search module {$module} total must be a non-negative integer.");
        }

        $metadata = array_key_exists('metadata', $payload) ? $payload['metadata'] : [];
        $metadata = $this->requireStringKeyedArray(
            $metadata,
            "Search module {$module} metadata must be an associative array."
        );

        return ['items' => $items, 'total' => $total, 'metadata' => $metadata];
    }
}
