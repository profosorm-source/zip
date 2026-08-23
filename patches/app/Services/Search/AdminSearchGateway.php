<?php

declare(strict_types=1);

namespace App\Services\Search;

use App\Contracts\LoggerInterface;
use Core\Database;

/**
 * Read-only adapter for admin search operations.
 * Uses direct table search with schema-aware fallbacks.
 */
class AdminSearchGateway
{

    /**
     * Database::fetchAll has a single row contract (list<stdClass>).
     *
     * Keep this boundary strict. Casting an array/scalar to an object would
     * manufacture a row and let a broken producer reach the search response.
     *
     * @return list<\stdClass>
     */
    private function toObjectArray(mixed $rows): array
    {
        if (!is_array($rows) || !array_is_list($rows)) {
            throw new \UnexpectedValueException('Search database result must be an array of rows.');
        }

        /** @var list<\stdClass> $result */
        $result = [];
        foreach ($rows as $row) {
            if (!$row instanceof \stdClass) {
                throw new \UnexpectedValueException('Search database rows must be stdClass values.');
            }
            $result[] = $row;
        }

        return $result;
    }



    /** @var array<string, array{table:string, alias:string, columns:list<string>, joins?:string, filters?:list<string>, order?:string, deleted?:string|null}> */
    private array $registry = [
        'bank_cards' => ['table' => 'bank_cards', 'alias' => 'bc', 'columns' => ['card_number', 'sheba', 'bank_name', 'status'], 'joins' => 'LEFT JOIN users u ON u.id = bc.user_id', 'filters' => ['status', 'user_id'], 'order' => 'bc.created_at DESC', 'deleted' => 'bc.deleted_at IS NULL'],
        'kyc' => ['table' => 'kyc_verifications', 'alias' => 'kyc', 'columns' => ['national_id', 'status', 'rejection_reason'], 'joins' => 'LEFT JOIN users u ON u.id = kyc.user_id', 'filters' => ['status', 'user_id'], 'order' => 'kyc.created_at DESC'],
        'manual_deposits' => ['table' => 'manual_deposits', 'alias' => 'md', 'columns' => ['tracking_code', 'status', 'transaction_id'], 'joins' => 'LEFT JOIN users u ON u.id = md.user_id', 'filters' => ['status', 'user_id'], 'order' => 'md.created_at DESC'],
        'crypto_deposits' => ['table' => 'crypto_deposits', 'alias' => 'cd', 'columns' => ['tx_hash', 'network', 'verification_status', 'transaction_id'], 'joins' => 'LEFT JOIN users u ON u.id = cd.user_id', 'filters' => ['verification_status', 'status', 'network', 'user_id'], 'order' => 'cd.created_at DESC'],
        'social_accounts' => ['table' => 'social_accounts', 'alias' => 'sa', 'columns' => ['username', 'platform', 'status'], 'joins' => 'LEFT JOIN users u ON u.id = sa.user_id', 'filters' => ['platform', 'status', 'user_id'], 'order' => 'sa.created_at DESC'],
        'data_exports' => ['table' => 'data_exports', 'alias' => 'de', 'columns' => ['type', 'status', 'file_path'], 'joins' => 'LEFT JOIN users u ON u.id = de.user_id', 'filters' => ['type', 'status', 'user_id'], 'order' => 'de.created_at DESC'],
        'account_deletion_logs' => ['table' => 'account_deletion_logs', 'alias' => 'adl', 'columns' => ['reason', 'status', 'admin_note'], 'joins' => 'LEFT JOIN users u ON u.id = adl.user_id', 'filters' => ['status', 'user_id'], 'order' => 'adl.created_at DESC'],
        'investment' => ['table' => 'investments', 'alias' => 'i', 'columns' => ['status'], 'joins' => 'LEFT JOIN users u ON u.id = i.user_id', 'filters' => ['status', 'user_id'], 'order' => 'i.created_at DESC', 'deleted' => 'i.deleted_at IS NULL'],
        'bug_report' => ['table' => 'bug_reports', 'alias' => 'br', 'columns' => ['subject', 'description', 'status'], 'joins' => 'LEFT JOIN users u ON u.id = br.user_id', 'filters' => ['status', 'user_id'], 'order' => 'br.created_at DESC'],
        'escrow' => ['table' => 'escrows', 'alias' => 'es', 'columns' => ['status', 'transaction_id'], 'joins' => 'LEFT JOIN users u ON u.id = es.buyer_id', 'filters' => ['status', 'buyer_id', 'seller_id'], 'order' => 'es.created_at DESC'],
        'prediction' => ['table' => 'prediction_games', 'alias' => 'pg', 'columns' => ['title', 'team_home', 'team_away', 'sport_type', 'status'], 'filters' => ['status', 'sport_type'], 'order' => 'pg.created_at DESC', 'deleted' => 'pg.deleted_at IS NULL'],
        'lottery' => ['table' => 'lottery_rounds', 'alias' => 'lr', 'columns' => ['status', 'type'], 'filters' => ['status', 'type'], 'order' => 'lr.created_at DESC'],
        'coupons' => ['table' => 'coupons', 'alias' => 'c', 'columns' => ['code', 'type', 'applicable_to'], 'filters' => ['active', 'type'], 'order' => 'c.created_at DESC', 'deleted' => 'c.deleted_at IS NULL'],
        'direct_messages' => ['table' => 'direct_messages', 'alias' => 'dm', 'columns' => ['message'], 'joins' => 'LEFT JOIN users s ON s.id = dm.sender_id LEFT JOIN users r ON r.id = dm.recipient_id', 'filters' => ['sender_id', 'recipient_id'], 'order' => 'dm.created_at DESC'],
        'content' => ['table' => 'content_submissions', 'alias' => 'cs', 'columns' => ['title', 'description', 'video_url', 'platform', 'status'], 'joins' => 'LEFT JOIN users u ON u.id = cs.user_id', 'filters' => ['status', 'platform', 'user_id'], 'order' => 'cs.created_at DESC', 'deleted' => 'cs.is_deleted = 0'],
    ];

