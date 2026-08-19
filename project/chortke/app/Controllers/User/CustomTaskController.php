<?php

namespace App\Controllers\User;

use App\Services\CustomTask\CustomTaskExecutorService;
use App\Services\CustomTask\CustomTaskModerationService;
use App\Services\Shared\DisputeService;
use App\Services\Analytics\AnalyticsService;
use App\Services\UploadService;
use App\Validators\Requests\SubmitCustomTaskProofRequest;
use App\Controllers\User\BaseUserController;
use Core\Logger;

/**
 * CustomTaskController — Executor-only controller.
 *
 * NOTE: Advertiser management (create, pause, stats, list) is unified under /ads (AdsController).
 * This controller only handles the worker side: submission, rating, and proof upload.
 */
class CustomTaskController extends BaseUserController
{
    private CustomTaskExecutorService $executorService;
    private CustomTaskModerationService $moderationService;
    private UploadService $uploadService;
    private \App\Models\Ads $adsModel;
    private DisputeService $disputeService;
    private \App\Models\CustomTaskSubmissionModel $submissionModel;

    public function __construct(
        CustomTaskExecutorService $executorService,
        CustomTaskModerationService $moderationService,
        UploadService $uploadService,
        \App\Models\Ads $adsModel,
        DisputeService $disputeService,
        \App\Models\CustomTaskSubmissionModel $submissionModel,
        Logger $logger
    ) {
        parent::__construct(null, null, null, null, $logger);
        $this->executorService = $executorService;
        $this->moderationService = $moderationService;
        $this->uploadService = $uploadService;
        $this->adsModel = $adsModel;
        $this->disputeService = $disputeService;
        $this->submissionModel = $submissionModel;
    }

    public function index(): void
    {
        $this->available();
    }

    public function available(): void
    {
        // Worker-side custom tasks are now part of the unified task marketplace.
        $this->response->redirect(url('/tasks?type=custom_task'));
    }

    public function mySubmissions(): void
    {
        $userId = (int)$this->userId();
        $submissions = [];
        try {
            $submissions = $this->submissionModel->submission_getByWorkerWithTask($userId);
        } catch (\Throwable $e) {
            $this->logger->warning('custom_tasks.my_submissions_unavailable', ['user_id' => $userId, 'error' => $e->getMessage()]);
        }

        $this->view('user/custom-tasks/executor/my-submissions', [
            'submissions' => $submissions,
            'title' => 'اجراهای تسک سفارشی من',
        ]);
    }

    public function show(): void
    {
        $taskId = (int)$this->request->param('id');
        $task = null;
        try {
            $task = $this->adsModel->find($taskId);
        } catch (\Throwable $e) {
            $this->logger->warning('custom_tasks.show_unavailable', ['task_id' => $taskId, 'error' => $e->getMessage()]);
        }

        if (!$task) {
            $this->session->setFlash('error', 'تسک مورد نظر یافت نشد.');
            $this->response->redirect(url('/tasks?type=custom_task'));
            return;
        }

        $this->view('user/custom-tasks/show', [
            'task' => $task,
            'title' => $task->title ?? 'جزئیات تسک سفارشی',
        ]);
    }

    public function start(): void
    {
        $taskId = int_value($this->request->param('id') ?: ($this->request->input('task_id') ?? $this->request->input('id') ?? 0));
        $userId = (int)$this->userId();
        if ($taskId <= 0) {
            $this->response->json(['success' => false, 'message' => 'شناسه تسک نامعتبر است.'], 422);
            return;
        }
        $result = $this->executorService->startTask($taskId, $userId);
        if (!empty($result['success']) && !empty($result['submission_id'])) {
            $result['redirect_url'] = url('/custom-tasks/submissions/' . int_value($result['submission_id']) . '/proof');
            $result['message'] = $result['message'] ?? 'تسک شروع شد؛ اکنون مدرک انجام را ارسال کنید.';
        }
        $this->response->json($result, !empty($result['success']) ? 200 : 422);
    }

