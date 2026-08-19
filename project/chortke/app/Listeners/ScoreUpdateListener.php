<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\ScoreUpdatedEvent;
use App\Services\Notification\NotificationService;
use App\Services\AuditTrail;
use App\Services\User\UserLevelService;
use App\Contracts\LoggerInterface;
use Core\Container;

/**
 * ScoreUpdateListener - Handles score change events
 * 
 * Decouples score updates from:
 * - Level upgrade checks
 * - Threshold alerts
 * - User notifications
 * - Audit logging
 */
class ScoreUpdateListener
{
    private Container $container;
    private LoggerInterface $logger;

    public function __construct(Container $container, LoggerInterface $logger) {
        $this->container = $container;
        $this->logger = $logger;
    }

    /**
     * Handle score.updated event
     * 
     * Checks for level upgrades
     * Sends threshold alerts
     * Logs changes
     */
    public function handle(ScoreUpdatedEvent $event): void
    {
        try {
            $rawData = $event->getData();
            $data = is_array($rawData) ? $rawData : [];
            $userId = isset($data['user_id']) ? int_value($data['user_id']) : null;
            $oldScore = float_value($data['old_score'] ?? 0);
            $newScore = float_value($data['new_score'] ?? 0);
            $reason = $data['reason'] ?? '';

            if (!$userId) {
                $this->logger->warning('score.updated event missing user_id', $data);
                return;
            }

            // Log to audit trail
            $auditTrail = $this->container->make(AuditTrail::class);
            $auditTrail->logEvent([
                'user_id' => $userId,
                'action' => 'score.updated',
                'metadata' => [
                    'old_score' => $oldScore,
                    'new_score' => $newScore,
                    'change' => $newScore - $oldScore,
                    'reason' => $reason
                ]
            ]);

            // Check for level upgrade
            $this->checkLevelUpgrade($userId, $oldScore, $newScore);

            // Check for threshold alerts
            $this->checkThresholdAlerts($userId, $newScore);

        } catch (\Throwable $e) {
            \App\Services\Sentry\SentryExceptionHandler::captureException($e, null, ['operation' => 'app.Listeners.ScoreUpdateListener.handle']);
            $this->logger->error('score.updated listener failed', [
                'error' => $e->getMessage(),
                'event' => $event->getData()
            ]);
        }
    }

    /**
     * Check if user qualifies for level upgrade
     */
    private function checkLevelUpgrade(int $userId, float $oldScore, float $newScore): void
    {
        try {
            $levelService = $this->container->make(UserLevelService::class);
            $levelService->checkUpgrade($userId);
        } catch (\Throwable $e) {
            \App\Services\Sentry\SentryExceptionHandler::captureException($e, null, ['operation' => 'app.Listeners.ScoreUpdateListener.checkLevelUpgrade']);
            $this->logger->warning('Level upgrade check failed', [
                'user_id' => $userId,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Send alerts for score thresholds (e.g., high risk score)
     */
    private function checkThresholdAlerts(int $userId, float $score): void
    {
        try {
            $notificationService = $this->container->make(NotificationService::class);

            // High risk score threshold
            if ($score >= 80) {
                $notificationService->send(
                    $userId,
                    'score.high_risk_alert',
                    '⚠️ هشدار ریسک بالا',
                    'نمره ریسک شما بالا است. فعالیت‌های مریب را دوباره بررسی کنید.',
                    ['score' => $score]
                );
            }

            // Low trust score threshold
            if ($score <= 30) {
                $notificationService->send(
                    $userId,
                    'score.low_trust_alert',
                    'نمره اعتماد پایین',
                    'نمره اعتماد شما کاهش یافته است. فعالیت‌های مثبت را انجام دهید.',
                    ['score' => $score]
                );
            }
        } catch (\Throwable $e) {
            \App\Services\Sentry\SentryExceptionHandler::captureException($e, null, ['operation' => 'app.Listeners.ScoreUpdateListener.checkThresholdAlerts']);
            $this->logger->warning('Threshold alert failed', [
                'user_id' => $userId,
                'error' => $e->getMessage()
            ]);
        }
    }
}
