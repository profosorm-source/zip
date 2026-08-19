<?php

declare(strict_types=1);

namespace Core;

use \PDO;
use \PDOException;
/**
 * Database Connection (Singleton)
 * 
 * مدیریت اتصال به دیتابیس با PDO
 */
/**
 * @phpstan-type DbConfig array{host: string, port: int|string, name: string, charset: string, user: string, pass: string, read?: array<string, mixed>}
 * @phpstan-type SqlParameters array<int|string, mixed>
 * @phpstan-type DiagnosticContext array<string, mixed>
 * @phpstan-type TraceFrame array{file?: string, line?: int, function?: string, class?: string, type?: string}
 */
class Database
{
    private static ?self $instance = null;
    private ?\PDO $pdo = null;
    private ?\PDO $pdoRead = null;
	private static int $queryDepth = 0;
    /** @var array<string, mixed>|null */
	private static ?array $lastSqlErrorContext = null;
    private ?\App\Contracts\DatabaseErrorReporter $errorReporter = null;
    private int $transactionLevel = 0; // H24 Fix: شمارنده پشته تراکنش‌ها جهت جلوگیری از Partial Commit در معماری تودرتو
    // M-37 FIX (read-your-writes): once ANY write happens on this request-scoped connection,
    // subsequent non-transactional SELECTs are pinned to the primary for the rest of the
    // request. Without this, a read issued right after a committed write could be routed to a
    // lagging read-replica and silently observe stale/missing data (a freshly created row, an
    // updated balance, or a just-changed status). Mirrors the sticky-connection strategy.
    private bool $stickyPrimaryAfterWrite = false;
    private bool $isRollbackOnly = false; // Prevents silent commit of corrupted nested transactions

    // ─────────────────────────────────────────────────────────────────────────
    // BUGFIX-RECURSION-2026-06: Defense against error-handler self-recursion.
    //
    // Root cause: When a query against settings storage fails, the chain
    //   reportQueryError → error reporter → alert dispatcher → app settings
    //   → settings model → DB::query("SELECT * FROM <settings table> ...")
    // re-enters the very query that just failed, creating an infinite loop
    // that only the PHP max_execution_time can stop (CPU + log amplification).
    //
    // The previous `$queryDepth > 100` check was a generic recursion limit
    // that triggered far too late and never short-circuited the reporting path.
    //
    // This guard is per-request (per-process) and process-local. It blocks
    // RE-ENTRY into reportQueryError() while a previous report is still on
    // the call stack. Failures inside the report path are funneled directly
    // to the file-based fallback log (never back to the DB).
    // ─────────────────────────────────────────────────────────────────────────
    private static bool $inErrorReporting = false;
    private static ?string $errorReportingForSql = null;

    // 🛡️ N+1 Detection Wiring (Phase 1+2): counters و flags برای کنترل و observability
    // - در حالت sample rate، برای تصمیم‌گیری deterministic نیاز به counter داریم
    // - counters در طول حیات process نگه داشته می‌شوند (reset نمی‌شوند)
    private static bool $inQueryTracking = false;
    private static ?bool $queryTrackingEnabled = null; // lazy init از env/config
    private static int $trackedQueriesCount = 0;
    private static int $skippedQueriesCount = 0;
    private static int $trackingErrorsCount = 0;
    private static ?float $sampleRate = null; // lazy init

    private int $lastPingTime = 0;
    private const PING_INTERVAL = 60; // 60 seconds
    /** @var DbConfig */
    private array $config;

    /**
     * Constructor (Private)
     * M4 Fix: رفع منقضی شدن PHP 8.1+ با تبدیل به تایپ نال‌پذیر
     */
    /** @param DbConfig|null $dbConfig */
    private function __construct(?array $dbConfig = null)
    {
        $this->config = $this->normalizeDbConfig($dbConfig ?? config('database'));
    }

    /**
     * Database config originates outside this class. Validate it once, before
     * connection construction, so every later PDO call has a concrete contract.
     *
     * @return DbConfig
     */
    private function normalizeDbConfig(mixed $config): array
    {
        if (!is_array($config)) {
            throw new \RuntimeException('Database configuration must be an array');
        }

        foreach (['host', 'port', 'name', 'charset', 'user', 'pass'] as $key) {
            if (!array_key_exists($key, $config) || !is_scalar($config[$key])) {
                throw new \RuntimeException("Database configuration key {$key} is missing or invalid");
            }
        }
        if (isset($config['read']) && !is_array($config['read'])) {
            throw new \RuntimeException('Database read-replica configuration must be an array');
        }

        $config['host'] = (string)$config['host'];
        $config['port'] = is_int($config['port']) ? $config['port'] : (string)$config['port'];
        $config['name'] = (string)$config['name'];
        $config['charset'] = (string)$config['charset'];
        $config['user'] = (string)$config['user'];
        $config['pass'] = (string)$config['pass'];

        /** @var DbConfig $config */
        return $config;
    }

    private function primaryPdo(): \PDO
    {
        if ($this->pdo === null) {
            throw new \RuntimeException('Primary PDO connection is not initialized');
        }
        return $this->pdo;
    }

    private function connectionForQuery(bool $isRead): \PDO
    {
        $primary = $this->primaryPdo();
        // M-37: force primary for writes, inside transactions, and for reads that follow a
        // write in the same request (read-your-writes consistency).
        if (!$isRead || $this->inTransaction() || $this->stickyPrimaryAfterWrite) {
            return $primary;
        }
        return $this->pdoRead ?? $primary;
    }

