<?php

declare(strict_types=1);

namespace App\Jobs\SocialTask;

use App\Models\SocialTaskExecutionModel;
use App\Models\SocialTaskModel;
use App\Contracts\WalletServiceInterface;
use Core\Database;

class ApproveSocialTaskExecutionJob
{
    private ?\App\Contracts\LoggerInterface $logger = null;
    public function __construct(
        private Database $db,
        private SocialTaskExecutionModel $execModel,
        private SocialTaskModel $taskModel,
        private WalletServiceInterface $walletService,
        private ?\App\Contracts\OutboxServiceInterface $outbox = null,
        private ?\App\Services\EscrowService $escrowService = null
    ) {}

    /** @return array<string, mixed> */
public function handle(int $advertiserId, int $executionId): array
    {
        try {
            $this->db->beginTransaction();
            $exec = $this->execModel->getExecutionById($executionId, true);
            if (!$exec) {
                $this->db->rollback();
                return ['success' => false, 'message' => 'اجرا یافت نشد'];
            }
            $ad = $this->taskModel->getAdById((int)$exec->ad_id, true);
            if (!$ad || (int)$ad->user_id !== $advertiserId) {
                $this->db->rollback();
                return ['success' => false, 'message' => 'دسترسی مجاز نیست'];
            }
            if ((string)$exec->status !== 'submitted') {
                $this->db->rollback();
                return ['success' => false, 'message' => 'این اجرا در وضعیت قابل تأیید نیست.'];
            }

            $amount = (string)($ad->price_per_task ?? '0');
            $currency = (string)($ad->currency ?? 'irt');
            $txId = null;
            $escrowService = $this->escrowService;
            if ($escrowService === null) {
                $this->logger?->warning('social_task.approve.no_escrow_service', ['execution_id' => $executionId]);
                return ['success' => true, 'message' => 'تسک تأیید شد (بدون Escrow).'];
            }
            $escrow = $escrowService->getByOrder((int)$exec->ad_id, 'social_task_budget');
            if ($escrow && in_array((string)$escrow->status, ['pending', 'in_escrow', 'partial'], true)) {
                // PRIMARY: social task campaign budget is held in one central escrow.
                // Release the worker reward from that escrow so advertiser locked balance is reduced.
                $release = $escrowService->partialRelease((int)$escrow->id, (int)$exec->executor_id, $amount, 'social_task_reward_' . $executionId);
                if (empty($release['ok'])) {
                    throw new \RuntimeException(str_value($release['error'] ?? 'خطا در آزادسازی پاداش اجتماعی از escrow.'));
                }
                $txId = 'escrow_social_release_' . (int)$escrow->id . '_' . $executionId;
            } else {
                // COMPATIBILITY_REDIRECT: legacy social tasks created before central budget escrow.
                $pay = $this->walletService->deposit((int)$exec->executor_id, $amount, $currency, [
                    'type' => 'social_task_reward',
                    'description' => 'پاداش انجام تسک اجتماعی',
                    'idempotency_key' => 'social_task_reward_' . $executionId,
                    'execution_id' => $executionId,
                    'ad_id' => (int)$exec->ad_id,
                ]);
                if (empty($pay['success'])) {
                    throw new \RuntimeException(str_value($pay['message'] ?? 'خطا در پرداخت پاداش'));
                }
                $txId = $pay['transaction_id'] ?? null;
            }

            $approvalData = [
                'reward_paid' => 1,
                'reward_amount' => $amount,
                'decision' => 'approved',
                'reviewed_by' => $advertiserId,
                'reviewed_at' => date('Y-m-d H:i:s'),
            ];
            $this->execModel->updateExecutionStatus($executionId, 'approved', $approvalData);
            $this->outbox?->record('social_task', $executionId, 'social_task.approved', [
                'execution_id' => $executionId,
                'status' => 'approved',
            ]);
            $this->db->query(
                "UPDATE ads
                 SET completed_count = COALESCE(completed_count,0)+1,
                     pending_count = GREATEST(COALESCE(pending_count,0)-1,0),
                     remaining_budget = GREATEST(COALESCE(remaining_budget,0)-?,0),
                     status = CASE
                         WHEN GREATEST(COALESCE(remaining_budget,0)-?,0) <= 0
                           OR (COALESCE(total_count,0) > 0 AND COALESCE(completed_count,0)+1 >= COALESCE(total_count,0))
                         THEN 'completed' ELSE status END,
                     is_active = CASE
                         WHEN GREATEST(COALESCE(remaining_budget,0)-?,0) <= 0
                           OR (COALESCE(total_count,0) > 0 AND COALESCE(completed_count,0)+1 >= COALESCE(total_count,0))
                         THEN 0 ELSE is_active END,
                     updated_at = NOW()
                 WHERE id = ?",
                [(float)$amount, (float)$amount, (float)$amount, (int)$exec->ad_id]
            );
            $this->db->query("INSERT INTO social_user_trust (user_id, trust_score, created_at, updated_at) VALUES (?, 55, NOW(), NOW()) ON DUPLICATE KEY UPDATE trust_score = LEAST(100, trust_score + 5), updated_at = NOW()", [(int)$exec->executor_id]);
            $this->db->commit();
            return ['success' => true, 'message' => 'اجرا تأیید و پاداش پرداخت شد.'];
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) $this->db->rollback();
            return ['success' => false, 'message' => 'خطا در تأیید اجرا: ' . $e->getMessage()];
        }
    }
}
