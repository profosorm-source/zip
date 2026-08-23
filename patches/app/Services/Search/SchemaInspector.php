<?php

declare(strict_types=1);

namespace App\Services\Search;

use Core\Cache;
use Core\Database;

/**
 * SchemaInspector — متمرکزسازی introspection اسکیمای دیتابیس برای لایه‌ی Search.
 *
 * استفاده از Cache مرکزی (Redis) و کوئری‌های بهینه INFORMATION_SCHEMA به جای
 * کش استاتیک و SHOW TABLES. نتیجه‌های این کلاس در مرز cache به‌صورت صریح
 * اعتبارسنجی می‌شوند؛ مقدار scalar هرگز به row/object تبدیل نمی‌شود.
 */
class SchemaInspector
{
    private const TTL = 3600 * 24;
    private const TAG = 'schema:introspection';

    /**
     * @var array<string, list<string>>
     */
    private const STATIC_FULLTEXT_INDEXES = [
        'audit_trail' => ['event'],
        'users' => ['full_name', 'email', 'mobile', 'username'],
        'transactions' => ['transaction_id', 'description', 'gateway_transaction_id', 'ref_id'],
        'tickets' => ['subject', 'ticket_id', 'status', 'priority'],
        'withdrawals' => ['tracking_code', 'transaction_id', 'status', 'currency'],
        'ads' => ['title', 'description', 'keyword'],
        'search_projections' => ['title', 'content', 'ref'],
        'content_submissions' => ['title', 'description', 'video_url', 'platform', 'status'],
        'influencer_profiles' => ['username', 'bio', 'page_url', 'platform', 'status'],
        'vitrine_listings' => ['title', 'description', 'username'],
        'bank_cards' => ['card_number', 'sheba', 'bank_name', 'status'],
        'kyc_verifications' => ['national_id', 'status', 'rejection_reason'],
        'manual_deposits' => ['tracking_code', 'status', 'transaction_id'],
        'crypto_deposits' => ['tx_hash', 'network', 'verification_status', 'transaction_id'],
        'social_accounts' => ['username', 'platform', 'status'],
        'data_exports' => ['type', 'status', 'file_path'],
        'account_deletion_logs' => ['reason', 'status', 'admin_note'],
        'bug_reports' => ['subject', 'description', 'status'],
        'escrows' => ['status', 'transaction_id'],
        'prediction_games' => ['title', 'team_home', 'team_away', 'sport_type', 'status'],
        'lottery_rounds' => ['status', 'type'],
        'coupons' => ['code', 'type', 'applicable_to'],
        'direct_messages' => ['message'],
    ];

    private Database $db;
    private Cache $cache;

    public function __construct(Database $db, Cache $cache)
    {
        $this->db = $db;
        $this->cache = $cache;
    }

    public function tableExists(string $table): bool
    {
        $table = $this->normalizeIdentifier($table);
        $cacheKey = "schema:table_exists:{$table}";

        $cached = $this->cache->tags([self::TAG])->rememberSeconds(
            $cacheKey,
            self::TTL,
            function () use ($table): bool {
                try {
                    return (bool) $this->db->fetchColumn(
                        'SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
                        [$table]
                    );
                } catch (\Throwable) {
                    // A missing schema connection is a valid boundary failure
                    // for the read path; callers can choose their fallback.
                    return false;
                }
            }
        );

        if (!is_bool($cached)) {
            throw new \UnexpectedValueException('Schema table-exists cache must contain a boolean.');
        }

        return $cached;
    }

    /** @return list<string> */
    public function getColumns(string $table): array
    {
        $table = $this->normalizeIdentifier($table);
        $cacheKey = "schema:columns:{$table}";

        $cached = $this->cache->tags([self::TAG])->rememberSeconds(
            $cacheKey,
            self::TTL,
            function () use ($table): array {
                try {
                    $rows = $this->db->fetchAll(
                        'SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
                        [$table]
                    );
                } catch (\Throwable) {
                    return [];
                }

                return $this->columnNamesFromRows($rows);
            }
        );

        return $this->requireStringList(
            $cached,
            'Schema columns cache must contain a list of strings.'
        );
    }

