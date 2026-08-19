<?php

declare(strict_types=1);

namespace App\Jobs\SocialTask;

use App\Models\SocialTaskModel;
use App\Models\SocialTaskExecutionModel;
use App\Contracts\LoggerInterface;
use Core\Database;

class StartSocialTaskExecutionJob
{
    private const EXPECTED_TIME = [
        'follow' => 45,
        'like' => 20,
        'comment' => 90,
        'share' => 30,
        'retweet' => 25,
        'join_channel' => 30,
        'join_group' => 30,
    ];

    public function __construct(
        private Database $db,
        private SocialTaskModel $taskModel,
        private SocialTaskExecutionModel $executionModel,
        private LoggerInterface $logger
    ) {}

/**
 * @param array<string, mixed> $context
 * @return array<string, mixed>
 */
public function handle(int $userId, int $adId, array $context = []): array
    {
        try {
            $this->db->beginTransaction();
            $result = (function () use ($userId, $adId, $context) {
                $ad = $this->taskModel->getAdById($adId, true);
                if (!$ad || (string)$ad->status !== 'active') {
                    return ['success' => false, 'message' => 'تسک موجود نیست یا فعال نیست.'];
                }
                if ((string)($ad->platform ?? '') === 'youtube') {
                    return ['success' => false, 'message' => 'تسک‌های YouTube از SocialTask جدا هستند.'];
                }
                $remaining = (int)($ad->remaining_count ?? (($ad->total_count ?? 0) - ($ad->completed_count ?? 0)));
                if ($remaining <= 0) {
                    return ['success' => false, 'message' => 'ظرفیت تسک تکمیل شده است.'];
                }
                if ((int)$ad->user_id === $userId) {
                    return ['success' => false, 'message' => 'امکان اجرای تسک خودتان وجود ندارد.'];
                }

                $existing = $this->db->fetch(
                    "SELECT id, status FROM social_task_executions WHERE ad_id = ? AND executor_id = ? AND status NOT IN ('expired','cancelled','rejected') LIMIT 1 FOR UPDATE",
                    [$adId, $userId]
                );
                if ($existing) {
                    return ['success' => false, 'message' => 'شما قبلاً این تسک را شروع کرده‌اید.'];
                }

                $taskType = (string)($ad->task_type ?? 'follow');
                $minGap = self::EXPECTED_TIME[$taskType] ?? 30;
                $recent = $this->db->fetch(
                    "SELECT e.created_at, TIMESTAMPDIFF(SECOND, e.created_at, NOW()) AS age_seconds
                     FROM social_task_executions e
                     JOIN ads a ON a.id = e.ad_id
                     WHERE e.executor_id = ? AND a.task_type = ?
                     ORDER BY e.created_at DESC LIMIT 1",
                    [$userId, $taskType]
                );
                if ($recent && (int)($recent->age_seconds ?? 999999) < $minGap) {
                    return ['success' => false, 'message' => "برای اجرای دوباره تسک {$taskType} باید {$minGap} ثانیه فاصله رعایت شود.", 'rate_limit_seconds' => $minGap];
                }

                $execId = $this->executionModel->createExecution([
                    'ad_id' => $adId,
                    'executor_id' => $userId,
                    'ip_address' => $context['ip'] ?? (get_client_ip()),
                    'user_agent' => $context['user_agent'] ?? (get_user_agent()),
                    'expected_time' => $minGap,
                ]);
                $this->executionModel->updateExecutionStatus($execId, 'in_progress');

                $this->db->query(
                    "UPDATE ads SET remaining_count = GREATEST(COALESCE(remaining_count,total_count) - 1, 0), pending_count = COALESCE(pending_count,0) + 1, updated_at = NOW() WHERE id = ?",
                    [$adId]
                );

                return [
                    'success' => true,
                    'execution_id' => $execId,
                    'status' => 'in_progress',
                    'expected_time' => $minGap,
                    'target_url' => $ad->target_url ?? $ad->link ?? null,
                    'task_type' => $taskType,
                ];
            })();
            $this->db->commit();
            return $result;
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollback();
            }
            $this->logger->error('social.start_execution_failed', ['user_id' => $userId, 'ad_id' => $adId, 'error' => $e->getMessage()]);
            return ['success' => false, 'message' => 'خطا در شروع اجرای تسک: ' . $e->getMessage()];
        }
    }
}
