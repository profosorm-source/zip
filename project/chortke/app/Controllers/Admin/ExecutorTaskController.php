<?php

namespace App\Controllers\Admin;

use App\Models\CustomTaskSubmissionModel;
use App\Models\InteractionModel;
use App\Services\CustomTask\AdminCustomTaskService;
use App\Services\Shared\DisputeService;
use App\Controllers\Admin\BaseAdminController;

class ExecutorTaskController extends BaseAdminController
{
    private AdminCustomTaskService $customTaskService;
    private CustomTaskSubmissionModel $submissionModel;
    private DisputeService $disputeService;
    private InteractionModel $interactionModel;

    public function __construct(
        AdminCustomTaskService $customTaskService,
        CustomTaskSubmissionModel $submissionModel,
        DisputeService $disputeService,
        InteractionModel $interactionModel,
        ?\App\Contracts\LoggerInterface $logger = null
    ) {
        parent::__construct(null, null, null, null, $logger);
        $this->customTaskService = $customTaskService;
        $this->submissionModel = $submissionModel;
        $this->disputeService = $disputeService;
        $this->interactionModel = $interactionModel;
    }

    /**
     * تایید اجباری submission توسط ادمین
     */
    public function forceApproveSubmission(): void
    {
        $body = $this->jsonBody();
        $submissionId = int_value($body['submission_id'] ?? 0);

        $submission = $this->submissionModel->submission_find($submissionId);
        if (!$submission) {
            $this->response->json(['ok' => false, 'message' => 'یافت نشد.'], 404);
            return;
        }

        $result = $this->customTaskService->forceApproveSubmissionByAdmin(
            $submissionId,
            (int)$this->userId()
        );

        $this->logger->activity('custom_task.force_approve', 'تایید اجباری submission', user_id(), [
            'submission_id' => $submissionId,
            'admin_id' => (int)$this->userId(),
        ]);

        $result['success'] = (bool)($result['ok'] ?? false);
        $this->response->json($result, $result['ok'] ? 200 : 422);
    }

    /**
     * رد اجباری submission توسط ادمین
     */
    public function forceRejectSubmission(): void
    {
        $body = $this->jsonBody();
        $submissionId = int_value($body['submission_id'] ?? 0);
        $reason = isset($body['reason']) ? str_value($body['reason']) : 'رد توسط ادمین';

        $submission = $this->submissionModel->submission_find($submissionId);
        if (!$submission) {
            $this->response->json(['ok' => false, 'message' => 'یافت نشد.'], 404);
            return;
        }

        $result = $this->customTaskService->forceRejectSubmissionByAdmin(
            $submissionId,
            (int)$this->userId(),
            $reason
        );

        $this->logger->activity('custom_task.force_reject', 'رد اجباری submission', user_id(), [
            'submission_id' => $submissionId,
            'admin_id' => (int)$this->userId(),
            'reason' => $reason,
        ]);

        $result['success'] = (bool)($result['ok'] ?? false);
        $this->response->json($result, $result['ok'] ? 200 : 422);
    }

    /**
     * لیست اختلافات
     */
    public function disputes(): string
    {
        $rawRefType = $this->request->get('ref_type');
        $filters = [
            'status'   => $this->request->get('status'),
            'ref_type' => is_string($rawRefType) ? $rawRefType : '',
        ];

        $page = \max(1, $this->request->int('page', 1));
        $limit = 30;
        $offset = ($page - 1) * $limit;

        $disputeResult = $this->disputeService->listForAdmin($filters, $limit, $offset);
        $disputes = $disputeResult['items'];
        $total = $disputeResult['total'];

        return view('admin.custom-tasks.disputes', [
            'disputes' => $disputes,
            'total' => $total,
            'page' => $page,
            'pages' => \ceil($total / $limit),
            'filters' => $filters,
        ]);
    }

