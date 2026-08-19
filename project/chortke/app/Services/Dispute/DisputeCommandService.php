<?php

declare(strict_types=1);

namespace App\Services\Dispute;

use App\Models\Dispute;
use App\Contracts\WalletServiceInterface;
use App\Contracts\LoggerInterface;
use App\Services\ReconciliationService;
use App\Services\Shared\IdempotencyService;
use Core\Database;
use App\Domain\Financial\Services\FinancialEscrowService;
use Core\TransactionWrapper;

/**
 * سرویس Command اختلافات — عملیات نوشتن
 * Logic از DisputeService + ResolveDisputeByAgreementJob + EscalateDisputeToAdminJob + ProcessExpiredDisputesJob
 *
 * @phpstan-type CommandResult array<string, mixed>
 * @phpstan-type DisputeInput array<string, mixed>
 */
class DisputeCommandService
{
        private Database $db;
    private LoggerInterface $logger;
    private Dispute $disputeModel;
    private WalletServiceInterface $walletService;
    private ReconciliationService $reconciliationService;
    private \App\Models\Transaction $transactionModel;
    private ?\App\Services\OutboxService $outboxService;

    private IdempotencyService $idempotencyService;
    private ?\App\Services\Notification\NotificationTemplateService $templateService;
    private ?FinancialEscrowService $financialEscrowService;
    private ?\Core\TransactionWrapper $transactionWrapper = null;

    public function __construct(
        Database $db,
        LoggerInterface $logger,
        Dispute $disputeModel,
        WalletServiceInterface $walletService,
        ReconciliationService $reconciliationService,
        \App\Models\Transaction $transactionModel,
        IdempotencyService $idempotencyService,
        ?\App\Services\OutboxService $outboxService = null,
        ?\App\Services\Notification\NotificationTemplateService $templateService = null,
        ?\App\Domain\Financial\Services\FinancialEscrowService $financialEscrowService = null,
        ?\Core\TransactionWrapper $transactionWrapper = null
    ) {
        $this->db = $db;
        $this->logger = $logger;
        $this->disputeModel = $disputeModel;
        $this->walletService = $walletService;
        $this->reconciliationService = $reconciliationService;
        $this->transactionModel = $transactionModel;
        $this->idempotencyService = $idempotencyService;
        $this->outboxService = $outboxService;
        $this->templateService = $templateService;
        $this->financialEscrowService = $financialEscrowService;
        $this->transactionWrapper = $transactionWrapper;
    }
    /**
     * Centralized toObject (root-cause normalization for DB results).
     * Guarantees object (never array/mixed) before any ->prop access.
     */
    private function toObject(mixed $data): ?\stdClass
    {
        if ($data instanceof \stdClass) return $data;
        if (is_array($data)) return (object)$data;
        if (is_object($data)) return (object)get_object_vars($data);
        return null;
    }

    /**
     * باز کردن dispute برای custom_task_submission.
     * جایگزین Raw SQL در CustomTaskController — بدون فایل جدید.
     */
    /** برای استفاده در DisputeService Facade */
    public function getDisputeModel(): Dispute
    {
        return $this->disputeModel;
    }

    /** @return CommandResult */
    public function openCustomTaskDispute(
        int $submissionId,
        int $userId,
        int $targetUserId,
        string $reason
    ): array {
        // بررسی تکراری بودن
        $existing = $this->toObject($this->disputeModel->findByRef('custom_task_submission', $submissionId));
        if ($existing && in_array((string)$existing->status, ['open','open_peer','under_review','escalated'], true)) {
            return ['success' => false, 'dispute_id' => (int)$existing->id, 'message' => 'برای این ارسال، اختلاف باز وجود دارد.'];
        }

        $idemKey = "custom_task_dispute_{$userId}_{$submissionId}";

        $result = $this->idempotencyService->executeWithTransaction(
            'dispute.open_custom_task',
            $userId,
            ['submission_id' => $submissionId, 'user_id' => $userId],
            function () use ($submissionId, $userId, $targetUserId, $reason) {
                $dispute = $this->toObject($this->disputeModel->createDispute([
                    'ref_type'       => 'custom_task_submission',
                    'ref_id'         => $submissionId,
                    'user_id'        => $userId,
                    'target_user_id' => $targetUserId,
                    'status'         => 'open',
                    'reason'         => $reason,
                    'role'           => 'worker',
                    'peer_deadline'  => date('Y-m-d H:i:s', (strtotime('+48 hours') ?: time())),
                ]));

                if ($dispute === null) {
                    throw new \RuntimeException('خطا در باز کردن پرونده اختلاف.');
                }

                // پیام اولیه
                $this->disputeModel->addMessage((int)$dispute->id, $userId, $reason, null, 'worker');

                // بروزرسانی وضعیت submission
                $this->db->query(
                    "UPDATE custom_task_submissions SET status = 'disputed', dispute_id = ?, updated_at = NOW() WHERE id = ?",
                    [(int)$dispute->id, $submissionId]
                );

                $this->logger->info('dispute.custom_task_opened', [
                    'dispute_id'    => $dispute->id,
                    'submission_id' => $submissionId,
                    'user_id'       => $userId,
                ]);

                return ['success' => true, 'dispute_id' => (int)$dispute->id];
            },
            $idemKey
        );
        return is_array($result) ? $result : ['success' => false, 'message' => 'پاسخ idempotency اختلاف نامعتبر است'];
    }

