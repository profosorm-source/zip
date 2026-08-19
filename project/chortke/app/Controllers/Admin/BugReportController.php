<?php

namespace App\Controllers\Admin;

use App\Services\TicketService;
use App\Services\UploadService;
use App\Services\Search\SearchOrchestrator;
use App\Controllers\Admin\BaseAdminController;
use App\Policies\RateLimitPolicy;

class BugReportController extends BaseAdminController
{
    // L-04: این کنترلر به نقش support مجاز است؛ گیت ورود = ناحیهٔ ادمین، و عملیات با authorizeById('bug_reports.*') ریزدانه محدود می‌شود.
    protected function enforceAdminAccess(): void
    {
        $this->requireAdminArea();
    }

    private TicketService $ticketService;

    public function __construct(
        TicketService $ticketService,
        ?\App\Contracts\LoggerInterface $logger = null
    ) {
        parent::__construct(null, null, null, null, $logger);
        $this->ticketService = $ticketService;
    }

    /**
     * لیست گزارش‌ها (مهاجرت یافته به سیستم تیکت یکپارچه)
     */
    public function index(): string
    {
        $adminId = $this->requireAdminId();

        // 🛡️ CRIT-02: بررسی دسترسی مشاهده گزارش‌های باگ
        if (!$this->policyService->authorizeById('bug_reports.view', $adminId)) {
            $this->session->setFlash('error', 'دسترسی غیرمجاز.');
            return redirect(url('/admin/dashboard'));
        }

        $page = max(1, $this->request->int('page', 1));
        $perPage = 20;
        $offset = ($page - 1) * $perPage;

        $search = trim(str_value($this->request->get('search') ?? ''));

        $filters = [];
        foreach (['status', 'priority', 'category', 'date_from', 'date_to'] as $key) {
            $val = $this->request->get($key);
            if ($val !== null && $val !== '') {
                $filters[$key] = $val;
            }
        }

        if (!empty($search)) {
            $filters['search'] = $search;
        }
        $reports = $this->ticketService->getAdminBugReports($filters, $page, $perPage);
        $total = $this->ticketService->countAdminBugReports($filters);

        $totalPages = (int)\ceil($total / $perPage);
        $stats = $this->ticketService->getAdminBugStats();
        $categoryStats = []; // Simplified representation

        return view('admin.bug-reports.index', [
            'reports' => $reports,
            'stats' => $stats,
            'categoryStats' => $categoryStats,
            'filters' => $filters,
            'search' => $search,
            'page' => $page,
            'totalPages' => $totalPages,
            'total' => $total,
        ]);
    }

    /**
     * جزئیات گزارش
     */
    public function show(): string
    {
        $adminId = $this->requireAdminId();

        // 🛡️ CRIT-02: بررسی دسترسی مشاهده گزارش‌های باگ
        if (!$this->policyService->authorizeById('bug_reports.view', $adminId)) {
            $this->session->setFlash('error', 'دسترسی غیرمجاز.');
            return redirect(url('/admin/dashboard'));
        }

        $id = $this->request->int('id');

        $report = $this->ticketService->findBugReport($id);
        if (!$report) {
            $this->session->setFlash('error', 'گزارش یافت نشد');
            return redirect(url('/admin/bug-reports'));
        }

        $comments = $this->ticketService->getBugReportComments($id);

        return view('admin.bug-reports.show', [
            'report' => $report,
            'comments' => $comments,
        ]);
    }

    /**
     * تغییر وضعیت (AJAX)
     */
    public function updateStatus(): void
    {
        $this->validateCsrf();

        // 🛡️ HIGH-11: بررسی دسترسی اختصاصی جهت تغییر وضعیت گزارش‌های باگ
        $adminId = $this->requireAdminId();
        if (!$this->policyService->authorizeById('bug_reports.update_status', $adminId)) {
            $this->response->json(['success' => false, 'message' => 'دسترسی غیرمجاز.'], 403);
            return;
        }

        $id = $this->request->int('id');
        $data = $this->request->json() ?? [];

        $status = $data['status'] ?? '';
        if (!in_array($status, \App\Enums\TicketStatus::all(), true)) {
            $this->response->json(['success' => false, 'message' => 'وضعیت نامعتبر است.'], 422);
            return;
        }

        // 🛡️ MED-06: تایید اصالت گزارش باگ قبل از هرگونه تغییر
        $oldReport = $this->ticketService->findBugReport($id);
        if (!$oldReport) {
            $this->response->json(['success' => false, 'message' => 'گزارش یافت نشد.'], 404);
            return;
        }
        $oldStatus = $oldReport->status;

        $ok = $this->ticketService->updateStatus($id, $status, $adminId);
        if ($ok) {
            $this->auditLog('bug_report_status_changed', 'bug_report', $id, ['status' => $oldStatus], ['status' => $status]);
        }

        $this->response->json(['success' => $ok]);
    }

