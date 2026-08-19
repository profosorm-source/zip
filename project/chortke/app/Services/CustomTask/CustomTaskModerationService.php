<?php

declare(strict_types=1);

namespace App\Services\CustomTask;

use App\Models\Ads;
use App\Models\CustomTaskSubmissionModel;
use App\Models\User;
use App\Services\Settings\AppSettings;
use Core\Database;
use Core\EventDispatcher;
use App\Exceptions\BusinessException;
use App\Validators\Requests\RateCustomTaskRequest;
use App\Services\StateMachineService;
use App\Events\TaskCompletedEvent;

/**
 * CustomTaskModerationService - Handles advertiser moderation workflows (approving/rejecting submissions, rating workers, paying rewards)
 */
class CustomTaskModerationService
{
    private Ads $taskModel;
    private CustomTaskSubmissionModel $submissionModel;
    private StateMachineService $stateMachine;
    private ?\App\Services\OutboxService $outbox;

    private \Core\Database $db;
    private \App\Contracts\LoggerInterface $logger;

    private ?\App\Contracts\WalletServiceInterface $walletService = null;
    private ?\App\Services\Notification\NotificationTemplateService $templateService = null;
    private ?\App\Jobs\CustomTask\PayRewardJob $payRewardJob = null;
    private ?\App\Jobs\CustomTask\RateSubmissionJob $rateSubmissionJob = null;
    private ?\App\Services\EscrowService $escrowService = null;

    #[\Core\Attributes\Inject]
    private \Core\Container $container;

    public function __construct(
        \Core\Database $db,
        \App\Contracts\LoggerInterface $logger,
        Ads $taskModel,
        CustomTaskSubmissionModel $submissionModel,
        ?StateMachineService $stateMachine = null,
        ?\App\Services\OutboxService $outbox = null,
        ?\App\Contracts\WalletServiceInterface $walletService = null,
        ?\App\Services\Notification\NotificationTemplateService $templateService = null,
        ?\App\Jobs\CustomTask\PayRewardJob $payRewardJob = null,
        ?\App\Jobs\CustomTask\RateSubmissionJob $rateSubmissionJob = null,
        ?\App\Services\EscrowService $escrowService = null
    ) {
        $this->db = $db;
        $this->logger = $logger;

        $this->taskModel = $taskModel;
        $this->submissionModel = $submissionModel;
        $this->outbox = $outbox;
        // 🛡️ STABILITY FIX: تصحیح ترتیب تزریق وابستگی‌ها به StateMachineService ($this->db, $this->logger)
        $this->stateMachine = $stateMachine ?? new StateMachineService($this->db, $this->logger);
        $this->walletService = $walletService;
        $this->templateService = $templateService;
        $this->payRewardJob = $payRewardJob;
        $this->rateSubmissionJob = $rateSubmissionJob;
        $this->escrowService = $escrowService;
        $this->container = \Core\Container::getInstance();
    }

    private function wallet(): \App\Contracts\WalletServiceInterface
    {
        if ($this->walletService === null) {
            throw new \RuntimeException('WalletService must be injected into CustomTaskModerationService');
        }

        return $this->walletService;
    }



    /** @return array<string, mixed> */
    public function reviewSubmission(
        int $submissionId,
        int $reviewerId,
        string $decision,
        ?string $reason = null
    ): array {
        $submission = $this->submissionModel->submission_find($submissionId);

        if (!$submission) {
            return ['success' => false, 'message' => 'یافت نشد.'];
        }

        if ($submission->creator_id !== $reviewerId) {
            return ['success' => false, 'message' => 'دسترسی غیرمجاز.'];
        }

        if ($submission->status !== 'submitted') {
            return ['success' => false, 'message' => 'وضعیت نامعتبر.'];
        }

        if (!in_array($decision, ['approve', 'reject'])) {
            return ['success' => false, 'message' => 'تصمیم نامعتبر.'];
        }

        if ($decision === 'approve') {
            return $this->approveSubmission($submission);
        } else {
            return $this->rejectSubmission($submission, $reason);
        }
    }

