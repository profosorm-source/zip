<?php

declare(strict_types=1);

namespace App\Services\Search;

use App\Contracts\LoggerInterface;
use Core\Database;

/**
 * SearchProjectionRepository — مسیر خواندن Read-Model جستجو (CQRS Read Side).
 *
 * همه‌ی جستجوها از این repository روی جدول واحد `search_projections` با
 * MATCH(title, content) AGAINST(... IN BOOLEAN MODE) اجرا می‌شوند:
 *   - بدون LIKE '%...%' و بدون Full Table Scan
 *   - بدون JOIN به ۱۷ جدول live (دانش schema دامنه‌ها از Search خارج شد)
 *   - ownership کاربر مستقیماً با ستون owner_id اعمال می‌شود
 *   - pagination یکپارچه (همان COUNT با SQL_CALC سبک‌تر روی یک جدول ایندکس‌دار)
 *
 * اگر projection هنوز برای یک scope/module آماده نباشد، متد isReady() به
 * Gatewayها اجازه می‌دهد تصمیم dual-read بگیرند (fallback به جدول live).
 */
class SearchProjectionRepository
{
    private const TABLE = 'search_projections';

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

    /**
     * آیا جدول projection وجود دارد و حداقل یک رکورد فعال در این scope دارد؟
     * (مبنای تصمیم dual-read؛ از فعال‌شدن جستجوی خالی جلوگیری می‌کند)
     */
    public function isReady(string $scope, ?string $module = null): bool
    {
        if (!$this->schema->tableExists(self::TABLE)) {
            $this->applyGracefulTimeout();
            return false;
        }

        try {
            $sql = "SELECT 1 FROM " . self::TABLE . " WHERE scope = ? AND is_active = 1";
            $params = [$scope];
            if ($module !== null) {
                $sql .= " AND module = ?";
                $params[] = $module;
            }
            $sql .= " LIMIT 1";
            $isReady = (bool)$this->db->fetchColumn($sql, $params);
            if (!$isReady) {
                $this->applyGracefulTimeout();
            }
            return $isReady;
        } catch (\Throwable $e) {
            $this->logger->warning('search.projection.ready_check_failed', ['error' => $e->getMessage()]);
            $this->applyGracefulTimeout();
            return false;
        }
    }

    /**
     * Calculates Eventual Consistency Lag in seconds.
     * Compares projection freshness and emits alerts if lag > 300s.
     */
    public function getReplicationLag(): int
    {
        try {
            $rawLastUpdate = $this->db->fetchColumn("SELECT MAX(updated_at) FROM " . self::TABLE);
        } catch (\Throwable) {
            return -1;
        }

        if ($rawLastUpdate === null || $rawLastUpdate === false) {
            return 0;
        }

        if (is_int($rawLastUpdate)) {
            $timestamp = $rawLastUpdate;
        } elseif (is_string($rawLastUpdate) && $rawLastUpdate !== '') {
            $timestamp = strtotime($rawLastUpdate);
            if ($timestamp === false) {
                throw new \UnexpectedValueException('Projection replication timestamp is invalid.');
            }
        } else {
            throw new \UnexpectedValueException('Projection replication timestamp has an invalid type.');
        }

        $lag = time() - $timestamp;
        if ($lag > 300) {
            $this->logger->warning('search.projection.high_lag_detected', ['lag_seconds' => $lag]);
        }
        return max(0, $lag);
    }

    /**
     * Circuit Breaker & Timeout for Live Database Fallback
     * Prevents Live Search Queries from crushing the DB when CQRS projection is not ready.
     */
    private function applyGracefulTimeout(int $milliseconds = 2000): void
    {
        try {
            $milliseconds = max(100, min(30000, $milliseconds));
            $this->db->query("SET SESSION max_execution_time = ?", [$milliseconds]);
        } catch (\Throwable $e) {
            // Ignored if unsupported by DB driver
        }
    }

