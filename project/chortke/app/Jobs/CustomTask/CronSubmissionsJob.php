<?php

declare(strict_types=1);

namespace App\Jobs\CustomTask;

use App\Models\CustomTaskSubmissionModel;
use App\Models\Ads;
use Core\Database;
use App\Services\CustomTask\CustomTaskModerationService;
use App\Services\Settings\AppSettings;
use Core\Logger;
use Core\EventDispatcher;

class CronSubmissionsJob
{
    private CustomTaskSubmissionModel $submissionModel;
    private CustomTaskModerationService $moderationService;
    private AppSettings $appSettings;
    private Logger $logger;
    private Database $db;
    private Ads $taskModel;
    private \App\Services\Notification\NotificationTemplateService $templateService;
    private ?\App\Contracts\OutboxServiceInterface $outbox = null;
    public function __construct(
        CustomTaskSubmissionModel $submissionModel,
        CustomTaskModerationService $moderationService,
        AppSettings $appSettings,
        Logger $logger,
        Database $db,
        Ads $taskModel,
        \App\Services\Notification\NotificationTemplateService $templateService,
        ?\App\Contracts\OutboxServiceInterface $outbox = null
    ) {        $this->submissionModel = $submissionModel;
        $this->moderationService = $moderationService;
        $this->appSettings = $appSettings;
        $this->logger = $logger;
        $this->db = $db;
        $this->taskModel = $taskModel;
        $this->templateService = $templateService;
        $this->outbox = $outbox;
}

    public function autoApproveOldSubmissions(): int
    {
        $hours = int_value($this->appSettings->get('custom_task_auto_approve_hours', 48));
        $submissions = $this->submissionModel->getOldSubmissionsForAutoApproval($hours);

        $approved = 0;
        foreach ($submissions as $sub) {
            $result = $this->moderationService->approveSubmission($sub);
            if ($result['success']) {
                $this->submissionModel->submission_update((int)$sub->id, ['auto_approved_at' => date('Y-m-d H:i:s')]);
                $approved++;

                $tpl = $this->templateService->renderTemplate('submission_auto_approved', ['task_title' => $sub->task_title]);
                $this->outbox?->record('notification', $sub->worker_id, 'notification.requested', [
                    'user_id' => $sub->worker_id,
                    'type' => 'auto_approved',
                    'title' => $tpl['title'],
                    'message' => $tpl['message'],
                    'data' => [
                        'submission_id' => $sub->id,
                        'task_id' => $sub->task_id
                    ]
                ]);
            }
        }

        return $approved;
    }
    public function expireOldSubmissions(): int
    {
        $expired = 0;
        $submissions = $this->submissionModel->submission_getExpiredSubmissions();

        foreach ($submissions as $sub) {
            try {
                $this->db->beginTransaction();

                $this->submissionModel->submission_update($sub->id, [
                    'status' => 'expired',
                ]);

                $this->taskModel->decrementPendingCount($sub->task_id);

                $this->db->commit();
                $expired++;

            } catch (\Exception $e) {
                if ($this->db->inTransaction()) { $this->db->rollback(); }
                $this->logger->error('expire_submission_failed', [
                    'submission_id' => $sub->id,
                    'error' => $e->getMessage()
                ]);
            }
        }

        return $expired;
    }
}