    /** @return CommandResult */
    public function openDispute(int $orderId, int $customerId, string $reason): array
    {
        // 🔐 Architectural Fix: Ensure Order integrity and contextual ownership
        $order = $this->toObject($this->db->table('story_orders')
            ->where('id', '=', $orderId)
            ->first());
        if ($order === null) {
            return ['success' => false, 'message' => 'سفارش معتبر یافت نشد.'];
        }

        $customerId = (int)$customerId;
        $buyerId = (int)($order->customer_id ?? 0);
        $sellerId = (int)($order->influencer_user_id ?? 0);

        if ($buyerId !== $customerId && $sellerId !== $customerId) {
            return ['success' => false, 'message' => 'شما دسترسی به طرح اختلاف برای این سفارش را ندارید.'];
        }

        // Auto-determine target counterparty safely
        $targetUserId = ($buyerId === $customerId) ? $sellerId : $buyerId;

        $data = [
            'ref_type' => 'order',
            'ref_id' => $orderId,
            'user_id' => $customerId,
            'target_user_id' => $targetUserId,
            'reason' => $reason
        ];

        // 🛡️ Idempotency: جلوگیری از باز شدن چندباره dispute برای یک سفارش
        $idempotencyKey = 'dispute_open_' . $customerId . '_' . $orderId;

        $result = $this->idempotencyService->executeWithTransaction(
            'dispute.open',
            $customerId,
            $data,
            function () use ($data) {
                $dispute = $this->openCase($data);
                if (!$dispute) {
                    return ['success' => false, 'message' => 'خطا در باز کردن پرونده اختلاف.'];
                }
                return ['success' => true, 'dispute_id' => $dispute->id];
            },
            $idempotencyKey
        );
        return is_array($result) ? $result : ['success' => false, 'message' => 'پاسخ idempotency اختلاف نامعتبر است'];
    }

    /**
     * ارسال پیام در پرونده اختلاف
     */
    /** @return CommandResult */
    public function sendMessage(int $disputeId, int $userId, string $role, string $message, ?string $attachment = null): array
    {
        $dispute = $this->toObject($this->disputeModel->find((int)$disputeId));
        if (!$dispute || !isset($dispute->id) || ((int)$dispute->user_id !== $userId && (int)($dispute->target_user_id ?? 0) !== $userId)) {
            return ['success' => false, 'message' => 'دسترسی غیرمجاز.'];
        }
        if (!in_array((string)$dispute->status, Dispute::OPEN_STATUSES, true)) {
            return ['success' => false, 'message' => 'این پرونده بسته شده و پیام جدید نمی‌پذیرد.'];
        }

        $ok = $this->disputeModel->addMessage($disputeId, $userId, $message, $attachment, $role);
        if (!$ok) {
            return ['success' => false, 'message' => 'خطا در ارسال پیام.'];
        }
        $messageId = (int)$this->db->lastInsertId();
        $messageRow = $messageId > 0
            ? $this->toObject($this->db->fetch(
                "SELECT m.*, u.full_name AS sender_name
                 FROM dispute_messages m LEFT JOIN users u ON u.id = m.user_id
                 WHERE m.id = ? LIMIT 1",
                [$messageId]
            ))
            : null;
        if ($messageRow === null) {
            throw new \RuntimeException('پیام اختلاف ثبت شد اما قابل بازیابی نیست.');
        }

        $this->logger->info('case.message_sent', [
            'dispute_id' => $disputeId,
            'user_id' => $userId,
            'role' => $role
        ]);

        return ['success' => true, 'message_item' => $messageRow];
    }

