<?php

declare(strict_types=1);

namespace Core;

/**
 * Model — پایه تمام Model‌های پروژه
 *
 * ─── جریان صحیح ────────────────────────────────────────────────
 *
 *   Container::make(UserModel)
 *       └─→ Model::__construct()
 *               └─→ Container::make(Database::class)  ← singleton
 *
 * ─── قرارداد ───────────────────────────────────────────────────
 *   همه متدها از $this->db استفاده می‌کنند.
 *   db() متد هم همان $this->db را برمی‌گرداند (نه app()->db).
 *   هیچ‌جا مستقیم Database::getInstance() صدا زده نمی‌شود.
 *
 * ─── تذکر ──────────────────────────────────────────────────────
 *   Model نباید Business Logic داشته باشد.
 *   Logic باید در Service باشد، Model فقط Data Access.
 */
/**
 * @phpstan-type AttributeMap array<string, mixed>
 * @phpstan-type RelationDefinition array{0: class-string, 1: string, 2?: string}
 */
abstract class Model
{
    /** @var list<string> */
    protected static array $searchable = [];
    protected static string $table = '';

    /**
     * فیلدهایی که مجاز به insert هستند.
     *
     * ── Mass Assignment Protection ────────────────────────────────────────────
     * اگر یک Model این آرایه را پر کند، متد create() فقط همین فیلدها
     * را به DB می‌فرستد و بقیه $data را نادیده می‌گیرد.
     *
     * اگر خالی باشد (پیش‌فرض) → هیچ فیلتری اعمال نمی‌شود و همه فیلدها
     * پاس می‌شوند (backward-compatible برای Model‌هایی که $fillable ندارند).
     *
     * ── همچنین برای update() ─────────────────────────────────────────────────
     * update() هم $fillable را enforce می‌کند تا جلوگیری شود از اینکه
     * فیلدهای حساس (مثل role, is_admin) از طریق updateUser() تغییر کنند.
     * فیلدهای سیستمی (updated_at) که خود Model اضافه می‌کند مشمول نمی‌شوند.
     * ─────────────────────────────────────────────────────────────────────────
     *
     * مثال در Model فرزند:
     *   protected array $fillable = ['title', 'body', 'user_id'];
     */
    /** @var list<string> */
    protected array $fillable = [];

    protected static bool $unguarded = false;

    protected function fetchObject(\PDOStatement $statement): ?\stdClass
    {
        $row = $statement->fetch(\PDO::FETCH_OBJ);
        return $row instanceof \stdClass ? $row : null;
    }

    /** @return array<string, mixed> */
    protected function fetchAssoc(\PDOStatement $statement): array
    {
        $row = $statement->fetch(\PDO::FETCH_ASSOC);
        return is_array($row) ? $row : [];
    }

    /** @return list<array<string, mixed>> */
    protected function fetchAssocList(\PDOStatement $statement): array
    {
        $rows = $statement->fetchAll(\PDO::FETCH_ASSOC);
        if (!is_array($rows)) {
            return [];
        }

        return array_values(array_filter($rows, 'is_array'));
    }

    /** @return list<\stdClass> */
    protected function fetchObjectList(\PDOStatement $statement): array
    {
        $rows = $statement->fetchAll(\PDO::FETCH_OBJ);
        if (!is_array($rows)) {
            return [];
        }

        return array_values(array_filter(
            $rows,
            static fn(mixed $row): bool => $row instanceof \stdClass
        ));
    }

    public static function unguard(bool $state = true): void
    {
        static::$unguarded = $state;
    }

    public static function reguard(): void
    {
        static::$unguarded = false;
    }

