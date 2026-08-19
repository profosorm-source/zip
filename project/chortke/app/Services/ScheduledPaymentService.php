<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\WalletServiceInterface;
use Core\Database;
use App\Models\ScheduledPayment;
use App\Models\Transaction;
use App\Models\Wallet;
use App\Contracts\LoggerInterface;

class ScheduledPaymentService
{



    private \App\Services\Shared\IdempotencyService $idempotencyService;

    private \Core\TransactionWrapper $transactionWrapper;
    private \App\Contracts\LoggerInterface $logger;
    private ScheduledPayment $scheduledPaymentModel;
    private WalletServiceInterface $walletService;
    private ReconciliationService $reconciliationService;
    private ?\App\Contracts\ValidatorFactoryInterface $validatorFactory;
    public function __construct(
        \Core\TransactionWrapper $transactionWrapper,
        \App\Contracts\LoggerInterface $logger,
        ScheduledPayment $scheduledPaymentModel,
        WalletServiceInterface $walletService,
        ReconciliationService $reconciliationService,
        \App\Contracts\ValidatorFactoryInterface $validatorFactory,
        \App\Services\Shared\IdempotencyService $idempotencyService
    ) {        $this->transactionWrapper = $transactionWrapper;
        $this->logger = $logger;
        $this->scheduledPaymentModel = $scheduledPaymentModel;
        $this->walletService = $walletService;
        $this->reconciliationService = $reconciliationService;

        
        $this->validatorFactory = $validatorFactory;
        $this->idempotencyService = $idempotencyService;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function createSchedule(array $data): ?object
    {
        if ($this->validatorFactory === null) {
            return null;
        }
        $validator = $this->validatorFactory->make($data, [
            'user_id' => 'required|integer|min:1',
            'amount' => 'required|numeric|min:1',
            'next_run_at' => 'required|date'
        ]);

        if ($validator->fails()) {
            return null;
        }

        $userId = int_value($data['user_id'] ?? 0);
        
        // Ensure idempotency for creating schedules
        $explicitKeyRaw = $data['idempotency_key'] ?? null;
        $explicitKey = is_string($explicitKeyRaw) ? $explicitKeyRaw : null;

        return $this->idempotencyService->executeWithTransaction(
            'scheduled_payment.create',
            $userId,
            $data,
            function() use ($data) {
                return $this->scheduledPaymentModel->createSchedule($data);
            },
            $explicitKey
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function processDuePayments(int $limit = 50): array
    {
        $processed = 0;
        $failed = 0;
        $details = [];

        $result = $this->getTransactionWrapper()->runWithRetry(function($db) use ($limit, &$processed, &$failed, &$details) {
            $due = $this->scheduledPaymentModel->getDuePayments($limit);

            if (empty($due)) {
                return;
            }

            // ✅ N+1 FIX: یک query برای پیدا کردن wallet‌های frozen قبل از loop
            $dueUserIds   = array_unique(array_map(fn($p) => (int)$p->user_id, $due));
            $placeholders = implode(',', array_fill(0, count($dueUserIds), '?'));
            $frozenRows   = $db->fetchAll(
                "SELECT user_id FROM wallets WHERE user_id IN ({$placeholders}) AND is_frozen = 1",
                $dueUserIds
            ) ?: [];
            $frozenSet = array_flip(array_column($frozenRows, 'user_id'));
            // ──────────────────────────────────────────────────────────

            foreach ($due as $payment) {
                try {
                    $this->getTransactionWrapper()->runWithRetry(function($db) use ($payment, $frozenSet, &$processed, &$failed, &$details) {
                        // بررسی از map — بدون query اضافه
                        if (isset($frozenSet[(int)$payment->user_id])) {
                            $this->scheduledPaymentModel->updateStatus((int)$payment->id, 'paused');
                            $details[] = ['id' => $payment->id, 'status' => 'paused', 'reason' => 'wallet_frozen'];
                            $failed++;
                            return;
                        }

                        // 🔐 M-28 FIX: reject unknown scheduling frequencies BEFORE charging so an
                        // invalid value can never charge the user and then loop forever on an
                        // unadvanced next_run_at. Fail-fast and mark the payment failed.
                        $freq = strtolower((string)$payment->frequency);
                        if (!in_array($freq, ['daily', 'weekly', 'monthly', 'one_time', 'once'], true)) {
                            $this->scheduledPaymentModel->updateStatus((int)$payment->id, 'failed');
                            $details[] = ['id' => $payment->id, 'status' => 'failed', 'reason' => 'invalid_frequency'];
                            $failed++;
                            return;
                        }

                        // 🔒 Lock wallet row داخل transaction — این ضروری است (TOCTOU prevention)
                        $db->query("SELECT id FROM wallets WHERE user_id = ? FOR UPDATE", [(int)$payment->user_id])->fetch();

                        if (!$this->walletService->hasBalance((int)$payment->user_id, (string)$payment->amount, $payment->currency)) {
                            $this->scheduledPaymentModel->updateStatus((int)$payment->id, 'failed');

                            // Sending official Failure Notification (SP-03 🆕)
                            $db->query("INSERT INTO notifications (user_id, type, title, message, priority, created_at) VALUES (?, ?, ?, ?, 'high', NOW())", [
                                (int)$payment->user_id,
                                'scheduled_payment_failed',
                                'شکست در کسر پرداخت زمان‌بندی‌شده',
                                'موجودی کیف پول شما برای اجرای پرداخت زمان‌بندی‌شده کافی نبود.'
                            ]);

                            $details[] = ['id' => $payment->id, 'status' => 'failed', 'reason' => 'insufficient_funds'];
                            $failed++;
                            return;
                        }

                        assert_fraud_allowed((int)$payment->user_id, 'scheduled_payment', ['amount' => $payment->amount]);
                        $txId = $this->walletService->withdraw(
                            (int)$payment->user_id,
                            (string)$payment->amount,
                            $payment->currency,
                            [
                                'type' => 'scheduled_payment',
                                'description' => $payment->description ?? 'Scheduled payment charge',
                                'scheduled_payment_id' => $payment->id,
                                'idempotency_key' => hash('sha256', 'sched_payment|' . $payment->id . '|' . $payment->next_run_at)
                            ]
                        );

                        if (empty($txId) || !is_array($txId) || empty($txId['success']) || empty($txId['transaction_id'])) {
                            throw new \RuntimeException('Failed to execute atomic wallet withdrawal: ' . ($txId['message'] ?? 'Unknown error'));
                        }

                        $nextRun = $this->calculateNextRun((string)$payment->frequency, (string)$payment->next_run_at);
                        $frequency = strtolower((string)$payment->frequency);
                        $status = in_array($frequency, ['one_time', 'once'], true) ? 'completed' : 'active';
                        $this->scheduledPaymentModel->updateNextRun((int)$payment->id, $nextRun, $status);

                        // ✅ **تطبیق scheduled payment با wallet و ledger**
                        // تأیید: آیا scheduled payment واقعاً از wallet کاهش پیدا کرد؟
                        try {
                            $reconciliation = $this->reconciliationService->verifyConsistency(
                                (int)$payment->user_id,
                                (string)$payment->currency
                            );

                            if (!$reconciliation['valid']) {
                                $this->logger->warning('scheduled_payment.reconciliation_failed', [
                                    'payment_id' => $payment->id,
                                    'user_id' => $payment->user_id,
                                    'amount' => $payment->amount,
                                    'message' => $reconciliation['message'],
                                ]);
                            }
                        } catch (\Throwable $reconcileEx) {
                            $this->logger->error('scheduled_payment.reconciliation_exception', [
                                'payment_id' => $payment->id,
                                'error' => $reconcileEx->getMessage()
                            ]);
                        }

                        $processed++;
                        $details[] = ['id' => $payment->id, 'status' => $status];
                    });
                } catch (\Exception $e) {
                    $this->scheduledPaymentModel->updateStatus((int)$payment->id, 'failed');
                    $details[] = ['id' => $payment->id, 'status' => 'failed', 'reason' => $e->getMessage()];
                    $failed++;
                    $this->logger->error('scheduled_payment.process.failed', [
                        'payment_id' => $payment->id,
                        'user_id' => $payment->user_id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
            
            return ['processed' => $processed, 'failed' => $failed, 'details' => $details];
        });

        return is_array($result) ? $result : ['processed' => $processed, 'failed' => $failed, 'details' => $details];
    }

    private function calculateNextRun(string $frequency, string $currentRun): string
    {
        $current = new \DateTimeImmutable($currentRun);

        return match (strtolower((string)$frequency)) {
            'weekly' => $current->modify('+1 week')->format('Y-m-d H:i:s'),
            'monthly' => $current->modify('+1 month')->format('Y-m-d H:i:s'),
            'daily' => $current->modify('+1 day')->format('Y-m-d H:i:s'),
            'one_time', 'once' => $current->format('Y-m-d H:i:s'),
            // 🔐 M-28 FIX: an unknown frequency previously returned the SAME next_run_at
            // (+0 seconds) while status stayed 'active', so the payment was re-selected every
            // cycle forever. Fail-fast on an unrecognized frequency (defense-in-depth; the
            // caller now validates frequency before charging).
            default => throw new \InvalidArgumentException("frequency نامعتبر برای پرداخت زمان‌بندی‌شده: {$frequency}"),
        };
    }

    /**
     * دریافت نمونه تراکنش لفافه‌بندی شده
     */
    public function getTransactionWrapper(): \Core\TransactionWrapper
    {
        return $this->transactionWrapper;
    }
}

