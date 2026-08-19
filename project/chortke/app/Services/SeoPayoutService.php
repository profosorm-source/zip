<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Ads;
use App\Contracts\LoggerInterface;

/**
 * SeoPayoutService — محاسبه پرداخت پویا
 * 
 * فرمول: Payout = MinPayout + ((FinalScore / 100) × (MaxPayout - MinPayout))
 */
class SeoPayoutService
{
    private Ads $seoAdModel;

    /**
     * Centralized toObject (root-cause normalization).
     */
    private function toObject(mixed $data): ?\stdClass
    {
        if ($data === null || $data === false) return null;
        if ($data instanceof \stdClass) return $data;
        if (is_object($data) || is_array($data)) return (object)(array)$data;
        return (object)[$data];
    }

    public function __construct(
        Ads $seoAdModel
    ) {
                $this->seoAdModel = $seoAdModel;
    }

    /**
     * محاسبه پرداخت بر اساس امتیاز
     * 
     * @param int $adId شناسه آگهی
     * @param float $finalScore امتیاز نهایی (0-100)
     * @return array<string, mixed> ['payout' => float, 'can_pay' => bool, 'message' => string]
     */
    public function calculatePayout(int $adId, float $finalScore): array
    {
        $ad = $this->toObject($this->seoAdModel->find($adId));
        if (!$ad) { 
        return [
                'payout' => 0,
                'can_pay' => false,
                'message' => 'آگهی یافت نشد'
            ];
        }

        // بررسی وضعیت آگهی
        if ($ad->status !== 'active') {
            return [
                'payout' => 0,
                'can_pay' => false,
                'message' => 'آگهی فعال نیست'
            ];
        }

        // محاسبه پرداخت پویا
        $minPayout = $ad->min_payout ?? 1000;
        $maxPayout = $ad->max_payout ?? 5000;

        // فرمول: Min + (Score/100 × (Max - Min))
        $scoreRatio = $finalScore / 100;
        $payout = $minPayout + ($scoreRatio * ($maxPayout - $minPayout));
        // PRECISION FIX: پرداخت با bcmath
        $minStr   = (string)$minPayout;
        $maxStr   = (string)$maxPayout;
        $diff     = bcsub($maxStr, $minStr, 8);
        $payout   = bcadd($minStr, bcmul((string)$scoreRatio, $diff, 8), 4);

        // بررسی بودجه
        if ($ad->remaining_budget < $payout) {
            return [
                'payout' => 0,
                'can_pay' => false,
                'message' => 'بودجه آگهی کافی نیست'
            ];
        }

        // بررسی حداقل امتیاز قابل قبول (اختیاری)
        $minAcceptableScore = $ad->min_score ?? 40;
        if ($finalScore < $minAcceptableScore) {
            return [
                'payout' => 0,
                'can_pay' => false,
                'message' => "حداقل امتیاز قابل قبول {$minAcceptableScore} است"
            ];
        }

        return [
            'payout' => $payout,
            'can_pay' => true,
            'message' => 'پرداخت محاسبه شد',
            'details' => [
                'min_payout' => $minPayout,
                'max_payout' => $maxPayout,
                'score_ratio' => round($scoreRatio * 100, 2) . '%',
                'remaining_budget' => $ad->remaining_budget,
            ]
        ];
    }

    /**
     * کسر پرداخت از بودجه آگهی
     * 
     * @param int $adId
     * @param string $amount
     * @return bool
     */
    public function deductFromBudget(int $adId, string $amount): bool
    {
        // INTERNAL_API: atomic SEO payout deduction.
        // If the remaining budget drops below min_payout, mark it exhausted immediately;
        // AdsBudgetSettlementService::reconcileLifecycle() then refunds the unusable escrow remainder.
        $stmt = $this->seoAdModel->getDb()->prepare(
            "UPDATE ads
             SET remaining_budget = remaining_budget - ?,
                 executions_count = executions_count + 1,
                 status = CASE
                    WHEN (remaining_budget - ?) <= 0
                      OR (COALESCE(min_payout,0) > 0 AND (remaining_budget - ?) < COALESCE(min_payout,0))
                    THEN 'exhausted' ELSE status END,
                 is_active = CASE
                    WHEN (remaining_budget - ?) <= 0
                      OR (COALESCE(min_payout,0) > 0 AND (remaining_budget - ?) < COALESCE(min_payout,0))
                    THEN 0 ELSE is_active END,
                 updated_at = NOW()
             WHERE id = ? AND remaining_budget >= ?"
        );
        
        $ok = $stmt->execute([$amount, $amount, $amount, $amount, $amount, $adId, $amount]);
        return $ok && $stmt->rowCount() > 0;
    }