    /**
     * جستجوی واحد روی projection.
     *
     * @param array<string, mixed> $filters
     */
    public function search(string $term, array $filters, int $limit, int $offset): SearchResult
    {
        if (!$this->schema->tableExists(self::TABLE)) {
            return new SearchResult([], 0, ['error' => 'projection_unavailable']);
        }

        $limit = max(1, min(200, $limit));
        $offset = max(0, $offset);

        // سقف سخت‌گیرانه: حداکثر 10,000 رکورد قابل دسترسی (صفحه ~50 با limit=200)
        // جلوگیری از Deep Offset Pagination که CPU سرور رو فلج میکنه
        $maxOffset = int_value(config('search.max_offset', 10000));
        if ($offset > $maxOffset) {
            $this->logger->warning('search.projection.deep_offset_blocked', [
                'requested_offset' => $offset,
                'max_offset' => $maxOffset,
            ]);
            return new SearchResult([], 0, ['error' => 'offset_too_deep', 'max_offset' => $maxOffset]);
        }

        $where = ['is_active = 1'];
        $params = [];

        if (array_key_exists('scope', $filters)) {
            $scope = $filters['scope'];
            if (!is_string($scope) || $scope === '') {
                throw new \InvalidArgumentException('Projection scope must be a non-empty string.');
            }
            $where[] = 'scope = ?';
            $params[] = $scope;
        }

        // فیلتر ماژول (تک یا چندتایی)
        $modules = [];
        if (array_key_exists('modules', $filters) && array_key_exists('module', $filters)) {
            throw new \InvalidArgumentException('Projection filters may contain module or modules, not both.');
        }
        if (array_key_exists('modules', $filters)) {
            $rawModules = $filters['modules'];
            if (!is_array($rawModules) || !array_is_list($rawModules)) {
                throw new \InvalidArgumentException('Projection modules must be a list of strings.');
            }
            foreach ($rawModules as $module) {
                if (!is_string($module) || $module === '') {
                    throw new \InvalidArgumentException('Projection modules must be a list of strings.');
                }
                $modules[] = $module;
            }
        } elseif (array_key_exists('module', $filters)) {
            $module = $filters['module'];
            if (!is_string($module) || $module === '') {
                throw new \InvalidArgumentException('Projection module must be a non-empty string.');
            }
            $modules[] = $module;
        }
        if ($modules !== []) {
            $where[] = 'module IN (' . implode(', ', array_fill(0, count($modules), '?')) . ')';
            foreach ($modules as $module) {
                $params[] = $module;
            }
        }

        if (array_key_exists('entity_type', $filters)) {
            $entityType = $filters['entity_type'];
            if (!is_string($entityType) || $entityType === '') {
                throw new \InvalidArgumentException('Projection entity_type must be a non-empty string.');
            }
            $where[] = 'entity_type = ?';
            $params[] = $entityType;
        }

        // Ownership کاربر — حیاتی برای امنیت User Search
        if (array_key_exists('owner_id', $filters)) {
            $ownerId = $filters['owner_id'];
            if (!is_int($ownerId) || $ownerId <= 0) {
                throw new \InvalidArgumentException('Projection owner_id must be a positive integer.');
            }
            $where[] = 'owner_id = ?';
            $params[] = $ownerId;
        }

        // عبارت جستجو: FULLTEXT BOOLEAN MODE با fallback به ref برای تطبیق دقیق
        $relevanceSelect = '0 AS relevance';
        $term = trim($term);
        if ($term !== '') {
            $boolean = $this->toBooleanQuery($term);
            if ($boolean !== '') {
                // ترکیب FULLTEXT روی متن + تطبیق دقیق روی ref (کدهای پیگیری/تراکنش)
                $where[] = "(MATCH(title, content) AGAINST(? IN BOOLEAN MODE) OR ref = ?)";
                $params[] = $boolean;
                $params[] = mb_substr($term, 0, 190);

                $relevanceSelect = 'MATCH(title, content) AGAINST(? IN BOOLEAN MODE) AS relevance';
                // پارامتر relevance باید قبل از WHERE قرار گیرد؛ پایین‌تر مدیریت می‌شود.
            }
        }

        $whereSql = implode(' AND ', $where);

        try {
            $total = (int)$this->db->fetchColumn(
                "SELECT COUNT(*) FROM " . self::TABLE . " WHERE {$whereSql}",
                $params
            );

            // مرتب‌سازی: ابتدا relevance (در صورت وجود term)، سپس جدیدترین
            if (str_contains($relevanceSelect, 'MATCH')) {
                // پارامتر اضافه‌ی relevance در ابتدای لیست
                $selectParams = array_merge([$this->toBooleanQuery($term)], $params);
                $rawItems = $this->db->fetchAll(
                    "SELECT id, entity_type, entity_id, owner_id, scope, module, ref, title, content, metadata, updated_at, {$relevanceSelect}
                     FROM " . self::TABLE . "
                     WHERE {$whereSql}
                     ORDER BY relevance DESC, updated_at DESC
                     LIMIT " . $limit . " OFFSET " . $offset,
                    $selectParams
                );
            } else {
                $rawItems = $this->db->fetchAll(
                    "SELECT id, entity_type, entity_id, owner_id, scope, module, ref, title, content, metadata, updated_at
                     FROM " . self::TABLE . "
                     WHERE {$whereSql}
                     ORDER BY updated_at DESC
                     LIMIT " . $limit . " OFFSET " . $offset,
                    $params
                );
            }
        } catch (\Throwable $e) {
            $this->logger->warning('search.projection.query_failed', [
                'error' => $e->getMessage(),
                'scope' => $filters['scope'] ?? null,
            ]);
            // L-11 FIX: the raw driver message (table/column names, SQL fragments) used to be
            // returned to the caller and rendered in the UI. The detail stays in the log; the
            // response now carries only a stable, non-revealing error code.
            return new SearchResult([], 0, ['error' => 'search_unavailable']);
        }

        // A malformed driver result is an internal contract violation, not a
        // query outage. Validate it outside the database-error handler.
        $items = $this->toObjectArray($rawItems);
        return new SearchResult($this->hydrate($items), $total, ['source' => 'projection']);
    }

