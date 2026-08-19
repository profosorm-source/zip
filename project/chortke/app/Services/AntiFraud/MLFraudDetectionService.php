<?php

declare(strict_types=1);

namespace App\Services\AntiFraud;

// M-01: Removed AntiFraudModel dependency - uses specific models via composition
use App\Contracts\LoggerInterface;
use App\Models\VelocityAndScoreModel;

/**
 * RuleBasedFraudScoringService  (نام قبلی: MLFraudDetectionService)
 *
 * ── شفاف‌سازی معماری ─────────────────────────────────────────────────────────
 * این سرویس یک سیستم امتیازدهی مبتنی بر قوانین (Rule-Based Weighted Scoring)
 * است، نه machine learning واقعی.
 *
 * آنچه این سرویس انجام می‌دهد:
 *   - استخراج feature از دیتابیس (velocity، anomaly، pattern)
 *   - ضرب هر feature در وزن hardcoded
 *   - جمع امتیازها برای تولید risk score نهایی
 *
 * آنچه این سرویس انجام نمی‌دهد (و نباید ادعا شود):
 *   ✗ Model training / fitting
 *   ✗ Prediction pipeline (gradient boosting, neural net, …)
 *   ✗ Model versioning / A-B testing
 *   ✗ Online learning از feedback
 *
 * نام کلاس عمداً به‌عنوان alias حفظ شده تا dependency injection در
 * Container و سایر کلاس‌ها بدون تغییر کار کند. داخل فایل این کلاس
 * MLFraudDetectionService نام‌گذاری شده اما docblock واقعیت را توضیح می‌دهد.
 *
 * برای ارتقا به ML واقعی در آینده:
 *   → یک MLModelClient جداگانه بسازید که با Python/ONNX/TensorFlow Serving
 *     صحبت کند و این کلاس را به‌عنوان fallback نگه دارید.
 * ─────────────────────────────────────────────────────────────────────────────
 */
class MLFraudDetectionService
{
    // ─── Velocity lookback windows (hours) ────────────────────────────
    private const VELOCITY_WINDOW_1H    = 1;    // پنجره ۱ ساعته
    private const VELOCITY_WINDOW_24H   = 24;   // پنجره ۲۴ ساعته
    private const VELOCITY_WINDOW_7D    = 168;  // پنجره ۷ روزه (= 7 * 24)

    // ─── Velocity suspicious thresholds ──────────────────────────────
    private const VELOCITY_1H_HIGH      = 10;   // بالای این در ۱ ساعت = مشکوک
    private const VELOCITY_1H_MEDIUM    = 5;    // بالای این = نیمه مشکوک

    // ─── Behavior change lookback (days) ─────────────────────────────
    private const BEHAVIOR_RECENT_DAYS      = 7;    // رفتار اخیر
    private const BEHAVIOR_HISTORICAL_DAYS  = 30;   // رفتار تاریخی
    private const BEHAVIOR_MIN_TX_FOR_CHECK = 10;   // حداقل تراکنش برای مقایسه

    // ─── Referrer risk threshold ──────────────────────────────────────
    private const REFERRER_FRAUD_SCORE_HIGH = 70;   // امتیاز تقلب معرف بالای این = ریسک

    private VelocityAndScoreModel $model;
    private const RISK_THRESHOLD_HIGH   = 0.75;
    private const RISK_THRESHOLD_MEDIUM = 0.50;
    private const RISK_THRESHOLD_LOW    = 0.25;

    /**
     * وزن‌های ثابت هر feature در محاسبه امتیاز نهایی.
     *
     * این مقادیر بر اساس تجربه و آنالیز دستی تنظیم شده‌اند، نه از طریق
     * model training. برای تغییر وزن‌ها باید این ثابت‌ها را ویرایش کرد.
     *
     * جمع همه وزن‌ها = 1.0 (تضمین می‌شود risk score بین 0.0 و 1.0 باشد).
     */
    private const WEIGHTS = [
        'transaction_velocity' => 0.25,
        'amount_anomaly'       => 0.20,
        'time_pattern'         => 0.15,
        'device_diversity'     => 0.15,
        'behavior_change'      => 0.15,
        'network_risk'         => 0.10,
    ];
    
    private \App\Contracts\LoggerInterface $logger;
    public function __construct(
        \App\Contracts\LoggerInterface $logger,
        VelocityAndScoreModel $model
    ) {        $this->logger = $logger;

                $this->model = $model;
    }
    
