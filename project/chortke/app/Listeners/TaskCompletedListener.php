<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\TaskCompletedEvent;
use App\Services\Gamification\XpService;
use App\Services\Notification\NotificationService;
use App\Services\ScoreService;
use App\Services\AuditTrail;
use App\Contracts\LoggerInterface;
use App\Enums\ModuleContext;
use App\Services\OutboxService;
use Core\Container;

/**
 * TaskCompletedListener - Handles custom task completion events
 * 
 * Decouples task service from:
 * - XP award system
 * - Trust score updates
 * - User notifications
 * - Audit logging
 */
class TaskCompletedListener
{
    private Container $container;
    private LoggerInterface $logger;

    public function __construct(Container $container, LoggerInterface $logger) {
        $this->container = $container;
        $this->logger = $logger;
    }

    /**
     * Handle task.completed event
     * 
     * Awards XP points
     * Updates trust score
     * Sends notification
     * Logs to audit trail
     */
    public function handle(TaskCompletedEvent $event): void
    {
        try {
            $rawData = $event->getData();
            $data = is_array($rawData) ? $rawData : [];
            $userId = int_value($data['user_id'] ?? 0);
            $taskId = int_value($data['task_id'] ?? 0);
            $title = str_value($data['title'] ?? 'تسک');
            $xpReward = int_value($data['xp_reward'] ?? 10);

            if (!$userId || !$taskId) {
                $this->logger->warning('task.completed event missing required data', $data);
                return;
            }

            // Award XP points
            $userIdInt = $userId;
            $xpService = $this->container->make(XpService::class);
            $xpService->award($userIdInt, ModuleContext::CUSTOM_TASKS, (float)$xpReward, "task_$taskId");

            // Update trust score
            $scoreService = $this->container->make(ScoreService::class);
            $scoreService->applyDelta('user', $userIdInt, 'score_trust', 3, 'task_completed');

            // Log to audit trail
            $auditTrail = $this->container->make(AuditTrail::class);
            $auditTrail->logEvent([
                'user_id' => $userId,
                'action' => 'task.completed',
                'resource_id' => $taskId,
                'metadata' => [
                    'title' => $title,
                    'xp_awarded' => $xpReward
                ]
            ]);

            // Send notification asynchronously via OutboxService
            $outboxService = $this->container->make(OutboxService::class);
            $outboxService->record(
                'notification',
                $userId . '_task_completed',
                'send_notification',
                [
                    'notification' => [
                        'method' => 'send',
                        'args' => [
                            $userId,
                            'task.completed',
                            'تسک تکمیل شد',
                            "تسک \"$title\" تکمیل شد. $xpReward XP کسب کردید!",
                            ['task_id' => $taskId, 'xp_reward' => $xpReward]
                        ]
                    ]
                ]
            );

        } catch (\Throwable $e) {
            \App\Services\Sentry\SentryExceptionHandler::captureException($e, null, ['operation' => 'app.Listeners.TaskCompletedListener.handle']);
            $this->logger->error('task.completed listener failed', [
                'error' => $e->getMessage(),
                'event' => $event->getData()
            ]);
        }
    }
}
