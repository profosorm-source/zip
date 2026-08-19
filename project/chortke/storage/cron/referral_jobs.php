<?php

/**
 * Referral System Scheduled Jobs
 * 
 * Executed via crontab or Scheduler.
 * All logic delegates to ReferralService + ReferralManagementService.
 */

require_once __DIR__ . '/../../bootstrap/app.php';

use App\Services\Shared\ReferralService;
use App\Services\ReferralManagementService;
use Core\Database;
use Core\Cache;

// ═══════════════════════════════════════════════════
// Helpers
// ═══════════════════════════════════════════════════

$db  = app(Database::class);
$ref = app(ReferralService::class);
$mgr = app(ReferralManagementService::class);

$currentHour      = (int) date('H');
$currentDay       = (int) date('d');
$currentDayOfWeek = (int) date('N');

// ═══════════════════════════════════════════════════
// Job 1: Tier Upgrade — هر ۶ ساعت
// ═══════════════════════════════════════════════════
if ($currentHour % 6 === 0) {
    try {
        $users = $db->fetchAll(
            "SELECT DISTINCT u.id FROM users u
             INNER JOIN users ref ON ref.referred_by = u.id AND ref.deleted_at IS NULL
             WHERE u.deleted_at IS NULL LIMIT 500"
        );

        $upgraded = 0;
        foreach ($users as $user) {
            $result = $ref->checkAndUpgrade((int) $user->id);
            if (!empty($result->tier_name) && $result->tier_name !== 'BRONZE') $upgraded++;
        }
        error_log("[referral.cron] Tier upgrade: {$upgraded} upgraded / " . count($users) . " checked");
    } catch (\Exception $e) {
        error_log("[referral.cron] Tier upgrade failed: " . $e->getMessage());
    }
}

// ═══════════════════════════════════════════════════
// Job 2: Milestone Awards — هر ۴ ساعت
// ═══════════════════════════════════════════════════
if ($currentHour % 4 === 0) {
    try {
        $users = $db->fetchAll(
            "SELECT DISTINCT u.id FROM users u
             INNER JOIN users ref ON ref.referred_by = u.id AND ref.deleted_at IS NULL
             WHERE u.deleted_at IS NULL LIMIT 500"
        );

        $totalAwarded = 0;
        foreach ($users as $user) {
            $result = $ref->checkAndAwardMilestones((int) $user->id);
            $totalAwarded += count($result['awarded'] ?? []);
        }
        error_log("[referral.cron] Milestones: {$totalAwarded} awarded / " . count($users) . " checked");
    } catch (\Exception $e) {
        error_log("[referral.cron] Milestone check failed: " . $e->getMessage());
    }
}

// ═══════════════════════════════════════════════════
// Job 3: Leaderboard Update — ساعت ۲۳
// ═══════════════════════════════════════════════════
if ($currentHour === 23) {
    try {
        $count = $mgr->updateCurrentLeaderboard(100);
        error_log("[referral.cron] Leaderboard updated: {$count} leaders");
    } catch (\Exception $e) {
        error_log("[referral.cron] Leaderboard update failed: " . $e->getMessage());
    }
}

// ═══════════════════════════════════════════════════
// Job 4: Monthly Rewards — روز اول ماه ساعت ۲
// ═══════════════════════════════════════════════════
if ($currentDay === 1 && $currentHour === 2) {
    try {
        $results = $mgr->distributeMonthlyRewards();
        error_log("[referral.cron] Monthly rewards: " . json_encode($results));
    } catch (\Exception $e) {
        error_log("[referral.cron] Monthly rewards failed: " . $e->getMessage());
    }
}

// ═══════════════════════════════════════════════════
// Job 5: Quality Score Recalculation — یکشنبه‌ها ساعت ۳
// ═══════════════════════════════════════════════════
if ($currentDayOfWeek === 7 && $currentHour === 3) {
    try {
        $count = $mgr->batchRecalculateQuality(500);
        error_log("[referral.cron] Quality score: {$count} processed");
    } catch (\Exception $e) {
        error_log("[referral.cron] Quality score failed: " . $e->getMessage());
    }
}

// ═══════════════════════════════════════════════════
// Job 6: Batch Commission Payment — روزانه ساعت ۱
// ═══════════════════════════════════════════════════
if ($currentHour === 1) {
    try {
        $irt  = $ref->batchPay('irt');
        $usdt = $ref->batchPay('usdt');
        error_log("[referral.cron] Batch commissions: irt=" . json_encode($irt) . " usdt=" . json_encode($usdt));
    } catch (\Exception $e) {
        error_log("[referral.cron] Batch commission failed: " . $e->getMessage());
    }
}

// ═══════════════════════════════════════════════════
// Job 7: Old Log Cleanup — روز اول ماه ساعت ۴
// ═══════════════════════════════════════════════════
if ($currentDay === 1 && $currentHour === 4) {
    try {
        $db->execute(
            "DELETE FROM referral_activity_logs
             WHERE created_at < DATE_SUB(NOW(), INTERVAL 6 MONTH)
             LIMIT 10000"
        );
        error_log("[referral.cron] Old referral logs cleaned");
    } catch (\Exception $e) {
        error_log("[referral.cron] Log cleanup failed: " . $e->getMessage());
    }
}

error_log("[referral.cron] Cycle completed");