    /**
     * Force-rebuild the PDO connection.
     * Public alias of reconnect() — used by MigrationService after DDL to
     * clear PDO's per-connection schema metadata cache (BUGFIX-MIGRATION-
     * DDL-METADATA-2026-06: see app/Services/MigrationService.php).
     */
    public function forceReconnect(): void
    {
        $this->reconnect();
    }

    private function reconnect(): void
    {
        $this->pdo = $this->createPdoConnection($this->config);
        
        // Setup Read Replica if configured (supports array of hosts for load balancing)
        if (isset($this->config['read']) && $this->config['read'] !== []) {
            $readConfig = array_merge($this->config, $this->config['read']);
            $readHost = $readConfig['host'] ?? null;
            if (is_array($readHost)) {
                $hosts = array_values(array_filter($readHost, 'is_string'));
                if ($hosts === []) {
                    throw new \RuntimeException('Database read-replica host list is empty or invalid');
                }
                $readConfig['host'] = $hosts[array_rand($hosts)];
            }
            $this->pdoRead = $this->createPdoConnection($this->normalizeDbConfig($readConfig));
        } else {
            $this->pdoRead = $this->pdo; // Fallback to master
        }

        $this->syncTimezoneFromDb();
    }

    private function syncTimezoneFromDb(): void
    {
        $pdo = $this->primaryPdo();
        try {
            $stmt = $pdo->prepare("SELECT value FROM system_settings WHERE `key` = 'site_timezone' LIMIT 1");
            $stmt->execute();
            $row = $stmt->fetch(\PDO::FETCH_OBJ);
            $settingValue = $row instanceof \stdClass ? ($row->value ?? null) : null;
            $timezone = is_string($settingValue) ? trim($settingValue) : '';
            if ($timezone === '') {
                $configuredTimezone = config('app.timezone', 'Asia/Tehran');
                $timezone = is_string($configuredTimezone) && $configuredTimezone !== ''
                    ? $configuredTimezone
                    : 'Asia/Tehran';
            }

            date_default_timezone_set($timezone);

            $tz = new \DateTimeZone($timezone);
            $offset = $tz->getOffset(new \DateTime());
            $hours = intval($offset / 3600);
            $minutes = abs(intval(($offset % 3600) / 60));
            $sign = $hours >= 0 ? '+' : '-';
            $formattedOffset = $sign . sprintf("%02d:%02d", abs($hours), $minutes);
            
            $pdo->exec("SET time_zone = '{$formattedOffset}'");
            if ($this->pdoRead !== null && $this->pdoRead !== $pdo) {
                $this->pdoRead->exec("SET time_zone = '{$formattedOffset}'");
            }
        } catch (\Throwable $e) {
            try {
                $this->primaryPdo()->exec("SET time_zone = '+03:30'");
                if ($this->pdoRead !== null && $this->pdoRead !== $pdo) {
                    $this->pdoRead->exec("SET time_zone = '+03:30'");
                }
            } catch (\Throwable $ex) {}
        }
    }

    /** @param DbConfig $config */
    private function createPdoConnection(array $config): \PDO
    {
        $dsn = "mysql:host={$config['host']};port={$config['port']};dbname={$config['name']};charset={$config['charset']};connect_timeout=2";
        
        // ✅ Persistent connection فقط در FPM/Web — نه CLI/Worker
        // CLI (queue worker، scheduler) نباید persistent باشد چون:
        //   - worker ها طولانی‌مدت هستند و connection دچار stale می‌شود
        //   - در FPM هر worker process یک connection persistent نگه می‌دارد
        $isPersistent = (PHP_SAPI !== 'cli');

        $options = [
            \PDO::ATTR_ERRMODE            => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_OBJ,     // ✅ Object به جای Array
            \PDO::ATTR_EMULATE_PREPARES   => false,
            \PDO::ATTR_TIMEOUT            => 2,                   // ✅ Strict timeout (SQLi DoS protection)
            \PDO::ATTR_PERSISTENT         => $isPersistent,       // ✅ Connection reuse در FPM
        ];

        if (defined('\PDO::MYSQL_ATTR_INIT_COMMAND')) {
            $options[\PDO::MYSQL_ATTR_INIT_COMMAND] = "SET NAMES {$config['charset']} COLLATE utf8mb4_unicode_ci";
        }
        if (defined('\PDO::MYSQL_ATTR_READ_TIMEOUT')) {
            $options[\PDO::MYSQL_ATTR_READ_TIMEOUT] = 3; // ✅ Query Timeout Manager (Read)
        }
        if (defined('\PDO::MYSQL_ATTR_WRITE_TIMEOUT')) {
            $options[\PDO::MYSQL_ATTR_WRITE_TIMEOUT] = 3; // ✅ Query Timeout Manager (Write)
        }
        
        $pdo = new \PDO($dsn, $config['user'], $config['pass'], $options);
        try {
            $pdo->exec("SET time_zone = '+03:30'");
        } catch (\Throwable $e) {}
        return $pdo;
    }

    public function ensureConnected(): void
    {
        if ($this->pdo === null || time() - $this->lastPingTime > self::PING_INTERVAL) {
            try {
                if ($this->pdo) {
                    $this->pdo->query('SELECT 1');
                } else {
                    $this->reconnect();
                }
                $this->lastPingTime = time();
            } catch (\PDOException $e) {
                try {
                    $this->reconnect();
                    $this->lastPingTime = time();
                } catch (\Throwable $ex) {
                    throw new \RuntimeException("Database reconnection failed: " . $ex->getMessage(), (int)$ex->getCode(), $ex);
                }
            }
        }
    }

