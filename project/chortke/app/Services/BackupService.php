<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\BackupLog;
use App\Contracts\LoggerInterface;
use Core\PathResolver;

/**
 * BackupService — سرویس پشتیبان‌گیری و بازیابی دیتابیس
 *
 * ویژگی‌ها:
 * - ایجاد پشتیبان دستی یا خودکار
 * - بازیابی از فایل پشتیبان
 * - مدیریت پشتیبان‌های قدیمی
 * - فشرده‌سازی فایل‌های پشتیبان
 */
class BackupService
{
    private BackupLog $backupLogModel;
    private string $backupDir;

    private \App\Contracts\LoggerInterface $logger;
    public function __construct(
        \App\Contracts\LoggerInterface $logger,
        BackupLog $backupLogModel,
        PathResolver $paths
    ) {
        $this->logger = $logger;
        $this->backupLogModel = $backupLogModel;
        $this->backupDir = $paths->storage('backups');

        if (!is_dir($this->backupDir)) {
            mkdir($this->backupDir, 0700, true);
        }
        @chmod($this->backupDir, 0700);
    }

    private function configuredExecutable(string $key, string $default): string
    {
        $value = config($key, $default);
        if (!is_string($value) || $value === '' || preg_match('#^[A-Za-z0-9_./-]+$#', $value) !== 1) {
            throw new \Core\Exceptions\InfrastructureException("Executable configuration '{$key}' is invalid.");
        }
        return $value;
    }

    /** @return array{name: string, user: string, pass: string, host: string} */
    private function databaseConnectionConfig(): array
    {
        $raw = config('database');
        if (!is_array($raw)) {
            throw new \Core\Exceptions\InfrastructureException('پیکربندی دیتابیس نامعتبر است.');
        }

        $name = $raw['name'] ?? 'chortke';
        $user = $raw['user'] ?? 'root';
        $pass = $raw['pass'] ?? '';
        $host = $raw['host'] ?? 'localhost';

        if (!is_string($name) || !is_string($user) || !is_string($pass) || !is_string($host)) {
            throw new \Core\Exceptions\InfrastructureException('مقادیر اتصال دیتابیس باید رشته باشند.');
        }

        return ['name' => $name, 'user' => $user, 'pass' => $pass, 'host' => $host];
    }

