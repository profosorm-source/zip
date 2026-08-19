<?php

declare(strict_types=1);

namespace App\Contracts;

/**
 * قرارداد گزارش خطای دیتابیس
 *
 * این interface وابستگی core/Database.php به لایه App را معکوس می‌کند (DIP).
 * هر سیستم مانیتورینگ (Sentry, Datadog, ...) می‌تواند این قرارداد را پیاده‌سازی کند.
 */
interface DatabaseErrorReporter
{
    /**
     * ثبت Exception دیتابیس (مثل query failure, deadlock)
     *
     * @param \Throwable $exception
     * @param array<string, mixed> $context اطلاعات اضافی (sql, params, ...)
     * @param string $level سطح خطا (error, critical, ...)
     * @param int|null $userId شناسه کاربر (اختیاری)
     * @return string|null شناسه خطا (مثل Sentry event ID)
     */
    public function captureException(\Throwable $exception, array $context = [], string $level = 'error', ?int $userId = null): ?string;

    /**
     * ثبت پیام دیتابیس (مثل slow query warning)
     *
     * @param string $message
     * @param string $level سطح (info, warning, error)
     * @param array<string, mixed> $context اطلاعات اضافی
     * @param int|null $userId شناسه کاربر (اختیاری)
     * @return string|null
     */
    public function captureMessage(string $message, string $level = 'info', array $context = [], ?int $userId = null): ?string;
}
