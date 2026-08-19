<?php

declare(strict_types=1);

namespace App\Services\AntiFraud;

use App\Models\VelocityAndScoreModel;
use Core\Cache;
use App\Contracts\LoggerInterface;
/**
 * VelocityCheckService
 * 
 * بررسی سرعت تراکنش‌ها (Velocity Checks)
 */
class VelocityCheckService
{
    // ─── Burst detection constants ────────────────────────────────────
    /** پنجره ۵ دقیقه برای تشخیص burst (ثانیه) */
    private const BURST_WINDOW_SECONDS    = 300;
    /** تعداد bucket های ۵دقیقه‌ای در ۲۴ ساعت: 24*60/5 = 288 */
    private const BURST_BUCKETS_PER_DAY  = 288;
    /** ضریب انفجار: اگر recent > avg * این عدد باشد = burst */
    private const BURST_MULTIPLIER       = 5;
    /** پنجره ۲۴ ساعته برای historical average (ثانیه) */
    private const HISTORICAL_WINDOW_SECS = 86400;

    private VelocityAndScoreModel $model;

    private const DEFAULT_RULES = [
        'deposit' => [
            '1h' => ['limit' => 5, 'period' => 3600],
            '24h' => ['limit' => 20, 'period' => 86400],
            '7d' => ['limit' => 50, 'period' => 604800],
        ],
        'withdrawal' => [
            '1h' => ['limit' => 3, 'period' => 3600],
            '24h' => ['limit' => 10, 'period' => 86400],
            '7d' => ['limit' => 30, 'period' => 604800],
        ],
        'transfer' => [
            '1h' => ['limit' => 10, 'period' => 3600],
            '24h' => ['limit' => 50, 'period' => 86400],
            '7d' => ['limit' => 200, 'period' => 604800],
        ],
        'social_task' => [
            '1h' => ['limit' => 20, 'period' => 3600],
            '24h' => ['limit' => 100, 'period' => 86400],
            '7d' => ['limit' => 500, 'period' => 604800],
        ],
        'login' => [
            '5m' => ['limit' => 5, 'period' => 300],
            '1h' => ['limit' => 10, 'period' => 3600],
            '24h' => ['limit' => 30, 'period' => 86400],
        ],
        'password_change' => [
            '1h' => ['limit' => 2, 'period' => 3600],
            '24h' => ['limit' => 5, 'period' => 86400],
            '7d' => ['limit' => 10, 'period' => 604800],
        ],
    ];
    
    private const AMOUNT_LIMITS = [
        'deposit' => [
            '1h' => 50000000,
            '24h' => 200000000,
            '7d' => 1000000000,
        ],
        'withdrawal' => [
            '1h' => 20000000,
            '24h' => 100000000,
            '7d' => 500000000,
        ],
    ];
    
    private \App\Services\DistributedLockService $lockService;
    /** @var array<string, bool> */
    /** @var array<string, string> */
    private array $activeLocks = [];

    private \Core\Cache $cache;
    private \App\Contracts\LoggerInterface $logger;
    public function __construct(
        \Core\Cache $cache,
        \App\Contracts\LoggerInterface $logger,
        VelocityAndScoreModel $model,
        \App\Services\DistributedLockService $lockService
    ) {        $this->cache = $cache;
        $this->logger = $logger;

        
        $this->model = $model;
        $this->lockService = $lockService;

        // Register shutdown function to release any unreleased locks gracefully
        register_shutdown_function([$this, 'releaseAllLocks']);
    }
    
