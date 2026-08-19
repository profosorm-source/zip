<?php

declare(strict_types=1);

namespace App\Services\Search;

/**
 * 🚀 UPG-01: ModuleSearchProvider - تأمین‌کننده اختصاصی جستجوهای ماژولار به صورت Tagged Cache
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
    ) {        $this->gateway = $gateway;
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
        $modulesRaw = $query->getFilters()['modules'] ?? [];
        $modules = is_array($modulesRaw)
            ? array_values(array_map(static fn(mixed $m): string => str_value($m), $modulesRaw))
            : [];
        $grouped = $this->searchModules($modules, $query->getFilters(), $query->getLimit(), $query->getOffset());

        // SearchResult::items قراردادش list<\stdClass> است؛ نتایج ماژول‌ها را تخت می‌کنیم
        // و ساختار گروه‌بندی‌شده را برای مصرف‌کنندگان در metadata['modules'] نگه می‌داریم.
        $items = [];
        $total = 0;
        foreach ($grouped as $moduleResult) {
            $moduleItems = $moduleResult['items'] ?? [];
            if (is_array($moduleItems)) {
                foreach ($moduleItems as $row) {
                    if ($row instanceof \stdClass) {
                        $items[] = $row;
                    }
                }
            }
            $total += int_value($moduleResult['total'] ?? count(is_array($moduleItems) ? $moduleItems : []));
        }

        return new \App\Services\Search\SearchResult(
            $items,
            $total,
            ['modules' => $grouped]
        );
    }

    /**
     * جستجوی اختصاصی ماژول‌های سیستم
     */
    /**
     * @param list<string> $modules
     * @param array<string, mixed> $filters
     * @return array<string, array<string, mixed>>
     */
    public function searchModules(
        array $modules,
        array $filters = [],
        int $limit = self::DEFAULT_LIMIT,
        int $offset = 0
    ): array {
        $filtersJson = json_encode($filters, JSON_UNESCAPED_UNICODE);
        $this->logSearch('module', is_string($filtersJson) ? $filtersJson : '{}', null);

        $limit = min($limit, self::MAX_LIMIT);
        $offset = max(0, $offset);
        
        $results = [];
        $registeredModules = $this->adminSearchGateway->registeredModules();

        foreach ($modules as $module) {
            if (!in_array($module, $registeredModules, true)) {
                continue;
            }

            $cacheKey = $this->generateCacheKey($module, $filters, $limit, $offset);
            $tags = $this->searchTags('search:module', "search:{$module}", $module);
            $cached = $this->cacheGet($cacheKey, $tags);

            if (is_array($cached)) {
                /** @var array<string, mixed> $cached */
                $results[$module] = $cached;
                continue;
            }

            // Proxy directly to dynamic AdminSearchGateway to unify pagination, index-usage, and full text search
            $searchResult = $this->gateway->searchRegistered($module, '', $filters, $limit, $offset);

            // Use unified cache TTL from config, fallback to 15 mins
            $ttl = $this->getCacheTTL('search');
            $this->cacheSetSeconds($cacheKey, $searchResult, $ttl, $tags);
            $results[$module] = $searchResult;
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
            if ($this->cacheInvalidation) {
                $this->cacheInvalidation->invalidateModuleSearch($module);
            } else {
                $this->cache->tags([$module])->flush();
                $this->cache->tags(["search:{$module}"])->flush();
            }
            $this->logger->info("search.cache_invalidated", [
                'module' => $module,
                'driver' => $this->cache->driver()
            ]);
        } catch (\Throwable $e) {
            $this->logger->error("search.cache_invalidation_failed", [
                'module' => $module,
                'error' => $e->getMessage()
            ]);
        }
    }
}
