<?php

declare(strict_types=1);

namespace App\Services\AntiFraud;

use App\Models\VelocityAndScoreModel;
use App\Services\AntiFraud\RiskPolicyService;
use App\Contracts\LoggerInterface;
use Core\EventDispatcher; // 🚀 UPG-05: واردسازی ابزار دیسپچر رویدادها
use App\Events\FraudScoreUpdatedEvent; // 🚀 UPG-05: واردسازی رویداد تغییر امتیاز فراد

/**
 * FraudDetectionService - سیستم تشخیص تقلب پیشرفته
 *
 * محاسبه امتیاز تقلب بر اساس:
 * - سن حساب کاربری
 * - امتیاز شهرت
 * - سرعت تراکنش‌ها
 * - ناهنجاری‌های جغرافیایی
 *
 * اقدامات خودکار:
 * - امتیاز > 50: پرچم برای بررسی
 * - امتیاز > 70: نیاز به KYC
 * - امتیاز > 85: بررسی دستی
 * - امتیاز > 95: تعلیق حساب
 */
class FraudDetectionService
{
    /**
     * Centralized toObject (root-cause normalization for DB results).
     * Guarantees object (never array/mixed) before any ->prop access.
     */
    private function toObject(mixed $data): ?object
    {
        if ($data === null || $data === false) return null;
        if (is_object($data)) return $data;
        if (is_array($data)) return (object)$data;
        return (object)(array)$data;
    }


    private VelocityAndScoreModel $fraudModel;
    private RiskPolicyService $policy;

    // آستانه‌های پیش‌فرض (fallback)
    private const RISK_THRESHOLDS = [
        'flag'     => 50,
        'kyc'      => 70,
        'review'   => 85,
        'suspend'  => 95
    ];

    // وزن‌های پیش‌فرض (fallback)
    private const WEIGHTS = [
        'account_age' => 0.2,
        'reputation'  => 0.3,
        'velocity'    => 0.3,
        'geographic'  => 0.2
    ];

    // تنظیمات پیش‌فرض سرعت تراکنش‌ها (Velocity Fallback)
    private const VELOCITY_DEFAULTS = [
        'daily_high'   => 10,
        'daily_medium' => 5,
        'weekly_high'  => 50,
        'weekly_medium'=> 20,
        'spike_ratio'  => 2.0,
        'spike_min'    => 10,
    ];

    // ─── Account Age thresholds (days) ───────────────────────────────
    private const ACCOUNT_AGE_NEW_USER_DAYS      = 1;    // زیر این = حساب خیلی جدید
    private const ACCOUNT_AGE_YOUNG_DAYS         = 7;    // زیر این = حساب جدید
    private const ACCOUNT_AGE_MEDIUM_DAYS        = 30;   // زیر این = نیمه جدید
    private const ACCOUNT_AGE_MATURE_DAYS        = 90;   // زیر این = نسبتاً قدیمی
    private const ACCOUNT_AGE_FALLBACK_DAYS      = 365;  // مقدار پیش‌فرض در صورت خطا

    // ─── Reputation thresholds ────────────────────────────────────────
    private const REPUTATION_LOW_THRESHOLD       = 10;   // زیر این = شهرت پایین
    private const REPUTATION_MEDIUM_THRESHOLD    = 50;   // زیر این = شهرت متوسط
    private const REPUTATION_HIGH_THRESHOLD      = 100;  // زیر این = شهرت بالا

    // MED-06: وزن‌های پویا از RiskPolicyService
    /** @var array<string, mixed> */
    private array $thresholds;
    /** @var array<string, mixed> */
    private array $weights;
    /** @var array<string, mixed> */
    private array $velocitySettings;
// 🚀 UPG-05

    private \App\Contracts\LoggerInterface $logger;
    private ?\App\Contracts\OutboxServiceInterface $outbox = null;
    private ?\App\Services\AuditTrail $auditTrail = null;
    private \Core\Database $db;

