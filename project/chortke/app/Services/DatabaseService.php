<?php

declare(strict_types=1);

namespace App\Services;

use Core\Database;
use App\Models\BackupLog;
use App\Contracts\LoggerInterface;
use Exception;

/**
 * DatabaseService
 * مدیر مرکزی دیتابیس برای چرخه حیات داده‌ها (Data Retention و Archival) و عملیات Backup/Restore
 */
class DatabaseService
{
    private BackupLog $backupLogModel;
    private BackupService $backupService;
    private string $backupDir;

    private \App\Contracts\LoggerInterface $logger;
    public function __construct(
        \App\Contracts\LoggerInterface $logger,
        BackupLog $backupLogModel,
        BackupService $backupService,
        \Core\PathResolver $paths
    ) {
        $this->logger = $logger;
        $this->backupLogModel = $backupLogModel;
        $this->backupService = $backupService;
        $this->backupDir = $paths->storage('backups');

        if (!is_dir($this->backupDir)) {
            @mkdir($this->backupDir, 0700, true);
        }
        @chmod($this->backupDir, 0700);
    }

    // ====================================================================================
    // 2. Backup & Restore (Replacing BackupService)
    // ====================================================================================

    /** @return array<string, mixed> */
    public function createBackup(?string $description = null): array
    {
        return $this->backupService->createBackup($description);
    }

    /** @return array<string, mixed> */
    public function verifyBackupIntegrity(string $filename): array
    {
        $filepath = $this->backupDir . '/' . basename($filename);
        if (!file_exists($filepath)) {
            return ['success' => false, 'error' => 'فایل بک‌آپ یافت نشد.'];
        }

        $backupRecord = $this->backupLogModel->findByFilename(basename($filename));
        if (!$backupRecord || empty($backupRecord['checksum'])) {
            return ['success' => false, 'error' => 'اطلاعات هَش (Checksum) فایل در دیتابیس موجود نیست.'];
        }

        $currentChecksum = hash_file('sha256', $filepath);
        if ($currentChecksum !== $backupRecord['checksum']) {
            $this->logger->critical('database.backup.tampered', ['file' => $filename]);
            return ['success' => false, 'error' => 'عدم تطابق هَش! فایل بک‌آپ دستکاری یا خراب شده است.'];
        }

        return ['success' => true, 'message' => 'صحت فایل بک‌آپ کاملاً تایید شد.'];
    }

    /** @return array<string, mixed> */
    public function restoreBackup(string $filename, bool $skipSnapshot = false): array
    {
        return $this->backupService->restoreBackup($filename, $skipSnapshot);
    }

    /** @return array<string, mixed> */
    public function getBackups(int $limit = 50, int $offset = 0): array
    {
        try {
            $logs = $this->backupLogModel->getRecentBackups($limit, $offset);
            return ['success' => true, 'backups' => $logs, 'count' => count($logs)];
        } catch (\Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /** @return array<string, mixed>|null */
    public function getBackupById(int $backupId): ?array
    {
        return $this->backupLogModel->findById($backupId);
    }

    /** @return array<string, mixed> */
    public function getBackupStats(): array
    {
        try {
            $stats = $this->backupLogModel->getStats();
            $totalBackups = is_numeric($stats['total_backups'] ?? null) ? (int)$stats['total_backups'] : 0;
            $totalSize = is_numeric($stats['total_size'] ?? null) ? (int)$stats['total_size'] : 0;
            return [
                'success' => true,
                'total_backups' => $totalBackups,
                'total_size' => $this->formatBytes($totalSize),
                'last_backup' => $stats['last_backup'] ?? null,
                'first_backup' => $stats['first_backup'] ?? null
            ];
        } catch (\Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /** @return array<string, mixed> */
    public function cleanupOldBackups(int $daysToKeep = 30): array
    {
        try {
            $cutoffDate = date('Y-m-d H:i:s', time() - ($daysToKeep * 86400));
            $oldBackups = $this->backupLogModel->getOlderThan($cutoffDate);
            $deleted = 0;

            foreach ($oldBackups as $backup) {
                $filepath = $this->backupDir . '/' . basename(((array)$backup)['file_path']);
                if (file_exists($filepath)) {
                    @unlink($filepath);
                    $deleted++;
                }
            }
            $this->backupLogModel->deleteOlderThan($cutoffDate);

            return ['success' => true, 'deleted' => $deleted];
        } catch (\Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        $bytes /= (1 << (10 * $pow));
        return round($bytes, 2) . ' ' . $units[$pow];
    }
}