    /**
     * Filter data array by $fillable fields unless model is unguarded
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    protected function filterFillable(array $data): array
    {
        if (static::$unguarded) {
            return $data;
        }

        if (!empty($this->fillable)) {
            return array_intersect_key($data, array_flip($this->fillable));
        }

        return $data;
    }

    /**
     * 🛡️ Phase 3 (Eager Loading Mini-System): تعریف روابط در Model فرزند
     *
     * قالب هر رابطه:
     *   'relationName' => [RelatedModelClass::class, 'foreign_key_in_related_table', 'mode']
     *
     * mode ها:
     *   'one'  → یک رکورد مرتبط (مثل wallet هر کاربر)
     *   'many' → چند رکورد مرتبط (مثل tasks هر کاربر) — default
     *
     * مثال در User.php:
     *   protected array $relations = [
     *       'wallet'    => [WalletModel::class,    'user_id', 'one'],
     *       'tasks'     => [TaskModel::class,      'user_id', 'many'],
     *       'kycRecord' => [KycModel::class,       'user_id', 'one'],
     *   ];
     *
     * نکته: opt-in است — اگر خالی باشد، Model فعلی بدون تغییر کار می‌کند.
     *
     * @var array<string, array{0: class-string, 1: string, 2?: string}>
     */
    protected array $relations = [];

    protected Database $db;

    public function __construct(Database $db) {
        if (empty(static::$table)) {
            throw new \RuntimeException("Model " . static::class . " must define \$table");
        }
        $this->db = $db;
    }

    // ─────────────────────────────────────────────────────────────
    // Internal Helper — یک‌منبعه
    // ─────────────────────────────────────────────────────────────

    /**
     * برای backward compatibility — همان $this->db
     */
    protected function db(): Database
    {
        return $this->db;
    }

    /**
     * دریافت شیء دیتابیس
     */
    public function getDb(): Database
    {
        return $this->db;
    }

    public function getTable(): string
    {
        return static::$table;
    }

    // ─────────────────────────────────────────────────────────────
    // CRUD پایه
    // ─────────────────────────────────────────────────────────────

    /**
     * درج یک رکورد جدید.
     *
     * اگر $fillable پر باشد، فقط فیلدهای مجاز به DB می‌روند (Mass Assignment Protection).
     * اگر $fillable خالی باشد، همه $data پاس می‌شوند (backward-compatible).
     *
     * @param array<string, mixed> $data
     * @return int|false
     */
    public function create(array $data): mixed
    {
        $data = $this->filterFillable($data);

        $result = $this->db->table(static::$table)->insert($data);
        return $result === false ? false : (int)$result;
    }

    /** @return ?\stdClass */
    public function find(int $id): ?object
    {
        $result = $this->db->table(static::$table)
            ->where('id', '=', $id)
            ->first();

        if ($result === null) {
            return null;
        }
        // ROOT FIX (principled): Always normalize DB row to object.
        // This is the single source of truth for "array vs object" problem.
        // All find* and first/get callers in Services/Controllers will benefit.
        return $this->normalizeToObject($result);
    }

    /**
     * ROOT CAUSE FIX (centralized, principled)
     * Single place to guarantee object (never array) from any DB result.
     * Used by find(), custom findBy*, and can be used by Services.
     */
    /**
     * Normalize only documented database row representations.
     *
     * Database/QueryBuilder return stdClass. A small number of legacy model
     * queries still use PDO::FETCH_ASSOC, so arrays are converted here at the
     * one model boundary. Scalar values and arbitrary objects are not database
     * rows and must fail closed rather than being disguised as an object.
     *
     * @return ?\stdClass
     */
    protected function normalizeToObject(mixed $row): ?\stdClass
    {
        if ($row instanceof \stdClass) {
            return $row;
        }
        if (is_array($row)) {
            return (object)$row;
        }
        return null;
    }

    /**
     * @param AttributeMap $filters
     * @return list<\stdClass>
     */
    public function all(array $filters = [], int $limit = 100, int $offset = 0): array
    {
        return $this->db->table(static::$table)
            ->limit((int)$limit)
            ->offset((int)$offset)
            ->get();
    }

