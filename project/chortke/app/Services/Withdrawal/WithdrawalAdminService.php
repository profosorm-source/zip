<?php

declare(strict_types=1);

namespace App\Services\Withdrawal;

use App\Services\SagaOrchestrator;
use App\Contracts\WalletServiceInterface;
use App\Models\Withdrawal;
use App\Contracts\LoggerInterface;
use App\Services\Settings\AppSettings;
use App\Services\Notification\NotificationService;
use Core\Database;
use Core\Container;
use App\Services\OutboxService;
use App\Events\WithdrawalApprovedEvent;

/**
 * WithdrawalAdminService - نسخه اتمیک با الگوی ساگا
 */
class WithdrawalAdminService 
{
    public function __construct(
        private Database $db,
        private WalletServiceInterface $wallet,
        private Withdrawal $model,
        private LoggerInterface $logger,
        private AppSettings $appSettings,
        private NotificationService $notificationService,
        private SagaOrchestrator $sagaOrchestrator,
        private ?OutboxService $outbox = null
    ) {
    }

    /**
     * Centralized toObject (root-cause normalization).
     */
    private function toObject(mixed $data): ?\stdClass
    {
        if ($data === null || $data === false) return null;
        if (is_object($data)) {
            /** @var \stdClass $data */
            return $data;
        }
        if (is_array($data)) return (object)$data;
        return (object)(array)$data;
    }