    public function __construct(
        \App\Contracts\LoggerInterface $logger,
        VelocityAndScoreModel $fraudModel,
        RiskPolicyService $policy,
        \Core\Database $db,
        ?\App\Contracts\OutboxServiceInterface $outbox = null,
        ?\App\Services\AuditTrail $auditTrail = null
    ) {
        $this->logger = $logger;
        $this->db = $db;

        
        $this->fraudModel = $fraudModel;
        // 🚀 UPG-05
        $this->policy = $policy;
        $this->outbox = $outbox;
        $this->auditTrail = $auditTrail;
        
        // 🛡️ Strict Fallback Merging & Intersecting: Ensures ONLY standard required keys exist, excluding extraneous remote data.
        $this->thresholds = $this->normalizeThresholds(array_intersect_key(
            array_merge(
                self::RISK_THRESHOLDS, 
                $this->policy->getArray('fraud', 'risk_thresholds', self::RISK_THRESHOLDS)
            ),
            self::RISK_THRESHOLDS
        ));
        
        $this->weights = array_intersect_key(
            array_merge(
                self::WEIGHTS, 
                $this->policy->getArray('fraud', 'score_weights', self::WEIGHTS)
            ),
            self::WEIGHTS
        );

        // M-SRV-03 Fix: نرمال‌سازی ریاضی وزن‌ها جهت ممانعت از خزش امتیاز (Score Drift) و تضمین ریاضی سقف امتیاز ۱۰۰
        $totalWeight = array_sum($this->weights);
        if ($totalWeight > 0 && abs($totalWeight - 1.0) > 0.0001) {
            foreach ($this->weights as $key => $w) {
                $this->weights[$key] = $w / $totalWeight;
        $this->outbox = $outbox;
            }
            $this->logger->warning('fraud.weights.auto_normalized', [
                'original_sum' => $totalWeight,
                'normalized_weights' => $this->weights
            ]);
        }

        $this->velocitySettings = array_intersect_key(
            array_merge(
                self::VELOCITY_DEFAULTS,
                $this->policy->getArray('fraud', 'velocity_settings', self::VELOCITY_DEFAULTS)
            ),
            self::VELOCITY_DEFAULTS
        );
    }

    /**
     * محاسبه امتیاز تقلب برای کاربر
     */
    public function calculateFraudScore(int $userId): int
    {
        try {
            $factors = $this->gatherRiskFactors($userId);
        } catch (\Throwable $e) {
            $this->logger->error('fraud.gather_risk_factors_failed', [
                'user_id' => $userId,
                'error' => $e->getMessage()
            ]);
            \App\Services\Sentry\SentryExceptionHandler::captureException($e, $userId, [
                'operation' => 'fraud.gather_risk_factors',
            ]);
            $factors = [
                'account_age' => self::ACCOUNT_AGE_FALLBACK_DAYS,
                'reputation'  => 0,
                'velocity'    => ['daily' => 0, 'weekly' => 0, 'prev_weekly' => 0],
                'geographic'  => ['country_changes' => 0, 'city_changes' => 0, 'suspicious_ips' => 0],
            ];
        }

        $score = 0;
        $score += $this->calculateAccountAgeFactor(int_value($factors['account_age'])) * $this->weights['account_age'];
        $score += $this->calculateReputationFactor(int_value($factors['reputation'])) * $this->weights['reputation'];
        $score += $this->calculateVelocityFactor(is_array($factors['velocity'] ?? null) ? $factors['velocity'] : []) * $this->weights['velocity'];
        $score += $this->calculateGeographicFactor(is_array($factors['geographic'] ?? null) ? $factors['geographic'] : []) * $this->weights['geographic'];

        $finalScore = (int) min(100, max(0, round($score)));

        try {
            // ۱. ابتدا لاگ کردن محاسبه در پایگاه داده جهت تضمین Persistence و پیشگیری از ناهماهنگی با پردازنده‌های ثانویه
            $this->logFraudCalculation($userId, $factors, $finalScore);

            // ۲. سپس شلیک رویداد جهت فرآیندهای ثانویه به صورت کاملاً ناهمگام و پس‌زمینه (🚀 UPG-06)
            $this->outbox?->record('fraud', $userId, 'fraud.score_updated', [
                'user_id' => $userId, 'fraud_score' => $finalScore,
            ]);
        } catch (\Throwable $e) {
            // M33 Fix: جلوگیری از کرش کل فرآیند در صورت بروز خطا در نوشتن سوابق آماری و لاگ‌های غیرضروری
            $this->logger->error('fraud.score_persistence.failed', [
                'user_id' => $userId,
                'score'   => $finalScore,
                'error'   => $e->getMessage()
            ]);
            \App\Services\Sentry\SentryExceptionHandler::captureException($e, $userId, [
                'operation' => 'fraud.score_persistence',
                'score' => $finalScore,
            ]);
        }

        return $finalScore;
    }