    /**
     * حل پرونده اختلاف به صورت توافقی و دوستانه
     */

    /** @return CommandResult */
    public function resolveByAgreement(int $disputeId, int $initiatorId, string $resolution, string $verdict): array
    {
        $dispute = $this->toObject($this->disputeModel->getSafe($disputeId));
        if (!$dispute) {
            return ['success' => false, 'message' => 'پرونده یافت نشد.'];
        }
        
        if ((int)$dispute->user_id !== $initiatorId && (int)$dispute->target_user_id !== $initiatorId) {
            return ['success' => false, 'message' => 'شما مجاز به حل این پرونده نیستید.'];
        }

        $ok = $this->disputeModel->update((int)$disputeId, [
            'status' => Dispute::STATUS_RESOLVED_PEER,
            'resolution_note' => $resolution,
            'resolved_at' => date('Y-m-d H:i:s'),
            'resolved_by' => $initiatorId
        ]);
        
        if (!$ok) {
            return ['success' => false, 'message' => 'خطا در ثبت تفاهم‌نامه.'];
        }
        
        $this->logger->info('case.resolved_peer', [
            'dispute_id' => $disputeId,
            'resolved_by' => $initiatorId
        ]);
        
        $this->outboxService?->record('notification', (int)$dispute->user_id, 'notification.requested', [
            'user_id' => (int)$dispute->user_id,
            'type' => 'system',
            'title' => 'حل اختلاف به صورت دوستانه',
            'message' => 'اختلاف سفارش شما به توافق طرفین خاتمه یافت.'
        ]);
        if ($dispute->target_user_id) {
            $this->outboxService?->record('notification', (int)$dispute->target_user_id, 'notification.requested', [
                'user_id' => (int)$dispute->target_user_id,
                'type' => 'system',
                'title' => 'حل اختلاف به صورت دوستانه',
                'message' => 'اختلاف سفارش شما به توافق طرفین خاتمه یافت.'
            ]);
        }
        
        return ['success' => true];
    }

    /** @return CommandResult */
    public function escalateToAdmin(int $disputeId, int $requesterId): array
    {
        $dispute = $this->toObject($this->disputeModel->getSafe($disputeId));
        if (!$dispute) {
            return ['success' => false, 'message' => 'پرونده یافت نشد.'];
        }
        
        if ($dispute->status !== Dispute::STATUS_OPEN_PEER && $dispute->status !== Dispute::STATUS_OPEN) {
            return ['success' => false, 'message' => 'امکان ارجاع این پرونده وجود ندارد.'];
        }
        if ((int)$dispute->user_id !== $requesterId && (int)$dispute->target_user_id !== $requesterId) {
            return ['success' => false, 'message' => 'شما مجاز به ارجاع این پرونده نیستید.'];
        }
        
        $ok = $this->disputeModel->update((int)$disputeId, [
            'status' => Dispute::STATUS_ESCALATED,
            'resolved_by' => $requesterId
        ]);
        
        if (!$ok) {
            return ['success' => false, 'message' => 'خطا در ارجاع پرونده به ادمین.'];
        }
        
        $this->logger->info('case.escalated', [
            'dispute_id' => $disputeId,
            'requester_id' => $requesterId
        ]);
        
        return ['success' => true];
    }

    public function getTransactionWrapper(): ?\Core\TransactionWrapper
    {
        return $this->transactionWrapper;
    }