    /**
     * جزئیات اختلاف + پیام‌های دوطرفه
     */
    public function disputeDetail(): string
    {
        $disputeId = (int)$this->request->param('id');
        // Raw SQL حذف شد — از Dispute model استفاده می‌شود
        $dispute = $this->disputeService->findDetailWithSubmission($disputeId);
        if (!$dispute) {
            $this->session->setFlash('error', 'پرونده اختلاف یافت نشد.');
            return redirect(url('/admin/custom-tasks/disputes'));
        }
        $messages = $this->disputeService->getMessages($disputeId);
        return view('admin.custom-tasks.dispute-detail', [
            'dispute'  => $dispute,
            'messages' => $messages,
        ]);
    }

    public function replyDispute(): void
    {
        $disputeId = (int)$this->request->param('id');
        $message   = trim($this->request->str('message'));
        if (mb_strlen((string)$message) < 2) {
            $this->session->setFlash('error', 'متن پیام الزامی است.');
            $this->response->redirect(url('/admin/custom-tasks/disputes/' . $disputeId));
            return;
        }
        // delegate به DisputeCommandService — Raw SQL + bug ($db بجای $this->db) حذف شد
        $result = $this->disputeService->sendMessage($disputeId, (int)$this->userId(), 'admin', $message);
        if (!($result['success'] ?? false)) {
            $this->session->setFlash('error', $result['message'] ?? 'امکان ارسال پیام برای این پرونده وجود ندارد.');
            $this->response->redirect(url('/admin/custom-tasks/disputes'));
            return;
        }
        $this->session->setFlash('success', 'پیام ادمین ثبت شد.');
        $this->response->redirect(url('/admin/custom-tasks/disputes/' . $disputeId));
    }

    /**
     * حل اختلاف
     */
    public function resolveDispute(): void
    {
        $body = $this->jsonBody();
        $disputeId = int_value($body['dispute_id'] ?? 0);
        $decision = $body['decision'] ?? '';
        $adminNote = $body['admin_note'] ?? '';
        $executorPercent = is_scalar($body['executor_percent'] ?? $body['worker_percent'] ?? null) ? str_value($body['executor_percent'] ?? $body['worker_percent']) : '0';

        $decision = match ($decision) {
            'worker_wins', 'worker', 'executor_wins' => 'executor',
            'advertiser_wins', 'advertiser' => 'advertiser',
            'split', 'partial' => 'split',
            default => $decision,
        };
        if (!in_array($decision, ['executor', 'advertiser', 'split'], true)) {
            $this->response->json(['ok' => false, 'success' => false, 'message' => 'تصمیم نامعتبر است.'], 422);
            return;
        }

        $result = $this->disputeService->resolveForAdmin(
            (int)$this->userId(),
            $disputeId,
            [
                'decision' => $decision,
                'admin_note' => $adminNote,
                'executor_percent' => $executorPercent,
            ]
        );

        $this->logger->activity('custom_task.resolve_dispute', 'حل اختلاف', user_id(), [
            'dispute_id' => $disputeId,
            'decision' => $decision,
            'executor_percent' => $executorPercent,
        ]);

        $result['success'] = (bool)($result['ok'] ?? false);
        $this->response->json($result, $result['ok'] ? 200 : 422);
    }

    /**
     * لیست گزارشات تسک‌ها
     */
    public function reports(): string
    {
        $filters = [
            'status' => $this->request->get('status'),
            'reason' => $this->request->get('reason'),
        ];

        $page = max(1, $this->request->int('page', 1));
        $limit = 30;
        $offset = ($page - 1) * $limit;

        $reports = $this->interactionModel->adminListTaskReports($filters, $limit, $offset);
        $total = $this->interactionModel->adminCountTaskReports($filters);

        return view('admin.custom-tasks.reports', [
            'reports' => $reports,
            'total' => $total,
            'page' => $page,
            'pages' => ceil($total / $limit),
            'filters' => $filters,
            'statusLabels' => $this->interactionModel->taskReportStatusLabels(),
            'reasonLabels' => $this->interactionModel->taskReportReasonLabels(),
        ]);
    }

