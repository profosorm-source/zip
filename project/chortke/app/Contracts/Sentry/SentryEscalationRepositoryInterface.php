<?php

declare(strict_types=1);

namespace App\Contracts\Sentry;

/**
 * SentryEscalationRepositoryInterface
 *
 * مسئولیت: مدیریت Escalation و Auto-resolve
 *
 * Consumers: EscalationManager
 */
interface SentryEscalationRepositoryInterface
{
    /** @return array<int, \stdClass> */
    public function getPendingEscalations(): array;
    public function escalateIssue(int $id, string $newLevel, string $oldLevel): void;
    public function escalateAlert(int $id, string $new, string $old): void;
    public function acknowledgeAlert(int $id, ?int $userId, ?string $note): bool;
    public function autoResolveErrorAlerts(): int;
    public function getEscalationStatistics(): object;
}