    /** @return array<string, mixed> */
    public function adminApprove(int $withdrawalId, int $adminId, ?string $paymentReference = null): array
    {
        try {
            $this->db->beginTransaction();
            $withdrawal = $this->model->lockForUpdate($withdrawalId);
            if (!$withdrawal || (string)$withdrawal->status !== 'pending') {
                $this->db->rollback();
                return ['success' => false, 'message' => 'درخواست نامعتبر'];
            }

            $ok = $this->wallet->completeWithdrawal(
                (int)$withdrawal->user_id,
                (string)$withdrawal->amount,
                (string)$withdrawal->currency,
                (string)$withdrawal->transaction_id
            );
            if (!$ok) {
                throw new \RuntimeException('خطا در تسویه نهایی کیف پول');
            }

            $fee = $this->calculateFee((string)$withdrawal->amount, (string)$withdrawal->currency);
            $finalAmount = bcsub((string)$withdrawal->amount, $fee, 8);
            if (bccomp($finalAmount, '0', 8) < 0) {
                $finalAmount = '0';
            }

            $this->model->updateStatus($withdrawalId, 'completed', null, $adminId);
            $this->db->query(
                "UPDATE withdrawals
                 SET fee = ?, final_amount = ?, tracking_code = COALESCE(NULLIF(tracking_code, ''), ?), updated_at = NOW()
                 WHERE id = ?",
                [$fee, $finalAmount, (string)($paymentReference ?? ''), $withdrawalId]
            );

            // 🛡️ Ledger Recording Fix (Issue #13): Record platform fee revenue in ledger/transactions
            if (bccomp($fee, '0', 8) > 0) {
                try {
                    $this->db->query(
                        "INSERT INTO transactions (user_id, amount, currency, type, description, status, created_at)
                         VALUES (?, ?, ?, 'platform_fee', ?, 'completed', NOW())",
                        [(int)$withdrawal->user_id, $fee, (string)$withdrawal->currency, "کارمزد پلتفرم برای برداشت #{$withdrawalId}"]
                    );
                } catch (\Throwable $feeErr) {
                    $this->logger->warning('withdrawal.approve.fee_ledger_failed', ['withdrawal_id' => $withdrawalId, 'error' => $feeErr->getMessage()]);
                }
            }

            $this->db->commit();
            $result = [
                'success' => true,
                'message' => 'برداشت با موفقیت تأیید شد',
                'withdrawal_id' => $withdrawalId,
                'user_id' => (int)$withdrawal->user_id,
                'amount' => (string)$withdrawal->amount,
                'currency' => (string)$withdrawal->currency,
                'fee' => $fee,
                'final_amount' => $finalAmount,
                'admin_id' => $adminId,
            ];
            if ($this->outbox) {
                $this->outbox->record(
                    'withdrawal',
                    (string)$withdrawalId,
                    WithdrawalApprovedEvent::class,
                    [
                        'user_id' => (int)$withdrawal->user_id,
                        'withdrawal_id' => $withdrawalId,
                        'amount' => (string)$withdrawal->amount,
                        'currency' => (string)$withdrawal->currency,
                        'approved_by' => $adminId,
                    ]
                );
            }

            $this->notifyWithdrawalApproved($result);
            return $result;
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollback();
            }
            $this->logger->error('withdrawal.approve.failed', ['withdrawal_id' => $withdrawalId, 'error' => $e->getMessage()]);
            \App\Services\Sentry\SentryExceptionHandler::captureException($e, null, [
                'operation'     => 'withdrawal.adminApprove',
                'withdrawal_id' => $withdrawalId,
                'admin_id'      => $adminId,
            ]);
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * 🛡️ Fix: تبدیل reject به Saga Pattern برای consistency با approve
     * 
     * قبلی: transaction دستی (بدون compensation mechanism)
     * جدید: Saga با دو step: refund → update_status (با compensation)
     */
    /**
     * Auto-resolve stuck withdrawals (safe: only pending + older than X hours).
     * Used by StuckWithdrawalReviewCommand.
     */
    /** @return array<string, mixed> */
    public function autoResolveStuck(int $adminId, bool $stableOnly = true, int $limit = 50): array
    {
        $hours = $stableOnly ? 48 : 24;

        $rows = $this->db->fetchAll(
            "SELECT id, user_id, amount, currency, status, created_at
             FROM withdrawals
             WHERE status = 'pending'
               AND created_at < DATE_SUB(NOW(), INTERVAL ? HOUR)
             ORDER BY created_at ASC
             LIMIT ?",
            [$hours, $limit]
        );

        $pending = is_array($rows) ? $rows : [];
        $resolved = 0;
        $failed   = 0;

        foreach ($pending as $w) {
            $wObj = is_object($w) ? $w : (object)(array)$w;
            $wId = (int)($wObj->id ?? 0);
            if ($wId <= 0) continue;

            try {
                $this->db->beginTransaction();
                $this->db->execute(
                    "UPDATE withdrawals SET status = 'rejected', admin_note = ?, updated_at = NOW() WHERE id = ? AND status = 'pending'",
                    ['auto_resolved_stuck_' . $hours . 'h', $wId]
                );
                $this->db->commit();
                $resolved++;
            } catch (\Throwable $e) {
                if ($this->db->inTransaction()) $this->db->rollback();
                \App\Services\Sentry\SentryExceptionHandler::captureException($e, null, [
                    'operation'     => 'withdrawal.autoResolveStuck',
                    'withdrawal_id' => $wId,
                ]);
                $failed++;
            }
        }

        return [
            'scanned' => count($pending),
            'resolved' => $resolved,
            'fixed' => $resolved,
            'escalated' => 0,
            'failed' => $failed,
            'errors' => $failed,
        ];
    }

    /** @return array<string, mixed> */
    public function adminReject(int $withdrawalId, int $adminId, ?string $reason = null): array
    {
        $w = $this->toObject($this->model->find($withdrawalId));
        if (!$w || !isset($w->id) || $w->status !== 'pending') return ['success' => false, 'message' => 'درخواست نامعتبر'];

        $orchestrator = $this->sagaOrchestrator;

        // BUGFIX-SAGA-TX-ROOT: قبلاً فقط مرحله‌ی دوم (update_status) با
        // beginTransaction/commit مجزا محافظت می‌شد و مرحله‌ی اول (refund_hold)
        // کاملاً بدون تراکنش بود. اگر update_status بعد از refund_hold موفق fail
        // می‌شد، وجه به کیف پول کاربر برگردانده شده بود ولی وضعیت درخواست همچنان
        // pending می‌ماند (وضعیت ناقص). اکنون کل Saga در یک تراکنش واحد اجرا می‌شود.
        $result = $this->db->transactional(function () use ($orchestrator, $withdrawalId, $adminId, $reason) {
        return $orchestrator
            ->setSaga('withdrawal_rejection', ['withdrawal_id' => $withdrawalId, 'admin_id' => $adminId, 'reason' => $reason])
            ->addStep(
                'refund_hold',
                function($ctx) {
                    $w = $this->toObject($this->model->find($ctx['withdrawal_id']));
                    if (!$w || $w->status !== 'pending') throw new \Core\Exceptions\InvalidStateException('درخواست نامعتبر');

                    $ok = $this->wallet->cancelWithdrawal(
                        (int)$w->user_id,
                        (string)$w->amount,
                        (string)$w->currency,
                        (string)$w->transaction_id
                    );
                    if (!$ok) throw new \Core\Exceptions\ApplicationException('خطا در بازگشت وجه');

                    return array_merge($ctx, [
                        'user_id' => (int)$w->user_id,
                        'amount' => (string)$w->amount,
                        'currency' => (string)$w->currency,
                    ]);
                },
                function($err, $res) {
                    // در صورت خطا در مرحله دوم، refund قبلاً انجام شده و نیازی به جبران نیست
                }
            )
            ->addStep(
                'update_status',
                function($ctx) {
                    $this->model->updateStatus($ctx['withdrawal_id'], 'rejected', $ctx['reason'], $ctx['admin_id']);
                    return $ctx;
                },
                function($err, $res) {
                    // جبران: اگر آپدیت وضعیت failed، دوباره وجه را برمی‌گردانیم
                    if (isset($res['user_id']) && isset($res['withdrawal_id'])) {
                        try {
                    $w = $this->toObject($this->model->find($res['withdrawal_id']));
                    if ($w && isset($w->id)) {
                                $this->wallet->cancelWithdrawal(
                                    (int)$w->user_id,
                                    (string)$w->amount,
                                    (string)$w->currency,
                                    (string)$w->transaction_id
                                );
                            }
                        } catch (\Throwable $e) {
                            $this->logger->error('saga.withdrawal_reject.compensation_failed', [
                                'withdrawal_id' => $res['withdrawal_id'],
                                'error' => $e->getMessage(),
                            ]);
                            \App\Services\Sentry\SentryExceptionHandler::captureException($e, null, [
                                'operation' => 'withdrawal.reject.compensation',
                                'withdrawal_id' => $res['withdrawal_id'] ?? null,
                            ]);
                        }
                    }
                }
            )
            ->execute();
        });

        if (is_array($result)) { $this->notifyWithdrawalRejected($result); }

        return [
            'success' => true,
            'message' => 'برداشت رد شد و موجودی بازگردانده شد',
            'withdrawal_id' => $withdrawalId,
            'admin_id' => $adminId,
            'user_id' => (int)$w->user_id,
            'result' => $result,
        ];
    }

    private function calculateFee(string $amount, string $currency): string
    {
        $currencyKey = strtolower((string)$currency);
        $percent = $this->normalizeNonNegativeMoney(
            $this->appSettings->get("withdrawal_fee_percent_{$currencyKey}", '0')
        );
        $fixed = $this->normalizeNonNegativeMoney(
            $this->appSettings->get("withdrawal_fee_fixed_{$currencyKey}", '0')
        );

        $percentFee = '0';
        if (bccomp($percent, '0', 8) > 0) {
            $percentFee = bcdiv(bcmul($amount, $percent, 8), '100', 8);
        }

        return bcadd($percentFee, $fixed, 8);
    }

    private function normalizeNonNegativeMoney(mixed $value): string
    {
        $value = is_scalar($value) ? trim((string)$value) : '0';
        if ($value === '' || !is_numeric($value) || bccomp($value, '0', 8) < 0) {
            return '0';
        }
        return $value;
    }

    /** @param array<string, mixed> $result */
    private function notifyWithdrawalApproved(array $result): void
    {
        try {
            if (!empty($result['user_id']) && isset($result['final_amount'], $result['currency'])) {
                $this->notificationService->withdrawalApproved(
                    int_value($result['user_id']),
                    str_value($result['final_amount']),
                    str_value($result['currency'])
                );
            }
        } catch (\Throwable $e) {
            $this->logger->error('withdrawal.approve.notification_failed', [
                'withdrawal_id' => $result['withdrawal_id'],
                'user_id' => $result['user_id'],
                'error' => $e->getMessage(),
            ]);
            \App\Services\Sentry\SentryExceptionHandler::captureException($e, int_value($result['user_id']), [
                'operation' => 'withdrawal.approve.notification',
                'withdrawal_id' => $result['withdrawal_id'],
            ]);
        }
    }

    /** @param array<string, mixed> $result */
    private function notifyWithdrawalRejected(array $result): void
    {
        try {
            if (!empty($result['user_id']) && isset($result['amount'])) {
                $this->notificationService->withdrawalRejected(
                    int_value($result['user_id']),
                    str_value($result['amount']),
                    str_value($result['reason'] ?? 'درخواست برداشت رد شد'));
            }
        } catch (\Throwable $e) {
            $this->logger->error('withdrawal.reject.notification_failed', [
                'withdrawal_id' => $result['withdrawal_id'],
                'user_id' => $result['user_id'],
                'error' => $e->getMessage(),
            ]);
            \App\Services\Sentry\SentryExceptionHandler::captureException($e, int_value($result['user_id']), [
                'operation' => 'withdrawal.reject.notification',
                'withdrawal_id' => $result['withdrawal_id'],
            ]);
        }
    }
}
