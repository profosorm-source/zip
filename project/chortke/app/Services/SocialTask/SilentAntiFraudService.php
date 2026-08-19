<?php

declare(strict_types=1);

namespace App\Services\SocialTask;

use App\Services\AntiFraud\IPQualityService;
use App\Services\AntiFraud\BrowserFingerprintService;
use App\Services\AntiFraud\SessionAnomalyService;
use App\Services\AuditTrail;
use App\Contracts\NotificationServiceInterface;
use App\Services\Settings\AppSettings;
use App\Services\Gamification\TrustService;
use App\Enums\ModuleContext;
use App\Services\AntiFraud\TaskExecutionEvaluatorService;
use App\Services\User\UserService;

use App\Contracts\LoggerInterface;
/**
 * SilentAntiFraudService
 *
 * تصمیم‌گیری نامحسوس (Silent Anti-Fraud)
 */
class SilentAntiFraudService
{
    private const DEFAULT_RESTRICTION_LEVELS = [
        'high'   => ['task_ratio' => 0.10, 'reward_ratio' => 0.50],
        'medium' => ['task_ratio' => 0.30, 'reward_ratio' => 0.70],
        'low'    => ['task_ratio' => 0.60, 'reward_ratio' => 0.90],
        'clean'  => ['task_ratio' => 1.00, 'reward_ratio' => 1.00],
    ];

    private TrustService $trustService;
    private UserService $userService;
    private AppSettings $appSettings;

    #[\Core\Attributes\Inject]
    private \Core\Container $container;

    public function __construct(
        TrustService $trustService,
        UserService $userService,
        AppSettings $appSettings,
        private ?\App\Jobs\SocialTask\CalculateSilentRiskScoreJob $calculateRiskScoreJob = null,
        private ?\App\Jobs\SocialTask\DecideSilentAntiFraudJob $decideJob = null,
    ) {        $this->trustService = $trustService;
        $this->userService = $userService;
        $this->appSettings = $appSettings;
        $this->container = \Core\Container::getInstance();
    }

    /**
     * Risk Score ترکیبی
     */
    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    public function calculateRiskScore(int $userId, array $context = []): array
    {
        $job = $this->calculateRiskScoreJob ?? $this->container->make(\App\Jobs\SocialTask\CalculateSilentRiskScoreJob::class);
        return $job->handle($userId, $context);
    }


    /**
     * @param array<string, mixed> $riskResult
     * @return array<string, mixed>
     */
    public function decide(
        int   $userId,
        int   $executionId,
        float $taskScore,
        array $riskResult
    ): array {
        $job = $this->decideJob ?? $this->container->make(\App\Jobs\SocialTask\DecideSilentAntiFraudJob::class);
        return $job->handle($userId, $executionId, $taskScore, $riskResult);
    }


    /** @return array<string, mixed> */
    public function getRestrictionLevel(int $userId): array
    {
        $userObj = $this->userService->findById($userId);
        $trustScore = $userObj ? $this->trustService->getTrustScore($userObj, ModuleContext::SOCIAL_TASKS) : 50.0;

        // LOW-11: Scale static hardcoded boundaries into injectable dynamic thresholds
        $highLimit   = int_value($this->appSettings->get('restriction_trust_limit_high', 20));
        $mediumLimit = int_value($this->appSettings->get('restriction_trust_limit_medium', 40));
        $lowLimit    = int_value($this->appSettings->get('restriction_trust_limit_low', 60));

        if ($trustScore < $highLimit) {
            $level = 'high';
        } elseif ($trustScore < $mediumLimit) {
            $level = 'medium';
        } elseif ($trustScore < $lowLimit) {
            $level = 'low';
        } else {
            $level = 'clean';
        }

        $levelsRaw = $this->appSettings->get('antifraud_restriction_levels', self::DEFAULT_RESTRICTION_LEVELS);
        $levels = is_array($levelsRaw) ? $levelsRaw : self::DEFAULT_RESTRICTION_LEVELS;
        $selectedRaw = $levels[$level] ?? $levels['clean'] ?? self::DEFAULT_RESTRICTION_LEVELS['clean'];
        $selected = is_array($selectedRaw) ? $selectedRaw : self::DEFAULT_RESTRICTION_LEVELS['clean'];

        return array_merge(
            ['level' => $level, 'trust_score' => $trustScore],
            $selected
        );
    }

    public function filterTaskCount(int $userId, int $available): int
    {
        $restriction = $this->getRestrictionLevel($userId);
        return (int)ceil($available * $restriction['task_ratio']);
    }

    public function adjustedReward(int $userId, string $originalReward): string
    {
        $restriction = $this->getRestrictionLevel($userId);
        $money = \Core\ValueObjects\Money::of($originalReward, 'IRT');
        $percent = bcmul(str_value($restriction['reward_ratio']), '100', 4);
        return $money->percentage($percent)->getAmount();
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function scoreExecution(object $exec, array $payload): array
    {
        // 🛡️ Inline scoring — previously delegated to a broken Job that has been removed.
        // This method is available but has 0 callers; wire it when silent anti-fraud scoring is needed.
        $userId = (int)($exec->user_id ?? $payload['user_id'] ?? 0);
        $score  = $this->calculateRiskScore($userId, $payload);
        $modifier = $this->getTrustModifier($userId);

        return [
            'success'      => true,
            'risk_score'   => $score['risk_score'] ?? 0,
            'risk_level'   => $score['risk_level'] ?? 'low',
            'trust_modifier' => $modifier,
            'user_id'      => $userId,
            'execution_id' => (int)($exec->id ?? 0),
        ];
    }


    private function getTrustModifier(int $userId): float
    {
        $userObj = $this->userService->findById($userId);
        $trust = $userObj ? $this->trustService->getTrustScore($userObj, ModuleContext::SOCIAL_TASKS) : 50.0;

        $threshHigh = float_value($this->appSettings->get('trust_thresh_high', 80.0));
        $threshMed  = float_value($this->appSettings->get('trust_thresh_med', 60.0));
        $threshLow  = float_value($this->appSettings->get('trust_thresh_low', 40.0));
        $threshCrit = float_value($this->appSettings->get('trust_thresh_crit', 20.0));

        $modHigh     = float_value($this->appSettings->get('trust_mod_high', 10.0));
        $modMed      = float_value($this->appSettings->get('trust_mod_med', 5.0));
        $modLow      = float_value($this->appSettings->get('trust_mod_low', 0.0));
        $modCrit     = float_value($this->appSettings->get('trust_mod_crit', -5.0));
        $modVeryCrit = float_value($this->appSettings->get('trust_mod_verycrit', -10.0));

        if ($trust >= $threshHigh) return $modHigh;
        if ($trust >= $threshMed)  return $modMed;
        if ($trust >= $threshLow)  return $modLow;
        if ($trust >= $threshCrit) return $modCrit;
        
        return $modVeryCrit;
    }

    /**
     * @param array<string, mixed> $score
     * @return array<string, mixed>
     */
    public function decisionFromScore(array $score): array
    {
        // Simple mapping for now, can be expanded
        return [
            'decision' => $score['task_score'] >= 40 ? 'approve' : 'reject',
            'pay_reward' => $score['task_score'] >= 40,
        ];
    }
}