    /**
     * تحلیل اصلی تقلب برای یک کاربر
     */
    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    public function analyzeUser(int $userId, array $context = []): array
    {
        $this->logger->info('ml_fraud.analysis_started', [
            'user_id' => $userId,
            'context' => $context
        ]);
        
        $features = $this->extractFeatures($userId, $context);
        $riskScore = $this->calculateRiskScore($features);
        $riskLevel = $this->determineRiskLevel($riskScore);
        $suspiciousFactors = $this->identifySuspiciousFactors($features);
        
        $this->storePrediction($userId, $riskScore, $features);
        
        $result = [
            'risk_score' => round($riskScore, 4),
            'risk_level' => $riskLevel,
            'factors' => $suspiciousFactors,
            'recommendation' => $this->getRecommendation($riskLevel),
            'features' => $features,
        ];
        
        $this->logger->info('ml_fraud.analysis_completed', [
            'user_id' => $userId,
            'risk_score' => $riskScore,
            'risk_level' => $riskLevel
        ]);
        
        return $result;
    }
    
    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    private function extractFeatures(int $userId, array $context): array
    {
        $features = [];
        
        $features['transaction_velocity'] = $this->calculateTransactionVelocity($userId);
        $txAmount = float_value($context['transaction_amount'] ?? 0.0);
        $features['amount_anomaly'] = $this->detectAmountAnomaly($userId, $txAmount);
        $features['time_pattern'] = $this->analyzeTimePattern($userId);
        $features['device_diversity'] = $this->analyzeDeviceDiversity($userId);
        $features['behavior_change'] = $this->detectBehaviorChange($userId);
        $features['network_risk'] = $this->analyzeNetworkRisk($userId);
        
        return $features;
    }
    
    private function calculateTransactionVelocity(int $userId): float
    {
        $velocityScore = 0.0;
        
        $txn1h = $this->model->getRecentTransactionCount($userId, self::VELOCITY_WINDOW_1H);
        $txn24h = $this->model->getRecentTransactionCount($userId, self::VELOCITY_WINDOW_24H);
        $txn7d = $this->model->getRecentTransactionCount($userId, self::VELOCITY_WINDOW_7D);
        
        $avgDaily = $this->model->getUserAverageDaily($userId);
        
        if ($txn1h > self::VELOCITY_1H_HIGH) {
            $velocityScore += 0.5;
        } elseif ($txn1h > self::VELOCITY_1H_MEDIUM) {
            $velocityScore += 0.3;
        }
        
        if ($avgDaily > 0 && $txn24h > ($avgDaily * 3)) {
            $velocityScore += 0.3;
        }
        
        if ($avgDaily > 0 && ($txn7d / 7) > ($avgDaily * 2)) {
            $velocityScore += 0.2;
        }
        
        return min(1.0, $velocityScore);
    }
    
    private function detectAmountAnomaly(int $userId, float $currentAmount): float
    {
        if ($currentAmount <= 0) {
            return 0.0;
        }
        
        $stats = $this->model->getTransactionAmountStats($userId);
        
        if ($stats['count'] < 5) {
            return 0.1;
        }
        
        $mean = $stats['mean'];
        $stdDev = $stats['std_dev'];
        
        if ($stdDev == 0) {
            return 0.0;
        }
        
        $zScore = abs(($currentAmount - $mean) / $stdDev);
        
        if ($zScore > 3.0) {
            return 0.9;
        } elseif ($zScore > 2.0) {
            return 0.6;
        } elseif ($zScore > 1.5) {
            return 0.3;
        }
        
        return 0.0;
    }
    
    private function analyzeTimePattern(int $userId): float
    {
        $hourlyActivity = $this->model->getHourlyActivity($userId);
        
        $suspicionScore = 0.0;
        $totalActivity = array_sum($hourlyActivity);
        
        if ($totalActivity == 0) {
            return 0.0;
        }
        
        $lateNightActivity = 0;
        for ($h = 2; $h <= 6; $h++) {
            $lateNightActivity += $hourlyActivity[$h] ?? 0;
        }
        
        $lateNightRatio = $lateNightActivity / $totalActivity;
        
        if ($lateNightRatio > 0.4) {
            $suspicionScore = 0.7;
        } elseif ($lateNightRatio > 0.2) {
            $suspicionScore = 0.4;
        }
        
        return $suspicionScore;
    }
    
    private function analyzeDeviceDiversity(int $userId): float
    {
        $deviceCount = $this->model->getDeviceCount($userId);
        
        if ($deviceCount > 5) {
            return 0.8;
        } elseif ($deviceCount > 3) {
            return 0.5;
        }
        
        return 0.0;
    }
    