    public function proofForm(): void
    {
        $submissionId = (int)$this->request->param('id');
        $userId = (int)$this->userId();
        $submission = $this->findSubmissionForProof($submissionId, $userId);

        if (!$submission) {
            $this->session->setFlash('error', 'اجرای تسک سفارشی یافت نشد.');
            $this->response->redirect(url('/custom-tasks/my-submissions'));
            return;
        }

        $this->view('user/custom-tasks/proof', [
            'submission' => $submission,
            'title' => 'ارسال مدرک تسک سفارشی',
        ]);
    }

    public function disputes(): void
    {
        $userId = (int)$this->userId();
        $items = [];
        try {
            $items = $this->disputeService->getCustomTaskDisputesByUser($userId);
        } catch (\Throwable $e) {
            $this->logger->warning('custom_tasks.disputes_unavailable', ['user_id' => $userId, 'error' => $e->getMessage()]);
        }

        $this->view('user/custom-tasks/executor/disputes', [
            'items' => $items,
            'disputes' => $items,
            'title' => 'اختلافات تسک‌های سفارشی',
        ]);
    }

    public function storeDispute(): void
    {
        $userId = (int)$this->userId();
        $submissionId = int_value($this->request->param('id'));
        $reason = trim(str_value($this->request->input('reason') ?? $this->request->input('message') ?? ''));
        $wantsJson = $this->wantsJson();
        if ($submissionId <= 0 || mb_strlen($reason) < 10) {
            $this->finishDisputeResponse(false, 'دلیل اختلاف باید حداقل ۱۰ کاراکتر باشد.', null, 422, $wantsJson);
            return;
        }

        try {
            // بارگذاری submission از طریق Model — Raw SQL حذف شد
            $submission = $this->findSubmissionForProof($submissionId, $userId);
            if (!$submission) {
                $this->finishDisputeResponse(false, 'ارسال/اجرای تسک یافت نشد.', null, 404, $wantsJson);
                return;
            }
            if (!in_array((string)$submission->status, ['rejected'], true)) {
                $this->finishDisputeResponse(false, 'اختلاف فقط پس از رد شدن مدرک قابل ثبت است.', null, 422, $wantsJson);
                return;
            }

            // delegate به DisputeService — همه Raw SQL حذف شد
            $result = $this->disputeService->openCustomTaskDispute(
                $submissionId,
                $userId,
                (int)$submission->advertiser_id,
                $reason
            );

            $this->finishDisputeResponse(
                (bool)($result['success'] ?? false),
                str_value($result['message'] ?? 'اختلاف ثبت شد.'),
                isset($result['dispute_id']) ? int_value($result['dispute_id']) : null,
                ($result['success'] ?? false) ? 200 : 422,
                $wantsJson
            );
        } catch (\Core\Exceptions\HttpResponseException $response) {
            throw $response;
        } catch (\Throwable $e) {
            $this->logger->error('custom_tasks.dispute_create_failed', ['submission_id' => $submissionId, 'user_id' => $userId, 'error' => $e->getMessage()]);
            $this->finishDisputeResponse(false, 'ثبت اختلاف انجام نشد.', null, 500, $wantsJson);
        }
    }

    public function disputeDetail(): void
    {
        $disputeId = (int)$this->request->param('id');
        $userId = (int)$this->userId();
        // Raw SQL حذف شد — از DisputeService استفاده می‌شود
        $dispute = $this->disputeService->findDetailWithSubmission($disputeId, $userId);
        if (!$dispute) {
            $this->session->setFlash('error', 'پرونده اختلاف یافت نشد یا دسترسی ندارید.');
            $this->response->redirect(url('/custom-tasks/disputes-list'));
            return;
        }
        $messages = $this->disputeService->getMessages($disputeId);
        $this->view('user/custom-tasks/executor/dispute-detail', [
            'dispute' => $dispute,
            'messages' => $messages,
            'title' => 'جزئیات اختلاف تسک سفارشی',
        ]);
    }