    /**
     * بررسی گزارش
     */
    public function reviewReport(): void
    {
        $body = $this->jsonBody();
        $reportId = int_value($body['report_id'] ?? 0);
        $status = $body['status'] ?? '';
        $adminNote = $body['admin_note'] ?? '';
        $executorPercent = is_scalar($body['executor_percent'] ?? $body['worker_percent'] ?? null) ? str_value($body['executor_percent'] ?? $body['worker_percent']) : '0';

        if (!in_array($status, ['reviewed', 'resolved', 'rejected'])) {
            $this->response->json(['success' => false, 'message' => 'وضعیت نامعتبر است.'], 422);
            return;
        }

        $report = $this->interactionModel->findTaskReport($reportId);

        if (!$report) {
            $this->response->json(['success' => false, 'message' => 'گزارش یافت نشد.'], 404);
            return;
        }

        $updated = $this->interactionModel->updateTaskReport($reportId, [
            'status' => $status,
            'admin_id' => (int)$this->userId(),
            'admin_note' => $adminNote,
            'resolved_at' => ($status === 'resolved') ? date('Y-m-d H:i:s') : null,
        ]);

        if ($updated) {
            if ($status === 'resolved' && $report->reason === 'fraud') {
                $this->customTaskService->rejectTask($report->task_id, (int)$this->userId(), 'گزارش شده به دلیل تقلب');
            }

            $this->logger->activity('custom_task.review_report', 'بررسی گزارش', user_id(), [
                'report_id' => $reportId,
                'status' => $status,
            ]);

            $this->response->json(['success' => true, 'message' => 'گزارش با موفقیت بررسی شد.'], 200);
        } else {
            $this->response->json(['success' => false, 'message' => 'خطا در بررسی گزارش.'], 500);
        }
    }

    /**
     * عملیات دسته‌جمعی (Batch Operations)
     */
    public function batchAction(): void
    {
        $body = $this->jsonBody();
        $action = $body['action'] ?? '';
        $ids = $body['ids'] ?? [];

        if (!in_array($action, ['approve_all', 'reject_all', 'pause_all', 'delete_all'])) {
            $this->response->json(['success' => false, 'message' => 'عملیات نامعتبر است.'], 422);
            return;
        }

        if (empty($ids) || !is_array($ids)) {
            $this->response->json(['success' => false, 'message' => 'هیچ موردی انتخاب نشده است.'], 422);
            return;
        }

        $success = 0;
        $failed = 0;

        foreach ($ids as $id) {
            $id = (int) $id;
            
            try {
                switch ($action) {
                    case 'approve_all':
                        $result = $this->customTaskService->forceApproveSubmissionByAdmin($id, (int)$this->userId());
                        break;
                    
                    case 'reject_all':
                        $result = $this->customTaskService->forceRejectSubmissionByAdmin($id, (int)$this->userId(), 'رد دسته‌جمعی توسط مدیریت');
                        break;
                    
                    case 'pause_all':
                        $result = $this->customTaskService->pauseTask($id, (int)$this->userId());
                        break;
                    
                    case 'delete_all':
                        $result = $this->customTaskService->deleteTask($id, (int)$this->userId());
                        break;
                    
                    default:
                        $result = ['ok' => false];
                }

                if ($result['ok'] ?? false) {
                    $success++;
                } else {
                    $failed++;
                }
            } catch (\Exception $e) {
                $failed++;
            }
        }

        $this->logger->activity('custom_task.batch_action', 'عملیات دسته‌جمعی', user_id(), [
            'action' => $action,
            'total' => count($ids),
            'success' => $success,
            'failed' => $failed,
        ]);

        $this->response->json([
            'success' => true,
            'message' => "موفق: {$success}، ناموفق: {$failed}",
            'stats' => ['success' => $success, 'failed' => $failed]
        ], 200);
    }
    /** @return array<string, mixed> */
    private function jsonBody(): array
    {
        $decoded = json_decode((string)file_get_contents('php://input'), true);
        return is_array($decoded) ? $decoded : [];
    }

}
