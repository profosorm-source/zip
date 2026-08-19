<?php

declare(strict_types=1);

namespace App\Contracts;

interface NotificationServiceInterface
{
    /** @param array<string, mixed>|null $data */
    public function send(
        int     $userId,
        string  $type,
        string  $title,
        string  $message,
        ?array  $data        = null,
        ?string $actionUrl   = null,
        ?string $actionText  = null,
        string  $priority    = 'normal',
        ?string $expiresAt   = null,
        ?string $imageUrl    = null,
        ?string $groupKey    = null,
        ?string $scheduledAt = null
    ): ?int;

    /** @param array<string, mixed>|null $data */
    public function sendToAdmins(
        string $type,
        string $title,
        string $message,
        ?array $data = null,
        string $priority = 'normal'
    ): int;

    /** @param array<string, mixed>|null $data */
    public function dispatch(
        string $channel,
        int $userId,
        string $title,
        string $message,
        ?array $data = null,
        ?string $imageUrl = null,
        ?string $actionUrl = null,
        ?string $actionText = null,
        string $priority = 'normal'
    ): bool;

    public function depositSuccess(int $userId, string $amount, string $currency): ?int;

    /**
     * آمار کلی notification analytics
     * @return array<string, mixed>
     */
    public function getAnalyticsOverview(int $days = 30): array;

    /**
     * آمار funnel نوتیفیکیشن‌ها
     * @return array<string, mixed>
     */
    public function getAnalyticsFunnelStats(int $days = 30): array;
}