    /** @return CommandResult */
    public function adminResolve(int $disputeId, int $adminId, string $verdict, string $note, string $refundPercent = '0'): array
    {
        $adminUser = $this->toObject($this->db->table('users')->where('id', '=', $adminId)->first());
        if (!$adminUser || !isset($adminUser->id) || !in_array((string)$adminUser->role, ['admin', 'super_admin'], true)) {
            throw new \Core\Exceptions\SecurityException('403 Forbidden: Only administrators can resolve disputes.');
        }

        // 🔒 Hardened Fix: Wrapping entire multi-step resolver in an Atomic DB Transaction
        if (!is_numeric($refundPercent) || bccomp($refundPercent, '0', 8) < 0 || bccomp($refundPercent, '100', 8) > 0) {
            return ['success' => false, 'message' => 'درصد بازگشت باید بین ۰ تا ۱۰۰ باشد.'];
        }
        $transactionWrapper = $this->getTransactionWrapper();
        if (!$transactionWrapper) {
            throw new \RuntimeException('TransactionWrapper is not available');
        }
        $result = $transactionWrapper->runWithRetry(function() use ($disputeId, $adminId, $verdict, $note, $refundPercent) {
            $dispute = $this->toObject($this->disputeModel->getSafe($disputeId));
            if (!$dispute) {
                return ['success' => false, 'message' => 'پرونده یافت نشد.'];
            }

            // 🔒 M-16 FIX: guard against re-resolving an already-finalized dispute. getSafe() above is a
            // non-locking read, so two concurrent adminResolve() calls (double-click / retried request)
            // could both observe an open dispute and each run the full refund path — a double payout.
            // Re-read the authoritative status under a row lock inside this transaction; if the dispute
            // has already reached a terminal state, abort before any refund side effect executes.
            $lockedStatus = $this->db->fetchColumn(
                "SELECT status FROM disputes WHERE id = ? FOR UPDATE",
                [$disputeId]
            );
            $terminalStatuses = [
                Dispute::STATUS_RESOLVED_ADMIN,
                Dispute::STATUS_RESOLVED_EXECUTOR,
                Dispute::STATUS_RESOLVED_ADVERTISER,
                Dispute::STATUS_CLOSED,
            ];
            if ($lockedStatus !== null && in_array((string)$lockedStatus, $terminalStatuses, true)) {
                return ['success' => false, 'message' => 'این پرونده قبلاً حل‌وفصل شده است.'];
            }

            if (in_array((string)$dispute->ref_type, ['influencer_order', 'story_order', 'order'], true)) {
                $influencerResult = $this->resolveInfluencerDisputeWithEscrow($dispute, $adminId, $verdict, $note, $refundPercent);
                if ($influencerResult !== null) {
                    return $influencerResult;
                }
            }
            
            $ok = $this->disputeModel->update((int)$disputeId, [
                'status' => Dispute::STATUS_RESOLVED_ADMIN,
                'admin_decision' => $verdict,
                'admin_id' => $adminId,
                'admin_note' => $note,
                'refund_percent' => $refundPercent,
                'resolved_at' => date('Y-m-d H:i:s')
            ]);
            
            if (!$ok) {
                throw new \Core\Exceptions\ApplicationException('خطا در ثبت رأی داوری مدیریت.');
            }
            
            $this->logger->info('case.resolved_admin', [
                'dispute_id' => $disputeId,
                'admin_id' => $adminId,
                'verdict' => $verdict
            ]);
            
            // پردازش استرداد وجه بر اساس ردیابی زنجیره تراکنش‌های مالی
            if (bccomp($refundPercent, '0', 8) > 0) {
                // 🔐 Safe Architectural Refactor: Swapped dynamic RAW string lookup for hard-coded Transaction model helper.
                $originalTx = $this->transactionModel->findCompletedByReference((string)$dispute->ref_id, (string)$dispute->ref_type);

                if ($originalTx && isset($originalTx->amount)) {
                    // H-02: اگر تراکنش مبنا تحت مدیریت Escrow باشد، بازگشت از مسیر عمومی reverseTransaction
                    // مجاز نیست؛ hold آن ممکن است قبلاً در payout/refund مصرف شده باشد و reverse عمومی
                    // وجه تسویه‌شده را دوباره اعتبار می‌دهد. چنین اختلافی باید از مسیر resolveDisputedEscrow/
                    // refund خودِ escrow حل شود. اینجا با استثنا، کل تراکنش اتمیک rollback می‌شود.
                    if ($this->transactionModel->isEscrowManaged($originalTx)) {
                        throw new \Core\Exceptions\InvalidStateException(
                            'این اختلاف به وجه امانی (escrow) وابسته است و باید از مسیر حل‌وفصل escrow رفع شود، نه بازگشت تراکنش عمومی.'
                        );
                    }

                    // PRECISION FIX: abs با bcmath به‌جای (float)
                    $rawAmount  = (string)($originalTx->amount ?? '0');
                    $baseAmount = bccomp($rawAmount, '0', 8) < 0 ? bcsub('0', $rawAmount, 8) : $rawAmount;
                    $currency = $originalTx->currency ?? 'irt';
                    $refundAmount = bcdiv(bcmul($baseAmount, $refundPercent, 8), '100', 8);

                    $success = false;

                    // سناریوی ۱: بازگشت ۱۰۰٪ وجه - استفاده از سیستم اتمیک reverse
                    if (bccomp($refundPercent, '100', 8) === 0 && method_exists($this->walletService, 'reverseTransaction')) {
                        $success = $this->walletService->reverseTransaction(
                            $originalTx->transaction_id, 
                            $adminId, 
                            "استرداد کامل (۱۰۰٪) وجه مربوط به رأی اختلاف شماره {$disputeId}"
                        );
                    } else {
                        // سناریوی ۲: بازگشت جزئی (درصدی) یا روش جایگزین
                        $payload = [
                            'user_id' => (int)$dispute->user_id,
                            'amount' => $refundAmount,
                            'currency' => $currency,
                            'metadata' => [
                                'type' => 'refund',
                                'description' => "استرداد وجه ({$refundPercent}٪) مربوط به حل اختلاف شماره {$disputeId}",
                                'ref_id' => $disputeId,
                                'ref_type' => 'dispute',
                                'admin_id' => $adminId
                            ],
                        ];

                        if ($this->outboxService) {
                            $ok = $this->outboxService->record('dispute', $disputeId, \App\Events\Registry\EventRegistry::DISPUTE_RESOLVED_REFUND, $payload);
                            $success = $ok === true;
                        } else {
                            $res = $this->walletService->deposit((int)$dispute->user_id, $refundAmount, $currency, [
                                'type' => 'refund',
                                'description' => "استرداد وجه ({$refundPercent}٪) مربوط به حل اختلاف شماره {$disputeId}",
                                'ref_id' => $disputeId,
                                'ref_type' => 'dispute',
                                'admin_id' => $adminId
                            ]);
                            $success = isset($res['success']) && $res['success'] === true;
                        }
                    }

                    if ($success) {
                        $this->logger->info('case.refund_processed', [
                            'dispute_id' => $disputeId,
                            'refund_amount' => $refundAmount,
                            'currency' => $currency,
                            'percent' => $refundPercent,
                            'user_id' => $dispute->user_id,
                            'is_reversal' => (bccomp($refundPercent, '100', 8) === 0)
                        ]);

                        // تطبیق نهایی پرداخت با دفتر کل
                        $this->reconciliationService->reconcilePayment([
                            'transaction_id' => 'dispute_refund_' . $disputeId . '_' . time(),
                            'reference_id' => 'dispute_' . $disputeId,
                            'order_id' => (int)$dispute->ref_id,
                            'amount' => $refundAmount,
                            'currency' => $currency,
                            'status' => 'success',
                            'gateway' => 'system_refund',
                            'user_id' => (int)$dispute->user_id,
                            'description' => "تطبیق خودکار استرداد رأی اختلاف",
                            'timestamp' => time(),
                            'is_internal' => true,
                        ], true); // M-30 FIX: reconcilePayment() reads the internal flag from its
                        // dedicated second argument, NOT from the payload array. Passing it only
                        // inside $webhookData left $isInternal=false, so this system-originated refund
                        // reconcile was treated as an external webhook and rejected for a missing
                        // signature. The flag must NOT be honoured from the array for external callers
                        // (that would let an attacker bypass HMAC), so the correct fix is to pass the
                        // argument explicitly here.
                    } else {
                        throw new \RuntimeException("Atomic dispute reversal failed at Wallet core.");
                    }
                } else {
                    $this->logger->warning('case.refund_skipped_no_tx', [
                        'dispute_id' => $disputeId,
                        'ref_id' => $dispute->ref_id,
                        'ref_type' => $dispute->ref_type,
                        'message' => 'No matching completed transaction found to derive refund amount.'
                    ]);
                }
            }
            
            $tpl = $this->templateService?->renderTemplate('dispute_resolved') ?? ['title' => 'رأی داوری صادر شد ⚖️', 'message' => 'داور سیستم رأی پرونده اختلاف را صادر کرد.'];
            $this->outboxService?->record('notification', (int)$dispute->user_id, 'notification.requested', [
                'user_id' => (int)$dispute->user_id,
                'type' => 'system',
                'title' => $tpl['title'],
                'message' => $tpl['message'],
            ]);
            if ($dispute->target_user_id) {
                $this->outboxService?->record('notification', (int)$dispute->target_user_id, 'notification.requested', [
                    'user_id' => (int)$dispute->target_user_id,
                    'type' => 'system',
                    'title' => $tpl['title'],
                    'message' => $tpl['message'],
                ]);
            }
            
            return ['success' => true];
        });
        return is_array($result) ? $result : ['success' => false, 'message' => 'پاسخ حل اختلاف نامعتبر است'];
    }

