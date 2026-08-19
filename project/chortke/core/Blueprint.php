<?php
namespace Core;

/**
 * Blueprint
 * 
 * تعریف ساختار جدول
 */
class Blueprint
{
    private string $table;
    /** @var list<string> */
    private array $columns = [];
    /** @var list<string> */
    private array $indexes = [];
    /** @var list<string> */
    private array $commands = [];       // H25 Fix: صف نگهدارنده دستورات ساختاری اختصاصی مانند DROP و RENAME
    /** @var list<string> */
    private array $modifications = []; // H25 Fix: علامت‌گذار برای ستون‌هایی که به جای ADD باید MODIFY شوند

    public function __construct(string $table) {
        $this->table = $table;
    }

    /**
     * ID (Auto Increment)
     */
    public function id(string $name = 'id'): static
    {
        return $this->bigIncrements($name);
    }

    /**
     * Big Integer Auto Increment
     */
    public function bigIncrements(string $name): static
    {
        $this->columns[] = "`{$name}` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY";
        return $this;
    }

    /**
     * String
     */
    public function string(string $name, int $length = 255): static
    {
        $this->columns[] = "`{$name}` VARCHAR({$length})";
        return $this;
    }

    /**
     * Text
     */
    public function text(string $name): static
    {
        $this->columns[] = "`{$name}` TEXT";
        return $this;
    }

    /**
     * Integer
     */
    public function integer(string $name): static
    {
        $this->columns[] = "`{$name}` INT";
        return $this;
    }

    /**
     * Big Integer
     */
    public function bigInteger(string $name): static
    {
        $this->columns[] = "`{$name}` BIGINT";
        return $this;
    }

    /**
     * Decimal
     */
    public function decimal(string $name, int $precision = 8, int $scale = 2): static
    {
        $this->columns[] = "`{$name}` DECIMAL({$precision}, {$scale})";
        return $this;
    }

    /**
     * Boolean
     */
    public function boolean(string $name): static
    {
        $this->columns[] = "`{$name}` TINYINT(1)";
        return $this;
    }

    /**
     * Date
     */
    public function date(string $name): static
    {
        $this->columns[] = "`{$name}` DATE";
        return $this;
    }

    /**
     * DateTime
     */
    public function dateTime(string $name): static
    {
        $this->columns[] = "`{$name}` DATETIME";
        return $this;
    }

    /**
     * Timestamp
     */
    public function timestamp(string $name): static
    {
        $this->columns[] = "`{$name}` TIMESTAMP";
        return $this;
    }

    /**
     * Timestamps (created_at, updated_at)
     */
    public function timestamps(): static
    {
        $this->columns[] = "created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP";
        $this->columns[] = "updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP";
        return $this;
    }

    /**
     * Soft Deletes
     */
    public function softDeletes(): static
    {
        $this->columns[] = "deleted_at TIMESTAMP NULL";
        return $this;
    }

    /**
     * Enum
     *
     * @param list<string> $values
     */
    public function enum(string $name, array $values): static
    {
        $valuesStr = "'" . implode("','", $values) . "'";
        $this->columns[] = "`{$name}` ENUM({$valuesStr})";
        return $this;
    }

    /**
     * Foreign Key
     */
    public function foreignId(string $name): static
    {
        $this->columns[] = "`{$name}` BIGINT UNSIGNED";
        return $this;
    }

    /**
     * Nullable
     */
    public function nullable(): static
    {
        $lastIndex = count($this->columns) - 1;
        $this->columns[$lastIndex] .= " NULL";
        return $this;
    }

    /**
     * Default
     */
    public function default(string|int|float|bool|null $value): static
    {
        $lastIndex = count($this->columns) - 1;
        if ($lastIndex < 0) {
            throw new \LogicException('A column must be defined before assigning a default value.');
        }

        if (is_string($value)) {
            $sqlValue = "'" . str_replace("'", "''", $value) . "'";
        } elseif (is_bool($value)) {
            $sqlValue = $value ? '1' : '0';
        } elseif ($value === null) {
            $sqlValue = 'NULL';
        } elseif (is_float($value)) {
            if (!is_finite($value)) {
                throw new \InvalidArgumentException('Schema default float must be finite.');
            }
            $sqlValue = (string)$value;
        } else {
            $sqlValue = (string)$value;
        }

        $this->columns[$lastIndex] .= " DEFAULT {$sqlValue}";
        return $this;
    }