    /**
     * ایجاد پشتیبان دیتابیس
     */
    /** @return array<string, mixed> */
    public function createBackup(?string $description = null): array
    {
        $cnfFile = null;
        try {
            // Check required tools
            $mysqldump = $this->configuredExecutable('database.mysqldump_path', 'mysqldump');
            exec(escapeshellcmd($mysqldump) . ' --version 2>&1', $outDump, $retDump);
            if ($retDump !== 0) {
                throw new \Core\Exceptions\InfrastructureException('ابزار mysqldump در سرور یافت نشد. لطفاً نصب کنید.');
            }
            exec('gzip --version 2>&1', $outGzip, $retGzip);
            if ($retGzip !== 0) {
                throw new \Core\Exceptions\InfrastructureException('ابزار gzip در سرور یافت نشد. لطفاً نصب کنید.');
            }
            exec('openssl version 2>&1', $outSsl, $retSsl);
            if ($retSsl !== 0) {
                throw new \Core\Exceptions\InfrastructureException('ابزار openssl در سرور یافت نشد. لطفاً نصب کنید.');
            }

            $timestamp = date('YmdHis');
            $filename = "backup_{$timestamp}.sql";
            $filepath = $this->backupDir . '/' . $filename;

            // دریافت اطلاعات کامل دیتابیس از لایه پیکربندی مرکزی
            $dbConfig = $this->databaseConnectionConfig();
            $dbName = $dbConfig['name'];
            $dbUser = $dbConfig['user'];
            $dbPass = $dbConfig['pass'];
            $dbHost = $dbConfig['host'];

            // ساخت فایل موقت تنظیمات جهت مخفی‌سازی پسورد دیتابیس
            $cnfFile = tempnam(sys_get_temp_dir(), 'mycnf_');
            $cnfContent = sprintf("[client]\npassword=%s\n", $dbPass);
            file_put_contents($cnfFile, $cnfContent);
            chmod($cnfFile, 0600);

            // دستور mysqldump با استفاده از --defaults-extra-file
            $command = sprintf(
                '%s --defaults-extra-file=%s --single-transaction --quick --skip-lock-tables --host=%s --user=%s %s > %s 2>&1',
                escapeshellcmd($mysqldump),
                escapeshellarg($cnfFile),
                escapeshellarg($dbHost),
                escapeshellarg($dbUser),
                escapeshellarg($dbName),
                escapeshellarg($filepath)
            );

            exec($command, $output, $exitCode);

            if ($exitCode !== 0) {
                throw new \Core\Exceptions\InfrastructureException('خطا در اجرای mysqldump: ' . implode("\n", $output));
            }

            // فشرده‌سازی فایل
            $gzFilepath = $filepath . '.gz';
            exec("gzip " . escapeshellarg($filepath), $compressOutput, $compressCode);

            // Encryption using OpenSSL CLI (پشتیبانی استاندارد از OpenSSL 3.0+ و الزام تعداد دفعات تکرار PBKDF2)
            $encFilepath = $gzFilepath . '.enc';
            $appKey = config('app.key');
            if (empty($appKey) || strlen(str_value($appKey)) < 32) {
                throw new \Core\Exceptions\ApplicationException('APP_KEY باید تنظیم شده و حداقل ۳۲ کاراکتر برای رمزنگاری پشتیبان باشد');
            }
            $encKey = bin2hex(hash('sha256', str_value($appKey), true));
            // L-40/M-40 hardening: never pass backup encryption secrets on the
            // process command line (`ps` can expose argv). Provide the passphrase
            // through an owner-only temporary file and wipe it immediately after use.
            $passFile = tempnam(sys_get_temp_dir(), 'bkpass_');
            if ($passFile === false) {
                throw new \Core\Exceptions\ApplicationException('خطا در ایجاد فایل موقت رمزنگاری پشتیبان');
            }
            file_put_contents($passFile, $encKey);
            chmod($passFile, 0600);

            $encCmd = sprintf(
                'openssl enc -aes-256-cbc -salt -pbkdf2 -iter 10000 -in %s -out %s -pass file:%s 2>&1',
                escapeshellarg($gzFilepath),
                escapeshellarg($encFilepath),
                escapeshellarg($passFile)
            );
            exec($encCmd, $encOut, $encCode);
            @unlink($passFile);
            
            if ($encCode !== 0) {
                throw new \Core\Exceptions\ApplicationException('رمزنگاری فایل پشتیبان با خطا مواجه شد: ' . implode("\n", $encOut));
            }
            @unlink($gzFilepath); // Remove unencrypted gz
            
            $finalFilepath = $encFilepath;
            @chmod($finalFilepath, 0600);

            // Integrity Check: Calculate SHA-256 checksum
            $checksum = hash_file('sha256', $finalFilepath);
            $fileSize = filesize($finalFilepath) ?: 0;

            $this->logger->info('backup.created', [
                'filename' => basename($finalFilepath),
                'size' => $fileSize,
                'checksum' => $checksum,
                'request_id' => app()->request->header('x-request-id'),
                'description' => $description,
                'timestamp' => $timestamp
            ]);

            // ذخیره اطلاعات پشتیبان
            $this->backupLogModel->logBackup([
                'request_id' => app()->request->header('x-request-id'),
                'status' => 'completed',
                'type' => 'manual',
                'file_path' => basename($finalFilepath),
                'size_bytes' => $fileSize,
                'checksum' => $checksum,
                'description' => $description,
                'created_at' => date('Y-m-d H:i:s'),
                'completed_at' => date('Y-m-d H:i:s'),
            ]);

            return [
                'success' => true,
                'filename' => basename($finalFilepath),
                'size' => $this->formatBytes($fileSize),
                'path' => $finalFilepath,
                'timestamp' => $timestamp
            ];

        } catch (\Exception $e) {
            $this->logger->error('backup.creation_failed', ['error' => $e->getMessage()]);
            if (class_exists(\App\Services\Sentry\SentryExceptionHandler::class)) {
                \App\Services\Sentry\SentryExceptionHandler::captureMessage('Backup failed: ' . $e->getMessage(), 'critical', null, ['component' => 'BackupService']);
            }
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        } finally {
            if ($cnfFile && file_exists($cnfFile)) {
                unlink($cnfFile);
            }
        }
    }

