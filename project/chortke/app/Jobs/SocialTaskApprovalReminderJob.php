<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Contracts\NotificationServiceInterface;
use App\Contracts\LoggerInterface;
use Core\Database;

/**
 * SocialTaskApprovalReminderJob
 *
 * یادآوری به تبلیغ‌دهنده‌هایی که اجرای تسک‌های اجتماعی را در مهلت مقرر تأیید نکرده‌اند.
 * - اجراهایی که بیش از X ساعت در وضعیت submitted بوده‌اند Reminder می‌گیرند.
 * - پس از deadline بلند مدت، به صورت خودکار تأیید و پرداخت انجام می‌شود.
 */
class SocialTaskApprovalReminderJob
{
    // ساعت‌هایی که پس از آن reminder ارسال می‌شود
    private const REMINDER_THRESHOLD_HOURS = 24;
    // ساعت‌هایی که پس از آن auto-approve اعمال می‌شود
    private const AUTO_APPROVE_THRESHOLD_HOURS = 72;

    private Database $db;
    private NotificationServiceInterface $notificationService;
    private LoggerInterface $logger;
    private \App\Services\SocialTask\SocialTaskService $socialTaskService;

    public function __construct(
        Database $db,
        NotificationServiceInterface $notificationService,
        LoggerInterface $logger,
        \App\Services\SocialTask\SocialTaskService $socialTaskService
    ) {
        $this->db = $db;
        $this->notificationService = $notificationService;
        $this->logger = $logger;
        $this->socialTaskService = $socialTaskService;
    }

    /** @param array<string, mixed> $data */
    /** @param array<string, mixed> $data */
public function handle(array $data = []): void
    {
        $this->sendReminders();
        $this->autoApproveLongPending();
    }

    /**
     * ارسال یادآوری برای تبلیغ‌دهنده‌هایی که بین REMINDER_THRESHOLD و AUTO_APPROVE_THRESHOLD اجرا دارند
     */
    private function sendReminders(): void
    {
        try {
            $executions = $this->db->fetchAll(
                "SELECT ste.id, ste.ad_id, ste.executor_id, sa.user_id as advertiser_id, sa.title
                 FROM social_task_executions ste
                 JOIN ads sa ON sa.id = ste.ad_id
                 WHERE ste.status = 'submitted'
                   AND ste.updated_at < DATE_SUB(NOW(), INTERVAL ? HOUR)
                   AND ste.updated_at >= DATE_SUB(NOW(), INTERVAL ? HOUR)
                   AND ste.reminder_sent IS NULL",
                [self::REMINDER_THRESHOLD_HOURS, self::AUTO_APPROVE_THRESHOLD_HOURS]
            );

            foreach ($executions as $exec) {
                try {
                    $this->notificationService->send(
                        (int) $exec->advertiser_id,
                        'social_task_approval_reminder',
                        '⏰ یادآوری: تأیید تسک در انتظار شماست',
                        "اجرای تسک «{$exec->title}» برای بررسی و تأیید منتظر شماست. لطفاً هرچه زودتر بررسی کنید.",
                        ['execution_id' => $exec->id, 'ad_id' => $exec->ad_id],
                        null,
                        null,
                        'normal'
                    );

                    $this->db->prepare(
                        "UPDATE social_task_executions SET reminder_sent = NOW() WHERE id = ?"
                    )->execute([$exec->id]);
                } catch (\Throwable $e) {
                    $this->logger->error('social_task.reminder_failed', [
                        'execution_id' => $exec->id,
                        'error'        => $e->getMessage(),
                    ]);
                }
            }

            $this->logger->info('social_task.reminders_sent', ['count' => count($executions)]);
        } catch (\Throwable $e) {
            $this->logger->error('social_task.reminder_job_failed', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Auto-approve اجراهایی که بیش از AUTO_APPROVE_THRESHOLD_HOURS در انتظار تأیید بوده‌اند
     */
    private function autoApproveLongPending(): void
    {
        try {
            $executions = $this->db->fetchAll(
                "SELECT ste.id, ste.ad_id, ste.executor_id, sa.user_id as advertiser_id, sa.title
                 FROM social_task_executions ste
                 JOIN ads sa ON sa.id = ste.ad_id
                 WHERE ste.status = 'submitted'
                   AND ste.updated_at < DATE_SUB(NOW(), INTERVAL ? HOUR)
                 ORDER BY ste.updated_at ASC
                 LIMIT 100",
                [self::AUTO_APPROVE_THRESHOLD_HOURS]
            );

            $approved = 0;
            $service = $this->socialTaskService;
            foreach ($executions as $exec) {
                $result = $service->advertiserApprove((int)$exec->advertiser_id, (int)$exec->id);
                if (!empty($result['success'])) {
                    $this->db->query("UPDATE social_task_executions SET auto_approved_at = COALESCE(auto_approved_at, NOW()) WHERE id = ?", [(int)$exec->id]);
                    $approved++;
                } else {
                    $this->logger->warning('social_task.auto_approve_skipped', [
                        'execution_id' => $exec->id,
                        'reason' => $result['message'] ?? 'unknown',
                    ]);
                }
            }

            if ($approved > 0) {
                $this->logger->info('social_task.auto_approved', ['count' => $approved]);
            }
        } catch (\Throwable $e) {
            $this->logger->error('social_task.auto_approve_failed', ['error' => $e->getMessage()]);
        }
    }
}