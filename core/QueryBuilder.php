<?php

declare(strict_types=1);
namespace Core;

/**
 * Query Builder
 * 
 * ساخت Query به صورت شیء‌گرا
 */
/**
 * @phpstan-type SqlBindings array<int|string, mixed>
 * @phpstan-type AttributeMap array<string, mixed>
 */
class QueryBuilder
{
    private \PDO $pdo;
    private string $table = '';
    /** @var list<string> */
    private array $select = ['*'];
    /** @var array<int, string> */
    private array $selectRaw = [];
    /** @var array<int, array<mixed>> */
    private array $where = [];
    /** @var array<int, array{string, string}> */
    private array $orderBy = [];
    /** @var array<int, string> */
    private array $groupBy = [];
    /** @var array<int, string> */
    private array $groupByRaw = [];
    private ?int $limit = null;
    private ?int $offset = null;
    /** @var array<int, mixed> */
    private array $join = [];
    private bool $forUpdate = false;
    private bool $distinct = false;
    private bool $allowGlobalUpdate = false;
    
    public function __construct(\PDO $pdo) {
        $this->pdo = $pdo;
    }

    /**
     * Validate نام جدول برای جلوگیری از SQL Injection
     */
    private function validateTableName(string $table): string
    {
        if (!preg_match('/^[a-zA-Z0-9_]+(?:\s+(?:as\s+)?[a-zA-Z0-9_]+)?$/i', $table)) {
            throw new \InvalidArgumentException("نام جدول غیرمجاز: {$table}");
        }
        return $table;
    }

    /**
     * Validate نام ستون برای جلوگیری از SQL Injection
     */
    private function validateColumnName(string $column): string
    {
        // ── Regex امنیتی سخت‌گیرانه: تطبیق کامل الگوهای مجاز نظیر identifier, table.identifier, *, table.*, aliasing
        // هرگونه وجود پرانتز یا نقل قول صراحتاً مسدود شده و منجر به خطا می‌شود.
        $pattern = '/^([a-zA-Z_][a-zA-Z0-9_]*|\*)(\.([a-zA-Z_][a-zA-Z0-9_]*|\*))?(?:\s+(?:[aA][sS]\s+)?[a-zA-Z_][a-zA-Z0-9_]*)?$/';
        if (!preg_match($pattern, $column)) {
            throw new \InvalidArgumentException("نام ستون غیرمجاز یا مشکوک: {$column}");
        }
        return $column;
    }

    /**
     * تنظیم جدول
     */
    public function table(string $table): static
    {
        $this->table = $this->validateTableName($table);
        $this->reset();
        return $this;
    }

    /**
     * انتخاب ستون‌ها
     */
    public function select(string ...$columns): static
    {
        // اگر یکی از columns آرایه بود (مثل ['col1', 'col2'])
        if (count($columns) === 1 && is_array($columns[0])) {
            $columns = $columns[0];
        }
        
        // Validate هر ستون
        foreach ($columns as $column) {
            $this->validateColumnName($column);
        }
        
        $this->select = $columns;
        return $this;
    }

    /**
     * تأیید عبارت raw از طریق Allowlist Parser (Core\Sql\SafeExpression).
     *
     * این متد عبارت را به‌صورت یک AST کوچک پارس می‌کند و خروجی **بازنویسی‌شده**
     * (با backtick روی identifier ها و escape روی string ها) را برمی‌گرداند.
     * یعنی آنچه به DB می‌رود، input کاربر **نیست**؛ خروجی emitter پارسر است.
     *
     * این رویکرد جایگزین blacklist/heuristic قبلی شد چون:
     *   ۱) Allowlist اثبات‌پذیر است: گرامر دقیقاً مشخص است،
     *   ۲) توابع خطرناک (SLEEP, BENCHMARK, LOAD_FILE, EXTRACTVALUE, ...)
     *      ساده‌ترین حالت رد می‌شوند چون در ALLOWED_FUNCTIONS نیستند،
     *   ۳) subquery، UNION، stacked statement از نظر **گرامری** غیرممکن هستند،
     *   ۴) خروجی canonical است → پایداری و log readability بهتر.
     *
     * اگر شما واقعاً به عبارتی نیاز دارید که خارج از این گرامر است (مثلاً
     * یک vendor-specific syntax)، از متد *RawUnsafe() استفاده کنید که آن هم
     * هنوز سمی‌کالن و کامنت را بلاک می‌کند ولی validation parser نمی‌کند.
     *
     * @param string       $sql             عبارت raw از سمت توسعه‌دهنده/کاربر
     * @param string       $context         'select' | 'where' | 'groupby' | 'having'
     * @param list<string> $allowedColumns  اختیاری: محدود کردن نام ستون‌های قابل‌ارجاع
     * @return string                       عبارت بازنویسی‌شده‌ی امن برای تزریق به SQL
     * @throws \Core\Sql\SqlExpressionException
     */
    private function validateRawSql(string $sql, string $context = 'expression', array $allowedColumns = []): string
    {
        // اطمینان از لود شدن کلاس‌های parser حتی اگر autoloader فعال نباشد.
        if (!class_exists(\Core\Sql\SafeExpression::class, false)) {
            require_once __DIR__ . '/Sql/SqlExpressionException.php';
            require_once __DIR__ . '/Sql/SafeExpression.php';
        }
        try {
            return \Core\Sql\SafeExpression::parse($sql, $allowedColumns)->emit();
        } catch (\Core\Sql\SqlExpressionException $e) {
            throw new \Core\Sql\SqlExpressionException(
                "[{$context}] " . $e->getMessage(), 0, $e
            );
        }
    }

