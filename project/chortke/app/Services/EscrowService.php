<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Escrow;
use Core\Database;
use App\Contracts\LoggerInterface;
use App\Services\StateMachineService;
use App\Events\EscrowReleasedEvent;
use App\Events\DisputeOpenedEvent;

/**
 * EscrowService - تسویه‌ مرکزی برای تمام ماژول‌های مالی
 * 
 * وضعیت‌های Escrow:
 * - pending:    انتقال از seller/advertiser منتظر
 * - in_escrow:  funds held
 * - released:   transferred to seller/advertiser
 * - refunded:   returned to buyer
 * - disputed:   waiting for resolution
 */
/**
 * @phpstan-type EscrowResult array{ok: bool, error?: string, escrow_id?: int, ...}
 * @phpstan-type EscrowRow object{
 *     id: int|string,
 *     order_id: int|string,
 *     order_type: string,
 *     buyer_id: int|string,
 *     seller_id: int|string,
 *     amount: int|float|string,
 *     currency: string,
 *     status: string
 * }
 */
class EscrowService
{
    // Uses transactional() + IdempotencyService for safe operations
    private Escrow   $escrowModel;
    private StateMachineService $stateMachine;

    private \Core\Database $db;
    private \App\Contracts\LoggerInterface $logger;
    private \App\Services\Shared\IdempotencyService $idempotencyService;
    private ?\App\Services\DistributedLockService $lockService;
    private ?\App\Contracts\OutboxServiceInterface $outbox;
    private ?\App\Contracts\WalletServiceInterface $walletService;
    public function __construct(
        \Core\Database $db,
        \App\Contracts\LoggerInterface $logger,
        Escrow $escrowModel,
        \App\Services\Shared\IdempotencyService $idempotencyService,
        ?StateMachineService $stateMachine = null,
        ?\App\Services\DistributedLockService $lockService = null,
        ?\App\Contracts\OutboxServiceInterface $outbox = null,
        ?\App\Contracts\WalletServiceInterface $walletService = null
    ) {
        $this->db = $db;
        $this->logger = $logger;
        $this->idempotencyService = $idempotencyService;
        $this->outbox = $outbox;
        $this->walletService = $walletService;
        $this->escrowModel = $escrowModel;
        // 🛡️ SECURITY & STABILITY FIX: تصحیح ترتیب تزریق وابستگی‌ها به StateMachineService ($db, $logger)
        $this->stateMachine = $stateMachine ?? new StateMachineService($db, $logger);
        // eventDispatcher already assigned from constructor parameter
        $this->lockService = $lockService;
    }

    /** @return EscrowResult */
    private function normalizeEscrowResult(mixed $result): array
    {
        if (!is_array($result)) {
            return ['ok' => (bool)$result];
        }
        foreach (array_keys($result) as $key) {
            if (!is_string($key)) {
                throw new \UnexpectedValueException('Escrow operation result must be an associative array');
            }
        }
        if (!array_key_exists('ok', $result) || !is_bool($result['ok'])) {
            throw new \UnexpectedValueException('Escrow operation result must contain boolean ok');
        }
        if (array_key_exists('error', $result) && !is_string($result['error'])) {
            throw new \UnexpectedValueException('Escrow operation error must be a string');
        }
        if (array_key_exists('escrow_id', $result) && !is_int($result['escrow_id'])) {
            throw new \UnexpectedValueException('Escrow operation escrow_id must be an integer');
        }

        /** @var EscrowResult $result */
        return $result;
    }

    /**
     * @param object $escrow
     * @return EscrowRow
     */
    private function requireEscrowRow(object $escrow): object
    {
        $values = get_object_vars($escrow);
        foreach (['id', 'order_id', 'order_type', 'buyer_id', 'seller_id', 'amount', 'currency', 'status'] as $field) {
            if (!array_key_exists($field, $values) || !is_scalar($values[$field])) {
                throw new \UnexpectedValueException("Invalid escrow row: {$field}");
            }
        }
        /** @var EscrowRow $escrow */
        return $escrow;
    }

