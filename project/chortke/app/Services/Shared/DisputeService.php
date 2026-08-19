<?php

declare(strict_types=1);

namespace App\Services\Shared;

use App\Services\Dispute\DisputeCommandService;
use App\Services\Dispute\DisputeQueryService;
use App\Models\Dispute;
use App\Contracts\WalletServiceInterface;
use App\Contracts\LoggerInterface;
use App\Services\ReconciliationService;
use App\Services\CustomTask\CustomTaskModerationService;
use App\Services\EscrowService;
use App\Models\Ads;
use App\Models\CustomTaskSubmissionModel;
use Core\Database;
use Core\ValueObjects\Money;

/**
 * DisputeService — Facade
 * Logic به DisputeCommandService و DisputeQueryService منتقل شده.
 *
 * @phpstan-type CommandResult array<string, mixed>
 * @phpstan-type DisputeInput array<string, mixed>
 */
class DisputeService
{
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


    private DisputeCommandService $commandService;
    private DisputeQueryService $queryService;
    private Database $db;
    private ?CustomTaskModerationService $moderationService = null;
    private ?EscrowService $escrowService = null;
    private ?Ads $adsModel = null;
    private ?CustomTaskSubmissionModel $submissionModel = null;

    public function __construct(
        Database $db,
        LoggerInterface $logger,
        Dispute $disputeModel,
        WalletServiceInterface $walletService,
        ReconciliationService $reconciliationService,
        \App\Models\Transaction $transactionModel,
        \App\Services\Shared\IdempotencyService $idempotencyService,
        ?CustomTaskModerationService $moderationService = null,
        ?EscrowService $escrowService = null,
        ?Ads $adsModel = null,
        ?CustomTaskSubmissionModel $submissionModel = null,
        ?\App\Services\OutboxService $outboxService = null,
        ?\App\Domain\Financial\Services\FinancialEscrowService $financialEscrowService = null,
        ?\Core\TransactionWrapper $transactionWrapper = null
    ) {
        $this->db = $db;
        $this->moderationService = $moderationService;
        $this->escrowService = $escrowService;
        $this->adsModel = $adsModel;
        $this->submissionModel = $submissionModel;
        $this->commandService = new DisputeCommandService(
            $db, $logger, $disputeModel,
            $walletService, $reconciliationService, $transactionModel, $idempotencyService, $outboxService,
            null,
            $financialEscrowService,
            $transactionWrapper
        );
        $this->queryService = new DisputeQueryService($db, $disputeModel);
    }

    // ─── Command ────────────────────────────────────────────────

    /** @return CommandResult */
    public function openDispute(int $orderId, int $customerId, string $reason): array
    { return $this->commandService->openDispute($orderId, $customerId, $reason); }

    /** @return CommandResult */
    public function openCustomTaskDispute(int $submissionId, int $userId, int $targetUserId, string $reason): array
    { return $this->commandService->openCustomTaskDispute($submissionId, $userId, $targetUserId, $reason); }

    public function findDetailWithSubmission(int $disputeId, ?int $userId = null): ?object
    { return $this->commandService->getDisputeModel()->findDetailWithSubmission($disputeId, $userId); }

    /** @return CommandResult */
    public function sendMessage(int $disputeId, int $userId, string $role, string $message, ?string $attachment = null): array
    { return $this->commandService->sendMessage($disputeId, $userId, $role, $message, $attachment); }

    /** @return CommandResult */
    public function resolveByAgreement(int $disputeId, int $initiatorId, string $resolution, string $verdict): array
    { return $this->commandService->resolveByAgreement($disputeId, $initiatorId, $resolution, $verdict); }

    /** @return CommandResult */
    public function escalateToAdmin(int $disputeId, int $requesterId): array
    { return $this->commandService->escalateToAdmin($disputeId, $requesterId); }

    /** @return CommandResult */
    public function adminResolve(int $disputeId, int $adminId, string $verdict, string $note, string $refundPercent = '0'): array
    { return $this->commandService->adminResolve($disputeId, $adminId, $verdict, $note, $refundPercent); }

    public function processExpiredPeerResolutions(): int
    { return $this->commandService->processExpiredPeerResolutions(); }

    /** @param DisputeInput $data */
    public function openCase(array $data): ?\stdClass
    { return $this->commandService->openCase($data); }