    /**
     * Escape-hatch بدون parser. **فقط** برای موارد بسیار خاص:
     *   - migration ها
     *   - vendor-specific syntax که SafeExpression پشتیبانی نمی‌کند
     *   - عبارت‌هایی که از سورس کد ثابت (نه ورودی کاربر) می‌آیند
     *
     * این متد فقط دو حداقل را اعمال می‌کند:
     *   - بلاک کردن سمی‌کالن (stacked queries)
     *   - بلاک کردن کامنت‌های SQL
     * هیچ تضمین دیگری نمی‌دهد. مسئولیتش با caller است.
     * هر فراخوانی در error_log برای audit ثبت می‌شود.
     */
    private function assertRawUnsafe(string $sql, string $context): void
    {
        if ($sql === '' || strlen((string)$sql) > 4096) {
            throw new \InvalidArgumentException("rawUnsafe {$context}: invalid length");
        }
        if (str_contains($sql, ';')) {
            throw new \InvalidArgumentException("rawUnsafe {$context}: ';' is forbidden even in unsafe mode");
        }
        if (preg_match('#(--|/\*|(?<!\w)\#)#', $sql)) {
            throw new \InvalidArgumentException("rawUnsafe {$context}: SQL comments are forbidden even in unsafe mode");
        }
        if (function_exists('logger')) {
            logger()->warning('database.raw_unsafe_used', [
                'context' => $context,
                'sql_preview' => substr($sql, 0, 200),
            ]);
        } else {
            error_log("[QueryBuilder] rawUnsafe used in {$context}: " . substr($sql, 0, 200));
        }
    }

    /**
     * (LEGACY) اعتبارسنجی heuristic که با parser واقعی جایگزین شد.
     * متد به‌خاطر backward-compatibility حفظ شده ولی استفاده نمی‌شود.
     *
     *   لایه 1 - Structural: ممنوعیت ';' در سطح بالا و کامنت‌های SQL (-- , / * , #)
     *            برای جلوگیری از stacked queries و comment-based evasion.
     *   لایه 2 - Balance: تطبیق پرانتزها و quoteها برای جلوگیری از شکستن syntax.
     *   لایه 3 - Tokenized keyword scan: حذف string-literal ها و backtick-identifier ها
     *            از متن، سپس بررسی این که هیچ statement خطرناک (DROP/DELETE/UPDATE/INSERT/...)
     *            به‌عنوان statement جدید آغاز نشده باشد. کلمات داخل literal یا
     *            به‌عنوان نام ستون (مثل deleted_at, updated_at, delete_count) مجاز هستند.
     *
     * @param string $sql      عبارت raw دریافت‌شده از کاربر API.
     * @param string $context  یکی از 'select' | 'where' | 'groupby' | 'orderby' | 'having'.
     *                         برای پیام خطای دقیق‌تر استفاده می‌شود.
     * @throws \InvalidArgumentException در صورت ناامن بودن عبارت.
     */
    private function validateRawSql_LEGACY_HEURISTIC(string $sql, string $context = 'expression'): void
    {
        $sql = trim((string)$sql);

        if ($sql === '') {
            throw new \InvalidArgumentException("Empty raw SQL expression is not allowed");
        }

        // محدودیت طول برای جلوگیری از ReDoS و payload های بزرگ.
        if (strlen((string)$sql) > 4096) {
            throw new \InvalidArgumentException("Raw SQL expression exceeds maximum allowed length (4096)");
        }

        // ───────────────────────────────────────────────────────────────────────
        // پیش‌پردازش: strip کردن string literal ها و backtick identifier ها
        // تا کلمات داخل داده (مثلاً 'deleted') با scanner برخورد نکنند.
        // ───────────────────────────────────────────────────────────────────────
        $stripped = $this->stripStringLiteralsAndIdentifiers($sql);

        // ───────────────────────────────────────────────────────────────────────
        // لایه 1: Structural - ممنوعیت stacked-query و کامنت‌ها
        // ───────────────────────────────────────────────────────────────────────
        if (str_contains($stripped, ';')) {
            throw new \InvalidArgumentException(
                "Semicolons are not allowed in raw {$context}; stacked queries are forbidden"
            );
        }

        // کامنت‌ها در raw expression هیچ کاربرد قانونی ندارند و مسیر اصلی evasion هستند.
        if (preg_match('#(--|/\*|\*/|(?<!\w)\#)#', $sql)) {
            throw new \InvalidArgumentException(
                "SQL comments (--, /*, #) are not allowed in raw {$context}"
            );
        }

        // ───────────────────────────────────────────────────────────────────────
        // لایه 2: Balance - پرانتز و quote باید balanced باشد
        // ───────────────────────────────────────────────────────────────────────
        if (substr_count($stripped, '(') !== substr_count($stripped, ')')) {
            throw new \InvalidArgumentException("Unbalanced parentheses in raw {$context}");
        }

        // اگر بعد از strip کردن literal ها هنوز quote باقی مانده، یعنی unmatched است.
        if (preg_match("/['\"`]/", $stripped)) {
            throw new \InvalidArgumentException("Unbalanced quotes/backticks in raw {$context}");
        }

        // ───────────────────────────────────────────────────────────────────────
        // لایه 3: Context-aware keyword scan
        // ───────────────────────────────────────────────────────────────────────
        // فقط statement-starter های واقعاً خطرناک. این‌ها زمانی خطرناک‌اند که
        // به عنوان شروع یک جمله SQL ظاهر شوند، نه به عنوان بخشی از نام ستون یا literal.
        $dangerousStatements = [
            'drop', 'truncate', 'alter', 'rename', 'create',
            'insert', 'update', 'delete', 'replace', 'merge',
            'grant', 'revoke', 'exec', 'execute', 'call',
            'load_file', 'handler', 'lock', 'unlock', 'use', 'set',
            'benchmark', 'sleep', 'pg_sleep', 'waitfor', 'dbms_lock', 'dbms_pipe',
            // SELECT به‌عنوان subquery داخل raw expression از طرف کاربر یعنی
            // exfiltration vector (مثلا blind SQLi با ascii(substr((SELECT user())))).
            // اگر واقعاً به subquery نیاز دارید، آن را به عنوان QueryBuilder تو در تو
            // (متد ->subQuery) بسازید نه به‌صورت رشته‌ی raw.
            'select', 'show', 'describe', 'desc', 'explain',
        ];

        // literal ها/identifier ها strip شده‌اند، پس فقط ساختار خود SQL باقی مانده.
        $normalized = ' ' . preg_replace('/\s+/', ' ', strtolower((string)$stripped)) . ' ';

        foreach ($dangerousStatements as $kw) {
            // \b کافی نیست چون '_' را به‌عنوان مرز نمی‌شناسد؛ از lookaround سفارشی استفاده می‌کنیم
            // تا 'updated_at' یا 'delete_count' false-positive نسازند.
            $pattern = '/(?<![a-z0-9_])' . preg_quote($kw, '/') . '(?![a-z0-9_])/i';
            if (preg_match($pattern, $normalized)) {
                throw new \InvalidArgumentException(
                    "Dangerous SQL statement '{$kw}' detected in raw {$context}. " .
                    "Raw expressions must be read-only fragments; use the dedicated " .
                    "builder methods for write operations."
                );
            }
        }

        // عبارات multi-word مثل INTO OUTFILE / INTO DUMPFILE / LOAD DATA
        $multiWordPatterns = [
            '/(?<![a-z0-9_])into\s+(out|dump)file(?![a-z0-9_])/i' => 'INTO OUTFILE/DUMPFILE',
            '/(?<![a-z0-9_])load\s+data(?![a-z0-9_])/i'           => 'LOAD DATA',
        ];
        foreach ((array)$multiWordPatterns as $regex => $label) {
            if (preg_match($regex, $normalized)) {
                throw new \InvalidArgumentException(
                    "Dangerous SQL construct '{$label}' detected in raw {$context}"
                );
            }
        }

        // UNION در raw expression همیشه نشانه‌ی union-based SQLi است.
        if (preg_match('/(?<![a-z0-9_])union(\s+all)?(?![a-z0-9_])/i', $normalized)) {
            throw new \InvalidArgumentException(
                "UNION is not allowed in raw {$context} (union-based injection risk)"
            );
        }
    }