    private function detectBehaviorChange(int $userId): float
    {
        $recentBehavior = $this->model->getBehaviorMetrics($userId, self::BEHAVIOR_RECENT_DAYS);
        $historicalBehavior = $this->model->getBehaviorMetrics($userId, self::BEHAVIOR_HISTORICAL_DAYS, self::BEHAVIOR_RECENT_DAYS);
        
        if ($historicalBehavior['transaction_count'] < self::BEHAVIOR_MIN_TX_FOR_CHECK) {
            return 0.0;
        }
        
        $changeScore = 0.0;
        
        if ($historicalBehavior['avg_amount'] > 0) {
            $amountChange = abs(
                ($recentBehavior['avg_amount'] - $historicalBehavior['avg_amount']) 
                / $historicalBehavior['avg_amount']
            );
            
            if ($amountChange > 2.0) {
                $changeScore += 0.4;
            } elseif ($amountChange > 1.0) {
                $changeScore += 0.2;
            }
        }
        
        $recentFrequency = $recentBehavior['transaction_count'] / self::BEHAVIOR_RECENT_DAYS;
        $historicalFrequency = $historicalBehavior['transaction_count'] / self::BEHAVIOR_HISTORICAL_DAYS;
        
        if ($historicalFrequency > 0) {
            $frequencyChange = abs(
                ($recentFrequency - $historicalFrequency) / $historicalFrequency
            );
            
            if ($frequencyChange > 3.0) {
                $changeScore += 0.4;
            } elseif ($frequencyChange > 1.5) {
                $changeScore += 0.2;
            }
        }
        
        return min(1.0, $changeScore);
    }
    
    private function analyzeNetworkRisk(int $userId): float
    {
        $userInfo = $this->model->getUserAndReferrerInfo($userId);
        
        $networkScore = 0.0;
        
        if ($userInfo && $userInfo->referred_by) {
            if ($userInfo->referrer_is_blacklisted) {
                $networkScore += 0.6;
            }
            
            if ($userInfo->referrer_fraud_score > self::REFERRER_FRAUD_SCORE_HIGH) {
                $networkScore += 0.3;
            }
        }
        
        $sharedIPScore = $this->analyzeSharedIP($userId);
        $networkScore += $sharedIPScore * 0.4;
        
        return min(1.0, $networkScore);
    }
    
    private function analyzeSharedIP(int $userId): float
    {
        $ipData = $this->model->getSharedIPData($userId);
        
        $suspicionScore = 0.0;
        
        foreach ($ipData as $ip) {
            if ($ip->user_count > 5) {
                $suspicionScore += 0.4;
            }
            
            if ($ip->suspicious_users > 0) {
                $suspicionScore += 0.5;
            }
        }
        
        return min(1.0, $suspicionScore);
    }
    
    /** @param array<string, mixed> $features */
    private function calculateRiskScore(array $features): float
    {
        $totalScore = 0.0;
        
        foreach (self::WEIGHTS as $feature => $weight) {
            $totalScore += ($features[$feature] ?? 0.0) * $weight;
        }
        
        return min(1.0, max(0.0, $totalScore));
    }
    
    private function determineRiskLevel(float $score): string
    {
        if ($score >= self::RISK_THRESHOLD_HIGH) {
            return 'high';
        } elseif ($score >= self::RISK_THRESHOLD_MEDIUM) {
            return 'medium';
        } elseif ($score >= self::RISK_THRESHOLD_LOW) {
            return 'low';
        }
        
        return 'safe';
    }
    
    /**
     * @param array<string, mixed> $features
     * @return array<string, mixed>
     */
    /**
     * @param array<string, mixed> $features
     * @return array<int, array<string, mixed>>
     */
    private function identifySuspiciousFactors(array $features): array
    {
        $suspicious = [];
        
        foreach ((array)$features as $feature => $score) {
            $scoreVal = float_value($score);
            if ($scoreVal > 0.5) {
                $suspicious[] = [
                    'factor' => (string)$feature,
                    'score' => round($scoreVal, 2),
                    'severity' => $scoreVal > 0.75 ? 'high' : 'medium'
                ];
            }
        }
        
        return $suspicious;
    }
    
    private function getRecommendation(string $riskLevel): string
    {
        return match($riskLevel) {
            'high' => 'block_transaction',
            'medium' => 'manual_review',
            'low' => 'monitor',
            default => 'allow'
        };
    }
    
    /** @param array<string, mixed> $features */
    private function storePrediction(int $userId, float $riskScore, array $features): void
    {
        try {
            $this->model->storePrediction($userId, $riskScore, $features);
        } catch (\Exception $e) {
            $this->logger->warning('ml_fraud.store_prediction_failed', [
                'error' => $e->getMessage()
            ]);
            \App\Services\Sentry\SentryExceptionHandler::captureException($e, null, ['operation' => 'antifraud.ml.storePrediction']);
        }
    }
    
    /**
     * ثبت نتیجه واقعی برای بهبود آینده قوانین (نه ML training).
     *
     * این feedback در جدول ذخیره می‌شود تا تیم بتواند با آنالیز دستی
     * وزن‌های WEIGHTS را بهبود دهد. این یک feedback loop دستی است،
     * نه online learning خودکار.
     */
    public function provideFeedback(int $userId, string $actualOutcome): void
    {
        $this->model->updatePredictionFeedback($userId, $actualOutcome);

        $this->logger->info('fraud_scoring.feedback_received', [
            'user_id' => $userId,
            'outcome' => $actualOutcome,
            'note'    => 'stored for manual weight-tuning review',
        ]);
    }
}

