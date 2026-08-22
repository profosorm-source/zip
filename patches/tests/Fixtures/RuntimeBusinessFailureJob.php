<?php

declare(strict_types=1);

namespace Tests\Fixtures;

/**
 * جابی که یک خطای «تجاری» قطعی پرتاب می‌کند.
 *
 * نوع خطا از طریق داده‌ی جاب انتخاب می‌شود تا بتوان همه‌ی کلاس‌های
 * خانواده‌ی BusinessException را با یک فیکسچر پوشش داد.
 */
final class RuntimeBusinessFailureJob
{
    /** @param array<string,mixed> $data */
    public function handle(array $data): void
    {
        $kind = (string) ($data['business_kind'] ?? 'core');

        throw match ($kind) {
            'app' => new \App\Exceptions\BusinessException('خطای تجاری لایه App برای تست صف'),
            'domain' => new \Core\Exceptions\DomainException('نقض قاعده‌ی دامنه برای تست صف'),
            'insufficient_balance' => new \Core\Exceptions\InsufficientBalanceException('موجودی کافی نیست — تست صف'),
            'invalid_state' => new \Core\Exceptions\InvalidStateException('وضعیت نامعتبر — تست صف'),
            default => new \Core\Exceptions\BusinessException('خطای تجاری لایه Core برای تست صف'),
        };
    }
}
