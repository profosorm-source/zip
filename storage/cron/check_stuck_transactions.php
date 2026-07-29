<?php

/**
 * Cron Job: بررسی تراکنش‌های گیرکرده
 *
 * اجرا: هر 30 دقیقه یکبار
 * دستور Cron: *\/30 * * * * php /path/to/project/storage/cron/check_stuck_transactions.php
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('Access denied: این اسکریپت فقط از طریق CLI قابل اجرا است.');
}

require_once __DIR__ . '/../../core/Autoloader.php';
require_once __DIR__ . '/../../bootstrap/app.php';

use App\Services\CronService;
use App\Services\WalletService;

echo "[" . date('Y-m-d H:i:s') . "] Checking for stuck transactions...\n";

try {
    $cron             = app(CronService::class);
    $stuckTransactions = $cron->getStuckTransactions(1); // بیش از 1 ساعت

    if (empty($stuckTransactions)) {
        echo "No stuck transactions found.\n";
        exit(0);
    }

    echo "Found " . count($stuckTransactions) . " stuck transaction(s).\n";

    $walletService = app(WalletService::class);
    $count         = 0;

    foreach ($stuckTransactions as $tx) {
        $updated = $cron->markTransactionFailed($tx->id);

        if ($updated) {
            $count++;
            echo "✓ Transaction {$tx->transaction_id} marked as failed.\n";

            // اگر withdraw بود، موجودی را از طریق WalletService آزاد کن
            if ($tx->type === 'withdraw') {
                $walletService->cancelWithdrawal(
                    $tx->user_id,
                    (float)$tx->amount,
                    $tx->currency,
                    $tx->transaction_id
                );
                echo "  → Wallet balance unlocked.\n";
            }
        }
    }

    echo "\nTotal failed: {$count}\n";

    if ($count > 0) {
        $this->logger->warning('Stuck transactions detected and auto-failed', [
            'count'           => $count,
            'transaction_ids' => array_column($stuckTransactions, 'transaction_id'),
        ]);
    }

} catch (\Exception $e) {
    echo "Error: " . $e->getMessage() . PHP_EOL;
    $this->logger->error('cron.transactions.check_stuck.failed', [
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
