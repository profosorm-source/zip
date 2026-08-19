<?php

declare(strict_types=1);

namespace App\Services;

use Core\Database;
use Core\Redis;
use App\Contracts\LoggerInterface;

/**
 * MigrationService
 * مدیر ارکستراسیون ورژن‌های دیتابیس (Schema Migrations)
 */
class MigrationService
{
    /**
     * Centralized toObject (root-cause normalization for DB results).
     * Guarantees object (never array/mixed) before any ->prop access.
     */
    private function toObject(mixed $data): ?object
    {
        if ($data === null || $data === false) return null;
        if (is_object($data)) return $data;
        if (is_array($data)) return (object)$data;
        return (object)(array)$data;
    }



    private string $migrationsDir;


    private \Core\Redis $redis;
    private \Core\Database $db;
    private \App\Contracts\LoggerInterface $logger;
    public function __construct(
        \Core\Redis $redis,
        \Core\Database $db,
        \App\Contracts\LoggerInterface $logger
    ) {        $this->redis = $redis;
        $this->db = $db;
        $this->logger = $logger;

        
        $this->migrationsDir = realpath(__DIR__ . '/../../database/migrations') ?: (__DIR__ . '/../../database/migrations');
    }

    /**
     * @return array<string, mixed>
     */
    public function runMigrations(): array
    {
        $this->initializeSchemaTable();
        
        // 🛡️ Owner-verified Distributed Lock + Fail-Closed DB Advisory Lock Fallback (Findings #2 & #3)
        $lockToken = bin2hex(random_bytes(16));
        $lockAcquired = false;
        $usingDbLock = false;
        $redis = $this->redis;

        if ($redis !== null && $redis->isAvailable()) {
            try {
                $client = $redis->getClient();
                $lock = $client instanceof \Redis
                    ? $client->set('schema_migration_lock', $lockToken, ['nx', 'ex' => 300])
                    : false;
                if ($lock) {
                    $lockAcquired = true;
                }
            } catch (\Throwable $e) {
                $redis = null;
            }
        }

        if (!$lockAcquired) {
            // Fail-closed DB Advisory Lock fallback using MariaDB GET_LOCK()
            try {
                $stmt = $this->db->query("SELECT GET_LOCK('schema_migration_lock', 10) as lock_status");
                $res = $stmt->fetch(\PDO::FETCH_OBJ);
                if (is_object($res) && (int)($res->lock_status ?? 0) === 1) {
                    $lockAcquired = true;
                    $usingDbLock = true;
                }
            } catch (\Throwable $e) {}
        }

        if (!$lockAcquired) {
            return ['success' => false, 'message' => 'Migration is already running or could not acquire migration lock.'];
        }

        try {
            $executedRows = $this->getExecutedMigrations();
            $executedMap = [];
            foreach ($executedRows as $row) {
                $executedMap[$row->migration] = [
                    'batch' => $row->batch,
                    'checksum' => $row->checksum ?? null,
                ];
            }
            $executedList = array_keys($executedMap);

            // Both SQL and PHP migrations are first-class migrations. PHP files
            // are used for seeders and schema helpers and must participate in the
            // same ordering, tracking and failure semantics as SQL migrations.
            $allFiles = array_merge(
                glob($this->migrationsDir . '/*.sql') ?: [],
                glob($this->migrationsDir . '/*.php') ?: []
            );
            natsort($allFiles);
            $allFiles = array_values($allFiles);

            $pending = [];
            foreach ($allFiles as $filePath) {
                $filename = basename($filePath);
                $checksum = hash_file('sha256', $filePath);

                if (in_array($filename, $executedList, true)) {
                    $storedChecksum = $executedMap[$filename]['checksum'];
                    if ($storedChecksum !== null && $storedChecksum !== '' && $storedChecksum !== $checksum) {
                        throw new \RuntimeException("Migration checksum mismatch for '{$filename}'. Stored: {$storedChecksum}, Actual: {$checksum}. Migration file was tampered with after execution.");
                    }
                } else {
                    $pending[] = $filePath;
                }
            }

            if (empty($pending)) {
                return ['success' => true, 'executed' => 0, 'message' => 'Database schema is already up to date.'];
            }

            $batch = $this->getNextMigrationBatch();
            $executedCount = 0;
            $errors = [];

            foreach ($pending as $file) {
                $filename = basename($file);
                $checksum = hash_file('sha256', $file);
                $extension = strtolower(pathinfo($file, PATHINFO_EXTENSION));
                $useTx = false;
                $sql = '';

                if ($extension === 'sql') {
                    $sql = file_get_contents($file);
                    if ($sql === false) {
                        throw new \RuntimeException("Unable to read migration file: {$filename}");
                    }
                    $isDdl = (bool)preg_match('/\b(ALTER|CREATE|DROP|RENAME|TRUNCATE)\b/i', $sql);
                    $useTx = !$isDdl;
                }

                if ($useTx) {
                    $this->db->beginTransaction();
                }

                try {
                    if ($extension === 'php') {
                        // PHP migrations are intentionally executed in-process so
                        // they share the same configured database connection and
                        // migration lifecycle. Output is suppressed because a
                        // migration must not corrupt CLI/HTTP response streams.
                        ob_start();
                        try {
                            $migration = require $file;
                            if (is_object($migration) && method_exists($migration, 'up')) {
                                $migration->up($this->db);
                            }
                        } finally {
                            ob_end_clean();
                        }
                    } else {
                    $statements = self::splitSqlStatements($sql);
                    $stmtErrors = [];
                    $lastWasDdl = false;
                    foreach ($statements as $stmt) {
                        $trimmed = trim((string)$stmt);
                        if ($trimmed === '') {
                            continue;
                        }
                        $codeOnly = preg_replace('!--[^\n]*|/\*.*?\*/|^\s*#[^\n]*!ms', '', $trimmed);
                        if (trim((string)$codeOnly) === '') {
                            continue;
                        }
                        if ($lastWasDdl) {
                            try { $this->db->forceReconnect(); } catch (\Throwable) {}
                        }

                        // 🛡️ Production Safety Guard: prevent dropping populated tables on non-fresh migrations
                        if (preg_match('/^\s*DROP\s+TABLE\s+(?:IF\s+EXISTS\s+)?[`"]?([a-zA-Z0-9_]+)[`"]?/i', $trimmed, $dropMatches)) {
                            $targetTable = $dropMatches[1];
                            $isTempTable = (bool)preg_match('/(temp|bak|tmp)$/i', $targetTable);
                            if (!$isTempTable) {
                                $appEnv = strtolower(str_value(config('app.env', 'local')));
                                $allowDestructive = filter_var(getenv('ALLOW_DESTRUCTIVE_MIGRATIONS'), FILTER_VALIDATE_BOOLEAN);
                                $isFreshFlag = in_array('--fresh', $_SERVER['argv'] ?? [], true) || in_array('--force-fresh', $_SERVER['argv'] ?? [], true);
                                
                                if (($appEnv === 'production' || !$isFreshFlag) && !$allowDestructive) {
                                    try {
                                        // Do not probe an absent table directly. Core\Database logs every
                                        // failed query before it can be caught here, which produced hundreds
                                        // of false ERROR events during a healthy fresh installation.
                                        $tableExists = (int) ($this->db->fetchColumn(
                                            'SELECT COUNT(*) FROM information_schema.tables'
                                            . ' WHERE table_schema = DATABASE() AND table_name = ?',
                                            [$targetTable]
                                        ) ?? 0) > 0;

                                        if ($tableExists) {
                                            $rowCount = (int) ($this->db->fetchColumn(
                                                "SELECT COUNT(*) FROM `{$targetTable}`"
                                            ) ?? 0);
                                            if ($rowCount > 0) {
                                                $this->logger->warning('database.migration.destructive_drop_skipped', [
                                                    'migration' => $filename,
                                                    'table' => $targetTable,
                                                    'row_count' => $rowCount,
                                                    'reason' => 'Destructive DROP TABLE skipped on populated table to protect data'
                                                ]);
                                                continue;
                                            }
                                        }
                                    } catch (\Throwable $ignore) {
                                        // Safety inspection must not prevent an idempotent DROP IF EXISTS.
                                    }
                                }
                            }
                        }

                        try {
                            $this->db->getPdo()->exec($trimmed);
                            $lastWasDdl = (bool) preg_match(
                                '/^\s*(ALTER|CREATE|DROP|RENAME|TRUNCATE)\b/i', $trimmed);
                        } catch (\PDOException $stmtEx) {
                            if (self::isIdempotentSqlError($stmtEx->getMessage())) {
                                $this->logger->info('database.migration.statement.skipped_idempotent', [
                                    'migration' => $filename,
                                    'reason'    => $stmtEx->getMessage(),
                                ]);
                                continue;
                            }
                            $stmtErrors[] = $stmtEx->getMessage();
                            throw $stmtEx;
                        }
                    }
                    }

                    $this->db->query(
                        "INSERT INTO schema_migrations (migration, batch, checksum) VALUES (?, ?, ?)",
                        [$filename, $batch, $checksum]
                    );

                    if ($useTx && $this->db->inTransaction()) {
                        $this->db->commit();
                    }
                    $executedCount++;
                    $this->logger->info('database.migration.executed', ['migration' => $filename]);
                } catch (\Throwable $e) {
                    if ($useTx && $this->db->inTransaction()) {
                        $this->db->rollback();
                    }
                    $errors[] = "Failed executing {$filename}: " . $e->getMessage();
                    $this->logger->critical('database.migration.failed', ['migration' => $filename, 'error' => $e->getMessage()]);
                    break;
                }
            }

            return [
                'success' => empty($errors),
                'executed' => $executedCount,
                'batch' => $batch,
                'errors' => $errors,
                'message' => "Executed {$executedCount} migrations."
            ];
        } finally {
            if ($usingDbLock) {
                try {
                    $this->db->query("SELECT RELEASE_LOCK('schema_migration_lock')");
                } catch (\Throwable $e) {}
            } elseif ($redis !== null && $redis->isAvailable()) {
                try {
                    $script = 'if redis.call("get", KEYS[1]) == ARGV[1] then return redis.call("del", KEYS[1]) else return 0 end';
                    $client = $redis->getClient();
                    if ($client instanceof \Redis) {
                        $client->eval($script, ['schema_migration_lock', $lockToken], 1);
                    }
                } catch (\Throwable $e) {}
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rollbackMigrations(int $steps = 1): array
    {
        return [
            'success' => false,
            'message' => 'Rollback is partially supported for SQL files. Full PHP-class support required.'
        ];
    }

    private function initializeSchemaTable(): void
    {
        $this->db->query(
            "CREATE TABLE IF NOT EXISTS schema_migrations (
                id INT AUTO_INCREMENT PRIMARY KEY,
                migration VARCHAR(255) UNIQUE NOT NULL,
                batch INT NOT NULL,
                checksum VARCHAR(64) NULL,
                executed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )"
        );
    }

    /**
     * @return list<\stdClass>
     */
    private function getExecutedMigrations(): array
    {
        return (array)$this->db->fetchAll("SELECT migration, batch, checksum, executed_at FROM schema_migrations ORDER BY batch, id");
    }

    /**
     * BUGFIX-MIGRATION-MULTI-STMT-2026-06 — idempotency classifier.
     *
     * Returns true for MySQL/MariaDB error messages that we know are safe
     * to ignore when re-applying an idempotent migration. The migrations
     * shipped with this project rely heavily on `ADD COLUMN IF NOT EXISTS`,
     * `CREATE INDEX IF NOT EXISTS`, `CREATE TABLE IF NOT EXISTS`, and
     * `INSERT ... ON DUPLICATE KEY UPDATE`. Older MariaDB versions still
     * emit specific error numbers when the target object already exists
     * (1050, 1060, 1061, ...), so we filter on those.
     *
     * We deliberately do NOT include syntax errors (1064), permission
     * errors, or constraint violations on real data — those must always
     * abort the migration.
     */
    public static function isIdempotentSqlError(string $message): bool
    {
        $idempotentErrorCodes = [
            '1050', // Table 'x' already exists
            '1060', // Duplicate column name
            '1061', // Duplicate key name
            '1091', // Can't DROP — check that column/key exists
            '1826', // Duplicate foreign key constraint name
        ];
        foreach ($idempotentErrorCodes as $code) {
            if (str_contains($message, $code . ' ')) {
                return true;
            }
        }
        return false;
    }

    /**
     * BUGFIX-MIGRATION-MULTI-STMT-2026-06:
     * Split a SQL script into individual statements on ';' boundaries while
     * respecting:
     *   - string literals ('...' and "...")
     *   - single-line comments (-- ... \n  and  # ... \n)
     *   - block comments (/* ... *\/)
     *   - escape sequences inside string literals (\' \" \\)
     *
     * This is intentionally lightweight: it does NOT attempt to parse
     * stored-procedure DELIMITER blocks. Our migration policy disallows
     * them (see 2026_06_14_0020_currency_normalize_to_irt.sql for an
     * example of how to avoid DELIMITER in favour of guarded UPDATEs).
     *
     * @param  string $sql full migration file contents
     * @return string[] individual SQL statements (semicolons stripped)
     */
    public static function splitSqlStatements(string $sql): array
    {
        $statements = [];
        $buf = '';
        $len = strlen((string)$sql);
        $i = 0;
        $inString = null; // null | '\'' | '"'
        $inLineComment = false;
        $inBlockComment = false;

        while ($i < $len) {
            $c  = $sql[$i];
            $c2 = $i + 1 < $len ? $sql[$i + 1] : '';

            if ($inLineComment) {
                $buf .= $c;
                if ($c === "\n") $inLineComment = false;
                $i++; continue;
            }
            if ($inBlockComment) {
                $buf .= $c;
                if ($c === '*' && $c2 === '/') { $buf .= $c2; $i += 2; $inBlockComment = false; continue; }
                $i++; continue;
            }
            if ($inString !== null) {
                $buf .= $c;
                if ($c === '\\' && $c2 !== '') { $buf .= $c2; $i += 2; continue; }
                if ($c === $inString) $inString = null;
                $i++; continue;
            }
            // Not inside string/comment
            if ($c === '-' && $c2 === '-') { $buf .= $c . $c2; $i += 2; $inLineComment = true; continue; }
            if ($c === '#')                 { $buf .= $c;       $i++;    $inLineComment = true; continue; }
            if ($c === '/' && $c2 === '*') { $buf .= $c . $c2; $i += 2; $inBlockComment = true; continue; }
            if ($c === "'" || $c === '"')  { $buf .= $c; $inString = $c; $i++; continue; }
            if ($c === ';') {
                $statements[] = $buf;
                $buf = '';
                $i++;
                continue;
            }
            $buf .= $c;
            $i++;
        }
        if (trim((string)$buf) !== '') {
            $statements[] = $buf;
        }
        return $statements;
    }

    private function getNextMigrationBatch(): int
    {
        $result = $this->toObject($this->db->fetch("SELECT COALESCE(MAX(batch), 0) as max_batch FROM schema_migrations"));
        return ((int)($result?->max_batch ?? 0)) + 1;
    }
}
