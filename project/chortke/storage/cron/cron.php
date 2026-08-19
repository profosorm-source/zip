<?php
declare(strict_types=1);
/**
 * cron.php - نقطه ورود زمانبندی وظایف
 *
 * ثبت در crontab سرور (هر دقیقه یکبار اجرا می‌شود):
 *   * * * * * /usr/bin/php /var/www/html/cron.php >> /var/log/chortke-cron.log 2>&1
 *
 * اجرای دستی برای تست:
 *   php cron.php
 *   php cron.php --job=email_queue   (فقط یک job خاص)
 *   php cron.php --dry-run            (فقط نمایش بدون اجرا)
 */


// ─── CLI-only guard ───────────────────────────────────────────────────────────
// این فایل فقط از طریق CLI (crontab/shell) قابل اجرا است.
// دسترسی مستقیم از مرورگر یا HTTP ممنوع است.
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('Access denied: این اسکریپت فقط از طریق CLI قابل اجرا است.');
}
// ─────────────────────────────────────────────────────────────────────────────

define('CRON_MODE', true);
if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__, 2));
}

require_once BASE_PATH . '/bootstrap/app.php';

use Core\Scheduler;
use App\Services\EmailService;
use App\Services\CryptoDeposit\CryptoDepositService;
use App\Services\UserLevelService;
use App\Services\LotteryService;
use App\Services\BannerService;
use App\Services\WithdrawalService;
use App\Services\Cron\CronService;
use App\Models\Advertisement;
use Core\Cache;
use Core\Database;

if (!class_exists('App\Services\CronService', false)) {
    \class_alias(\App\Services\Cron\CronService::class, 'App\Services\CronService');
}
if (!class_exists('App\Services\UserLevelService', false)) {
    \class_alias(\App\Services\User\UserLevelService::class, 'App\Services\UserLevelService');
}

// ==========================================
//  پارامترهای CLI
// ==========================================
$onlyJob = null;
$dryRun  = false;

foreach ($argv ?? [] as $arg) {
    if (str_starts_with($arg, '--job=')) {
        $onlyJob = substr($arg, 6);
    }
    if ($arg === '--dry-run') {
        $dryRun = true;
    }
}

if ($dryRun) {
    echo "[DRY-RUN] فقط نمایش وظایف - اجرا نمی‌شوند\n";
}

// ==========================================
//  تعریف وظایف
// ==========================================
$scheduler = new Scheduler();
if ($onlyJob !== null) {
    $scheduler->forceRegisterJobs(true);
}

/**
 * ─────────────────────────────────────────
 * هر دقیقه
 * ─────────────────────────────────────────
 */

// پردازش صف ایمیل‌ها
$scheduler->everyMinute(function () {
    $service = app(EmailService::class);
    $result  = $service->processQueue(20); // حداکثر 20 ایمیل در هر دقیقه
    return [
        'sent'   => $result['sent']   ?? 0,
        'failed' => $result['failed'] ?? 0,
    ];
}, 'email_queue');

// تأیید خودکار واریزهای کریپتو در انتظار
$scheduler->everyMinute(function () {
    $cron    = app(\App\Services\CronService::class);
    $service = app(\App\Services\CryptoDeposit\CryptoDepositService::class);

    // از طریق CronService — بدون Database مستقیم
    $pending = $cron->getPendingCryptoDeposits(12, 10);

    $verified = 0;
    foreach ($pending as $row) {
        $id     = is_array($row) ? $row['id'] : $row->id;
        $result = $service->tryAutoVerify($id);
        if (($result['auto'] ?? false) === true) {
            $verified++;
        }
    }

    return ['pending_checked' => count($pending), 'verified' => $verified];
}, 'crypto_verify');

/**
 * ─────────────────────────────────────────
 * هر ۵ دقیقه
 * ─────────────────────────────────────────
 */

// پاک‌سازی کش منقضی‌شده
$scheduler->everyMinutes(5, function () {
    $cleaned = Cache::getInstance()->cleanup();
    return ['cleaned_files' => $cleaned];
}, 'cache_cleanup');

/**
 * ─────────────────────────────────────────
 * هر ساعت (دقیقه ۰)
 * ─────────────────────────────────────────
 */

// غیرفعال کردن آگهی‌های منقضی‌شده
$scheduler->hourly(function () {
    $cron     = app(\App\Services\CronService::class);
    $affected = $cron->expireOldAdvertisements();
    return ['expired_ads' => $affected];
}, 'expire_ads');

// غیرفعال کردن بنرهای منقضی‌شده
$scheduler->hourly(function () {
    $service = app(BannerService::class);
    $count   = $service->deactivateExpiredBanners();
    return ['deactivated_banners' => $count];
}, 'expire_banners');

// انقضای نشست‌های قدیمی کاربران (بیش از ۳۰ روز)
$scheduler->hourly(function () {
    $cron     = app(\App\Services\CronService::class);
    $affected = $cron->deleteOldSessions(30);
    return ['deleted_sessions' => $affected];
}, 'cleanup_sessions');

