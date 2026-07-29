<?php

/**
 * Cron Job: بررسی قوانین هشدار و ارسال نوتیفیکیشن
 * اجرا: هر 5 دقیقه یکبار
 * 
 * افزودن به crontab:
 * *//* * * * * /usr/bin/php /path/to/project/cron_alert_checker.php
 */

if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__, 2));
}
require_once BASE_PATH . '/bootstrap/app.php';

use Core\Database;
use App\Services\LogNotificationService;
use App\Services\ErrorLogService;

try {
    echo "[" . date('Y-m-d H:i:s') . "] Starting alert checker...\n";

    $db = Database::getInstance();
    $notificationService = app(LogNotificationService::class);
    $errorService = app(ErrorLogService::class);

    // بررسی و اجرای قوانین هشدار
    $notificationService->checkAlertRules();
    echo "Alert rules checked.\n";

    // بررسی خطاهای تکراری که حل نشده‌اند
    $unresolvedErrors = $db->query(
        "SELECT * FROM error_logs 
         WHERE is_resolved = 0 
         AND occurrence_count >= 10
         AND last_occurred_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)
         ORDER BY occurrence_count DESC
         LIMIT 5"
    )->fetchAll(PDO::FETCH_OBJ);

    foreach ($unresolvedErrors as $error) {
        $notificationService->sendAlert(
            "⚠️ خطای تکراری حل نشده",
            "خطا '{$error->message}' بیش از {$error->occurrence_count} بار تکرار شده است.\n" .
            "فایل: {$error->file_path}:{$error->line_number}",
            'high'
        );
        echo "Sent alert for error #{$error->id}\n";
    }

    // آپدیت آمار روزانه
    updateDailyStatistics($db);
    echo "Daily statistics updated.\n";

    // پاکسازی خطاهای قدیمی (حل شده‌های بیش از 30 روز)
    $deleted = $errorService->cleanup(30);
    echo "Cleaned up {$deleted} old error logs.\n";

    echo "[" . date('Y-m-d H:i:s') . "] Alert checker completed successfully.\n";

} catch (Throwable $e) {
    echo "[ERROR] " . $e->getMessage() . "\n";
    $this->logger->error("Alert checker failed: " . $e->getMessage());
}

/**
 * آپدیت آمار روزانه
 */
function updateDailyStatistics(Database $db): void
{
    $today = date('Y-m-d');

    // شمارش خطاهای امروز
    $errorStats = $db->query(
        "SELECT 
            COUNT(*) as total_errors,
            SUM(CASE WHEN level IN ('CRITICAL', 'FATAL') THEN 1 ELSE 0 END) as critical_errors
         FROM error_logs
         WHERE DATE(created_at) = ?",
        [$today]
    )->fetch(PDO::FETCH_OBJ);

    // شمارش درخواست‌ها
    $perfStats = $db->query(
        "SELECT 
            COUNT(*) as total_requests,
            SUM(CASE WHEN is_slow = 1 THEN 1 ELSE 0 END) as slow_requests,
            AVG(execution_time) as avg_response_time,
            MAX(memory_usage) as peak_memory
         FROM performance_logs
         WHERE DATE(created_at) = ?",
        [$today]
    )->fetch(PDO::FETCH_OBJ);

    // شمارش حوادث امنیتی
    $securityStats = $db->query(
        "SELECT COUNT(*) as security_incidents
         FROM security_logs
         WHERE DATE(created_at) = ?",
        [$today]
    )->fetch(PDO::FETCH_OBJ);

    // تعداد کاربران منحصر به فرد
    $uniqueUsers = $db->query(
        "SELECT COUNT(DISTINCT user_id) as unique_users
         FROM performance_logs
         WHERE DATE(created_at) = ? AND user_id IS NOT NULL",
        [$today]
    )->fetch(PDO::FETCH_OBJ);

    // Insert or Update
    $db->query(
        "INSERT INTO daily_statistics 
        (stat_date, total_errors, critical_errors, total_requests, slow_requests, 
         avg_response_time, security_incidents, unique_users, peak_memory)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
        total_errors = VALUES(total_errors),
        critical_errors = VALUES(critical_errors),
        total_requests = VALUES(total_requests),
        slow_requests = VALUES(slow_requests),
        avg_response_time = VALUES(avg_response_time),
        security_incidents = VALUES(security_incidents),
        unique_users = VALUES(unique_users),
        peak_memory = VALUES(peak_memory),
        updated_at = NOW()",
        [
            $today,
            $errorStats->total_errors ?? 0,
            $errorStats->critical_errors ?? 0,
            $perfStats->total_requests ?? 0,
            $perfStats->slow_requests ?? 0,
            $perfStats->avg_response_time ?? 0,
            $securityStats->security_incidents ?? 0,
            $uniqueUsers->unique_users ?? 0,
            $perfStats->peak_memory ?? 0
        ]
    );
}