    /**
     * 🛡️ Phase 3: دریافت لیست به همراه eager loaded relations
     *
     * این متد N+1 query problem را حل می‌کند. به جای N+1 query:
     *   $users = $this->userModel->all(100);
     *   foreach ($users as $user) {
     *       $user->wallet = $this->walletModel->findByUserId($user->id);  // N queries!
     *   }
     *
     * از این استفاده کنید:
     *   $users = $this->userModel->allWith(['wallet', 'tasks'], 100);
     *   // فقط ۳ query: ۱ برای users، ۱ برای wallets، ۱ برای tasks
     *
     * @param list<string> $relations لیست نام روابط (از $relations همین Model)
     * @param int $limit
     * @param int $offset
     * @return list<\stdClass> لیست objects با relations به صورت property
     */
    public function allWith(array $relations, int $limit = 100, int $offset = 0): array
    {
        $items = $this->all([], $limit, $offset);
        return $this->loadRelations($items, $relations);
    }

    /**
     * 🛡️ Phase 3: دریافت یک رکورد به همراه eager loaded relations
     *
     * اگر رکورد پیدا نشد، null برمی‌گردد (بدون eager load اضافی).
     *
     * @param int $id
     * @param list<string> $relations
     * @return object|null
     */
    public function findWith(int $id, array $relations): ?object
    {
        $item = $this->find($id);
        if ($item === null) {
            return null;
        }
        $loaded = $this->loadRelations([$item], $relations);
        return $loaded[0] ?? null;
    }

    /**
     * 🛡️ Phase 3 (هسته eager loading): بارگذاری relations برای یک collection
     *
     * - اگر Model فرزند $relations تعریف نکرده باشد، items بدون تغییر برمی‌گردد
     * - اگر relation نامعتبر باشد، silent skip می‌شود (با logger)
     * - اگر items خالی باشد، query اضافی نمی‌زند
     * - اگر relation در $relations نباشد، silent skip می‌شود
     *
     * @param list<\stdClass> $items
     * @param list<string>|null $relations null = همه relations تعریف‌شده
     * @return list<\stdClass>
     */
    public function loadRelations(array $items, ?array $relations = null): array
    {
        if (empty($items) || empty($this->relations)) {
            return $items;
        }

        $relations = $relations ?? array_keys($this->relations);

        foreach ($relations as $relationName) {
            if (!isset($this->relations[$relationName])) {
                continue;
            }

            $definition = $this->relations[$relationName];
            [$relatedClass, $foreignKey, $mode] = array_pad($definition, 3, 'many');
            $mode = $mode ?: 'many';

            if (!class_exists($relatedClass)) {
                if (function_exists('logger')) {
                    try { logger()->warning('model.relation.class_missing', [
                        'model' => static::class,
                        'relation' => $relationName,
                        'class' => $relatedClass,
                    ]); } catch (\Throwable $ignore) {}
                }
                continue;
            }

            $items = $this->attachRelation($items, $relatedClass, $foreignKey, $relationName, $mode);
        }

        return $items;
    }

    /**
     * 🛡️ Phase 3 (internal): یک relation را با یک query جمع‌آوری و attach می‌کند
     */
    /**
     * @param list<\stdClass> $items
     * @return list<\stdClass>
     */
    private function attachRelation(array $items, string $relatedClass, string $foreignKey, string $relationName, string $mode): array
    {
        // جمع‌آوری parent IDs (id هر item در collection فعلی)
        $parentIds = [];
        foreach ($items as $item) {
            if (isset($item->id)) {
                $parentIds[] = (int)$item->id;
            }
        }
        $parentIds = array_values(array_unique(array_filter($parentIds)));

        if (empty($parentIds)) {
            return $items;
        }

        try {
            /** @var Model $relatedModel */
            $relatedModel = new $relatedClass($this->db);
            $relatedTable = $relatedModel->getTable();
            if (empty($relatedTable)) {
                return $items;
            }

            // یک query برای همه related rows: WHERE foreign_key IN (...)
            $query = $relatedModel->whereIn($foreignKey, $parentIds);

            // mode 'one': فقط یکی (معمولاً آخرین/اولین) — برای wallet هر کاربر
            if ($mode === 'one') {
                $query->orderBy('id', 'DESC')->limit(100000); // همه را بگیر ولی بعد group کن
                $relatedItems = $query->get();

                // group by foreign key (هر parent فقط یک related دارد)
                $grouped = [];
                foreach ($relatedItems as $r) {
                    $fk = (int)($r->{$foreignKey} ?? 0);
                    if ($fk > 0 && !isset($grouped[$fk])) {
                        $grouped[$fk] = $r;
                    }
                }
                foreach ($items as $item) {
                    $item->{$relationName} = $grouped[(int)$item->id] ?? null;
                }
            } else { // mode 'many' — collection
                $relatedItems = $query->get();

                // group by foreign key (هر parent ممکن است چند related داشته باشد)
                $grouped = [];
                foreach ($relatedItems as $r) {
                    $fk = (int)($r->{$foreignKey} ?? 0);
                    if ($fk > 0) {
                        $grouped[$fk][] = $r;
                    }
                }
                foreach ($items as $item) {
                    $item->{$relationName} = $grouped[(int)$item->id] ?? [];
                }
            }
        } catch (\Throwable $e) {
            // Eager load failure should NEVER break the original query result
            if (function_exists('logger')) {
                try { logger()->warning('model.relation.load_failed', [
                    'model' => static::class,
                    'relation' => $relationName,
                    'error' => $e->getMessage(),
                ]); } catch (\Throwable $ignore) {}
            }
        }

        return $items;
    }

