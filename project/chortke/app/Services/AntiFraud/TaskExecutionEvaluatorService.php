<?php

declare(strict_types=1);

namespace App\Services\AntiFraud;


use App\Contracts\LoggerInterface;
use App\Services\Settings\AppSettings;

/**
 * TaskExecutionEvaluatorService
 *
 * ارزیابی کیفیت و ریسک رفتار کاربر در زمان اجرای تسک (AntiFraud).
 */
class TaskExecutionEvaluatorService
{
    // ─── Micro-behavior action delay thresholds (ms) ─────────────────
    private const DELAY_BOT_MAX_MS          = 50;   // زیر این = ربات (بدون تأخیر)
    private const DELAY_FAST_MAX_MS         = 150;  // زیر این = خیلی سریع
    private const DELAY_NORMAL_MAX_MS       = 300;  // زیر این = معمولی
    private const DELAY_HESITANT_MIN_MS     = 500;  // بالای این = با تردید

    // ─── Scroll behavior thresholds ───────────────────────────────────
    // Unused constants removed - no longer needed
    private const FOCUS_OUT_SUSPICIOUS_SECS = 10;   // خروج از focus بیش از این = مشکوک

    // ─── Camera score thresholds ─────────────────────────────────────
    private const CAMERA_SCORE_EXCELLENT    = 80;   // تأیید عالی
    private const CAMERA_SCORE_GOOD         = 60;   // تأیید خوب
    private const CAMERA_SCORE_MINIMAL      = 40;   // تأیید ضعیف

    // ─── Touch/tap behavior ───────────────────────────────────────────
    private const TOUCH_VARIANCE_BOT_MAX    = 5;    // واریانس زیر این = tap ربات
    private const TAP_MIN_FOR_BOT_CHECK     = 10;   // حداقل tap برای چک ربات

    // ─── Penalty constants ────────────────────────────────────────────
    private const PENALTY_NO_INTERACTION    = -40;
    private const PENALTY_TOO_FAST          = -30;
    private const PENALTY_BOT_PATTERN       = -20;
    private const TOO_FAST_RATIO            = 0.15; // زیر ۱۵٪ زمان انتظار = خیلی سریع

    // ─── Trust modifier bounds ────────────────────────────────────────
    private const TRUST_MODIFIER_MIN        = -10;
    private const TRUST_MODIFIER_MAX        = 10;

    private AppSettings $appSettings;
    public function __construct(
        AppSettings $appSettings
    ) {        $this->appSettings = $appSettings;

            }

    /**
     * محاسبه Task Score کامل
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function calculate(array $data): array
    {
        $timeScore = $this->calculateTimeScore(
            int_value($data['active_time'] ?? 0),
            int_value($data['expected_time'] ?? 60));
        $interactionScore = $this->calculateInteractionScore(
            (array)($data['interactions'] ?? [])
        );
        $behaviorScore = $this->calculateBehaviorScore(
            (array)($data['behavior_signals'] ?? [])
        );
        $trustModifier = $this->clamp(float_value($data['trust_modifier'] ?? 0), self::TRUST_MODIFIER_MIN, self::TRUST_MODIFIER_MAX);

        $penalties = $this->calculatePenalties($data, $interactionScore);
        $penaltySum = array_sum(array_column($penalties, 'value'));

        $cameraBonus = 0;
        $signals = (array)($data['behavior_signals'] ?? []);
        if (!empty($signals['camera_verified'])) {
            $cScore = int_value($signals['camera_score'] ?? 0);
            $cSignals = (array)($signals['camera_signals'] ?? []);
            $cameraBonus = $this->calculateCameraContribution($cScore, $cSignals);
        }

        // MED-13: Move hardcoded contribution weights to configurable admin settings
        $weightTime = float_value($this->appSettings->get('scoring_weight_time', 0.30));
        $weightInteraction = float_value($this->appSettings->get('scoring_weight_interaction', 0.25));
        $weightBehavior = float_value($this->appSettings->get('scoring_weight_behavior', 0.20));

        $rawScore = ($timeScore * $weightTime)
            + ($interactionScore * $weightInteraction)
            + ($behaviorScore * $weightBehavior)
            + $trustModifier
            + $cameraBonus
            + $penaltySum;

        $taskScore = $this->clamp($rawScore, 0, 100);

        return [
            'task_score'        => round($taskScore, 1),
            'time_score'        => $timeScore,
            'interaction_score' => $interactionScore,
            'behavior_score'    => $behaviorScore,
            'trust_modifier'    => $trustModifier,
            'penalties'         => $penalties,
            'camera_bonus'      => $cameraBonus,
            'breakdown'         => [
                'time_contribution'        => round($timeScore * $weightTime, 1),
                'interaction_contribution' => round($interactionScore * $weightInteraction, 1),
                'behavior_contribution'    => round($behaviorScore * $weightBehavior, 1),
                'camera_contribution'      => $cameraBonus,
            ],
        ];
    }

    public function calculateTimeScore(int $activeTime, int $expectedTime): int
    {
        if ($expectedTime <= 0) return 0;
        $ratio = $activeTime / $expectedTime;

        // MED-12: Replace unfair discrete steps with fair, linear interpolation maps
        if ($ratio >= 1.0)  return 100;
        if ($ratio <= 0.10) return 10;

        // Maps ratios 0.10 to 1.00 uniformly to score range 10 to 100
        $score = 10 + (($ratio - 0.10) / 0.90) * 90;
        return (int)round($score);
    }

    /** @param array<int|string, mixed> $interactions */
    public function calculateInteractionScore(array $interactions): int
    {
        $types = array_unique($interactions);
        $count = count($types);
        $hasScroll = in_array('scroll', $types, true);
        $hasClick  = in_array('click', $types, true);
        $hasTap    = in_array('tap', $types, true);

        if ($hasScroll && $hasClick && $hasTap) return 25;
        if ($count >= 2) return 20;
        if ($count === 1) return 10;
        return 0;
    }

