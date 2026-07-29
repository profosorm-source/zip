<?php
namespace Core;

/**
 * Schema Builder
 * 
 * ساخت و مدیریت ساختار جداول
 */
class Schema
{
    private static ?\Core\Database $db = null;

    /**
     * H24 Fix: ولیدیشن نام جدول برای محافظت کامل در برابر SQL Injectionهای پویا
     */
    private static function validateTable(string $table): void
    {
        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $table)) {
            throw new \InvalidArgumentException("نام جدول نامعتبر است ({$table}). استفاده از کاراکترهای خاص مجاز نیست.");
        }
    }

    /**
     * ایجاد جدول
     */
    public static function create(string $table, callable $callback): void
    {
        self::validateTable($table);
        self::$db = Database::getInstance();
        
        $blueprint = new Blueprint($table);
        $callback($blueprint);
        
        $sql = $blueprint->toSql('create');
        
        try {
            self::$db->query($sql);
            logger()->info('migration.table.created', [
                'channel' => 'database',
                'table' => $table,
            ]);
        } catch (\Exception $e) {
            logger()->error('migration.table.create.failed', [
                'channel' => 'database',
                'table' => $table,
                'error' => $e->getMessage(),
                'exception' => get_class($e),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
            throw $e;
        }
    }

    /**
     * بررسی وجود جدول
     */
    public static function hasTable(string $table): bool
    {
        self::validateTable($table);
        self::$db = Database::getInstance();
        
        $sql = "SHOW TABLES LIKE ?";
        $result = self::$db->select($sql, [$table]);
        
        return !empty($result);
    }

    /**
     * حذف جدول
     */
    public static function drop(string $table): void
    {
        self::validateTable($table);
        self::$db = Database::getInstance();
        
        // H24 Fix: بستن نام جدول در Backtick جهت فراردهی امن از کاراکترهای رزرو شده
        $sql = "DROP TABLE IF EXISTS `{$table}`";
        
        try {
            self::$db->query($sql);
            logger()->info('migration.table.dropped', [
                'channel' => 'database',
                'table' => $table,
            ]);
        } catch (\Exception $e) {
            logger()->error('migration.table.drop.failed', [
                'channel' => 'database',
                'table' => $table,
                'error' => $e->getMessage(),
                'exception' => get_class($e),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
            throw $e;
        }
    }

    /**
     * ویرایش جدول
     */
    public static function table(string $table, callable $callback): void
    {
        self::validateTable($table);
        self::$db = Database::getInstance();
        
        $blueprint = new Blueprint($table);
        $callback($blueprint);
        
        $sql = $blueprint->toSql('alter');
        
        try {
            self::$db->query($sql);
            logger()->info('migration.table.altered', [
                'channel' => 'database',
                'table' => $table,
            ]);
        } catch (\Exception $e) {
            logger()->error('migration.table.alter.failed', [
                'channel' => 'database',
                'table' => $table,
                'error' => $e->getMessage(),
                'exception' => get_class($e),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
            throw $e;
        }
    }
}