    public function setErrorReporter(\App\Contracts\DatabaseErrorReporter $reporter): void
    {
        $this->errorReporter = $reporter;
    }


private function normalizeSql(string $sql): string
{
    $sql = preg_replace('/\s+/', ' ', (string)$sql);
    return trim((string)$sql);
}

/**
 * @param SqlParameters $params
 * @return DiagnosticContext
 */
private function buildSqlErrorContext(string $sql, array $params, \Throwable $e): array
{
    $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 25);

    $originFile = null;
    $originLine = null;
    $stack = [];

    foreach ($trace as $t) {
        $cls = $t['class'] ?? null;
        $fn  = $t['function'];
        if ($fn) {
            $stack[] = ($cls ? $cls . '->' : '') . $fn . '()';
        }

        $file = $t['file'] ?? null;
        if ($file) {
            $normalized = str_replace('\\', '/', $file);
            if (!str_contains($normalized, '/core/Database.php') && $originFile === null) {
                $originFile = $file;
                $originLine = $t['line'] ?? null;
            }
        }
    }

    $unknownColumn = null;
    if (preg_match("/Unknown column '([^']+)'/i", $e->getMessage(), $m)) {
        $unknownColumn = $m[1];
    }

    $tables = [];
    $patterns = [
        '/\bfrom\s+([`a-zA-Z0-9_\.]+)/i',
        '/\bjoin\s+([`a-zA-Z0-9_\.]+)/i',
        '/\bupdate\s+([`a-zA-Z0-9_\.]+)/i',
        '/\binsert\s+into\s+([`a-zA-Z0-9_\.]+)/i',
        '/\bdelete\s+from\s+([`a-zA-Z0-9_\.]+)/i',
    ];
    foreach ($patterns as $p) {
        if (preg_match_all($p, $sql, $mm)) {
            foreach ($mm[1] as $t) {
                $tables[] = trim($t, '`');
            }
        }
    }

    $context = [
        'error' => $e->getMessage(),
        'error_type' => get_class($e),
        'request_id' => $_SERVER['HTTP_X_REQUEST_ID'] ?? null,
        'uri' => $_SERVER['REQUEST_URI'] ?? null,
    ];

    if (config('app.env') !== 'production') {
        $context['sql'] = mb_substr($sql, 0, 1500);
        $context['params_count'] = count($params);
        $context['file'] = $originFile;
        $context['line'] = $originLine;
        $context['stack'] = array_slice($stack, 0, 10);
        $context['tables'] = array_values(array_unique($tables));
        $context['unknown_column'] = $unknownColumn;
        $context['method'] = $_SERVER['REQUEST_METHOD'] ?? null;
        $context['ip'] = $_SERVER['REMOTE_ADDR'] ?? null;
        $context['user_id'] = function_exists('user_id') ? (user_id() ?: null) : null;
    } else {
        $context['sql_hash'] = hash('sha256', $sql);
        $context['error_code'] = $e->getCode();
    }

    return $context;
}

/** @param DiagnosticContext $context */
private static function fallbackLog(string $event, array $context = []): void
{
    static $inProgress = [];
    $key = md5($event . ':' . json_encode($context));
    if (isset($inProgress[$key])) {
        return;
    }

    $inProgress[$key] = true;
    try {
        $loggerHandled = false;
        try {
            if (function_exists('logger')) {
                $logger = logger();
                if (method_exists($logger, 'error')) {
                    $logger->error($event, $context);
                    $loggerHandled = true;
                }
            }
        } catch (\Throwable $ignore) {}

        // 🛡️ LOGGING ARCHITECTURE FIX: پرهیز از نوشتن زائد روی دیسک (Disk I/O Optimization)
        // لاگ متنی فایل تنها در صورتی نوشته می‌شود که لاگر اصلی پروژه در دسترس نباشد
        if (!$loggerHandled) {
            $payload = [
                'timestamp' => date('c'),
                'event'     => $event,
                'context'   => $context,
            ];
            @file_put_contents(
                __DIR__ . '/../storage/logs/_db_fallback.log',
                json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL,
                FILE_APPEND | LOCK_EX
            );
        }
    } finally {
        unset($inProgress[$key]);
    }
}
/** @return DiagnosticContext|null */
public static function getLastSqlErrorContext(): ?array
{
    return self::$lastSqlErrorContext;
}

/** @param DiagnosticContext $context */
private static function recordSqlFailure(string $event, array $context): void
{
    self::$lastSqlErrorContext = $context;
    self::fallbackLog($event, $context);
}

    /**
     * دریافت Instance (Singleton)
     *
     * Singleton پس از ایجاد تغییر نمی‌کند. اگر نیاز به config متفاوت دارید،
     * ابتدا reset() کنید (فقط CLI/testing) و سپس دوباره getInstance بزنید.
     */
    /** @param DbConfig|null $dbConfig */
    public static function getInstance(?array $dbConfig = null): self
    {
        if (self::$instance === null) {
            self::$instance = new self($dbConfig);
        }

        return self::$instance;
    }

    /**
     * ریست کردن اتصال پایگاه داده
     * فقط برای استفاده در محیط CLI/testing مجاز است
     */
    public static function reset(): void
    {
        if (PHP_SAPI !== 'cli') {
            throw new \RuntimeException('Database::reset() is only allowed in CLI/testing');
        }
        self::$instance = null;
    }
	