    /**
     * حذف امن string-literal ها ('...', "..."), backtick-identifier ها (`...`)
     * و escape sequence های داخل آن‌ها برای آنالیز ساختاری.
     *
     * مثال:  name = 'O''Brien' AND `col` = "x"
     *   →    name =             AND       =
     *
     * این کار باعث می‌شود کلمات کلیدی موجود در داده‌ی واقعی (مثلاً
     * status = 'deleted') با scanner کلمات کلیدی برخورد نکنند.
     */
    private function stripStringLiteralsAndIdentifiers(string $sql): string
    {
        $out = '';
        $len = strlen((string)$sql);
        $i = 0;

        while ($i < $len) {
            $c = $sql[$i];

            // single-quoted string literal
            if ($c === "'") {
                $i++;
                $closed = false;
                while ($i < $len) {
                    if ($sql[$i] === '\\' && $i + 1 < $len) { $i += 2; continue; }
                    if ($sql[$i] === "'" && $i + 1 < $len && $sql[$i + 1] === "'") { $i += 2; continue; }
                    if ($sql[$i] === "'") { $i++; $closed = true; break; }
                    $i++;
                }
                // اگر literal بسته نشده، یک quote باقی می‌گذاریم تا layer 2 (balance) آن را
                // به‌عنوان unbalanced quote تشخیص دهد. در غیر این صورت با space جایگزین می‌شود.
                $out .= $closed ? ' ' : "'";
                continue;
            }

            // double-quoted string literal
            if ($c === '"') {
                $i++;
                $closed = false;
                while ($i < $len) {
                    if ($sql[$i] === '\\' && $i + 1 < $len) { $i += 2; continue; }
                    if ($sql[$i] === '"' && $i + 1 < $len && $sql[$i + 1] === '"') { $i += 2; continue; }
                    if ($sql[$i] === '"') { $i++; $closed = true; break; }
                    $i++;
                }
                $out .= $closed ? ' ' : '"';
                continue;
            }

            // backtick identifier
            if ($c === '`') {
                $i++;
                $closed = false;
                while ($i < $len) {
                    if ($sql[$i] === '`') { $i++; $closed = true; break; }
                    $i++;
                }
                $out .= $closed ? ' ' : '`';
                continue;
            }

            $out .= $c;
            $i++;
        }

        return $out;
    }

    // ═════════════════════════════════════════════════════════════════════════
    //  Raw expression API  ─  Allowlist Parser
    // ═════════════════════════════════════════════════════════════════════════
    //
    //  Two tiers:
    //    • *Raw()       → parsed by Core\Sql\SafeExpression; output is canonical,
    //                     identifiers backtick-quoted, literals re-escaped.
    //                     This is what 99% of code should use.
    //
    //    • *RawUnsafe() → blocks only ';' and SQL comments. No parser. Caller
    //                     takes full responsibility. Each call is logged to
    //                     error_log for audit. Use ONLY for vendor-specific
    //                     syntax or constant fragments under code review.
    //
    //  The raw input is NEVER concatenated into the final SQL when using the
    //  parsed tier — what hits the DB is the emitter's canonical form.
    // ═════════════════════════════════════════════════════════════════════════

    /**
     * Add a SELECT expression. Parsed by the allowlist parser.
     *
     * @example $qb->selectRaw("SUM(CASE WHEN status='deleted' THEN 1 ELSE 0 END) as deleted_count")
     *              // → emits  SUM(CASE WHEN `status` = 'deleted' THEN 1 ELSE 0 END)
     *              // (note: alias support is handled by the column-list builder, not here)
     */
    public function selectRaw(string $expression): static
    {
        $this->selectRaw[] = $this->validateRawSql($expression, 'select');
        return $this;
    }

    /**
     * Escape hatch: insert a raw SELECT fragment WITHOUT parser validation.
     * Use only for constant fragments from trusted source code.
     */
    public function selectRawUnsafe(string $expression): static
    {
        $this->assertRawUnsafe($expression, 'select');
        $this->selectRaw[] = $expression;
        return $this;
    }