    /**
     * درخواست نگهداری funds (Seller → Escrow)
     * ✅ Transaction-based state machine
     */
    /** @return EscrowResult */
    public function holdFunds(
        int|string $orderId,
        string $orderType,
        int    $buyerId,
        int    $sellerId,
        string $amount,
        string $currency = 'USDT',
        ?string $idempotencyKey = null
    ): array {
        return $this->normalizeEscrowResult($this->idempotencyService->execute(
            'escrow.holdFunds',
            $buyerId,
            [
                'order_id'   => $orderId,
                'order_type' => $orderType,
                'buyer_id'   => $buyerId,
                'seller_id'  => $sellerId,
                'amount'     => $amount,
                'currency'   => $currency,
            ],
            function () use (
                $orderId,
                $orderType,
                $buyerId,
                $sellerId,
                $amount,
                $currency
            ) {
                $execute = function() use ($orderId, $orderType, $buyerId, $sellerId, $amount, $currency) {
                    // ✅ Check if escrow already exists
                    $existing = $this->escrowModel->findByOrderId($orderId, $orderType, 'refunded');

                    if ($existing) {
                        return ['ok' => false, 'error' => 'Escrow already exists for this order'];
                    }

                    // ✅ Validate amount
                    if (bccomp($amount, '0', 8) <= 0) {
                        return ['ok' => false, 'error' => 'Invalid amount'];
                    }

                    $escrowId = $this->escrowModel->createEscrow(
                        $orderId,
                        $orderType,
                        $buyerId,
                        $sellerId,
                        $amount,
                        $currency
                    );

                    if (!$escrowId) {
                        throw new \Core\Exceptions\ApplicationException('خطا در ایجاد صندوق امانات. لطفاً دوباره تلاش کنید.');
                    }

                    $this->logger->info('escrow.hold_requested', [
                        'order_id' => $orderId,
                        'order_type' => $orderType,
                        'amount' => $amount,
                        'buyer_id' => $buyerId,
                        'seller_id' => $sellerId,
                    ]);

                    $this->outbox?->record('escrow', (int)$escrowId, 'escrow.state_changed', [
                        'escrow_id' => (int)$escrowId,
                        'order_id' => $orderId,
                        'order_type' => $orderType,
                        'old_status' => null,
                        'new_status' => 'pending',
                        'amount' => $amount,
                        'currency' => $currency
                    ]);

                    return ['ok' => true, 'escrow_id' => (int)$escrowId];
                };

                if ($this->lockService !== null) {
                    try {
                        return $this->lockService->synchronized("escrow_hold_{$orderType}_{$orderId}", $execute, null, 1);
                    } catch (\Throwable $lockError) {
                        // Idempotency + DB uniqueness already protect this flow. If the optional distributed
                        // lock backend is unavailable/stale in local/file mode, continue with DB-safe execution.
                        $this->logger->warning('escrow.hold.lock_unavailable_fallback', [
                            'order_id' => $orderId,
                            'order_type' => $orderType,
                            'error' => $lockError->getMessage(),
                        ]);
                        \App\Services\Sentry\SentryExceptionHandler::captureException($lockError, null, [
                            'operation' => 'escrow.hold.lock_unavailable_fallback',
                            'order_id' => $orderId,
                            'order_type' => $orderType,
                        ]);
                    }
                }
                
                return $execute();
            },
            $idempotencyKey
        ));
    }

    /**
     * تایید و نگهداری funds (pending → in_escrow)
     * ✅ With database locking
     */
    /** @return EscrowResult */
    public function confirmHold(int|string $orderId, string $orderType, int $sellerId, ?string $idempotencyKey = null): array
    {
        return $this->normalizeEscrowResult($this->idempotencyService->execute(
            'escrow.confirmHold',
            $sellerId,
            [
                'order_id' => $orderId,
                'order_type' => $orderType,
                'seller_id' => $sellerId,
            ],
            function () use ($orderId, $orderType, $sellerId) {
                // ✅ Acquire write lock
                $escrow = $this->escrowModel->findPendingForConfirm($orderId, $orderType, $sellerId);

                if (!$escrow) {
                    return ['ok' => false, 'error' => 'Escrow not found or already confirmed'];
                }
                $escrow = $this->requireEscrowRow($escrow);

                // Validate state transition
                if (!$this->stateMachine->canTransition('escrow', $escrow->status, 'in_escrow')) {
                    return ['ok' => false, 'error' => "Invalid transition from {$escrow->status} to in_escrow"];
                }

                // ✅ Update status
                $result = $this->escrowModel->confirmHold((int)$escrow->id);

                if (!$result) {
                    throw new \Core\Exceptions\ApplicationException('خطا در تأیید صندوق امانات. لطفاً دوباره تلاش کنید.');
                }

                $this->logger->info('escrow.confirmed', [
                    'escrow_id' => $escrow->id,
                    'order_id' => $orderId,
                    'amount' => $escrow->amount,
                ]);

                $this->outbox?->record('escrow', (int)$escrow->id, 'escrow.state_changed', [
                    'escrow_id' => (int)$escrow->id,
                    'order_id' => (int)$escrow->order_id,
                    'order_type' => $escrow->order_type,
                    'old_status' => $escrow->status,
                    'new_status' => 'in_escrow',
                    'amount' => $escrow->amount,
                    'currency' => $escrow->currency
                ]);

                return ['ok' => true, 'escrow_id' => (int)$escrow->id];
            },
            $idempotencyKey
        ));
    }

