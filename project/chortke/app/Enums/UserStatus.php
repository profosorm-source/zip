<?php
namespace App\Enums;

/**
 * User Status Enum
 */
class UserStatus
{
    const INACTIVE = 'inactive';
    const ACTIVE = 'active';
    const SUSPENDED = 'suspended';
    const BANNED = 'banned';
    
    /**
     * دریافت تمام وضعیت‌ها
     */
    /** @return list<string> */
    public static function all(): array
    {
        return [
            self::INACTIVE,
            self::ACTIVE,
            self::SUSPENDED,
            self::BANNED,
        ];
    }
    
    /**
     * دریافت برچسب فارسی
     */
    public static function label(string $status): string
    {
        $labels = [
            self::INACTIVE => 'غیرفعال',
            self::ACTIVE => 'فعال',
            self::SUSPENDED => 'تعلیق شده',
            self::BANNED => 'مسدود شده',
        ];
        
        return $labels[$status] ?? 'نامشخص';
    }
    
    /**
     * دریافت رنگ Badge
     */
    public static function color(string $status): string
    {
        $colors = [
            self::INACTIVE => 'secondary',
            self::ACTIVE => 'success',
            self::SUSPENDED => 'warning',
            self::BANNED => 'danger',
        ];
        
        return $colors[$status] ?? 'secondary';
    }
    
    /**
     * بررسی معتبر بودن
     */
    public static function isValid(string $status): bool
    {
        return in_array($status, self::all());
    }
}