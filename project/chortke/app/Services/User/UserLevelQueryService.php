<?php

declare(strict_types=1);

namespace App\Services\User;

use App\Models\UserLevel;
use App\Services\Settings\AppSettings;

class UserLevelQueryService
{
    private UserLevel $levelModel;

    public function __construct(
        UserLevel $levelModel
    ) {
        $this->levelModel = $levelModel;
    }

    /**
     * @return array<int, object>
     */
    public function getUserLevels(): array
    {
        return $this->levelModel->getAllActive();
    }

    public function getUserBonuses(int $userId): \stdClass
    {
        // Fallback or cached logic
        return (object)[
            'earning_bonus_percent' => 10.0,
            'referral_bonus_percent' => 5.0,
            'withdrawal_limit_bonus' => 50000.0,
            'daily_task_limit_bonus' => 5,
            'priority_support' => false,
            'special_badge' => false
        ];
    }

    public function getProgress(int $userId): object
    {
        $db = \Core\Database::getInstance();
        $user = $db->fetch("SELECT level_slug, monthly_active_days, level_expires_at, level_type FROM users WHERE id = ?", [$userId]);
        
        $currentSlug = $user->level_slug ?? 'bronze';
        $currentLevel = $this->levelModel->findBySlug($currentSlug);
        
        if (!$currentLevel) {
            $currentLevel = (object)[
                'name' => ucfirst($currentSlug),
                'slug' => $currentSlug,
                'color' => '#c0c0c0',
                'icon' => 'workspace_premium'
            ];
        }

        $nextLevel = $this->levelModel->getNextLevel($currentSlug);

        $details = [];
        $overallProgress = 0;

        if ($nextLevel) {
            $minActive = $nextLevel->min_active_days ?? 10;
            $currentActive = (int)($user->monthly_active_days ?? 0);
            $basePercent = round(($currentActive / max(1, $minActive)) * 100);

            // 🚀 اعمال شتاب‌دهنده ویدیویی (XP Boost) در صورت فعال بودن
            if (\Core\Cache::getInstance()->get("xp_boost_active:{$userId}")) {
                $xpGrowthRate = int_value(\config('video_rewards.xp_growth_rate', \setting('xp_growth_rate', 50)));
                $basePercent = round($basePercent * (1 + ($xpGrowthRate / 100)));
            }
            
            $details = [
                (object)[
                    'label' => 'روزهای فعالیت ماهانه',
                    'current' => $currentActive,
                    'required' => $minActive,
                    'percent' => min(100, (int)$basePercent)
                ]
            ];
            $overallProgress = $details[0]->percent;
        }

        return (object)[
            'current' => $currentLevel,
            'next' => $nextLevel,
            'is_max' => !$nextLevel,
            'progress' => $overallProgress,
            'details' => $details,
            'monthly_active_days' => $user->monthly_active_days ?? 0,
            'level_type' => $user->level_type ?? 'earned',
            'level_expires_at' => $user->level_expires_at ?? null
        ];
    }

    public function applyEarningBonus(int $userId, string $baseAmount): string
    {
        $bonuses = $this->getUserBonuses($userId);
        if ($bonuses->earning_bonus_percent <= 0) return $baseAmount;
        
        $percentStr = (string)$bonuses->earning_bonus_percent;
        $money = \Core\ValueObjects\Money::of($baseAmount, 'IRT');
        $bonusAmount = $money->percentage($percentStr);
        return $money->add($bonusAmount)->getAmount();
    }
}