public function prepare(string $sql): \PDOStatement
{
    $this->ensureConnected();
    try {
        $isRead = stripos(ltrim((string)$sql), 'SELECT') === 0;
        $useMaster = !$isRead || $this->inTransaction();
        $pdo = $this->connectionForQuery($isRead);
        return $pdo->prepare($sql);
    } catch (\Throwable $e) {
        self::recordSqlFailure('database.prepare.failed', $this->buildSqlErrorContext($sql, [], $e));
        throw $e;
    }
}

    /**
     * دریافت PDO
     */
    public function getPdo(): \PDO
    {
        $this->ensureConnected();
        return $this->primaryPdo();
    }

    /**
     * دریافت Query Builder
     */
    public function table(string $table): QueryBuilder
    {
        $this->ensureConnected();
        return (new QueryBuilder($this->primaryPdo()))->table($table);
    }
	
	
	
/**
 * @param SqlParameters $params
 * @return ?\stdClass
 */
public function fetch(string $sql, array $params = []): ?\stdClass
{
    $stmt = $this->executeStatement($sql, $params, 'database.fetch.failed', true);
    $row = $stmt->fetch(\PDO::FETCH_OBJ);
    return $row instanceof \stdClass ? $row : null;
}

/**
 * @param SqlParameters $params
 * @return list<\stdClass>
 */
public function fetchAll(string $sql, array $params = []): array
{
    $stmt = $this->executeStatement($sql, $params, 'database.fetchAll.failed', true);
    /** @var list<\stdClass> $rows */
    $rows = $stmt->fetchAll(\PDO::FETCH_OBJ) ?: [];
    return $rows;
}

/**
 * @param SqlParameters $params
 * @return string|int|float|bool|null
 */
public function fetchColumn(string $sql, array $params = [], int $column = 0)
{
    $stmt = $this->executeStatement($sql, $params, 'database.fetchColumn.failed', true);
    return $stmt->fetchColumn($column);
}

    /**
     * اجرای مرکزی دستورات دیتابیس با مدیریت هوشمند ریکرژن، لاگ و استثناها
     */
    /** @param SqlParameters $params */
    private function executeStatement(string $sql, array $params, string $failureEvent, bool $isRead = false): \PDOStatement
    {
        $this->ensureConnected();

        self::$queryDepth++;
        if (self::$queryDepth > 100) {
            self::$queryDepth--;
            throw new \RuntimeException('Database recursion guard triggered');
        }

        $sql = $this->normalizeSql($sql);
        $startTime = microtime(true);

        try {
            // M-37: any non-read statement marks the connection sticky-to-primary for reads.
            if (!$isRead) {
                $this->stickyPrimaryAfterWrite = true;
            }
            $useMaster = !$isRead || $this->inTransaction();
            $pdo = $this->connectionForQuery($isRead);

            $stmt = $pdo->prepare($sql);

            foreach ($params as $key => $value) {
                $param = is_int($key) ? $key + 1 : ':' . ltrim((string)$key, ':');
                $value = $this->normalizeParameterValue($value);

                $type = \PDO::PARAM_STR;
                if (is_int($value))        $type = \PDO::PARAM_INT;
                elseif (is_bool($value))   $type = \PDO::PARAM_BOOL;
                elseif ($value === null)   $type = \PDO::PARAM_NULL;

                $stmt->bindValue($param, $value, $type);
            }

            $stmt->execute();

            // مانیتورینگ کوئری‌های کند
            $duration = microtime(true) - $startTime;
            if ($duration > 0.1) {
                $this->logSlowQuery($sql, $params, $duration);
            }

            // 🛡️ N+1 Detection Wiring (Phase 1+2): ارسال query به Sentry Performance Monitor
            self::trackQueryToSentry($sql, $duration, $params);

            return $stmt;
        } catch (\PDOException $e) {
            // Check if connection was lost and queryDepth is low to retry safely
            $message = $e->getMessage();
            $lostConnection = false;
            // Deadlocks do not indicate a broken connection. Reconnecting here
            // destroys transaction state and hides SQLSTATE 40001/driver 1213
            // from the transaction retry layer.
            $lostKeywords = ['gone away', 'lost connection', 'refused', 'packets out of order'];
            foreach ($lostKeywords as $kw) {
                if (stripos($message, $kw) !== false) {
                    $lostConnection = true;
                    break;
                }
            }

            // Never reconnect/retry inside an active transaction. Reconnecting
            // destroys the server-side transaction and retrying the statement
            // would autocommit it on a fresh connection, creating a partial
            // commit. The transaction boundary must observe the failure and
            // fail closed; only non-transactional statements may reconnect.
            if ($lostConnection && self::$queryDepth <= 1 && $this->transactionLevel === 0) {
                try {
                    $this->reconnect();
                    $this->lastPingTime = time();
                    
                    // Retry once
                    $useMaster = !$isRead || $this->inTransaction();
                    $pdo = $this->connectionForQuery($isRead);

                    $stmt = $pdo->prepare($sql);
                    foreach ((array)$params as $key => $value) {
                        $param = is_int($key) ? $key + 1 : ':' . ltrim((string)$key, ':');
                        $type = \PDO::PARAM_STR;
                        if (is_int($value))        $type = \PDO::PARAM_INT;
                        elseif (is_bool($value))   $type = \PDO::PARAM_BOOL;
                        elseif ($value === null)   $type = \PDO::PARAM_NULL;
                        $stmt->bindValue($param, $value, $type);
                    }
                    $stmt->execute();
                    return $stmt;
                } catch (\Throwable $retryEx) {
                    // Fall through to regular logging & exception
                }
            }

            $ctx = $this->buildSqlErrorContext($sql, $params, $e);
            self::$lastSqlErrorContext = $ctx;
            self::fallbackLog($failureEvent, $ctx);

            // ارسال خودکار تمام خطاهای دیتابیسی (از کوئری، فچ و غیره) به سیستم مانیتورینگ
            $this->reportQueryError($sql, $params, $e);

            // If unique constraint violation occurs during HTTP request handling, translate it to a user-friendly ValidationException
            if (PHP_SAPI !== 'cli' && ((string)$e->getCode() === '23000' || str_contains($e->getMessage(), 'Duplicate entry') || str_contains($e->getMessage(), '1062'))) {
                $fieldName = 'record';
                $message = $e->getMessage();
                
                if (preg_match("/key '.*?_([a-zA-Z0-9_]+)_unique'/i", $message, $matches)) {
                    $fieldName = $matches[1];
                } elseif (preg_match("/key '.*?\.(.*?)'/i", $message, $matches)) {
                    $fieldName = $matches[1];
                } elseif (preg_match("/for key '([^']+)'/i", $message, $matches)) {
                    $keyName = $matches[1];
                    $parts = explode('_', $keyName);
                    if (count($parts) > 1) {
                        $fieldName = $parts[count($parts) - 2];
                    } else {
                        $fieldName = $keyName;
                    }
                }
                
                $friendlyFieldNames = [
                    'email' => 'ایمیل',
                    'username' => 'نام کاربری',
                    'mobile' => 'شماره موبایل',
                    'phone' => 'شماره تلفن',
                    'card_number' => 'شماره کارت',
                    'national_code' => 'کد ملی',
                    'slug' => 'شناسه یکتا',
                    'name' => 'نام',
                    'key' => 'کلید همزمانی',
                ];
                
                $friendlyName = $friendlyFieldNames[$fieldName] ?? 'این مقدار';
                $errorMessage = "{$friendlyName} قبلاً در سیستم ثبت شده است و نمی‌تواند تکراری باشد.";
                
                throw new \Core\Exceptions\ValidationException(
                    [$fieldName => [$errorMessage]],
                    "ثبت داده‌های تکراری در سیستم امکان‌پذیر نیست."
                );
            }

            throw $e;
        } finally {
            self::$queryDepth--;
        }
    }

    /**
     * اجرای Query مستقیم
     */
    /** @param SqlParameters $params */
    public function query(string $sql, array $params = []): \PDOStatement
    {
        $isRead = stripos(ltrim((string)$sql), 'SELECT') === 0;
        return $this->executeStatement($sql, $params, 'database.query.failed', $isRead);
    }