// پاک‌سازی توکن‌های reset password منقضی
$scheduler->hourly(function () {
    $cron     = app(\App\Services\CronService::class);
    $affected = $cron->deleteExpiredPasswordResets();
    return ['deleted_tokens' => $affected];
}, 'cleanup_password_resets');

/**
 * ─────────────────────────────────────────
 * روزانه ساعت ۰۲:۰۰
 * ─────────────────────────────────────────
 */

// بررسی سطح کاربران (downgrade/upgrade/expire)
$scheduler->daily('02:00', function () {
    $service = app(UserLevelService::class);

    $downgrades = $service->checkDowngrades();
    $expired    = $service->checkExpiredPurchases();

    return [
        'downgraded' => count($downgrades),
        'expired'    => $expired,
    ];
}, 'user_levels');

// پاک‌سازی لاگ‌های قدیمی (بیش از ۹۰ روز)
$scheduler->daily('02:30', function () {
    $cron     = app(\App\Services\CronService::class);
    $affected = $cron->deleteOldActivityLogs(90);
    return ['deleted_logs' => $affected];
}, 'cleanup_logs');

// پاک‌سازی ایمیل‌های ارسال‌شده قدیمی (بیش از ۳۰ روز)
$scheduler->daily('03:00', function () {
    $cron     = app(\App\Services\CronService::class);
    $affected = $cron->deleteOldSentEmails(30);
    return ['deleted_emails' => $affected];
}, 'cleanup_email_queue');

// پاک‌سازی تصاویر KYC رد شده قدیمی (۶۰ روز)
$scheduler->daily('03:30', function () {
    $cron    = app(\App\Services\CronService::class);
    $rows    = $cron->getOldRejectedKycRecords(60);
    $cleaned = 0;

    foreach ($rows as $row) {
        foreach (['document_front', 'document_back', 'selfie'] as $field) {
            if (!empty($row[$field])) {
                $path = BASE_PATH . '/storage/uploads/kyc/' . $row[$field];
                if (file_exists($path)) {
                    unlink($path);
                }
            }
        }
        $cron->markKycDocumentsDeleted((int)$row['id']);
        $cleaned++;
    }

    return ['cleaned_kyc_files' => $cleaned];
}, 'cleanup_kyc_files');

/**
 * ─────────────────────────────────────────
 * روزانه ساعت ۰۴:۰۰ - ریست ماهانه
 * ─────────────────────────────────────────
 */

// ریست آمار ماهانه سطح کاربران (اول هر ماه)
$scheduler->daily('04:00', function () {
    if ((int)date('j') !== 1) {
        return ['skipped' => 'not first day of month'];
    }
    $service = app(UserLevelService::class);
    $reset   = $service->monthlyReset();
    return ['reset_users' => $reset];
}, 'monthly_level_reset');

/**
 * ─────────────────────────────────────────
 * هفتگی - یکشنبه ساعت ۰۵:۰۰
 * ─────────────────────────────────────────
 */

// گزارش هفتگی KPI به ادمین
$scheduler->weekly('Sunday', '05:00', function () {
    $cron     = app(\App\Services\CronService::class);
    $newUsers = $cron->countNewUsers(7);
    $txVolume = $cron->getTransactionVolume(7);

    Cache::getInstance()->put('kpi_weekly_report', [
        'new_users'    => $newUsers,
        'tx_volume'    => $txVolume,
        'generated_at' => date('Y-m-d H:i:s'),
    ], 10080); // یک هفته

    return ['new_users' => $newUsers, 'tx_volume' => $txVolume];
}, 'weekly_kpi_report');

// ==========================================
//  اجرا
// ==========================================

echo '[' . date('Y-m-d H:i:s') . '] شروع اجرای cron jobs' . PHP_EOL;

if ($dryRun) {
    echo "وظایف ثبت‌شده - اجرا نشدند (dry-run mode)\n";
    exit(0);
}

$results = $scheduler->run($onlyJob);

// نمایش نتایج
foreach ($results as $name => $result) {
    $status = $result['status'];
    $icon   = match($status) {
        'ok'      => '✓',
        'error'   => '✗',
        'skipped' => '⟳',
        default   => '?',
    };

    echo "[{$icon}] {$name}: {$status}";

    if ($status === 'ok' && isset($result['output'])) {
        $out = $result['output'];
        if (is_array($out)) {
            echo ' - ' . implode(', ', array_map(
                fn($k, $v) => "{$k}={$v}",
                array_keys($out),
                array_values($out)
            ));
        }
    }

    if ($status === 'error') {
        echo ' - ' . ($result['message'] ?? '');
    }

    echo PHP_EOL;
}

echo '[' . date('Y-m-d H:i:s') . '] پایان' . PHP_EOL;
