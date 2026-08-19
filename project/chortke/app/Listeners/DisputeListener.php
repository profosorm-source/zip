<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\DisputeOpenedEvent;
use App\Services\Notification\NotificationService;
use App\Services\AuditTrail;
use App\Services\EscrowService;
use App\Contracts\LoggerInterface;
use Core\Container;
use Core\Database;

/**
 * DisputeListener - Handles dispute creation events
 * 
 * Decouples dispute management from:
 * - Admin notifications
 * - Fund freezing logic
 * - Audit logging
 * - Escalation workflows
 */
class DisputeListener
{
    private Container $container;
    private LoggerInterface $logger;

    public function __construct(Container $container, LoggerInterface $logger) {
        $this->container = $container;
        $this->logger = $logger;
    }

    /**
     * Handle dispute.opened event
     * 
     * Freezes escrow funds
     * Notifies admins
     * Logs to audit trail
     * Triggers escalation workflow
     */
    public function handle(DisputeOpenedEvent $event): void
    {
        try {
            $rawData = $event->getData();
            $data = is_array($rawData) ? $rawData : [];
            $disputeId = int_value($data['dispute_id'] ?? 0);
            $escrowId = int_value($data['escrow_id'] ?? 0);
            $userIdValue = $data['user_id'] ?? null;
            $userId = is_numeric($userIdValue) ? int_value($userIdValue) : null;
            $reason = str_value($data['reason'] ?? '');

            if (!$disputeId || !$escrowId) {
                $this->logger->warning('dispute.opened event missing required data', $data);
                return;
            }

            // The escrow state transition is already performed by the originating service.
            // This listener should only handle side effects, not re-run the dispute transition.
            // Log to audit trail
            $auditTrail = $this->container->make(AuditTrail::class);
            $auditTrail->logEvent([
                'user_id' => $userId,
                'action' => 'dispute.opened',
                'resource_id' => $disputeId,
                'metadata' => [
                    'escrow_id' => $escrowId,
                    'reason' => $reason
                ]
            ]);

            // Notify admins
            $this->notifyAdmins(int_value($disputeId), int_value($escrowId), $reason, $userId);

            // Mark in database for admin dashboard
            $db = $this->container->make(Database::class);
            $db->query(
                'INSERT INTO admin_alerts (alert_type, resource_id, message, created_at) 
                 VALUES (?, ?, ?, NOW())',
                ['dispute_opened', $disputeId, "نزاع جدید #$disputeId برای escrow #$escrowId: $reason"]
            );

        } catch (\Throwable $e) {
            \App\Services\Sentry\SentryExceptionHandler::captureException($e, null, ['operation' => 'app.Listeners.DisputeListener.handle']);
            $this->logger->error('dispute.opened listener failed', [
                'error' => $e->getMessage(),
                'event' => $event->getData()
            ]);
        }
    }

    /**
     * Send notifications to admin users
     */
    private function notifyAdmins(int $disputeId, int $escrowId, string $reason, ?int $userId): void
    {
        try {
            $db = $this->container->make(Database::class);
            
            // Get all admin users
            $admins = $db->query(
                'SELECT id FROM users WHERE role = ?',
                ['admin']
            )->fetchAll();

            $notificationService = $this->container->make(NotificationService::class);

            foreach ($admins as $admin) {
                $notificationService->send(
                    $admin->id,
                    'dispute.admin_alert',
                    '⚠️ نزاع جدید',
                    "نزاع #$disputeId برای کاربر #$userId در escrow #$escrowId\nدلیل: $reason",
                    [
                        'dispute_id' => $disputeId,
                        'escrow_id' => $escrowId,
                        'user_id' => $userId
                    ]
                );
            }
        } catch (\Throwable $e) {
            \App\Services\Sentry\SentryExceptionHandler::captureException($e, null, ['operation' => 'app.Listeners.DisputeListener.notifyAdmins']);
            $this->logger->error('Failed to notify admins about dispute', ['error' => $e->getMessage()]);
        }
    }
}
