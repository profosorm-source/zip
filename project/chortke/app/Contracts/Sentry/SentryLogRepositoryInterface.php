<?php

declare(strict_types=1);

namespace App\Contracts\Sentry;

/**
 * SentryLogRepositoryInterface
 *
 * مسئولیت: داده‌های Error Logs (جدول error_logs) — برای LogController
 *
 * Consumers: LogController، SentryAdminController
 */
interface SentryLogRepositoryInterface
{
    public function checkTableExists(string $table): bool;
    public function countTableRows(string $table, string $where = '1=1'): int;
    public function avgTableColumn(string $table, string $column): float;
    /** @return array<int, \stdClass> */
    public function getErrorLogs(int $perPage, int $offset): array;
    public function findErrorById(int $id): ?object;
    /** @return array<int, \stdClass> */
    public function getSimilarErrors(string $message): array;
    /** @return array<int, \stdClass> */
    public function getTopErrorLogs(): array;
    public function getLastCronRun(): ?object;
}