    /**
     * INTERNAL STATE TRANSITION ONLY — settlement must be orchestrated by
     * FinancialEscrowService. Direct controller/job callers are forbidden by
     * FinancialIntegrityTest architecture guard.
     *
     * @internal
     */
    /** @return EscrowResult */
    public function releaseFunds(int $escrowId, int $sellerId, string $releasedBy, ?string $idempotencyKey = null): array
    {
        return $this->normalizeEscrowResult($this->idempotencyService->execute(
            'escrow.releaseFunds',
            $sellerId,
            [
                'escrow_id' => $escrowId,
                'seller_id' => $sellerId,
                'released_by' => $releasedBy,
            ],
            function () use ($escrowId, $sellerId, $releasedBy) {
                return $this->db->transactional(function () use ($escrowId, $sellerId, $releasedBy) {
                // ✅ Acquire lock & validate state
                $escrow = $this->escrowModel->findReleasable($escrowId, $sellerId);

                if (!$escrow) {
                    return ['ok' => false, 'error' => 'Escrow not found or cannot be released'];
                }
                $escrow = $this->requireEscrowRow($escrow);

                // Validate state transition
                if (!$this->stateMachine->canTransition('escrow', $escrow->status, 'released')) {
                    return ['ok' => false, 'error' => "Invalid transition from {$escrow->status} to released"];
                }

                // ✅ Update escrow status
                $result = $this->escrowModel->releaseFunds($escrowId, $releasedBy);

                if (!$result) {
                    throw new \Core\Exceptions\ApplicationException('خطا در آزادسازی وجه امانی. لطفاً دوباره تلاش کنید.');
                }

                // ✅ Log audit trail
                $this->escrowModel->logEscrowAction($escrowId, 'released', (string)$escrow->amount, $releasedBy);

                // Financial ledger legs are written by FinancialEscrowService
                // through WalletMutationService. This state-only method must
                // not create a second synthetic escrow->wallet posting.

                $this->logger->info('escrow.released', [
                    'escrow_id' => $escrowId,
                    'order_id' => $escrow->order_id,
                    'amount' => $escrow->amount,
                    'seller_id' => $sellerId,
                ]);

                // Domain Event: escrow released → outbox
                $this->outbox?->record('escrow', $escrowId, EscrowReleasedEvent::class, [
                    'escrow_id' => $escrowId,
                    'seller_id' => $sellerId,
                    'amount'    => (string)$escrow->amount,
                    'currency'  => $escrow->currency,
                ]);

                return ['ok' => true, 'amount' => $escrow->amount];
                });
            },
            $idempotencyKey
        ));
    }

