<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\DataExport;
use App\Models\KYCVerification;
use App\Models\Transaction;
use App\Models\User;
use App\Models\UserSetting;
use App\Models\Wallet;
use Core\Cache;
use Core\Database;
use App\Contracts\LoggerInterface;

/**
 * DataExportService — صادرکردن داده‌های کاربر
 */
class DataExportService
{


    private DataExport $exportModel;
    private User $userModel;
    private Transaction $transactionModel;
    private Wallet $walletModel;
    private KYCVerification $kycVerificationModel;
    private UserSetting $userSettingModel;
    private Database $db;

    private \App\Contracts\LoggerInterface $logger;
    public function __construct(
        \App\Contracts\LoggerInterface $logger,
        DataExport $exportModel,
        User $userModel,
        Transaction $transactionModel,
        Wallet $walletModel,
        KYCVerification $kycVerificationModel,
        UserSetting $userSettingModel,
        Database $db
    ) {
        $this->logger = $logger;
        $this->exportModel = $exportModel;
        $this->userModel = $userModel;
        $this->transactionModel = $transactionModel;
        $this->walletModel = $walletModel;
        $this->kycVerificationModel = $kycVerificationModel;
        $this->userSettingModel = $userSettingModel;
        $this->db = $db;
    }

    /**
     * ایجاد درخواست صادرکردن
     */
    public function requestExport(int $userId, string $format): ?int
    {
        if (!in_array($format, ['json', 'csv'], true)) {
            $this->logger->warning('data_export.invalid_format', ['format' => $format, 'user_id' => $userId]);
            return null;
        }

        try {
            $exportId = $this->exportModel->createExport($userId, $format);
            $this->logger->info('data_export.requested', ['user_id' => $userId, 'format' => $format, 'export_id' => $exportId]);
            return $exportId;
        } catch (\Exception $e) {
            $this->logger->error('data_export.request_failed', ['user_id' => $userId, 'error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * صادرکردن داده‌های JSON
     */
    public function exportJSON(int $userId): ?string
    {
        try {
            $user = $this->userModel->findById($userId);
            if (!$user) {
                return null;
            }

            // Comprehensive data export (Finding #10)
            $data = [
                'user' => $this->sanitizeUserData($user),
                'transactions' => $this->getUserTransactions($userId),
                'wallet' => $this->getUserWallet($userId),
                'kyc' => $this->getUserKYC($userId),
                'settings' => $this->getUserSettings($userId),
                'notifications' => $this->db->fetchAll("SELECT id, type, title, message, is_read, created_at FROM notifications WHERE user_id = ? ORDER BY id DESC LIMIT 1000", [$userId]) ?: [],
                'direct_messages' => $this->db->fetchAll("SELECT id, sender_id, recipient_id, is_read, created_at FROM direct_messages WHERE sender_id = ? OR recipient_id = ? ORDER BY id DESC LIMIT 1000", [$userId, $userId]) ?: [],
                'custom_task_submissions' => $this->db->fetchAll("SELECT * FROM custom_task_submissions WHERE user_id = ? OR worker_id = ? ORDER BY id DESC", [$userId, $userId]) ?: [],
                'social_task_executions' => $this->db->fetchAll("SELECT * FROM social_task_executions WHERE executor_id = ? ORDER BY id DESC", [$userId]) ?: [],
                'referrals' => $this->db->fetchAll("SELECT id, username, email, created_at FROM users WHERE referred_by = ?", [$userId]) ?: [],
                'prediction_bets' => $this->db->fetchAll("SELECT * FROM prediction_bets WHERE user_id = ? ORDER BY id DESC", [$userId]) ?: [],
                'vitrine_listings' => $this->db->fetchAll("SELECT * FROM vitrine_listings WHERE seller_id = ? ORDER BY id DESC", [$userId]) ?: [],
                'exported_at' => date('Y-m-d H:i:s'),
                'timezone' => date_default_timezone_get(),
            ];

            return (string)json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        } catch (\Exception $e) {
            $this->logger->error('data_export.json_failed', ['user_id' => $userId, 'error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * صادرکردن داده‌های CSV
     */
    public function exportCSV(int $userId): ?string
    {
        try {
            $user = $this->userModel->findById($userId);
            if (!$user) {
                return null;
            }
            $user = (array)$user;

            $sanitize = static function(mixed $val): string {
                $str = str_value($val ?? '');
                if (preg_match('/^[\=\+\-\@]/', $str)) {
                    $str = "'" . $str;
                }
                return '"' . str_replace('"', '""', $str) . '"';
            };

            $csv = "نام,مقدار\n";

            // اطلاعات کاربر
            $csv .= "نام کاربری," . $sanitize($user['username'] ?? '') . "\n";
            $csv .= "نام کامل," . $sanitize($user['full_name'] ?? '') . "\n";
            $csv .= "ایمیل," . $sanitize($user['email'] ?? '') . "\n";
            $csv .= "موبایل," . $sanitize($user['mobile'] ?? 'ندارد') . "\n";
            $csv .= "تاریخ عضویت," . $sanitize($user['created_at'] ?? '') . "\n";

            // آمار تراکنش‌ها
            $transactions = $this->getUserTransactions($userId);
            $csv .= "\n--- تراکنش‌ها ---\n";
            $csv .= "کل تراکنش‌ها," . count($transactions) . "\n";
            $totalAmount = array_sum(array_map(fn(array $t): float => float_value($t['amount'] ?? 0), $transactions));
            $csv .= "کل مبلغ," . $totalAmount . " تومان\n";

            // آمار کیف‌پول
            $wallet = $this->getUserWallet($userId);
            $csv .= "\n--- کیف‌پول ---\n";
            $csv .= "موجودی," . $sanitize(($wallet['balance'] ?? 0) . " تومان") . "\n";

            return $csv;
        } catch (\Exception $e) {
            $this->logger->error('data_export.csv_failed', ['user_id' => $userId, 'error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * ذخیره فایل صادرشده
     */
    public function saveExportFile(int $exportId, string $format, string $content): ?string
    {
        try {
            $timestamp = date('YmdHis');
            $filename = "export_{$exportId}_{$timestamp}.{$format}";
            $exportDir = storage_path("exports");
            $filepath = $exportDir . "/{$filename}";

            // Finding #9 Fix: Secure permissions 0700 for dir and 0600 for files
            if (!is_dir($exportDir)) {
                mkdir($exportDir, 0700, true);
            }
            @chmod($exportDir, 0700);

            $tempFile = tempnam($exportDir, 'tmp_exp_');
            if ($tempFile !== false) {
                file_put_contents($tempFile, $content);
                chmod($tempFile, 0600);
                rename($tempFile, $filepath);
                chmod($filepath, 0600);
            } else {
                file_put_contents($filepath, $content);
                chmod($filepath, 0600);
            }

            // بروزرسانی وضعیت
            $this->exportModel->updateStatus($exportId, 'completed', $filepath);

            $this->logger->info('data_export.file_saved', ['export_id' => $exportId, 'filepath' => $filepath]);
            return $filepath;
        } catch (\Exception $e) {
            $this->logger->error('data_export.save_failed', ['export_id' => $exportId, 'error' => $e->getMessage()]);
            $this->exportModel->updateStatus($exportId, 'failed', null, $e->getMessage());
            return null;
        }
    }

    /**
     * حذف فایل‌های منقضی
     */
    public function deleteExpiredExports(): int
    {
        try {
            $expiredExports = $this->exportModel->getExpiredExports();
            $deleted = 0;

            $baseExportDir = realpath(storage_path('exports'));

            foreach ($expiredExports as $export) {
                $filePath = is_array($export) && is_scalar($export['file_path'] ?? null)
                    ? (string)$export['file_path']
                    : '';
                if ($filePath !== '') {
                    $realPath = realpath($filePath);
                    // 🛡️ Path Containment Fix (Issue #25): Ensure base dir has trailing separator
                    $baseDirFormatted = $baseExportDir !== false ? rtrim($baseExportDir, '/\\') . DIRECTORY_SEPARATOR : '';
                    if ($realPath !== false && $baseDirFormatted !== '' && (str_starts_with($realPath, $baseDirFormatted) || $realPath === rtrim($baseDirFormatted, '/\\'))) {
                        $lockFile = $realPath . '.lock';
                        if (file_exists($lockFile)) {
                            continue;
                        }
                        
                        touch($lockFile);
                        try {
                            if (file_exists($realPath)) {
                                unlink($realPath);
                            }
                            $exportId = is_array($export) && is_numeric($export['id'] ?? null) ? (int)$export['id'] : 0;
                            $this->exportModel->clearFilePath($exportId);
                            $deleted++;
                        } finally {
                            @unlink($lockFile);
                        }
                    } else {
                        $exportId = is_array($export) && is_numeric($export['id'] ?? null) ? (int)$export['id'] : 0;
                            $this->exportModel->clearFilePath($exportId);
                    }
                } else {
                    $exportId = is_array($export) && is_numeric($export['id'] ?? null) ? (int)$export['id'] : 0;
                            $this->exportModel->clearFilePath($exportId);
                }
            }

            $this->logger->info('data_export.expired_deleted', ['count' => $deleted]);
            return $deleted;
        } catch (\Exception $e) {
            $this->logger->error('data_export.delete_expired_failed', ['error' => $e->getMessage()]);
            return 0;
        }
    }

    /**
     * دریافت تراکنش‌های کاربر
     */
    /** @return list<array<string, mixed>> */
    private function getUserTransactions(int $userId): array
    {
        return $this->transactionModel->getRecentByUserId($userId, 100);
    }

    /**
     * دریافت کیف‌پول کاربر
     */
    /** @return array<string, mixed> */
    private function getUserWallet(int $userId): array
    {
        $wallet = $this->walletModel->findByUserId($userId);
        if (!$wallet) {
            return [];
        }

        return [
            'balance_irt' => floatval($wallet->balance_irt ?? 0),
            'balance_usdt' => floatval($wallet->balance_usdt ?? 0),
            'locked_irt' => floatval($wallet->locked_irt ?? 0),
            'locked_usdt' => floatval($wallet->locked_usdt ?? 0),
            'total_irt' => floatval($wallet->balance_irt ?? 0) + floatval($wallet->locked_irt ?? 0),
            'total_usdt' => floatval($wallet->balance_usdt ?? 0) + floatval($wallet->locked_usdt ?? 0),
            'currency' => 'multi',
            'balance' => floatval($wallet->balance_irt ?? 0),
        ];
    }

    /**
     * دریافت KYC کاربر
     */
    /** @return array<string, mixed> */
    private function getUserKYC(int $userId): array
    {
        $kyc = $this->kycVerificationModel->findByUserId($userId);
        if (!$kyc) {
            return [];
        }

        return [
            'status' => $kyc->status,
            'verified_at' => $kyc->verified_at ?? null,
            'document_type' => $kyc->document_type ?? null,
        ];
    }

    /**
     * دریافت تنظیمات کاربر
     */
    /** @return array<string, mixed> */
    private function getUserSettings(int $userId): array
    {
        $settings = $this->userSettingModel->getUserSettings($userId);

        $result = [];
        foreach ($settings as $setting) {
            $item = (array)$setting;
            $k = (string)($item['setting_key'] ?? '');
            if ($k !== '') {
                $result[$k] = $item['setting_value'] ?? null;
            }
        }
        return $result;
    }

    /**
     * پاکسازی داده‌های حساس
     */
    /** @return array<string, mixed> */
    private function sanitizeUserData(mixed $user): array
    {
        $userArray = is_object($user) ? get_object_vars($user) : (is_array($user) ? $user : []);
        return [
            'id' => $userArray['id'] ?? null,
            'username' => $userArray['username'] ?? null,
            'full_name' => $userArray['full_name'] ?? null,
            'email' => $userArray['email'] ?? null,
            'mobile' => $userArray['mobile'] ?? null,
            'kyc_status' => $userArray['kyc_status'] ?? null,
            'created_at' => $userArray['created_at'] ?? null,
            'updated_at' => $userArray['updated_at'] ?? null,
        ];
    }
}
