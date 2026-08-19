<?php

declare(strict_types=1);

namespace App\Services\Sentry\Audit;

use App\Models\SentryModel;
use Core\Logger;
use App\Services\AuditTrail;
use Core\Session;

/**
 * 📋 AdvancedAuditTrail - سیستم پیشرفته Audit Trail
 */
class AdvancedAuditTrail
{
    /** @var array<string, mixed> */
    private array $config = [
        'retention_days' => 90,
        'batch_size' => 100,
        'enable_compression' => true,
    ];

    private SentryModel $model;
    private Logger $logger;
    private AuditTrail $auditTrail;
    private Session $session;
    private \Core\PathResolver $paths;
    /** @param array<string, mixed> $config */
    public function __construct(
        SentryModel $model,
        Logger $logger,
        AuditTrail $auditTrail,
        Session $session,
        \Core\PathResolver $paths,
        array $config = []
    ) {        $this->model = $model;
        $this->logger = $logger;
        $this->auditTrail = $auditTrail;
        $this->session = $session;
        $this->paths = $paths;

        $this->config = array_merge($this->config, $config);
    }

    /**
     * 📝 Record Event
     */
    /** @param array<string, mixed> $context */
    public function record(string $event, ?int $userId = null, array $context = [], ?int $actorId = null, string $category = 'general'): void
    {
        try {
            $enrichedContext = $this->enrichContext($context);
            if ($actorId === null) {
                $actorId = $this->detectActor();
            }

            $this->auditTrail->record($event, $userId, array_merge($enrichedContext, ['category' => $category]), $actorId);
        } catch (\Throwable $e) {
            $this->logger->error('sentry.advanced_audit.record.failed', ['channel' => 'sentry', 'event' => $event, 'error' => $e->getMessage()]);
        }
    }

    /**
     * 🔍 Advanced Search
     */
    /** @param array<string, mixed> $filters
     *  @return array<string, mixed> */
    public function search(array $filters): array
    {
        // AU3: Explicit whitelist of permitted filter vectors to fully defeat arbitrary SQL Injection fields
        $allowedKeys = ['user_id', 'event', 'category', 'date_from', 'date_to', 'ip_address', 'context_search', 'page', 'per_page'];
        $filters = array_intersect_key($filters, array_flip($allowedKeys));

        $where = ['1=1'];
        $params = [];

        if (!empty($filters['user_id'])) {
            $where[] = '(at.user_id = ? OR at.actor_id = ?)';
            $params[] = $filters['user_id'];
            $params[] = $filters['user_id'];
        }

        if (!empty($filters['event'])) {
            $where[] = 'at.event LIKE ?';
            $params[] = '%' . $filters['event'] . '%';
        }

        if (!empty($filters['category'])) {
            $where[] = 'at.category = ?';
            $params[] = $filters['category'];
        }

        if (!empty($filters['date_from'])) {
            $where[] = 'at.created_at >= ?';
            $params[] = $filters['date_from'];
        }
        if (!empty($filters['date_to'])) {
            $where[] = 'at.created_at <= ?';
            $params[] = $filters['date_to'];
        }

        if (!empty($filters['ip_address'])) {
            $where[] = 'at.ip_address = ?';
            $params[] = $filters['ip_address'];
        }

        if (!empty($filters['context_search'])) {
            $where[] = 'at.context LIKE ?';
            $params[] = '%' . $filters['context_search'] . '%';
        }

        $page = (int)(is_numeric($filters['page'] ?? null) ? $filters['page'] : 1);
        $perPage = (int)(is_numeric($filters['per_page'] ?? null) ? $filters['per_page'] : 50);
        $offset = ($page - 1) * $perPage;

        $whereClause = implode(' AND ', $where);

        $total = $this->model->getAuditCount($whereClause, $params);
        $records = $this->model->searchAuditRecords($whereClause, $params, $perPage, $offset);

        return [
            'records' => $records,
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'total_pages' => ceil($total / $perPage),
        ];
    }