    /**
     * جمع‌آوری عوامل ریسک
     */
    /** @return array<string, mixed> */
    private function gatherRiskFactors(int $userId): array
    {
        return [
            'account_age' => $this->getAccountAge($userId),
            'reputation'  => $this->getUserReputation($userId),
            'velocity'    => $this->getTransactionVelocity($userId),
            'geographic'  => $this->getGeographicAnomalies($userId),
        ];
    }

    /**
     * محاسبه عامل سن حساب
     */
    private function calculateAccountAgeFactor(int $days): float
    {
        if ($days < self::ACCOUNT_AGE_NEW_USER_DAYS)  return 100; // حساب خیلی جدید
        if ($days < self::ACCOUNT_AGE_YOUNG_DAYS)     return 80;
        if ($days < self::ACCOUNT_AGE_MEDIUM_DAYS)    return 50;
        if ($days < self::ACCOUNT_AGE_MATURE_DAYS)    return 20;
        return 0; // حساب قدیمی
    }

    /**
     * محاسبه عامل شهرت
     */
    private function calculateReputationFactor(int $reputation): float
    {
        if ($reputation < 0)                                return 100; // شهرت منفی
        if ($reputation < self::REPUTATION_LOW_THRESHOLD)   return 80;
        if ($reputation < self::REPUTATION_MEDIUM_THRESHOLD)return 50;
        if ($reputation < self::REPUTATION_HIGH_THRESHOLD)  return 20;
        return 0; // شهرت بالا
    }

    /**
     * محاسبه عامل سرعت تراکنش با متغیرهای کاملاً پویا
     */
    /** @param array<string, mixed> $velocity */
    private function calculateVelocityFactor(array $velocity): float
    {
        $score = 0;

        $daily = int_value($velocity['daily'] ?? 0);
        $weekly = int_value($velocity['weekly'] ?? 0);
        $prevWeekly = int_value($velocity['prev_weekly'] ?? 0);

        // 📈 Dynamic Metric Check: Evaluates velocity bounds retrieved from active Risk Policy settings.
        if ($daily > int_value($this->velocitySettings['daily_high'])) {
            $score += 30;
        } elseif ($daily > int_value($this->velocitySettings['daily_medium'])) {
            $score += 15;
        }

        if ($weekly > int_value($this->velocitySettings['weekly_high'])) {
            $score += 40;
        } elseif ($weekly > int_value($this->velocitySettings['weekly_medium'])) {
            $score += 20;
        }

        // Sudden Spike Detection: Compares ratios against configured spike limits.
        $ratio = float_value($this->velocitySettings['spike_ratio']);
        $minSpike = int_value($this->velocitySettings['spike_min']);

        if ($prevWeekly === 0 && $weekly > $minSpike) {
            $score += 25; // Step from inactivity to high volume
        } elseif ($prevWeekly > 0 && ($weekly / $prevWeekly) >= $ratio && $weekly > 5) {
            $score += 30;
        }

        return min(100, $score);
    }

