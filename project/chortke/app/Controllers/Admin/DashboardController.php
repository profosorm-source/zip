<?php

namespace App\Controllers\Admin;

use App\Services\AdminDashboard\AdminDashboardService;
use App\Controllers\Admin\BaseAdminController;

class DashboardController extends BaseAdminController
{
    private AdminDashboardService $dashboardService;

    public function __construct(AdminDashboardService $dashboardService, ?\App\Contracts\LoggerInterface $logger = null) {
        parent::__construct(null, null, null, null, $logger);
        $this->dashboardService = $dashboardService;
    }

    // ══════════════════════════════════════════════════════════
    // صفحه اصلی داشبورد
    // ══════════════════════════════════════════════════════════

   public function index(): void
{
    $userId = (int)$this->userId();
    if ($userId <= 0) {
        $this->session->setFlash('error', 'لطفاً وارد شوید.');
        redirect('/admin/login');
    }

    try {
        $data = $this->dashboardService->getDashboardData($userId);
    } catch (\Throwable $e) {
        $this->logger->error('admin.dashboard.index.failed', [
            'channel' => 'admin',
            'error' => $e->getMessage(),
            'exception' => get_class($e),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
        ]);
        $this->session->setFlash('error', 'خطا در دریافت اطلاعات داشبورد');
        redirect('/admin/login');
    }

    $data = is_array($data) ? $data : [];

    $currentUser = $data['currentUser'] ?? [];
    $fullName = is_array($currentUser)
        ? (string)($currentUser['full_name'] ?? 'مدیر')
        : 'مدیر';

    $this->view('admin/dashboard', [
        'title' => 'داشبورد مدیریت',
        'stats' => $data['stats'] ?? [],
        'chartData' => $data['chartData'] ?? [],
        'recentUsers' => $data['recentUsers'] ?? [],
        'pendingWithdrawalsList' => $data['pendingWithdrawalsList'] ?? [],
        'recentActivities' => $data['recentActivities'] ?? [],
        'adminAccessLog' => $data['adminAccessLog'] ?? [],
        'user' => is_array($currentUser) ? $currentUser : [],
        'fullName' => $fullName,
    ]);
}

    // ══════════════════════════════════════════════════════════
    // GET /admin/dashboard/recent-activity
    // پارامترها: ?type=all&limit=20&page=1
    // ══════════════════════════════════════════════════════════

    public function recentActivity(): void
    {
        $type = $this->request->str('type', 'all');
        $limit = min($this->request->int('limit', 20), 100);
        $page  = max($this->request->int('page', 1), 1);

        try {
    $result = $this->dashboardService->getRecentActivity($type, $limit, $page);

    header('Content-Type: application/json');
    echo json_encode([
        'success' => true,
        'data' => $result['items'],
        'stats' => $result['stats'],
        'page' => $page,
        'limit' => $limit,
    ], JSON_UNESCAPED_UNICODE);

} catch (\Throwable $e) {
    $this->logger->error('admin.dashboard.recent_activity.failed', [
        'channel' => 'admin',
        'type' => $type,
        'limit' => $limit ?? null,
        'page' => $page ?? null,
        'error' => $e->getMessage(),
        'exception' => get_class($e),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
    ]);

    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'خطا در دریافت فعالیت‌ها'], JSON_UNESCAPED_UNICODE);
}
    }

    // ══════════════════════════════════════════════════════════
    // GET /admin/dashboard/system-status
    // ══════════════════════════════════════════════════════════

    public function systemStatus(): void
    {
        try {
    $raw = $this->dashboardService->getSystemStatus();

    // Restructure for dashboard.js compatibility
    $data = [
        'resources'  => [
            'cpu'  => $raw['cpu']  ?? [],
            'ram'  => $raw['ram']  ?? [],
            'disk' => $raw['disk'] ?? [],
            'gpu'  => $raw['gpu']  ?? ['available' => false],
        ],
        'database'   => $raw['database']   ?? [],
        'uptime'     => $raw['uptime']     ?? 'نامشخص',
        'queues'     => $raw['queues']     ?? [],
        'services'   => $raw['services']   ?? [],
        'cron_jobs'  => $raw['cron_jobs']  ?? [],
        'payment_gates' => $raw['payment_gates'] ?? [],
        'email_queue'=> $raw['email_queue'] ?? [],
    ];

    header('Content-Type: application/json');
    echo json_encode(['success' => true, 'data' => $data], JSON_UNESCAPED_UNICODE);

} catch (\Throwable $e) {
    $this->logger->error('admin.dashboard.system_status.failed', [
        'channel' => 'admin',
        'error' => $e->getMessage(),
        'exception' => get_class($e),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
    ]);

    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'خطا در دریافت وضعیت سیستم'], JSON_UNESCAPED_UNICODE);
}
    }
}
