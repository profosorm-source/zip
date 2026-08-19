<?php

/**
 * Cron Job: گزارش روزانه کیف پول
 *
 * اجرا: روزانه در ساعت 23:00
 * دستور Cron: 0 23 * * * php /path/to/project/storage/cron/daily_wallet_report.php
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('Access denied: این اسکریپت فقط از طریق CLI قابل اجرا است.');
}

require_once __DIR__ . '/../../core/Autoloader.php';
require_once __DIR__ . '/../../bootstrap/app.php';

use App\Services\CronService;

echo "[" . date('Y-m-d H:i:s') . "] Generating daily wallet report...\n";

try {
    $cron  = app(CronService::class);
    $today = date('Y-m-d');

    $irtReport  = $cron->getDailyTransactionReport('irt',  $today);
    $usdtReport = $cron->getDailyTransactionReport('usdt', $today);

    $report = [
        'date' => $today,
        'irt'  => [
            'total_transactions' => $irtReport->total_count,
            'total_deposits'     => $irtReport->total_deposits,
            'total_withdrawals'  => $irtReport->total_withdrawals,
            'deposit_count'      => $irtReport->deposit_count,
            'withdrawal_count'   => $irtReport->withdrawal_count,
        ],
        'usdt' => [
            'total_transactions' => $usdtReport->total_count,
            'total_deposits'     => $usdtReport->total_deposits,
            'total_withdrawals'  => $usdtReport->total_withdrawals,
            'deposit_count'      => $usdtReport->deposit_count,
            'withdrawal_count'   => $usdtReport->withdrawal_count,
        ],
    ];

    $reportPath = __DIR__ . '/../reports/';
    if (!is_dir($reportPath)) {
        mkdir($reportPath, 0755, true);
    }

    $filename = $reportPath . 'wallet_report_' . $today . '.json';
    file_put_contents($filename, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

    echo "✓ Report saved: {$filename}\n";
    echo "\n=== IRT Summary ===\n";
    echo "Total Transactions: {$irtReport->total_count}\n";
    echo "Total Deposits: "     . number_format($irtReport->total_deposits)    . " تومان\n";
    echo "Total Withdrawals: "  . number_format($irtReport->total_withdrawals) . " تومان\n";
    echo "\n=== USDT Summary ===\n";
    echo "Total Transactions: {$usdtReport->total_count}\n";
    echo "Total Deposits: {$usdtReport->total_deposits} USDT\n";
    echo "Total Withdrawals: {$usdtReport->total_withdrawals} USDT\n";

} catch (\Exception $e) {
    echo "Error: " . $e->getMessage() . PHP_EOL;
    $this->logger->error('cron.wallet.daily_report.failed', [
        'channel' => 'cron',
        'error' => $e->getMessage(),
        'exception' => get_class($e),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
    ]);
    exit(1);
}

echo "\n[" . date('Y-m-d H:i:s') . "] Report generation completed.\n";
exit(0);
