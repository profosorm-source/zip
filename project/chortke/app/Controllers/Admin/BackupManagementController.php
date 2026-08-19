<?php

namespace App\Controllers\Admin;

use App\Services\BackupService;
use App\Contracts\LoggerInterface;

/**
 * Controller: BackupManagementController
 * مدیریت پشتیبان‌گیری و بازیابی دیتابیس (رمزنگاری‌شده با BackupService)
 */
class BackupManagementController extends BaseAdminController
{
    private BackupService $backupService;

    public function __construct(BackupService $backupService, LoggerInterface $logger) {
        parent::__construct(null, null, null, null, $logger);
        $this->backupService = $backupService;
    }

    /**
     * نمایش لیست پشتیبان‌ها
     */
    public function index(): string
    {
        try {
            $backups = $this->backupService->getBackups(50, 0); 
            $stats = $this->backupService->getBackupStats();

            return (string)view('admin/backups/index', [
                'backups' => $backups['backups'] ?? [],
                'stats' => $stats,
                'success' => $backups['success'] ?? false
            ]);

        } catch (\Core\Exceptions\HttpResponseException $e) {
            throw $e;
        } catch (\Exception $e) {
            $this->logger->error('admin.backups.index.failed', ['error' => $e->getMessage()]);
            $this->session->setFlash('error', 'خطا: دریافت لیست پشتیبان‌ها ناموفق بود');
            redirect(url('/admin/dashboard'));
        }
    }

    /**
     * ایجاد پشتیبان جدید
     */
    public function createBackup(): void
    {
        try {
            $description = $this->request->str('description') !== '' ? $this->request->str('description') : null;

            $result = $this->backupService->createBackup($description);

            if (($result['success'] ?? false) === true) {
                $filename = is_string($result['filename'] ?? null) ? $result['filename'] : 'unknown';
                $this->logger->info('admin.backup.created', [
                    'filename' => $filename,
                    'size' => $result['size'] ?? null
                ]);
                $this->session->setFlash('success', "پشتیبان رمزنگاری‌شده با موفقیت ایجاد شد: {$filename}");
            } else {
                $error = is_string($result['error'] ?? null) ? $result['error'] : 'خطای نامشخص';
                $this->session->setFlash('error', "خطا: {$error}");
            }

            redirect(url('/admin/backups'));

        } catch (\Core\Exceptions\HttpResponseException $e) {
            throw $e;
        } catch (\Exception $e) {
            $this->logger->error('admin.backup.create.failed', ['error' => $e->getMessage()]);
            $this->session->setFlash('error', 'خطا: ایجاد پشتیبان ناموفق بود');
            redirect(url('/admin/backups'));
        }
    }

    /**
     * بازیابی از پشتیبان (محدود به ادمین‌های ارشد فقط)
     */
    public function restoreBackup(): void
    {
        $this->requirePermission('super_admin');
        try {
            $backupId = $this->request->post('backup_id');

            if (!$backupId) {
                $this->session->setFlash('error', 'شناسه پشتیبان الزامی است');
                redirect(url('/admin/backups'));
            }

            $backup = $this->backupService->getBackupById(int_value($backupId));
            $filename = is_array($backup) ? ($backup['file_path'] ?? '') : '';
            if (!is_string($filename) || $filename === '') {
                $this->session->setFlash('error', 'پشتیبان یافت نشد');
                redirect(url('/admin/backups'));
            }

            $result = $this->backupService->restoreBackup($filename);

            if ($result['success']) {
                $this->logger->info('admin.backup.restore.success', ['backup_id' => $backupId]);
                $this->session->setFlash('success', 'بازیابی پشتیبان رمزنگاری‌شده با موفقیت انجام شد');
            } else {
                $this->logger->error('admin.backup.restore.failed', [
                    'backup_id' => $backupId,
                    'error' => $result['error']
                ]);
                $this->session->setFlash('error', 'خطا در بازیابی: ' . $result['error']);
            }

            redirect(url('/admin/backups'));

        } catch (\Core\Exceptions\HttpResponseException $e) {
            throw $e;
        } catch (\Exception $e) {
            $this->logger->error('admin.backup.restore.failed', ['error' => $e->getMessage()]);
            $this->session->setFlash('error', 'خطا: بازیابی ناموفق بود');
            redirect(url('/admin/backups'));
        }
    }