    /**
     * partialRelease - Release partial amount from escrow to seller, keeping the rest.
     */
    /**
     * Settle a portion of an escrow to its seller.
     *
     * Financial contract (all inside one DB transaction):
     *   buyer.locked -= amount
     *   buyer.balance is unchanged
     *   seller.balance += amount
     *   escrow.amount becomes the remaining held amount
     *
     * releaseLockedFunds is deliberately NOT used here: that primitive is a
     * refund (locked -> buyer balance) and would create money when combined
     * with a seller payout.
     */
    /** @return EscrowResult */
    public function partialRelease(
        int $escrowId,
        int $sellerId,
        string $releaseAmount,
        string $reason,
        ?string $idempotencyKey = null
    ): array {
        $canonicalKey = $idempotencyKey ?: hash('sha256', implode('|', [
            'escrow.partial-release', $escrowId, $sellerId, $releaseAmount, $reason,
        ]));

        return $this->normalizeEscrowResult($this->idempotencyService->execute(
            'escrow.partialRelease',
            $sellerId,
            [
                'escrow_id' => $escrowId,
                'seller_id' => $sellerId,
                'amount' => $releaseAmount,
                'reason' => $reason,
            ],
            function () use ($escrowId, $sellerId, $releaseAmount, $reason, $canonicalKey) {
                return $this->db->transactional(function () use ($escrowId, $sellerId, $releaseAmount, $reason, $canonicalKey) {
                    if (bccomp($releaseAmount, '0', 8) <= 0) {
                        return ['ok' => false, 'error' => 'Release amount must be positive'];
                    }
                    if ($this->walletService === null) {
                        // Never fall back to direct SQL: the wallet service is the
                        // only place that provides lock, idempotency and ledger
                        // guarantees for a monetary settlement.
                        throw new \RuntimeException('WalletService is required for escrow settlement');
                    }

                    $escrow = $this->escrowModel->findReleasable($escrowId, $sellerId);
                    if (!$escrow) {
                        return ['ok' => false, 'error' => 'Escrow not found or not releasable'];
                    }
                    $escrow = $this->requireEscrowRow($escrow);
                    if (bccomp($releaseAmount, (string)$escrow->amount, 8) > 0) {
                        return ['ok' => false, 'error' => 'Release amount exceeds escrow amount'];
                    }

                    $currency = strtolower((string)$escrow->currency) === 'usdt' ? 'usdt' : 'irt';
                    $remaining = bcsub((string)$escrow->amount, $releaseAmount, 8);
                    if (bccomp($remaining, '0', 8) < 0) {
                        throw new \LogicException('Escrow remaining amount cannot be negative');
                    }

                    $spendKey = hash('sha256', 'escrow-spend|' . $escrowId . '|' . $canonicalKey);
                    $payoutKey = hash('sha256', 'escrow-payout|' . $escrowId . '|' . $canonicalKey);

                    $spend = $this->walletService->spendLockedFunds(
                        (int)$escrow->buyer_id,
                        $releaseAmount,
                        $currency,
                        [
                            'type' => 'escrow_partial_spend',
                            'ref_id' => $escrowId,
                            'ref_type' => 'escrow',
                            'description' => 'مصرف وجه قفل‌شده برای تسویهٔ امانی',
                            'ledger_credit_account' => 'escrow_payout',
                            'idempotency_key' => $spendKey,
                        ]
                    );
                    if (empty($spend['success'])) {
                        throw new \Core\Exceptions\ApplicationException((is_string($spend['message'] ?? null) ? $spend['message'] : 'کسر موجودی قفل‌شده انجام نشد'));
                    }

                    if (!$this->walletService->getOrCreateWallet($sellerId)) {
                        throw new \Core\Exceptions\ApplicationException('کیف پول فروشنده ایجاد نشد');
                    }
                    $payout = $this->walletService->deposit(
                        $sellerId,
                        $releaseAmount,
                        $currency,
                        [
                            'type' => 'escrow_partial_payout',
                            'ref_id' => $escrowId,
                            'ref_type' => 'escrow',
                            'description' => 'دریافت وجه امانی',
                            'ledger_debit_account' => 'escrow_payout',
                            'idempotency_key' => $payoutKey,
                        ]
                    );
                    if (empty($payout['success'])) {
                        throw new \Core\Exceptions\ApplicationException((is_string($payout['message'] ?? null) ? $payout['message'] : 'واریز وجه امانی به فروشنده انجام نشد'));
                    }

                    $newStatus = bccomp($remaining, '0', 8) === 0 ? 'released' : 'partial';
                    $stmt = $this->db->prepare(
                        "UPDATE escrow_transactions
                         SET status = ?,
                             amount = ?,
                             partial_released = COALESCE(partial_released, 0) + ?,
                             released_at = CASE WHEN ? = 'released' THEN NOW() ELSE released_at END,
                             released_by = CASE WHEN ? = 'released' THEN ? ELSE released_by END,
                             updated_at = NOW()
                         WHERE id = ? AND status IN ('pending', 'in_escrow', 'partial')"
                    );
                    $stmt->execute([$newStatus, $remaining, $releaseAmount, $newStatus, $newStatus, 'escrow_settlement', $escrowId]);
                    if ($stmt->rowCount() !== 1) {
                        throw new \Core\Exceptions\ApplicationException('وضعیت صندوق امانات در زمان تسویه تغییر کرد');
                    }

                    $this->escrowModel->logEscrowAction($escrowId, 'partial_release', $releaseAmount, 'system', $reason);
                    $this->outbox?->record('escrow', $escrowId, 'escrow.state_changed', [
                        'escrow_id' => $escrowId,
                        'order_id' => (int)$escrow->order_id,
                        'order_type' => $escrow->order_type,
                        'old_status' => $escrow->status,
                        'new_status' => $newStatus,
                        'amount' => $remaining,
                        'currency' => $currency,
                        'released_amount' => $releaseAmount,
                        'seller_id' => $sellerId,
                        'reason' => $reason,
                    ]);

                    return [
                        'ok' => true,
                        'released' => $releaseAmount,
                        'remaining' => $remaining,
                        'status' => $newStatus,
                        'spend_transaction_id' => $spend['transaction_id'] ?? null,
                        'payout_transaction_id' => $payout['transaction_id'] ?? null,
                    ];
                });
            },
            $canonicalKey
        ));
    }