    public function replyDispute(): void
    {
        $disputeId = int_value($this->request->param('id'));
        $userId = (int)$this->userId();
        $message = trim($this->request->str('message'));
        if (mb_strlen($message) < 2) {
            $this->session->setFlash('error', 'متن پیام الزامی است.');
            $this->response->redirect(url('/custom-tasks/disputes/' . $disputeId));
            return;
        }
        // Raw SQL حذف شد — از DisputeService.sendMessage() استفاده می‌شود
        $result = $this->disputeService->sendMessage($disputeId, $userId, 'auto', $message);
        if (!($result['success'] ?? false)) {
            $this->session->setFlash('error', $result['message'] ?? 'امکان ارسال پیام برای این پرونده وجود ندارد.');
            $this->response->redirect(url('/custom-tasks/disputes-list'));
            return;
        }
        $this->session->setFlash('success', 'پیام شما ثبت شد.');
        $this->response->redirect(url('/custom-tasks/disputes/' . $disputeId));
    }

    public function review(): void
    {
        $this->response->json(['success' => false, 'message' => 'بررسی ارسال‌ها از پنل تبلیغ‌دهنده انجام می‌شود.'], 422);
    }

    /**
     * ارسال مدرک - schema-based validation per proof_type.
     */
    public function submitProof(): void
    {
        $userId = (int)$this->userId();
        $subId = (int)$this->request->param('id');
        $submission = $this->findSubmissionForProof($subId, $userId);

        if (!$submission) {
            $this->response->json(['success' => false, 'message' => 'اجرای تسک سفارشی یافت نشد.'], 404);
            return;
        }
        if ((string)$submission->status !== 'in_progress') {
            $this->response->json(['success' => false, 'message' => 'این اجرا در وضعیت قابل ارسال مدرک نیست.'], 422);
            return;
        }

        $payload = $this->request->all();
        $payload['task_execution_id'] = $subId;
        if (empty($payload['idempotency_key'])) {
            $payload['idempotency_key'] = 'CUSTOM_' . $userId . '_' . $subId . '_' . bin2hex(random_bytes(8));
        }

        $proofType = $this->normalizeProofType((string)($submission->proof_type ?? 'text'));
        $fileRequired = in_array($proofType, ['screenshot', 'file'], true);
        $hasProofFile = !empty($_FILES['proof_file']['name']);

        if ($fileRequired && !$hasProofFile) {
            $this->response->json(['success' => false, 'message' => $proofType === 'screenshot' ? 'برای این تسک، اسکرین‌شات الزامی است.' : 'برای این تسک، فایل مدرک الزامی است.'], 422);
            return;
        }

        if ($hasProofFile) {
            try {
                $uploadResult = $this->storeProofFile($_FILES['proof_file'], $proofType);
                $payload['proof_file'] = $uploadResult['path'];
                $payload['proof_file_hash'] = $uploadResult['hash'];
            } catch (\Core\Exceptions\BusinessException|\RuntimeException $e) {
                // این دو نوع Exception پیام‌های کاربرپسند و امن (فارسی) تولید می‌کنند.
                // سایر خطاهای غیرمنتظره به GlobalExceptionMiddleware واگذار می‌شوند.
                $this->response->json(['success' => false, 'message' => $e->getMessage()], 422);
                return;
            }
        }

        $schemaError = $this->validateProofSchema($proofType, $payload);
        if ($schemaError !== null) {
            $this->response->json(['success' => false, 'message' => $schemaError], 422);
            return;
        }

        $payload['proof_data'] = json_encode([
            'proof_type' => $proofType,
            'proof_schema' => $this->decodeJsonObject($submission->proof_schema ?? null),
            'submitted_fields' => [
                'proof_text' => trim(str_value($payload['proof_text'] ?? '')) !== '',
                'proof_url' => trim(str_value($payload['proof_url'] ?? '')) !== '',
                'proof_code' => trim(str_value($payload['proof_code'] ?? '')) !== '',
                'proof_file' => !empty($payload['proof_file']),
            ],
        ], JSON_UNESCAPED_UNICODE);

        $request = new SubmitCustomTaskProofRequest($payload);
        if (!$request->validate()) {
            $this->response->json([
                'success' => false,
                'message' => 'خطای اعتبارسنجی',
                'errors' => $request->errors(),
            ], 422);
            return;
        }

        $proofData = array_merge($payload, $request->validated());
        $result = $this->executorService->submitProof($subId, $userId, $proofData);
        $this->response->json($result, !empty($result['success']) ? 200 : 422);
    }


