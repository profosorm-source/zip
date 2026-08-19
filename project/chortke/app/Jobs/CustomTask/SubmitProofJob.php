<?php
declare(strict_types=1);

namespace App\Jobs\CustomTask;

use Core\Logger;
use Core\RateLimiter;
use App\Models\CustomTaskSubmissionModel;
use App\Models\Ads;
use App\Services\Settings\AppSettings;

class SubmitProofJob
{
    public function __construct(
        private RateLimiter $rateLimiter,
        private CustomTaskSubmissionModel $submissionModel,
        private Ads $taskModel,
        private AppSettings $appSettings,
        private Logger $logger,
        private ?\App\Contracts\OutboxServiceInterface $outbox = null
    ) {}

/**
 * @param array<string, mixed> $payload
 * @return array<string, mixed>
 */
public function handle(array $payload): array
    {
        $submissionId = int_value($payload['submission_id'] ?? 0);
        $workerId = int_value($payload['worker_id'] ?? 0);
        $proofData = is_array($payload['proof_data'] ?? null) ? $payload['proof_data'] : [];
        $proofData['task_execution_id'] = $submissionId;

        if ($workerId <= 0 || $submissionId <= 0) {
            return ['success' => false, 'message' => 'شناسه کاربر یا اجرا نامعتبر است.'];
        }

        if (!$this->rateLimiter->attempt('custom_task:submit:' . $workerId, 10, 10)) {
            $wait = ceil($this->rateLimiter->availableIn('custom_task:submit:' . $workerId) / 60);
            return ['success' => false, 'message' => "تعداد درخواست‌های شما بیش از حد مجاز است. لطفا {$wait} دقیقه دیگر امتحان کنید."];
        }

        try {
            $submission = $this->submissionModel->submission_findById($submissionId);
            if (!$submission || (int)$submission->worker_id !== $workerId) {
                return ['success' => false, 'message' => 'دسترسی غیرمجاز.'];
            }
            if ((string)$submission->status !== 'in_progress') {
                return ['success' => false, 'message' => 'وضعیت نامعتبر.'];
            }
            if (!empty($submission->deadline_at) && strtotime((string)$submission->deadline_at) < time()) {
                return ['success' => false, 'message' => 'مهلت ارسال مدرک پایان یافته.'];
            }

            $proofType = $this->normalizeProofType((string)($submission->proof_type ?? 'text'));
            $schemaError = $this->validateProofSchema($proofType, $proofData);
            if ($schemaError !== null) {
                return ['success' => false, 'message' => $schemaError];
            }

            $request = new \App\Validators\Requests\SubmitCustomTaskProofRequest($proofData);
            if (!$request->validate()) {
                return ['success' => false, 'message' => 'خطای اعتبارسنجی', 'errors' => $request->errors()];
            }
            $validated = array_merge($proofData, $request->validated());

            $duplicate = $this->checkDuplicateProof($submission, $proofType, $validated);
            if ($duplicate !== null) {
                return ['success' => false, 'message' => $duplicate];
            }

            $updateData = [
                'proof_url' => $validated['proof_url'] ?? null,
                'proof_text' => $validated['proof_text'] ?? null,
                'proof_code' => $validated['proof_code'] ?? null,
                'proof_file' => $validated['proof_file'] ?? null,
                'proof_file_hash' => $validated['proof_file_hash'] ?? null,
                'proof_data' => $validated['proof_data'] ?? null,
                'submitted_at' => date('Y-m-d H:i:s'),
                'status' => 'submitted',
            ];

            $this->submissionModel->submission_update($submissionId, $updateData);

            $this->outbox?->record('custom_task', $submissionId, 'custom_task.submission_created', [
                'submission_id' => $submissionId,
                'task_id' => $submission->task_id,
                'worker_id' => $workerId,
                'reward_amount' => $submission->reward_amount,
                'reward_currency' => $submission->reward_currency,
                'proof_type' => $proofType,
                'submitted_at' => date('Y-m-d H:i:s'),
            ]);

            $this->logger->info('Proof submitted', [
                'submission_id' => $submissionId,
                'worker_id' => $workerId,
                'proof_type' => $proofType,
            ]);

            $task = $this->taskModel->find((int)$submission->task_id);
            if ($task) {
                $this->outbox?->record('notification', (int)$task->user_id, 'notification.requested', [
                    'user_id' => (int)$task->user_id,
                    'type' => 'task_proof_submitted',
                    'title' => 'مدرک جدید دریافت شد',
                    'message' => 'مدرک جدیدی برای وظیفه «' . ($task->title ?? '') . '» ارسال شد و منتظر بررسی است.',
                    'data' => [
                        'task_id' => $task->id ?? 0,
                        'submission_id' => $submissionId,
                        'url' => '/user/custom-tasks/submissions/' . $submissionId,
                    ],
                ]);
            }

            $autoApproveHours = int_value($this->appSettings->get('custom_task_auto_approve_hours', 48));
            return [
                'success' => true,
                'message' => 'مدرک شما با موفقیت ارسال شد.',
                'auto_approve_info' => "در صورتی که کارفرما تا {$autoApproveHours} ساعت آینده بررسی نکند، بصورت خودکار تایید خواهد شد.",
            ];
        } catch (\Throwable $e) {
            $this->logger->error('task.proof_submission.failed', [
                'channel' => 'task',
                'error' => $e->getMessage(),
            ]);
            return ['success' => false, 'message' => 'خطا در ارسال مدرک.'];
        }
    }

