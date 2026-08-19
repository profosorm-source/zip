<?php

namespace App\Controllers\Admin;

use App\Models\SentryModel;
use App\Services\Sentry\Analytics\DashboardService;
use App\Services\Sentry\Analytics\TrendAnalyzer;
use App\Services\Sentry\Alerting\AlertRulesEngine;
use App\Services\Sentry\Alerting\EscalationManager;
use App\Services\Sentry\Audit\AdvancedAuditTrail;
use Core\Response;

/**
 * 🎛️ SentryAdminController - کنترلر پنل ادمین Sentry
 */
class SentryAdminController extends BaseAdminController
{
    private DashboardService $dashboard;
    private TrendAnalyzer $trendAnalyzer;
    private AlertRulesEngine $alertRules;
    private EscalationManager $escalation;
    private AdvancedAuditTrail $audit;
    private SentryModel $model;

    public function __construct(
        DashboardService $dashboard,
        TrendAnalyzer $trendAnalyzer,
        AlertRulesEngine $alertRules,
        EscalationManager $escalation,
        AdvancedAuditTrail $audit,
        SentryModel $model,
        ?\App\Contracts\LoggerInterface $logger = null
    ) {
        parent::__construct(null, null, null, null, $logger);
        $this->dashboard = $dashboard;
        $this->trendAnalyzer = $trendAnalyzer;
        $this->alertRules = $alertRules;
        $this->escalation = $escalation;
        $this->audit = $audit;
        $this->model = $model;
    }

    /**
     * 🏠 Dashboard Overview
     */
    public function index(): void
    {
        $data = [
            'overview' => $this->dashboard->getOverview(),
            'trends' => [
                'errors' => $this->trendAnalyzer->analyzeTrends('errors', 7),
                'performance' => $this->trendAnalyzer->analyzeTrends('performance', 7),
            ],
            'escalation_stats' => $this->escalation->getStatistics(),
        ];

        $this->view('admin/sentry/dashboard', $this->normalizeViewData($data));
    }

    /**
     * 🚨 Issues List
     */
    public function issues(): void
    {
        $page = int_value($this->request->query('page', 1));
        $status = $this->request->query('status', 'unresolved');
        $levelRaw = $this->request->query('level');
        $level = $levelRaw !== null ? str_value($levelRaw) : null;

        $issues = $this->dashboard->getIssuesList($page, str_value($status), $level);

        $this->view('admin/sentry/issues', [
            'issues' => $this->normalizeViewData($issues),
            'status' => $status,
            'level' => $level,
        ]);
    }

    /**
     * 📝 Issue Details
     */
    public function issueDetails(int $id): void
    {
        $issue = $this->dashboard->getIssueDetails($id);

        if (!$issue) {
            $this->response->html('Not found', 404);
            return;
        }

        $this->view('admin/sentry/issue-details', [
            'issue' => $this->normalizeViewData($issue),
            'events' => $this->normalizeViewData($issue->events),
        ]);
    }

    /**
     * 🧾 Failed Jobs / DLQ
     */
    public function failedJobs(): void
    {
        $page = int_value($this->request->query('page', 1));
        $queueRaw = $this->request->query('queue');
        $queue = $queueRaw !== null ? str_value($queueRaw) : null;

        $failedJobs = $this->dashboard->getFailedJobsList($page, 20, $queue);
        $summary = $this->dashboard->getFailedJobsOverview();

        $this->view('admin/sentry/failed-jobs', [
            'failed_jobs' => $this->normalizeViewData($failedJobs),
            'summary' => $this->normalizeViewData($summary),
            'queue_counts' => $this->normalizeViewData($this->model->getFailedJobQueueCounts(20)),
        ]);
    }

    /**
     * 📤 Outbox DLQ
     */
    public function outboxDLQ(): void
    {
        $page = int_value($this->request->query('page', 1));

        $outbox = $this->dashboard->getOutboxDLQList($page, 20);
        $summary = $this->dashboard->getOutboxSummary();

        $this->view('admin/sentry/outbox-dlq', [
            'outbox' => $this->normalizeViewData($outbox),
            'summary' => $this->normalizeViewData($summary),
        ]);
    }

    /**
     * 📋 Failed Job Details
     */
    public function failedJobDetails(int $id): void
    {
        $job = $this->dashboard->getFailedJobDetails($id);
        if (!$job) {
            $this->response->html('Not found', 404);
            return;
        }

        $this->view('admin/sentry/failed-job-details', [
            'job' => $this->normalizeViewData($job),
        ]);
    }

    /**
     * 🔁 Retry Failed Job
     */
    public function retryFailedJob(int $id): void
    {
        if ($this->dashboard->retryFailedJob($id)) {
            $this->response->json(['success' => true]);
            return;
        }

        $this->response->json(['success' => false, 'error' => 'Unable to retry failed job']);
    }

    /**
     * 🗑️ Forget Failed Job
     */
    public function forgetFailedJob(int $id): void
    {
        if ($this->dashboard->forgetFailedJob($id)) {
            $this->response->json(['success' => true]);
            return;
        }

        $this->response->json(['success' => false, 'error' => 'Unable to delete failed job']);
    }

    /**
     * 🚀 Performance Monitor
     */
    public function performance(): void
    {
        $period = $this->request->query('period', '24h');
        
        $data = [
            'stats' => $this->dashboard->getPerformanceStatistics(),
            'slowest_endpoints' => $this->dashboard->getTopSlowestEndpoints(20),
            'time_series' => $this->dashboard->getTimeSeriesData('performance', str_value($period)),
            'degradation' => $this->trendAnalyzer->getPerformanceDegradation(),
        ];

        $this->view('admin/sentry/performance', $this->normalizeViewData($data));
    }

