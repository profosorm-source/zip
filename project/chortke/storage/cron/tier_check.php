<?php

/**
 * Tier Level Check Cron Job
 *
 * اجرا: php storage/cron/tier_check.php
 *
 * وظایف:
 * - بررسی فعالیت کاربران
 * - Reset کردن Tier Level کاربران غیرفعال
 * - ارسال نوتیفیکیشن به کاربران
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('Access denied: این اسکریپت فقط از طریق CLI قابل اجرا است.');
}
define('BASE_PATH', dirname(__DIR__, 2));
require_once BASE_PATH . '/core/Autoloader.php';
require_once BASE_PATH . '/bootstrap/app.php';

use App\Services\CronService;

echo "=================================\n";
echo "   Tier Level Check Started\n";
echo "=================================\n\n";

try {
    $cron  = app(CronService::class);
    $users = $cron->getUsersWithElevatedTier(['gold', 'vip']);

    echo "🔄 Checking user tier levels...\n\n";

    if (empty($users)) {
        echo "ℹ️  No users with elevated tier levels found.\n\n";
        exit(0);
    }

    echo "Found " . count($users) . " users to check\n\n";

    $resetCount  = 0;
    $monthStart  = strtotime(date('Y-m-01'));

    foreach ($users as $user) {
        $userId     = $user['id'];
        $username   = $user['username'];
        $tierLevel  = $user['level_slug'];
        $lastActive = $user['last_active_date'] ? strtotime($user['last_active_date']) : 0;

        $daysInMonth = (int)date('j');
        $inactiveDays = 0;

        if ($lastActive < $monthStart) {
            // کل ماه غیرفعال بوده
            $inactiveDays = $daysInMonth;
        } else {
            $activeDays   = $cron->countUserActiveDaysThisMonth($userId);
            $inactiveDays = $daysInMonth - $activeDays;
        }

        echo "User: {$username} (Tier: {$tierLevel})\n";
        echo "  - Inactive days this month: {$inactiveDays}\n";

        if ($inactiveDays > 3) {
            echo "  ⚠️  Reset tier to Silver\n";

            $cron->resetUserTierToSilver($userId);

            $cron->logSystemActivity(
                $userId,
                'tier_reset',
                "سطح کاربری به دلیل عدم فعالیت ({$inactiveDays} روز) به نقره‌ای تغییر کرد"
            );

            $resetCount++;
            echo "  ✅ Notification sent\n";
        } else {
            echo "  ✓ Active enough, no action needed\n";
        }

        echo "\n";
    }

    echo "=================================\n";
    echo "✅ Tier Check Completed\n";
    echo "   Users checked: " . count($users) . "\n";
    echo "   Tiers reset: {$resetCount}\n";
    echo "=================================\n\n";

}catch (\Exception $e) {
    echo PHP_EOL . "Error: " . $e->getMessage() . PHP_EOL . PHP_EOL;
    $this->logger->error('cron.tier_check.failed', [
        'channel' => 'cron',
        'error' => $e->getMessage(),
        'exception' => get_class($e),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
    ]);
    exit(1);
}