    /**
     * INTERNAL STATE TRANSITION ONLY — never refunds a wallet by itself.
     * FinancialEscrowService::refundEscrowToBuyer is the public financial API.
     *
     * @internal
     */
    /** @return EscrowResult */
    public function refundFunds(
        int    $escrowId,
        int    $buyerId,
        string $reason,
        string $initiatedBy,
        ?string $idempotencyKey = null
    ): array {
        return $this->normalizeEscrowResult($this->idempotencyService->execute(
            'escrow.refundFunds',
            $buyerId,
            [
                'escrow_id' => $escrowId,
                'buyer_id' => $buyerId,
                'reason' => $reason,
                'initiated_by' => $initiatedBy,
            ],
            function () use ($escrowId, $buyerId, $reason, $initiatedBy) {
                return $this->db->transactional(function () use ($escrowId, $buyerId, $reason, $initiatedBy) {
                    $escrow = $this->escrowModel->findRefundable($escrowId, $buyerId);
                    if (!$escrow) {
                        return ['ok' => false, 'error' => 'Escrow not found or cannot be refunded'];
                    }
                    $escrow = $this->requireEscrowRow($escrow);

                    if (!$this->stateMachine->canTransition('escrow', $escrow->status, 'refunded')) {
                        return ['ok' => false, 'error' => "Invalid transition from {$escrow->status} to refunded"];
                    }

                    $result = $this->escrowModel->refundFunds($escrowId, $reason, $initiatedBy);
                    if (!$result) {
                        throw new \Core\Exceptions\ApplicationException('خطا در بازگشت وجه امانی. لطفاً دوباره تلاش کنید.');
                    }

                    $this->escrowModel->logEscrowAction($escrowId, 'refunded', (string)$escrow->amount, $initiatedBy, $reason);

                    // WalletMutationService records the actual locked->wallet
                    // refund ledger leg. Do not duplicate it in this state layer.

                    $this->logger->info('escrow.refunded', [
                        'escrow_id' => $escrowId,
                        'order_id' => $escrow->order_id,
                        'amount' => $escrow->amount,
                        'reason' => $reason,
                    ]);

                    $this->outbox?->record('escrow', (int)($escrow->id ?? $escrowId), 'escrow.state_changed', [
                        'escrow_id' => $escrowId,
                        'order_id' => (int)$escrow->order_id,
                        'order_type' => $escrow->order_type,
                        'old_status' => $escrow->status,
                        'new_status' => 'refunded',
                        'amount' => $escrow->amount,
                        'currency' => $escrow->currency,
                        'initiated_by' => $initiatedBy,
                        'reason' => $reason
                    ]);

                    return ['ok' => true, 'amount' => $escrow->amount, 'refund_id' => $escrowId];
                });
            },
            $idempotencyKey
        ));
    }