private function normalizeParameterValue(mixed $value): string|int|float|bool|null
{
    if (is_string($value) || is_int($value) || is_float($value) || is_bool($value) || $value === null) {
        return $value;
    }
    if ($value instanceof \Stringable) {
        return (string)$value;
    }

    throw new \InvalidArgumentException('SQL parameters must be scalar, null, or Stringable');
}

private function formatParamValue(mixed $value): string
{
    $value = $this->normalizeParameterValue($value);
    if ($value === null) return 'NULL';
    if (is_bool($value)) return $value ? '1' : '0';
    if (is_int($value) || is_float($value)) return (string)$value;
    return $this->primaryPdo()->quote($value);
}

/** @param SqlParameters $params */
private function interpolateSql(string $sql, array $params): string
{
    if (!$params) {
        return $sql;
    }

    // ── پالایش اطلاعات حساس برای جلوگیری از نشت در لاگ‌ها ──────────────────
    $sensitiveKeys = ['pass', 'password', 'token', 'key', 'card', 'iban', 'national_id', 'secret', 'cvv', 'auth'];
    $redactedParams = [];
    foreach ((array)$params as $key => $val) {
        $isSensitive = false;
        if (!is_numeric($key)) {
            $stringKey = strtolower((string)$key);
            foreach ($sensitiveKeys as $sensitiveKey) {
                if (str_contains($stringKey, $sensitiveKey)) {
                    $isSensitive = true;
                    break;
                }
            }
        }
        $redactedParams[$key] = $isSensitive ? '***[REDACTED]***' : $val;
    }

    $isPositional = array_keys($redactedParams) === range(0, count($redactedParams) - 1);

    if ($isPositional) {
        foreach ($redactedParams as $value) {
            $sql = (string) preg_replace('/\?/', $this->formatParamValue($value), (string)$sql, 1);
        }
    } else {
        foreach ((array)$redactedParams as $key => $value) {
            $name = ltrim((string)$key, ':');
            $sql = (string) preg_replace('/:' . preg_quote($name, '/') . '\b/', $this->formatParamValue($value), $sql);
        }
    }

    // پسا-پالایش: فیلتر جفت‌های کلید/مقدار در عبارات SQL (مانند شرط‌های UPDATE/WHERE)
    $sensitiveKeywords = 'pass|password|token|key|card|iban|national_id|secret|cvv|auth';
    $pattern = '/\b(' . $sensitiveKeywords . ')\b\s*=\s*(\'[^\']*\'|"[^"]*"|\d+)/i';
    $sql = (string) preg_replace($pattern, '$1 = \'***[REDACTED]***\'', (string)$sql);

    return $sql;
}




/**
 * ✅ متد جدید برای دریافت نتایج
 */
/**
 * @param SqlParameters $params
 * @return list<\stdClass>
 */