    /**
     * 🛡️ Phase 3: relations تعریف‌شده در این Model را برمی‌گرداند
     * (برای introspection / debug)
     */
    /** @return array<string, RelationDefinition> */
    public function getDefinedRelations(): array
    {
        return $this->relations;
    }

    /**
     * بروزرسانی یک رکورد.
     *
     * اگر $fillable پر باشد، فقط فیلدهای مجاز بروزرسانی می‌شوند.
     * فیلد updated_at که خود این متد اضافه می‌کند همیشه مجاز است
     * و نیازی به قرار گرفتن در $fillable ندارد.
     */
    /** @param AttributeMap $data */
    public function update(int $id, array $data): bool
    {
        $data = $this->filterFillable($data);
        $data['updated_at'] = date('Y-m-d H:i:s');

        $affected = $this->db->table(static::$table)
            ->where('id', '=', $id)
            ->update($data);

        return $affected > 0;
    }

    /** Soft Delete */
    public function delete(int $id): bool
    {
        // FIX C-9: قبلاً بدون چک وجود ردیف، update انجام می‌شد.
        // اگر id وجود نمی‌داشت، 0 ردیف تأثیر می‌گرفت و هیچ خطایی
        // داده نمی‌شد — صداکننده فکر می‌کرد عملیات موفق بوده.
        if (!$this->exists($id)) {
            return false;
        }

        $affected = $this->db->table(static::$table)
            ->where('id', '=', $id)
            ->update(['deleted_at' => date('Y-m-d H:i:s')]);

        return $affected > 0;
    }

    /** Hard Delete — با احتیاط استفاده کنید */
    public function forceDelete(int $id): bool
    {
        return (bool)$this->db->table(static::$table)
            ->where('id', '=', $id)
            ->delete();
    }

    public function count(): int
    {
        return (int) $this->db->table(static::$table)->count();
    }


    // ─────────────────────────────────────────────────────────────
    // Query Builder Bridge — امکان chain کردن روی Model
    // ─────────────────────────────────────────────────────────────

    /**
     * شروع یک query با WHERE روی جدول این Model
     * مثال: $this->model->where('user_id', $id)->whereIn('status', [...])->first()
     */
    public function where(string $column, mixed $operatorOrValue = '=', mixed $value = null): QueryBuilder
    {
        return $this->db->table(static::$table)->where($column, $operatorOrValue, $value);
    }

    /** @param list<mixed> $values */
    public function whereIn(string $column, array $values): QueryBuilder
    {
        return $this->db->table(static::$table)->whereIn($column, $values);
    }

    public function whereNull(string $column): QueryBuilder
    {
        return $this->db->table(static::$table)->whereNull($column);
    }

    public function whereNotNull(string $column): QueryBuilder
    {
        return $this->db->table(static::$table)->whereNotNull($column);
    }

