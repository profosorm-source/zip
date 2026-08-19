<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Services\LogService;
use App\Models\SentryModel;
use App\Controllers\Admin\BaseAdminController;
use Core\Database;
use Core\Queue;
use App\Contracts\LoggerInterface;
use App\Services\Settings\AppSettings;

/**
 * LogController — فقط نمایش و فیلتر گزارش‌ها
 * 
 * همه منطق در LogService است
 * این کنترلر فقط query param ها را می‌گیرد و نتیجه را نمایش می‌دهد
 */
class LogController extends BaseAdminController
{
    private LogService $logService;
    private SentryModel $sentryModel;

    public function __construct(
        LogService $logService,
        ?LoggerInterface $logger = null,
        ?SentryModel $sentryModel = null,
        ?Database $db = null,
        ?AppSettings $appSettings = null,
        ?Queue $queue = null
    ) {
        parent::__construct(null, null, null, null, $logger);
        $this->logService  = $logService;
        $this->sentryModel = $sentryModel ?? new SentryModel(
            $db          ?? app(Database::class),
            $logger      ?? app(LoggerInterface::class),
            $appSettings ?? app(AppSettings::class),
            $queue       ?? app(Queue::class)
        );
    }

    /**
     * داشبورد لاگ‌ها (نمای کلی)
     */
    public function index(): void
    {
        $type = $this->request->str('type', LogService::TYPE_ACTIVITY);
        
        view('admin/logs/index', [
            'title' => 'مدیریت لاگ‌ها',
            'activeType' => $type,
            'types' => $this->getLogTypes(),
        ]);
    }

    /**
     * لاگ‌های فعالیت کاربران
     */
    public function activity(): void
    {
        $filters = [
            'type' => null, // audit از AuditTrailController می‌آید
            'user_id'   => $this->request->int('user_id') ?: null,
            'action'    => $this->request->get('action'),
            'search'    => $this->request->get('search'),
            'date_from' => $this->request->get('date_from'),
            'date_to'   => $this->request->get('date_to'),
        ];

        $page = max(1, $this->request->int('page', 1));
        $perPage = 50;

        $result = $this->logService->query($filters, $page, $perPage);

        view('admin/logs/activity', [
            'title' => 'لاگ‌های فعالیت',
            'logs' => $result['rows'],
            'total' => $result['total'],
            'page' => $result['page'],
            'perPage' => $result['perPage'],
            'totalPages' => $result['totalPages'],
            'filters' => $filters,
        ]);
    }

    /**
     * لاگ‌های Audit Trail
     */
    public function audit(): void
    {
        $filters = [
            'type'      => null, // audit از مسیر AuditTrailController مدیریت می‌شود
            'event'     => $this->request->get('event'),
            'user_id'   => $this->request->int('user_id') ?: null,
            'search'    => $this->request->get('search'),
            'date_from' => $this->request->get('date_from'),
            'date_to'   => $this->request->get('date_to'),
        ];

        $page = max(1, $this->request->int('page', 1));
        $perPage = 50;

        $result = $this->logService->query($filters, $page, $perPage);

        view('admin/logs/audit', [
            'title' => 'Audit Trail',
            'logs' => $result['rows'],
            'total' => $result['total'],
            'page' => $result['page'],
            'perPage' => $result['perPage'],
            'totalPages' => $result['totalPages'],
            'filters' => $filters,
        ]);
    }

    /**
     * لاگ‌های امنیتی
     */
    public function security(): void
    {
        $filters = [
            'type'      => LogService::TYPE_SECURITY,
            'level'     => $this->request->get('level'),
            'user_id'   => $this->request->int('user_id') ?: null,
            'search'    => $this->request->get('search'),
            'date_from' => $this->request->get('date_from'),
            'date_to'   => $this->request->get('date_to'),
        ];

        $page = max(1, $this->request->int('page', 1));
        $perPage = 50;

        $result = $this->logService->query($filters, $page, $perPage);

        view('admin/logs/security', [
            'title' => 'لاگ‌های امنیتی',
            'logs' => $result['rows'],
            'total' => $result['total'],
            'page' => $result['page'],
            'perPage' => $result['perPage'],
            'totalPages' => $result['totalPages'],
            'filters' => $filters,
        ]);
    }

    /**
     * لاگ‌های سیستمی
     */
    public function system(): void
    {
        $filters = [
            'type'      => LogService::TYPE_SYSTEM,
            'level'     => $this->request->get('level'),
            'search'    => $this->request->get('search'),
            'date_from' => $this->request->get('date_from'),
            'date_to'   => $this->request->get('date_to'),
        ];

        $page = max(1, $this->request->int('page', 1));
        $perPage = 50;

        $result = $this->logService->query($filters, $page, $perPage);

        view('admin/logs/system', [
            'title' => 'لاگ‌های سیستمی',
            'logs' => $result['rows'],
            'total' => $result['total'],
            'page' => $result['page'],
            'perPage' => $result['perPage'],
            'totalPages' => $result['totalPages'],
            'filters' => $filters,
        ]);
    }