    /**
     * محاسبه عامل جغرافیایی
     */
    /** @param array<string, mixed> $geo */
    private function calculateGeographicFactor(array $geo): float
    {
        $score = 0;

        // تغییرات سریع کشور
        if ($geo['country_changes'] > 3) $score += 40;
        elseif ($geo['country_changes'] > 1) $score += 20;

        // تغییرات سریع شهر
        if ($geo['city_changes'] > 5) $score += 30;
        elseif ($geo['city_changes'] > 2) $score += 15;

        // IP های مشکوک
        if ($geo['suspicious_ips'] > 0) $score += 30;

        return min(100, $score);
    }

    /**
     * گرفتن سن حساب به روز
     */
    private function getAccountAge(int $userId): int
    {
        return $this->fraudModel->getAccountAge($userId);
    }

    /**
     * گرفتن امتیاز شهرت کاربر
     */
    private function getUserReputation(int $userId): int
    {
        return $this->fraudModel->getUserReputation($userId);
    }

    /**
     * گرفتن سرعت تراکنش‌ها
     */
    /** @return array<string, mixed> */
    private function getTransactionVelocity(int $userId): array
    {
        return [
            'daily' => $this->fraudModel->getDailyTransactionCount($userId),
            'weekly' => $this->fraudModel->getWeeklyTransactionCount($userId),
            'prev_weekly' => $this->fraudModel->getPreviousWeeklyTransactionCount($userId),
        ];
    }

    /**
     * گرفتن ناهنجاری‌های جغرافیایی
     */
    /** @return array<string, mixed> */
    private function getGeographicAnomalies(int $userId): array
    {
        return [
            'country_changes' => $this->fraudModel->getCountryChanges($userId),
            'city_changes' => $this->fraudModel->getCityChanges($userId),
            'suspicious_ips' => $this->fraudModel->getSuspiciousIPCount($userId),
        ];
    }

    /**
     * لاگ کردن محاسبه امتیاز تقلب
     */
    /** @param array<string, mixed> $factors */
    private function logFraudCalculation(int $userId, array $factors, int $finalScore): void
    {
        $this->fraudModel->logFraudCalculation($userId, $factors, $finalScore);
    }

    /**
     * اجرای اقدامات خودکار بر اساس امتیاز
     * @param int $userId شناسه کاربر
     * @param int|null $forcedScore امتیاز اجباری (برای تست یا override دستی)
     */
    /** @return list<string> */
    public function executeAutomatedActions(int $userId, ?int $forcedScore = null): array
    {
        $score = $forcedScore ?? $this->calculateFraudScore($userId);
        $actions = [];

        if ($score >= $this->thresholds['suspend']) {
            $actions[] = $this->suspendAccount($userId, 'High fraud risk score: ' . $score);
        } elseif ($score >= $this->thresholds['review']) {
            $actions[] = $this->flagForManualReview($userId, $score);
        } elseif ($score >= $this->thresholds['kyc']) {
            $actions[] = $this->requireKYC($userId, $score);
        } elseif ($score >= $this->thresholds['flag']) {
            $actions[] = $this->flagForReview($userId, $score);
        }

        return $actions;
    }

    /**
     * پرچم‌گذاری برای بررسی
     */
    private function flagForReview(int $userId, int $score): string
    {
        $this->fraudModel->flagForReview($userId, $score);
        $this->logFraudAction($userId, 'flag_for_review', $score, 'User flagged for review due to fraud score');
        return 'flagged_for_review';
    }

    /**
     * نیاز به KYC
     */
    private function requireKYC(int $userId, int $score): string
    {
        $this->fraudModel->requireKYC($userId, $score);
        $this->logFraudAction($userId, 'require_kyc', $score, 'KYC required due to fraud score');
        return 'kyc_required';
    }

    /**
     * پرچم‌گذاری برای بررسی دستی
     */
    private function flagForManualReview(int $userId, int $score): string
    {
        $this->fraudModel->flagForManualReview($userId, $score);
        $this->logFraudAction($userId, 'manual_review', $score, 'Manual review required due to high fraud score');
        return 'manual_review_required';
    }