    /**
     * برگشت بودجه (در صورت رد شدن یا تقلب)
     * 
     * @param int $adId
     * @param string|int|float $amount
     * @return bool
     */
    public function refundToBudget(int $adId, string|int|float $amount): bool
    {
        $amount = (string)$amount;
        // 🔐 M-29 FIX: perform the refund as a single atomic UPDATE using DB-side decimal
        // arithmetic. The previous read-then-write (find → bcadd → UPDATE remaining_budget = ?)
        // was a lost-update race: two concurrent refunds both read the same balance and each
        // overwrote it with base+amount, silently dropping one refund. Doing the addition in
        // SQL removes the race and keeps money math in the DB's DECIMAL column (no float).
        // NOTE: MySQL evaluates SET assignments left-to-right and later items see earlier
        // updated columns, so is_active and status are derived from the ORIGINAL row and
        // remaining_budget is updated LAST.
        if (!is_numeric($amount) || bccomp($amount, '0', 8) <= 0) {
            return false;
        }

        $stmt = $this->seoAdModel->getDb()->prepare(
            "UPDATE ads
             SET is_active = CASE
                    WHEN status = 'exhausted' AND (remaining_budget + ?) > 0 THEN 1
                    ELSE is_active END,
                 status = CASE
                    WHEN status = 'exhausted' AND (remaining_budget + ?) > 0 THEN 'active'
                    ELSE status END,
                 executions_count = GREATEST(0, executions_count - 1),
                 remaining_budget = remaining_budget + ?,
                 updated_at = NOW()
             WHERE id = ?"
        );

        $ok = $stmt->execute([$amount, $amount, $amount, $adId]);
        return $ok && $stmt->rowCount() > 0;
    }

    /**
     * پیش‌بینی بودجه مورد نیاز
     * 
     * @param array<string, mixed> $config شامل: min_payout, max_payout, expected_users
     * @return array<string, mixed>
     */
    /**
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    public function estimateBudget(array $config): array
    {
        $minPayout = is_numeric($config['min_payout'] ?? null) ? (float)$config['min_payout'] : 1000.0;
        $maxPayout = is_numeric($config['max_payout'] ?? null) ? (float)$config['max_payout'] : 5000.0;
        $expectedUsers = is_numeric($config['expected_users'] ?? null) ? (int)$config['expected_users'] : 100;
        $avgScore = is_numeric($config['avg_score'] ?? null) ? (float)$config['avg_score'] : 70.0; // میانگین امتیاز پیش‌بینی شده

        // محاسبه میانگین پرداخت
        $avgPayout = $minPayout + (($avgScore / 100) * ($maxPayout - $minPayout));

        // بودجه کل مورد نیاز
        $totalBudget = $avgPayout * $expectedUsers;

        // سناریوهای مختلف
        $scenarios = [
            'worst_case' => [
                'description' => 'همه کاربران حداکثر امتیاز (100)',
                'budget' => $maxPayout * $expectedUsers,
            ],
            'average_case' => [
                'description' => "میانگین امتیاز {$avgScore}",
                'budget' => $totalBudget,
            ],
            'best_case' => [
                'description' => 'همه کاربران حداقل امتیاز (40)',
                'budget' => ($minPayout + (0.4 * ($maxPayout - $minPayout))) * $expectedUsers,
            ],
        ];

        return [
            'min_payout' => $minPayout,
            'max_payout' => $maxPayout,
            'expected_users' => $expectedUsers,
            'avg_payout' => round($avgPayout, 2),
            'recommended_budget' => round($totalBudget, 2),
            'scenarios' => $scenarios,
        ];
    }

    /**
     * محاسبه تعداد کاربران قابل پوشش با بودجه فعلی
     * 
     * @param int $adId
     * @return array<string, mixed>
     */
    public function estimateReach(int $adId): array
    {
        $ad = $this->toObject($this->seoAdModel->find($adId));
        if (!$ad) { 
        return ['error' => 'آگهی یافت نشد'];
        }

        $minPayout = $ad->min_payout ?? 1000;
        $maxPayout = $ad->max_payout ?? 5000;
        $avgPayout = ($minPayout + $maxPayout) / 2;

        $remainingBudget = $ad->remaining_budget;

        return [
            'remaining_budget' => $remainingBudget,
            'min_users' => floor($remainingBudget / $maxPayout), // بدترین حالت
            'max_users' => floor($remainingBudget / $minPayout), // بهترین حالت
            'avg_users' => floor($remainingBudget / $avgPayout), // حالت معمولی
        ];
    }
}