    /**
     * دریافت لیست پشتیبان‌ها
     */
    /** @return array<string, mixed> */
    public function getBackups(int $limit = 50, int $offset = 0): array
    {
        try {
            $logs = $this->backupLogModel->getRecentBackups($limit, $offset);

            return [
                'success' => true,
                'backups' => $logs,
                'count' => count($logs)
            ];

        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    public function verifyStoredBackup(string $filename, string $expectedChecksum): bool
    {
        $filename = basename($filename);
        if ($filename === '' || $expectedChecksum === '') {
            return false;
        }

        $candidate = $this->backupDir . DIRECTORY_SEPARATOR . $filename;
        $realFile = realpath($candidate);
        $realRoot = realpath($this->backupDir);
        if (
            $realFile === false
            || $realRoot === false
            || !is_file($realFile)
            || !str_starts_with($realFile, $realRoot . DIRECTORY_SEPARATOR)
        ) {
            return false;
        }

        $actualChecksum = hash_file('sha256', $realFile);
        return is_string($actualChecksum) && hash_equals($expectedChecksum, $actualChecksum);
    }

    /** @return array<string, mixed>|null */
    public function getBackupById(int $backupId): ?array
    {
        return $this->backupLogModel->findById($backupId);
    }

    /**
     * حذف پشتیبان قدیمی‌ها (قدیمی‌تر از X روز)
     */
    /** @return array<string, mixed> */
    public function cleanupOldBackups(int $daysToKeep = 30): array
    {
        try {
            $cutoffDate = date('Y-m-d H:i:s', time() - ($daysToKeep * 86400));

            // دریافت فایل‌های قدیمی
            $oldBackups = $this->backupLogModel->getOlderThan($cutoffDate);

            $deleted = 0;
            foreach ($oldBackups as $backup) {
                $backup = (array)$backup;
                $filename = $backup['file_path'];
                $filepath = $this->backupDir . '/' . $filename;

                if (file_exists($filepath)) {
                    unlink($filepath);
                    $deleted++;
                }
            }

            // حذف سوابق
            $this->backupLogModel->deleteOlderThan($cutoffDate);

            $this->logger->info('backup.cleanup_completed', ['deleted' => $deleted]);

            return [
                'success' => true,
                'deleted' => $deleted,
                'message' => "Deleted {$deleted} old backups (older than {$daysToKeep} days)"
            ];

        } catch (\Exception $e) {
            $this->logger->error('backup.cleanup_failed', ['error' => $e->getMessage()]);
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * بازیابی از پشتیبان
     */
    /** @return array<string, mixed> */
    public function restoreBackup(string $filename, bool $skipSnapshot = false): array
    {
        $cnfFile = null;
        $tempSqlFile = null;
        $tempGzFile = null;
        try {
            if (!$skipSnapshot) {
                // 1. Create emergency snapshot
                $snapshotResult = $this->createBackup('pre-restore-snapshot-' . time());
                if (!$snapshotResult['success']) {
                    throw new \Core\Exceptions\ApplicationException('بازیابی لغو شد: ایجاد پشتیبان اضطراری با خطا مواجه شد.');
                }
            }

            $filename = basename($filename);
            // Validate filename format strictly
            if (!preg_match('/^[a-zA-Z0-9_\-\.]+\.(sql|gz|enc)$/i', $filename)) {
                throw new \InvalidArgumentException('نام فایل پشتیبان نامعتبر است');
            }

            $baseReal = realpath($this->backupDir);
            if ($baseReal === false) {
                throw new \Core\Exceptions\ApplicationException('مسیر پوشه پشتیبان نامعتبر است');
            }

            $filepath = $this->backupDir . '/' . $filename;
            if (!file_exists($filepath)) {
                throw new \Core\Exceptions\NotFoundException('فایل پشتیبان یافت نشد');
            }

            $fileReal = realpath($filepath);
            if ($fileReal === false || strpos($fileReal, $baseReal) !== 0) {
                throw new \Core\Exceptions\SecurityException('مسیر فایل نامعتبر است');
            }
            
            // 2. Checksum Verification
            $backupRecord = $this->backupLogModel->findByFilename($filename);
            if (!$backupRecord || empty($backupRecord['checksum'])) {
                throw new \Core\Exceptions\NotFoundException('اطلاعات فایل پشتیبان یافت نشد');
            }
            
            $currentChecksum = hash_file('sha256', $fileReal);
            if ($currentChecksum !== $backupRecord['checksum']) {
                $this->logger->critical('backup.checksum_mismatch', [
                    'file' => $filename,
                    'expected' => $backupRecord['checksum'],
                    'actual' => $currentChecksum
                ]);
                throw new \Core\Exceptions\SecurityException('بررسی صحت فایل پشتیبان ناموفق بود؛ ممکن است فایل دستکاری یا خراب شده باشد.');
            }

            $restoreSourcePath = $fileReal;
            
            // Decrypt if encrypted
            $isEncrypted = (strtolower(substr($filename, -4)) === '.enc');
            if ($isEncrypted) {
                $tempGzFile = tempnam(sys_get_temp_dir(), 'dbdec_') . '.gz';
                $appKey = config('app.key');
                if (empty($appKey) || strlen(str_value($appKey)) < 32) {
                    throw new \Core\Exceptions\ApplicationException('APP_KEY باید تنظیم شده و حداقل ۳۲ کاراکتر برای رمزگشایی پشتیبان باشد');
                }
                $encKey = bin2hex(hash('sha256', str_value($appKey), true));
                // M-40 hardening: keep restore passphrase out of argv/process list.
                $passFile = tempnam(sys_get_temp_dir(), 'bkpass_');
                if ($passFile === false) {
                    throw new \Core\Exceptions\ApplicationException('خطا در ایجاد فایل موقت رمزگشایی پشتیبان');
                }
                file_put_contents($passFile, $encKey);
                chmod($passFile, 0600);

                $decCmd = sprintf(
                    'openssl enc -d -aes-256-cbc -pbkdf2 -iter 10000 -in %s -out %s -pass file:%s 2>&1',
                    escapeshellarg($restoreSourcePath),
                    escapeshellarg($tempGzFile),
                    escapeshellarg($passFile)
                );
                exec($decCmd, $decOut, $decCode);
                @unlink($passFile);
                if ($decCode !== 0) {
                    throw new \Core\Exceptions\ApplicationException('رمزگشایی فایل پشتیبان با خطا مواجه شد.');
                }
                $restoreSourcePath = $tempGzFile;
            }

            $isCompressed = (strtolower(substr($restoreSourcePath, -3)) === '.gz');

            // Safe non-destructive decompression using native PHP zlib streams to local temp with Zip Bomb Protection (Issue #26 Fix)
            if ($isCompressed) {
                if (!function_exists('gzopen')) {
                    throw new \Core\Exceptions\InfrastructureException('پشتیبانی از استخراج Zlib در این محیط PHP وجود ندارد');
                }

                $tempSqlFile = tempnam(sys_get_temp_dir(), 'dbrestore_');
                if ($tempSqlFile === false) {
                    throw new \Core\Exceptions\ApplicationException('خطا در ایجاد فایل موقت استخراج');
                }

                $gz = gzopen($restoreSourcePath, 'rb');
                $out = fopen($tempSqlFile, 'wb');
                
                if (!$gz || !$out) {
                    if ($gz) gzclose($gz);
                    if ($out) fclose($out);
                    throw new \Core\Exceptions\ApplicationException('خطا در باز کردن فایل‌های پشتیبان برای استخراج');
                }

                $maxExtractedBytes = 500 * 1024 * 1024; // 500 MB max limit
                $extractedBytes = 0;

                while (!gzeof($gz)) {
                    $data = gzread($gz, 65536);
                    if ($data !== false && $data !== '') {
                        $extractedBytes += strlen($data);
                        if ($extractedBytes > $maxExtractedBytes) {
                            gzclose($gz);
                            fclose($out);
                            @unlink($tempSqlFile);
                            throw new \Core\Exceptions\SecurityException('حجم استخراج‌شده فایل پشتیبان از سقف مجاز ۵۰۰ مگابایت فراتر رفت (Gzip Bomb Protection).');
                        }
                        fwrite($out, $data);
                    }
                }
                gzclose($gz);
                fclose($out);

                $restoreSourcePath = $tempSqlFile;
            }

            // دریافت تنظیمات دیتابیس از لایه مرکزی
            $dbConfig = $this->databaseConnectionConfig();
            $dbName = $dbConfig['name'];
            $dbUser = $dbConfig['user'];
            $dbPass = $dbConfig['pass'];
            $dbHost = $dbConfig['host'];

            // ساخت فایل موقت تنظیمات جهت مخفی‌سازی پسورد دیتابیس
            $cnfFile = tempnam(sys_get_temp_dir(), 'mycnf_');
            $cnfContent = sprintf("[client]\npassword=%s\n", $dbPass);
            file_put_contents($cnfFile, $cnfContent);
            chmod($cnfFile, 0600);

            // دستور mysql import با استفاده از --defaults-extra-file
            $mysqlPath = $this->configuredExecutable('database.mysql_path', 'mysql');
            $command = sprintf(
                '%s --defaults-extra-file=%s --host=%s --user=%s %s < %s 2>&1',
                escapeshellcmd($mysqlPath),
                escapeshellarg($cnfFile),
                escapeshellarg($dbHost),
                escapeshellarg($dbUser),
                escapeshellarg($dbName),
                escapeshellarg($restoreSourcePath)
            );

            exec($command, $output, $exitCode);

            if ($exitCode !== 0) {
                // Log the output privately, do NOT leak it back to user
                $this->logger->error('backup.restore_command_failed', [
                    'filename' => $filename,
                    'exit_code' => $exitCode,
                    'output' => implode("\n", $output)
                ]);
                throw new \Core\Exceptions\ApplicationException('خطای سیستمی در بازیابی پایگاه داده. لطفاً لاگ‌ها را بررسی کنید.');
            }

            $this->logger->info('backup.restored', [
                'filename' => $filename,
                'timestamp' => date('Y-m-d H:i:s')
            ]);

            return [
                'success' => true,
                'message' => 'Backup restored successfully'
            ];

        } catch (\Exception $e) {
            $this->logger->error('backup.restore_failed', [
                'filename' => $filename,
                'error' => $e->getMessage()
            ]);
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        } finally {
            if ($cnfFile && file_exists($cnfFile)) {
                @unlink($cnfFile);
            }
            if ($tempSqlFile && file_exists($tempSqlFile)) {
                @unlink($tempSqlFile);
            }
            if ($tempGzFile && file_exists($tempGzFile)) {
                @unlink($tempGzFile);
            }
        }
    }

    /**
     * دریافت آمار پشتیبان‌ها
     */
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
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * تبدیل بایت به فرمت خوانا
     */
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
