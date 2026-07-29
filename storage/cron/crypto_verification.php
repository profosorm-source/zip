<?php

/**
 * Cron Job: بررسی خودکار واریزهای کریپتو
 *
 * اجرا: هر 5 دقیقه یکبار
 * دستور Cron: هر 5 دقیقه یکبار با دستور cron مناسب اجرا شود
 */

// ─── CLI-only guard ───────────────────────────────────────────────────────────
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('Access denied: این اسکریپت فقط از طریق CLI قابل اجرا است.');
}
// ─────────────────────────────────────────────────────────────────────────────

require_once __DIR__ . '/../../core/Autoloader.php';
require_once __DIR__ . '/../../bootstrap/app.php';

use App\Models\CryptoDeposit;
use App\Services\CryptoDeposit\CryptoDepositService;

echo "[" . date('Y-m-d H:i:s') . "] Starting crypto verification cron job...\n";

try {
    $depositModel = app(CryptoDeposit::class);
    $cryptoDepositService = app(CryptoDepositService::class);

    $deposits = $depositModel->getPendingForVerification(3, 10);

    if (empty($deposits)) {
        echo "No pending deposits found.\n";
        exit(0);
    }

    echo "Found " . count($deposits) . " pending deposit(s).\n";

    $successCount = 0;
    $failCount = 0;
    $manualReviewCount = 0;

    foreach ($deposits as $deposit) {
        echo "\nProcessing deposit ID: {$deposit->id} (User: {$deposit->user_id}, Amount: {$deposit->amount} USDT)\n";

        try {
            $result = $cryptoDepositService->tryAutoVerify($deposit->id);

            if (($result['auto'] ?? false) === true) {
                echo "✓ Auto verification successful!\n";
                $successCount++;
            } else {
                $message = $result['message'] ?? 'نامشخص';
                echo "⚠ {$message}\n";

                if (strpos($message, 'بررسی دستی') !== false) {
                    $manualReviewCount++;
                } else {
                    $failCount++;
                }
            }
        } catch (\Throwable $e) {
            echo "Error: " . $e->getMessage() . PHP_EOL;
            $failCount++;
        }

        sleep(2);
    }

    echo "\n=== Summary ===\n";
    echo "Success: {$successCount}\n";
    echo "Failed: {$failCount}\n";
    echo "Manual Review: {$manualReviewCount}\n";
    echo "Total Processed: " . count($deposits) . "\n";

    echo "[" . date('Y-m-d H:i:s') . "] Cron job completed.\n";
    exit(0);
} catch (\Throwable $e) {
    echo "Critical Error: " . $e->getMessage() . PHP_EOL;
    exit(1);
}