    /** Parsed WHERE fragment. */
    /** @param SqlBindings $bindings */
    public function whereRaw(string $sql, array $bindings = []): self
    {
        $this->where[] = [
            'type'     => 'RAW',
            'sql'      => $this->validateRawSql($sql, 'where'),
            'bindings' => $bindings,
            'boolean'  => 'AND',
        ];
        return $this;
    }

    /** Escape-hatch: WHERE without parser. See selectRawUnsafe() warnings. */
    /** @param SqlBindings $bindings */
    public function whereRawUnsafe(string $sql, array $bindings = []): self
    {
        $this->assertRawUnsafe($sql, 'where');
        $this->where[] = ['type'=>'RAW', 'sql'=>$sql, 'bindings'=>$bindings, 'boolean'=>'AND'];
        return $this;
    }

    /** Parsed OR-WHERE fragment. */
    /** @param SqlBindings $bindings */
    public function orWhereRaw(string $sql, array $bindings = []): self
    {
        $this->where[] = [
            'type'     => 'RAW',
            'sql'      => $this->validateRawSql($sql, 'where'),
            'bindings' => $bindings,
            'boolean'  => 'OR',
        ];
        return $this;
    }

    /** Escape-hatch: OR-WHERE without parser. */
    /** @param SqlBindings $bindings */
    public function orWhereRawUnsafe(string $sql, array $bindings = []): self
    {
        $this->assertRawUnsafe($sql, 'where');
        $this->where[] = ['type'=>'RAW', 'sql'=>$sql, 'bindings'=>$bindings, 'boolean'=>'OR'];
        return $this;
    }

    /** Parsed GROUP BY fragment. */
    public function groupByRaw(string $expression): self
    {
        $this->groupByRaw[] = $this->validateRawSql($expression, 'groupby');
        return $this;
    }

    /** Escape-hatch: GROUP BY without parser. */
    public function groupByRawUnsafe(string $expression): self
    {
        $this->assertRawUnsafe($expression, 'groupby');
        $this->groupByRaw[] = $expression;
        return $this;
    }

    /**
     * فعال کردن مجاز بودن Update کلی بدون WHERE (مشابه Delete پیش‌فرض غیرمجاز است)
     */
    public function allowGlobalUpdate(): static
    {
        $this->allowGlobalUpdate = true;
        return $this;
    }

    /**
     * شرط WHERE
     */
    public function where(mixed $column, string $operator = '=', mixed $value = null): static
    {
        if ($column instanceof \Closure) {
            $this->where[] = [
                'type' => 'AND',
                'column' => $column,
                'operator' => 'NESTED',
                'value' => null
            ];
            return $this;
        }

        if ($value === null) {
            $value = $operator;
            $operator = '=';
        }
        
        $this->where[] = [
            'type' => 'AND',
            'column' => $column,
            'operator' => $operator,
            'value' => $value
        ];
        
        return $this;
    }

    /**
     * شرط OR WHERE
     */
    public function orWhere(mixed $column, string $operator = '=', mixed $value = null): static
    {
        if ($column instanceof \Closure) {
            $this->where[] = [
                'type' => 'OR',
                'column' => $column,
                'operator' => 'NESTED',
                'value' => null
            ];
            return $this;
        }

        if ($value === null) {
            $value = $operator;
            $operator = '=';
        }
        
        $this->where[] = [
            'type' => 'OR',
            'column' => $column,
            'operator' => $operator,
            'value' => $value
        ];
        
        return $this;
    }

    /**
     * WHERE IN
     */
    /** @param list<mixed> $values */
    public function whereIn(string $column, array $values): static
    {
        $this->where[] = [
            'type' => 'AND',
            'column' => $column,
            'operator' => 'IN',
            'value' => $values
        ];
        
        return $this;
    }

    /**
     * WHERE NULL
     */
    public function whereNull(string $column): static
    {
        $this->where[] = [
            'type' => 'AND',
            'column' => $column,
            'operator' => 'IS NULL',
            'value' => null
        ];
        
        return $this;
    }

    /**
     * WHERE NOT NULL
     */
    public function whereNotNull(string $column): static
    {
        $this->where[] = [
            'type' => 'AND',
            'column' => $column,
            'operator' => 'IS NOT NULL',
            'value' => null
        ];
        
        return $this;
    }

    /**
     * BUGFIX-QB-OR-NULL-2026-06:
     * متدهای orWhereNull / orWhereNotNull مفقود بودند درحالی‌که در سایر بخش‌های
     * پروژه (Role::allRoles و …) استفاده می‌شدند. برای حفظ تقارن با whereNull/whereNotNull
     * اضافه می‌شوند.
     */
    public function orWhereNull(string $column): static
    {
        $this->where[] = [
            'type' => 'OR',
            'column' => $column,
            'operator' => 'IS NULL',
            'value' => null
        ];
        return $this;
    }

    public function orWhereNotNull(string $column): static
    {
        $this->where[] = [
            'type' => 'OR',
            'column' => $column,
            'operator' => 'IS NOT NULL',
            'value' => null
        ];
        return $this;
    }

    /**
     * JOIN
     */
    public function join(string $table, string $first, string $operator, string $second): static
    {
        $this->join[] = [
            'type' => 'INNER',
            'table' => $this->validateTableName($table),
            'first' => $this->validateColumnName($first),
            'operator' => $this->validateOperator($operator),
            'second' => $this->validateColumnName($second)
        ];
        
        return $this;
    }

    /**
     * LEFT JOIN
     */
    public function leftJoin(string $table, string $first, string $operator, string $second): static
    {
        $this->join[] = [
            'type' => 'LEFT',
            'table' => $this->validateTableName($table),
            'first' => $this->validateColumnName($first),
            'operator' => $this->validateOperator($operator),
            'second' => $this->validateColumnName($second)
        ];
        
        return $this;
    }

