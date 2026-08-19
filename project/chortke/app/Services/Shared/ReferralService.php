<?php

declare(strict_types=1);

namespace App\Services\Shared;

use Core\Database;
use App\Models\ReferralCommission;
use App\Models\User;
use App\Services\AuditTrail;
use App\Services\Settings\AppSettings;
use Core\TransactionWrapper;

use App\Contracts\LoggerInterface;
use App\Contracts\WalletServiceInterface;
use App\Contracts\NotificationServiceInterface;
use Core\EventDispatcher;

/**
 * ReferralService — سرویس اشتراکی سیستم رفرال
 *
 * جایگزین App\Services\ReferralService و App\Services\ReferralCommissionService می‌شود.
 */
class ReferralService
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

    /** @return list<\stdClass> */
    private function toObjectArray(mixed $rows): array
    {
        if (!is_array($rows)) throw new \UnexpectedValueException('Referral database result must be an array.');
        $result = [];
        foreach ($rows as $row) {
            if ($row instanceof \stdClass) $result[] = $row;
            elseif (is_array($row)) $result[] = (object)$row;
            else throw new \UnexpectedValueException('Referral database row is invalid.');
        }
        return $result;
    }



    private ?\App\Contracts\OutboxServiceInterface $outboxService = null;

    private \Core\Database $db;
    private \App\Contracts\LoggerInterface $logger;
    private WalletServiceInterface $walletService;
    private ReferralCommission $commissionModel;
    private User $userModel;
    private AppSettings $appSettings;
    private TransactionWrapper $transactionWrapper;

    public function __construct(
        \Core\Database $db,
        \App\Contracts\LoggerInterface $logger,
        WalletServiceInterface $walletService,
        ReferralCommission $commissionModel,
        User $userModel,
        AppSettings $appSettings,
        TransactionWrapper $transactionWrapper,
        ?\App\Contracts\OutboxServiceInterface $outboxService = null
    ) {
        $this->db = $db;
        $this->logger = $logger;
        $this->walletService = $walletService;
        $this->commissionModel = $commissionModel;
        $this->userModel = $userModel;
        $this->appSettings = $appSettings;
        $this->transactionWrapper = $transactionWrapper;
        $this->outboxService = $outboxService;
    }


    // ═══════════════════════════════════════════════════════════════════════
    // Analytics
    // ═══════════════════════════════════════════════════════════════════════

    /** @return array<string, mixed> */
    public function getReferralTrend(int $userId, int $days = 30): array
    {
        $sql = "SELECT DATE(created_at) as date, COUNT(*) as count, SUM(commission_amount) as total_commission
                FROM referral_commissions
                WHERE referrer_id = ? AND created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
                GROUP BY DATE(created_at) ORDER by date ASC";
        $results = $this->toObjectArray($this->db->fetchAll($sql, [$userId, $days]));
        return ['data' => $results, 'period_days' => $days];
    }

    /** @return array<string, mixed> */
    public function getCommissionTrend(int $userId, int $days = 30): array
    {
        $sql = "SELECT DATE(created_at) as date, SUM(commission_amount) as commission_amount,
                       COUNT(*) as commission_count
                FROM referral_commissions
                WHERE referrer_id = ? AND created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
                GROUP BY DATE(created_at) ORDER BY date ASC";
        $results = $this->toObjectArray($this->db->fetchAll($sql, [$userId, $days]));
        return ['data' => $results, 'period_days' => $days];
    }

    /** @return array<string, mixed> */
    public function getConversionRate(int $userId, int $days = 30): array
    {
        $sql = "SELECT COUNT(DISTINCT referred_user_id) as converted,
                       COUNT(DISTINCT click_user_id) as clicked,
                       ROUND(100.0 * COUNT(DISTINCT referred_user_id) / NULLIF(COUNT(DISTINCT click_user_id), 0), 2) as conversion_rate
                FROM referral_clicks rc
                LEFT JOIN referral_commissions r ON rc.referred_user_id = r.referred_user_id
                WHERE rc.referrer_id = ? AND rc.created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)";
        $result = $this->toObject($this->db->fetch($sql, [$userId, $days]));
        return [
            'converted' => $result->converted ?? 0,
            'clicked' => $result->clicked ?? 0,
            'rate' => $result->conversion_rate ?? 0,
        ];
    }

    public function getIndirectEarnings(int $userId, string $currency = 'irt'): float
    {
        $sql = "SELECT SUM(commission_amount) as total FROM referral_commissions rc
                WHERE rc.referrer_id IN (
                   SELECT referred_user_id FROM referral_commissions WHERE referrer_id = ?
                ) AND rc.currency = ?";
        $res = $this->toObject($this->db->fetch($sql, [$userId, $currency]));
        return floatval($res->total ?? 0);
    }

    // ═══════════════════════════════════════════════════════════════════════
    // Commission Processing
    // ═══════════════════════════════════════════════════════════════════════

    /** @param array<string, mixed> $context
     *  @return array<string, mixed> */
    /**
     * اعطای بونوسِ ثابت ثبت‌نام به معرف (referrer).
     * از کلید پنل ادمین `referral_signup_bonus` (و `referral_signup_bonus_usdt`) می‌خواند.
     * بونوس ثابتِ کامل است (نه درصدی) و idempotent.
     * @return array<string, mixed>
     */
    public function awardSignupBonus(int $referredUserId, string $currency = 'irt'): array
    {
        $referredUser = $this->userModel->findById($referredUserId);
        if (!$referredUser || empty($referredUser->referred_by)) {
            return ['success' => false, 'message' => 'کاربر معرفی‌شده‌ای ندارد.', 'awarded' => false];
        }
        $referrerId = (int)$referredUser->referred_by;

        $amountRaw = $this->appSettings->get(
            $currency === 'usdt' ? 'referral_signup_bonus_usdt' : 'referral_signup_bonus',
            '1000'
        );
        $amount = (string)(is_scalar($amountRaw) ? $amountRaw : '1000');
        if (bccomp($amount, '0', 2) <= 0) {
            return ['success' => false, 'message' => 'بونوس ثبت‌نام پیکربندی نشده است.', 'awarded' => false];
        }

        $idempotencyKey = "referral_signup_{$referrerId}_{$referredUserId}";
        $existing = $this->commissionModel->findByIdempotencyKey($idempotencyKey);
        if ($existing) {
            return ['success' => true, 'awarded' => true, 'duplicate' => true, 'commission' => (string)$existing->commission_amount];
        }

        try {
            $result = $this->transactionWrapper->runWithRetry(function () use ($referrerId, $referredUserId, $amount, $currency, $idempotencyKey) {
                $this->db->query('SELECT id FROM users WHERE id = ? FOR UPDATE', [$referrerId]);
                $this->commissionModel->createCommission([
                    'referrer_id' => $referrerId,
                    'referred_user_id' => $referredUserId,
                    'amount' => $amount,
                    'commission_amount' => $amount,
                    'currency' => $currency,
                    'status' => 'paid',
                    'source_type' => 'signup',
                    'idempotency_key' => $idempotencyKey,
                    'context' => json_encode(['bonus_type' => 'fixed', 'reason' => 'signup_referral'], JSON_UNESCAPED_UNICODE),
                ]);
                $this->walletService->depositInTransaction($referrerId, $amount, $currency, [
                    'type' => 'referral_signup_bonus',
                    'description' => 'بونوس معرفی ثبت‌نام',
                    'idempotency_key' => $idempotencyKey,
                ]);
                return ['success' => true, 'commission' => $amount];
            });
            if (!empty($result['success'])) {
                $this->maybeAwardMilestones($referrerId);
            }
            return array_merge($result, ['awarded' => (bool)($result['success'] ?? false)]);
        } catch (\Exception $e) {
            $this->logger->error('referral_signup_bonus_error', ['error' => $e->getMessage(), 'user_id' => $referredUserId]);
            return ['success' => false, 'message' => $e->getMessage(), 'awarded' => false];
        }
    }

    /**
     * M-08 FIX: the fallback idempotency key used to be hash(json_encode($context)) over the whole
     * context, but callers (e.g. ReferralCommissionListener) inject per-attempt values such as
     * correlation_id/timestamp into that context. Every retry therefore produced a *different*
     * key, so the duplicate check never matched and the same commission could be paid repeatedly.
     * The key is now derived only from the fields that identify the business event, and volatile
     * per-attempt fields are explicitly excluded.
     *
     * @param array<string, mixed> $context
     */
    private function commissionIdempotencyKey(int $referrerId, string $scope, array $context): string
    {
        $explicit = $context['idempotency_key'] ?? null;
        if (is_scalar($explicit) && (string)$explicit !== '') {
            return (string)$explicit;
        }

        $volatile = [
            'idempotency_key', 'correlation_id', 'request_id', 'trace_id', 'timestamp',
            'created_at', 'attempt', 'retry_count', 'ip', 'ip_address', 'user_agent',
            'device_fingerprint', 'percentage',
        ];
        $identity = [];
        foreach ($context as $contextKey => $contextValue) {
            if (in_array((string)$contextKey, $volatile, true)) {
                continue;
            }
            if (!is_scalar($contextValue) && $contextValue !== null) {
                continue;
            }
            $identity[(string)$contextKey] = is_bool($contextValue) ? ($contextValue ? '1' : '0') : (string)$contextValue;
        }
        ksort($identity);
        $identity['referrer_id'] = (string)$referrerId;
        $identity['scope'] = $scope;

        return "referral_{$referrerId}_{$scope}_" . hash('sha256', json_encode($identity, JSON_UNESCAPED_UNICODE) ?: $scope);
    }

    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    public function processCommission(int $referrerId, string $amount, string $currency, array $context = []): array
    {
        $percentage = (string)(is_scalar($this->appSettings->get('referral_commission_percent', 5)) ? $this->appSettings->get('referral_commission_percent', 5) : '');
        $commissionRatio = bcdiv((string)$percentage, '100', 8);
        $commissionValue = \Core\ValueObjects\Money::fromString((string)$amount)->multiply($commissionRatio)->getAmount();
        $commission = bcdiv($commissionValue, '1', 2);

        if ($referrerId === (int)(is_numeric($context['investor_id'] ?? null) ? $context['investor_id'] : 0)|| $referrerId === (int)(is_numeric($context['user_id'] ?? null) ? $context['user_id'] : 0)) {
            return ['success' => false, 'message' => 'امکان واریز پورسانت به خود وجود ندارد.'];
        }

        $investorRaw = $context['investor_id'] ?? $context['user_id'] ?? null;
        $investorId = is_numeric($investorRaw) ? (int)$investorRaw : 0;
        if ($investorId > 0 && $this->detectCircularReferral($investorId, $referrerId)) {
            return ['success' => false, 'message' => 'Circular referral chain detected.'];
        }

        // ROOT FIX: the daily quota used to be incremented *before* the idempotency check, so every
        // retry of the same event burned a slot and eventually rejected legitimate commissions.
        // The idempotency key is now resolved first and an already-processed event short-circuits
        // without touching the counter. The counter is only consumed for genuinely new events, and
        // if the transaction ultimately fails the consumed slot is refunded.
        $commissionIdempotencyKey = $this->commissionIdempotencyKey($referrerId, 'general', $context);
        $preExisting = $this->commissionModel->findByIdempotencyKey($commissionIdempotencyKey);
        if ($preExisting) {
            return ['success' => true, 'commission' => (string)$preExisting->commission_amount, 'duplicate' => true];
        }

        $rateLimitKey = "ref_commission_limit:" . date('Y-m-d') . ":" . $referrerId;
        $dailyCount = cache()->increment($rateLimitKey, 1, 86400);
        $dailyMax = (int)(is_numeric($this->appSettings->get('referral_daily_limit', 50)) ? $this->appSettings->get('referral_daily_limit', 50) : 50);
        // L-16 Fix: این یک مسیر مالی است؛ اگر rate-limiter در دسترس نباشد (increment مقدار false
        // برمی‌گرداند) نباید fail-open شویم و کمیسیون را بدون سقف پردازش کنیم. در این
        // حالت fail-closed می‌شویم تا سقف روزانه قابل دور زدن نباشد (رویداد دوباره رترای می‌شود).
        if ($dailyCount === false) {
            $this->logger->error('referral.commission.ratelimit_unavailable', ['referrer_id' => $referrerId]);
            return ['success' => false, 'message' => 'خطای موقتی در بررسی محدودیت پورسانت. لطفاً بعداً دوباره تلاش کنید.'];
        }
        if ($dailyCount > $dailyMax) {
            cache()->decrement($rateLimitKey, 1);
            return ['success' => false, 'message' => 'محدودیت تعداد پورسانت‌های روزانه برای این معرف به پایان رسیده است.'];
        }

        try {
            // The milestone check below used to sit after a `return`, so it was dead code and
            // milestones were never granted through this path. The result is captured first.
            $commissionResult = $this->transactionWrapper->runWithRetry(function() use ($referrerId, $amount, $currency, $commission, $percentage, $context, $commissionIdempotencyKey) {
                $this->db->query('SELECT id FROM users WHERE id = ? FOR UPDATE', [$referrerId]);

                $existingCommission = $this->commissionModel->findByIdempotencyKey($commissionIdempotencyKey);
                if ($existingCommission) {
                    return ['success' => true, 'commission' => (string)$existingCommission->commission_amount, 'duplicate' => true];
                }

                $this->commissionModel->createCommission([
                    'referrer_id' => $referrerId,
                    'referred_user_id' => (int)(is_numeric($context['user_id'] ?? null) ? $context['user_id'] : 0),
                    'amount' => $amount,
                    'commission_amount' => $commission,
                    'currency' => $currency,
                    'status' => 'paid',
                    'source_type' => (string)(is_scalar($context['source_type'] ?? null) ? $context['source_type'] : 'general'),
                    'idempotency_key' => $commissionIdempotencyKey,
                    'context' => json_encode(array_merge($context, [
                        'percentage' => $percentage,
                    ])),
                ]);

                $this->walletService->depositInTransaction($referrerId, (string)$commission, $currency, [
                    'type' => 'referral_commission',
                    'description' => 'کمیسیون معرفی',
                    'idempotency_key' => $commissionIdempotencyKey,
                ]);

                return ['success' => true, 'commission' => $commission];
            });

            // پس از اعطای کمیسیون، میلستون‌های معرف را نیز بررسی و در صورت رسیدن، اهدا کن.
            // A replayed (duplicate) commission must not re-trigger milestone awards.
            if (!empty($commissionResult['success']) && empty($commissionResult['duplicate'])) {
                $this->maybeAwardMilestones($referrerId);
            } elseif (!empty($commissionResult['duplicate'])) {
                // A concurrent worker had already recorded it: give the consumed slot back.
                cache()->decrement($rateLimitKey, 1);
            }

            return $commissionResult;
        } catch (\Exception $e) {
            // The event was never recorded, so the reserved quota slot must not be leaked.
            cache()->decrement($rateLimitKey, 1);
            $this->logger->error('commission_error', ['error' => $e->getMessage()]);
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * اعطای بونوس معرفی پس از تأیید محتوای کاربر.
     *
     * @return array<string, mixed>
     */
    /**
     * اعطای بونوس معرفی (بونوسِ ثابت کامل — نه درصدی) پس از تأیید محتوای کاربر.
     *
     * @return array<string, mixed>
     */
    public function checkAndAwardBonus(int $userId, string $context = 'content_approval', int $referenceId = 0): array
    {
        $user = $this->userModel->findById($userId);
        if (!$user || empty($user->referred_by)) {
            return ['success' => false, 'message' => 'کاربر معرفی‌شده‌ای ندارد.', 'awarded' => false];
        }

        $referrerId = (int)$user->referred_by;
        $amount = (string)(is_scalar($this->appSettings->get('referral_content_approval_amount', '0')) ? $this->appSettings->get('referral_content_approval_amount', '0') : '');
        if (bccomp($amount, '0', 2) <= 0) {
            return ['success' => false, 'message' => 'بونوس تأیید محتوا پیکربندی نشده است.', 'awarded' => false];
        }
        $currency = (string)(is_scalar($this->appSettings->get('referral_content_approval_currency', 'irt')) ? $this->appSettings->get('referral_content_approval_currency', 'irt') : '');

        $idempotencyKey = "referral_{$userId}_{$context}_{$referenceId}";
        // ایدمپوتنسی: اگر این بونوس قبلاً پرداخت شده، دوباره پرداخت نکن
        $existing = $this->commissionModel->findByIdempotencyKey($idempotencyKey);
        if ($existing) {
            return ['success' => true, 'awarded' => true, 'duplicate' => true, 'commission' => (string)$existing->commission_amount];
        }

        try {
            $result = $this->transactionWrapper->runWithRetry(function () use ($referrerId, $userId, $amount, $currency, $context, $referenceId, $idempotencyKey) {
                $this->db->query('SELECT id FROM users WHERE id = ? FOR UPDATE', [$referrerId]);

                // بونوس ثابت کامل (به‌جای درصد)
                $this->commissionModel->createCommission([
                    'referrer_id' => $referrerId,
                    'referred_user_id' => $userId,
                    'amount' => $amount,
                    'commission_amount' => $amount,
                    'currency' => $currency,
                    'status' => 'paid',
                    'source_type' => (string)(is_scalar($context) ? $context : 'content_approval'),
                    'idempotency_key' => $idempotencyKey,
                    'context' => json_encode(array_merge((array)$context, [
                        'reference_id' => $referenceId,
                        'bonus_type' => 'fixed',
                    ]), JSON_UNESCAPED_UNICODE),
                ]);

                $this->walletService->depositInTransaction($referrerId, $amount, $currency, [
                    'type' => 'referral_bonus',
                    'description' => 'بونوس معرفی (تأیید محتوا)',
                    'idempotency_key' => $idempotencyKey,
                ]);

                return ['success' => true, 'commission' => $amount];
            });

            if (!empty($result['success'])) {
                $this->maybeAwardMilestones($referrerId);
            }
            return array_merge($result, ['awarded' => (bool)($result['success'] ?? false)]);
        } catch (\Exception $e) {
            $this->logger->error('referral_bonus_error', ['error' => $e->getMessage(), 'user_id' => $userId]);
            return ['success' => false, 'message' => $e->getMessage(), 'awarded' => false];
        }
    }

    /** @param array<string, mixed> $context
     *  @return array<string, mixed> */
    public function processModularCommission(int $referredUserId, string $module, string $amount, string $currency, array $context = []): array
    {
        $referrerUser = $this->userModel->findById($referredUserId);
        if (!$referrerUser || !$referrerUser->referred_by) {
            return ['success' => true, 'commission' => 0.0, 'message' => 'No referrer found'];
        }

        $referrerId = (int)$referrerUser->referred_by;

        if ($referrerId === (int)$referredUserId) {
            return ['success' => false, 'message' => 'Self-referral detected'];
        }

        if ($this->detectCircularReferral((int)$referredUserId, $referrerId)) {
            return ['success' => false, 'message' => 'Circular referral chain detected.'];
        }

        $rateLimitKey = "ref_commission_limit:" . date('Y-m-d') . ":" . $referrerId;
        $dailyCount = cache()->increment($rateLimitKey, 1, 86400);
        $dailyMax = (int)(is_numeric($this->appSettings->get('referral_daily_limit', 50)) ? $this->appSettings->get('referral_daily_limit', 50) : 50);
        if ($dailyCount !== false && $dailyCount > $dailyMax) {
            return ['success' => false, 'message' => 'محدودیت تعداد پورسانت‌های روزانه برای این معرف به پایان رسیده است.'];
        }

        if ($module === 'influencer') {
            $isInfluencer = false;
            try {
                $count = (int)$this->db->table('influencer_profiles')
                    ->where('user_id', '=', $referrerId)
                    ->where('status', '=', 'approved')
                    ->count();
                $isInfluencer = $count > 0;
            } catch (\Throwable $t) {
                $this->logger->error('influencer_check_failed', ['error' => $t->getMessage()]);
                $isInfluencer = false; 
            }

            if ($isInfluencer) {
                $percentage = (string)(is_scalar($this->appSettings->get('referral_influencer_pro_percent', '10')) ? $this->appSettings->get('referral_influencer_pro_percent', '10') : '');
            } else {
                $percentage = (string)(is_scalar($this->appSettings->get('referral_influencer_regular_percent', '5')) ? $this->appSettings->get('referral_influencer_regular_percent', '5') : '');
            }
        } else {
            $settingKey = "referral_{$module}_percent";
            $percentage = (string)(is_scalar($this->appSettings->get($settingKey, '5')) ? $this->appSettings->get($settingKey, '5') : '');
        }

        $commissionRatio = bcdiv((string)$percentage, '100', 8);
        $commissionValue = \Core\ValueObjects\Money::fromString((string)$amount)->multiply($commissionRatio)->getAmount();
        $commission = bcdiv($commissionValue, '1', 2);

        try {
            return $this->transactionWrapper->runWithRetry(function() use ($referrerId, $amount, $currency, $commission, $percentage, $module, $referredUserId, $context) {
                $this->db->query("SELECT id, referred_by FROM users WHERE id = ? FOR UPDATE", [$referrerId]);

                $commissionIdempotencyKey = $this->commissionIdempotencyKey($referrerId, 'modular_' . $module, $context);

                $existingCommission = $this->commissionModel->findByIdempotencyKey($commissionIdempotencyKey);
                if ($existingCommission) {
                    return ['success' => true, 'commission' => (string)$existingCommission->commission_amount, 'duplicate' => true];
                }

                $this->commissionModel->createCommission([
                    'referrer_id' => $referrerId,
                    'referred_user_id' => $referredUserId,
                    'amount' => $amount,
                    'commission_amount' => $commission,
                    'currency' => $currency,
                    'status' => 'paid',
                    'source_type' => $module,
                    'idempotency_key' => $commissionIdempotencyKey,
                    'context' => json_encode(array_merge($context, [
                        'percentage' => $percentage,
                    ])),
                ]);

                $this->walletService->depositInTransaction($referrerId, (string)$commission, $currency, [
                    'type' => 'referral_commission',
                    'description' => 'کمیسیون معرفی (' . $module . ')',
                    'idempotency_key' => $commissionIdempotencyKey,
                ]);

                return ['success' => true, 'commission' => $commission];
            });
        } catch (\Throwable $e) {
            $this->logger->error('modular_commission_error', ['error' => $e->getMessage()]);
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * بررسی و اهدای میلستون‌های معرف پس از اعطای هر کمیسیون/بونوس.
     * خطای آن هرگز نباید اعطای اصلی را خراب کند.
     */
    private function maybeAwardMilestones(int $referrerId): void
    {
        try {
            $this->checkAndAwardMilestones($referrerId);
        } catch (\Throwable $e) {
            $this->logger->warning('referral.milestone_check_failed', [
                'referrer_id' => $referrerId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function detectCircularReferral(int $userId, int $proposedReferrerId, int $maxDepth = 10): bool
    {
        if ($userId === $proposedReferrerId) {
            return true;
        }

        $currentReferrerId = $proposedReferrerId;
        $depth = 0;

        while ($currentReferrerId > 0 && $depth < $maxDepth) {
            if ($currentReferrerId === $userId) {
                return true;
            }

            $user = $this->userModel->findById($currentReferrerId);
            if (!$user || empty($user->referred_by)) {
                break;
            }

            $currentReferrerId = (int)$user->referred_by;
            $depth++;
        }

        return false;
    }

    public function getReferrerStats(int $referrerId): ?object
    {
        return $this->commissionModel->getReferrerStats($referrerId);
    }

    /** @param array<string, mixed> $filters
     *  @return list<\stdClass> */
    public function getByReferrer(int $referrerId, array $filters = [], int $limit = 20, int $offset = 0): array
    {
        return $this->commissionModel->getByReferrer($referrerId, $filters, $limit, $offset);
    }

    /** @param array<string, mixed> $filters */
    public function countByReferrer(int $referrerId, array $filters = []): int
    {
        return $this->commissionModel->countByReferrer($referrerId, $filters);
    }

    /** @return list<\stdClass> */
    public function getReferredUsers(int $referrerId, int $limit = 20, int $offset = 0): array
    {
        $limit  = \max(1, (int)$limit);
        $offset = \max(0, (int)$offset);

        $sql = "
            SELECT
                u.id,
                u.full_name,
                u.email,
                u.created_at AS joined_at,
                u.status,
                COALESCE(SUM(CASE WHEN rc.currency='irt' AND rc.status='paid' THEN rc.commission_amount ELSE 0 END), 0) AS earned_irt,
                COALESCE(SUM(CASE WHEN rc.currency='usdt' AND rc.status='paid' THEN rc.commission_amount ELSE 0 END), 0) AS earned_usdt,
                COUNT(rc.id) AS commission_count
            FROM users u
            LEFT JOIN referral_commissions rc
                ON rc.referred_user_id = u.id AND rc.referrer_id = ?
            WHERE u.referred_by = ? AND u.deleted_at IS NULL
            GROUP BY u.id
            ORDER BY u.created_at DESC
            LIMIT ? OFFSET ?
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(1, $referrerId, \PDO::PARAM_INT);
        $stmt->bindValue(2, $referrerId, \PDO::PARAM_INT);
        $stmt->bindValue(3, $limit, \PDO::PARAM_INT);
        $stmt->bindValue(4, $offset, \PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(\PDO::FETCH_OBJ);
    }

    public function countReferredUsers(int $referrerId): int
    {
        $sql = "SELECT COUNT(*) AS count FROM users WHERE referred_by = ? AND deleted_at IS NULL";
        $res = $this->toObject($this->db->fetch($sql, [$referrerId]));
        return (int)($res->count ?? 0);
    }

    public function todaySignupCount(int $referrerId): int
    {
        $sql = "
            SELECT COUNT(*) AS count FROM users
            WHERE referred_by = ?
              AND DATE(created_at) = CURDATE()
              AND deleted_at IS NULL
        ";
        $res = $this->toObject($this->db->fetch($sql, [$referrerId]));
        return (int)($res->count ?? 0);
    }

    public function todaySignupCountByIp(string $ip): int
    {
        $sql = "
            SELECT COUNT(*) AS count FROM referral_activity_logs
            WHERE action = 'signup'
              AND ip_address = ?
              AND DATE(created_at) = CURDATE()
        ";
        $res = $this->toObject($this->db->fetch($sql, [$ip]));
        return (int)($res->count ?? 0);
    }

    /** @param array<string, mixed> $filters
     *  @return array<string, mixed> */
    public function adminList(array $filters = [], int $limit = 30, int $offset = 0): array
    {
        $limit  = \max(1, (int)$limit);
        $offset = \max(0, (int)$offset);

        $sql = "SELECT rc.*,
                   ref.full_name AS referrer_name, ref.email AS referrer_email,
                   r.full_name AS referred_name, r.email AS referred_email
            FROM referral_commissions rc
            LEFT JOIN users ref ON ref.id = rc.referrer_id
            LEFT JOIN users r   ON r.id   = rc.referred_user_id
            WHERE 1=1";

        $params = [];

        if (!empty($filters['status'])) {
            $sql .= " AND rc.status = ?";
            $params[] = $filters['status'];
        }
        if (!empty($filters['source_type'])) {
            $sql .= " AND rc.source_type = ?";
            $params[] = $filters['source_type'];
        }
        if (!empty($filters['currency'])) {
            $sql .= " AND rc.currency = ?";
            $params[] = $filters['currency'];
        }
        if (!empty($filters['search'])) {
            $sql .= " AND (
                ref.full_name LIKE ? OR ref.email LIKE ?
                OR r.full_name LIKE ? OR r.email LIKE ?
                OR rc.idempotency_key LIKE ?
            )";
            $s = '%' . $filters['search'] . '%';
            $params[] = $s; $params[] = $s; $params[] = $s; $params[] = $s; $params[] = $s;
        }

        $sql .= " ORDER BY rc.created_at DESC LIMIT ? OFFSET ?";

        $stmt = $this->db->prepare($sql);
        $index = 1;
        foreach ($params as $val) {
            $stmt->bindValue($index++, $val);
        }
        $stmt->bindValue($index++, $limit, \PDO::PARAM_INT);
        $stmt->bindValue($index++, $offset, \PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(\PDO::FETCH_OBJ);
    }

    /** @param array<string, mixed> $filters */
    public function adminCount(array $filters = []): int
    {
        $sql = "SELECT COUNT(*) as total
                FROM referral_commissions rc
                LEFT JOIN users ref ON ref.id = rc.referrer_id
                LEFT JOIN users r   ON r.id = rc.referred_user_id
                WHERE 1=1";

        $params = [];

        if (!empty($filters['status'])) {
            $sql .= " AND rc.status = ?";
            $params[] = $filters['status'];
        }
        if (!empty($filters['source_type'])) {
            $sql .= " AND rc.source_type = ?";
            $params[] = $filters['source_type'];
        }
        if (!empty($filters['currency'])) {
            $sql .= " AND rc.currency = ?";
            $params[] = $filters['currency'];
        }
        if (!empty($filters['search'])) {
            $sql .= " AND (
                ref.full_name LIKE ? OR ref.email LIKE ?
                OR r.full_name LIKE ? OR r.email LIKE ?
                OR rc.idempotency_key LIKE ?
            )";
            $s = '%' . $filters['search'] . '%';
            $params[] = $s; $params[] = $s; $params[] = $s; $params[] = $s; $params[] = $s;
        }

        $stmt = $this->db->query($sql, $params);
        /** @var object{total: int}|null $row */
        $row = $stmt->fetch(\PDO::FETCH_OBJ) ?: null;

        return (int)($row->total ?? 0);
    }

    public function globalStats(): object
    {
        $stmt = $this->db->query("
            SELECT
              COUNT(*) as total,
              SUM(CASE WHEN status='pending' THEN 1 ELSE 0 END) as pending_count,
              SUM(CASE WHEN status='paid' THEN 1 ELSE 0 END) as paid_count,
              COALESCE(SUM(CASE WHEN currency='irt'  AND status='paid' THEN commission_amount ELSE 0 END),0) as total_paid_irt,
              COALESCE(SUM(CASE WHEN currency='usdt' AND status='paid' THEN commission_amount ELSE 0 END),0) as total_paid_usdt
            FROM referral_commissions
        ");

        /** @var object $result */
        $result = $stmt->fetch(\PDO::FETCH_OBJ) ?: (object)[];
        return $result;
    }

    /** @return list<\stdClass> */
    public function topReferrers(string $currency = 'irt', int $limit = 5): array
    {
        $limit = \max(1, (int)$limit);

        $stmt = $this->db->prepare("
            SELECT u.id, u.full_name, u.email,
                   COALESCE(SUM(rc.commission_amount),0) as total_commission
            FROM referral_commissions rc
            JOIN users u ON u.id = rc.referrer_id
            WHERE rc.status='paid' AND rc.currency = ?
            GROUP BY u.id
            ORDER BY total_commission DESC
            LIMIT ?
        ");
        $stmt->bindValue(1, $currency);
        $stmt->bindValue(2, $limit, \PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(\PDO::FETCH_OBJ);
    }

    // ═══════════════════════════════════════════════════════════════════════
    // Core Enhancements & Milestones / RBAC / Multi-tier / Settings Shims
    // ═══════════════════════════════════════════════════════════════════════

    public function getCurrentTier(int $userId): string
    {
        /** @var object{tier_name: string}|null $res */
        $res = $this->toObject($this->db->fetch("SELECT tier_name FROM referral_tiers WHERE user_id = ? ORDER BY id DESC LIMIT 1", [$userId]));
        return $res ? (string)$res->tier_name : 'BRONZE';
    }

    public function checkAndUpgrade(int $userId): object
    {
        $count = $this->countReferredUsers($userId);
        $newTier = 'BRONZE'; $bonus = 1.0;
        if ($count >= 50) { $newTier = 'PLATINUM'; $bonus = 5.0; }
        elseif ($count >= 20) { $newTier = 'GOLD'; $bonus = 3.0; }
        elseif ($count >= 5) { $newTier = 'SILVER'; $bonus = 2.0; }

        $this->db->execute("INSERT IGNORE INTO referral_tiers (user_id, tier_name, bonus_percent, is_active, upgraded_at) VALUES (?, ?, ?, 1, NOW())", [$userId, $newTier, max(1, (int)$bonus)]);
        return (object)['tier_name' => $newTier, 'bonus_percent' => max(1, (int)$bonus), 'name_fa' => $newTier];
    }

    public function getScore(int $userId): float
    {
        $user = $this->userModel->findById($userId);
        return $user ? floatval($user->referral_quality_score ?? 85.0) : 85.0;
    }

    public function calculateScore(int $userId): float
    {
        $rate = $this->getConversionRate($userId, 30);
        $score = min(100.0, max(20.0, ($rate['rate'] * 1.5) + 40.0));
        $this->db->execute("UPDATE users SET referral_quality_score = ? WHERE id = ?", [$score, $userId]);
        return $score;
    }

    public function rewardScore(int $userId, int $amount, string $reason): void
    {
        $this->db->execute("UPDATE users SET referral_quality_score = LEAST(100, referral_quality_score + ?) WHERE id = ?", [$amount, $userId]);
    }

    public function penalizeScore(int $userId, int $amount, string $reason): void
    {
        $this->db->execute("UPDATE users SET referral_quality_score = GREATEST(10, referral_quality_score - ?) WHERE id = ?", [$amount, $userId]);
    }

    /** @return list<\stdClass> */
    /**
     * میلستون‌هایی که کاربر واقعاً به آن‌ها رسیده است (بر اساس threshold).
     * @return list<\stdClass>
     */
    public function getUserAchievedMilestones(int $userId): array
    {
        $referralCount = $this->countReferredUsers($userId);
        $milestones = $this->db->fetchAll(
            "SELECT * FROM referral_milestones WHERE is_active = 1 ORDER BY threshold_value ASC"
        ) ?: [];

        $achieved = [];
        foreach ($milestones as $m) {
            $threshold = (int)($m->threshold_value ?? 0);
            $type = (string)($m->milestone_type ?? 'referrals');
            if ($type === 'referrals' && $referralCount >= $threshold) {
                $achieved[] = $m;
            }
        }
        return $achieved;
    }

    /**
     * بررسی و اعطای پاداش میلستون‌های تازه‌رسیده به کاربرِ معرف.
     * هر میلستون فقط یک‌بار اعطا می‌شود (referral_user_milestones).
     * @return array<string, mixed>
     */
    public function checkAndAwardMilestones(int $userId): array
    {
        $awarded = [];
        $newAwarded = [];
        $referralCount = $this->countReferredUsers($userId);

        $milestones = $this->db->fetchAll(
            "SELECT * FROM referral_milestones WHERE is_active = 1 ORDER BY threshold_value ASC"
        ) ?: [];

        // میلستون‌هایی که قبلاً اعطا شده‌اند
        $alreadyAwarded = [];
        $rows = $this->db->fetchAll(
            "SELECT milestone_id FROM referral_user_milestones WHERE user_id = ?", [$userId]
        ) ?: [];
        foreach ($rows as $r) {
            $alreadyAwarded[(int)($r->milestone_id ?? 0)] = true;
        }

        foreach ($milestones as $m) {
            $milestoneId = (int)($m->id ?? 0);
            $threshold = (int)($m->threshold_value ?? 0);
            $type = (string)($m->milestone_type ?? 'referrals');
            $reward = (string)($m->reward_amount ?? '0');
            $currency = (string)($m->currency ?? 'irt');

            $achieved = $type === 'referrals' && $referralCount >= $threshold;
            $already = isset($alreadyAwarded[$milestoneId]);

            if ($achieved && !$already && bccomp($reward, '0', 8) > 0) {
                // اهدای پاداش (idempotent با رکورد referral_user_milestones)
                try {
                    $this->db->beginTransaction();
                    $this->commissionModel->createCommission([
                        'referrer_id' => $userId,
                        'referred_user_id' => $userId,
                        'amount' => $reward,
                        'commission_amount' => $reward,
                        'currency' => $currency,
                        'status' => 'paid',
                        'source_type' => 'milestone',
                        'idempotency_key' => "referral_milestone_{$userId}_{$milestoneId}",
                        'context' => json_encode(['milestone_id' => $milestoneId, 'title' => (string)($m->title ?? '')], JSON_UNESCAPED_UNICODE),
                    ]);
                    $this->walletService->depositInTransaction($userId, $reward, $currency, [
                        'type' => 'referral_milestone',
                        'description' => 'پاداش میلستون معرفی: ' . (string)($m->title ?? ''),
                        'idempotency_key' => "referral_milestone_{$userId}_{$milestoneId}",
                    ]);
                    $this->db->query(
                        "INSERT INTO referral_user_milestones (user_id, milestone_id, reward_amount, currency) VALUES (?, ?, ?, ?)
                         ON DUPLICATE KEY UPDATE reward_amount = VALUES(reward_amount), currency = VALUES(currency)",
                        [$userId, $milestoneId, $reward, $currency]
                    );
                    $this->db->commit();
                    $awarded[] = (int)$milestoneId;
                    $newAwarded[] = (string)($m->title ?? '') . ' (' . $reward . ' ' . $currency . ')';
                } catch (\Throwable $e) {
                    if ($this->db->inTransaction()) $this->db->rollback();
                    $this->logger->error('referral_milestone_award_failed', [
                        'user_id' => $userId, 'milestone_id' => $milestoneId, 'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        return [
            'awarded' => $newAwarded,
            'awarded_count' => count($newAwarded),
            'referral_count' => $referralCount,
            'achieved' => $this->getUserAchievedMilestones($userId),
        ];
    }

    /** @return list<\stdClass> */
    public function getLeaderboard(int $limit = 100, string $periodKey = 'month'): array
    {
        return $this->topReferrers('irt', $limit);
    }

    /**
     * توزیع پاداش ماهانهٔ صدرنشین جدول رفرال.
     *
     * منطق این متد قبلاً در App\Jobs\Referral\DistributeMonthlyReferralRewardsJob بود و آن Job هرگز
     * به چرخهٔ اجرا تزریق نشده بود؛ کرون همین متدِ خالی را صدا می‌زد و عملاً هیچ پاداشی پرداخت نمی‌شد.
     * اکنون منطقِ ریشه‌ای به سرویس منتقل شده و از همان مسیرِ کرون (ReferralManagementService) اجرا می‌شود.
     *
     * @return array<string, mixed>
     */
    public function distributeMonthlyRewards(): array
    {
        $top = $this->commissionModel->getTopMonthlyReferrer();

        if (!$top) {
            return ['success' => true, 'rewarded' => false, 'bonus_percent' => 0.0];
        }

        $bonusPercent = float_value($this->appSettings->get('referral_top_bonus_percent', 5)) / 100;
        $bonus = float_value($top->total ?? 0) * $bonusPercent;
        $sysCurrency = strtolower(str_value($this->appSettings->get('currency_mode', 'irt')));
        $targetCurrency = in_array($sysCurrency, ['irt', 'usdt'], true) ? $sysCurrency : 'irt';

        $this->outboxService?->record('referral', (int)$top->id, \App\Events\Registry\EventRegistry::REFERRAL_COMMISSION_EARNED, [
            'user_id' => (int)$top->id,
            'amount' => $bonus,
            'currency' => $targetCurrency,
            'metadata' => [
                'type' => 'referral_bonus',
                'description' => 'Referral leaderboard bonus',
            ],
        ]);

        return [
            'success' => true,
            'rewarded' => true,
            'user_id' => (int)$top->id,
            'amount' => $bonus,
            'currency' => $targetCurrency,
            'bonus_percent' => $bonusPercent,
        ];
    }

    /**
     * @return array{
     *   success:bool,
     *   payouts:array<int,array{user_id:int,amount:string,duplicate?:bool}>,
     *   skipped?:string,
     *   message?:string
     * }
     */
    public function processMultiTierCommissions(int $userId, string|float|int $amount, string $currency): array
    {
        $amountStr = (string)$amount;

        // H-05 (پرداخت نادرست): مبنای پرداخت دیگر جعل نمی‌شود. پیش‌تر مقدار <0.01
        // با «1000.00» جایگزین می‌شد و چون داشبورد این متد را با amount=0 صدا می‌زد،
        // روی هر بارگذاری کمیسیون واقعی روی مبلغ ساختگی پرداخت می‌شد.
        if (!is_numeric($amountStr) || bccomp($amountStr, '0', 8) <= 0) {
            return ['success' => true, 'payouts' => [], 'skipped' => 'non_positive_amount'];
        }

        $user = $this->userModel->findById($userId);
        if (!$user || empty($user->referred_by)) {
            return ['success' => true, 'payouts' => []];
        }

        // ساخت زنجیرهٔ سطوح (حداکثر ۳ سطح) با نرخ هر سطح، پیش از ورود به تراکنش.
        /** @var list<array{level:int,user_id:int,ratio:string}> $tiers */
        $tiers = [];
        $ratios = [1 => '0.05', 2 => '0.02', 3 => '0.01'];
        $chainUser = $user;
        for ($level = 1; $level <= 3; $level++) {
            if (!$chainUser || empty($chainUser->referred_by)) {
                break;
            }
            $payeeId = (int)$chainUser->referred_by;
            $tiers[] = ['level' => $level, 'user_id' => $payeeId, 'ratio' => $ratios[$level]];
            $chainUser = $this->userModel->findById($payeeId);
        }

        if ($tiers === []) {
            return ['success' => true, 'payouts' => []];
        }

        try {
            $payouts = $this->transactionWrapper->runWithRetry(function () use ($userId, $amountStr, $currency, $tiers) {
                $result = [];
                $paidUserIds = []; // جلوگیری از پرداخت به یک کاربر در چند سطح (زنجیرهٔ معیوب/حلقه)

                foreach ($tiers as $tier) {
                    $level = (int)$tier['level'];
                    $payeeId = (int)$tier['user_id'];

                    // H-05 گاردهای «پرداخت نادرست»: گیرندهٔ نامعتبر/خودِ کاربر،
                    // کاربری که در سطح بالاتر پرداخت گرفته، و زنجیرهٔ حلقوی.
                    if ($payeeId <= 0 || $payeeId === $userId) {
                        continue;
                    }
                    if (isset($paidUserIds[$payeeId])) {
                        continue;
                    }
                    if ($this->detectCircularReferral($userId, $payeeId)) {
                        continue;
                    }

                    $tierAmount = bcmul($amountStr, $tier['ratio'], 2);
                    if (bccomp($tierAmount, '0', 8) <= 0) {
                        continue;
                    }

                    $idempotencyKey = hash('sha256', "ref_multi|{$userId}|{$payeeId}|{$level}|{$amountStr}|{$currency}");

                    // قفل ردیف گیرنده تا هم‌زمانی سریالایز شود (هم‌راستا با processCommission).
                    $this->db->query('SELECT id FROM users WHERE id = ? FOR UPDATE', [$payeeId]);

                    // پیش‌بررسی idempotency (مسیر سریع)؛ گاردِ اتمیک نهایی، UNIQUE(idempotency_key) است.
                    if ($this->commissionModel->findByIdempotencyKey($idempotencyKey)) {
                        $result[$level] = ['user_id' => $payeeId, 'amount' => $tierAmount, 'duplicate' => true];
                        $paidUserIds[$payeeId] = true;
                        continue;
                    }

                    // H-05 (تکرار): ابتدا رکورد کمیسیون (گاردِ اتمیک با UNIQUE) و سپس واریز —
                    // دقیقاً هم‌راستا با processCommission. اگر ثبت کمیسیون ناموفق بود (رقابت روی UNIQUE)،
                    // از واریز این سطح صرف‌نظر می‌شود تا double-deposit رخ ندهد.
                    $created = $this->commissionModel->createCommission([
                        'referrer_id' => $payeeId, 'referred_user_id' => $userId, 'amount' => $amountStr,
                        'commission_amount' => $tierAmount, 'currency' => $currency, 'status' => 'paid',
                        'source_type' => "multi_tier_level_{$level}", 'idempotency_key' => $idempotencyKey,
                        'context' => json_encode(['level' => $level], JSON_UNESCAPED_UNICODE),
                    ]);
                    if ($created === null) {
                        continue;
                    }

                    $this->walletService->depositInTransaction($payeeId, $tierAmount, $currency, [
                        'type' => 'referral_multi_tier', 'level' => $level, 'idempotency_key' => $idempotencyKey,
                    ]);

                    $result[$level] = ['user_id' => $payeeId, 'amount' => $tierAmount];
                    $paidUserIds[$payeeId] = true;
                }

                return $result;
            });

            return ['success' => true, 'payouts' => $payouts];
        } catch (\Throwable $e) {
            $this->logger->error('referral_multi_tier_error', ['user_id' => $userId, 'error' => $e->getMessage()]);
            return ['success' => false, 'message' => $e->getMessage(), 'payouts' => []];
        }
    }

    /** @return array<string, string> */
    public function getSourceTypes(): array
    {
        return ['influencer' => 'اینفلوئنسر', 'vitrine' => 'ویترین', 'custom_task' => 'تسک تعاملی', 'lottery' => 'قرعه‌کشی'];
    }

    /** @param array<string, mixed> $settings */
    public function saveSettings(array $settings): bool
    {
        foreach ((array)$settings as $k => $v) {
            $this->db->execute("INSERT INTO settings (`key`, `value`) VALUES (?, ?) ON DUPLICATE KEY UPDATE `value` = ?", [$k, $v, $v]);
        }
        return true;
    }

    public function cancelCommission(int $id, string $reason): bool
    {
        $affected = $this->db->execute("UPDATE referral_commissions SET status = 'cancelled', updated_at = NOW() WHERE id = ? AND status = 'pending'", [$id]);
        return $affected > 0;
    }

    /** @return array<string, mixed> */
    public function batchPay(string $currency): array
    {
        $commissions = $this->db->fetchAll("SELECT * FROM referral_commissions WHERE status = 'pending' AND currency = ?", [$currency]);
        $success = 0; $failed = 0; $skipped = 0;
        foreach ($commissions as $c) {
            // 🔐 M-22 FIX: use the stable per-commission id as the idempotency key. Appending
            // uniqid() made the key non-deterministic, so a retried batchPay could pay the same
            // commission twice. The commission row id is unique and stable across retries.
            $res = $this->processCommission((int)$c->referrer_id, (string)$c->amount, $currency, ['user_id' => (int)$c->referred_user_id, 'idempotency_key' => "bp_{$c->id}"]);
            if (!empty($res['success'])) {
                $this->db->execute("UPDATE referral_commissions SET status = 'paid', paid_at = NOW() WHERE id = ?", [$c->id]);
                $success++;
            } else { $failed++; }
        }
        return ['success' => $success, 'failed' => $failed, 'skipped' => $skipped];
    }
}
