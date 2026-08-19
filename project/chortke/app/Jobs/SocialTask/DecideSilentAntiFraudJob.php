<?php

declare(strict_types=1);

namespace App\Jobs\SocialTask;

use App\Enums\ModuleContext;
use App\Services\Settings\AppSettings;
use App\Services\AuditTrail;
use App\Services\User\UserService;
use App\Services\Gamification\TrustService;

class DecideSilentAntiFraudJob
{
    public function __construct(
        private AppSettings $appSettings,
        private AuditTrail $auditTrail,
        private UserService $userService,
        private TrustService $trustService,
        private ?\App\Contracts\OutboxServiceInterface $outbox = null
    ) {}

/**
 * @param array<string, mixed> $riskResult
 * @return array<string, mixed>
 */
public function handle(int $userId, int $executionId, float $taskScore, array $riskResult): array
    {
        $userObj = $this->userService->findById($userId);
        $trustScore = $userObj ? $this->trustService->getTrustScore((object)['id' => int_value($userObj->id ?? 0)], ModuleContext::SOCIAL_TASKS) : 50.0;
        $riskScore  = int_value($riskResult['risk_score'] ?? 0);

        $minTaskScore  = int_value($this->appSettings->get('antifraud_min_task_score', 70));
        $minTrustScore = int_value($this->appSettings->get('antifraud_min_trust_score', 60));
        $maxRiskScore  = int_value($this->appSettings->get('antifraud_max_risk_score', 30));
        $softMinScore  = int_value($this->appSettings->get('antifraud_soft_min_score', 40));

        if ($riskScore >= 80) {
            $decision = 'rejected'; $payReward = false; $giveScore = false; $flagReview = true; $reason = 'high_risk_rejected';
        } elseif ($riskScore >= 50) {
            $decision = 'flagged'; $payReward = false; $giveScore = false; $flagReview = true; $reason = 'medium_risk_flagged';
        } elseif ($taskScore >= $minTaskScore && $trustScore >= $minTrustScore && $riskScore < $maxRiskScore) {
            $decision = 'approved'; $payReward = true; $giveScore = true; $flagReview = false; $reason = 'score_trust_risk_all_good';
        } elseif ($taskScore >= $softMinScore) {
            $decision = 'soft_approved'; $payReward = true; $giveScore = false; $flagReview = $taskScore < 50; $reason = 'soft_approved_borderline_score';
        } else {
            $decision = 'rejected'; $payReward = false; $giveScore = false; $flagReview = $taskScore < 20; $reason = 'low_score_rejected';
        }

        try {
            $this->auditTrail->record($decision === 'approved' ? 'task.execution.approved' : 'task.execution.rejected', $userId, [
                'execution_id' => $executionId,
                'task_score'   => $taskScore,
                'trust_score'  => $trustScore,
                'risk_score'   => $riskScore,
                'decision'     => $decision,
                'reason'       => $reason,
            ]);
        } catch (\Throwable $e) {
            @error_log('[DecideSilentAntiFraudJob] failed: ' . $e->getMessage());
        }

        if ($flagReview) {
            $adminId = int_value($this->appSettings->get('system_admin_user_id', 1));
            $this->outbox?->record('notification', $adminId, 'antifraud.task_flagged', [
                'user_id' => $userId,
                'execution_id' => $executionId,
                'risk_score' => $riskScore,
                'reason' => $reason,
            ]);
        }

        return compact('decision', 'taskScore', 'trustScore', 'riskScore', 'reason', 'payReward', 'giveScore', 'flagReview') + [
            'task_score' => $taskScore,
            'trust_score' => $trustScore,
            'risk_score' => $riskScore,
            'pay_reward' => $payReward,
            'give_score' => $giveScore,
            'flag_review' => $flagReview,
        ];
    }
}
