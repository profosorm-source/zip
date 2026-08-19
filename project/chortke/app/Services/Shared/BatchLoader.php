<?php

declare(strict_types=1);

namespace App\Services\Shared;

use Core\Database;

/**
 * BatchLoader — حل ریشه‌ای N+1 Query
 *
 * به جای N query جداگانه در foreach، یک IN query واحد اجرا می‌کند.
 *
 * ── مثال استفاده ──────────────────────────────────────────────────────
 *
 * // ❌ قبل (N+1):
 * foreach ($orders as $o) {
 *     $user = $this->toObject($this->db->fetch("SELECT * FROM users WHERE id = ?", [$o->user_id]);
 * }
 *
 * // ✅ بعد (1+1):
 * $usersMap = BatchLoader::byIds($this->db, 'users', array_column($orders, 'user_id'));
 * foreach ($orders as $o) {
 *     $user = $usersMap[$o->user_id] ?? null;
 * }
 *
 * ──────────────────────────────────────────────────────────────────────
 */
class BatchLoader
{


    /**
     * بارگذاری batch رکوردها بر اساس یک ستون ID
     *
     * @param Database $db
     * @param string   $table      نام جدول
     * @param list<int|string|null> $ids آرایه شناسه‌ها؛ null/empty در boundary حذف می‌شود
     * @param string   $keyColumn  ستون کلید (پیش‌فرض: id)
     * @param string   $columns    ستون‌های SELECT (پیش‌فرض: *)
     * @return array<int|string, \stdClass>  map از key → row
     */
    public static function byIds(
        Database $db,
        string $table,
        array $ids,
        string $keyColumn = 'id',
        string $columns = '*'
    ): array {
        if (empty($ids)) {
            return [];
        }

        $ids = array_values(array_unique(array_filter($ids, fn($id) => $id !== null && $id !== '')));
        if (empty($ids)) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $table        = self::sanitizeIdentifier($table);
        $keyColumn    = self::sanitizeIdentifier($keyColumn);

        $rows = $db->fetchAll(
            "SELECT {$columns} FROM `{$table}` WHERE `{$keyColumn}` IN ({$placeholders})",
            array_values($ids)
        ) ?: [];

        // ایندکس بر اساس keyColumn
        $map = [];
        foreach ($rows as $row) {
            $key       = $row->$keyColumn ?? null;
            if ($key !== null) {
                $map[$key] = $row;
            }
        }

        return $map;
    }

    /**
     * بارگذاری batch با شرط‌های ترکیبی (compound key)
     * مناسب برای: WHERE (user_id = ? AND currency = ?) OR (user_id = ? AND currency = ?) ...
     *
     * @param Database $db
     * @param string   $query   کوئری کامل با ? placeholder
     * @param array<int|string, mixed> $params آرایه پارامترها (flat)
     * @param callable $keyFn   تابع lambda برای ساختن key از row
     * @return array<string, object>
     */
    public static function byQuery(
        Database $db,
        string $query,
        array $params,
        callable $keyFn
    ): array {
        if (empty($params)) {
            return [];
        }

        $rows = $db->fetchAll($query, $params) ?: [];
        $map  = [];
        foreach ($rows as $row) {
            $key       = $keyFn($row);
            $map[$key] = $row;
        }

        return $map;
    }

    /**
     * بررسی وجود رکورد برای مجموعه‌ای از شناسه‌ها
     * برمی‌گرداند: set از id های موجود
     *
     * @param list<int|string> $ids شناسه‌ها
     * @return list<int|string> IDs که وجود دارند
     */
    public static function existingIds(
        Database $db,
        string $table,
        array $ids,
        string $keyColumn = 'id'
    ): array {
        if (empty($ids)) {
            return [];
        }

        $ids          = array_values(array_unique(array_filter($ids)));
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $table        = self::sanitizeIdentifier($table);
        $keyColumn    = self::sanitizeIdentifier($keyColumn);

        $rows = $db->fetchAll(
            "SELECT `{$keyColumn}` FROM `{$table}` WHERE `{$keyColumn}` IN ({$placeholders})",
            $ids
        ) ?: [];

        return array_map(fn($r) => $r->$keyColumn, $rows);
    }

    /**
     * Batch aggregate بر اساس group key
     * مناسب برای: ledger balance، count per user و ...
     *
     * مثال:
     * BatchLoader::aggregate($db,
     *   "SELECT account, currency, SUM(debit)-SUM(credit) AS net
     *    FROM ledger_entries
     *    WHERE account IN (%s) AND currency = ?
     *    GROUP BY account, currency",
     *   $accountKeys,
     *   ['irt'],
     *   fn($row) => "{$row->account}:{$row->currency}"
     * )
     *
     * @param string $queryWithPlaceholder  کوئری با %s برای IN list
     * @param list<mixed> $inValues مقادیر IN
     * @param array<int|string, mixed> $extraParams پارامترهای اضافه بعد از IN
     * @param callable $keyFn              تابع ساختن key از row
     * @return array<string, mixed>
     */
    public static function aggregate(
        Database $db,
        string $queryWithPlaceholder,
        array $inValues,
        array $extraParams,
        callable $keyFn
    ): array {
        if (empty($inValues)) {
            return [];
        }

        $inValues     = array_values(array_unique($inValues));
        $placeholders = implode(',', array_fill(0, count($inValues), '?'));
        $query        = sprintf($queryWithPlaceholder, $placeholders);
        $params       = array_merge($inValues, $extraParams);

        $rows = $db->fetchAll($query, $params) ?: [];
        $map  = [];
        foreach ($rows as $row) {
            $key       = $keyFn($row);
            $map[$key] = $row;
        }

        return $map;
    }

    /**
     * جلوگیری از SQL Injection در نام جداول/ستون‌ها
     */
    private static function sanitizeIdentifier(string $identifier): string
    {
        // فقط حروف، اعداد، underscore و نقطه مجاز هستند
        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_.]*$/', $identifier)) {
            throw new \InvalidArgumentException(
                "Invalid SQL identifier: '{$identifier}'"
            );
        }
        return $identifier;
    }
}