    private Database $db;
    private LoggerInterface $logger;
    private SchemaInspector $schema;
    public function __construct(
        Database $db,
        LoggerInterface $logger,
        SchemaInspector $schema
    ) {        $this->db = $db;
        $this->logger = $logger;
        $this->schema = $schema;
}

    public function quickSearchUsers(SearchQuery $query): SearchResult
    {
        return $this->searchTable($query, 'users', 'u', ['full_name', 'email', 'mobile', 'username'], '', ['status']);
    }

    public function quickSearchTransactions(SearchQuery $query): SearchResult
    {
        return $this->searchTable($query, 'transactions', 't', ['transaction_id', 'description', 'gateway_transaction_id', 'ref_id'], 'LEFT JOIN users u ON u.id = t.user_id', ['status', 'type', 'currency', 'user_id']);
    }

    public function quickSearchTickets(SearchQuery $query): SearchResult
    {
        return $this->searchTable($query, 'tickets', 't', ['subject', 'ticket_id', 'status', 'priority'], 'LEFT JOIN users u ON u.id = t.user_id LEFT JOIN ticket_categories tc ON tc.id = t.category_id', ['status', 'priority', 'category_id', 'assigned_to', 'user_id']);
    }

    public function quickSearchWithdrawals(SearchQuery $query): SearchResult
    {
        return $this->searchTable($query, 'withdrawals', 'w', ['tracking_code', 'transaction_id', 'status', 'currency'], 'LEFT JOIN users u ON u.id = w.user_id LEFT JOIN bank_cards c ON c.id = w.card_id', ['status', 'currency', 'user_id']);
    }

    /** @return list<\stdClass> */
    public function quickSearchDeposits(SearchQuery $query): array
    {
        $manual = $this->searchRegistered('manual_deposits', $query)->getItems();
        $crypto = $this->searchRegistered('crypto_deposits', $query)->getItems();
        $results = array_merge($manual, $crypto);
        usort($results, function (\stdClass $left, \stdClass $right): int {
            return $this->createdAtTimestamp($right) <=> $this->createdAtTimestamp($left);
        });
        return array_slice($results, 0, $query->getLimit());
    }

    public function quickSearchAds(SearchQuery $query): SearchResult
    {
        return $this->searchTable($query, 'ads', 'a', ['title', 'description', 'keyword'], 'LEFT JOIN users u ON u.id = a.user_id', ['type', 'status', 'user_id']);
    }

    /** @param array<string, mixed> $filters */
    public function searchRegistered(string $module, SearchQuery|string $queryOrTerm, array $filters = [], int $limit = 20, int $offset = 0): SearchResult
    {
        $module = strtolower(trim($module));
        if (!isset($this->registry[$module])) {
            return new SearchResult([], 0);
        }

        $query = $queryOrTerm instanceof SearchQuery
            ? $queryOrTerm
            : new SearchQuery($queryOrTerm, $filters, $limit, $offset);

        $def = $this->registry[$module];
        return $this->searchTable(
            $query,
            $def['table'],
            $def['alias'],
            $def['columns'],
            $def['joins'] ?? '',
            $def['filters'] ?? [],
            $def['deleted'] ?? null,
            $def['order'] ?? null
        );
    }

    /** @return list<string> */
    public function registeredModules(): array
    {
        return array_keys($this->registry);
    }