    /**
     * بررسی صحت (Verify) فایل پشتیبان
     */
    public function verifyBackup(): void
    {
        try {
            $backupId = $this->request->post('backup_id');

            if (!$backupId) {
                $this->session->setFlash('error', 'شناسه پشتیبان الزامی است');
                redirect(url('/admin/backups'));
            }

            $backup = $this->backupService->getBackupById(int_value($backupId));
            $filename = is_array($backup) ? ($backup['file_path'] ?? '') : '';
            if (!is_string($filename) || $filename === '') {
                $this->session->setFlash('error', 'پشتیبان یافت نشد');
                redirect(url('/admin/backups'));
            }

            $checksum = $backup['checksum'] ?? null;
            if (is_string($checksum) && $this->backupService->verifyStoredBackup($filename, $checksum)) {
                $this->logger->info('admin.backup.verify.success', ['backup_id' => $backupId]);
                $this->session->setFlash('success', 'چک‌سام SHA-256 و ساختار رمزنگاری فایل پشتیبان با موفقیت تایید شد.');
            } else {
                $this->session->setFlash('error', 'خطا: فایل پشتیبان یافت نشد یا چک‌سام آن معتبر نیست.');
            }

            redirect(url('/admin/backups'));

        } catch (\Core\Exceptions\HttpResponseException $e) {
            throw $e;
        } catch (\Exception $e) {
            $this->logger->error('admin.backup.verify.failed', ['error' => $e->getMessage()]);
            $this->session->setFlash('error', 'خطا در بررسی صحت پشتیبان');
            redirect(url('/admin/backups'));
        }
    }

    /**
     * نمایش آمار پشتیبان‌ها
     */
    public function stats(): string
    {
        try {
            $stats = $this->backupService->getBackupStats();

            return (string) view('admin/backups/stats', ['stats' => $stats]);

        } catch (\Core\Exceptions\HttpResponseException $e) {
            throw $e;
        } catch (\Exception $e) {
            $this->logger->error('admin.backups.stats.failed', ['error' => $e->getMessage()]);
            $this->session->setFlash('error', 'خطا: دریافت آمار ناموفق بود');
            redirect(url('/admin/dashboard'));
        }
    }

    /**
     * پاک‌سازی پشتیبان‌های قدیمی
     */
    public function cleanup(): void
    {
        try {
            $daysToKeep = $this->request->int('days_to_keep', 30);
            if ($daysToKeep < 1 || $daysToKeep > 3650) {
                $daysToKeep = 30;
            }

            $result = $this->backupService->cleanupOldBackups($daysToKeep);

            if (($result['success'] ?? false) === true) {
                $deleted = int_value($result['deleted'] ?? 0);
                $this->logger->info('admin.backup.cleanup', [
                    'deleted' => $deleted,
                    'days_to_keep' => $daysToKeep
                ]);
                $this->session->setFlash('success', "پاک‌سازی انجام شد: {$deleted} پشتیبان حذف شد");
            } else {
                $error = is_string($result['error'] ?? null) ? $result['error'] : 'خطای نامشخص';
                $this->session->setFlash('error', "خطا: {$error}");
            }

            redirect(url('/admin/backups'));

        } catch (\Core\Exceptions\HttpResponseException $e) {
            throw $e;
        } catch (\Exception $e) {
            $this->logger->error('admin.backup.cleanup.failed', ['error' => $e->getMessage()]);
            $this->session->setFlash('error', 'خطا: پاک‌سازی ناموفق بود');
            redirect(url('/admin/backups'));
        }
    }
}