    public function orderBy(string $column, string $direction = 'ASC'): QueryBuilder
    {
        return $this->db->table(static::$table)->orderBy($column, $direction);
    }

    /**
     * دسترسی مستقیم به QueryBuilder برای query های پیچیده‌تر
     */
    public function query(): QueryBuilder
    {
        return $this->db->table(static::$table);
    }

    /**
     * اجرای SQL و بازگشت یک سطر — proxy به Database::fetch
     * @param array<int|string, mixed> $params
     * @return ?\stdClass
     */
    public function fetch(string $sql, array $params = []): ?\stdClass
    {
        return $this->db->fetch($sql, $params);
    }

    /**
     * اجرای SQL و بازگشت همه سطرها — proxy به Database::fetchAll
     * @param array<int|string, mixed> $params
     * @return list<\stdClass>
     */
    public function fetchAll(string $sql, array $params = []): array
    {
        return $this->db->fetchAll($sql, $params);
    }

    public function exists(int $id): bool
    {
        return (bool) $this->db->table(static::$table)
            ->where('id', '=', $id)
            ->selectRaw('1')
            ->first();
    }

    public function beginTransaction(): void
    {
        $this->db->beginTransaction();
    }

    public function commit(): void
    {
        $this->db->commit();
    }

    public function rollback(): void
    {
        $this->db->rollback();
    }

    /**
     * اعمال هوشمند فیلتر کلمه کلیدی (LIKE) بر روی ستون‌های تعریف شده در $searchable
     */
    public function applySearch(QueryBuilder $query, ?string $term): QueryBuilder
    {
        $term = trim((string)$term);
        if (empty($term) || empty(static::$searchable)) {
            return $query;
        }

        $escaped = $this->escapeLikeValue($term);
        $like = "%{$escaped}%";

        return $query->where(function(QueryBuilder $q) use ($like) {
            foreach (static::$searchable as $index => $column) {
                if ($index === 0) {
                    $q->where($column, 'LIKE', $like);
                } else {
                    $q->orWhere($column, 'LIKE', $like);
                }
            }
        });
    }

    /**
     * Escape LIKE wildcards to prevent injection
     */
    protected function escapeLikeValue(string $value, int $maxLength = 100): string
    {
        $value = trim((string)$value);
        
        if (strlen((string)$value) > $maxLength) {
            throw new \InvalidArgumentException("Search term exceeds {$maxLength} characters");
        }
        
        return addcslashes($value, '%_');
    }
    
    /**
     * Chunked update helper
     * @param array<int|string, mixed> $params
     */
    protected function chunkedUpdate(
        string $sql, 
        array $params, 
        int $chunkSize = 1000,
        int $maxIterations = 100
    ): int {
        $totalAffected = 0;
        
        for ($i = 0; $i < $maxIterations; $i++) {
            $stmt = $this->db->prepare($sql . " LIMIT ?");
            // Bind parameters plus the chunkSize
            $allParams = array_merge($params, [$chunkSize]);
            
            // Execute using the statement wrapper with bound parameters
            $stmt->execute($allParams);
            $affected = $stmt->rowCount();
            $totalAffected += $affected;
            
            if ($affected < $chunkSize) {
                break;
            }
            
            usleep(50000); // 50ms delay
        }
        
        return $totalAffected;
    }
    
    /** @return array<string, mixed> */
    public function paginate(int $perPage = 15, string $pageName = 'page', ?int $page = null): array
    {
        return $this->db->table(static::$table)->paginate($perPage, $pageName, $page);
    }

    /**
     * صفحه‌بندی مبتنی بر نشانگر (Cursor-Based Pagination)
     * جهت اسکرول بی‌نهایت در اپلیکیشن‌های نیتیو موبایل
     */
    /** @return array<string, mixed> */
    public function cursorPaginate(string $cursorColumn = 'id', int $perPage = 15, ?string $cursorValue = null, string $direction = 'desc'): array
    {
        return $this->db->table(static::$table)->cursorPaginate($cursorColumn, $perPage, $cursorValue, $direction);
    }

