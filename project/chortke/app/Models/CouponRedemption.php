<?php

declare(strict_types=1);

namespace App\Models;

use Core\Model;

/**
 * CouponRedemption Model
 */
class CouponRedemption extends Model
{
    protected static string $table = 'coupon_redemptions';
    
    protected array $fillable = [
        'coupon_id', 'user_id', 'original_amount', 'discount_amount',
        'final_amount', 'currency', 'entity_type', 'entity_id', 'ip_address'
    ];

    /**
     * تمام مصارف با سازگاری با Model پایه
     */
    /** @return list<object> */
    public function getAll(int $limit = 100, int $offset = 0): array
    {
        return $this->db->table(static::$table . ' as cr')
            ->select('cr.*', 'c.code', 'u.username')
            ->leftJoin('coupons as c', 'cr.coupon_id', '=', 'c.id')
            ->leftJoin('users as u', 'cr.user_id', '=', 'u.id')
            ->orderBy('cr.created_at', 'DESC')
            ->limit($limit)
            ->offset($offset)
            ->get();
    }

    /**
     * بررسی مصرف قبلی کاربر با قفل بدبینانه (برای استفاده در تراکنش)
     */
    public function hasUserUsedCouponForUpdate(int $userId, int $couponId): bool
    {
        return $this->db->query(
            "SELECT 1 FROM `" . static::$table . "` WHERE user_id = ? AND coupon_id = ? FOR UPDATE",
            [$userId, $couponId]
        )->fetch() !== false;
    }

    /**
     * بررسی مصرف قبلی کاربر برای یک کوپن خاص
     */
    public function hasUserUsedCoupon(int $userId, int $couponId): bool
    {
        return $this->db->table(static::$table)
            ->where('user_id', '=', $userId)
            ->where('coupon_id', '=', $couponId)
            ->exists();
    }

    /**
     * تاریخچه مصرف یک کوپن
     */
    /** @return list<object> */
    public function getCouponHistory(int $couponId, int $limit = 50): array
    {
        return $this->db->table(static::$table . ' as cr')
            ->select('cr.*', 'u.username', 'u.email')
            ->leftJoin('users as u', 'cr.user_id', '=', 'u.id')
            ->where('cr.coupon_id', '=', $couponId)
            ->orderBy('cr.created_at', 'DESC')
            ->limit($limit)
            ->get();
    }

    /**
     * تاریخچه مصرف کوپن‌های یک کاربر
     */
    /** @return list<object> */
    public function getUserHistory(int $userId, int $limit = 20): array
    {
        return $this->db->table(static::$table . ' as cr')
            ->select('cr.*', 'c.code', 'c.type', 'c.value')
            ->leftJoin('coupons as c', 'cr.coupon_id', '=', 'c.id')
            ->where('cr.user_id', '=', $userId)
            ->orderBy('cr.created_at', 'DESC')
            ->limit($limit)
            ->get();
    }

    /**
     * آمار مصرف کوپن
     */
    public function getCouponStats(int $couponId): ?\stdClass
    {
        return $this->db->table(static::$table)
            ->selectRaw('COUNT(*) as total_uses')
            ->selectRaw('SUM(discount_amount) as total_discount')
            ->selectRaw('AVG(discount_amount) as avg_discount')
            ->selectRaw('MAX(discount_amount) as max_discount')
            ->selectRaw('MIN(discount_amount) as min_discount')
            ->where('coupon_id', '=', $couponId)
            ->first();
    }

    /**
     * مصارف امروز
     */
    /** @return list<object> */
    public function getTodayRedemptions(int $limit = 100): array
    {
        return $this->db->table(static::$table . ' as cr')
            ->select('cr.*', 'c.code', 'u.username')
            ->leftJoin('coupons as c', 'cr.coupon_id', '=', 'c.id')
            ->leftJoin('users as u', 'cr.user_id', '=', 'u.id')
            ->whereRaw('DATE(cr.created_at) = CURDATE()')
            ->orderBy('cr.created_at', 'DESC')
            ->limit($limit)
            ->get();
    }

    /**
     * آمار کلی مصارف
     */
    public function getOverallStats(): ?\stdClass
    {
        return $this->db->table(static::$table)
            ->selectRaw('COUNT(*) as total_redemptions')
            ->selectRaw('COUNT(DISTINCT user_id) as unique_users')
            ->selectRaw('COUNT(DISTINCT coupon_id) as used_coupons')
            ->selectRaw('SUM(discount_amount) as total_discount_given')
            ->selectRaw('AVG(discount_amount) as avg_discount_per_use')
            ->first();
    }

    /**
     * مصارف بر اساس نوع موجودیت
     */
    /** @return list<object> */
    public function getRedemptionsByEntityType(string $entityType, int $limit = 100): array
    {
        return $this->db->table(static::$table . ' as cr')
            ->select('cr.*', 'c.code', 'u.username')
            ->leftJoin('coupons as c', 'cr.coupon_id', '=', 'c.id')
            ->leftJoin('users as u', 'cr.user_id', '=', 'u.id')
            ->where('cr.entity_type', '=', $entityType)
            ->orderBy('cr.created_at', 'DESC')
            ->limit($limit)
            ->get();
    }

    /**
     * مصارف بر اساس واحد پول
     */
    /** @return list<object> */
    public function getRedemptionsByCurrency(string $currency, int $limit = 100): array
    {
        return $this->db->table(static::$table . ' as cr')
            ->select('cr.*', 'c.code', 'u.username')
            ->leftJoin('coupons as c', 'cr.coupon_id', '=', 'c.id')
            ->leftJoin('users as u', 'cr.user_id', '=', 'u.id')
            ->where('cr.currency', '=', $currency)
            ->orderBy('cr.created_at', 'DESC')
            ->limit($limit)
            ->get();
    }

    /**
     * شمارش کل مصارف
     */
    public function count(): int
    {
        return $this->db->table(static::$table)->count();
    }

    /**
     * شمارش مصارف امروز
     */
    public function countToday(): int
    {
        return $this->db->table(static::$table)
            ->whereRaw('DATE(created_at) = CURDATE()')
            ->count();
    }
}