    /**
     * 📊 Generate Compliance Report
     */
    /** @return array<string, mixed> */
    public function generateComplianceReport(string $startDate, string $endDate, string $type = 'full'): array
    {
        $report = [
            'period' => ['start' => $startDate, 'end' => $endDate],
            'generated_at' => date('Y-m-d H:i:s'),
            'type' => $type,
        ];

        $report['summary'] = $this->getReportSummary($startDate, $endDate);
        $report['by_category'] = $this->model->getAuditEventsByCategory($startDate, $endDate);
        $report['critical_events'] = $this->getCriticalEvents($startDate, $endDate);

        if ($type === 'full' || $type === 'user_activity') {
            $report['user_activity'] = $this->model->getAuditUserActivity($startDate, $endDate);
        }

        if ($type === 'full' || $type === 'access_patterns') {
            $report['access_patterns'] = $this->model->getAuditAccessPatterns($startDate, $endDate);
        }

        if ($type === 'full' || $type === 'security') {
            $report['failed_operations'] = $this->model->getAuditFailedOperations($startDate, $endDate);
        }

        return $report;
    }

    /**
     * 💾 Export to CSV
     */
    /** @param array<string, mixed> $filters */
    public function exportToCSV(array $filters, string $filename): string
    {
        // AU1: Sanitize input to block directory traversal & restrict path disclosure
        $filename = basename($filename);
        $filename = preg_replace('/[^A-Za-z0-9_\-\.]/', '', $filename);
        if (empty($filename)) {
            $filename = 'audit_export_' . date('YmdHis') . '.csv';
        }
        if (!str_ends_with(strtolower((string)$filename), '.csv')) {
            $filename .= '.csv';
        }

        $path = $this->paths->storage('exports/' . $filename);
        if (!is_dir(dirname($path))) mkdir(dirname($path), 0755, true);

        $csv = fopen($path, 'w');
        if ($csv === false) {
            throw new \RuntimeException('Unable to open audit export file for writing.');
        }
        fputcsv($csv, ['ID', 'Event', 'Category', 'User', 'Actor', 'IP Address', 'Created At', 'Context']);

        // AU2: Execute incremental retrieval batches to suppress OOM overflows under 10,000 record loads
        $page = 1;
        $perPage = 500;
        $totalLimit = 10000;
        $fetchedCount = 0;

        do {
            $batchFilters = array_merge($filters, ['page' => $page, 'per_page' => $perPage]);
            $data = $this->search($batchFilters);
            
            if (empty($data['records'])) {
                break;
            }

            /** @var list<\stdClass> $records */
            $records = $data['records'];
            foreach ($records as $record) {
                fputcsv($csv, [
                    $record->id, 
                    $record->event, 
                    $record->category, 
                    $record->user_email ?? '-', 
                    $record->actor_email ?? '-', 
                    $record->ip_address, 
                    $record->created_at, 
                    $record->context
                ]);
                $fetchedCount++;
            }
            
            $page++;
            $hasMore = $fetchedCount < $data['total'] && $fetchedCount < $totalLimit && count($records) === $perPage;
        } while ($hasMore);

        fclose($csv);

        // AU4: Compute SHA-256 integrity checksums on output export resources
        $content = file_get_contents($path);
        $checksum = hash('sha256', $content ?: '');
        file_put_contents($path . '.sha256', $checksum);

        return $path;
    }

    /**
     * 🗑️ Data Retention
     */
    public function cleanupOldRecords(): int
    {
        try {
            $cutoffDate = date('Y-m-d', (strtotime("-{(string)(int)\$this->config['retention_days']} days") ?: time()));

            if ($this->config['enable_compression']) {
                $this->archiveOldRecords($cutoffDate);
            }

            $deleted = $this->model->deleteOldAuditRecords($cutoffDate);
            if ($deleted > 0) {
                $this->logger->info("Cleaned up {$deleted} old audit records");
            }
            return $deleted;
        } catch (\Throwable $e) {
            $this->logger->error('Cleanup failed', ['error' => $e->getMessage()]);
            return 0;
        }
    }

