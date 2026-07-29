<?php
/**
 * Cleanup Cron Job
 * 
 * اجرا: php storage/cron/cleanup.php
 * 
 * وظایف:
 * - پاکسازی Session های منقضی شده
 * - پاکسازی Rate Limit های قدیمی
 * - پاکسازی Cache های منقضی شده
 * - پاکسازی Password Reset های منقضی شده
 */


// ─── CLI-only guard ───────────────────────────────────────────────────────────
// این فایل فقط از طریق CLI (crontab/shell) قابل اجرا است.
// دسترسی مستقیم از مرورگر یا HTTP ممنوع است.
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('Access denied: این اسکریپت فقط از طریق CLI قابل اجرا است.');
}
// ─────────────────────────────────────────────────────────────────────────────
define('BASE_PATH', dirname(__DIR__, 2));
require_once BASE_PATH . '/core/Autoloader.php';
require_once BASE_PATH . '/bootstrap/app.php';

use Core\Session;
use Core\RateLimiter;
use Core\Cache;
use App\Models\PasswordReset;
use App\Models\ActivityLog;


echo "=================================\n";
echo "   Cleanup Cron Job Started\n";
echo "=================================\n\n";

try {
    // 1. پاکسازی Sessions
    echo "🔄 Cleaning up expired sessions...\n";
    $sessionsCleaned = Session::cleanupExpiredSessions();
    echo "✅ Cleaned {$sessionsCleaned} sessions\n\n";
    
    // 2. پاکسازی Rate Limits
    echo "🔄 Cleaning up rate limits...\n";
    $rateLimiter = app(RateLimiter::class);
    $rateLimitsCleaned = $rateLimiter->cleanup();
    echo "✅ Cleaned {$rateLimitsCleaned} rate limit files\n\n";
    
    // 3. پاکسازی Cache
    echo "🔄 Cleaning up cache...\n";
    $cache = Cache::getInstance();
    $cacheCleaned = $cache->cleanup();
    echo "✅ Cleaned {$cacheCleaned} cache files\n\n";
    
    // 4. پاکسازی Password Resets
    echo "🔄 Cleaning up expired password resets...\n";
    $passwordReset = app(PasswordReset::class);
    $resetsCleaned = $passwordReset->cleanupExpired();
    echo "✅ Cleaned {$resetsCleaned} password reset tokens\n\n";
    
    // 5. پاکسازی Activity Logs (قدیمی‌تر از 90 روز)
    echo "🔄 Cleaning up old activity logs...\n";
    $activityLog = app(ActivityLog::class);
    $logsCleaned = $activityLog->cleanup(90);
    echo "✅ Cleaned {$logsCleaned} activity logs\n\n";
    
    echo "=================================\n";
    echo "   Cleanup Completed Successfully\n";
    echo "=================================\n\n";
    
} catch (\Exception $e) {
    echo PHP_EOL . "Error: " . $e->getMessage() . PHP_EOL . PHP_EOL;
    $this->logger->error('cron.cleanup.failed', [
        'channel' => 'cron',
        'error' => $e->getMessage(),
        'exception' => get_class($e),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
    ]);
    exit(1);
}