    /**
     * فقط ستون‌هایی از $wanted را برمی‌گرداند که واقعاً در جدول موجودند.
     *
     * @param list<string> $wanted
     * @return list<string>
     */
    public function filterExistingColumns(string $table, array $wanted): array
    {
        $existing = $this->getColumns($table);
        $filtered = array_filter(
            $wanted,
            static fn(string $column): bool => in_array($column, $existing, true)
        );
        return array_values($filtered);
    }

    /**
     * بررسی اینکه آیا یک FULLTEXT index همه‌ی ستون‌های داده‌شده را پوشش می‌دهد.
     *
     * @param list<string> $columns
     */
    public function hasFullTextIndex(string $table, array $columns): bool
    {
        $table = $this->normalizeIdentifier($table);
        $wanted = [];
        foreach ($columns as $column) {
            $column = strtolower($this->normalizeIdentifier($column));
            if ($column !== '' && !in_array($column, $wanted, true)) {
                $wanted[] = $column;
            }
        }

        if ($wanted === []) {
            return false;
        }

        $indexed = $this->getFullTextIndexedColumns($table);
        foreach ($wanted as $column) {
            if (!in_array($column, $indexed, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * تمام ستون‌هایی که در هر FULLTEXT index جدول شرکت دارند (lowercase).
     *
     * @return list<string>
     */
    private function getFullTextIndexedColumns(string $table): array
    {
        $cacheKey = "schema:fulltext_columns:{$table}";
        $cached = $this->cache->tags([self::TAG])->rememberSeconds(
            $cacheKey,
            self::TTL,
            function () use ($table): array {
                try {
                    $rows = $this->db->fetchAll(
                        "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.STATISTICS
                         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_TYPE = 'FULLTEXT'",
                        [$table]
                    );
                } catch (\Throwable) {
                    // INFORMATION_SCHEMA is unavailable. The static map is
                    // only an offline fallback, never a coercion of bad data.
                    $fallback = self::STATIC_FULLTEXT_INDEXES[$table] ?? [];
                    $normalized = [];
                    foreach ($fallback as $column) {
                        $normalized[] = strtolower($column);
                    }
                    return array_values(array_unique($normalized));
                }

                $columns = $this->columnNamesFromRows($rows);
                $normalized = [];
                foreach ($columns as $column) {
                    $normalized[] = strtolower($column);
                }
                return array_values(array_unique($normalized));
            }
        );

        return $this->requireStringList(
            $cached,
            'Schema fulltext cache must contain a list of strings.'
        );
    }

    /**
     * @return list<string>
     */
    private function columnNamesFromRows(mixed $rows): array
    {
        if (!is_array($rows) || !array_is_list($rows)) {
            throw new \UnexpectedValueException('Schema database rows must be a list.');
        }

        /** @var list<string> $columns */
        $columns = [];
        foreach ($rows as $row) {
            if (!$row instanceof \stdClass) {
                throw new \UnexpectedValueException('Schema database rows must be stdClass values.');
            }

            $name = $row->COLUMN_NAME ?? $row->Column_name ?? null;
            if (!is_string($name) || $name === '') {
                throw new \UnexpectedValueException('Schema database rows must contain COLUMN_NAME.');
            }
            $columns[] = $name;
        }

        return $columns;
    }

    /** @return list<string> */
    private function requireStringList(mixed $value, string $message): array
    {
        if (!is_array($value) || !array_is_list($value)) {
            throw new \UnexpectedValueException($message);
        }

        /** @var list<string> $result */
        $result = [];
        foreach ($value as $key => $item) {
            if (!is_int($key) || !is_string($item) || $item === '') {
                throw new \UnexpectedValueException($message);
            }
            $result[] = $item;
        }

        return $result;
    }

    private function normalizeIdentifier(string $identifier): string
    {
        $normalized = trim(str_replace('`', '', $identifier));
        if ($normalized === '' || preg_match('/\A[a-zA-Z0-9_]+\z/D', $normalized) !== 1) {
            throw new \InvalidArgumentException('Schema identifiers must contain only letters, numbers, and underscores.');
        }
        return $normalized;
    }

    public function flush(): void
    {
        $this->cache->tags([self::TAG])->flush();
    }

    /** @param list<string> $tables */
    public function warm(array $tables): void
    {
        foreach ($tables as $table) {
            $this->tableExists($table);
            $this->getColumns($table);
        }
    }
}