    /**
     * find-or-create with race-safe semantics.
     *
     * Concurrency model (industry-standard "INSERT-then-recover"):
     * ───────────────────────────────────────────────────────────────────────
     *   1. SELECT with the given attributes. If found → return.
     *   2. Try INSERT inside a (possibly nested) transaction.
     *   3. If INSERT raises a UNIQUE-violation, another writer won the race.
     *      → SELECT again and return the winner's row.
     *   4. Any other PDOException re-throws unchanged.
     *
     * Why not GET_LOCK / ON DUPLICATE KEY UPDATE?
     *   - GET_LOCK is global serialization across the cluster — orders of
     *     magnitude slower under contention and MySQL-only.
     *   - ON DUPLICATE KEY UPDATE is MySQL-only AND semantically wrong for
     *     "first-or-create" (it would overwrite existing rows on race loss).
     *   - "INSERT-then-recover" works on MySQL/PostgreSQL/SQLite alike, only
     *     requires what you already need anyway: a UNIQUE index on $attributes.
     *
     * PREREQUISITE — required of the caller:
     *   The columns named in $attributes MUST form (or be covered by) a UNIQUE
     *   index in the database. Without that, no race-free upsert exists in any
     *   RDBMS at any cost, and this method will throw RuntimeException after
     *   the second race-detection failure to make the missing constraint loud.
     *
     * @param array<string,mixed> $attributes lookup columns (must be UNIQUE-indexed)
     * @param array<string,mixed> $values     extra columns to set on INSERT only
     * @throws \RuntimeException if a race occurs but no UNIQUE constraint exists
     * @throws \PDOException     for non-uniqueness DB errors
     */
    public function firstOrCreate(array $attributes, array $values = []): object
    {
        return $this->createOrFetchAtomically(
            $attributes,
            $values,
            updateExistingWithValues: false,
        );
    }

    /**
     * update-or-create with race-safe semantics.
     * Same concurrency contract as firstOrCreate(); see its docblock.
     * Difference: when a row already exists (initial SELECT *or* race loss),
     * $values are written on top of it.
     *
     * @param array<string,mixed> $attributes lookup columns (must be UNIQUE-indexed)
     * @param array<string,mixed> $values     columns to set on both INSERT and UPDATE
     */
    public function updateOrCreate(array $attributes, array $values = []): object
    {
        return $this->createOrFetchAtomically(
            $attributes,
            $values,
            updateExistingWithValues: true,
        );
    }

    /**
     * Shared core for firstOrCreate / updateOrCreate.
     *
     * Implements the four-step protocol described in firstOrCreate()'s docblock.
     * Wraps the INSERT in beginTransaction()/commit() so the row is fully
     * committed before the post-INSERT SELECT runs — preventing find() from
     * observing a phantom NULL on read replicas. Database::beginTransaction()
     * already issues SAVEPOINTs for nested calls, so a caller's outer
     * transaction is preserved without interference.
     *
     * @param AttributeMap $attributes
     * @param AttributeMap $values
     */
    private function createOrFetchAtomically(
        array $attributes,
        array $values,
        bool $updateExistingWithValues,
    ): object {
        // Step 1: optimistic SELECT (cheap — avoids transaction overhead in the
        // common path where the row already exists).
        $existing = $this->selectByAttributes($attributes);
        if ($existing !== null) {
            if ($updateExistingWithValues && !empty($values)) {
                $this->update((int) $existing->id, $values);
                return $this->find((int) $existing->id) ?? $existing;
            }
            return $existing;
        }

        // Step 2: one INSERT attempt inside a transaction. InnoDB resolves a
        // duplicate-key race only after the competing writer commits or rolls
        // back, so a post-rollback SELECT can observe the committed winner.
        // Retrying INSERT is both redundant and unsafe when the violated UNIQUE
        // constraint is unrelated to the lookup attributes.
        $this->db->beginTransaction();
        try {
            $id = $this->create(array_merge($attributes, $values));
            $this->db->commit();

            // Post-commit fetch by primary key. If a concurrent delete wins in
            // this narrow window, retry lookup by immutable attributes.
            return $this->find((int)$id)
                ?? $this->selectByAttributes($attributes)
                ?? throw new \RuntimeException(
                    'createOrFetch: INSERT succeeded but row vanished before fetch'
                );
        } catch (\PDOException $e) {
            $this->db->rollback();

            if (!$this->isUniqueViolation($e)) {
                // FK, NOT NULL, CHECK, deadlock and connection errors are not
                // idempotency races and must propagate unchanged.
                throw $e;
            }

            $winner = $this->selectByAttributes($attributes);
            if ($winner !== null) {
                if ($updateExistingWithValues && $values !== []) {
                    $this->update((int)$winner->id, $values);
                    return $this->find((int)$winner->id) ?? $winner;
                }

                return $winner;
            }

            throw new \RuntimeException(
                "createOrFetch on " . static::$table . ": a UNIQUE violation occurred " .
                "but no row matches the lookup attributes (" . implode(',', array_keys($attributes)) . "). " .
                "Ensure these columns are covered by a UNIQUE index.",
                0,
                $e
            );
        }
    }