    /** @return ?CommandResult */
    private function resolveInfluencerDisputeWithEscrow(\stdClass $dispute, int $adminId, string $verdict, string $note, string $refundPercent): ?array
    {
        $order = $this->toObject($this->db->fetch("SELECT * FROM story_orders WHERE id = ? LIMIT 1", [(int)$dispute->ref_id]));
        if (!$order) {
            return null;
        }

        $escrow = $this->financialEscrowService;
        if (!$escrow) {
            throw new \RuntimeException('FinancialEscrowService is not available');
        }
        $orderId = (int)$order->id;
        $result = null;
        $newOrderStatus = null;
        $txId = null;
        $effectiveRefundPercent = $refundPercent;

        if ($verdict === 'favor_influencer') {
            $result = $escrow->releaseInfluencerOrderFunds($orderId, (int)$order->influencer_user_id, (string)$order->influencer_earning, "admin_story_release_{$orderId}_{$dispute->id}");
            $newOrderStatus = 'completed';
            $effectiveRefundPercent = '0';
            $txId = $result['transaction_id'] ?? null;
        } elseif ($verdict === 'favor_customer') {
            $result = $escrow->refundInfluencerOrderFunds($orderId, (int)$order->customer_id, $note ?: 'admin_dispute_refund', "admin_story_refund_{$orderId}_{$dispute->id}");
            $newOrderStatus = 'refunded';
            $effectiveRefundPercent = '100';
        } elseif ($verdict === 'partial') {
            $effectiveRefundPercent = bccomp($refundPercent, '0', 8) < 0 ? '0' : (bccomp($refundPercent, '100', 8) > 0 ? '100' : $refundPercent);
            $mark = $escrow->markEscrowDisputed($orderId, 'influencer_order', 'admin_partial_resolution', "admin_story_mark_dispute_{$orderId}_{$dispute->id}");
            if (empty($mark['ok'])) {
                return ['success' => false, 'message' => $mark['error'] ?? 'امانت سفارش برای حل اختلاف آماده نشد.'];
            }
            $result = $escrow->resolveDisputedEscrow($orderId, 'influencer_order', 'partial', $effectiveRefundPercent, "admin_story_partial_{$orderId}_{$dispute->id}");
            $newOrderStatus = 'partially_refunded';
        } else {
            return ['success' => false, 'message' => 'رأی نامعتبر است.'];
        }

        if (empty($result['ok'])) {
            return ['success' => false, 'message' => $result['error'] ?? 'حل مالی اختلاف اینفلوئنسر انجام نشد.'];
        }

        $this->db->query(
            "UPDATE story_orders SET status = ?, reviewed_by = ?, reviewed_at = NOW(), admin_note = ?, payout_transaction_id = COALESCE(?, payout_transaction_id), buyer_confirmed_at = CASE WHEN ? = 'completed' THEN NOW() ELSE buyer_confirmed_at END, updated_at = NOW() WHERE id = ?",
            [$newOrderStatus, $adminId, $note, $txId, $newOrderStatus, $orderId]
        );

        $this->disputeModel->update((int)$dispute->id, [
            'status' => Dispute::STATUS_RESOLVED_ADMIN,
            'admin_decision' => $verdict,
            'admin_id' => $adminId,
            'admin_note' => $note,
            'refund_percent' => $effectiveRefundPercent,
            'resolved_at' => date('Y-m-d H:i:s'),
            'resolved_by' => $adminId,
        ]);

        $this->logger->info('case.influencer_resolved_escrow', [
            'dispute_id' => (int)$dispute->id,
            'order_id' => $orderId,
            'verdict' => $verdict,
            'refund_percent' => $effectiveRefundPercent,
        ]);

        return ['success' => true, 'message' => 'اختلاف اینفلوئنسر با امانت مالی تعیین تکلیف شد.'];
    }

