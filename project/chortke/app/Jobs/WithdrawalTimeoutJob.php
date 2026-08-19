<?php

declare(strict_types=1);

namespace App\Jobs;

use Core\Database;
use Core\Cache;
use App\Contracts\LoggerInterface;
use App\Services\Wallet\WalletMutationService;

/**
 * WithdrawalTimeoutJob
 * 
 * اسکن خودکار برداشت‌های گیر کرده (stuck) که بیش از X ساعت در وضعیت
 * 'pending' یا 'processing' مانده‌اند و آن‌ها را لغو کرده و مبلغ را باز می‌گرداند.
 * همچنین گزارشی برای بررسی ادمین ثبت می‌کند.
 * 
 * در Cron: هر ۱۰ دقیقه اجرا می‌شود
 */
class WithdrawalTimeoutJob
{
    private Database $db;
    private LoggerInterface $logger;
    private WalletMutationService $walletMutationService;

    /** ساعت‌هایی بعد از آن برداشت stuck محسوب می‌شود */
    private const STUCK_HOURS = 2;
    
    /** حداکثر تعداد برداشتی که در هر اجرا اسکن می‌شود */
    private const MAX_SCAN = 200;

    public function __construct(
        Database $db,
        LoggerInterface $logger,
        WalletMutationService $walletMutationService
    ) {
        $this->db = $db;
        $this->logger = $logger;
        $this->walletMutationService = $walletMutationService;
    }

/**
 * @param array<string, mixed> $data
 * @return array<string, mixed>
 */
public function handle(array $data = []): array
    {
        $hours = int_value($data['stuck_hours'] ?? self::STUCK_HOURS);
        $limit = int_value($data['limit'] ?? self::MAX_SCAN);

        try {
            // پیدا کردن برداشت‌هایی که بیش از X ساعت در وضعیت pending/processing مانده‌اند
            $stuckWithdrawals = $this->db->query("
                SELECT id, user_id, amount, currency, status, transaction_id, created_at,
                       TIMESTAMPDIFF(HOUR, created_at, NOW()) AS stuck_hours
                FROM withdrawals
                WHERE status IN ('pending', 'processing')
                  AND created_at < DATE_SUB(NOW(), INTERVAL ? HOUR)
                ORDER BY created_at ASC
                LIMIT ?
            ", [$hours, $limit])->fetchAll(\PDO::FETCH_OBJ);

            if (empty($stuckWithdrawals)) {
                return ['scanned' => 0, 'cancelled' => 0, 'message' => 'بدون برداشت گیر کرده'];
            }

            // ✅ N+1 FIX: یک query برای پیدا کردن همه withdrawal_ids که قبلاً flag شدند
            $allIds         = array_map(fn($w) => (int)$w->id, $stuckWithdrawals);
            $placeholders   = implode(',', array_fill(0, count($allIds), '?'));
            $alreadyFlagged = $this->db->fetchAll(
                "SELECT withdrawal_id FROM withdrawal_reviews WHERE withdrawal_id IN ({$placeholders})",
                $allIds
            ) ?: [];
            $flaggedSet = array_flip(array_column($alreadyFlagged, 'withdrawal_id'));
            // ────────────────────────────────────────────────────────────

            $cancelledCount = 0;
            foreach ($stuckWithdrawals as $w) {
                // ۱. ثبت گزارش برای ادمین — بدون SELECT اضافه
                if (!isset($flaggedSet[(int)$w->id])) {
                    $this->db->query(
                        "INSERT INTO withdrawal_reviews 
                         (withdrawal_id, user_id, amount, currency, status, 
                          stuck_hours, detected_at, created_at)
                        VALUES (?, ?, ?, ?, 'pending', ?, NOW(), NOW())",
                        [
                            (int)$w->id,
                            (int)$w->user_id,
                            (string)$w->amount,
                            (string)$w->currency,
                            (int)$w->stuck_hours,
                        ]
                    );
                }

                // ۲. لغو خودکار برداشت و بازگشت موجودی (Auto-Cancellation)
                try {
                    // استفاده از WalletMutationService برای اطمینان از اتمیک بودن بازگشت وجه و تغییر وضعیت
                    $success = $this->walletMutationService->cancelWithdrawal(
                        (string)$w->transaction_id,
                        (int)$w->user_id
                    );

                    if ($success) {
                        $this->db->query(
                            "UPDATE withdrawals
                             SET status = 'cancelled', rejection_reason = COALESCE(rejection_reason, 'لغو خودکار به دلیل timeout'), updated_at = NOW()
                             WHERE id = ? AND status IN ('pending', 'processing')",
                            [(int)$w->id]
                        );

                        $cancelledCount++;
                        $this->logger->info('withdrawal.timeout.cancelled', [
                            'withdrawal_id' => $w->id,
                            'user_id' => $w->user_id,
                            'amount' => $w->amount
                        ]);
                    }
                } catch (\Throwable $e) {
                    $this->logger->error('withdrawal.timeout.cancel_failed', [
                        'withdrawal_id' => $w->id,
                        'error' => $e->getMessage()
                    ]);
                    \App\Services\Sentry\SentryExceptionHandler::captureException($e, (int)$w->user_id, [
                        'operation'     => 'withdrawal.timeout.cancel',
                        'withdrawal_id' => $w->id,
                        'amount'        => $w->amount,
                        'stuck_hours'   => $w->stuck_hours,
                    ]);
                }
            }

            $this->logger->info('withdrawal.stuck_processed', [
                'scanned' => count($stuckWithdrawals),
                'cancelled' => $cancelledCount,
                'hours_threshold' => $hours,
            ]);

            return [
                'scanned' => count($stuckWithdrawals),
                'cancelled' => $cancelledCount,
                'message' => "{$cancelledCount} برداشت گیر کرده لغو و بازگردانده شد",
            ];

        } catch (\Throwable $e) {
            $this->logger->error('withdrawal.stuck_scan_failed', ['error' => $e->getMessage()]);
            \App\Services\Sentry\SentryExceptionHandler::captureException($e, null, [
                'operation' => 'withdrawal.stuck_scan',
            ]);
            return ['scanned' => 0, 'cancelled' => 0, 'error' => $e->getMessage()];
        }
    }
}