    /** @return CommandResult */
    public function addMessageWithContext(int $disputeId, int $userId, string $message, ?string $attachment = null): array
    { return $this->commandService->addMessageWithContext($disputeId, $userId, $message, $attachment); }


    /** Admin list for CustomTask disputes (kept here for legacy AdminExecutorTaskController). */
    /**
     * @param array<string, mixed> $filters
     * @return array{items: list<\stdClass>, total: int}
     */
    /**
     * لیست یکپارچه‌ی اختلافات برای پنل ادمین — همه‌ی ref_typeها.
     * اگر $filters['ref_type'] خالی باشد همه‌ی ماژول‌ها (custom task، اینفلوئنسر/سفارش، ویترین) نمایش داده می‌شوند.
     * @param array<string, mixed> $filters
     * @return array{items: list<\stdClass>, total: int}
     */
    public function listForAdmin(array $filters = [], int $limit = 30, int $offset = 0): array
    {
        $where = [];
        $params = [];

        $refType = $filters['ref_type'] ?? null;
        if (is_string($refType) && $refType !== '' && $refType !== 'all') {
            $where[] = 'd.ref_type = ?';
            $params[] = $refType;
        }

        if (!empty($filters['status'])) {
            if ($filters['status'] === 'resolved') {
                $where[] = "d.status IN ('resolved','resolved_admin','resolved_for_executor','resolved_for_advertiser','closed')";
            } else {
                $where[] = 'd.status = ?';
                $status = $filters['status'];
                if (is_scalar($status)) $params[] = (string)$status;
            }
        }

        $whereSql = empty($where) ? '1=1' : implode(' AND ', $where);
        $safeLimit = max(1, min(200, $limit));
        $safeOffset = max(0, $offset);
        $items = $this->queryService->unifiedAdminDisputeList($whereSql, $params, $safeLimit, $safeOffset);
        $total = $this->queryService->unifiedAdminDisputeCount($whereSql, $params);
        return ['items' => $items, 'total' => $total];
    }

