<?php

declare(strict_types=1);

namespace App\Contracts\Sentry;

/**
 * SentryPerformanceRepositoryInterface
 *
 * مسئولیت: ذخیره و بازیابی داده‌های Performance Monitoring
 *
 * Consumers: SentryPerformanceMonitor، DashboardService
 */
interface SentryPerformanceRepositoryInterface
{
    /** @param array<int|string, mixed> $data */
    public function storePerformanceTransaction(array $data): bool;
    public function getPerformanceAggregates(string $period): ?object;
    /** @return array<int, \stdClass> */
    public function getSlowestTransactions(int $limit = 10): array;
    public function getP95ResponseTime(int $minutes = 60): float;
    public function getHealthMetricsBundle(int $minutes = 60): object;
    public function getPerformanceStatsSummary(int $hours = 24): ?object;
    /** @return array<int, \stdClass> */
    public function getPerformanceTimeSeries(int $periodHours, int $intervalMinutes): array;
    /** @return array<int, \stdClass> */
    public function getTopSlowestEndpoints(int $limit = 10): array;
    /** @return array<int, \stdClass> */
    public function getPerformanceHistoricalData(int $days): array;
    public function getWeeklyPerformanceAvg(int $offset): float;
}
