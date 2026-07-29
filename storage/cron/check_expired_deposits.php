<?php

/**
 * Cron Job: بررسی واریزهای منقضی‌شده
 *
 * اجرا: هر ساعت یکبار
 * دستور Cron: 0 * * * * php /path/to/project/storage/cron/check_expired_deposits.php
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('Access denied: این اسکریپت فقط از طریق CLI قابل اجرا است.');
}

require_once __DIR__ . '/../../core/Autoloader.php';
require_once __DIR__ . '/../../bootstrap/app.php';

use App\Services\CronService;

echo "[" . date('Y-m-d H:i:s') . "] Checking for expired deposits...\n";

try {
    $cron     = app(CronService::class);
    $deposits = $cron->getExpiredCryptoDeposits(30, 3);

    if (empty($deposits)) {
        echo "No expired deposits found.\n";
        exit(0);
    }

    echo "Found " . count($deposits) . " expired deposit(s).\n";

    $count = 0;
    foreach ($deposits as $deposit) {
        $updated = $cron->moveCryptoDepositToManualReview(
            $deposit->id,
            'زمان بررسی خودکار منقضی شد - نیاز به بررسی دستی'
        );

        if ($updated) {
            $count++;
            echo "✓ Deposit ID {$deposit->id} moved to manual review.\n";
        }
    }

    echo "\nTotal moved to manual review: {$count}\n";

} catch (\Exception $e) {
    echo "Error: " . $e->getMessage() . PHP_EOL;
    $this->logger->error('cron.deposits.check_expired.failed', [
        'channel' => 'cron',
        'error' => $e->getMessage(),
        'exception' => get_class($e),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
    ]);
    exit(1);
}

echo "[" . date('Y-m-d H:i:s') . "] Cron job completed.\n";
exit(0);