    /**
     * تعلیق حساب
     */
    private function suspendAccount(int $userId, string $reason): string
    {
        $this->fraudModel->suspendAccount($userId, $reason);
        $this->logFraudAction($userId, 'account_suspended', 100, $reason);
        return 'account_suspended';
    }

    /**
     * لاگ کردن اقدامات تقلب
     */
    private function logFraudAction(int $userId, string $action, int $score, string $details): void
    {
        $this->fraudModel->logFraudAction($userId, $action, $score, $details);
    }

    /**
     * بررسی اینکه آیا کاربر نیاز به بررسی دارد
     */
    public function requiresReview(int $userId): bool
    {
        $user = $this->fraudModel->getUserFraudInfo($userId);
        if ($user) {
            $isKycRequired = ($user->flag_type ?? '') === 'kyc_required' || !empty($user->kyc_required);
            $isSuspended = ($user->flag_type ?? '') === 'suspended' || ($user->status ?? '') === 'suspended';

            if ($isKycRequired || $isSuspended) {
                return true;
            }
        }

        try {
            $kyc = $this->toObject($this->db->fetch("SELECT id FROM kyc_verifications WHERE user_id = ? AND status IN ('pending', 'under_review') LIMIT 1", [$userId]));
            if ($kyc) {
                return true;
            }
        } catch (\Throwable $e) {
            $this->logger->warning('fraud.kyc_check_failed', ['user_id' => $userId, 'error' => $e->getMessage()]);
            \App\Services\Sentry\SentryExceptionHandler::captureException($e, $userId, ['operation' => 'fraud.kyc_check']);
        }

        return false;
    }

    /**
     * @param array<string, mixed> $thresholds
     * @return array<string, mixed>
     */
    private function normalizeThresholds(array $thresholds): array
    {
        foreach ((array)$thresholds as $key => $value) {
            $thresholds[$key] = is_numeric($value) ? (int)$value : self::RISK_THRESHOLDS[$key];
            $thresholds[$key] = max(0, min(100, $thresholds[$key]));
        }

        if ($thresholds['kyc'] < $thresholds['flag']) {
            $thresholds['kyc'] = $thresholds['flag'];
        }
        if ($thresholds['review'] < $thresholds['kyc']) {
            $thresholds['review'] = $thresholds['kyc'];
        }
        if ($thresholds['suspend'] < $thresholds['review']) {
            $thresholds['suspend'] = $thresholds['review'];
        }

        return $thresholds;
    }

    /**
     * گرفتن گزارش ریسک کاربر
     */
    /** @return array<string, mixed> */
    public function getRiskReport(int $userId): array
    {
        $score = $this->calculateFraudScore($userId);
        $factors = $this->gatherRiskFactors($userId);

        $user = $this->fraudModel->getUserFlags($userId);

        return [
            'user_id' => $userId,
            'fraud_score' => $score,
            'risk_factors' => $factors,
            'flags' => [
                'requires_review' => (bool) ($user->requires_review ?? false),
                'requires_kyc' => (bool) ($user->requires_kyc ?? false),
                'requires_manual_review' => (bool) ($user->requires_manual_review ?? false),
                'is_blacklisted' => (bool) ($user->is_blacklisted ?? false),
                'blacklist_reason' => $user->blacklist_reason ?? null
            ],
            'thresholds' => $this->thresholds
        ];
    }

    /**
     * گرفتن لیست کاربران پر ریسک
     */
    /** @return list<\stdClass> */
    public function getHighRiskUsers(int $minScore = 50, int $limit = 50): array
    {
        if (method_exists($this->fraudModel, 'getHighRiskUsers')) {
            return $this->fraudModel->getHighRiskUsers($minScore, $limit);
        }
        try {
            return db()->fetchAll('SELECT id, email, full_name, fraud_score FROM users WHERE fraud_score >= ? ORDER BY fraud_score DESC LIMIT ' . max(1, min(500, $limit)), [$minScore]) ?: [];
        } catch (\Throwable $e) {
            $this->logger->warning('fraud.high_risk_users_fallback_failed', ['error' => $e->getMessage()]);
            \App\Services\Sentry\SentryExceptionHandler::captureException($e, null, ['operation' => 'fraud.getHighRiskUsers']);
            return [];
        }
    }