    /**
     * @param array<string, mixed> $filters
     * @return array{items: list<\stdClass>, total: int, metadata: array<string, mixed>}
     */
    public function searchBanners(string $q, array $filters, int $limit, int $offset): array
    {
        return $this->searchTable(new SearchQuery($q, $filters, $limit, $offset), 'ads', 'a', ['title', 'description', 'keyword'], '', ['status', 'type', 'user_id'], "a.type = 'banner'")->toArray();
    }

    /**
     * @param array<string, mixed> $filters
     * @return array{items: list<\stdClass>, total: int, metadata: array<string, mixed>}
     */
    public function searchContent(string $q, array $filters, int $limit, int $offset): array
    {
        return $this->searchRegistered('content', new SearchQuery($q, $filters, $limit, $offset))->toArray();
    }

    /**
     * @param array<string, mixed> $filters
     * @return array{items: list<\stdClass>, total: int, metadata: array<string, mixed>}
     */
    public function searchContentExport(string $q, array $filters, int $limit, int $offset): array
    {
        return $this->searchContent($q, $filters, $limit, $offset);
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    public function searchTokens(string $q, array $filters, int $limit, int $offset): array
    {
        return (new SearchResult([], 0, ['warning' => 'crypto_tokens table is not available in current schema']))->toArray();
    }

    /**
     * @param array<string, mixed> $filters
     * @return array{items: list<\stdClass>, total: int, metadata: array<string, mixed>}
     */
    public function searchEmails(string $q, array $filters, int $limit, int $offset): array
    {
        return $this->searchTable(new SearchQuery($q, $filters, $limit, $offset), 'email_queue', 'eq', ['to_email', 'subject', 'status'], '', ['status', 'user_id', 'priority'])->toArray();
    }

    /**
     * @param array<string, mixed> $filters
     * @return array{items: list<\stdClass>, total: int, metadata: array<string, mixed>}
     */
    public function searchInvestments(string $q, array $filters, int $limit, int $offset): array
    {
        return $this->searchRegistered('investment', new SearchQuery($q, $filters, $limit, $offset))->toArray();
    }

    /**
     * @param array<string, mixed> $filters
     * @return array{items: list<\stdClass>, total: int, metadata: array<string, mixed>}
     */
    public function searchTicketsAdmin(string $q, array $filters, int $limit, int $offset): array
    {
        return $this->quickSearchTickets(new SearchQuery($q, $filters, $limit, $offset))->toArray();
    }

    /**
     * @param array<string, mixed> $filters
     * @return array{items: list<\stdClass>, total: int, metadata: array<string, mixed>}
     */
    public function searchInfluencersAdmin(string $q, array $filters, int $limit, int $offset): array
    {
        return $this->searchTable(new SearchQuery($q, $filters, $limit, $offset), 'influencer_profiles', 'ip', ['username', 'bio', 'page_url', 'platform', 'status'], 'LEFT JOIN users u ON u.id = ip.user_id', ['status', 'platform', 'category', 'user_id'], 'ip.deleted_at IS NULL')->toArray();
    }

    /** @return array{items: list<\stdClass>, total: int, metadata: array<string, mixed>} */
    public function quickSearchSubmissions(string $q, ?int $userId, int $limit): array
    {
        $filters = $userId ? ['user_id' => $userId] : [];
        return $this->searchTable(new SearchQuery($q, $filters, $limit, 0), 'custom_task_submissions', 'cts', ['proof_url', 'proof_text', 'status'], '', ['status', 'user_id', 'task_id'])->toArray();
    }

    /**
     * @param list<string> $columns
     * @param list<string> $allowedFilters
     */
    private function searchTable(
        SearchQuery $query,
        string $table,
        string $alias,
        array $columns,
        string $joins = '',
        array $allowedFilters = [],
        ?string $fixedWhere = null,
        ?string $defaultOrder = null
    ): SearchResult {
        if (!$this->schema->tableExists($table)) {
            return new SearchResult([], 0, ['warning' => "table {$table} not found"]);
        }

        $existingColumns = $this->schema->getColumns($table);
        $searchableColumns = array_values(array_filter(
            $columns,
            static fn(string $column): bool => in_array($column, $existingColumns, true)
        ));

        $limit = max(1, min(200, $query->getLimit()));
        $offset = max(0, $query->getOffset());
        $params = [];
        $where = ['1=1'];

        if ($fixedWhere !== null && $fixedWhere !== '') {
            $where[] = $fixedWhere;
        }

        $likeWhere = $this->buildSearchWhere($table, $alias, $searchableColumns, $query->getTerm() ?? '', $params);
        if ($likeWhere !== '') {
            $where[] = $likeWhere;
        }

        $this->applyAllowedFilters($alias, $table, $query->getFilters(), $allowedFilters, $where, $params);

        $whereSql = implode(' AND ', $where);
        $orderBy = $this->safeOrderBy($query->getSort(), $alias, $existingColumns, $defaultOrder ?? "{$alias}.created_at DESC");
        $select = "{$alias}.*";

        try {
            $total = (int)$this->db->fetchColumn("SELECT COUNT(*) FROM {$table} {$alias} {$joins} WHERE {$whereSql}", $params);
            $rawItems = $this->db->fetchAll("SELECT {$select} FROM {$table} {$alias} {$joins} WHERE {$whereSql} ORDER BY {$orderBy} LIMIT {$limit} OFFSET {$offset}", $params);
        } catch (\Throwable $e) {
            $this->logger->warning('search.read_query_failed', [
                'table' => $table,
                'error' => $e->getMessage(),
            ]);
            // L-11 FIX: the raw driver message (table/column names, SQL fragments) used to be
            // returned to the caller and rendered in the UI. The detail stays in the log; the
            // response now carries only a stable, non-revealing error code.
            return new SearchResult([], 0, ['error' => 'search_unavailable']);
        }

        // A malformed driver result is an internal contract violation, not a
        // database outage. Validate it outside the database-error handler.
        return new SearchResult($this->toObjectArray($rawItems), $total);
    }

    /**
     * @param list<string> $columns
     * @param array<string, mixed> $params
     */
    private function buildSearchWhere(string $table, string $alias, array $columns, string $q, array &$params): string
    {
        $q = trim($q);
        if ($q === '' || empty($columns)) {
            return '';
        }

        if ($this->schema->hasFullTextIndex($table, $columns)) {
            $boolean = $this->toBooleanQuery($q);
            if ($boolean !== '') {
                $params['ft_term'] = $boolean;
                $qualified = implode(', ', array_map(
                    static fn(string $column): string => "{$alias}.{$column}",
                    $columns
                ));
                return "MATCH({$qualified}) AGAINST(:ft_term IN BOOLEAN MODE)";
            }
        }

        $conditions = [];
        $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], mb_substr($q, 0, 100));
        foreach ($columns as $index => $column) {
            $param = "q_{$index}";
            $conditions[] = "{$alias}.{$column} LIKE :{$param} ESCAPE '\\\\'";
            $params[$param] = '%' . $escaped . '%';
        }

