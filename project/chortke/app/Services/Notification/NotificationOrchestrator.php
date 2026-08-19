<?php

declare(strict_types=1);

namespace App\Services\Notification;

use App\Contracts\LoggerInterface;
use App\Contracts\CacheInterface;
use Core\CircuitBreaker;

/**
 * NotificationOrchestrator
 *
 * مرکز ثقل و مدیریت مشترک فرآیندهای جانبی نوتیفیکیشن‌ها (شامل Cache، Logger و CircuitBreaker).
 * این کلاس برای حذف لاجیک‌های تکراری در آداپتورهای مختلف نوتیفیکیشن پیاده‌سازی شده است.
 */
class NotificationOrchestrator
{
    private LoggerInterface $logger;
    private CacheInterface $cache;
    private CircuitBreaker $circuitBreaker;

    public function __construct(
        LoggerInterface $logger,
        CacheInterface $cache,
        CircuitBreaker $circuitBreaker
    ) {
        $this->logger = $logger;
        $this->cache = $cache;
        $this->circuitBreaker = $circuitBreaker;
    }

    public function logger(): LoggerInterface
    {
        return $this->logger;
    }

    public function cache(): CacheInterface
    {
        return $this->cache;
    }

    public function circuitBreaker(): CircuitBreaker
    {
        return $this->circuitBreaker;
    }

    /**
     * ذخیره یا دریافت داده از کش به صورت متمرکز
     */
    public function getCached(string $key): mixed
    {
        return $this->cache->get($key);
    }

    public function setCached(string $key, mixed $value, int $ttlMinutes = 1440): void
    {
        $this->cache->put($key, $value, $ttlMinutes);
    }

    public function forgetCached(string $key): void
    {
        $this->cache->forget($key);
    }

    /**
     * ثبت لاگ‌ها به صورت متمرکز و استاندارد
     */
    /** @param array<string, mixed> $context */
    public function log(string $level, string $message, array $context = []): void
    {
        $this->logger->log($level, $message, $context);
    }

    /** @param array<string, mixed> $context */
    public function info(string $message, array $context = []): void
    {
        $this->logger->info($message, $context);
    }

    /** @param array<string, mixed> $context */
    public function warning(string $message, array $context = []): void
    {
        $this->logger->warning($message, $context);
    }

    /** @param array<string, mixed> $context */
    public function error(string $message, array $context = []): void
    {
        $this->logger->error($message, $context);
    }

    /**
     * اجرای امن عملیات تحت کنترل Circuit Breaker به صورت متمرکز
     */
    public function executeWithBreaker(string $providerName, callable $operation, ?callable $fallback = null): mixed
    {
        try {
            return $this->circuitBreaker->call($providerName, $operation);
        } catch (\Throwable $e) {
            \App\Services\Sentry\SentryExceptionHandler::captureException($e, null, ['operation' => 'app.Services.Notification.NotificationOrchestrator.executeWithBreaker']);
            if ($fallback !== null) {
                return $fallback($e);
            }
            throw $e;
        }
    }
}
