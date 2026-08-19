<?php

declare(strict_types=1);

namespace App\Services\Search;

use Core\Database;
use Core\Cache;

/**
 * SchemaInspector — متمرکزسازی introspection اسکیمای دیتابیس برای لایه‌ی Search.
 *
 * استفاده از Cache مرکزی (Redis) و کوئری‌های بهینه INFORMATION_SCHEMA به جای
 * کش استاتیک (برای جلوگیری از Memory Leak در Swoole) و SHOW TABLES (کوئری‌های DDL کند).
 *
 * ⚠️ IMPORTANT: STATIC_FULLTEXT_INDEXES باید با migration ها همگام بمونه.
 * هر وقت FULLTEXT INDEX جدید اضافه کردید، اینجا هم آپدیت کنید.
 */
class SchemaInspector
{



    private const TTL = 3600 * 24; // 24 ساعت کش
    private const TAG = 'schema:introspection';

    private Database $db;
    private Cache $cache;
    public function __construct(
        Database $db,
        Cache $cache
    ) {        $this->db = $db;
        $this->cache = $cache;

    }

    public function tableExists(string $table): bool
    {
        $table = str_replace('`', '', $table);
        $cacheKey = "schema:table_exists:{$table}";

        $cached = $this->cache->tags([self::TAG])->rememberSeconds($cacheKey, self::TTL, function () use ($table) {
            try {
                $exists = (bool)$this->db->fetchColumn(
                    "SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?",
                    [$table]
                );
                return $exists;
            } catch (\Throwable) {
                return false;
            }
        });
        if (!is_bool($cached)) throw new \UnexpectedValueException('Schema table-exists cache must contain a boolean.');
        return $cached;
    }

    /** @return array<int,string> */
    public function getColumns(string $table): array
    {
        $table = str_replace('`', '', $table);
        $cacheKey = "schema:columns:{$table}";

        $cached = $this->cache->tags([self::TAG])->rememberSeconds($cacheKey, self::TTL, function () use ($table) {
            try {
                $rows = $this->db->fetchAll(
                    "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?",
                    [$table]
                );
                
                $columns = [];
                foreach ($rows as $row) {
                    $values = is_object($row) ? get_object_vars($row) : (is_array($row) ? $row : []);
                    $name = $values['COLUMN_NAME'] ?? $values['Column_name'] ?? null;
                    if (is_string($name) && $name !== '') $columns[] = $name;
                }
                return $columns;
            } catch (\Throwable) {
                return [];
            }
        });
        if (!is_array($cached) || array_filter($cached, 'is_string') !== $cached) {
            throw new \UnexpectedValueException('Schema columns cache must contain a list of strings.');
        }
        return array_values($cached);
    }

    /**
     * فقط ستون‌هایی از $wanted را برمی‌گرداند که واقعاً در جدول موجودند.
     *
     * @param array<int,string> $wanted
     * @return array<int,string>
     */
    public function filterExistingColumns(string $table, array $wanted): array
    {
        $existing = $this->getColumns($table);
        return array_values(array_filter($wanted, static fn(string $c) => in_array($c, $existing, true)));
    }

    /**
     * بررسی اینکه آیا یک FULLTEXT index دقیقاً همه‌ی ستون‌های داده‌شده را پوشش می‌دهد.
     *
     * @param array<int,string> $columns
     */
    /**
     * @var array<string, array<int, string>>
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
        'direct_messages' => ['message']
    ];

    /**
     * بررسی اینکه آیا یک FULLTEXT index دقیقاً همه‌ی ستون‌های داده‌شده را پوشش می‌دهد.
     * با استفاده از تنظیمات استاتیک برای جلوگیری از SHOW INDEX در دیتابیس لایو.
     *
     * @param array<int,string> $columns
     */
    public function hasFullTextIndex(string $table, array $columns): bool
    {
        // L-10 FIX: this method used to `return false` unconditionally, which silently
        // downgraded every module search to `LIKE '%term%'` on large tables (full scan
        // → performance DoS surface). Now the real index layout is introspected once
        // and cached (same TTL/tag policy as tableExists/getColumns), with the static
        // map kept only as an offline fallback when INFORMATION_SCHEMA is unreachable.
        $table = str_replace('`', '', $table);
        $wanted = array_values(array_unique(array_map(
            static fn($c) => strtolower(trim(str_replace('`', '', (string)$c))),
            $columns
        )));

        if ($wanted === []) {
            return false;
        }

        $indexed = $this->getFullTextIndexedColumns($table);
        if ($indexed === []) {
            return false;
        }

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
     * @return array<int,string>
     */
    private function getFullTextIndexedColumns(string $table): array
    {
        $cacheKey = "schema:fulltext_columns:{$table}";

        $cached = $this->cache->tags([self::TAG])->rememberSeconds($cacheKey, self::TTL, function () use ($table) {
            try {
                $rows = $this->db->fetchAll(
                    "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.STATISTICS
                     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_TYPE = 'FULLTEXT'",
                    [$table]
                );

                $columns = [];
                foreach ((array)$rows as $row) {
                    $row = (array)$row;
                    $name = $row['COLUMN_NAME'] ?? $row['Column_name'] ?? null;
                    if (is_scalar($name) && (string)$name !== '') {
                        $columns[] = strtolower((string)$name);
                    }
                }

                if ($columns !== []) {
                    return array_values(array_unique($columns));
                }
            } catch (\Throwable) {
                // introspection unavailable → fall through to the static fallback map
                $fallback = self::STATIC_FULLTEXT_INDEXES[$table] ?? [];
                return array_values(array_unique(array_map('strtolower', $fallback)));
            }

            return [];
        });
        if (!is_array($cached) || array_filter($cached, 'is_string') !== $cached) {
            throw new \UnexpectedValueException('Schema fulltext cache must contain a list of strings.');
        }
        return array_values($cached);
    }

    /**
     * پاک‌سازی cache برای تمام جداول.
     */
    public function flush(): void
    {
        $this->cache->tags([self::TAG])->flush();
    }

    /**
     * گرم کردن کش برای جداول مشخص شده.
     *
     * @param list<string> $tables
     */
    public function warm(array $tables): void
    {
        foreach ($tables as $table) {
            $this->tableExists($table);
            $this->getColumns($table);
        }
    }
}