    /**
     * بررسی سرعت برای یک عملیات
     */
    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    public function check(int $userId, string $actionType, array $context = []): array
    {
        $this->logger->info('velocity.check_started', [
            'user_id' => $userId,
            'action_type' => $actionType
        ]);

        // 🔒 Enforce distributed locking when updating/counting velocity to prevent transaction race conditions
        $lockKey = "velocity_check:{$userId}:{$actionType}";
        $lock = $this->lockService->acquire($lockKey, 10, 5); // 10s TTL, 5s max wait
        if (!$lock['acquired']) {
            $this->logger->warning('velocity.lock_failed', ['user_id' => $userId, 'action_type' => $actionType]);
            return [
                'allowed' => false,
                'reason' => 'سیستم در حال حاضر مشغول است. لطفاً چند لحظه دیگر تلاش کنید.'
            ];
        }

        // Store the lock token
        $this->activeLocks[$lockKey] = str_value($lock['token']);
        
        $countCheck = $this->checkCountVelocity($userId, $actionType);
        if (!$countCheck['allowed']) {
            $this->releaseLock($lockKey);
            return $countCheck;
        }
        
        if (isset($context['amount']) && $this->hasAmountLimit($actionType)) {
            $amountCheck = $this->checkAmountVelocity(
                $userId, 
                $actionType, 
                float_value($context['amount'])
            );
            
            if (!$amountCheck['allowed']) {
                $this->releaseLock($lockKey);
                return $amountCheck;
            }
        }
        
        $patternCheck = $this->checkPatternVelocity($userId, $actionType, $context);
        if (!$patternCheck['allowed']) {
            $this->releaseLock($lockKey);
            return $patternCheck;
        }
        
        $remaining = $this->getRemainingCount($userId, $actionType);
        $this->releaseLock($lockKey);

        return [
            'allowed' => true,
            'reason' => null,
            'remaining' => $remaining,
        ];
    }
    
    /** @return array<string, mixed> */
    private function checkCountVelocity(int $userId, string $actionType): array
    {
        $rules = $this->getRules($actionType);
        
        if (empty($rules)) {
            return ['allowed' => true];
        }
        
        foreach ((array)$rules as $period => $config) {
            $limit = int_value($config['limit']);
            $seconds = int_value($config['period']);
            
            $cacheKey = "velocity:{$userId}:{$actionType}:{$period}";
            $count = $this->cache->get($cacheKey);

            // M-23 FIX: the previous logic called cache->set(count+1, $seconds) on EVERY check.
            // That (a) refreshed the TTL on each call, turning the intended fixed window into a
            // sliding one that never closes for an active user, and (b) double-counted — the cached
            // value grew by one per *check* on top of the DB seed, so it tracked how often the
            // limiter ran rather than the real attempt count. Now the counter is seeded from the
            // authoritative store only when the window key is first created (TTL set once), and each
            // attempt reserves its slot via an atomic increment whose TTL is applied only when the
            // key has no expiry (see Cache::increment), so repeated checks never extend the window.
            if ($count === null) {
                $count = int_value($this->model->getTransactionCount($userId, $actionType, $seconds));
                $this->cache->set($cacheKey, $count, $seconds);
            } else {
                $count = int_value($count);
            }

            if ($count >= $limit) {
                $this->logger->warning('velocity.limit_exceeded', [
                    'user_id' => $userId,
                    'action_type' => $actionType,
                    'period' => $period,
                    'count' => $count,
                    'limit' => $limit
                ]);
                
                return [
                    'allowed' => false,
                    'reason' => "محدودیت تعداد در {$period} رسیده است",
                    'limit' => $limit,
                    'current' => $count,
                    'period' => $period,
                    'reset_at' => time() + $seconds,
                ];
            }

            // M-23 FIX: reserve this attempt's slot atomically. increment() re-applies the TTL only
            // when the key currently has no expiry, so this never slides the fixed window forward.
            $this->cache->increment($cacheKey, 1, $seconds);
        }
        
        return ['allowed' => true];
    }
    