    private function normalizeProofType(string $type): string
    {
        $type = strtolower(trim((string)$type));
        return match ($type) {
            'link' => 'url',
            'image' => 'screenshot',
            default => in_array($type, ['text', 'code', 'url', 'screenshot', 'file', 'video'], true) ? $type : 'text',
        };
    }

    /**
     * @param array<string, mixed> $data
     */
private function validateProofSchema(string $proofType, array $data): ?string
    {
        $text = trim(str_value($data['proof_text'] ?? ''));
        $url = trim(str_value($data['proof_url'] ?? ''));
        $code = trim(str_value($data['proof_code'] ?? ''));
        $file = trim(str_value($data['proof_file'] ?? ''));

        return match ($proofType) {
            'text' => mb_strlen((string)$text) >= 10 ? null : 'برای این تسک، توضیح متنی حداقل ۱۰ کاراکتر الزامی است.',
            'code' => mb_strlen((string)$code) >= 2 ? null : 'برای این تسک، کد یا شناسه مدرک الزامی است.',
            'url' => filter_var($url, FILTER_VALIDATE_URL) ? null : 'برای این تسک، لینک مدرک معتبر الزامی است.',
            'screenshot' => $file !== '' ? null : 'برای این تسک، اسکرین‌شات الزامی است.',
            'file' => $file !== '' ? null : 'برای این تسک، فایل مدرک الزامی است.',
            'video' => (filter_var($url, FILTER_VALIDATE_URL) || $file !== '') ? null : 'برای مدرک ویدیویی، لینک معتبر یا فایل ویدیو الزامی است.',
            default => ($text !== '' || $url !== '' || $code !== '' || $file !== '') ? null : 'مدرک انجام تسک الزامی است.',
        };
    }

    /**
     * @param array<string, mixed> $data
     */
    private function checkDuplicateProof(\stdClass $submission, string $proofType, array $data): ?string
    {
        $taskId = (int)$submission->task_id;
        $submissionId = (int)$submission->id;
        $db = $this->submissionModel->getDb();

        if (!empty($data['proof_file_hash'])) {
            $row = $db->fetch(
                "SELECT id FROM custom_task_submissions WHERE task_id = ? AND proof_file_hash = ? AND id <> ? AND status != 'rejected' LIMIT 1",
                [$taskId, str_value($data['proof_file_hash']), $submissionId]
            );
            if ($row) return 'این فایل مدرک قبلاً برای این تسک ارسال شده است.';
        }
        if ($proofType === 'code' && !empty($data['proof_code'])) {
            $row = $db->fetch(
                "SELECT id FROM custom_task_submissions WHERE task_id = ? AND proof_code = ? AND id <> ? AND status != 'rejected' LIMIT 1",
                [$taskId, trim(str_value($data['proof_code'])), $submissionId]
            );
            if ($row) return 'این کد/شناسه قبلاً برای این تسک ارسال شده است.';
        }
        if (in_array($proofType, ['url', 'video'], true) && !empty($data['proof_url'])) {
            $row = $db->fetch(
                "SELECT id FROM custom_task_submissions WHERE task_id = ? AND proof_url = ? AND id <> ? AND status != 'rejected' LIMIT 1",
                [$taskId, trim(str_value($data['proof_url'])), $submissionId]
            );
            if ($row) return 'این لینک مدرک قبلاً برای این تسک ارسال شده است.';
        }

        return null;
    }
}