    /**
     * 📊 Analytics
     */
    public function analytics(): void
    {
        $metric = $this->request->query('metric', 'errors');
        $days = int_value($this->request->query('days', 7));

        $metric = str_value($metric);
        $data = [
            'trends' => $this->trendAnalyzer->analyzeTrends($metric, $days),
            'hotspots' => $this->trendAnalyzer->getErrorHotspots(),
            'error_sources' => $this->dashboard->getTopErrorSources(15),
            'time_series' => $this->dashboard->getTimeSeriesData($metric, "{$days}d", '1h'),
        ];

        $this->view('admin/sentry/analytics', $this->normalizeViewData($data));
    }

    /**
     * 🔔 Alerts Management
     */
    public function alerts(): void
    {
        $activeAlerts = $this->alertRules->getActiveAlerts();
        $rules = $this->alertRules->getAlertRules();

        $this->view('admin/sentry/alerts', [
            'active_alerts' => $this->normalizeViewData($activeAlerts),
            'rules' => $this->normalizeViewData($rules),
        ]);
    }

    /**
     * ✅ Acknowledge Alert
     */
    public function acknowledgeAlert(): void
    {
        $alertId = int_value($this->request->input('alert_id', 0));
        $noteRaw = $this->request->input('note');
        $note = $noteRaw !== null ? str_value($noteRaw) : null;
        $userId = $this->requireAdminId();

        if ($this->escalation->acknowledgeAlert($alertId, $userId, $note)) {
            $this->response->json(['success' => true]);
        } else {
            $this->response->json(['success' => false, 'error' => 'Failed to acknowledge']);
        }
    }

    /**
     * 📋 Audit Trail
     */
    public function auditTrail(): void
    {
        $filters = [
            'user_id' => $this->request->query('user_id'),
            'event' => $this->request->query('event'),
            'category' => $this->request->query('category'),
            'date_from' => $this->request->query('date_from'),
            'date_to' => $this->request->query('date_to'),
            'page' => int_value($this->request->query('page', 1)),
            'per_page' => 50,
        ];

        $results = $this->audit->search($filters);

        $this->view('admin/sentry/audit-trail', [
            'results' => $this->normalizeViewData($results),
            'filters' => $filters,
        ]);
    }

    /**
     * 📄 Generate Compliance Report
     */
    public function generateReport(): void
    {
        $startDate = $this->request->input('start_date', date('Y-m-d', (strtotime('-30 days') ?: time())));
        $endDate = $this->request->input('end_date', date('Y-m-d'));
        $type = $this->request->input('type', 'full');

        $report = $this->audit->generateComplianceReport(str_value($startDate), str_value($endDate), str_value($type));

        $this->response->json($report);
    }

    /**
     * 💾 Export Audit
     */
    public function exportAudit(): void
    {
        $filters = [
            'user_id' => $this->request->query('user_id'),
            'date_from' => $this->request->query('date_from'),
            'date_to' => $this->request->query('date_to'),
        ];

        $filename = 'audit_export_' . date('Y-m-d_H-i-s') . '.csv';
        $path = $this->audit->exportToCSV($filters, $filename);

        // دانلود فایل
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        readfile($path);
        exit;
    }

    /**
     * 🔧 Resolve Issue
     */
    public function resolveIssue(): void
    {
        $issueId = int_value($this->request->input('issue_id', 0));
        $note = $this->request->input('note', '');
        $userIdValue = $this->session->get('user_id');
        $userId = is_int($userIdValue) ? $userIdValue : (is_numeric($userIdValue) ? (int)$userIdValue : 0);

        if ($this->dashboard->resolveIssue($issueId, $userId, str_value($note))) {
            $this->response->json(['success' => true]);
        } else {
            $this->response->json(['success' => false, 'error' => 'Failed to resolve issue']);
        }
    }

    /**
     * 🔕 Mute Issue
     */
    public function muteIssue(): void
    {
        $issueId = int_value($this->request->input('issue_id', 0));
        $duration = $this->request->input('duration', '7d');

        if ($this->dashboard->muteIssue($issueId, str_value($duration))) {
            $this->response->json(['success' => true]);
        } else {
            $this->response->json(['success' => false, 'error' => 'Failed to mute issue']);
        }
    }

    /**
     * 📊 Get Chart Data (API)
     */
    public function getChartData(): void
    {
        $metric = $this->request->query('metric', 'errors');
        $period = $this->request->query('period', '24h');
        $interval = $this->request->query('interval', '1h');

        $data = $this->dashboard->getTimeSeriesData(str_value($metric), str_value($period), str_value($interval));

        $this->response->json($data);
    }

    /**
     * 💚 Health Check (API)
     */
    public function healthCheck(): void
    {
        $health = $this->dashboard->calculateHealthScore();
        $this->response->json($health);
    }

    // ==========================================
    // Helper Methods
    // ==========================================
    /** @return array<array-key, mixed> */
    private function normalizeViewData(mixed $value): array
    {
        if (is_object($value)) {
            $value = get_object_vars($value);
        }
        if (!is_array($value)) {
            return [];
        }
        $result = [];
        foreach ($value as $key => $item) {
            $result[$key] = (is_array($item) || is_object($item))
                ? $this->normalizeViewData($item)
                : $item;
        }
        return $result;
    }

}