    /**
     * گرفتن لاگ‌های تقلب
     */
    /** @return list<\stdClass> */
    public function getFraudLogs(?int $userId = null, ?string $fraudType = null, int $limit = 100): array
    {
        if (method_exists($this->fraudModel, 'getFraudLogs')) {
            return $this->fraudModel->getFraudLogs($userId, $fraudType, $limit);
        }
        try {
            $where = []; $params = [];
            if ($userId !== null) { $where[] = 'user_id = ?'; $params[] = $userId; }
            if ($fraudType) { $where[] = 'fraud_type = ?'; $params[] = $fraudType; }
            $sql = 'SELECT * FROM fraud_logs' . (($where ? (' WHERE ' . implode(' AND ', $where)) : '') . ' ORDER BY created_at DESC LIMIT ' . max(1, min(500, $limit)));
            return db()->fetchAll($sql, $params) ?: [];
        } catch (\Throwable $e) {
            $this->logger->warning('fraud.high_risk_users_fallback_failed', ['error' => $e->getMessage()]);
            \App\Services\Sentry\SentryExceptionHandler::captureException($e, null, ['operation' => 'fraud.getHighRiskUsers']);
            return [];
        }
    }

    /**
     * پاک کردن پرچم‌های بررسی کاربر
     */
    public function clearUserFlags(int $userId): bool
    {
        $this->fraudModel->clearUserFlags($userId);

        // 🛡️ Audit Fix: استفاده از AuditTrail یکپارچه (Event-Driven) به جای لاگ مستقیم
        try {
            $this->auditTrail?->record('fraud.flags_cleared', $userId, ['action' => 'flags_cleared', 'reason' => 'Admin cleared all fraud flags']);
        } catch (\Throwable $e) {
            $this->logger->warning('fraud.audit_trail_failed', ['operation' => 'clearUserFlags', 'user_id' => $userId, 'error' => $e->getMessage()]);
            \App\Services\Sentry\SentryExceptionHandler::captureException($e, $userId, ['operation' => 'fraud.audit.clearUserFlags']);
        }

        return true;
    }

    /**
     * تعلیق حساب کاربر (نسخه ادمین)
     */
    public function suspendUser(int $userId, string $reason): bool
    {
        $this->fraudModel->blacklistUser($userId, $reason);

        // 🛡️ Audit Fix: استفاده از AuditTrail یکپارچه
        try {
            $this->auditTrail?->record('fraud.user_suspended', $userId, ['action' => 'manual_suspension', 'reason' => $reason]);
        } catch (\Throwable $e) {
            $this->logger->warning('fraud.audit_trail_failed', ['operation' => 'suspendUser', 'user_id' => $userId, 'error' => $e->getMessage()]);
            \App\Services\Sentry\SentryExceptionHandler::captureException($e, $userId, ['operation' => 'fraud.audit.suspendUser']);
        }

        return true;
    }

    /**
     * رفع تعلیق حساب کاربر
     */
    public function unsuspendUser(int $userId): bool
    {
        $this->fraudModel->unblacklistUser($userId);

        // 🛡️ Audit Fix: استفاده از AuditTrail یکپارچه
        try {
            $this->auditTrail?->record('fraud.user_unsuspended', $userId, ['action' => 'unsuspension', 'reason' => 'Admin removed suspension']);
        } catch (\Throwable $e) {
            $this->logger->warning('fraud.audit_trail_failed', ['operation' => 'unsuspendUser', 'user_id' => $userId, 'error' => $e->getMessage()]);
            \App\Services\Sentry\SentryExceptionHandler::captureException($e, $userId, ['operation' => 'fraud.audit.unsuspendUser']);
        }

        return true;
    }
}
