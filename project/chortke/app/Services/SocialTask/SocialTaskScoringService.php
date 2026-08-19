<?php

declare(strict_types=1);

namespace App\Services\SocialTask;

use App\Services\Settings\AppSettings;

/**
 * SocialTaskScoringService
 *
 * Centralized scoring logic for Social Tasks to ensure consistency 
 * and avoid duplication across behavior and camera services.
 */
class SocialTaskScoringService
{
    private AppSettings $appSettings;

    public function __construct(AppSettings $appSettings) {
        $this->appSettings = $appSettings;
    }

    /**
     * Calculates the behavioral score based on raw signals from the client.
     * 
     * @param array<string, mixed> $signals Raw behavioral metrics (timing, counts, variance)
     * @return int Final behavior score (0-100)
     */
    public function calculateBehaviorScore(array $signals): int
    {
        $score = 50; // Base neutral score

        $rawVariance = $signals['touch_timing_variance'] ?? 50;
        $rawAvgDelay = $signals['avg_action_delay_ms'] ?? 500;
        $rawTapCount = $signals['tap_count'] ?? 0;
        $rawScrollCount = $signals['scroll_count'] ?? 0;

        $variance = is_int($rawVariance) || is_float($rawVariance) || (is_string($rawVariance) && is_numeric($rawVariance))
            ? (float)$rawVariance : 50.0;
        $avgDelay = is_int($rawAvgDelay) || is_float($rawAvgDelay) || (is_string($rawAvgDelay) && is_numeric($rawAvgDelay))
            ? (float)$rawAvgDelay : 500.0;
        $tapCount = is_int($rawTapCount) || (is_string($rawTapCount) && ctype_digit($rawTapCount))
            ? max(0, (int)$rawTapCount) : 0;
        $scrollCount = is_int($rawScrollCount) || (is_string($rawScrollCount) && ctype_digit($rawScrollCount))
            ? max(0, (int)$rawScrollCount) : 0;

        // 1. Timing Variance: Humans have inconsistent timing.
        // Low variance (< 10ms) is a strong bot signal.
        if ($variance < 10) {
            $score -= 30;
        } elseif ($variance > 50) {
            $score += 20;
        }

        // 2. Action Delay: Very fast actions are suspicious.
        if ($avgDelay < 100) {
            $score -= 20;
        } elseif ($avgDelay > 200 && $avgDelay < 800) {
            $score += 10;
        }

        // 3. Interaction Balance: Mixed interaction (taps and scrolls) is more human.
        if ($tapCount > 0 && $scrollCount > 0) {
            $score += 15;
        } elseif ($tapCount === 0 && $scrollCount === 0) {
            $score -= 20;
        }

        return (int) max(0, min(100, $score));
    }

    /**
     * Converts camera verification metrics into a contribution score for the final task grade.
     * 
     * @param int $cameraScore The ML-derived confidence score (0-100)
     * @param array<string, mixed> $verifiedSignals List of specific verified traits (e.g., ['blink', 'smile'])
     * @return int Contribution points (typically 0-100)
     */
    public function calculateCameraContribution(int $cameraScore, array $verifiedSignals = []): int
    {
        // Base contribution is the camera score itself
        $contribution = $cameraScore;

        // Bonus for each successfully verified signal
        $signalBonus = count($verifiedSignals) * int_value($this->appSettings->get('camera_signal_bonus', 5));
        
        $contribution += $signalBonus;

        return (int) max(0, min(100, $contribution));
    }
}
