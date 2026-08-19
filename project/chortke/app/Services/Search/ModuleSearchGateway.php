<?php

declare(strict_types=1);

namespace App\Services\Search;

use App\Contracts\LoggerInterface;
use Core\Database;

/**
 * Read-only adapter for module search reads.
 *
 * بیشتر متدها به AdminSearchGateway (که FULLTEXT دارد) پروکسی می‌شوند.
 * تنها مسیر مستقل (ویترین) اکنون dual-read است:
 *   1) projection (scope=module, module=vitrines) با MATCH AGAINST
 *   2) fallback به جدول live با FULLTEXT (از طریق AbstractSearchGateway)
 *
 * این تغییر، آخرین LIKE '%...%' خام در لایه‌ی Module را حذف می‌کند.
 */
final class ModuleSearchGateway extends AbstractSearchGateway
{


    private AdminSearchGateway $reader;
    private SearchProjectionRepository $projection;
    private bool $projectionEnabled;
    public function __construct(
        Database $db,
        LoggerInterface $logger,
        SchemaInspector $schema,
        AdminSearchGateway $reader,
        SearchProjectionRepository $projection,
        bool $projectionEnabled = true
    ) {        $this->reader = $reader;
        $this->projection = $projection;
        $this->projectionEnabled = $projectionEnabled;

        parent::__construct($db, $logger, $schema);
    }

    /**
     * @param array<string, mixed> $filters
     * @return array{items: list<\stdClass>, total: int, metadata: array<string, mixed>}
     */
    public function searchSocialTasks(array $filters, int $limit, int $offset): array
    {
        $filters['type'] = $filters['type'] ?? 'social_task';
        return $this->reader->quickSearchAds(new SearchQuery(str_value($filters['q'] ?? $filters['search'] ?? ''), $filters, $limit, $offset))->toArray();
    }

    /**
     * @param array<string, mixed> $filters
     * @return array{items: list<\stdClass>, total: int, metadata: array<string, mixed>}
     */
    public function searchInfluencers(array $filters, int $limit, int $offset): array
    {
        return $this->reader->searchInfluencersAdmin(str_value($filters['q'] ?? $filters['search'] ?? ''), $filters, $limit, $offset);
    }

    /**
     * @param array<string, mixed> $filters
     * @return array{items: list<\stdClass>, total: int, facets: array<mixed>, source?: string}
     */
    public function searchVitrine(array $filters, int $limit, int $offset): array
    {
        return $this->searchVitrineRead(str_value($filters['q'] ?? $filters['search'] ?? ''), $filters, $limit, $offset);
    }

    /**
     * @param array<string, mixed> $filters
     * @return array{items: list<\stdClass>, total: int, metadata: array<string, mixed>}
     */
    public function searchCustomTasks(array $filters, int $limit, int $offset): array
    {
        $filters['type'] = 'custom_task';
        return $this->reader->quickSearchAds(new SearchQuery(str_value($filters['q'] ?? $filters['search'] ?? ''), $filters, $limit, $offset))->toArray();
    }

    /**
     * @param array<string, mixed> $filters
     * @return array{items: list<\stdClass>, total: int, metadata: array<string, mixed>}
     */
    public function searchInvestments(array $filters, int $limit, int $offset): array
    {
        return $this->reader->searchInvestments(str_value($filters['q'] ?? $filters['search'] ?? ''), $filters, $limit, $offset);
    }

    /**
     * @param array<string, mixed> $filters
     * @return array{items: list<\stdClass>, total: int, metadata: array<string, mixed>}
     */
    public function searchPredictions(array $filters, int $limit, int $offset): array
    {
        return $this->reader->searchRegistered('prediction', str_value($filters['q'] ?? $filters['search'] ?? ''), $filters, $limit, $offset)->toArray();
    }

    /**
     * @param array<string, mixed> $filters
     * @return array{items: list<\stdClass>, total: int, metadata: array<string, mixed>}
     */
    public function searchLotteries(array $filters, int $limit, int $offset): array
    {
        return $this->reader->searchRegistered('lottery', str_value($filters['q'] ?? $filters['search'] ?? ''), $filters, $limit, $offset)->toArray();
    }

    /**
     * @param array<string, mixed> $filters
     * @return array{items: list<\stdClass>, total: int, metadata: array<string, mixed>}
     */
    public function searchContents(array $filters, int $limit, int $offset): array
    {
        return $this->reader->searchContent(str_value($filters['q'] ?? $filters['search'] ?? ''), $filters, $limit, $offset);
    }