    private function wantsJson(): bool
    {
        $accept = strtolower(str_value($this->request->header('accept')));
        $xhr = $this->request->isAjax();
        return str_contains($accept, 'application/json') || $xhr;
    }

    private function finishDisputeResponse(bool $success, string $message, ?int $disputeId, int $status, bool $json): void
    {
        if ($json) {
            $this->response->json(['success' => $success, 'message' => $message, 'dispute_id' => $disputeId], $status);
            return;
        }
        $this->session->setFlash($success ? 'success' : 'error', $message);
        $this->response->redirect($success && $disputeId ? url('/custom-tasks/disputes/' . $disputeId) : url('/custom-tasks/my-submissions'));
    }

    /**
     * امتیازدهی - با Request Validation
     */
    public function rateSubmission(): void
    {
        $userId = (int)$this->userId();
        $decodedBody = json_decode((string)file_get_contents('php://input'), true);
        if (!is_array($decodedBody)) {
            $this->response->json([
                'success' => false,
                'message' => 'بدنه درخواست باید یک JSON object معتبر باشد',
            ], 422);
            return;
        }
        $body = $decodedBody;

        $validator = \Core\Validator::create($body, [
            'submission_id' => 'required|integer|min:1',
            'rating'        => 'required|integer|min:1|max:5',
            'feedback'      => 'nullable|string|min:5|max:1000',
        ]);

        if ($validator->fails()) {
            $this->response->json([
                'success' => false,
                'message' => 'خطای اعتبارسنجی',
                'errors' => $validator->errors(),
            ], 422);
            return;
        }

        $result = $this->moderationService->rateSubmission(
            (int)$body['submission_id'],
            $userId,
            $validator->data()
        );

        $this->response->json($result, !empty($result['success']) ? 200 : 422);
    }