    /**
     * وضعیت را به disputed تغییر بده (در صورت اختلاف)
     * ✅ Prevents release/refund during dispute
     */
    /** @return EscrowResult */
    public function markAsDisputed(int $escrowId, string $reason, ?string $idempotencyKey = null): array
    {
        $escrowOwner = $this->getStatus($escrowId);
        $idempotencyUserId = $escrowOwner === null
            ? 1
            : (int)$this->requireEscrowRow($escrowOwner)->buyer_id;

        return $this->normalizeEscrowResult($this->idempotencyService->execute(
            'escrow.markAsDisputed',
            $idempotencyUserId,
            [
                'escrow_id' => $escrowId,
                'reason' => $reason,
            ],
            function () use ($escrowId, $reason) {
                $escrow = $this->getStatus($escrowId);
                if (!$escrow) {
                    return ['ok' => false, 'error' => 'Escrow not found'];
                }
                $escrow = $this->requireEscrowRow($escrow);

                // Validate state transition
                if (!$this->stateMachine->canTransition('escrow', $escrow->status, 'disputed')) {
                    return ['ok' => false, 'error' => "Invalid transition from {$escrow->status} to disputed"];
                }

                $result = $this->escrowModel->markDisputed($escrowId, $reason);

                if (!$result) {
                    return ['ok' => false, 'error' => 'Failed to mark as disputed'];
                }

                $this->outbox?->record('escrow', (int)$escrowId, 'escrow.state_changed', [
                    'escrow_id' => $escrowId,
                    'order_id' => (int)$escrow->order_id,
                    'order_type' => $escrow->order_type,
                    'old_status' => $escrow->status,
                    'new_status' => 'disputed',
                    'amount' => $escrow->amount,
                    'currency' => $escrow->currency,
                    'reason' => $reason
                ]);

                $this->outbox?->record('dispute', (int)$escrowId, 'dispute.created', [
                    'escrow_id' => $escrowId,
                    'order_id' => (int)$escrow->order_id,
                    'order_type' => $escrow->order_type,
                    'buyer_id' => (int)$escrow->buyer_id,
                    'seller_id' => (int)$escrow->seller_id,
                    'amount' => $escrow->amount,
                    'currency' => $escrow->currency,
                    'reason' => $reason,
                    'created_at' => date('Y-m-d H:i:s')
                ]);

                // Domain Event: dispute opened → outbox
                $this->outbox?->record('dispute', $escrowId, DisputeOpenedEvent::class, [
                    'buyer_id'   => (int)$escrow->buyer_id,
                    'escrow_id'  => $escrowId,
                    'order_id'   => (int)$escrow->order_id,
                    'order_type' => $escrow->order_type,
                    'reason'     => $reason,
                ]);

                $this->logger->info('escrow.disputed', ['escrow_id' => $escrowId, 'reason' => $reason]);
                return ['ok' => true];
            },
            $idempotencyKey
        ));
    }