    /** @return array<string, mixed> */
    public function approveSubmission(\stdClass $submission): array
    {
        $wallet = $this->wallet();

        try {
            $this->db->beginTransaction();

            $sub = $this->submissionModel->submission_findByIdForUpdate((int)$submission->id);
            if (!$sub) {
                throw new \RuntimeException('درخواست یافت نشد.');
            }
            if (!in_array((string)$sub->status, ['submitted', 'disputed'], true)) {
                throw new \RuntimeException('این درخواست در وضعیت قابل تأیید نیست.');
            }

            $previousStatus = (string)$sub->status;

            $task = $this->taskModel->findByIdForUpdate((int)$sub->task_id);
            if (!$task) {
                throw new \RuntimeException('تسک یافت نشد.');
            }

            $escrowService = $this->escrowService;
            $escrow = $escrowService ? $escrowService->getByOrder((int)$sub->task_id, 'custom_task_budget') : null;
            $txId = null;

            // Fallback: submission may be missing reward_amount (legacy/manual insert)
            // float→decimal: مبلغ پاداش به‌صورت رشتهٔ decimal حفظ می‌شود (بدون گذر از float)
            $rewardAmount = $sub->reward_amount ?? $task->price_per_task ?? 0;
            $rewardCurrency = $sub->reward_currency ?? $task->currency ?? 'irt';
            if (empty($rewardAmount) || !is_numeric((string)$rewardAmount) || bccomp((string)$rewardAmount, '0', 8) <= 0) {
                $rewardAmount = is_numeric($task->price_per_task ?? null) ? (string)$task->price_per_task : '0';
            }
            if (empty($rewardCurrency)) {
                $rewardCurrency = (string)($task->currency ?? 'irt');
            }

            if ($escrow && $escrowService) {
                $release = $escrowService->partialRelease((int)$escrow->id, (int)$sub->worker_id, (string)$rewardAmount, 'custom_task_reward_' . (int)$sub->id);
                if (empty($release['ok'])) {
                    throw new \RuntimeException($release['error'] ?? 'خطا در آزادسازی وجه امانی.');
                }
                $txId = 'escrow_partial_release_' . (int)$escrow->id . '_submission_' . (int)$sub->id;
            } else {
                $res = $wallet->deposit((int)$sub->worker_id, (string)$rewardAmount, (string)$rewardCurrency, [
                    'type' => 'task_reward',
                    'description' => 'پاداش تسک سفارشی #' . (int)$sub->task_id,
                    'idempotency_key' => 'custom_task_reward_' . (int)$sub->id,
                    'submission_id' => (int)$sub->id,
                    'task_id' => (int)$sub->task_id,
                ]);
                if (empty($res['success'])) {
                    throw new \RuntimeException(is_string($res['message'] ?? null) ? $res['message'] : 'خطا در پرداخت پاداش.');
                }
                $txId = $res['transaction_id'] ?? null;
            }

            $this->submissionModel->submission_update((int)$sub->id, [
                'status' => 'approved',
                'reviewed_at' => date('Y-m-d H:i:s'),
                'approved_at' => date('Y-m-d H:i:s'),
                'reviewed_by' => (int)($task->user_id ?? $submission->creator_id ?? 0),
                'reward_paid' => 1,
                'reward_transaction_id' => $txId,
            ]);

            // If the submission had already been rejected/disputed, its slot was freed earlier.
            // Re-consuming that slot keeps capacity consistent when admin resolves in favor of worker.
            if ($previousStatus !== 'submitted') {
                $this->taskModel->decrementAdSlots((int)$sub->task_id);
            }
            $this->taskModel->incrementCustomTaskCompletion((int)$sub->task_id, (string)$rewardAmount, $previousStatus === 'submitted');

            $this->db->commit();

            $this->outbox?->record('custom_task', (int)$sub->id, 'custom_task.submission.approved', [
                'worker_id' => (int)$sub->worker_id,
                'task_id' => (int)$sub->task_id,
                'submission_id' => (int)$sub->id,
                'creator_id' => (int)($task->user_id ?? 0),
                'reward_amount' => (string)$rewardAmount,
                'reward_currency' => (string)$sub->reward_currency,
                'reward_transaction_id' => $txId,
                'score_delta' => 10,
            ]);

            try {
                $taskCompletedEvent = new TaskCompletedEvent(
                    (int)$sub->worker_id,
                    (int)$sub->task_id,
                    floatval($sub->reward_amount ?? 0),
                    'CUSTOM_TASK'
                );
                $eventData = $taskCompletedEvent->getData();
                $payload = is_array($eventData) ? $eventData : ['value' => $eventData];
                $this->outbox?->record('custom_task', (int)$sub->task_id, TaskCompletedEvent::class, $payload);
            } catch (\Throwable $e) {
                $this->logger->warning('custom_task.task_completed_event_failed', [
                    'submission_id' => $sub->id,
                    'error' => $e->getMessage(),
                ]);
            }

            return [
                'success' => true,
                'message' => 'تأیید و پرداخت پاداش با موفقیت انجام شد.',
                'submission_id' => (int)$sub->id,
                'reward_transaction_id' => $txId,
            ];
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollback();
            }
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /** @return array<string, mixed> */
    public function rejectSubmission(\stdClass $submission, ?string $reason): array
    {
        try {
            $this->db->beginTransaction();

            $sub = $this->submissionModel->submission_findByIdForUpdate($submission->id);
            
            if (!$sub) {
                throw new \Core\Exceptions\NotFoundException('درخواست یافت نشد.');
            }

            $previousStatus = (string)$sub->status;

            if ($sub->status === 'rejected') {
                throw new \Core\Exceptions\InvalidStateException('این درخواست قبلاً رد شده است.');
            }

            if (!$this->stateMachine->canTransition('custom_task_submission', $sub->status, 'rejected')) {
                throw new \Core\Exceptions\InvalidStateException("تغییر وضعیت از وضعیت فعلی ({$sub->status}) به رد شده مجاز نیست.");
            }

            $this->submissionModel->submission_update($submission->id, [
                'status' => 'rejected',
                'reviewed_at' => date('Y-m-d H:i:s'),
                'rejection_reason' => $reason,
            ]);

            if (in_array($previousStatus, ['submitted', 'in_progress'], true)) {
                $this->taskModel->decrementPendingCount((int)$submission->task_id);
            }

            $this->db->commit();

            $this->logger->info('Submission rejected', [
                'submission_id' => $submission->id,
                'reason' => $reason,
            ]);

            $tpl = $this->templateService?->renderTemplate('submission_rejected', ['task_title' => $submission->task_title, 'reason' => $reason]) ?? ['title' => 'مدرک رد شد ❌', 'message' => "مدرک رد شد."];
            $this->outbox?->record('notification', $submission->worker_id, 'notification.requested', [
                'user_id' => $submission->worker_id,
                'type' => 'task_submission_rejected',
                'title' => $tpl['title'],
                'message' => $tpl['message'],
                'data' => [
                    'submission_id' => $submission->id,
                    'task_id' => $submission->task_id,
                    'reason' => $reason,
                    'url' => "/user/custom-tasks/my-submissions/{$submission->id}"
                ]
            ]);

            // 🛡️ Fix: Outbox recording برای رد submission
            try {
                if ($this->outbox) {
                    $this->outbox->record(
                        'custom_task',
                        (int)$submission->id,
                        'custom_task.submission.rejected',
                        [
                            'worker_id' => (int)$submission->worker_id,
                            'task_id' => (int)$submission->task_id,
                            'submission_id' => (int)$submission->id,
                            'reason' => $reason,
                        ]
                    );
                }
            } catch (\Throwable $e) {
                $this->logger->warning('custom_task.reject_outbox_failed', [
                    'submission_id' => $submission->id,
                    'error' => $e->getMessage(),
                ]);
            }

            // Dispatch ایونت امتیازدهی برای رد submission
            $this->outbox?->record('custom_task', (int)$submission->id, 'custom_task.submission.rejected', [
                'worker_id'  => (int)$submission->worker_id,
                'task_id'    => (int)$submission->task_id,
                'submission_id' => (int)$submission->id,
                'creator_id' => (int)$submission->creator_id,
                'score_delta' => -5,
            ]);

            return ['success' => true, 'message' => 'درخواست رد شد.'];

        } catch (\Exception $e) {
            $this->db->rollback();
            $this->logger->error('task.rejection.failed', [
                'channel' => 'task',
                'error' => $e->getMessage(),
            ]);
            return ['success' => false, 'message' => 'خطا در رد درخواست.'];
        }
    }

    public function payWorkerReward(\stdClass $submission): void
    {
        $job = $this->payRewardJob ?? $this->container->make(\App\Jobs\CustomTask\PayRewardJob::class);
        $job->handle($submission);
    }

    /**
     * @param array<string, mixed> $ratingData
     * @return array<string, mixed>
     */
    public function rateSubmission(int $submissionId, int $raterId, array $ratingData): array
    {
        $job = $this->rateSubmissionJob ?? $this->container->make(\App\Jobs\CustomTask\RateSubmissionJob::class);
        return $job->handle($submissionId, $raterId, $ratingData);
    }

    /**
     * 🛡️ CT-05: ممیزی محتوای تسک یا ارسال‌های کاربران (Moderation)
     */
    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function moderate(array $data): array
    {
        $content = ($data['title'] ?? '') . ' ' . ($data['description'] ?? '') . ' ' . ($data['proof_text'] ?? '');
        $bannedWords = ['کلاهبرداری', 'اسکم', 'غیرمجاز', 'scam', 'فیشینگ', 'phishing', 'شرط‌بندی', 'قمار', 'سلاح', 'مواد مخدر'];
        
        foreach ($bannedWords as $word) {
            if (mb_stripos($content, $word) !== false) {
                $this->logger->warning('custom_task.moderation_rejected', ['word' => $word, 'data' => $data]);
                return ['allowed' => false, 'reason' => "محتوای ارائه شده شامل عبارات نامناسب یا غیرمجاز («{$word}») است."];
            }
        }

        return ['allowed' => true];
    }

    /** @return array<string, mixed> */
    public function moderateContent(string $content): array
    {
        return $this->moderate(['description' => $content]);
    }
}