        return '(' . implode(' OR ', $conditions) . ')';
    }

    /**
     * @param array<string, mixed> $queryFilters
     * @param list<string> $allowedFilters
     * @param list<string> $where
     * @param array<string, mixed> $params
     */
    private function applyAllowedFilters(string $alias, string $table, array $queryFilters, array $allowedFilters, array &$where, array &$params): void
    {
        $existingColumns = $this->schema->getColumns($table);
        foreach ($queryFilters as $key => $value) {
            if (!in_array($key, $allowedFilters, true)) {
                continue;
            }
            if (!in_array($key, $existingColumns, true)) {
                continue;
            }
            $paramName = 'f_' . str_replace('.', '_', $key);
            $where[] = "{$alias}.{$key} = :{$paramName}";
            $params[$paramName] = $value;
        }
    }

    /** @param list<string> $existingColumns */
    private function safeOrderBy(?string $sort, string $alias, array $existingColumns, string $defaultOrder): string
    {
        if (empty($sort)) {
            return $defaultOrder;
        }

        $cleanSort = preg_replace('/[^a-zA-Z0-9_\.\s]/', '', $sort);
        if (!is_string($cleanSort) || trim($cleanSort) === '') {
            return $defaultOrder;
        }

        $parts = preg_split('/\s+/', trim($cleanSort));
        if ($parts === false || $parts === [] || !isset($parts[0])) {
            return $defaultOrder;
        }

        $column = str_replace($alias . '.', '', $parts[0]);
        if (!in_array($column, $existingColumns, true)) {
            return $defaultOrder;
        }

        $direction = strtoupper($parts[1] ?? 'DESC') === 'ASC' ? 'ASC' : 'DESC';
        return "{$alias}.{$column} {$direction}";
    }


    private function createdAtTimestamp(\stdClass $row): int
    {
        $createdAt = $row->created_at ?? null;
        if (!is_string($createdAt) || $createdAt === '') {
            return 0;
        }

        $timestamp = strtotime($createdAt);
        return $timestamp === false ? 0 : $timestamp;
    }

    private function toBooleanQuery(string $keyword): string
    {
        $parts = preg_split('/\s+/u', trim($keyword));
        if ($parts === false) {
            return '';
        }

        $terms = [];
        foreach ($parts as $part) {
            $term = preg_replace('/[^\pL\pN_\-]/u', '', $part);
            if (is_string($term) && $term !== '') {
                $terms[] = $term;
            }
        }

        return $terms === [] ? '' : implode(' ', array_map(
            static fn(string $term): string => '+' . $term . '*',
            $terms
        ));
    }
}