public function select(string $sql, array $params = []): array
{
    $stmt = $this->query($sql, $params);
    return $stmt->fetchAll(\PDO::FETCH_OBJ);
}

    /**
     * SELECT یک رکورد
     */
    /**
     * @param SqlParameters $params
     * @return \stdClass|null
     */
    public function selectOne(string $sql, array $params = []): ?\stdClass
    {
        $stmt = $this->query($sql, $params);
        $result = $stmt->fetch(\PDO::FETCH_OBJ);
        return $result instanceof \stdClass ? $result : null;
    }
/**
 * دریافت آخرین ID درج شده
 */
public function lastInsertId(): int
{
    $this->ensureConnected();
    return (int)$this->primaryPdo()->lastInsertId();
}
    /**
     * INSERT
     */
    /** @param SqlParameters $params */
    public function insert(string $sql, array $params = []): int
    {
        $this->query($sql, $params);
        return (int)$this->primaryPdo()->lastInsertId();
    }

    /** @alias execute() */
    /** @param SqlParameters $params */
    public function exec(string $sql, array $params = []): int
    {
        return (int)$this->execute($sql, $params);
    }

    /**
     * UPDATE/DELETE
     */
    /** @param SqlParameters $params */
    public function execute(string $sql, array $params = []): int
    {
        $stmt = $this->query($sql, $params);
        return $stmt->rowCount();
    }

    /**
     * شروع Transaction
     * H24 Upgrade: پشتیبانی هوشمند از تراکنش‌های تو در تو (Nested Transactions)
     */
    public function beginTransaction(): void
    {
        $this->ensureConnected();
        if ($this->transactionLevel === 0) {
            try {
                $this->primaryPdo()->beginTransaction();
                $this->isRollbackOnly = false; // Reset on new root transaction
            } catch (\Throwable $e) {
                $this->transactionLevel = 0;
                throw new \RuntimeException("PDO BeginTransaction failed: " . $e->getMessage(), (int)$e->getCode(), $e);
            }
        } else {
            // H24 Fix: Create a database SAVEPOINT for nested transactions
            try {
                $this->primaryPdo()->exec("SAVEPOINT trans_" . $this->transactionLevel);
            } catch (\Throwable $e) {
                throw new \RuntimeException("PDO SAVEPOINT creation failed: " . $e->getMessage(), (int)$e->getCode(), $e);
            }
        }
        $this->transactionLevel++;
    }

    /**
     * Commit
     * H24 Upgrade: فقط زمانی به دیتابیس اعمال می‌شود که بالاترین سطح تراکنش خاتمه یابد
     */
    public function commit(): void
    {
        if ($this->transactionLevel <= 0) {
            $this->transactionLevel = 0;
            throw new \RuntimeException('No active transaction to commit');
        }

        if ($this->isRollbackOnly) {
            // Force a rollback of the entire transaction
            $this->transactionLevel = 0;
            if ($this->primaryPdo()->inTransaction()) {
                $this->primaryPdo()->rollback();
            }
            $this->isRollbackOnly = false;
            throw new \RuntimeException('Cannot commit transaction: nested rollback occurred');
        }

        try {
            if ($this->transactionLevel === 1) {
                if (!$this->primaryPdo()->commit()) {
                    throw new \RuntimeException('PDO Commit returned false');
                }
            } else {
                // Nested commit: Release the savepoint
                try {
                    $this->primaryPdo()->exec("RELEASE SAVEPOINT trans_" . ($this->transactionLevel - 1));
                } catch (\Throwable $e) {
                    // Fallback for database engines that do not support RELEASE SAVEPOINT (e.g. sqlite/mssql, though MySQL supports it)
                }
            }
        } finally {
            $this->transactionLevel = max(0, $this->transactionLevel - 1);
        }
    }

    /**
     * Rollback
     * H24 Upgrade: هر کجای زنجیره رخ دهد، به سطح تراکنش مربوطه بازنشانی می‌شود
     */
    public function rollback(): void
    {
        if ($this->transactionLevel <= 0) {
            $this->transactionLevel = 0;
            return;
        }

        try {
            if ($this->transactionLevel === 1) {
                $this->isRollbackOnly = false;
                if ($this->primaryPdo()->inTransaction()) {
                    if (!$this->primaryPdo()->rollback()) {
                         throw new \RuntimeException('PDO Rollback returned false');
                    }
                }
            } else {
                // Nested rollback: Rollback to the savepoint and mark transaction as rollback-only
                $this->isRollbackOnly = true;
                if ($this->primaryPdo()->inTransaction()) {
                    $this->primaryPdo()->exec("ROLLBACK TO SAVEPOINT trans_" . ($this->transactionLevel - 1));
                }
            }
        } catch (\Throwable $e) {
            $this->transactionLevel = 0;
            if ($this->primaryPdo()->inTransaction()) {
                $this->primaryPdo()->rollback();
            }
            throw new \RuntimeException("PDO Rollback failed: " . $e->getMessage(), (int)$e->getCode(), $e);
        } finally {
            $this->transactionLevel = max(0, $this->transactionLevel - 1);
        }
    }

    /**
     * بررسی فعال بودن تراکنش
     */
    public function inTransaction(): bool
    {
        return $this->transactionLevel > 0 || ($this->pdo !== null && $this->primaryPdo()->inTransaction());
    }

    /**
     * Transaction Boundary Manager - Golden Law Opus 4.8
     * اجرای atomic operation با پشتیبانی از nested transactions، retry و rollback-only
     *
     * @param callable $callback function(Database $db): mixed
     * @param int $attempts تعداد تلاش مجدد برای deadlock (پیش‌فرض 3)
     * @return mixed
     * @throws \Throwable
     */
    public function transactional(callable $callback, int $attempts = 3)
    {
        $lastException = new \RuntimeException('Transaction failed after all retries');
        
        for ($attempt = 0; $attempt < $attempts; $attempt++) {
            $this->beginTransaction();
            
            try {
                $result = $callback($this);
                
                if ($this->isRollbackOnly) {
                    throw new \RuntimeException('Transaction marked as rollback-only by nested operation');
                }
                
                $this->commit();
                return $result;
                
            } catch (\Throwable $e) {
                $lastException = $e;
                
                if ($this->inTransaction()) {
                    try {
                        $this->rollback();
                    } catch (\Throwable $rollbackEx) {
                        // Log rollback failure but preserve original exception
                        self::fallbackLog('database.rollback.failed', [
                            'original_error' => $e->getMessage(),
                            'rollback_error' => $rollbackEx->getMessage()
                        ]);
                    }
                }
                
                // Retry فقط برای deadlock یا lock timeout
                $errorMessage = strtolower($e->getMessage());
                $isRetryable = str_contains($errorMessage, 'deadlock') 
                    || str_contains($errorMessage, 'lock wait timeout')
                    || str_contains($errorMessage, 'try restarting transaction');
                
                if ($isRetryable && $attempt < $attempts - 1) {
                    usleep(100000 * ($attempt + 1)); // exponential backoff: 100ms, 200ms, 300ms
                    continue;
                }
                
                throw $e;
            }
        }
        
        throw $lastException;
    }

    /**
     * M3 Fix: حل‌کننده هوشمند و دارای کش کلاینت سنتری
     */
    private function getErrorReporter(): ?\App\Contracts\DatabaseErrorReporter
    {
        return $this->errorReporter;
    }

    /** @param SqlParameters $params */
    private function logSlowQuery(string $sql, array $params, float $duration): void
    {
        try {
            $reporter = $this->getErrorReporter();
            if ($reporter) {
                $interpolatedSql = $this->interpolateSql($sql, $params);
                $reporter->captureMessage(
                    "Slow query detected: " . mb_substr($interpolatedSql, 0, 200),
                    'warning',
                    [
                        'sql' => $sql,
                        'params_count' => count($params),
                        'duration_seconds' => $duration,
                        'interpolated_sql' => mb_substr($interpolatedSql, 0, 1000),
                        'backtrace' => array_slice(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 10), 2)
                    ]
                );
            }
        } catch (\Throwable $ignore) {
            // کاملاً فیل‌سیف
        }
    }

    /**
     * 🛡️ N+1 Detection Wiring (Phase 1+2): ارسال query به Sentry Performance Monitor
     *
     * کنترل‌ها:
     * - DB_TRACK_QUERIES env: on/off کلی (default: false در production، true در dev/test)
     * - DB_TRACK_QUERIES_SAMPLE_RATE env: درصد tracking (default: 1.0 = 100%، در production 0.1 = 10%)
     * - feature flag: feature_flags.db_query_tracking — runtime override بدون deploy
     *
     * ایمنی:
     * - recursion-safe: اگر خود trackQuery باعث DB query شود، reentrancy اتفاق نمی‌افتد
     * - fail-safe: try/catch کامل، Sentry failure هرگز query اصلی را می‌شکند
     * - lazy config: env فقط یک‌بار در طول حیات process خوانده می‌شود
     * - deterministic sampling: queries مشابه همیشه sample یکسان می‌شوند
     *
     * فعال‌سازی:
     *   dev:        DB_TRACK_QUERIES=true
     *   staging:    DB_TRACK_QUERIES=true, SAMPLE_RATE=1.0
     *   production: DB_TRACK_QUERIES=false (پیش‌فرض) → بعد از staging تست:
     *               DB_TRACK_QUERIES=true, DB_TRACK_QUERIES_SAMPLE_RATE=0.1
     */
    /** @param SqlParameters $params */
    private static function trackQueryToSentry(string $sql, float $duration, array $params): void
    {
        // 1. Recursion guard
        if (self::$inQueryTracking) {
            return;
        }

        // 2. Lazy config init
        if (self::$queryTrackingEnabled === null) {
            // priority: feature_flag > env > config
            $featureFlag = function_exists('config')
                ? config('feature_flags.db_query_tracking', null)
                : null;

            // 🐛 BUGFIX-DB-TRACK-1: feature_flags.db_query_tracking یک آرایه است
            // (شامل 'enabled', 'description', 'sample_rate')، نه یک boolean.
            // نسخه‌ی قبلی آرایه را مستقیماً به filter_var می‌داد → همیشه false
            // برمی‌گشت → tracking هرگز فعال نمی‌شد حتی با DB_TRACK_QUERIES=true.
            // اکنون: اگر آرایه بود، فیلد 'enabled' را استخراج می‌کنیم؛
            // در غیر این صورت رفتار قدیمی (boolean مستقیم) حفظ می‌شود.
            if (is_array($featureFlag)) {
                $envValue = $featureFlag['enabled'] ?? null;
            } else {
                $envValue = $featureFlag;
            }

            // fallback به env مستقیم اگر feature_flag مقدار نداشت
            if ($envValue === null) {
                $envValue = function_exists('env') ? env('DB_TRACK_QUERIES', null) : null;
            }
            if ($envValue === null && function_exists('config')) {
                $envValue = config('database.track_queries', false);
            }
            self::$queryTrackingEnabled = filter_var(
                $envValue ?? false,
                FILTER_VALIDATE_BOOLEAN
            );
        }

        if (!self::$queryTrackingEnabled) {
            self::$skippedQueriesCount++;
            return;
        }

        // 3. Sample rate (deterministic based on query hash)
        $sampleRate = self::getSampleRate();
        if ($sampleRate < 1.0) {
            $hash = hexdec(substr(md5($sql), 0, 8)) / 0xFFFFFFFF;
            if ($hash > $sampleRate) {
                self::$skippedQueriesCount++;
                return;
            }
        }

        // 4. Sentry handler may not be loaded during early bootstrap
        if (!class_exists('App\Services\Sentry\SentryExceptionHandler')) {
            return;
        }

        // 5. Track — تحت flag تا اگر Sentry خودش DB زد، recursion نشود
        self::$inQueryTracking = true;
        try {
            \App\Services\Sentry\SentryExceptionHandler::trackQuery(
                $sql,
                $duration,
                !empty($params) ? array_values($params) : []
            );
            self::$trackedQueriesCount++;
        } catch (\Throwable $e) {
            self::$trackingErrorsCount++;
            // Silent: Sentry failure must NEVER affect the original query
        } finally {
            self::$inQueryTracking = false;
        }
    }

    /**
     * 🛡️ Phase 2: sample rate را از config/env بخوان (lazy, cached)
     *
     * 🐛 BUGFIX-DB-TRACK-1: اولویت sample_rate حالا با enabled هماهنگ است:
     *   1. feature_flags.db_query_tracking.sample_rate  (آرایه)
     *   2. env: DB_TRACK_QUERIES_SAMPLE_RATE
     *   3. config: database.track_queries_sample_rate
     *   4. default: 1.0
     */
    private static function getSampleRate(): float
    {
        if (self::$sampleRate !== null) {
            return self::$sampleRate;
        }
        $value = null;

        // 1) اول از feature_flag (آرایه) بخوان
        if (function_exists('config')) {
            $featureFlag = config('feature_flags.db_query_tracking', null);
            if (is_array($featureFlag) && isset($featureFlag['sample_rate'])) {
                $value = $featureFlag['sample_rate'];
            }
        }

        // 2) fallback به env
        if ($value === null && function_exists('env')) {
            $value = env('DB_TRACK_QUERIES_SAMPLE_RATE', null);
        }

        // 3) fallback به config
        if ($value === null && function_exists('config')) {
            $value = config('database.track_queries_sample_rate', 1.0);
        }

        $rate = is_numeric($value) ? (float)$value : 1.0;
        self::$sampleRate = max(0.0, min(1.0, $rate));
        return self::$sampleRate;
    }

    /**
     * 🛡️ Phase 2: آمار tracking برای observability و debugging
     *
     * @return array<string, mixed>{tracked:int, skipped:int, errors:int, enabled:bool, sample_rate:float}
     */
    public static function getTrackingStats(): array
    {
        return [
            'tracked'     => self::$trackedQueriesCount,
            'skipped'     => self::$skippedQueriesCount,
            'errors'      => self::$trackingErrorsCount,
            'enabled'     => (bool)self::$queryTrackingEnabled,
            'sample_rate' => self::getSampleRate(),
            'in_tracking' => self::$inQueryTracking,
        ];
    }

    /**
     * 🛡️ Phase 2: reset counters (برای تست)
     */
    public static function resetTrackingStats(): void
    {
        self::$trackedQueriesCount = 0;
        self::$skippedQueriesCount = 0;
        self::$trackingErrorsCount = 0;
    }

    /** @param SqlParameters $params */
    private function reportQueryError(string $sql, array $params, \Throwable $e): void
    {
        // BUGFIX-RECURSION-2026-06 — Layer 1 & 2: Reentrancy + Same-SQL guards.
        //
        // If we are ALREADY inside an error report and a new query fails
        // (typically because the error reporter itself hit the database),
        // we MUST NOT recurse. Instead, write a single line to the file-based
        // fallback log and return. This converts an O(infinite) recursion
        // into O(1) per failed query inside the reporting path.
        if (self::$inErrorReporting) {
            self::fallbackLog('database.report.reentry_blocked', [
                'error'           => $e->getMessage(),
                'sql'             => mb_substr($sql, 0, 500),
                'original_sql'    => self::$errorReportingForSql,
                'reentry_depth'   => self::$queryDepth,
                'pid'             => function_exists('getmypid') ? getmypid() : null,
            ]);
            return;
        }

        self::$inErrorReporting     = true;
        self::$errorReportingForSql = mb_substr($sql, 0, 500);

        try {
            $reporter = $this->getErrorReporter();
            if ($reporter) {
                $interpolatedSql = $this->interpolateSql($sql, $params);
                $reporter->captureException(
                    $e,
                    [
                        'sql' => $sql,
                        'params_count' => count($params),
                        'interpolated_sql' => mb_substr($interpolatedSql, 0, 1000),
                    ],
                    'error'
                );
            }
        } catch (\Throwable $ignore) {
            // Last-resort: anything that escapes the reporter must not bubble
            // up and replace the original PDOException being thrown by the
            // caller in executeStatement().
            self::fallbackLog('database.report.reporter_threw', [
                'error' => $ignore->getMessage(),
                'class' => get_class($ignore),
            ]);
        } finally {
            // Guard must be released even on fatal errors inside the reporter
            // so that legitimate next requests on the same FPM worker are
            // not permanently blocked from reporting.
            self::$inErrorReporting     = false;
            self::$errorReportingForSql = null;
        }
    }

    /**
     * جلوگیری از Clone
     */
    private function __clone() {}

    /**
     * جلوگیری از Unserialize
     */
    public function __wakeup()
    {
        throw new \Exception("Cannot unserialize singleton");
    }
}