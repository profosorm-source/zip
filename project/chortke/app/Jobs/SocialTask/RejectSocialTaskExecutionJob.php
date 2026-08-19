<?php

declare(strict_types=1);

namespace App\Jobs\SocialTask;

use App\Models\SocialTaskExecutionModel;
use App\Models\SocialTaskModel;
use Core\Database;

class RejectSocialTaskExecutionJob
{
    public function __construct(
        private Database $db,
        private SocialTaskExecutionModel $execModel,
        private SocialTaskModel $taskModel,
        private ?\App\Contracts\OutboxServiceInterface $outbox = null
    ) {}

    /** @return array<string, mixed> */
public function handle(int $advertiserId, int $executionId, string $reason): array
    {
        $reason = trim((string)$reason);
        if ($reason === '') return ['success' => false, 'message' => 'دلیل رد الزامی است'];

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
                return ['success' => false, 'message' => 'این اجرا در وضعیت قابل رد نیست.'];
            }

            $this->execModel->updateExecutionStatus($executionId, 'rejected', [
                'reject_reason' => $reason,
                'decision' => 'rejected',
                'reviewed_by' => $advertiserId,
                'reviewed_at' => date('Y-m-d H:i:s'),
            ]);
            $this->outbox?->record('social_task', $executionId, 'social_task.rejected', [
                'execution_id' => $executionId,
                'status' => 'rejected',
            ]);
            $this->db->query("UPDATE ads SET pending_count = GREATEST(COALESCE(pending_count,0)-1,0), remaining_count = COALESCE(remaining_count,0)+1, updated_at = NOW() WHERE id = ?", [(int)$exec->ad_id]);
            $this->db->query("INSERT INTO social_user_trust (user_id, trust_score, created_at, updated_at) VALUES (?, 45, NOW(), NOW()) ON DUPLICATE KEY UPDATE trust_score = GREATEST(0, trust_score - 5), updated_at = NOW()", [(int)$exec->executor_id]);
            $this->db->commit();
            return ['success' => true, 'message' => 'اجرا رد شد'];
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) $this->db->rollback();
            return ['success' => false, 'message' => 'خطا در رد اجرا: ' . $e->getMessage()];
        }
    }
}