    /**
     * Validate عملگر برای جلوگیری از SQL Injection
     */
    private function validateOperator(string $operator): string
    {
        $allowedOps = ['=', '!=', '<', '>', '<=', '>=', 'LIKE', 'NOT LIKE', 'IN', 'NOT IN', 'IS NULL', 'IS NOT NULL'];
        $op = strtoupper((string)$operator);
        if (!in_array($op, $allowedOps, true)) {
            throw new \InvalidArgumentException("عملگر غیرمجاز: {$operator}");
        }
        return $op;
    }

    /**
     * ORDER BY RAW — برای عبارت‌های CASE WHEN و موارد پیچیده
     * توجه: SQL را بدون escape اضافه می‌کند — فقط با string های literal ثابت استفاده کنید
     */
    public function orderByRaw(string $expression): static
    {
        $this->orderBy[] = ['__raw__' . $expression, ''];
        return $this;
    }

    /**
     * ORDER BY
     */
    public function orderBy(string $column, string $direction = 'ASC'): static
    {
        // جلوگیری از SQL Injection: فقط کاراکترهای مجاز در نام ستون
        $this->validateColumnName($column);
        
        $direction = strtoupper((string)$direction);
        if (!in_array($direction, ['ASC', 'DESC'], true)) {
            $direction = 'ASC';
        }
        $this->orderBy[] = [$column, $direction];
        return $this;
    }

    /**
     * LIMIT
     */
    public function limit(int $limit): static
    {
        if (!is_int($limit) || $limit <= 0) {
            throw new \InvalidArgumentException("LIMIT باید عدد مثبت باشد");
        }
        $this->limit = $limit;
        return $this;
    }

    /**
     * OFFSET
     */
    public function offset(int $offset): static
    {
        if (!is_int($offset) || $offset < 0) {
            throw new \InvalidArgumentException("OFFSET باید عدد غیرمنفی باشد");
        }
        $this->offset = $offset;
        return $this;
    }

    /**
     * قفل کردن ردیف برای UPDATE (برای تراکنش‌های حساس مالی)
     * استفاده: ->where('id', $id)->lockForUpdate()->first()
     */
    public function lockForUpdate(): static
    {
        $this->forUpdate = true;
        return $this;
    }

    /**
     * GROUP BY - برای دسته‌بندی نتایج
     */
    public function groupBy(string $column): static
    {
        if (is_array($column)) {
            foreach ($column as $col) {
                $this->validateColumnName($col);
                $this->groupBy[] = $col;
            }
        } else {
            $this->validateColumnName($column);
            $this->groupBy[] = $column;
        }
        return $this;
    }

    /**
     * WHERE NOT IN - برای استثناء مقادیر از نتایج
     */
    /** @param list<mixed> $values */
    public function whereNotIn(string $column, array $values): static
    {
        $this->where[] = [
            'type' => 'AND',
            'column' => $column,
            'operator' => 'NOT IN',
            'value' => $values
        ];
        return $this;
    }

