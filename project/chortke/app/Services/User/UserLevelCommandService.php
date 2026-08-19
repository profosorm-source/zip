<?php

declare(strict_types=1);

namespace App\Services\User;

use App\Models\UserLevel;
use App\Models\UserLevelHistory;
use App\Models\User;
use Core\Database;
use App\Contracts\LoggerInterface;
use App\Services\Settings\AppSettings;
use Core\TransactionWrapper;

class UserLevelCommandService
{
    private UserLevel $levelModel;
    private AppSettings $appSettings;
    private TransactionWrapper $transactionWrapper;
    private Database $db;
    private LoggerInterface $logger;
    private \App\Contracts\WalletServiceInterface $walletService;
    private ?\App\Models\UserLevelHistory $historyModel = null;
    private ?\App\Models\Score $scoreModel = null;
    private ?\App\Services\User\UserService $userService = null;

    public function __construct(
        TransactionWrapper $transactionWrapper,
        Database $db,
        LoggerInterface $logger,
        UserLevel $levelModel,
        AppSettings $appSettings,
        \App\Contracts\WalletServiceInterface $walletService,
        ?\App\Models\UserLevelHistory $historyModel = null,
        ?\App\Models\Score $scoreModel = null
    ) {
        $this->transactionWrapper = $transactionWrapper;
        $this->db = $db;
        $this->logger = $logger;
        $this->levelModel = $levelModel;
        $this->appSettings = $appSettings;
        $this->walletService = $walletService;
        $this->historyModel = $historyModel;
        $this->scoreModel = $scoreModel;
    }

    private function fetchObject(\PDOStatement $statement): ?\stdClass
    {
        $row = $statement->fetch(\PDO::FETCH_OBJ);
        return $row instanceof \stdClass ? $row : null;
    }

    public function isEnabled(): bool
    {
        return (bool)$this->appSettings->get('user_levels_enabled', true);
    }

