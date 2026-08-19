<?php

declare(strict_types=1);

namespace App\Services\CustomTask;

use App\Models\Ads;
use App\Models\CustomTaskSubmissionModel;
use App\Models\CustomTaskAnalyticsModel;
use App\Services\Settings\AppSettings;
use App\Services\Notification\NotificationService;
use App\Services\AntiFraud\FraudGuardService;

use Core\Database;
use Core\Logger;
use App\Exceptions\BusinessException;
use App\Validators\Requests\SubmitCustomTaskProofRequest;

/**
 * CustomTaskExecutorService - Handles worker/executor task workflows
 */
class CustomTaskExecutorService
{
    private Ads $taskModel;
    private CustomTaskSubmissionModel $submissionModel;
    private CustomTaskAnalyticsModel $analyticsModel;
    private AppSettings $appSettings;
    private \Core\RateLimiter $rateLimiter;
    private FraudGuardService $fraudGuard;
    private \App\Services\DistributedLockService $lockService;

    private \Core\Database $db;
    private \App\Contracts\LoggerInterface $logger;
    private ?\App\Jobs\CustomTask\SubmitProofJob $submitProofJob = null;

    #[\Core\Attributes\Inject]
    private \Core\Container $container;

    public function __construct(
        \Core\Database $db,
        \App\Contracts\LoggerInterface $logger,
        Ads $taskModel,
        CustomTaskSubmissionModel $submissionModel,
        CustomTaskAnalyticsModel $analyticsModel,
        AppSettings $appSettings,
        \Core\RateLimiter $rateLimiter,
        FraudGuardService $fraudGuard,
        \App\Services\DistributedLockService $lockService,
        ?\App\Jobs\CustomTask\SubmitProofJob $submitProofJob = null
    ) {
        $this->db = $db;
        $this->logger = $logger;

        $this->taskModel = $taskModel;
        $this->submissionModel = $submissionModel;
        $this->analyticsModel = $analyticsModel;
        $this->appSettings = $appSettings;
        $this->rateLimiter = $rateLimiter;
        $this->fraudGuard = $fraudGuard;
        $this->lockService = $lockService;
        $this->submitProofJob = $submitProofJob;
        $this->container = \Core\Container::getInstance();
    }



    /** @return array<string, mixed> */
    public function startTask(int $taskId, int $workerId): array
    {
        $risk = $this->fraudGuard->checkAction($workerId, 'task.custom', [
            'task_id'    => $taskId,
            'ip'         => $this->clientIp(),
            'user_agent' => $this->userAgent(),
            'session_id' => session_id() ?: ''
        ]);

        if (!$risk['allowed']) {
            $this->logger->warning('task.custom_start_blocked_by_fraud_guard', [
                'worker_id' => $workerId,
                'task_id'   => $taskId,
                'reason'    => $risk['reason']
            ]);
            return ['success' => false, 'message' => 'امکان شروع تسک به دلیل رفتارهای نامتعارف سیستمی مسدود شد. دلیل: ' . ($risk['reason'] === 'velocity_limit' ? 'تجاوز از سقف فعالیت مجاز روزانه' : 'تشخیص فعالیت غیرمجاز')];
        }

        return $this->lockService->synchronized("custom_task_start_{$workerId}", function() use ($taskId, $workerId) {
            $this->db->beginTransaction();

        if (!$this->rateLimiter->attempt('custom_task:start:' . $workerId, 15, 5)) {
            $this->db->rollback();
            return ['success' => false, 'message' => "تعداد تلاش‌های شما برای شروع تسک بیش از حد مجاز است."];
        }

        $task = $this->taskModel->findByIdForUpdate($taskId);

        if (!$task || $task->status !== 'active') {
            $this->db->rollback();
            return ['success' => false, 'message' => 'وظیفه فعال نیست.'];
        }

        if ((int)$task->user_id === $workerId || (isset($task->creator_id) && (int)$task->creator_id === $workerId)) {
            $this->db->rollback();
            return ['success' => false, 'message' => 'نمی‌توانید وظیفه خودتان را انجام دهید.'];
        }

        if ($this->submissionModel->submission_hasWorkerDone($taskId, $workerId)) {
            $this->db->rollback();
            return ['success' => false, 'message' => 'شما قبلاً این وظیفه را شروع کرده اید.'];
        }

        $maxDaily = int_value($this->appSettings->get('custom_task_max_daily_submissions', 20));
        if ($this->submissionModel->submission_todayCount($workerId) >= $maxDaily) {
            $this->db->rollback();
            return ['success' => false, 'message' => "سقف انجام تسک روزانه ({$maxDaily}) تکمیل شده."];
        }

        $remaining = (int)$task->total_count - (int)$task->completed_count - (int)$task->pending_count;
        if ($remaining <= 0) {
            $this->db->rollback();
            return ['success' => false, 'message' => 'ظرفیت وظیفه تکمیل شده است.'];
        }

        try {
            $deadline = date('Y-m-d H:i:s', time() + (($task->deadline_hours ?? 24) * 3600));
            
            $subId = $this->submissionModel->submission_create([
                'task_id' => $taskId,
                'worker_id' => $workerId,
                'reward_amount' => $task->price_per_task,
                'reward_currency' => $task->currency,
                'deadline_at' => $deadline,
                'status' => 'in_progress',
                'worker_ip' => $this->clientIp(),
                'worker_fingerprint' => md5($this->userAgent() ?: 'unknown')
            ]);

            if (!$subId) {
                $this->db->rollback();
                return ['success' => false, 'message' => 'شروع تسک انجام نشد؛ ممکن است قبلاً این وظیفه را شروع کرده باشید.'];
            }

            $this->taskModel->incrementPendingCount($taskId);

            $this->db->commit();
            $actualSubId = is_object($subId) ? (int)$subId->id : (int)$subId;
            return ['success' => true, 'submission_id' => $actualSubId, 'deadline' => $deadline];
        } catch (\Exception $e) {
            $this->db->rollback();
            return ['success' => false, 'message' => 'خطا در تخصیص وظیفه.'];
        }
        });
    }

    /**
     * @param array<string, mixed> $proofData
     * @return array<string, mixed>
     */
    public function submitProof(int $submissionId, int $workerId, array $proofData): array
    {
        $job = $this->submitProofJob ?? $this->container->make(\App\Jobs\CustomTask\SubmitProofJob::class);
        return $job->handle(['submission_id' => $submissionId, 'worker_id' => $workerId, 'proof_data' => $proofData]);
    }

    public function recordTaskView(int $taskId, int $userId): void
    {
        $this->analyticsModel->recordTaskView($taskId);
    }

    private function clientIp(): string
    {
        return get_client_ip();
    }

    private function userAgent(): string
    {
        return get_user_agent();
    }
}