    /**
     * تغییر اولویت (AJAX)
     */
    public function updatePriority(): void
    {
        $this->validateCsrf();

        // 🛡️ HIGH-11: بررسی دسترسی اختصاصی جهت تغییر اولویت گزارش‌های باگ
        $adminId = $this->requireAdminId();
        if (!$this->policyService->authorizeById('bug_reports.update_priority', $adminId)) {
            $this->response->json(['success' => false, 'message' => 'دسترسی غیرمجاز.'], 403);
            return;
        }

        $id = $this->request->int('id');
        $data = $this->request->json() ?? [];

        $priority = $data['priority'] ?? '';
        if (!in_array($priority, ['low', 'normal', 'high', 'urgent'], true)) {
            $this->response->json(['success' => false, 'message' => 'اولویت نامعتبر است.'], 422);
            return;
        }

        // 🛡️ MED-06: تایید اصالت گزارش باگ قبل از هرگونه تغییر
        $oldReport = $this->ticketService->findBugReport($id);
        if (!$oldReport) {
            $this->response->json(['success' => false, 'message' => 'گزارش یافت نشد.'], 404);
            return;
        }
        $oldPriority = $oldReport->priority;

        $ok = $this->ticketService->updatePriority($id, $priority, $adminId);
        if ($ok) {
            $this->auditLog('bug_report_priority_changed', 'bug_report', $id, ['priority' => $oldPriority], ['priority' => $priority]);
        }

        $this->response->json(['success' => $ok]);
    }

    /**
     * افزودن کامنت ادمین (AJAX)
     */
    public function addComment(): void
    {
        $this->validateCsrf();

        $adminId = $this->requireAdminId();

        // 🛡️ CRIT-02: بررسی دسترسی ادمین
        if (!$this->policyService->authorizeById('bug_reports.view', $adminId)) {
            $this->response->json(['success' => false, 'message' => 'دسترسی غیرمجاز.'], 403);
            return;
        }

        $id = $this->request->int('id');

        // 🛡️ MED-06: تایید وجود و اصالت گزارش باگ
        $report = $this->ticketService->findBugReport($id);
        if (!$report) {
            $this->response->json(['success' => false, 'message' => 'گزارش یافت نشد.'], 404);
            return;
        }

        // 🛡️ MED-06: ممانعت از ثبت کامنت جدید و بازگشایی خودکار تیکت در صورت بسته بودن گزارش
        if ($report->status === 'closed') {
            $this->response->json(['success' => false, 'message' => 'امکان ثبت کامنت روی گزارش بسته شده وجود ندارد.'], 400);
            return;
        }

        // 🛡️ HIGH-01: Rate limiting سبک برای ادمین در پاسخ به گزارش باگ
        RateLimitPolicy::enforce('admin_bugreport_reply', $adminId, true);

        $data = $this->request->json() ?? [];
        if (empty($data)) {
            $data = $this->request->all();
        }

        // 🛡️ CRIT-03: ضدعفونی صریح کامنت ادمین جهت مقابله با حملات Stored XSS
        $comment = htmlspecialchars(trim(str_value($data['comment'] ?? '')), ENT_QUOTES, 'UTF-8', false);
        if ($comment === '') {
            $this->response->json(['success' => false, 'message' => 'متن کامنت الزامی است'], 422);
            return;
        }

        $result = $this->ticketService->reply($id, $adminId, $comment, true);
        if ($result['success'] ?? false) {
            $this->auditLog('bug_report_admin_comment', 'bug_report', $id, null, [
                'comment_length' => mb_strlen((string)$comment),
                'has_sanitized' => true
            ]);
        }

        $this->response->json($result);
    }

    /**
     * تغییر وضعیت مشکوک (Deprecated in unified model)
     */
    public function toggleSuspicious(): void
    {
        $this->response->json(['success' => true, 'message' => 'ویژگی در مدل یکپارچه لغو شده است']);
    }

    /**
     * بستن تیکت (به جای حذف نرم)
     */
    public function delete(): void
    {
        $this->validateCsrf();

        // 🛡️ CRIT-02: بررسی دسترسی ادمین
        $adminId = $this->requireAdminId();
        if (!$this->policyService->authorizeById('bug_reports.view', $adminId)) {
            $this->response->json(['success' => false, 'message' => 'دسترسی غیرمجاز.'], 403);
            return;
        }

        $id = $this->request->int('id');

        // 🛡️ MED-06: تایید اصالت گزارش باگ
        $oldReport = $this->ticketService->findBugReport($id);
        if (!$oldReport) {
            $this->response->json(['success' => false, 'message' => 'گزارش یافت نشد.'], 404);
            return;
        }
        $oldStatus = $oldReport->status;

        $result = $this->ticketService->close($id, $adminId, true);
        if ($result['success'] ?? false) {
            $this->auditLog('bug_report_closed', 'bug_report', $id, ['status' => $oldStatus], ['status' => 'closed']);
        }

        $this->response->json($result);
    }
}