    /** @return array<string, mixed> */
    private function checkAmountVelocity(int $userId, string $actionType, float $amount): array
    {
        $limits = $this->getAmountLimits($actionType);
        
        if (empty($limits)) {
            return ['allowed' => true];
        }
        
        foreach ((array)$limits as $period => $maxAmount) {
            $seconds = $this->periodToSeconds($period);
            
            $currentTotal = $this->model->getTotalAmount($userId, $actionType, $seconds);
            $projectedTotal = $currentTotal + $amount;
            
            if ($projectedTotal > float_value($maxAmount)) {
                $this->logger->warning('velocity.amount_limit_exceeded', [
                    'user_id' => $userId,
                    'action_type' => $actionType,
                    'period' => $period,
                    'current_total' => $currentTotal,
                    'requested_amount' => $amount,
                    'projected_total' => $projectedTotal,
                    'limit' => $maxAmount
                ]);
                
                return [
                    'allowed' => false,
                    'reason' => "محدودیت مبلغ در {$period} رسیده است",
                    'limit' => $maxAmount,
                    'current_total' => $currentTotal,
                    'requested_amount' => $amount,
                    'remaining_amount' => max(0, float_value($maxAmount) - $currentTotal),
                    'period' => $period,
                ];
            }
        }
        
        return ['allowed' => true];
    }
    
    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    private function checkPatternVelocity(int $userId, string $actionType, array $context): array
    {
        if ($this->detectRepeatedTransactions($userId, $actionType, $context)) {
            return [
                'allowed' => false,
                'reason' => 'الگوی تراکنش‌های تکراری شناسایی شد',
                'pattern' => 'repeated_transactions'
            ];
        }
        
        if ($this->detectBurstPattern($userId, $actionType)) {
            return [
                'allowed' => false,
                'reason' => 'افزایش ناگهانی در تراکنش‌ها شناسایی شد',
                'pattern' => 'burst'
            ];
        }
        
        if (isset($context['amount']) && $this->detectRoundNumberPattern($userId, float_value($context['amount']))) {
            return [
                'allowed' => false,
                'reason' => 'الگوی مبالغ گرد مشکوک',
                'pattern' => 'round_numbers'
            ];
        }
        
        return ['allowed' => true];
    }
    
    /** @param array<string, mixed> $context */
    private function detectRepeatedTransactions(int $userId, string $actionType, array $context): bool
    {
        if (!isset($context['amount'])) {
            return false;
        }
        
        $count = $this->model->getRepeatedTransactionsCount($userId, $actionType, float_value($context['amount']));
        return $count >= 3;
    }
    
    private function detectBurstPattern(int $userId, string $actionType): bool
    {
        $recent     = $this->model->getTransactionCount($userId, $actionType, self::BURST_WINDOW_SECONDS);
        $historical = $this->model->getTransactionCount($userId, $actionType, self::HISTORICAL_WINDOW_SECS);

        $avgPer5Min = $historical / self::BURST_BUCKETS_PER_DAY;

        if ($avgPer5Min > 0 && $recent > ($avgPer5Min * self::BURST_MULTIPLIER)) {
            $this->logger->warning('velocity.burst_detected', [
                'user_id' => $userId,
                'action_type' => $actionType,
                'recent_count' => $recent,
                'avg_per_5min' => $avgPer5Min
            ]);
            
            return true;
        }
        
        return false;
    }
    
    private function detectRoundNumberPattern(int $userId, float $amount): bool
    {
        $roundNumbers = [10000, 50000, 100000, 500000, 1000000, 5000000, 10000000];
        
        if (in_array((int)$amount, $roundNumbers)) {
            $stats = $this->model->getRoundNumberStats($userId);
            
            if ($stats['total'] >= 5) {
                $roundRatio = $stats['round_count'] / $stats['total'];
                
                if ($roundRatio > 0.8) {
                    return true;
                }
            }
        }
        
        return false;
    }
    
