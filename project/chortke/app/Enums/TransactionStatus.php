<?php
namespace App\Enums;

/**
 * Transaction Status Enum
 */
class TransactionStatus
{
    const PENDING = 'pending';
    const PROCESSING = 'processing';
    const COMPLETED = 'completed';
    const FAILED = 'failed';
    const CANCELLED = 'cancelled';
    const REFUNDED = 'refunded';
    
    /** @return list<string> */
    public static function all(): array
    {
        return [
            self::PENDING,
            self::PROCESSING,
            self::COMPLETED,
            self::FAILED,
            self::CANCELLED,
            self::REFUNDED,
        ];
    }
    
    public static function label(string $status): string
    {
        $labels = [
            self::PENDING => 'در انتظار',
            self::PROCESSING => 'در حال پردازش',
            self::COMPLETED => 'تکمیل شده',
            self::FAILED => 'ناموفق',
            self::CANCELLED => 'لغو شده',
            self::REFUNDED => 'بازپرداخت شده',
        ];
        
        return $labels[$status] ?? 'نامشخص';
    }
    
    public static function color(string $status): string
    {
        $colors = [
            self::PENDING => 'warning',
            self::PROCESSING => 'info',
            self::COMPLETED => 'success',
            self::FAILED => 'danger',
            self::CANCELLED => 'secondary',
            self::REFUNDED => 'primary',
        ];
        
        return $colors[$status] ?? 'secondary';
    }
    
    /**
     * وضعیت‌های نهایی که دیگر تغییر نمی‌کنند
     */
    public static function isFinal(string $status): bool
    {
        return in_array($status, [
            self::COMPLETED,
            self::FAILED,
            self::CANCELLED,
            self::REFUNDED,
        ]);
    }
}