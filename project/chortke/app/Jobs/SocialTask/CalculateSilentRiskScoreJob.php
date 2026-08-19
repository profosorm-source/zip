<?php

declare(strict_types=1);

namespace App\Jobs\SocialTask;

use App\Services\AntiFraud\IPQualityService;
use App\Services\AntiFraud\SessionAnomalyService;
use App\Models\SocialTaskExecutionModel;

class CalculateSilentRiskScoreJob
{
    public function __construct(
        private IPQualityService $ipService,
        private SessionAnomalyService $sessionService,
        private SocialTaskExecutionModel $model
    ) {}

/**
 * @param array<string, mixed> $context
 * @return array<string, mixed>
 */
public function handle(int $userId, array $context = []): array
    {
        $cacheKey = "risk_score:{$userId}:" . md5((string)json_encode($context));
        $cached = cache()->get($cacheKey);
        if ($cached !== null) {
            if (!is_array($cached)) {
                throw new \UnexpectedValueException('Cached risk score must be an array.');
            }
            return $cached;
        }

        $score = 0;
        $components = [];
        $ip = str_value($context['ip'] ?? '');
        $fingerprint = str_value($context['fingerprint'] ?? $context['device_fingerprint'] ?? '');
        $sessionId = str_value($context['session_id'] ?? '');

        if ($ip !== '') {
            $ipResult = $this->ipService->check($ip);
            $components['ip'] = $ipResult;
            $score += int_value($ipResult['risk_score'] ?? 0) * 0.35;
        }
        if ($sessionId !== '') {
            try {
                $session = $this->sessionService->analyze($userId, $sessionId);
                $components['session'] = $session;
                $score += int_value($session['score'] ?? 0) * 0.25;
            } catch (\Throwable $e) {
            @error_log('[CalculateSilentRiskScoreJob] failed: ' . $e->getMessage());
        }
        }
        if ($fingerprint !== '') {
            $shared = (int)$this->model->getSharedFingerprintUsers($fingerprint, $userId);
            $components['fingerprint'] = ['shared_users' => $shared];
            if ($shared > 0) {
                $score += min(50, $shared * 25);
            }
        }
        if (!empty($context['automation_signals']) || !empty($context['suspicious_signature'])) {
            $components['signature'] = ['suspicious' => true];
            $score += 50;
        }

        $result = [
            'risk_score' => (int)min(100, round($score)),
            'components' => $components,
            'is_high_risk' => $score >= 50,
        ];
        cache()->put($cacheKey, $result, 5);
        return $result;
    }
}