    /** @param array<string, mixed> $context */
    public function record(int $userId, string $actionType, array $context = []): void
    {
        $rules = $this->getRules($actionType);
        
        foreach ((array)$rules as $period => $config) {
            $cacheKey = "velocity:{$userId}:{$actionType}:{$period}";
            $current = $this->cache->get($cacheKey) ?? 0;
            $this->cache->set($cacheKey, $current + 1, int_value($config['period']));
        }
        
        $this->logger->info('velocity.recorded', [
            'user_id' => $userId,
            'action_type' => $actionType,
            'context' => $context
        ]);

        // Release the lock immediately since record is complete
        $lockKey = "velocity_check:{$userId}:{$actionType}";
        $this->releaseLock($lockKey);
    }

    private function releaseLock(string $lockKey): void
    {
        if (isset($this->activeLocks[$lockKey])) {
            $token = $this->activeLocks[$lockKey];
            $this->lockService->release($lockKey, $token);
            unset($this->activeLocks[$lockKey]);
        }
    }

    public function releaseAllLocks(): void
    {
        foreach ($this->activeLocks as $lockKey => $token) {
            $this->lockService->release($lockKey, $token);
        }
        $this->activeLocks = [];
    }
    
    /** @param array<string, mixed> $rules */
    public function setCustomRules(string $actionType, array $rules): void
    {
        $cacheKey = "velocity:rules:{$actionType}";
        $this->cache->set($cacheKey, $rules, 86400);
    }
    
    public function reset(int $userId, string $actionType): void
    {
        $rules = $this->getRules($actionType);
        
        foreach ((array)$rules as $period => $config) {
            $cacheKey = "velocity:{$userId}:{$actionType}:{$period}";
            $this->cache->forget($cacheKey);
        }
        
        $this->logger->info('velocity.reset', [
            'user_id' => $userId,
            'action_type' => $actionType
        ]);
    }
    
    /** @return array<string, mixed> */
    public function getStatus(int $userId, string $actionType): array
    {
        $rules = $this->getRules($actionType);
        $status = [];
        
        foreach ((array)$rules as $period => $config) {
            $count = $this->model->getTransactionCount($userId, $actionType, int_value($config['period']));
            $limit = int_value($config['limit']);
            
            $status[$period] = [
                'count' => $count,
                'limit' => $limit,
                'remaining' => max(0, $limit - $count),
                'percentage' => min(100, round(($count / $limit) * 100, 2)),
            ];
        }
        
        return $status;
    }
    
    /** @return array<string, array{limit: int, period: int}> */
    private function getRules(string $actionType): array
    {
        $cacheKey = "velocity:rules:{$actionType}";
        $source = $this->cache->get($cacheKey);
        if ($source === null) $source = self::DEFAULT_RULES[$actionType] ?? [];
        if (!is_array($source)) throw new \UnexpectedValueException('Velocity rules must be an array.');
        $rules = [];
        foreach ($source as $period => $config) {
            if (!is_string($period) || !is_array($config)
                || !is_numeric($config['limit'] ?? null) || !is_numeric($config['period'] ?? null)) {
                throw new \UnexpectedValueException('Each velocity rule requires period and limit.');
            }
            $rules[$period] = ['limit' => int_value($config['limit']), 'period' => int_value($config['period'])];
        }
        return $rules;
    }
    
    /** @return array<string, mixed> */
    private function getAmountLimits(string $actionType): array
    {
        return self::AMOUNT_LIMITS[$actionType] ?? [];
    }
    
    private function hasAmountLimit(string $actionType): bool
    {
        return isset(self::AMOUNT_LIMITS[$actionType]);
    }
    
    /** @return array<string, mixed> */
    private function getRemainingCount(int $userId, string $actionType): array
    {
        $rules = $this->getRules($actionType);
        $remaining = [];
        
        foreach ((array)$rules as $period => $config) {
            $count = $this->model->getTransactionCount($userId, $actionType, int_value($config['period']));
            $remaining[$period] = max(0, int_value($config['limit']) - $count);
        }
        
        return $remaining;
    }
    
    private function periodToSeconds(string $period): int
    {
        return match($period) {
            '5m' => 300,
            '1h' => 3600,
            '24h' => 86400,
            '7d' => 604800,
            default => 0
        };
    }
}