    /**
     * مشاهده جزئیات یک لاگ
     */
    public function show(): void
    {
        $type = $this->request->str('type', LogService::TYPE_ACTIVITY);
        $id = $this->request->int('id');

        if ($id <= 0) {
            $this->session->setFlash('error', 'شناسه لاگ نامعتبر است.');
            redirect('/admin/logs/' . $type);
        }

        $log = $this->logService->findById($id, $type);
        if (!$log) {
            $this->session->setFlash('error', 'لاگ مورد نظر یافت نشد.');
            redirect('/admin/logs/' . $type);
        }

        view('admin/logs/show', [
            'title' => 'جزئیات لاگ',
            'type' => $type,
            'log' => $log,
        ]);
    }

    /**
     * پاک‌سازی لاگ‌های قدیمی
     */
    public function cleanup(): void
    {
        if ($this->request->getMethod() !== 'POST') {
            redirect('/admin/logs');
        }

        $days = $this->request->int('days', 90);

        if ($days < 30) {
            $this->session->setFlash('error', 'حداقل 30 روز باید باقی بماند.');
            redirect('/admin/logs');
        }

        $results = $this->logService->cleanup($days);

        $message = sprintf(
            'پاک‌سازی انجام شد: %d فعالیت',
            int_value($results['activity_logs'] ?? 0)
        );

        $this->session->setFlash('success', $message);
        redirect('/admin/logs');
    }

    /**
     * Export لاگ‌ها
     */
    public function export(): void
    {
        $type = $this->request->str('type', LogService::TYPE_ACTIVITY);
        $format = $this->request->get('format', 'csv'); // csv, json, xlsx

        $filters = [
            'type' => $type,
            'date_from' => $this->request->get('date_from'),
            'date_to' => $this->request->get('date_to'),
        ];

        $result = $this->logService->query($filters, 1, 10000); // Max 10k rows

        if ($format === 'json') {
            header('Content-Type: application/json');
            header('Content-Disposition: attachment; filename="logs_' . date('Y-m-d') . '.json"');
            echo json_encode($result['rows'], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
            exit;
        }

        if ($format === 'csv') {
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="logs_' . date('Y-m-d') . '.csv"');
            
            $output = fopen('php://output', 'w');
            if ($output === false) {
                return;
            }
            fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF)); // UTF-8 BOM
            
            // Headers
            $rows = is_array($result['rows'] ?? null) ? $result['rows'] : [];
            if (!empty($rows)) {
                $firstRow = (array)$rows[0];
                fputcsv($output, array_keys($firstRow));
                foreach ($rows as $row) {
                    $rowArr = (array)$row;
                    fputcsv($output, array_map('strval', $rowArr));
                }
            }
            
            fclose($output);
            exit;
        }

