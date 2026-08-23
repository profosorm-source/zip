<?php

declare(strict_types=1);

namespace App\Models;

use Core\Model;

/**
 * Coupon Model
 */
class Coupon extends Model
{
    protected static string $table = 'coupons';

    protected array $fillable = [
        'code', 'type', 'value', 'min_purchase', 'max_discount',
        'start_date', 'end_date', 'usage_limit', 'usage_count',
        'applicable_to', 'active', 'created_by'
    ];

    public ?string $code = null;
    public ?string $type = null;
    public ?float $value = null;
    public ?float $min_purchase = null;
    public ?float $max_discount = null;
    public ?string $start_date = null;
    public ?string $end_date = null;
    public ?int $usage_limit = null;
    public ?int $usage_count = null;
    public ?string $applicable_to = null;
    public ?bool $active = null;
    public ?int $created_by = null;

    /**
     * تمام کوپن‌ها با سازگاری با Model پایه
     */
    /** @return list<object> */
    public function getAll(int $limit = 100, int $offset = 0): array
    {
        return $this->db->table(static::$table)
            ->whereNull('deleted_at')
            ->orderBy('id', 'DESC')
            ->limit($limit)
            ->offset($offset)
            ->get();
    }

    /**
     * یافتن کوپن با کد
     */
    public function findByCode(string $code): ?\stdClass
    {
        return $this->db->table(static::$table)
            ->where('code', '=', $code)
            ->whereNull('deleted_at')
            ->first();
    }

    /**
     * یافتن کوپن با کد و قفل بدبینانه (Pessimistic Locking)
     */
    public function findByCodeWithLock(string $code): ?\stdClass
    {
        return $this->db->table(static::$table)
            ->where('code', '=', $code)
            ->whereNull('deleted_at')
            ->lockForUpdate()
            ->first();
    }

    /**
     * یافتن کوپن با قفل بدبینانه (Pessimistic Locking)
     */
    public function findWithLock(int $id): ?\stdClass
    {
        return $this->db->table(static::$table)
            ->where('id', '=', $id)
            ->lockForUpdate()
            ->first();
    }

    /**
     * لیست کوپن‌های فعال
     */
    /** @return list<object> */
    public function getActiveCoupons(int $limit = 100): array
    {
        return $this->db->table(static::$table)
            ->where('active', '=', 1)
            ->whereNull('deleted_at')
            ->orderBy('created_at', 'DESC')
            ->limit($limit)
            ->get();
    }

    /**
     * بررسی فعال بودن کوپن (instance method)
     */
    public function isActive(): bool
    {
        if (empty($this->active)) {
            return false;
        }

        $now = date('Y-m-d H:i:s');

        if (!empty($this->start_date) && $this->start_date > $now) {
            return false;
        }

        if (!empty($this->end_date) && $this->end_date < $now) {
            return false;
        }

        if (!empty($this->usage_limit) && $this->usage_limit > 0 && $this->usage_count >= $this->usage_limit) {
            return false;
        }

        return true;
    }

    /**
     * افزایش شمارنده مصرف
     */
    public function incrementUsage(int $couponId): bool
    {
        return (bool)$this->db->table(static::$table)
            ->where('id', '=', $couponId)
            ->increment('usage_count', 1);
    }

    /**
     * تغییر وضعیت فعال/غیرفعال
     */
    public function toggleActive(int $couponId): bool
    {
        $coupon = $this->find($couponId);
        if (!$coupon) {
            return false;
        }

        $couponVars = get_object_vars($coupon);
        $active = (bool)($couponVars['active'] ?? false);
        return (bool)$this->db->table(static::$table)
            ->where('id', '=', $couponId)
            ->update(['active' => $active ? 0 : 1]);
    }

    /**
     * جستجو در کوپن‌ها
     */
    /** @return list<object> */
    public function search(string $query, int $limit = 50): array
    {
        $query = \trim((string)$query);
        $escapedQuery = $this->escapeLikeValue($query, 50);
        $like = "%{$escapedQuery}%";

        return $this->db->table(static::$table)
            ->whereNull('deleted_at')
            ->where(function ($q) use ($like, $query) {
                $q->where('code', 'LIKE', $like)
                  ->orWhere('applicable_to', 'LIKE', $like)
                  ->orWhere('id', '=', $query);
            })
            ->orderBy('created_at', 'DESC')
            ->limit($limit)
            ->get();
    }

    /**
     * کوپن‌های منقضی شده
     */
    /** @return list<object> */
    public function getExpiredCoupons(int $limit = 100): array
    {
        $now = date('Y-m-d H:i:s');
        return $this->db->table(static::$table)
            ->whereNull('deleted_at')
            ->where(function ($q) use ($now) {
                $q->where(function ($sub) use ($now) {
                    $sub->whereNotNull('end_date')
                        ->where('end_date', '<', $now);
                })->orWhere(function ($sub) {
                    $sub->where('usage_limit', '>', 0)
                        ->whereRaw('usage_count >= usage_limit');
                });
            })
            ->orderBy('end_date', 'DESC')
            ->limit($limit)
            ->get();
    }

    /**
     * شمارش کل کوپن‌ها
     */
    public function count(): int
    {
        return $this->db->table(static::$table)
            ->whereNull('deleted_at')
            ->count();
    }

    /**
     * شمارش کوپن‌های فعال
     */
    public function countActive(): int
    {
        return $this->db->table(static::$table)
            ->where('active', '=', 1)
            ->whereNull('deleted_at')
            ->count();
    }
}