    /**
     * Unique
     */
    public function unique(): static
    {
        $lastIndex = count($this->columns) - 1;
        $this->columns[$lastIndex] .= " UNIQUE";
        return $this;
    }

    /**
     * Index
     */
    public function index(mixed $columns): static
    {
        if (!is_array($columns)) {
            $columns = [$columns];
        }
        
        $this->indexes[] = "INDEX (" . implode(', ', $columns) . ")";
        return $this;
    }

    /**
     * H25 Fix: حذف ستون در حالت Alter
     */
    public function dropColumn(string $name): static
    {
        $this->commands[] = "DROP COLUMN `{$name}`";
        return $this;
    }

    /**
     * H25 Fix: تغییر نام ستون در حالت Alter
     */
    public function renameColumn(string $from, string $to): static
    {
        $this->commands[] = "RENAME COLUMN `{$from}` TO `{$to}`";
        return $this;
    }

    /**
     * H25 Fix: ویرایش ساختار ستون فعلی (تبدیل ADD به MODIFY COLUMN)
     */
    public function change(): static
    {
        $lastIndex = count($this->columns) - 1;
        if ($lastIndex >= 0) {
            $this->modifications[$lastIndex] = 'MODIFY COLUMN';
        }
        return $this;
    }

    /**
     * H25 Fix: حذف ایندکس در حالت Alter
     */
    public function dropIndex(string $indexName): static
    {
        $this->commands[] = "DROP INDEX `{$indexName}`";
        return $this;
    }

    /**
     * H25 Fix: تعریف کلید خارجی به شکل استاندارد
     */
    public function foreign(string $column, string $references, string $on, string $onDelete = 'CASCADE', string $onUpdate = 'RESTRICT'): static
    {
        $this->commands[] = "ADD CONSTRAINT `fk_{$this->table}_{$column}` FOREIGN KEY (`{$column}`) REFERENCES `{$on}` (`{$references}`) ON DELETE {$onDelete} ON UPDATE {$onUpdate}";
        return $this;
    }

    /**
     * تبدیل به SQL
     */
    public function toSql(string $type = 'create'): string
    {
        if ($type === 'create') {
            $sql = "CREATE TABLE IF NOT EXISTS `{$this->table}` (\n";
            $sql .= "  " . implode(",\n  ", $this->columns);
            
            if (!empty($this->indexes)) {
                $sql .= ",\n  " . implode(",\n  ", $this->indexes);
            }
            
            $sql .= "\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
            
            return $sql;
        }
        
        if ($type === 'alter') {
            $statements = [];
            
            // ۱. پردازش ستون‌ها با در نظر گرفتن فلگ MODIFY
            foreach ($this->columns as $index => $columnSql) {
                $prefix = isset($this->modifications[$index]) ? $this->modifications[$index] : 'ADD COLUMN';
                $statements[] = "{$prefix} {$columnSql}";
            }
            
            // ۲. پردازش ایندکس‌های اضافه شده
            foreach ($this->indexes as $indexSql) {
                $statements[] = "ADD " . $indexSql;
            }
            
            // ۳. الحاق دستورات اختصاصی (DROP, RENAME, FOREIGN)
            foreach ($this->commands as $cmdSql) {
                $statements[] = $cmdSql;
            }
            
            if (empty($statements)) {
                throw new \Exception("No columns or commands defined for alteration on table '{$this->table}'");
            }

            $sql = "ALTER TABLE `{$this->table}`\n";
            $sql .= "  " . implode(",\n  ", $statements);
            
            return $sql;
        }
        
        throw new \Exception("Unknown SQL type: {$type}");
    }
}