    public function recordDailyActivity(int $userId): void
    {
        if (!$this->isEnabled()) return;

        $today = date('Y-m-d');

        try {
            $this->transactionWrapper->runWithRetry(function() use ($userId, $today) {
                $stmt = $this->db->prepare("SELECT last_active_date FROM users WHERE id = ? FOR UPDATE");
                $stmt->execute([$userId]);
                $userRow = $this->fetchObject($stmt);

                if (!$userRow || $userRow->last_active_date === $today) {
                    return;
                }

                $this->db->prepare("
                    UPDATE users 
                    SET last_active_date = ?, 
                        active_days_count = active_days_count + 1,
                        monthly_active_days = monthly_active_days + 1
                    WHERE id = ?
                ")->execute([$today, $userId]);

                $this->logger->info('user_levels.daily_activity_recorded', ['user_id' => $userId]);
            });
        } catch (\Throwable $e) {
            $this->logger->error('user_levels.record_activity.failed', ['user_id' => $userId, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Admin-forced level change (manual override).
     */
    public function adminChangeLevel(int $userId, string $newLevel, string $reason = 'تغییر توسط مدیر'): bool
    {
        try {
            if ($this->userService === null) {
                return false;
            }
            $user = $this->userService->findById($userId);
            if (!$user) return false;

            // 🛡️ M-39 FIX: previously the level UPDATE and the history INSERT ran as two independent
            // auto-committed statements. If the history write failed the user's level had already
            // changed with NO audit record, and a concurrent purchase/expiry could interleave
            // between the read and the write (lost update). We now run both inside a single
            // retryable transaction and re-read the current level under FOR UPDATE — mirroring the
            // purchaseLevel() pattern in this same service — so the change and its audit trail are
            // atomic and serialized against concurrent level mutations.
            return (bool) $this->transactionWrapper->runWithRetry(function() use ($userId, $newLevel, $reason) {
                $stmt = $this->db->prepare("SELECT level_slug, level FROM users WHERE id = ? FOR UPDATE");
                $stmt->execute([$userId]);
                $locked = $this->fetchObject($stmt);
                if (!$locked) {
                    return false;
                }
                $oldLevel = $locked->level_slug ?? $locked->level ?? 'unknown';

                $this->db->execute(
                    "UPDATE users SET level_slug = ?, updated_at = NOW() WHERE id = ?",
                    [$newLevel, $userId]
                );

                // Record in history (inside the same transaction as the level change)
                if ($this->historyModel) {
                    $this->historyModel->createHistory([
                        'user_id'     => $userId,
                        'from_level'  => $oldLevel,
                        'to_level'    => $newLevel,
                        'change_type' => 'admin_manual',
                        'reason'      => $reason,
                        'metadata'    => json_encode(['admin_id' => user_id()], JSON_UNESCAPED_UNICODE),
                    ]);
                }

                return true;
            });
        } catch (\Throwable $e) {
            $this->logger->error('level.admin_change_failed', ['user_id' => $userId, 'error' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function purchaseLevel(int $userId, string $levelSlug, string $currency = 'irt'): array
    {
        if (!$this->isEnabled()) {
            return ['success' => false, 'message' => 'سیستم سطوح کاربری غیرفعال است'];
        }

        $level = $this->levelModel->findBySlug($levelSlug);
        if (!$level || !$level->is_active) {
            return ['success' => false, 'message' => 'سطح انتخاب شده معتبر نیست'];
        }

        $price = ($currency === 'usdt') ? (string)$level->purchase_price_usdt : (string)$level->purchase_price_irt;
        if (bccomp($price, '0', 2) <= 0) {
            return ['success' => false, 'message' => 'این سطح قابل خرید نیست'];
        }

        try {
            return $this->transactionWrapper->runWithRetry(function() use ($userId, $level, $price, $currency) {
                // 1. Get current user info
                $stmt = $this->db->prepare("SELECT level_slug FROM users WHERE id = ? FOR UPDATE");
                $stmt->execute([$userId]);
                $user = $this->fetchObject($stmt);
                
                if (!$user) {
                    return ['success' => false, 'message' => 'کاربر یافت نشد'];
                }

                if ($user->level_slug === $level->slug) {
                    return ['success' => false, 'message' => 'شما در حال حاضر در این سطح هستید'];
                }

                // 2. Anti-fraud check before financial operation
                assert_fraud_allowed($userId, 'user.level_purchase', ['amount' => $price, 'currency' => $currency, 'level' => $level->slug]);

                // 3. Perform Payment
                $payResult = $this->walletService->pay($userId, $price, $currency, [
                    'reason' => 'purchase_level',
                    'level' => $level->slug
                ]);

                if (!$payResult['success']) {
                    return ['success' => false, 'message' => 'موجودی کافی نیست یا خطای بانکی رخ داده است'];
                }

                // 3. Update User Level
                $expiresAt = date('Y-m-d H:i:s', (strtotime("+{$level->purchase_duration_days} days") ?: time()));
                $this->db->prepare("
                    UPDATE users 
                    SET level_slug = ?, 
                        level_type = 'purchased', 
                        level_expires_at = ? 
                    WHERE id = ?
                ")->execute([$level->slug, $expiresAt, $userId]);

                // 4. Record History
                $historyModel = $this->historyModel;
                $historyModel?->createHistory([
                    'user_id' => $userId,
                    'from_level' => $user->level_slug,
                    'to_level' => $level->slug,
                    'change_type' => 'purchase',
                    'reason' => "خرید سطح {$level->name}",
                    'metadata' => ['price' => $price, 'currency' => $currency, 'expires_at' => $expiresAt]
                ]);

                $this->logger->info('user_levels.purchased', ['user_id' => $userId, 'level' => $level->slug, 'price' => $price]);

                return ['success' => true, 'message' => "سطح شما با موفقیت به {$level->name} ارتقا یافت"];
            });
        } catch (\Throwable $e) {
            $this->logger->error('user_levels.purchase.failed', ['user_id' => $userId, 'error' => $e->getMessage()]);
            return ['success' => false, 'message' => 'خطای سیستمی در فرآیند خرید'];
        }
    }

    public function checkUpgrade(int $userId): void
    {
        if (!$this->isEnabled()) return;

        try {
            $upgraded = null;
            $this->transactionWrapper->runWithRetry(function() use ($userId, &$upgraded) {
                // 1. Get user current level and total global XP
                $stmt = $this->db->prepare("SELECT level_slug FROM users WHERE id = ? FOR UPDATE");
                $stmt->execute([$userId]);
                $user = $this->fetchObject($stmt);
                if (!$user) return;

                $scoreModel = $this->scoreModel;
                $totalXp = $scoreModel?->getDomainScore($userId, 'xp_global') ?? 0;

                // 2. Find the highest eligible level
                $eligibleLevel = $this->levelModel->getEligibleLevel($totalXp);
                if (!$eligibleLevel) return;

                // 3. Upgrade if eligible level is different from current level
                if ($user->level_slug !== $eligibleLevel->slug) {
                    $this->db->prepare("UPDATE users SET level_slug = ? WHERE id = ?")
                             ->execute([$eligibleLevel->slug, $userId]);

                    $historyModel = $this->historyModel;
                    $historyModel?->createHistory([
                        'user_id' => $userId,
                        'from_level' => $user->level_slug,
                        'to_level' => $eligibleLevel->slug,
                        'change_type' => 'earned',
                        'reason' => "ارتقا بر اساس امتیاز (XP: {$totalXp})",
                        'metadata' => ['total_xp' => $totalXp]
                    ]);

                    $this->logger->info('user_levels.upgraded', [
                        'user_id' => $userId,
                        'from' => $user->level_slug,
                        'to' => $eligibleLevel->slug,
                        'xp' => $totalXp
                    ]);

                    // L-29 Fix: اطلاعات ارتقا را نگه می‌داریم تا پس از commit رویداد dispatch شود.
                    $upgraded = [
                        'from' => (string)$user->level_slug,
                        'to' => (string)$eligibleLevel->slug,
                    ];
                }
            });
        } catch (\Throwable $e) {
            $this->logger->error('user_levels.check_upgrade.failed', ['user_id' => $userId, 'error' => $e->getMessage()]);
            return;
        }

        // L-29 Fix: پس از commit موفق، LevelUpgradedEvent را dispatch می‌کنیم تا اعلان/بج/audit اجرا شود.
        if ($upgraded !== null) {
            try {
                $dispatcher = \Core\Container::getInstance()->make(\Core\EventDispatcher::class);
                $dispatcher->dispatch(
                    \App\Events\LevelUpgradedEvent::class,
                    new \App\Events\LevelUpgradedEvent($userId, $upgraded['from'], $upgraded['to'], 'automatic')
                );
            } catch (\Throwable $e) {
                $this->logger->error('user_levels.level_upgraded_event.dispatch_failed', ['user_id' => $userId, 'error' => $e->getMessage()]);
            }
        }
    }

    /**
     * 🛡️ M-33 FIX: XP-based downgrades for auto (non-purchased) levels.
     *
     * Was a hard-coded `return []` stub — the nightly scheduler (Console\Kernel 02:00
     * 'user_levels') called it and always downgraded nobody, so users whose global XP fell below
     * their current level threshold (e.g. via inactivity decay) kept an inflated level forever.
     * Now we scan auto-level users and demote anyone whose XP-eligible level is strictly lower
     * than their current level. Purchased levels are intentionally skipped here — their lifecycle
     * is owned by checkExpiredPurchases(). Each demotion runs in its own retryable, row-locked
     * transaction with an audit history row.
     *
     * @return array<int, array{user_id:int, from:string, to:string}>
     */
    public function checkDowngrades(): array
    {
        if (!$this->isEnabled()) return [];

        $downgraded = [];
        $batchSize  = 5000;
        $lastId     = 0;
        $guard      = 0;
        try {
            // M-33 / cursor: به‌جای یک batch ثابتِ 5000، با cursor بر پایهٔ id تا خالی‌شدنِ
            // کامل ادامه می‌دهیم تا هیچ کاربری در backlog نماند. هر batch همچنان 5000تایی
            // است (سقفِ حافظه) و چون cursor همواره رو به جلو می‌رود، هیچ ردیفی دوباره پردازش نمی‌شود.
            do {
                if (++$guard > 100000) { // شیرِ اطمینان در برابر حلقهٔ بی‌پایان
                    $this->logger->warning('user_levels.check_downgrades.guard_tripped', ['last_id' => $lastId]);
                    break;
                }

                $rows = $this->db->fetchAll(
                    "SELECT u.id, u.level_slug, l.sort_order
                     FROM users u
                     JOIN user_levels l ON l.slug = u.level_slug AND l.is_active = 1
                     WHERE COALESCE(u.level_type, 'auto') = 'auto'
                       AND u.id > ?
                     ORDER BY u.id ASC
                     LIMIT {$batchSize}",
                    [$lastId]
                ) ?: [];
                $fetched = count($rows);

            foreach ($rows as $row) {
                $userId = (int)($row->id ?? 0);
                if ($userId > $lastId) {
                    $lastId = $userId; // پیشرویِ cursor صرف‌نظر از نتیجهٔ پردازش
                }
                if ($userId <= 0) continue;
                $currentSlug = (string)($row->level_slug ?? '');
                $currentSort = (int)($row->sort_order ?? 0);

                $totalXp = $this->scoreModel?->getDomainScore($userId, 'xp_global') ?? 0.0;
                $eligible = $this->levelModel->getEligibleLevel((float)$totalXp);
                if (!$eligible) continue;

                // Only act when the eligible level is genuinely lower than the current one.
                if ((int)$eligible->sort_order >= $currentSort || $eligible->slug === $currentSlug) {
                    continue;
                }

                try {
                    $this->transactionWrapper->runWithRetry(function() use ($userId, $eligible, $totalXp) {
                        $stmt = $this->db->prepare("SELECT level_slug, level_type FROM users WHERE id = ? FOR UPDATE");
                        $stmt->execute([$userId]);
                        $locked = $this->fetchObject($stmt);
                        if (!$locked) return;
                        // Re-check under lock: skip if it became purchased or already at/below target.
                        if ((string)($locked->level_type ?? 'auto') !== 'auto') return;
                        if ((string)$locked->level_slug === (string)$eligible->slug) return;

                        $this->db->prepare("UPDATE users SET level_slug = ?, updated_at = NOW() WHERE id = ?")
                                 ->execute([$eligible->slug, $userId]);

                        $this->historyModel?->createHistory([
                            'user_id'     => $userId,
                            'from_level'  => (string)$locked->level_slug,
                            'to_level'    => (string)$eligible->slug,
                            'change_type' => 'downgraded',
                            'reason'      => "تنزل خودکار بر اساس امتیاز (XP: {$totalXp})",
                            'metadata'    => ['total_xp' => $totalXp],
                        ]);
                    });

                    $downgraded[] = ['user_id' => $userId, 'from' => $currentSlug, 'to' => (string)$eligible->slug];
                    $this->logger->info('user_levels.downgraded', [
                        'user_id' => $userId, 'from' => $currentSlug, 'to' => (string)$eligible->slug, 'xp' => $totalXp,
                    ]);
                } catch (\Throwable $e) {
                    $this->logger->error('user_levels.downgrade.failed', ['user_id' => $userId, 'error' => $e->getMessage()]);
                }
            }
            } while ($fetched === $batchSize);
        } catch (\Throwable $e) {
            $this->logger->error('user_levels.check_downgrades.failed', ['error' => $e->getMessage()]);
        }

        return $downgraded;
    }

    /**
     * 🛡️ M-33 FIX: expire purchased (VIP/subscription) levels whose paid window has ended.
     *
     * Was a hard-coded `return 0` stub, so a purchased level with a past level_expires_at was
     * never reverted — users kept paid VIP perks indefinitely for free. Now we target exactly the
     * expired rows (indexed by idx_users_level_expires_at), and for each one revert level_type to
     * 'auto', clear the expiry, and set the level back to whatever the user's global XP actually
     * earns them (falling back to the lowest active level). Runs per-user in a row-locked,
     * retryable transaction with an audit row.
     *
     * @return int number of expired purchases processed
     */
    public function checkExpiredPurchases(): int
    {
        if (!$this->isEnabled()) return 0;

        $processed = 0;
        $batchSize = 5000;
        $lastId    = 0;
        $guard     = 0;
        try {
            // M-33 / cursor: تا خالی‌شدنِ کاملِ ردیف‌های منقضی ادامه می‌دهیم تا backlog نماند.
            do {
                if (++$guard > 100000) { // شیرِ اطمینان
                    $this->logger->warning('user_levels.check_expired_purchases.guard_tripped', ['last_id' => $lastId]);
                    break;
                }

                $rows = $this->db->fetchAll(
                    "SELECT id, level_slug FROM users
                     WHERE level_type = 'purchased'
                       AND level_expires_at IS NOT NULL
                       AND level_expires_at < NOW()
                       AND id > ?
                     ORDER BY id ASC
                     LIMIT {$batchSize}",
                    [$lastId]
                ) ?: [];
                $fetched = count($rows);

            foreach ($rows as $row) {
                $userId = (int)($row->id ?? 0);
                if ($userId > $lastId) {
                    $lastId = $userId;
                }
                if ($userId <= 0) continue;

                try {
                    $didProcess = $this->transactionWrapper->runWithRetry(function() use ($userId) {
                        $stmt = $this->db->prepare(
                            "SELECT level_slug, level_type, level_expires_at FROM users WHERE id = ? FOR UPDATE"
                        );
                        $stmt->execute([$userId]);
                        $locked = $this->fetchObject($stmt);
                        if (!$locked) return false;
                        // Re-validate under lock: still purchased AND still expired.
                        if ((string)($locked->level_type ?? '') !== 'purchased') return false;
                        $expiresAt = $locked->level_expires_at ?? null;
                        if ($expiresAt === null || strtotime((string)$expiresAt) === false || strtotime((string)$expiresAt) >= time()) {
                            return false;
                        }

                        $totalXp = $this->scoreModel?->getDomainScore($userId, 'xp_global') ?? 0.0;
                        $eligible = $this->levelModel->getEligibleLevel((float)$totalXp);
                        $targetSlug = $eligible->slug ?? ($this->levelModel->getEligibleLevel(0.0)->slug ?? 'silver');

                        $this->db->prepare(
                            "UPDATE users SET level_slug = ?, level_type = 'auto', level_expires_at = NULL, updated_at = NOW() WHERE id = ?"
                        )->execute([$targetSlug, $userId]);

                        $this->historyModel?->createHistory([
                            'user_id'     => $userId,
                            'from_level'  => (string)$locked->level_slug,
                            'to_level'    => (string)$targetSlug,
                            'change_type' => 'expired',
                            'reason'      => 'انقضای سطح خریداری‌شده (VIP)',
                            'metadata'    => ['total_xp' => $totalXp, 'previous_type' => 'purchased'],
                        ]);

                        return true;
                    });

                    if ($didProcess) {
                        $processed++;
                        $this->logger->info('user_levels.purchase_expired', ['user_id' => $userId]);
                    }
                } catch (\Throwable $e) {
                    $this->logger->error('user_levels.expire_purchase.failed', ['user_id' => $userId, 'error' => $e->getMessage()]);
                }
            }
            } while ($fetched === $batchSize);
        } catch (\Throwable $e) {
            $this->logger->error('user_levels.check_expired_purchases.failed', ['error' => $e->getMessage()]);
        }

        return $processed;
    }

    public function monthlyReset(): int
    {
        try {
            return $this->db->execute("UPDATE users SET monthly_active_days = 0");
        } catch (\Throwable $e) {
            return 0;
        }
    }
}