    /** @return list<\stdClass> */
    private function toObjectArray(mixed $rows): array
    {
        if (!is_array($rows) || !array_is_list($rows)) {
            throw new \UnexpectedValueException('Projection database result must be a list of rows.');
        }

        /** @var list<\stdClass> $result */
        $result = [];
        foreach ($rows as $row) {
            if (!$row instanceof \stdClass) {
                throw new \UnexpectedValueException('Projection database rows must be stdClass values.');
            }
            $result[] = $row;
        }
        return $result;
    }

    /**
     * تبدیل metadata (JSON) به فیلدهای قابل‌نمایش روی هر آیتم.
     *
     * @param list<\stdClass> $rows
     * @return list<\stdClass>
     */
    private function hydrate(array $rows): array
    {
        /** @var list<\stdClass> $out */
        $out = [];
        foreach ($rows as $row) {
            $entityId = $row->entity_id ?? null;
            if ((!is_int($entityId) && !is_string($entityId)) || (is_string($entityId) && !is_numeric($entityId))) {
                throw new \UnexpectedValueException('Projection row entity_id is invalid.');
            }

            $entityType = $row->entity_type ?? null;
            if (!is_string($entityType) || $entityType === '') {
                throw new \UnexpectedValueException('Projection row entity_type is invalid.');
            }

            $module = $row->module ?? null;
            if ($module !== null && !is_string($module)) {
                throw new \UnexpectedValueException('Projection row module is invalid.');
            }
            $ref = $row->ref ?? null;
            if ($ref !== null && !is_string($ref)) {
                throw new \UnexpectedValueException('Projection row ref is invalid.');
            }
            $title = $row->title ?? null;
            if ($title !== null && !is_string($title)) {
                throw new \UnexpectedValueException('Projection row title is invalid.');
            }
            $updatedAt = $row->updated_at ?? null;
            if ($updatedAt !== null && !is_string($updatedAt)) {
                throw new \UnexpectedValueException('Projection row updated_at is invalid.');
            }

            $meta = [];
            $rawMetadata = $row->metadata ?? null;
            if ($rawMetadata !== null && $rawMetadata !== '') {
                if (!is_string($rawMetadata)) {
                    throw new \UnexpectedValueException('Projection row metadata is invalid.');
                }
                $decoded = json_decode($rawMetadata, true);
                if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
                    throw new \UnexpectedValueException('Projection row metadata JSON is invalid.');
                }
                $meta = $decoded;
            }

            // Preserve all metadata under its own key to avoid collisions.
            $out[] = (object)[
                'id'          => (int)$entityId,
                'entity_type' => $entityType,
                'module'      => $module,
                'ref'         => $ref,
                'title'       => $title,
                'updated_at'  => $updatedAt,
                'metadata'    => $meta,
            ];
        }
        return $out;
    }

    /**
     * تبدیل عبارت کاربر به کوئری امنِ BOOLEAN MODE (هر واژه با + و * برای prefix match).
     */
    private function toBooleanQuery(string $term): string
    {
        $term = mb_substr($term, 0, 100);
        // حذف کاراکترهای کنترلی BOOLEAN MODE برای جلوگیری از خطای syntax/سوءاستفاده
        $clean = preg_replace('/[+\-><\(\)~*\"@]+/u', ' ', $term);
        if (!is_string($clean)) {
            return '';
        }
        $words = preg_split('/\s+/u', trim($clean), -1, PREG_SPLIT_NO_EMPTY);
        if ($words === false) {
            return '';
        }

        $parts = [];
        foreach ($words as $word) {
            if ($word === '') {
                continue;
            }
            // هر واژه الزامی (+) و با امکان prefix-match (*)
            $parts[] = '+' . $word . '*';
        }

        return implode(' ', $parts);
    }
}