    /**
     * Resolve CustomTask dispute from admin panel. decision: executor|advertiser|split
     * @return CommandResult
     */
    public function resolveByAdmin(int $adminId, int $disputeId, string $decision, string $adminNote = '', string $executorPercent = '0'): array
    {
        try {
            $dispute = $this->toObject($this->db->fetch(
                "SELECT d.*, s.id AS submission_id, s.status AS submission_status, s.task_id, s.worker_id, s.reward_amount, s.reward_currency, a.user_id AS advertiser_id
                 FROM disputes d
                 INNER JOIN custom_task_submissions s ON s.id = d.ref_id AND d.ref_type = 'custom_task_submission'
                 LEFT JOIN ads a ON a.id = s.task_id
                 WHERE d.id = ? LIMIT 1",
                [$disputeId]
            ));
            if (!$dispute) return ['ok' => false, 'message' => 'اختلاف یافت نشد.'];
            if (!in_array((string)$dispute->status, ['open','open_peer','under_review','escalated'], true)) {
                return ['ok' => false, 'message' => 'این اختلاف قبلاً تعیین تکلیف شده است.'];
            }
            $moderation = $this->moderationService;
            $submissionModel = $this->submissionModel;
            if ($moderation === null || $submissionModel === null) {
                return ['ok' => false, 'message' => 'زیرساخت بررسی اختلاف تسک در دسترس نیست.'];
            }
            $submission = $this->toObject($submissionModel->submission_find((int)$dispute->submission_id));
            if ($submission === null) return ['ok' => false, 'message' => 'ارسال مرتبط با اختلاف یافت نشد.'];

            if ($decision === 'executor') {
                $review = $moderation->approveSubmission($submission);
                if (empty($review['success'])) return ['ok' => false, 'message' => $review['message'] ?? 'تأیید به نفع مجری انجام نشد.'];
                $newStatus = 'resolved_for_executor';
                $adminDecision = 'worker_wins';
            } elseif ($decision === 'advertiser') {
                if ((string)$submission->status !== 'rejected') {
                    $review = $moderation->rejectSubmission($submission, $adminNote ?: 'رأی اختلاف به نفع کارفرما');
                    if (empty($review['success'])) return ['ok' => false, 'message' => $review['message'] ?? 'رد به نفع کارفرما انجام نشد.'];
                }
                $newStatus = 'resolved_for_advertiser';
                $adminDecision = 'advertiser_wins';
            } elseif ($decision === 'split') {
                $split = $this->resolveCustomTaskSplit($submission, $executorPercent, $adminNote, $adminId);
                if (empty($split['ok'])) return $split;
                $newStatus = 'resolved_admin';
                $adminDecision = 'split';
            } else {
                return ['ok' => false, 'message' => 'تصمیم نامعتبر است.'];
            }

            $this->db->query(
                "UPDATE disputes SET status = ?, admin_decision = ?, admin_note = ?, refund_percent = ?, resolved_by = ?, resolved_at = NOW(), updated_at = NOW() WHERE id = ?",
                [$newStatus, $adminDecision, $adminNote, $decision === 'split' ? bcsub('100', $executorPercent, 8) : null, $adminId, $disputeId]
            );
            return ['ok' => true, 'message' => 'اختلاف با موفقیت حل شد.'];
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => 'خطا در حل اختلاف: ' . $e->getMessage()];
        }
    }

    /**
     * داوریِ یکپارچه‌ی ادمین — بر اساس ref_type به ماژول درست dispatch می‌کند.
     * - اینفلوئنسر/سفارش → adminResolve (با escrow)
     * - تسک سفارشی → resolveByAdmin (executor/advertiser/split)
     * - ویترین → VitrineService::resolveDispute (seller/buyer)
     * @param array<string, mixed> $meta (decision, admin_note, executor_percent, winner)
     * @return array<string, mixed>
     */
    public function resolveForAdmin(int $adminId, int $disputeId, array $meta = []): array
    {
        try {
            $dispute = $this->queryService->find($disputeId);
            if (!$dispute) {
                return ['ok' => false, 'success' => false, 'message' => 'اختلاف یافت نشد.'];
            }
            $refType = (string)($dispute->ref_type ?? '');
            $decision = str_value($meta['decision'] ?? '');
            $note = str_value($meta['admin_note'] ?? '');

            if (in_array($refType, ['order', 'story_order', 'influencer_order', 'influencer'], true)) {
                $verdict = match ($decision) {
                    'seller', 'executor', 'favor_seller' => 'favor_seller',
                    'buyer', 'advertiser', 'favor_buyer' => 'favor_buyer',
                    default => $decision,
                };
                $refundPercent = str_value($meta['refund_percent'] ?? '0');
                $result = $this->commandService->adminResolve($disputeId, $adminId, $verdict, $note, $refundPercent);
                return array_merge($result, ['success' => (bool)($result['success'] ?? false)]);
            }

            if ($refType === 'vitrine_listing') {
                $winner = match ($decision) {
                    'seller', 'executor', 'favor_seller' => 'seller',
                    'buyer', 'advertiser', 'favor_buyer' => 'buyer',
                    default => $decision,
                };
                if (!in_array($winner, ['seller', 'buyer'], true)) {
                    return ['ok' => false, 'success' => false, 'message' => 'برای ویترین، برنده باید seller یا buyer باشد.'];
                }
                $vitrineService = app(\App\Services\VitrineService::class);
                $vitrineResult = $vitrineService->resolveDispute((int)$dispute->ref_id, $winner, $adminId);
                $ok = !empty($vitrineResult['success']);
                return ['ok' => $ok, 'success' => $ok, 'message' => $vitrineResult['message'] ?? ($ok ? 'اختلاف ویترین حل شد.' : 'خطا در حل اختلاف ویترین.'), 'data' => $vitrineResult];
            }

            // default: تسک سفارشی (custom_task_submission)
            $executorPercent = str_value($meta['executor_percent'] ?? '0');
            return $this->resolveByAdmin($adminId, $disputeId, $decision, $note, $executorPercent);
        } catch (\Throwable $e) {
            return ['ok' => false, 'success' => false, 'message' => 'خطا در حل اختلاف: ' . $e->getMessage()];
        }
    }

    /** @return CommandResult */
    private function resolveCustomTaskSplit(\stdClass $submission, string $executorPercent, string $note, int $adminId): array
    {
        if (!is_numeric($executorPercent)) return ['ok' => false, 'message' => 'درصد سهم مجری نامعتبر است.'];
        $executorPercent = bccomp($executorPercent, '0', 8) <= 0 ? '50' : $executorPercent;
        if (bccomp($executorPercent, '1', 8) < 0 || bccomp($executorPercent, '99', 8) > 0) {
            return ['ok' => false, 'message' => 'درصد سهم مجری باید بین ۱ تا ۹۹ باشد.'];
        }
        $reward = is_scalar($submission->reward_amount ?? null) ? (string)$submission->reward_amount : '0';
        $currency = is_string($submission->reward_currency ?? null) ? $submission->reward_currency : 'usdt';
        if (!is_numeric($reward) || bccomp($reward, '0', 8) <= 0) return ['ok' => false, 'message' => 'مبلغ سهم مجری نامعتبر است.'];
        $amount = Money::fromString($reward, $currency)->percentage($executorPercent)->getAmount();
        if (bccomp($amount, '0', 8) <= 0) return ['ok' => false, 'message' => 'مبلغ سهم مجری نامعتبر است.'];

        $db = $this->db;
        $escrowService = $this->escrowService;
        $taskModel = $this->adsModel;
        $submissionModel = $this->submissionModel;
        if ($escrowService === null || $taskModel === null || $submissionModel === null) {
            return ['ok' => false, 'message' => 'زیرساخت تسویه اختلاف تسک در دسترس نیست.'];
        }

        try {
            $db->beginTransaction();
            $sub = $this->toObject($submissionModel->submission_findByIdForUpdate((int)$submission->id));
            if (!$sub || !in_array((string)$sub->status, ['submitted','rejected','disputed'], true)) {
                throw new \RuntimeException('وضعیت ارسال برای حل split معتبر نیست.');
            }

            $txId = null;
            $escrow = $this->toObject($escrowService->getByOrder((int)$sub->task_id, 'custom_task_budget'));
            if ($escrow === null) {
                throw new \RuntimeException('تسویه split برای تسک بدون escrow فعال مجاز نیست؛ audit و migration مالی الزامی است.');
            }
            $release = $escrowService->partialRelease((int)$escrow->id, (int)$sub->worker_id, $amount, 'custom_task_dispute_split_' . (int)$sub->id);
            if (empty($release['ok'])) {
                throw new \RuntimeException(is_string($release['error'] ?? null) ? $release['error'] : 'آزادسازی سهم مجری انجام نشد.');
            }
            $txId = 'escrow_split_release_' . (int)$escrow->id . '_submission_' . (int)$sub->id;

            $submissionModel->submission_update((int)$sub->id, [
                'status' => 'resolved_split',
                'reviewed_at' => date('Y-m-d H:i:s'),
                'reviewed_by' => $adminId,
                'reward_paid' => 1,
                'paid_amount' => $amount,
                'reward_transaction_id' => $txId,
                'resolution_type' => 'split',
                'resolution_note' => $note,
            ]);

            if ((string)$sub->status === 'submitted') {
                // Not completed, only partially compensated: free the reserved slot.
                $taskModel->decrementPendingCount((int)$sub->task_id);
            }

            $db->commit();
            return ['ok' => true, 'paid_amount' => $amount, 'executor_percent' => $executorPercent, 'transaction_id' => $txId];
        } catch (\Throwable $e) {
            if ($db->inTransaction()) $db->rollback();
            return ['ok' => false, 'message' => $e->getMessage()];
        }
    }


    // ─── Query ──────────────────────────────────────────────────

    /** @return list<\stdClass> */
    public function getUserDisputes(int $userId, int $limit = 20, int $offset = 0): array
    { return $this->queryService->getUserDisputes($userId, $limit, $offset); }

    public function countUserDisputes(int $userId): int
    { return $this->queryService->countUserDisputes($userId); }

    public function find(int $id): ?\stdClass
    { return $this->toObject($this->queryService->find($id)); }

    /** @return list<\stdClass> */
    public function getMessages(int $disputeId): array
    { return $this->queryService->getMessages($disputeId); }

    /** @return list<\stdClass> */
    public function getCustomTaskDisputesByUser(int $userId, int $limit = 100, int $offset = 0): array
    { return $this->queryService->getCustomTaskDisputesByUser($userId, $limit, $offset); }
}
