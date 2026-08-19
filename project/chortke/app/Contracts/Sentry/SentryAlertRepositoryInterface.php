<?php

declare(strict_types=1);

namespace App\Contracts\Sentry;

/**
 * SentryAlertRepositoryInterface
 *
 * مسئولیت: ذخیره و بازیابی داده‌های Alerting & Rules
 *
 * Consumers: AlertDispatcher، AlertRulesEngine
 */
interface SentryAlertRepositoryInterface
{
    public function getLastAlert(string $fingerprint, string $severity): ?object;
    /** @param array<int|string, mixed> $data */
    public function storeAlert(array $data): int;
    /** @return array<int, \stdClass> */
    public function getActiveChannels(string $severity): array;
    public function recordNotificationHistory(int $channelId, int $alertId, string $status): void;
    public function markAlertAsSent(int $alertId): void;
    /** @return array<int, \stdClass> */
    public function getActiveAlerts(): array;
    /** @return array<int, \stdClass> */
    public function getActiveRules(): array;
    public function getRuleStatus(int $ruleId): ?object;
    public function updateRuleLastTriggered(int $ruleId): void;
    public function getMetricValue(string $type, int $minutes): float;
    /** @return array<int, \stdClass> */
    public function getNotificationChannelsForSettings(): array;
    /** @return array<int, \stdClass> */
    public function getAlertRulesForSettings(): array;
    /** @return array<int, \stdClass> */
    public function getActiveAlertsForDashboard(): array;
}
