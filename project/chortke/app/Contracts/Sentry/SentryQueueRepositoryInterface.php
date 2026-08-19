<?php

declare(strict_types=1);

namespace App\Contracts\Sentry;

/**
 * SentryQueueRepositoryInterface
 *
 * مسئولیت: داده‌های Failed Jobs و Outbox DLQ
 *
 * Consumers: DashboardService
 */
interface SentryQueueRepositoryInterface
{
    public function getFailedJobsSummary(): ?object;
    /** @return array<int, \stdClass> */
    public function getFailedJobQueueCounts(int $limit = 10): array;
    public function getFailedJobsCount(?string $queue = null): int;
    public function getOutboxDLQSummary(): ?object;
    /** @return array<int, \stdClass> */
    public function getOutboxDLQList(int $limit, int $offset): array;
    /** @return array<int, \stdClass> */
    public function getFailedJobsPaged(int $limit, int $offset, ?string $queue = null): array;
    public function getFailedJobById(int $id): ?object;
    public function retryFailedJob(int $id): bool;
    public function forgetFailedJob(int $id): bool;
}