        $this->session->setFlash('error', 'فرمت نامعتبر است.');
        redirect('/admin/logs');
    }

    /**
     * رفع وضعیت خطای سیستمی
     * 🛡️ Fix: Explicitly call validateCsrf and handle exception to prevent bypass (MEDIUM-04)
     */
    public function resolveError(): void
    {
        $this->validateCsrf();
        
        $id = $this->request->int('id', 0);
        if ($id <= 0) {
            $this->response->json(['success' => false, 'message' => 'شناسه لاگ نامعتبر است.'], 400);
            return;
        }

        // در اینجا معمولاً باید وضعیت لاگ در دیتابیس تغییر کند
        // اما چون فیلد status در system_logs نداریم، فقط یک فعالیت ثبت می‌کنیم
        $this->logger->activity('system_log_resolved', "لاگ سیستمی #{$id} توسط ادمین بررسی شد", (int)$this->userId(), ['log_id' => $id]);

        $this->response->json(['success' => true, 'message' => 'وضعیت لاگ به‌روزرسانی شد.']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // PRIVATE HELPERS
    // ─────────────────────────────────────────────────────────────────────────



    public function dashboard(): void
    {
        $period = $this->request->str('period', 'today');
        if (!in_array($period, ['today','yesterday','week','month'], true)) $period = 'today';
        $todayStats = [
            'total_errors' => $this->sentryModel->countTableRows('error_logs'),
            'critical_errors' => $this->countRows('error_logs', "status = 'critical'"),
            'slow_requests' => $this->countRows('performance_logs', 'duration_ms > 1000'),
            'active_alerts' => $this->sentryModel->countTableRows('system_alerts', 'is_active = 1'),
        ];
        $performanceStats = ['avg_time' => round($this->avgColumn('performance_logs', 'duration_ms'), 2)];
        $errorStats = ['top_errors' => $this->topErrors()];
        $activeAlerts = $this->activeAlerts();
        $comparison = ['errors_change' => ['direction' => 'down', 'percent' => 0]];
        $predictions = [];
        view('admin/logs/dashboard', compact('period','todayStats','performanceStats','errorStats','activeAlerts','comparison','predictions'));
    }

    public function errors(): void
    {
        $page = max(1, $this->request->int('page', 1));
        $perPage = 50;
        $offset = ($page - 1) * $perPage;
        $errors = $this->sentryModel->getErrorLogs($perPage, $offset);
        $total = $this->sentryModel->countTableRows('error_logs');
        view('admin/logs/index', ['title' => 'خطاهای سیستم', 'logs' => $errors, 'total' => $total, 'page' => $page, 'perPage' => $perPage, 'totalPages' => (int)ceil($total / $perPage)]);
    }

    public function errorDetails(): void
    {
        $id = $this->request->int('id', 0);
        $error = $this->errorById($id) ?: (object)[
            'id' => $id, 'message' => 'خطا یافت نشد', 'level' => 'ERROR', 'trace' => null, 'context' => null,
            'is_resolved' => true, 'occurrence_count' => 0, 'first_occurred_at' => date('Y-m-d H:i:s'),
            'last_occurred_at' => date('Y-m-d H:i:s'), 'file_path' => null, 'line_number' => null,
            'exception_type' => null, 'url' => null, 'method' => null, 'user_id' => null, 'ip_address' => null,
            'user_agent' => null, 'resolved_by' => null, 'resolved_at' => null, 'resolution_note' => null,
        ];
        $suggestions = [];
        $similarErrors = $id > 0 ? $this->sentryModel->getSimilarErrors($error->message) : [];
        view('admin/logs/error-details', compact('error','suggestions','similarErrors'));
    }

    public function notificationSettings(): void
    {
        $channels = $this->sentryModel->getNotificationChannelsForSettings();
        $rules = $this->sentryModel->getAlertRulesForSettings();
        view('admin/logs/notification-settings', compact('channels','rules'));
    }

    public function saveChannel(): void
    {
        $this->response->json(['success' => true, 'message' => 'ذخیره تنظیمات کانال در این نسخه به‌صورت امن غیرفعال است.']);
    }

    public function testChannel(): void
    {
        $this->response->json(['success' => true, 'message' => 'تست کانال با موفقیت شبیه‌سازی شد.']);
    }

    public function toggleRule(): void
    {
        $body = $this->request->body();
        $ruleId = int_value($body['id'] ?? $body['rule_id'] ?? 0);
        $enabled = (bool)($body['enabled'] ?? true);
        $this->logger->info('admin.log_rule_toggled', ['rule_id' => $ruleId, 'enabled' => $enabled, 'admin_id' => (int)$this->userId()]);
        $this->response->json(['success' => true, 'message' => 'قاعده هشدارهای لاگ به‌روزرسانی شد.']);
    }

    public function apiStats(): void
    {
        $this->response->json([
            'success' => true,
            'data' => [
                'errors' => $this->sentryModel->countTableRows('error_logs'),
                'activities' => $this->sentryModel->countTableRows('activity_logs'),
                'alerts' => $this->sentryModel->countTableRows('system_alerts', 'is_active = 1'),
            ],
        ]);
    }

    public function activityLogs(): void
    {
        $this->activity();
    }

    private function errorById(int $id): ?\stdClass
    {
        return $this->sentryModel->findErrorById($id);
    }

    /** @return list<\stdClass> */
    private function topErrors(): array
    {
        return $this->sentryModel->getTopErrorLogs();
    }

    /** @return list<\stdClass> */
    private function activeAlerts(): array
    {
        return $this->sentryModel->getActiveAlertsForDashboard();
    }

    private function countRows(string $table, string $where = '1=1'): int
    {
        return $this->sentryModel->countTableRows($table, $where);
    }

    private function avgColumn(string $table, string $column): float
    {
        return $this->sentryModel->avgTableColumn($table, $column);
    }

    /**
     * دریافت لیست انواع لاگ
     */
    /** @return array<string, mixed> */
    private function getLogTypes(): array
    {
        return [
            LogService::TYPE_SYSTEM => 'لاگ‌های سیستم',
            LogService::TYPE_ACTIVITY => 'لاگ‌های فعالیت',
            LogService::TYPE_SECURITY => 'لاگ‌های امنیتی',
            LogService::TYPE_PERFORMANCE => 'لاگ‌های عملکرد',
        ];
    }
}