    private function findSubmissionForProof(int $submissionId, int $userId): ?\stdClass
    {
        try {
            return $this->submissionModel->submission_findForProof($submissionId, $userId);
        } catch (\Throwable $e) {
            $this->logger->warning('custom_tasks.proof_submission_unavailable', [
                'submission_id' => $submissionId,
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);
            return null;
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

    /** @param array<string, mixed> $payload */
    private function validateProofSchema(string $proofType, array $payload): ?string
    {
        $text = trim(str_value($payload['proof_text'] ?? ''));
        $url = trim(str_value($payload['proof_url'] ?? ''));
        $code = trim(str_value($payload['proof_code'] ?? ''));
        $file = trim(str_value($payload['proof_file'] ?? ''));

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
     * @param array<string, mixed> $file
     * @return array<string, mixed>
     */
    private function storeProofFile(array $file, string $proofType): array
    {
        $original = str_value($file['name'] ?? 'proof');
        $ext = strtolower(pathinfo($original, PATHINFO_EXTENSION));
        $isPdf = $ext === 'pdf';

        if ($proofType === 'video') {
            return $this->storePrivateVideoProof($file);
        }

        if ($proofType === 'screenshot' && $isPdf) {
            throw new \RuntimeException('برای اسکرین‌شات فقط تصویر مجاز است.');
        }

        if ($isPdf) {
            return $this->storePrivatePdfProof($file);
        }

        $allowed = ['jpg', 'jpeg', 'png', 'webp'];
        $uploadResult = $this->uploadService->upload($file, 'task-proofs', $allowed, 5 * 1024 * 1024);
        $path = (string)$uploadResult['path'];
        $realPath = $this->uploadService->getPath($path);
        return [
            'path' => $path,
            'hash' => $realPath && is_file($realPath) ? hash_file('sha256', $realPath) : null,
        ];
    }

    /**
     * @param array<string, mixed> $file
     * @return array<string, mixed>
     */
    private function storePrivatePdfProof(array $file): array
    {
        $err = int_value($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($err !== UPLOAD_ERR_OK) {
            throw new \RuntimeException('خطا در آپلود فایل مدرک.');
        }
        $tmp = str_value($file['tmp_name'] ?? '');
        if ($tmp === '' || !is_uploaded_file($tmp)) {
            throw new \RuntimeException('فایل موقت مدرک نامعتبر است.');
        }
        $size = max(int_value($file['size'] ?? 0), (int)@filesize($tmp));
        if ($size <= 0 || $size > 5 * 1024 * 1024) {
            throw new \RuntimeException('حجم فایل PDF باید کمتر از ۵ مگابایت باشد.');
        }
        $header = (string)@file_get_contents($tmp, false, null, 0, 5);
        if (!str_starts_with($header, '%PDF-')) {
            throw new \RuntimeException('فایل PDF معتبر نیست.');
        }
        $root = realpath(__DIR__ . '/../../../') ?: (__DIR__ . '/../../../');
        $dir = rtrim($root, '/\\') . '/storage/uploads/task-proofs/';
        if (!is_dir($dir) && !mkdir($dir, 0750, true)) {
            throw new \RuntimeException('خطا در ایجاد پوشه مدرک.');
        }
        $filename = bin2hex(random_bytes(12)) . '.pdf';
        $dest = $dir . $filename;
        if (!move_uploaded_file($tmp, $dest)) {
            throw new \RuntimeException('خطا در ذخیره PDF مدرک.');
        }
        chmod($dest, 0640);
        return [
            'path' => 'task-proofs/' . $filename,
            'hash' => hash_file('sha256', $dest),
        ];
    }


    /**
     * @param array<string, mixed> $file
     * @return array<string, mixed>
     */
    private function storePrivateVideoProof(array $file): array
    {
        $err = int_value($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($err !== UPLOAD_ERR_OK) {
            throw new \RuntimeException('خطا در آپلود ویدیو مدرک.');
        }
        $tmp = str_value($file['tmp_name'] ?? '');
        if ($tmp === '' || !is_uploaded_file($tmp)) {
            throw new \RuntimeException('فایل موقت ویدیو نامعتبر است.');
        }
        $size = max(int_value($file['size'] ?? 0), (int)@filesize($tmp));
        $max = 30 * 1024 * 1024;
        if ($size <= 0 || $size > $max) {
            throw new \RuntimeException('حجم ویدیو باید کمتر از ۳۰ مگابایت باشد.');
        }
        $original = strtolower(str_value($file['name'] ?? 'video'));
        $ext = strtolower(pathinfo($original, PATHINFO_EXTENSION));
        $allowedExt = ['mp4', 'webm', 'mov'];
        if (!in_array($ext, $allowedExt, true)) {
            throw new \RuntimeException('فرمت ویدیو باید mp4، webm یا mov باشد.');
        }
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo === false) {
            throw new \RuntimeException('نوع فایل ویدیو معتبر نیست.');
        }
        $mime = (string)finfo_file($finfo, $tmp);
        finfo_close($finfo);
        $allowedMime = ['video/mp4', 'video/webm', 'video/quicktime', 'application/octet-stream'];
        if (!in_array($mime, $allowedMime, true)) {
            throw new \RuntimeException('نوع فایل ویدیو معتبر نیست.');
        }
        $root = realpath(__DIR__ . '/../../../') ?: (__DIR__ . '/../../../');
        $dir = rtrim($root, '/\\') . '/storage/uploads/task-proofs/';
        if (!is_dir($dir) && !mkdir($dir, 0750, true)) {
            throw new \RuntimeException('خطا در ایجاد پوشه ویدیو مدرک.');
        }
        $filename = bin2hex(random_bytes(12)) . '.' . $ext;
        $dest = $dir . $filename;
        if (!move_uploaded_file($tmp, $dest)) {
            throw new \RuntimeException('خطا در ذخیره ویدیو مدرک.');
        }
        chmod($dest, 0640);
        return [
            'path' => 'task-proofs/' . $filename,
            'hash' => hash_file('sha256', $dest),
        ];
    }

    /** @return array<string, mixed> */
    private function decodeJsonObject(?string $json): array
    {
        if (!$json) return [];
        $decoded = (array)(json_decode($json, true) ?? []);
        return is_array($decoded) ? $decoded : [];
    }
}
