<?php

declare(strict_types=1);

namespace App\Contracts\Sentry;

/**
 * SentryErrorRepositoryInterface
 *
 * مسئولیت: ذخیره و بازیابی داده‌های Error Monitoring
 *
 * Consumers: SentryErrorMonitor، DashboardService
 */
interface SentryErrorRepositoryInterface
{
    public function findExistingIssue(string $fingerprint, string $environment): ?object;
    /** @param array<int|string, mixed> $data */
    public function createIssue(array $data): int;
    public function updateIssueStats(int $issueId, string $level): void;
    /** @param array<int|string, mixed> $data */
    public function storeEventRecord(array $data): void;
    public function getErrorStats(string $period, string $environment): ?object;
    public function getUserData(int $userId): ?object;
    /** @return array<int, \stdClass> */
    public function getTrendingIssues(int $limit = 10): array;
    /** @return array<int, \stdClass> */
    public function getRecentSentryEvents(int $limit = 20): array;
    public function getDailySummary(): ?object;
    public function getPreviousDaySummary(): ?object;
    public function getUptimeStatus(int $minutes = 5): bool;
    /** @return array<int, \stdClass> */
    public function getErrorDistributionByLevel(int $hours = 24): array;
    /** @return array<int, \stdClass> */
    public function getErrorTimeSeries(int $periodHours, int $intervalMinutes): array;
    /** @param array<int|string, mixed> $filters */
    public function getIssuesCount(array $filters): int;
    /** @return array<int, \stdClass> */
    /** @param array<int|string, mixed> $filters */
    /** @return array<int, \stdClass> */
    /**
     * @param array<string, mixed> $filters
     * @return array<int, \stdClass>
     */
    public function getIssuesPaged(array $filters, int $limit, int $offset): array;
    public function getIssueWithEvents(int $id, int $limit = 50): ?object;
    public function resolveSentryIssue(int $issueId, ?int $userId, string $note = ''): bool;
    public function muteSentryIssue(int $issueId, int $days = 7): bool;
    /** @return array<int, \stdClass> */
    public function getErrorHistoricalData(int $days): array;
    /** @return array<int, \stdClass> */
    public function getErrorHotspots(int $days): array;
    /** @return array<int, \stdClass> */
    public function getTopErrorSources(int $limit = 15): array;
}