    /**
     * حل اختلاف و تقسیم وجه امانی به صورت جزئی یا کلی (BUG-05)
     */
    /**
     * Internal financial primitive used by FinancialEscrowService flows.
     * Direct controller/job use is prohibited by the architecture guard.
     */
    /** @return EscrowResult */
    public function resolveDisputePartial(
        int $escrowId, int $buyerId, int $sellerId, string $refundAmount,
        string $releaseAmount, string $initiatedBy, string $verdict, ?string $idempotencyKey = null
    ): array {
        $key=$idempotencyKey?:hash('sha256',implode('|',['escrow.dispute-split',$escrowId,$buyerId,$sellerId,$refundAmount,$releaseAmount,$verdict]));
        return $this->normalizeEscrowResult($this->idempotencyService->execute('escrow.resolveDisputePartial',$buyerId,['escrow_id'=>$escrowId,'buyer_id'=>$buyerId,'seller_id'=>$sellerId,'refund_amount'=>$refundAmount,'release_amount'=>$releaseAmount,'verdict'=>$verdict],function()use($escrowId,$buyerId,$sellerId,$refundAmount,$releaseAmount,$initiatedBy,$verdict,$key){
            return $this->db->transactional(function()use($escrowId,$buyerId,$sellerId,$refundAmount,$releaseAmount,$initiatedBy,$verdict,$key){
                if($this->walletService===null) throw new \RuntimeException('WalletService is required for dispute settlement');
                if(bccomp($refundAmount,'0',8)<0||bccomp($releaseAmount,'0',8)<0) return ['ok'=>false,'error'=>'Dispute amounts must not be negative'];
                $escrow=$this->escrowModel->findRefundable($escrowId,$buyerId);
                if(!$escrow) return ['ok'=>false,'error'=>'Escrow not found or not resolvable'];
                $escrow = $this->requireEscrowRow($escrow);
                if(!in_array($escrow->status,['disputed','in_escrow','pending'],true)) return ['ok'=>false,'error'=>'Escrow not found or not resolvable'];
                if((int)$escrow->seller_id!==$sellerId) return ['ok'=>false,'error'=>'Seller does not match escrow'];
                $total=bcadd($refundAmount,$releaseAmount,8);
                if(bccomp($total,(string)$escrow->amount,8)!==0) return ['ok'=>false,'error'=>'Refund and payout must equal the escrow amount'];
                $currency=strtolower((string)$escrow->currency)==='usdt'?'usdt':'irt';
                if(bccomp($refundAmount,'0',8)>0){$refund=$this->walletService->releaseLockedFunds($buyerId,$refundAmount,$currency,['type'=>'escrow_dispute_refund','ref_id'=>$escrowId,'ref_type'=>'escrow','description'=>'بازگشت وجه حل اختلاف','idempotency_key'=>hash('sha256','dispute-refund|'.$escrowId.'|'.$key)]);if(empty($refund['success']))throw new \Core\Exceptions\ApplicationException((is_string($refund['message'] ?? null) ? $refund['message'] : 'بازگشت وجه اختلاف ناموفق بود'));}
                if(bccomp($releaseAmount,'0',8)>0){$spend=$this->walletService->spendLockedFunds($buyerId,$releaseAmount,$currency,['type'=>'escrow_dispute_payout_spend','ref_id'=>$escrowId,'ref_type'=>'escrow','description'=>'پرداخت حل اختلاف','ledger_credit_account'=>'escrow_payout','idempotency_key'=>hash('sha256','dispute-spend|'.$escrowId.'|'.$key)]);if(empty($spend['success']))throw new \Core\Exceptions\ApplicationException((is_string($spend['message'] ?? null) ? $spend['message'] : 'مصرف وجه اختلاف ناموفق بود'));$pay=$this->walletService->deposit($sellerId,$releaseAmount,$currency,['type'=>'escrow_dispute_payout','ref_id'=>$escrowId,'ref_type'=>'escrow','description'=>'دریافت رأی اختلاف','ledger_debit_account'=>'escrow_payout','idempotency_key'=>hash('sha256','dispute-payout|'.$escrowId.'|'.$key)]);if(empty($pay['success']))throw new \Core\Exceptions\ApplicationException((is_string($pay['message'] ?? null) ? $pay['message'] : 'پرداخت فروشنده ناموفق بود'));}
                $status=bccomp($releaseAmount,'0',8)>0?'released':'refunded';
                $stmt=$this->db->prepare("UPDATE escrow_transactions SET status=?,amount=0,partial_released=COALESCE(partial_released,0)+?,released_at=CASE WHEN ?='released' THEN NOW() ELSE released_at END,released_by=CASE WHEN ?='released' THEN ? ELSE released_by END,refunded_at=CASE WHEN ?='refunded' THEN NOW() ELSE refunded_at END,refunded_by=CASE WHEN ?='refunded' THEN ? ELSE refunded_by END,refund_reason=CASE WHEN ?='refunded' THEN 'dispute_resolution' ELSE refund_reason END,updated_at=NOW() WHERE id=?");
                $stmt->execute([$status,$releaseAmount,$status,$status,$initiatedBy,$status,$status,$initiatedBy,$status,$escrowId]);if($stmt->rowCount()!==1)throw new \Core\Exceptions\ApplicationException('وضعیت اختلاف همزمان تغییر کرد');
                $this->escrowModel->logEscrowAction($escrowId,'dispute_settled',$total,$initiatedBy,"Dispute {$verdict}");
                $this->outbox?->record('escrow',$escrowId,'escrow.state_changed',['escrow_id'=>$escrowId,'old_status'=>$escrow->status,'new_status'=>$status,'refund_amount'=>$refundAmount,'release_amount'=>$releaseAmount,'currency'=>$currency,'seller_id'=>$sellerId]);
                return ['ok'=>true,'status'=>$status,'refund_amount'=>$refundAmount,'release_amount'=>$releaseAmount];
            });
        },$key));
    }

    /**
     * دریافت وضعیت escrow
     */
    public function getStatus(int $escrowId): ?\stdClass
    {
        return $this->escrowModel->getStatus($escrowId);
    }

    /**
     * دریافت escrow برای order
     */
    public function getByOrder(int|string $orderId, string $orderType): ?\stdClass
    {
        return $this->escrowModel->getByOrder((string)$orderId, $orderType);
    }

    /**
     * بررسی اینکه آیا escrow منقضی‌ شده (مثل قبل از تحویل)
     */
    public function isExpired(int $escrowId): bool
    {
        return $this->escrowModel->isExpired($escrowId);
    }


}