    /** @param array<int|string, mixed> $signals */
    public function calculateBehaviorScore(array $signals): int
    {
        $touch   = $this->scoreTouchBehavior($signals);
        $scroll  = $this->scoreScrollBehavior($signals);
        $session = $this->scoreSessionIntegrity($signals);
        $focus   = $this->scoreFocusBehavior($signals);
        $micro   = $this->scoreMicroBehavior($signals);

        return min(100, $touch + $scroll + $session + $focus + $micro);
    }

    /** @param array<int|string, mixed> $s */
    public function scoreTouchBehavior(array $s): int
    {
        $tapCount   = int_value($s['tap_count'] ?? 0);
        $swipeCount = int_value($s['swipe_count'] ?? 0);
        $pauseCount = int_value($s['touch_pauses'] ?? 0);
        $variance   = float_value($s['touch_timing_variance'] ?? 0);

        if ($variance < 5 && $tapCount > 5) return 0;
        if ($swipeCount === 0 && $pauseCount === 0) return 5;
        if ($swipeCount > 0 && $pauseCount === 0)  return 10;
        if ($swipeCount > 0 && $pauseCount > 0 && $variance < 50)  return 15;
        if ($swipeCount > 0 && $pauseCount > 2 && $variance >= 50) return 20;
        return 10;
    }

    /** @param array<int|string, mixed> $s */
    public function scoreScrollBehavior(array $s): int
    {
        $scrollCount    = int_value($s['scroll_count'] ?? 0);
        $scrollVariance = float_value($s['scroll_speed_variance'] ?? 0);
        $scrollPauses   = int_value($s['scroll_pauses'] ?? 0);

        if ($scrollCount === 0) return 0;
        if ($scrollVariance < 5) return 5;
        if ($scrollVariance < 20 && $scrollPauses === 0) return 10;
        if ($scrollVariance >= 20 && $scrollPauses === 0) return 15;
        if ($scrollVariance >= 20 && $scrollPauses > 0) return 20;
        return 10;
    }

    /** @param array<int|string, mixed> $s */
    public function scoreSessionIntegrity(array $s): int
    {
        $totalTime  = int_value($s['session_duration'] ?? 0);
        $activeTime = int_value($s['active_time'] ?? 0);
        $reconnects = int_value($s['reconnect_count'] ?? 0);

        if ($totalTime <= 0) return 0;
        $activeRatio = $activeTime / $totalTime;

        if ($reconnects > 3) return 5;
        if ($activeRatio < 0.40) return 10;
        if ($activeRatio < 0.70) return 15;
        if ($activeRatio >= 0.70) return 20;
        return 10;
    }