    /**
     * افزایش ستون عددی
     * مثال: ->where('id', $id)->increment('visits', 5)
     */
    public function increment(string $column, float $value = 1): int
    {
        $this->validateColumnName($column);
        
        if (empty($this->table)) {
            throw new \Exception('No table selected for update.');
        }
        if (empty($this->where)) {
            throw new \Exception('Cannot increment without WHERE clause.');
        }
        
        $sets = ["`{$column}` = `{$column}` + ?"];
        $bindings = [(int)$value];
        
        $sql = "UPDATE `{$this->table}` SET " . implode(', ', $sets);
        $sql .= $this->buildWhereClause($bindings);
        
        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($bindings);
            return (bool)$stmt->rowCount();
        } catch (\PDOException $e) {
            try {
                if (function_exists('logger')) { logger()->error('database.increment.failed', [
                        'channel' => 'database',
                        'sql' => $sql ?? null,
                        'error' => $e->getMessage(),
                    ]); }
            } catch (\Throwable $logError) {
                error_log('QueryBuilder increment failed: ' . $e->getMessage());
            }
            throw $e;
        }
    }

    /**
     * میانگین ستون
     * مثال: ->where('user_id', $id)->avg('score')
     */
    public function avg(string $column): float
    {
        $this->validateColumnName($column);
        
        $originalSelect = $this->select;
        $originalLimit = $this->limit;
        
        $this->select = ["AVG(`{$column}`) as avg"];
        $this->limit = null;
        
        $bindings = [];
        $sql = $this->buildSelectQuery($bindings);
        
        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($bindings);
            $result = $stmt->fetch(\PDO::FETCH_OBJ);
            return (float)($result->avg ?? 0);
        } finally {
            $this->select = $originalSelect;
            $this->limit = $originalLimit;
        }
    }

    /**
     * DISTINCT - برای دریافت رکوردهای منحصربه‌فرد
     * مثال: ->selectRaw('DISTINCT country')->get()
     */
    public function distinct(): static
    {
        $this->distinct = true;
        return $this;
    }

    /**
     * دریافت همه رکوردها
     * @return list<\stdClass>
     */
    public function get(): array
    {
        $bindings = [];
        $sql = $this->buildSelectQuery($bindings);
        
        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($bindings);
            // ROOT FIX: Always return objects for consistency (fixes PHPStan "property on array")
            return $stmt->fetchAll(\PDO::FETCH_OBJ);
        } catch (\PDOException $e) {
            // ✅ Safe logging
            try {
                if (function_exists('logger')) { logger()->error('database.builder.query.failed', [
                        'channel' => 'database',
                        'sql' => $sql ?? null,
                        'bindings' => $bindings,
                        'error' => $e->getMessage(),
                        'exception' => get_class($e),
                        'file' => $e->getFile(),
                        'line' => $e->getLine(),
                    ]); }
            } catch (\Throwable $logError) {
                error_log('QueryBuilder query failed: ' . $e->getMessage());
            }
            throw $e;
        }
    }

    /**
     * دریافت اولین رکورد
     */
    public function first(): ?\stdClass
    {
        $this->limit = 1;
        $results = $this->get();
        
        if (empty($results)) {
            return null;
        }
        
        // Now get() already returns objects thanks to FETCH_OBJ
        return $results[0];
    }

    /**
     * دریافت با ID
     */
    public function find(int $id): ?\stdClass
    {
        return $this->where('id', $id)->first();
    }

    /**
     * صفحه‌بندی مبتنی بر آفست (Offset-Based Pagination)
     * رفع باگ متد فراخوانی‌شده از سمت Model
     */
    /** @return array{items: list<\stdClass>, total: int, per_page: int, current_page: int, last_page: int, has_more: bool} */
    public function paginate(int $perPage = 15, string $pageName = 'page', ?int $page = null): array
    {
        $page = $page ?: (int)($_GET[$pageName] ?? 1);
        $page = max(1, $page);
        
        $total = $this->count();
        $offset = ($page - 1) * $perPage;
        
        $items = (clone $this)->limit($perPage)->offset($offset)->get();
        
        return [
            'items'        => $items,
            'total'        => $total,
            'per_page'     => $perPage,
            'current_page' => $page,
            'last_page'    => (int)ceil($total / $perPage),
            'has_more'     => ($page * $perPage) < $total,
        ];
    }

    /**
     * صفحه‌بندی مبتنی بر نشانگر (Cursor-Based Pagination)
     * بهینه‌سازی شده برای اسکرول بی‌نهایت در اپلیکیشن‌های موبایل (بدون سربار OFFSET)
     */
    /** @return array{items: list<\stdClass>, per_page: int, next_cursor: string|null, has_more: bool} */
    public function cursorPaginate(string $cursorColumn = 'id', int $perPage = 15, ?string $cursorValue = null, string $direction = 'desc'): array
    {
        $direction = strtoupper((string)$direction) === 'ASC' ? 'ASC' : 'DESC';
        $operator  = $direction === 'ASC' ? '>' : '<';

        $query = clone $this;
        
        if ($cursorValue !== null && $cursorValue !== '') {
            $query->where($cursorColumn, $operator, $cursorValue);
        }

        $items = $query->orderBy($cursorColumn, $direction)
                       ->limit($perPage + 1)
                       ->get();

        $hasMore = count($items) > $perPage;
        if ($hasMore) {
            array_pop($items);
        }

        $nextCursor = null;
        if (!empty($items)) {
            $lastItem = $items[count($items) - 1];
            $val = is_object($lastItem) ? ($lastItem->{$cursorColumn} ?? null) : ($lastItem[$cursorColumn] ?? null);
            $nextCursor = $val !== null ? (string)$val : null;
        }

        return [
            'items'       => $items,
            'per_page'    => $perPage,
            'next_cursor' => $hasMore ? $nextCursor : null,
            'has_more'    => $hasMore,
        ];
    }

    /**
     * شمارش
     */
    public function count(): int
    {
        // FIX C-4: count() مقدار select و limit را ذخیره می‌کند،
        // سپس بعد از اتمام کار آن‌ها را بازیابی می‌کند.
        // قبلاً first() صدا زده می‌شد که limit را به 1 تبدیل می‌کرد
        // و بعد از بازیابی select، limit همچنان 1 باقی می‌ماند.
        $originalSelect = $this->select;
        $originalSelectRaw = $this->selectRaw;
        $originalLimit  = $this->limit;

        $this->select = [];
        $this->selectRaw = ['COUNT(*) as count'];
        $this->limit  = null;

        $bindings = [];
        $sql = $this->buildSelectQuery($bindings);

        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($bindings);
            $result = $stmt->fetch(\PDO::FETCH_OBJ);
        } catch (\PDOException $e) {
            $this->select = $originalSelect;
            $this->selectRaw = $originalSelectRaw;
            $this->limit  = $originalLimit;
            throw $e;
        }

        $this->select = $originalSelect;
        $this->selectRaw = $originalSelectRaw;
        $this->limit  = $originalLimit;

        return (int)($result->count ?? 0);
    }

    /**
     * INSERT
     */
    /** @param AttributeMap $data */
    public function insert(array $data): int|bool
{
    if (empty($this->table)) {
        throw new \Exception('No table selected for insert.');
    }
    if (empty($data)) {
        throw new \Exception('Insert data is empty.');
    }

    $columns = \array_keys($data);
    $values  = \array_values($data);

    // Validate هر ستون
    foreach ($columns as $column) {
        $this->validateColumnName($column);
    }

    $placeholders = \array_fill(0, \count($columns), '?');

    // بک‌تیک برای ستون‌ها (ایمن‌تر)
    $colsSql = '`' . \implode('`,`', $columns) . '`';

    // بک‌تیک برای نام جدول (فرض: table از داخل سیستم set شده)
    $sql = "INSERT INTO `{$this->table}` ({$colsSql}) VALUES (" . \implode(',', $placeholders) . ")";

    try {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($values);
    } catch (\PDOException $e) {
        // ✅ Safe logging
        try {
            if (function_exists('logger')) { logger()->error('database.insert.failed', [
                    'channel' => 'database',
                    'sql' => $sql ?? null,
                    'error' => $e->getMessage(),
                    'exception' => get_class($e),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                ]); }
        } catch (\Throwable $logError) {
            error_log('QueryBuilder insert failed: ' . $e->getMessage());
        }
        throw $e;
    }

    // تلاش برای گرفتن ID
    $id = $this->pdo->lastInsertId();

    // اگر عددی بود برگردان (برای اینکه create بتواند find کند)
    if ($id !== '' && \ctype_digit((string)$id)) {
        return (int)$id;
    }

    // اگر جدول auto-inc ندارد
    return true;
}

    /**
     * UPDATE
     */
    /** @param AttributeMap $data */
    public function update(array $data): int
    {
        $sets = [];
        $bindings = [];

        foreach ((array)$data as $column => $value) {
            // رفع باگ #20: sanitize نام ستون برای جلوگیری از SQL Injection
            $this->validateColumnName($column);
            $sets[] = "`{$column}` = ?";
            $bindings[] = $value;
        }
        
        // جلوگیری از UPDATE بدون WHERE (به روز رسانی تمام رکوردها) مگر اینکه اجازه صریح داده شده باشد
        if (empty($this->where) && !$this->allowGlobalUpdate) {
            throw new \RuntimeException('UPDATE بدون WHERE clause مجاز نیست مگر اینکه صریحاً از allowGlobalUpdate() استفاده کنید.');
        }

        $sql = "UPDATE `{$this->table}` SET " . implode(', ', $sets);
        
        if (!empty($this->where)) {
            $sql .= $this->buildWhereClause($bindings);
        }
        
        if ($this->limit !== null) {
            $sql .= " LIMIT " . (int)$this->limit;
        }
        
        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($bindings);
            return $stmt->rowCount();
        } catch (\PDOException $e) {
            // ✅ Safe logging
            try {
                if (function_exists('logger')) { logger()->error('database.update.failed', [
                        'channel' => 'database',
                        'sql' => $sql ?? null,
                        'data' => $data ?? [],
                        'error' => $e->getMessage(),
                        'exception' => get_class($e),
                        'file' => $e->getFile(),
                        'line' => $e->getLine(),
                    ]); }
            } catch (\Throwable $logError) {
                error_log('QueryBuilder update failed: ' . $e->getMessage());
            }
            throw $e;
        }
    }

    /**
     * DELETE - باید حداقل یک WHERE clause وجود داشته باشد
     */
    public function delete(): int
    {
        // جلوگیری از DELETE بدون WHERE (حذف تمام رکوردها)
        if (empty($this->where)) {
            throw new \Exception('DELETE بدون WHERE clause مجاز نیست. برای حذف تمام رکوردها از: DB::table("users")->where("1", "=", "1")->delete()');
        }

        $sql = "DELETE FROM `{$this->table}`";
        $bindings = [];
        
        if (!empty($this->where)) {
            $sql .= $this->buildWhereClause($bindings);
        }
        
        if ($this->limit !== null) {
            $sql .= " LIMIT " . (int)$this->limit;
        }
        
        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($bindings);
            return $stmt->rowCount();
        } catch (\PDOException $e) {
            // ✅ Safe logging
            try {
                if (function_exists('logger')) { logger()->error('database.delete.failed', [
                        'channel' => 'database',
                        'sql' => $sql ?? null,
                        'error' => $e->getMessage(),
                        'exception' => get_class($e),
                        'file' => $e->getFile(),
                        'line' => $e->getLine(),
                    ]); }
            } catch (\Throwable $logError) {
                error_log('QueryBuilder delete failed: ' . $e->getMessage());
            }
            throw $e;
        }
    }

    /**
     * ساخت SELECT Query
     */
    /** @param SqlBindings $bindings */
    private function buildSelectQuery(array &$bindings = []): string
    {
        $mappedCols = [];
        // اگر فیلدهای select تعریف شده باشند یا هیچ عبارت selectRaw ای وجود نداشته باشد
        $hasColumns = count($this->select) > 1 || ($this->select !== ['*']);
        
        if ($hasColumns || empty($this->selectRaw)) {
            $mappedCols = array_map(function($col) {
                if ($col === '*') {
                    return '*';
                }
                
                $aliasParts = preg_split('/\s+as\s+/i', $col);
                if (count($aliasParts) === 1) {
                    $aliasParts = preg_split('/\s+/', $col);
                }
                
                $mainCol = trim($aliasParts[0]);
                $alias = isset($aliasParts[1]) ? trim($aliasParts[1]) : null;

                if ($mainCol === '*') {
                    $wrappedMain = '*';
                } elseif (strpos($mainCol, '.') !== false) {
                    $parts = explode('.', $mainCol);
                    $p0 = trim($parts[0]);
                    $p1 = trim($parts[1]);
                    
                    $wrappedMain = ($p0 === '*' ? '*' : '`' . $p0 . '`') . '.' . ($p1 === '*' ? '*' : '`' . $p1 . '`');
                } else {
                    $wrappedMain = '`' . $mainCol . '`';
                }

                if ($alias) {
                    return $wrappedMain . ' as `' . $alias . '`';
                }
                return $wrappedMain;
            }, $this->select);
        }

        if (!empty($this->selectRaw)) {
            $mappedCols = array_merge($mappedCols, $this->selectRaw);
        }

        $selectCols = implode(', ', $mappedCols);

        $tableSql = $this->table;
        if (strpos($tableSql, ' ') !== false) {
            $parts = preg_split('/\s+/', $tableSql);
            if (count($parts) === 3 && strtolower($parts[1]) === 'as') {
                $tableSql = "`" . $parts[0] . "` as `" . $parts[2] . "`";
            } elseif (count($parts) === 2) {
                $tableSql = "`" . $parts[0] . "` as `" . $parts[1] . "`";
            }
        } else {
            $tableSql = "`{$tableSql}`";
        }

        $selectClause = $this->distinct ? "DISTINCT {$selectCols}" : $selectCols;
        $sql = "SELECT {$selectClause} FROM {$tableSql}";
        
        // JOIN
        if (!empty($this->join)) {
            foreach ($this->join as $join) {
                $joinTable = $join['table'];
                if (strpos($joinTable, ' ') !== false) {
                    $parts = preg_split('/\s+/', $joinTable);
                    if (count($parts) === 3 && strtolower($parts[1]) === 'as') {
                        $joinTable = "`" . $parts[0] . "` as `" . $parts[2] . "`";
                    } elseif (count($parts) === 2) {
                        $joinTable = "`" . $parts[0] . "` as `" . $parts[1] . "`";
                    }
                } else {
                    $joinTable = "`{$joinTable}`";
                }
                $sql .= " {$join['type']} JOIN {$joinTable} ON {$join['first']} {$join['operator']} {$join['second']}";
            }
        }
        
        // WHERE
        if (!empty($this->where)) {
            $sql .= $this->buildWhereClause($bindings);
        }

        // GROUP BY
        if (!empty($this->groupBy) || !empty($this->groupByRaw)) {
            $groups = array_map(function($col) {
                if (strpos($col, '(') !== false || strpos($col, ' ') !== false) {
                    return $col; // Expression (e.g. HOUR(created_at))
                }
                return strpos($col, '.') !== false 
                    ? str_replace('.', '`.`', '`' . $col . '`')
                    : '`' . $col . '`';
            }, $this->groupBy);
            
            if (!empty($this->groupByRaw)) {
                $groups = array_merge($groups, $this->groupByRaw);
            }
            
            $sql .= " GROUP BY " . implode(', ', $groups);
        }
        
        // ORDER BY
        if (!empty($this->orderBy)) {
            $sql .= " ORDER BY ";
            $orders = [];
            foreach ($this->orderBy as $order) {
                // raw expression (از orderByRaw)
                if (str_starts_with($order[0], '__raw__')) {
                    $orders[] = substr($order[0], 7);
                    continue;
                }
                $col = strpos($order[0], '.') !== false 
                    ? str_replace('.', '`.`', '`' . $order[0] . '`')
                    : '`' . $order[0] . '`';
                $orders[] = "{$col} {$order[1]}";
            }
            $sql .= implode(', ', $orders);
        }
        
        // LIMIT
        if ($this->limit !== null) {
            $sql .= " LIMIT " . (int)$this->limit;
        }
        
        // OFFSET
        if ($this->offset !== null) {
            $sql .= " OFFSET " . (int)$this->offset;
        }
        
        // FOR UPDATE (قفل برای تراکنش‌ها)
        if ($this->forUpdate) {
            $sql .= " FOR UPDATE";
        }
        return $sql;
    }

    /**
     * ساخت SELECT Query (بدون DISTINCT)
     */
    /** @param SqlBindings $bindings */
    private function buildSelectQuerySimple(array &$bindings = []): string
    {
        return $this->buildSelectQuery($bindings);
    }

    /**
     * ساخت WHERE Clause
     */
    /** @param SqlBindings $bindings */
    private function buildWhereClause(array &$bindings): string
    {
        $sql = " WHERE ";
        $conditions = [];
        
        foreach ($this->where as $index => $condition) {
            if ($condition['type'] === 'RAW') {
                $type = $index === 0 ? '' : " {$condition['boolean']} ";
                $conditions[] = $type . "({$condition['sql']})";
                $bindings = array_merge($bindings, $condition['bindings']);
                continue;
            }

            $type = $index === 0 ? '' : " {$condition['type']} ";
            
            if ($condition['operator'] === 'NESTED') {
                $subBuilder = new QueryBuilder($this->pdo);
                $subBuilder->table = $this->table;
                $condition['column']($subBuilder);
                
                if (!empty($subBuilder->where)) {
                    $subBindings = [];
                    $subSql = $subBuilder->buildWhereClause($subBindings);
                    $subSql = substr($subSql, 7); // Remove " WHERE "
                    $conditions[] = $type . "({$subSql})";
                    $bindings = array_merge($bindings, $subBindings);
                }
                continue;
            }

            // رفع باگ #20: sanitize نام ستون در WHERE clause
            $col = $condition['column'];
            $this->validateColumnName($col);
            
            // sanitize operator - استفاده از method موجود
            $op = $this->validateOperator($condition['operator']);
            
            // اضافه کردن backticks برای ستون
            if (strpos($col, '.') !== false) {
                $col = str_replace('.', '`.`', '`' . $col . '`');
            } else {
                $col = '`' . $col . '`';
            }
            
            // Handle null values - convert = NULL to IS NULL and != NULL to IS NOT NULL
            if ($op === 'IS NULL' || $op === 'IS NOT NULL') {
                $conditions[] = $type . "{$col} {$op}";
            } elseif ($condition['value'] === null) {
                if ($op === '=') {
                    $conditions[] = $type . "{$col} IS NULL";
                } elseif ($op === '!=' || $op === '<>') {
                    $conditions[] = $type . "{$col} IS NOT NULL";
                } else {
                    // For other operators with null, just skip binding
                    $conditions[] = $type . "{$col} {$op} ?";
                    $bindings[] = $condition['value'];
                }
            } elseif ($op === 'IN') {
                if (empty($condition['value'])) {
                    // IN () نامعتبر است، شرط 0 = 1 (همیشه غلط) قرار داده می‌شود
                    $conditions[] = $type . "0 = 1";
                } else {
                    $placeholders = array_fill(0, count($condition['value']), '?');
                    $conditions[] = $type . "{$col} IN (" . implode(', ', $placeholders) . ")";
                    $bindings = array_merge($bindings, $condition['value']);
                }
            } elseif ($op === 'NOT IN') {
                if (empty($condition['value'])) {
                    // NOT IN () به معنی انتخاب همه است، شرط 1 = 1 (همیشه راست) قرار داده می‌شود
                    $conditions[] = $type . "1 = 1";
                } else {
                    $placeholders = array_fill(0, count($condition['value']), '?');
                    $conditions[] = $type . "{$col} NOT IN (" . implode(', ', $placeholders) . ")";
                    $bindings = array_merge($bindings, $condition['value']);
                }
            } else {
                $conditions[] = $type . "{$col} {$op} ?";
                $bindings[] = $condition['value'];
            }
        }
        
        $sql .= implode('', $conditions);
        
        return $sql;
    }


    /**
     * بررسی وجود رکورد
     */
    public function exists(): bool
    {
        return $this->count() > 0;
    }

    /**
     * WHERE JSON contains
     */
    public function whereJsonContains(string $column, mixed $value): static
    {
        $json = json_encode($value);
        $this->where[] = ["JSON_CONTAINS(`{$column}`, ?)", '=', $json];
        return $this;
    }

    /**
     * Reset کردن Query
     */
    private function reset(): void
    {
        $this->select = ['*'];
        $this->selectRaw = [];
        $this->where = [];
        $this->orderBy = [];
        $this->groupBy = [];
        $this->groupByRaw = [];
        $this->limit = null;
        $this->offset = null;
        $this->join = [];
        $this->forUpdate = false;
        $this->distinct = false;
        $this->allowGlobalUpdate = false;
    }
}