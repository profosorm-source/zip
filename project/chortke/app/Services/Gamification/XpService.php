<?php

declare(strict_types=1);

namespace App\Services\Gamification;

use App\Models\User;
use App\Models\UserVacation;
use App\Enums\ModuleContext;
use App\Enums\ScoreDomain;
use App\Contracts\LoggerInterface;
use App\Domain\Gamification\Strategies\DailySynergyStrategy;
use App\Domain\Gamification\Strategies\InactivityDecayStrategy;
use App\Services\ScoreService;
use Core\Database;
use Core\Cache;

/**
 * سرویس مدیریت تجربه (XP)
 * مسئولیت: اعطای XP، محاسبه هم‌افزایی روزانه و اعمال ریزش (Decay)
 */
class XpService
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


    private \Core\Cache $cache;
    private \Core\Database $db;
    private \App\Contracts\LoggerInterface $logger;
    private ScoreService $scoreService;
    private UserVacation $vacationModel;
    private DailySynergyStrategy $synergyStrategy;
    private InactivityDecayStrategy $decayStrategy;
    public function __construct(
        \Core\Cache $cache,
        \Core\Database $db,
        \App\Contracts\LoggerInterface $logger,
        ScoreService $scoreService,
        UserVacation $vacationModel,
        DailySynergyStrategy $synergyStrategy,
        InactivityDecayStrategy $decayStrategy
    ) {        $this->cache = $cache;
        $this->db = $db;
        $this->logger = $logger;
        $this->scoreService = $scoreService;
        $this->vacationModel = $vacationModel;
        $this->synergyStrategy = $synergyStrategy;
        $this->decayStrategy = $decayStrategy;

        
    }

    /**
     * اعطای XP به کاربر در یک ماژول مشخص با تضمین Idempotency و قفل همزمانی
     */
    public function award(int $userId, ModuleContext $context, float $baseXp, ?string $idempotencyKey = null): bool
    {
        if ($baseXp <= 0) return false;

        try {
            // 🛡️ M-2 Fix: ScoreService.applyDelta هندل Idempotency (Redis SETNX) و Lock (GET_LOCK) را خودش انجام می‌دهد
            // دیگر نیازی به GET_LOCK دستی و Idempotency چک با JSON_EXTRACT نیست

            // 🛡️ M-38 FIX: قبلاً ابتدا XP ماژول ثبت می‌شد و سپس وجود کاربر بررسی
            // می‌شد؛ در مسیر کاربر ناموجود، کد یک ثبت XP انجام داده و سپس $this->db->rollback()
            // را صدا می‌زد در حالی‌که هیچ تراکنشی باز نبود (applyDelta خودش تراکنش را
            // commit کرده بود) — این rollback استثنا پرتاب می‌کرد و XP ماژولی که قبلاً
            // commit شده بود را برنمی‌گرداند — یعنی XP به کاربر ناموجود داده می‌شد.
            // رفع ریشه‌ای: ابتدا وجود کاربر را اعتبارسنجی می‌کنیم؛ چون هر applyDelta idempotent
            // است، تکرار ایمن است و دیگر به rollback دستی نیازی نیست.
            $userRow = $this->toObject($this->db->query("SELECT * FROM users WHERE id = ?", [$userId])->fetch(\PDO::FETCH_OBJ));
            if (!$userRow) {
                $this->logger->warning('xp_service.award_user_not_found', ['user_id' => $userId]);
                return false;
            }

            // ۱. ثبت XP در ماژول مربوطه از طریق ScoreService
            $this->scoreService->applyDelta(
                'user', $userId, ScoreDomain::dynamicDomain(ScoreDomain::PREFIX_XP, $context), $baseXp, 'activity',
                ['idempotency_key' => $idempotencyKey],
                $idempotencyKey
            );

            // ۲. محاسبه ضریب هم‌افزایی روزانه (Synergy)
            $user = new User($this->db);
            foreach ((array)$userRow as $key => $val) {
                $user->{$key} = $val;
            }

            $activeDomainsCount = $this->getActiveDomainsCountToday($userId);
            $yesterdayMultiplier = float_value($this->cache->get("synergy:{$userId}:" . \date('Y-m-d', \strtotime('-1 day'))) ?? 1.0);
            
            $multiplier = $this->synergyStrategy->calculate($user, $context, [
                'active_domains_count' => $activeDomainsCount,
                'yesterday_multiplier' => $yesterdayMultiplier
            ]);

            // ۳. ثبت XP عمومی (Global XP) برای ارتقای لول کاربر
            $finalGlobalXp = $baseXp * $multiplier;
            $this->scoreService->applyDelta(
                'user', $userId, ScoreDomain::dynamicDomain(ScoreDomain::PREFIX_XP, ModuleContext::GLOBAL), $finalGlobalXp, 'synergy_activity',
                ['multiplier' => $multiplier, 'context' => $context->value],
                "xp_global:{$userId}:{$context->value}:" . date('Y-m-d')
            );

            $this->cache->set("synergy:{$userId}:" . \date('Y-m-d'), $multiplier, 86400);

            return true;
        } catch (\Throwable $e) {
            $this->logger->error('xp_service.award_failed', ['user_id' => $userId, 'error' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * اعمال ریزش عدم فعالیت (Decay) روی تمام دامنه‌های فعال
     */
    public function applyDecay(User $user, int $inactiveDays): void
    {
        // چک کردن وضعیت مرخصی (Vacation Mode)
        if ($this->vacationModel->isUserOnVacation($user->id)) {
            return;
        }

        $isVip = in_array($user->level_slug, ['silver', 'gold', 'vip', 'platinum']);

        $contexts = [
            ModuleContext::YOUTUBE_TASKS,
            ModuleContext::SOCIAL_TASKS,
            ModuleContext::CUSTOM_TASKS,
            ModuleContext::GOOGLE_SEARCH_TASKS
        ];

        foreach ($contexts as $context) {
            $currentScore = $this->scoreService->getScore('user', $user->id, ScoreDomain::dynamicDomain(ScoreDomain::PREFIX_XP, $context));

            $penalty = $this->decayStrategy->calculate($user, $context, [
                'inactive_days' => $inactiveDays,
                'current_score' => $currentScore,
                'is_vip' => $isVip
            ]);

            if ($penalty < 0) {
                // 🛡️ M-2 Fix: استفاده از ScoreService به جای مستقیم scoreModel
                $this->scoreService->applyDelta(
                    'user', $user->id, ScoreDomain::dynamicDomain(ScoreDomain::PREFIX_XP, $context), $penalty, 'inactivity_decay',
                    ['inactive_days' => $inactiveDays],
                    "xp_decay:{$user->id}:{$context->value}:" . date('Y-m-d')
                );
            }
        }
    }

    private function getActiveDomainsCountToday(int $userId): int
    {
        $stmt = $this->db->prepare("
            SELECT COUNT(DISTINCT domain) FROM score_events
            WHERE entity_id = ? AND entity_type = 'user'
            AND domain LIKE 'xp_%' AND domain != 'xp_global'
            AND DATE(created_at) = CURRENT_DATE()
        ");
        $stmt->execute([$userId]);
        return (int)$stmt->fetchColumn() ?: 1;
    }
}
