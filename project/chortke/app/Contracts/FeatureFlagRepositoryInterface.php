<?php

declare(strict_types=1);

namespace App\Contracts;

/**
 * Feature Flag Repository Interface
 * 
 * تعریف قراردادی برای مدیریت Feature Flags
 * پشتیبانی می‌کند: targeting پیشرفته، زمان‌بندی، درصد کاربران، caching
 */
interface FeatureFlagRepositoryInterface
{
    /**
     * بررسی آیا یک فیچر برای کاربر خاص فعال است
     * شامل: targeting (role, country, plan, device, route)، زمان‌بندی، درصد کاربران
     */
    /** @param array<string, mixed>|null $context */
    public function isEnabled(string $name, ?int $userId = null, ?array $context = null): bool;

    /**
     * بررسی چند فیچر به صورت AND
     */
    /** @param list<string> $names @param array<string, mixed>|null $context */
    /** @param list<string> $names @param array<string, mixed>|null $context */
    /**
     * @param list<string> $names
     * @param array<string, mixed>|null $context
     */
    public function areEnabled(array $names, ?int $userId = null, ?array $context = null): bool;

    /**
     * دریافت تمام فیچرهای فعال برای کاربر
     */
    /**
     * @param array<string, mixed>|null $context
     * @return array<string, mixed>
     */
    /** @return list<object> */
    /**
     * @param array<string, mixed>|null $context
     * @return list<string>
     */
    public function getEnabled(?int $userId = null, ?array $context = null): array;

    /**
     * دریافت تمام فیچرها
     */
    /** @return list<\App\Models\FeatureFlag> */
    public function getAll(): array;

    /**
     * یافتن یک فیچر با نام
     */
    public function findByName(string $name): ?\App\Models\FeatureFlag;

    /**
     * دریافت مقدار پارامتر فیچر (برای اعداد dynamic)
     */
    public function getValue(string $name, mixed $default = null): mixed;

    /**
     * فعال کردن یک فیچر
     */
    public function enable(string $name): bool;

    /**
     * غیرفعال کردن یک فیچر
     */
    public function disable(string $name): bool;

    /**
     * پاک کردن cache فیچرها
     */
    public function clearCache(): void;
}
