<?php

declare(strict_types=1);

namespace App\Services;

use Core\Database;
use App\Contracts\LoggerInterface;

/**
 * MaintenanceService — مدیر چرخه حیات داده‌ها و سلامت دیتابیس
 * 
 * این سرویس به عنوان ارکستراتور، تمام عملیات‌های آرشیو‌سازی (Archival) 
 * و پاکسازی رکوردهای زائد و منقضی (Data Retention / GDPR) را مدیریت می‌کند.
 */
class MaintenanceService
{
    private BackupService $backupService;

    private \Core\Database $db;
    private \App\Contracts\LoggerInterface $logger;
    private \App\Models\SystemLog $systemLog;
    private \App\Models\SecurityLog $securityLog;
    private \App\Models\PerformanceLog $performanceLog;
    private \App\Services\Shared\IdempotencyService $idempotencyService;
    private \Core\PathResolver $paths;
    public function __construct(
        \Core\Database $db,
        \App\Contracts\LoggerInterface $logger,
        BackupService $backupService,
        \App\Models\SystemLog $systemLog,
        \App\Models\SecurityLog $securityLog,
        \App\Models\PerformanceLog $performanceLog,
        \App\Services\Shared\IdempotencyService $idempotencyService,
        \Core\PathResolver $paths
    )
    {        $this->db = $db;
        $this->logger = $logger;

        
        $this->backupService = $backupService;
        $this->systemLog = $systemLog;
        $this->securityLog = $securityLog;
        $this->performanceLog = $performanceLog;
        $this->idempotencyService = $idempotencyService;
        $this->paths = $paths;
    }

    /**
     * اجرای روتین روزانه پاکسازی و آرشیو
     */
    /**
     * @return array<string, mixed>
     */
    public function runDailyMaintenance(): array
    {
        $this->logger->info('maintenance.daily.started');
        
        $results = [
            'retention' => $this->executeDataRetention(),
            'archival' => $this->executeArchival(),
            'backup_cleanup' => $this->backupService->cleanupOldBackups(30),
            'system_cleanup' => $this->executeSystemCleanup(),
        ];

        $this->logger->info('maintenance.daily.completed', $results);
        
        return $results;
    }

    // ====================================================================================
    // 1. Data Retention Strategy (GDPR & Junk Cleanup)
    // ====================================================================================

    /**
     * حذف لاگ‌های قدیمی، سشن‌های منقضی شده و درخواست‌های حذف اکانت (GDPR)
     */
    /**
     * @return array<string, mixed>
     */
    private function executeDataRetention(): array
    {
        $deletedSessions = 0;
        $deletedLogs = 0;
        $deletedAccounts = 0;

        try {
            // 1. پاکسازی سشن‌های منقضی‌شده (قدیمی‌تر از 30 روز)
            $stmt = $this->db->query("DELETE FROM user_sessions WHERE last_activity < DATE_SUB(NOW(), INTERVAL 30 DAY)");
            $deletedSessions = $stmt->rowCount();

            // 2. پاکسازی لاگ‌های فعالیت زائد (قدیمی‌تر از 90 روز)
            $stmt = $this->db->query("DELETE FROM activity_logs WHERE created_at < DATE_SUB(NOW(), INTERVAL 90 DAY)");
            $deletedLogs = $stmt->rowCount();

            // 3. پاکسازی اکانت‌هایی که درخواست GDPR Deletion داده‌اند و 30 روز گذشته است
            // (فرض بر وجود جدولی یا فیلدی به نام deleted_at)
            // $stmt = $this->db->query("DELETE FROM users WHERE deleted_at IS NOT NULL AND deleted_at < DATE_SUB(NOW(), INTERVAL 30 DAY)");
            // $deletedAccounts = $stmt->rowCount();

            return [
                'status' => 'success',
                'cleared_sessions' => $deletedSessions,
                'cleared_logs' => $deletedLogs,
                'deleted_accounts' => $deletedAccounts,
            ];
        } catch (\Throwable $e) {
            $this->logger->error('maintenance.retention.failed', ['error' => $e->getMessage()]);
            return ['status' => 'failed', 'error' => $e->getMessage()];
        }
    }

    // ====================================================================================
    // 2. Archival Strategy (Cold Storage for heavy tables)
    // ====================================================================================