    /**
     * SELECT one row matching every key=>value in $attributes, or null.
     * This database read is intentionally impure: a concurrent transaction may
     * commit between two calls with identical arguments.
     *
     * @phpstan-impure
     * @param AttributeMap $attributes
     */
    private function selectByAttributes(array $attributes): ?\stdClass
    {
        $q = $this->db->table(static::$table);
        foreach ((array)$attributes as $col => $val) {
            $q->where($col, '=', $val);
        }
        $row = $q->first();
        return $row ?: null;
    }

    /**
     * Driver-precise UNIQUE-violation detection.
     *
     * SQLSTATE 23000 alone is too broad: it's the "integrity constraint"
     * umbrella, covering UNIQUE *and* NOT NULL, FK, CHECK violations. Silently
     * retrying any 23000 would hide real bugs (e.g. inserting NULL into a
     * NOT NULL column would loop forever). We narrow to driver-specific codes:
     *
     *   MySQL/MariaDB → errorInfo[1] === 1062  (ER_DUP_ENTRY) or 1586
     *   PostgreSQL    → SQLSTATE === '23505'   (unique_violation)
     *   SQLite        → errorInfo[1] === 2067  (SQLITE_CONSTRAINT_UNIQUE), or
     *                   19 + message check     (extended-result-codes off)
     *
     * Refs: dev.mysql.com/doc/mysql-errors, postgresql.org/docs/current/errcodes-appendix,
     *       sqlite.org/rescode
     */
    private function isUniqueViolation(\PDOException $e): bool
    {
        $info       = $e->errorInfo ?? [];
        $sqlstate   = $info[0] ?? (string) $e->getCode();
        $driverCode = $info[1] ?? null;

        // PostgreSQL: SQLSTATE is precise enough.
        if ($sqlstate === '23505') return true;

        // MySQL: 23000 alone is ambiguous — must inspect driver code.
        if ($sqlstate === '23000' && in_array($driverCode, [1062, 1586], true)) return true;

        // SQLite: 2067 is the precise extended code; 19 is the generic
        // CONSTRAINT and needs a message-text fallback.
        if ($driverCode === 2067) return true;
        if ($driverCode === 19 && stripos($e->getMessage(), 'UNIQUE constraint failed') !== false) {
            return true;
        }

        return false;
    }

    /**
     * Validate integer ID
     */
    protected function validateId(int $id, string $field = 'id'): void
    {
        if ($id <= 0) {
            throw new \InvalidArgumentException("Invalid {$field}: must be positive integer");
        }
    }
    
    /**
     * Validate date string
     */
    protected function validateDate(string $date, string $field = 'date'): void
    {
        $d = \DateTime::createFromFormat('Y-m-d H:i:s', $date);
        if (!$d || $d->format('Y-m-d H:i:s') !== $date) {
            throw new \InvalidArgumentException("Invalid {$field} format");
        }
    }
}