    /** @param array<int|string, mixed> $s */
    public function scoreFocusBehavior(array $s): int
    {
        $outFocusCount   = int_value($s['app_blur_count'] ?? 0);
        $maxOutFocusSecs = int_value($s['max_blur_duration'] ?? 0);

        if ($outFocusCount === 0) return 20;
        if ($maxOutFocusSecs < 3 && $outFocusCount <= 2) return 15;
        if ($outFocusCount <= 4) return 10;
        if ($maxOutFocusSecs > self::FOCUS_OUT_SUSPICIOUS_SECS) return 5;
        return 0;
    }

    /** @param array<int|string, mixed> $s */
    public function scoreMicroBehavior(array $s): int
    {
        $hesitationCount = int_value($s['hesitation_count'] ?? 0);
        $avgActionDelay  = float_value($s['avg_action_delay_ms'] ?? 0);
        $naturalDelays   = int_value($s['natural_delay_count'] ?? 0);

        if ($avgActionDelay < self::DELAY_BOT_MAX_MS && $hesitationCount === 0) return 0;
        if ($avgActionDelay < self::DELAY_FAST_MAX_MS) return 5;
        if ($avgActionDelay < self::DELAY_NORMAL_MAX_MS && $naturalDelays === 0) return 10;
        if ($avgActionDelay >= self::DELAY_NORMAL_MAX_MS && $hesitationCount > 0) return 15;
        if ($avgActionDelay >= self::DELAY_HESITANT_MIN_MS && $hesitationCount > 2) return 20;
        return 10;
    }

    /**
     * @param array<string, mixed> $data
     * @return array<int, array<string, mixed>>
     */
    public function calculatePenalties(array $data, int $interactionScore): array
    {
        $penalties = [];
        if ($interactionScore === 0) {
            $penalties[] = ['rule' => 'no_interaction', 'value' => self::PENALTY_NO_INTERACTION, 'reason' => 'هیچ interaction ثبت نشد'];
        }
        $activeTime = int_value($data['active_time'] ?? 0);
        $expectedTime = int_value($data['expected_time'] ?? 60);
        if ($expectedTime > 0 && $activeTime < ($expectedTime * self::TOO_FAST_RATIO)) {
            $penalties[] = ['rule' => 'too_fast', 'value' => self::PENALTY_TOO_FAST, 'reason' => 'زمان انجام خیلی کوتاه'];
        }
        $signals = is_array($data['behavior_signals'] ?? null) ? $data['behavior_signals'] : [];
        $touchVariance = float_value($signals['touch_timing_variance'] ?? 999);
        $tapCount = int_value($signals['tap_count'] ?? 0);
        if ($touchVariance < self::TOUCH_VARIANCE_BOT_MAX && $tapCount > self::TAP_MIN_FOR_BOT_CHECK) {
            $penalties[] = ['rule' => 'bot_pattern', 'value' => self::PENALTY_BOT_PATTERN, 'reason' => 'الگوی حرکات رباتی'];
        }
        return $penalties;
    }

    public function riskModifier(int $riskScore): int
    {
        if ($riskScore < 20) return 5;
        if ($riskScore <= 50) return 0;
        return -10;
    }

    // MED-14: Converted to public to serve as the unified DRY scoring hook for camera evaluations
    /** @param array<string, mixed> $verifiedSignals */
    public function calculateCameraContribution(int $cameraScore, array $verifiedSignals = []): int
    {
        $base = 0;
        if ($cameraScore >= self::CAMERA_SCORE_EXCELLENT) $base = 15;
        elseif ($cameraScore >= self::CAMERA_SCORE_GOOD) $base = 8;
        elseif ($cameraScore >= self::CAMERA_SCORE_MINIMAL) $base = 2;
        else $base = -10;

        $bonus = 0;
        $highValueSignals = ['follow_button_visible', 'username_match', 'subscribe_confirmed', 'like_button_active'];
        foreach ($highValueSignals as $sig) {
            if (in_array($sig, $verifiedSignals, true)) $bonus += 3;
        }

        return $base + min($bonus, 10);
    }

    private function clamp(float $val, float $min, float $max): float
    {
        return max($min, min($max, $val));
    }
}

