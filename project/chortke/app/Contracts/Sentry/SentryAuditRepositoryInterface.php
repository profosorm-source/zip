<?php

declare(strict_types=1);

namespace App\Contracts\Sentry;

/**
 * SentryAuditRepositoryInterface
 *
 * مسئولیت: بازیابی داده‌های Audit Trail از دیتابیس
 *
 * Consumers: AdvancedAuditTrail
 */
interface SentryAuditRepositoryInterface
{
    /** @param array<int|string, mixed> $params */
    public function getAuditCount(string $where, array $params): int;
    /** @return array<int, \stdClass> */
    /** @param array<int|string, mixed> $params */
    /** @return array<int, \stdClass> */
    /**
     * @param array<int|string, mixed> $params
     * @return array<int, \stdClass>
     */
    public function searchAuditRecords(string $where, array $params, int $limit, int $offset): array;
    /** @return array<int, \stdClass> */
    public function getAuditEventsByCategory(string $start, string $end): array;
    /** @return array<int, \stdClass> */
    public function getAuditUserActivity(string $start, string $end): array;
    /** @return array<int, \stdClass> */
    public function getAuditAccessPatterns(string $start, string $end): array;
    /** @return array<int, \stdClass> */
    public function getAuditFailedOperations(string $start, string $end): array;
    public function deleteOldAuditRecords(string $cutoff): int;
    /** @return array<int, \stdClass> */
    public function getOldAuditRecords(string $cutoff): array;
    public function getAuditRecordById(int $id): ?object;
    /** @return array<int, \stdClass> */
    public function getActivityTimeline(?int $userId, int $days): array;
    public function getAuditReportSummary(string $start, string $end): ?object;
    /** @return array<int, \stdClass> */
    /** @param array<int|string, mixed> $critical */
    /** @return array<int, \stdClass> */
    /**
     * @param array<int|string, mixed> $critical
     * @return array<int, \stdClass>
     */
    public function getAuditCriticalEvents(array $critical, string $start, string $end): array;
}