    private function archiveOldRecords(string $cutoffDate): void
    {
        try {
            $archiveFile = "audit_archive_" . date('Y-m-d') . ".json.gz";
            $archivePath = $this->paths->storage('archives/' . $archiveFile);

            $oldRecords = $this->model->getOldAuditRecords($cutoffDate);
            if (empty($oldRecords)) return;

            $json = (string)json_encode($oldRecords, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
            $compressed = gzencode($json, 9);
            
            if (!is_dir(dirname($archivePath))) mkdir(dirname($archivePath), 0755, true);
            file_put_contents($archivePath, $compressed);

            // AU4: Store complementary SHA-256 hash of compressed binary payload for checksumming
            $checksum = hash('sha256', $compressed ?: '');
            file_put_contents($archivePath . '.sha256', $checksum);

            $this->logger->info("Archived " . count($oldRecords) . " records with checksum validation to {$archiveFile}");
        } catch (\Throwable $e) {
            $this->logger->error('Archive failed', ['error' => $e->getMessage()]);
        }
    }

    /** @return array<string, mixed> */
    public function compareChanges(int $recordId1, int $recordId2): array
    {
        $record1 = $this->model->getAuditRecordById($recordId1);
        $record2 = $this->model->getAuditRecordById($recordId2);

        if (!$record1 || !$record2) return ['error' => 'Records not found'];

        $context1Raw = json_decode((string)$record1->context, true);
        $context2Raw = json_decode((string)$record2->context, true);
        $context1 = is_array($context1Raw) ? $context1Raw : [];
        $context2 = is_array($context2Raw) ? $context2Raw : [];

        return [
            'record1' => ['id' => $record1->id, 'event' => $record1->event, 'created_at' => $record1->created_at],
            'record2' => ['id' => $record2->id, 'event' => $record2->event, 'created_at' => $record2->created_at],
            'changes' => $this->arrayDiff($context1, $context2),
        ];
    }

    /** @return list<\stdClass> */
    public function getActivityTimeline(int $userId, int $days = 30): array
    {
        return $this->model->getActivityTimeline($userId, $days);
    }

    /** @param array<string, mixed> $context
     *  @return array<string, mixed> */
    private function enrichContext(array $context): array
    {
        return array_merge($context, [
            // AU5: Defeat sequential microtime identifier collisions under mass parallelism by injecting random byte suffixes
            '_timestamp' => sprintf('%0.6f', microtime(true)) . '-' . bin2hex(random_bytes(4)),
            '_server_time' => date('Y-m-d H:i:s'),
            '_request_id' => app()->request->header('x-request-id') ?? bin2hex(random_bytes(8)),
        ]);
    }

    private function detectActor(): ?int
    {
        try {
            $uid = $this->session->get('user_id');
            return $uid !== null && is_numeric($uid) ? (int)$uid : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /** @param array<string, mixed> $old
     *  @param array<string, mixed> $new
     *  @return array<string, mixed> */
    private function arrayDiff(array $old, array $new): array
    {
        $changes = [];
        foreach ((array)$new as $key => $value) {
            if (!isset($old[$key])) $changes[$key] = ['added' => $value];
            elseif ($old[$key] !== $value) $changes[$key] = ['from' => $old[$key], 'to' => $value];
        }
        foreach ((array)$old as $key => $value) {
            if (!isset($new[$key])) $changes[$key] = ['removed' => $value];
        }
        return $changes;
    }

    /** @return array<string, mixed> */
    private function getReportSummary(string $start, string $end): array
    {
        $stats = $this->model->getAuditReportSummary($start, $end);
        return [
            'total_events' => (int)($stats->total_events ?? 0),
            'unique_users' => (int)($stats->unique_users ?? 0),
            'unique_categories' => (int)($stats->unique_categories ?? 0),
        ];
    }

    /** @return list<\stdClass> */
    private function getCriticalEvents(string $start, string $end): array
    {
        $criticalEvents = ['user.deleted', 'admin.role_changed', 'security.breach', 'payment.failed', 'data.exported', 'admin.impersonate'];
        return $this->model->getAuditCriticalEvents($criticalEvents, $start, $end);
    }
}
