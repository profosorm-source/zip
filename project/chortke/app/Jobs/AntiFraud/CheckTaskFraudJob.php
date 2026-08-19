<?php

declare(strict_types=1);

namespace App\Jobs\AntiFraud;

use App\Services\AntiFraud\IPQualityService;
use App\Services\AntiFraud\SessionAnomalyService;
use App\Services\AntiFraud\VelocityCheckService;
use App\Services\AntiFraud\BehavioralBiometricsService;
use App\Services\AntiFraud\SeoFraudDetector;
use App\Services\FeatureFlagService;
use App\Services\SocialTask\SilentAntiFraudService;

class CheckTaskFraudJob
{
    public function __construct(
        private IPQualityService $ipQuality,
        private SessionAnomalyService $sessionAnomaly,
        private VelocityCheckService $velocity,
        private BehavioralBiometricsService $biometrics,
        private SeoFraudDetector $seoDetector,
        private SilentAntiFraudService $silentAntiFraud,
        private FeatureFlagService $featureFlag
    ) {}

/**
 * @param array<string, mixed> $context
 * @return array<string, mixed>
 */
public function handle(int $userId, string $action, array $context): array
    {
        $results = [];
        $ip = str_value($context['ip'] ?? $this->clientIp());
        $sessionId = str_value($context['session_id'] ?? session_id());

        switch ($action) {
            case 'task.custom':
                $results['ip_quality'] = $this->ipQuality->check($ip);
                if ($sessionId) {
                    try { $results['session'] = $this->sessionAnomaly->analyze($userId, $sessionId); } catch (\Throwable $e) {
            @error_log('[CheckTaskFraudJob] failed: ' . $e->getMessage());
        }
                }
                $results['velocity'] = $this->velocity->check($userId, 'task_execution', $context);
                if (!$this->skipHeavyChecks($userId) && !empty($context['biometric_data'])) {
                    $results['biometrics'] = $this->biometrics->comprehensiveAnalysis($userId, is_array($context['biometric_data']) ? $context['biometric_data'] : []);
                }
                if (!empty($context['suspicious_signature'])) {
                    $results['signature'] = ['allowed' => false, 'reason' => 'suspicious_signature'];
                }
                break;

            case 'task.social':
                $results['silent_fraud'] = $this->silentAntiFraud->calculateRiskScore($userId, $context);
                if (($results['silent_fraud']['risk_score'] ?? 0) >= 80) {
                    $results['decision'] = ['allowed' => false, 'reason' => 'high_silent_fraud_risk'];
                }
                if (!empty($context['suspicious_signature'])) {
                    $results['signature'] = ['allowed' => false, 'reason' => 'suspicious_signature'];
                }
                break;

            case 'task.seo':
                $adId = int_value($context['ad_id'] ?? 0);
                $engagementData = $context['engagement_data'] ?? null;
                if (!is_array($engagementData)) {
                    throw new \InvalidArgumentException('SEO engagement_data must be an array.');
                }
                $seoFraud = $this->seoDetector->detect($userId, $adId, $engagementData);
                $this->assertSeoFraudResult($seoFraud);
                $results['seo_fraud'] = $seoFraud;
                if ($sessionId) {
                    try { $results['session'] = $this->sessionAnomaly->analyze($userId, $sessionId); } catch (\Throwable $e) {
            @error_log('[CheckTaskFraudJob] failed: ' . $e->getMessage());
        }
                }
                break;
        }

        return $results;
    }

    /** @param array<string, mixed> $result */
    private function assertSeoFraudResult(array $result): void
    {
        if (!is_bool($result['is_fraud'] ?? null) || !is_int($result['risk_score'] ?? null)) {
            throw new \UnexpectedValueException('SeoFraudDetector must return boolean is_fraud and integer risk_score.');
        }
        $flags = $result['flags'] ?? null;
        if (!is_array($flags) || array_filter($flags, 'is_string') !== $flags) {
            throw new \UnexpectedValueException('SeoFraudDetector flags must be a list of strings.');
        }
    }

    private function skipHeavyChecks(?int $userId): bool
    {
        try {
            return (bool)$this->featureFlag->isEnabled('anti_fraud.heavy_checks_disabled', $userId);
        } catch (\Throwable $e) {
            return false;
        }
    }

    private function clientIp(): string
    {
        return get_client_ip();
    }
}