    /**
     * پردازش خودکار گفتگوهای منقضی شده طرفین
     */

    public function processExpiredPeerResolutions(): int
    {
        $rows = $this->db->fetchAll(
            "SELECT id FROM disputes 
             WHERE status = ? AND peer_deadline < NOW()",
            [Dispute::STATUS_OPEN_PEER]
        );
        
        $count = 0;
        foreach (($rows ?: []) as $row) {
            $rowObj = $row;
            $ok = $this->disputeModel->update((int)$rowObj->id, [
                'status' => Dispute::STATUS_ESCALATED,
                'resolution_note' => 'سیستم: پایان زمان گفتگوی طرفین و ارجاع خودکار به مدیریت.'
            ]);
            
            if ($ok) {
                $count++;
                $this->logger->info('case.auto_escalated', ['dispute_id' => $rowObj->id]);
            }
        }
        
        return $count;
    }

    /** @param DisputeInput $data */
    public function openCase(array $data): ?\stdClass
    {
        $userId = $data['user_id'] ?? null;
        $refType = $data['ref_type'] ?? null;
        if ((!is_int($userId) && !(is_string($userId) && ctype_digit($userId))) || !is_string($refType) || $refType === '') {
            throw new \InvalidArgumentException('اطلاعات اختلاف نامعتبر است.');
        }
        $data['user_id'] = (int)$userId;
        // بررسی محدودیت‌ها برای کاربر
        if (!$this->checkLimits($data['user_id'])) {
            throw new \Core\Exceptions\RateLimitExceededException('تعداد موارد ارسالی بیش از حد مجاز است.');
        }

        try {
            $data['priority'] = $this->determinePriority($refType);
            
            $dispute = $this->toObject($this->disputeModel->createDispute($data));
            
            if ($dispute) {
                $this->logger->info('case.opened', [
                    'id' => $dispute->id,
                    'type' => $data['ref_type'],
                    'user_id' => $data['user_id']
                ]);
                
                // نوتیفیکیشن به طرفین یا ادمین
                $this->sendNotifications($dispute);
            }
            
            return $dispute;
        } catch (\Throwable $e) {
            $this->logger->error('case.open_failed', ['error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * ارسال پیام در پرونده با تعیین خودکار نقش و آپدیت تاریخچه
     */
    /** @return CommandResult */
    public function addMessageWithContext(int $disputeId, int $userId, string $message, ?string $attachment = null): array
    {
        $dispute = $this->toObject($this->disputeModel->find((int)$disputeId));
        if ($dispute === null) {
            return ['success' => false, 'message' => 'پرونده یافت نشد.'];
        }

        // Security check
        if ((int)$dispute->user_id !== $userId && (int)($dispute->target_user_id ?? 0) !== $userId) {
            return ['success' => false, 'message' => 'شما دسترسی به این پرونده ندارید.'];
        }
        if (!in_array((string)$dispute->status, Dispute::OPEN_STATUSES, true)) {
            return ['success' => false, 'message' => 'این پرونده بسته شده و پیام جدید نمی‌پذیرد.'];
        }

        // Auto determine role
        $role = ((int)$dispute->user_id === $userId) ? 'creator' : 'opponent';

        try {
            $transactionWrapper = $this->getTransactionWrapper();
            if ($transactionWrapper === null) {
                throw new \RuntimeException('TransactionWrapper is not available');
            }
            $result = $transactionWrapper->runWithRetry(function() use ($disputeId, $userId, $message, $attachment, $role) {
                $ok = $this->disputeModel->addMessage($disputeId, $userId, $message, $attachment, $role);
                if (!$ok) throw new \Core\Exceptions\ApplicationException('خطا در ثبت پیام');
    
                $this->db->query("UPDATE disputes SET updated_at = NOW() WHERE id = ?", [$disputeId]);
                
                $this->logger->info('dispute.message_added', ['dispute_id' => $disputeId, 'user_id' => $userId, 'role' => $role]);
                
                return ['success' => true];
            });
            return is_array($result) ? $result : ['success' => false, 'message' => 'پاسخ ثبت پیام نامعتبر است'];
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * کاربر کے تمام اختلافات حاصل کریں
     */

    private function checkLimits(int $userId): bool
    {
        // در مدل پیاده‌سازی می‌شود
        return true; 
    }

    /**
     * تعیین اولویت پرونده
     */
    private function determinePriority(string $type): string
    {
        $priorities = [
            'fraud_suspension' => 'urgent',
            'payment_dispute' => 'high',
            'order_dispute' => 'medium'
        ];
        return $priorities[$type] ?? 'low';
    }

    private function sendNotifications(object $case): void
    {
        // ارسال نوتیف به ادمین یا طرف مقابل
    }

}
