<?php

declare(strict_types=1);

namespace App\Jobs\CustomTask;

use App\Models\Ads;
use App\Models\CustomTaskSubmissionModel;
use App\Services\Interaction\RatingService;
use App\Services\Settings\AppSettings;
use Core\Database;
use Core\Logger;
use Core\EventDispatcher;

class RateSubmissionJob
{
    private Ads $taskModel;
    private CustomTaskSubmissionModel $submissionModel;
    private RatingService $ratingService;
    private AppSettings $appSettings;
    private Database $db;
    private Logger $logger;
    private \App\Services\Notification\NotificationTemplateService $templateService;
    private ?\App\Contracts\OutboxServiceInterface $outbox = null;
    public function __construct(
        Ads $taskModel,
        CustomTaskSubmissionModel $submissionModel,
        RatingService $ratingService,
        AppSettings $appSettings,
        Database $db,
        Logger $logger,
        \App\Services\Notification\NotificationTemplateService $templateService,
        ?\App\Contracts\OutboxServiceInterface $outbox = null
    ) {        $this->taskModel = $taskModel;
        $this->submissionModel = $submissionModel;
        $this->ratingService = $ratingService;
        $this->appSettings = $appSettings;
        $this->db = $db;
        $this->logger = $logger;
        $this->templateService = $templateService;
        $this->outbox = $outbox;
}

/**
 * @param array<string, mixed> $ratingData
 * @return array<string, mixed>
 */
public function handle(int $submissionId, int $raterId, array $ratingData): array
    {
        if ($raterId <= 0) {
            return ['success' => false, 'message' => 'شناسه ارزیاب نامعتبر است'];
        }

        if (!$this->appSettings->get('custom_task_rating_enabled', 1)) {
            return ['success' => false, 'message' => 'سیستم امتیازدهی غیرفعال است.'];
        }

        $submission = $this->submissionModel->submission_find($submissionId);

        if (!$submission) {
            return ['success' => false, 'message' => 'یافت نشد.'];
        }

        if ($submission->status !== 'approved') {
            return ['success' => false, 'message' => 'فقط می‌توانید به submission های تایید شده امتیاز دهید.'];
        }

        $rating = int_value($ratingData['rating'] ?? 0);
        if ($rating < 1 || $rating > 5) {
            return ['success' => false, 'message' => 'امتیاز باید بین 1 تا 5 باشد.'];
        }

        $reviewText = trim(str_value($ratingData['review_text'] ?? ''));
        $minLength = int_value($this->appSettings->get('custom_task_min_rating_text_length', 20));
        
        if (!empty($reviewText) && mb_strlen((string)$reviewText) < $minLength) {
            return ['success' => false, 'message' => "متن نظر باید حداقل {$minLength} کاراکتر باشد."];
        }

        $task = $this->taskModel->find(int_value($submission->task_id ?? 0));
        if ($task === null) {
            return ['success' => false, 'message' => 'وظیفه مرتبط یافت نشد.'];
        }

        if ($raterId === int_value($task->user_id ?? 0)) {
            $ratingType = 'worker';
            $ratedUserId = $submission->worker_id;
        } elseif ($raterId === int_value($submission->worker_id ?? 0)) {
            $ratingType = 'creator';
            $ratedUserId = $task->user_id;
        } else {
            return ['success' => false, 'message' => 'شما مجاز به امتیازدهی نیستید.'];
        }

        try {
            $this->db->beginTransaction();

            $success = $this->ratingService->rate(
                $raterId,
                'custom_task',
                $task->id,
                \App\Enums\ModuleContext::CUSTOM_TASKS,
                $rating
            );

            if (!$success) {
                throw new \Core\Exceptions\ApplicationException('خطا در ثبت امتیاز.');
            }

            $ratingObj = true;

            $this->db->commit();

            $taskTitle = $task->title ?? $task->id ?? 'unknown';
            $taskId = $task->id ?? $ratedUserId;
            $tpl = $this->templateService->renderTemplate('rating_received', ['rating' => $rating, 'task_title' => $taskTitle]);
            $this->outbox?->record('notification', $ratedUserId, 'notification.requested', [
                'user_id' => $ratedUserId,
                'type' => 'new_rating_received',
                'title' => $tpl['title'],
                'message' => $tpl['message'],
                'data' => [
                    'task_id' => $taskId,
                    'rating' => $rating
                ]
            ]);

            return [
                'success' => true,
                'message' => 'امتیاز با موفقیت ثبت شد.',
                'rating' => $ratingObj
            ];

        } catch (\Exception $e) {
            $this->db->rollback();
            $this->logger->error('rating.create.failed', [
                'error' => $e->getMessage(),
                'submission_id' => $submissionId,
            ]);
            return ['success' => false, 'message' => 'خطا در ثبت امتیاز.'];
        }
    }
}