    /**
     * @param array<string, mixed> $filters
     * @return array{items: list<\stdClass>, total: int, metadata: array<string, mixed>}
     */
    public function searchCoupons(array $filters, int $limit, int $offset): array
    {
        return $this->reader->searchRegistered('coupons', str_value($filters['q'] ?? $filters['search'] ?? ''), $filters, $limit, $offset)->toArray();
    }

    /**
     * @param array<string, mixed> $filters
     * @return array{items: list<\stdClass>, total: int, metadata: array<string, mixed>}
     */
    public function searchTickets(array $filters, int $limit, int $offset): array
    {
        return $this->reader->searchTicketsAdmin(str_value($filters['q'] ?? $filters['search'] ?? ''), $filters, $limit, $offset);
    }

    /**
     * @param array<string, mixed> $filters
     * @return array{items: list<\stdClass>, total: int, metadata: array<string, mixed>}
     */
    public function searchSeoAds(array $filters, int $limit, int $offset): array
    {
        $filters['type'] = 'seo';
        return $this->reader->quickSearchAds(new SearchQuery(str_value($filters['q'] ?? $filters['search'] ?? ''), $filters, $limit, $offset))->toArray();
    }

    /**
     * @param array<string, mixed> $filters
     * @return array{items: list<\stdClass>, total: int, metadata: array<string, mixed>}
     */
    public function searchDirectMessages(array $filters, int $limit, int $offset): array
    {
        return $this->reader->searchRegistered('direct_messages', str_value($filters['q'] ?? $filters['search'] ?? ''), $filters, $limit, $offset)->toArray();
    }

    /**
     * @param array<string, mixed> $filters
     * @return array{items: list<\stdClass>, total: int, metadata: array<string, mixed>}
     */
    public function searchRegistered(string $module, string $term, array $filters = [], int $limit = 20, int $offset = 0): array
    {
        return $this->reader->searchRegistered($module, $term, $filters, $limit, $offset)->toArray();
    }

    /**
     * @param array<string, mixed> $filters
     * @return array{items: list<\stdClass>, total: int, facets: array<mixed>, source?: string}
     */
    private function searchVitrineRead(string $q, array $filters, int $limit, int $offset): array
    {
        $limit = max(1, min(100, $limit));
        $offset = max(0, $offset);

        // ── مسیر اصلی: Read-Model (CQRS) ──
        if ($this->projectionEnabled && $this->projection->isReady('module', 'vitrines')) {
            $result = $this->projection->search($q, [
                'scope'  => 'module',
                'module' => 'vitrines',
            ], $limit, $offset);

            if (empty($result->getMetadata()['error'])) {
                return ['items' => $result->getItems(), 'total' => $result->getTotal(), 'facets' => []];
            }
        }

        // ── مسیر fallback: جدول live با FULLTEXT (از طریق پایه) ──
        if (!$this->tableExists('vitrine_listings')) {
            return ['items' => [], 'total' => 0, 'facets' => []];
        }

        $params = [];
        $where = ['vl.deleted_at IS NULL'];

        $searchSql = $this->buildSearchWhere('vitrine_listings', 'vl', ['title', 'description', 'username'], $q, $params);
        if ($searchSql !== '') {
            $where[] = $searchSql;
        }

        foreach (['category', 'platform', 'listing_type', 'status'] as $filter) {
            if (!empty($filters[$filter])) {
                $key = 'f_' . $filter;
                $where[] = "vl.{$filter} = :{$key}";
                $params[$key] = str_value($filters[$filter]);
            }
        }

        if (!empty($filters['min_price'])) {
            $where[] = 'vl.price_usdt >= :min_price';
            $params['min_price'] = float_value($filters['min_price']);
        }
        if (!empty($filters['max_price'])) {
            $where[] = 'vl.price_usdt <= :max_price';
            $params['max_price'] = float_value($filters['max_price']);
        }

        $whereSql = implode(' AND ', $where);
        try {
            $total = (int)$this->db->fetchColumn("SELECT COUNT(*) FROM vitrine_listings vl WHERE {$whereSql}", $params);
            $items = $this->db->fetchAll("SELECT vl.* FROM vitrine_listings vl WHERE {$whereSql} ORDER BY vl.created_at DESC LIMIT {$limit} OFFSET {$offset}", $params);
            return ['items' => $items, 'total' => $total, 'facets' => [], 'source' => 'live'];
        } catch (\Throwable $e) {
            $this->logger->warning('search.vitrine_failed', ['error' => $e->getMessage()]);
            return ['items' => [], 'total' => 0, 'facets' => []];
        }
    }
}