    /**
     * انتقال تراکنش‌های قدیمی (مالی/امتیازی) به جداول Archive جهت سبک ماندن جداول اصلی
     */
    /**
     * @return array<string, mixed>
     */
    private function executeArchival(): array
    {
        $archivedCount = 0;
        $batchLimit = max(100, min(10000, int_value(config('maintenance.archive_batch_limit', 5000))));
        
        try {
            // اطمینان از وجود جدول Archive
            $this->db->query(
                "CREATE TABLE IF NOT EXISTS transactions_archive LIKE transactions"
            );

            $this->db->beginTransaction();

            // انتخاب batch کوچک با ترتیب پایدار برای جلوگیری از lock طولانی و full-table move.
            $ids = $this->db->fetchAll(
                "SELECT id
                 FROM transactions
                 WHERE created_at < DATE_SUB(NOW(), INTERVAL 1 YEAR)
                 ORDER BY created_at ASC, id ASC
                 LIMIT {$batchLimit}",
            ) ?: [];

            $ids = array_values(array_filter(array_map(
                static fn($row): int => (int)($row->id ?? 0),
                $ids
            ), static fn(int $id): bool => $id > 0));

            if (empty($ids)) {
                $this->db->commit();
                return [
                    'status' => 'success',
                    'archived_records' => 0,
                    'batch_limit' => $batchLimit,
                    'message' => 'No eligible transactions to archive.',
                ];
            }

            $placeholders = implode(',', array_fill(0, count($ids), '?'));

            // 1. انتقال تراکنش‌های قدیمی‌تر از 1 سال به آرشیو — فقط batch انتخاب‌شده.
            $insertStmt = $this->db->query(
                "INSERT INTO transactions_archive
                 SELECT * FROM transactions
                 WHERE id IN ({$placeholders})",
                $ids
            );
            $archivedCount = $insertStmt->rowCount();

            // 2. حذف همان batch از جدول اصلی.
            $stmt = $this->db->query(
                "DELETE FROM transactions WHERE id IN ({$placeholders})",
                $ids
            );
            $deletedFromMain = $stmt->rowCount();

            $this->db->commit();

            return [
                'status' => 'success',
                'archived_records' => $deletedFromMain,
                'inserted_archive_records' => $archivedCount,
                'batch_limit' => $batchLimit,
            ];
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollback();
            }
            $this->logger->error('maintenance.archival.failed', ['error' => $e->getMessage()]);
            \App\Services\Sentry\SentryExceptionHandler::captureException($e, null, [
                'operation' => 'maintenance.executeArchival',
                'batch_limit' => $batchLimit,
            ]);
            return ['status' => 'failed', 'error' => $e->getMessage()];
        }
    }

    // ====================================================================================
    // 3. System Cleanup (لاگ‌ها، outbox، failed_jobs، audit_trail، search_projections، idempotency)
    // ====================================================================================

    /**
     * پاکسازی سیستمی — یکپارچه‌سازیِ منطقِ SystemCleanupJob (که هرگز زمان‌بندی نشده بود) داخل همین ارکستراتور.
     * لاگ‌ها از طریق مدل‌های DAL مالکِ همان جدول پاک می‌شوند تا SQL خامِ تکراری حذف شود و خلأ نگهداریِ
     * جداول system_logs / security_logs / performance_logs (که قبلاً هرگز هرس نمی‌شدند) برطرف شود.
     *
     * @return array<string, mixed>
     */
    private function executeSystemCleanup(): array
    {
        $logRetentionDays   = max(1, int_value(config('maintenance.log_retention_days', 90)));
        $opsRetentionDays   = max(1, int_value(config('maintenance.ops_retention_days', 30)));
        $auditRetentionDays = max(1, int_value(config('maintenance.audit_retention_days', 180)));

        try {
            // 1) لاگ‌ها از طریق مدل‌های DAL (chunked)
            $systemLogsDeleted      = $this->systemLog->deleteOlderThanChunked($logRetentionDays);
            $securityLogsDeleted    = $this->securityLog->deleteOlderThanChunked($logRetentionDays);
            $performanceLogsDeleted = $this->performanceLog->deleteOlderThanChunked($logRetentionDays);

            $opsThreshold = date('Y-m-d H:i:s', (strtotime("-{$opsRetentionDays} days") ?: time()));

            // 2) رویدادهای outbox پردازش‌شده (وضعیت published)
            $outboxProcessedDeleted = (int) $this->db->execute(
                "DELETE FROM outbox_events WHERE status = 'published' AND updated_at < ?",
                [$opsThreshold]
            );

            // 3) رویدادهای outbox شکست‌خورده/DLQ
            $outboxDlqDeleted = (int) $this->db->execute(
                "DELETE FROM outbox_events WHERE status IN ('dlq', 'failed') AND updated_at < ?",
                [$opsThreshold]
            );

            // 4) failed_jobs
            $failedJobsDeleted = (int) $this->db->execute(
                "DELETE FROM failed_jobs WHERE failed_at < ?",
                [$opsThreshold]
            );

            // 5) audit_trail: آرشیو به JSONL سپس حذف
            $auditDeleted = $this->archiveAndPruneAuditTrail($auditRetentionDays);

            // 6) search_projections یتیم
            $orphanedSearchDeleted = $this->cleanOrphanedSearchProjections();

            // 7) کلیدهای idempotency منقضی
            $idempotencyDeleted = 0;
            try {
                $idempotencyDeleted = $this->idempotencyService->cleanup(false);
            } catch (\Throwable $e) {
                $this->logger->warning('maintenance.system_cleanup.idempotency_failed', ['error' => $e->getMessage()]);
            }

            $result = [
                'status' => 'success',
                'system_logs_deleted' => $systemLogsDeleted,
                'security_logs_deleted' => $securityLogsDeleted,
                'performance_logs_deleted' => $performanceLogsDeleted,
                'outbox_processed_deleted' => $outboxProcessedDeleted,
                'outbox_dlq_deleted' => $outboxDlqDeleted,
                'failed_jobs_deleted' => $failedJobsDeleted,
                'audit_deleted' => $auditDeleted,
                'orphaned_search_deleted' => $orphanedSearchDeleted,
                'idempotency_deleted' => $idempotencyDeleted,
            ];

            $this->logger->info('maintenance.system_cleanup.completed', $result);
            return $result;
        } catch (\Throwable $e) {
            $this->logger->error('maintenance.system_cleanup.failed', ['error' => $e->getMessage()]);
            \App\Services\Sentry\SentryExceptionHandler::captureException($e, null, [
                'operation' => 'maintenance.executeSystemCleanup',
            ]);
            return ['status' => 'failed', 'error' => $e->getMessage()];
        }
    }

    /**
     * آرشیو batch رکوردهای قدیمیِ audit_trail در فایل JSONL و سپس حذف آن‌ها از جدول.
     */
    private function archiveAndPruneAuditTrail(int $auditRetentionDays): int
    {
        $auditThreshold = date('Y-m-d H:i:s', (strtotime("-{$auditRetentionDays} days") ?: time()));

        $rows = $this->db->fetchAll(
            "SELECT * FROM audit_trail WHERE created_at < ? ORDER BY created_at ASC LIMIT 5000",
            [$auditThreshold]
        ) ?: [];

        if (!empty($rows)) {
            $archiveFile = $this->paths->storage('logs/audit_archive_' . date('Y-m') . '.jsonl');
            $handle = @fopen($archiveFile, 'a');
            if ($handle) {
                foreach ($rows as $row) {
                    fwrite($handle, json_encode($row, JSON_UNESCAPED_UNICODE) . "\n");
                }
                fclose($handle);
            }
        }

        return (int) $this->db->execute(
            "DELETE FROM audit_trail WHERE created_at < ?",
            [$auditThreshold]
        );
    }

    /**
     * حذف پروجکشن‌های جستجوی یتیم (رکوردهایی که موجودیت اصلی‌شان حذف شده است).
     */
    private function cleanOrphanedSearchProjections(): int
    {
        $entityTables = [
            'user' => 'users',
            'ad'   => 'ads',
            'task' => 'ads',
        ];

        $deleted = 0;
        foreach ($entityTables as $entityType => $table) {
            $deleted += (int) $this->db->execute(
                "DELETE sp FROM search_projections sp
                 LEFT JOIN {$table} t ON sp.entity_id = t.id AND sp.entity_type = ?
                 WHERE sp.entity_type = ? AND t.id IS NULL",
                [$entityType, $entityType]
            );
        }

        return $deleted;
    }
}
