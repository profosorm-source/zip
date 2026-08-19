<?php

namespace App\Policies;

use Core\RateLimiter;
use Core\Logger;
use App\Models\FeatureFlag;

/**
 * RateLimitPolicy
 * 
 * این سرویس یکپارچه جایگزین ApiRateLimiter و AntiFraud\RateLimitingService است.
 * از Core\RateLimiter (Redis/Cache) به جای Database برای پرفورمنس استفاده می‌کند.
 */
class RateLimitPolicy
{
    private ?RateLimiter $limiter = null;

    private Logger $logger;
    private ?\App\Models\FeatureFlag $featureFlagModel;
    private \Core\Redis $redis;

    private const ACTIONS = [
        'withdrawal'         => 'withdrawal_limits',
        'manual_deposit'     => 'financial_limits',
        'crypto_deposit'     => 'financial_limits',
        'bank_card_add'      => 'financial_limits',
        'task_submit'        => 'task_limits',
        'task_dispute'       => 'task_limits',
        'kyc_submit'         => 'security_limits',
        'profile_update'     => 'user_limits',
        'password_change'    => 'security_limits',
        'ticket_create'      => 'support_limits',
        'ticket_reply'       => 'support_limits',
        'login'              => 'auth_limits',
    ];

    public function __construct(Logger $logger, \Core\Redis $redis, ?\App\Models\FeatureFlag $featureFlagModel = null) {
        $this->logger = $logger;
        $this->redis = $redis;
        $this->featureFlagModel = $featureFlagModel;
    }

    /**
     * Lazy-accessor for RateLimiter — breaks the circular dependency:
     * RateLimiter → RateLimitingService → RateLimitPolicy →(no longer)→ RateLimiter
     *
     * RateLimiter is resolved on FIRST USE (not during construction), so the
     * container can build RateLimitPolicy without needing RateLimiter first.
     * By the time getLimiter() is actually called, RateLimiter will already
     * be fully constructed in the container.
     */
    private function getLimiter(): RateLimiter
    {
        if ($this->limiter !== null) {
            return $this->limiter;
        }
        try {
            /** @var RateLimiter $resolved */
            $resolved = \Core\Container::getInstance()->make(RateLimiter::class);
            $this->limiter = $resolved;
        } catch (\Throwable $e) {
            throw new \RuntimeException('RateLimiter not available: ' . $e->getMessage(), 0, $e);
        }
        return $this->limiter;
    }

    /**
     * بررسی محدودیت با استفاده از FeatureFlag
     */
    public function check(string $action, string|int $identifier, ?string $limitKey = null): bool
    {
        if ($this->isWhitelisted($identifier)) {
            return true;
        }

        $config = $this->resolveActionConfig($action, $limitKey);

        $key = "rl_{$action}_{$identifier}";
        if (!empty($config['window_seconds'])) {
            $allowed = $this->attemptSecondsWindow($key, int_value($config['max_attempts']), int_value($config['window_seconds']));
        } else {
            $allowed = $this->getLimiter()->attempt($key, int_value($config['max_attempts']), int_value($config['decay_minutes']));
        }

        if (!$allowed) {
            $this->logger->warning('rate_limit_exceeded', [
                'action'     => $action,
                'identifier' => $identifier,
                'limit'      => $config['max_attempts'],
                'window_min' => $config['decay_minutes'],
            ]);
        }
        return $allowed;
    }

    private function isWhitelisted(string|int $identifier): bool
    {
        $candidate = (string)$identifier;

        $whitelist = config('rate_limits.whitelist', []);
        if (is_array($whitelist) && in_array($candidate, array_map('strval', $whitelist), true)) {
            return true;
        }

        if ($this->featureFlagModel) {
            try {
                $flag = $this->featureFlagModel->findByName('rate_limit_whitelist');
                if ($flag && !empty($flag->metadata)) {
                    $metadata = (array)(json_decode($flag->metadata, true) ?? []);
                    if (is_array($metadata) && isset($metadata['whitelist']) && is_array($metadata['whitelist'])) {
                        if (in_array($candidate, array_map('strval', $metadata['whitelist']), true)) {
                            return true;
                        }
                    }
                }
            } catch (\Throwable $e) {
                $this->logger->warning('rate_limit.whitelist_lookup_failed', [
                    'identifier' => $candidate,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return false;
    }

    /**
     * @return array{max_attempts:int, decay_minutes:int, window_seconds?:int|null}
     */
    private function getFeatureConfig(string $featureName, string $limitKey): array
    {
        // 1) Highest priority: ops-controlled FeatureFlag (live overrides without deploy).
        if ($this->featureFlagModel) {
            try {
                $flag = $this->featureFlagModel->findByName($featureName);
                if ($flag) {
                    $metadata = (array)(json_decode($flag->metadata ?? '{}', true) ?? []);
                    $entry = $metadata[$limitKey] ?? null;
                    if (is_array($entry)) {
                        return [
                            'max_attempts' => int_value($entry['max'] ?? 5),
                            'decay_minutes' => int_value($entry['window'] ?? 60),
                            'window_seconds' => isset($entry['window_seconds']) ? int_value($entry['window_seconds']) : null,
                        ];
                    }
                }
            } catch (\Throwable $e) {
                // Don't fail-closed silently here — drop to next source.
                $this->logger->warning('rate_limit.feature_flag_lookup_failed', [
                    'feature' => $featureName,
                    'error'   => $e->getMessage(),
                ]);
            }
        }

        // 2) Section 8.8 — Fallback to config/rate_limits.php (single source of truth).
        //    The legacy 'standard'/'standard' lookup path is preserved for old callers,
        //    but new callers should pass the action name and let resolveByAction() find
        //    the right (group, endpoint) pair via the action_map.
        $cfg = config("rate_limits.{$featureName}");
        if (is_array($cfg)) {
            // grouped: pick $limitKey or its first nested entry
            if (isset($cfg[$limitKey]) && is_array($cfg[$limitKey]) && isset($cfg[$limitKey]['max_attempts'])) {
                return [
                    'max_attempts' => int_value($cfg[$limitKey]['max_attempts']),
                    'decay_minutes' => int_value($cfg[$limitKey]['decay_minutes'] ?? 1),
                ];
            }
            if (isset($cfg['max_attempts'])) {
                return [
                    'max_attempts' => int_value($cfg['max_attempts']),
                    'decay_minutes' => int_value($cfg['decay_minutes'] ?? 1),
                ];
            }
        }

        // 3) Default from config (instead of hard restrictive 3/24h).
        $defaultValue = config('rate_limits.default', ['max_attempts' => 60, 'decay_minutes' => 1]);
        if (!is_array($defaultValue)) throw new \UnexpectedValueException('rate_limits.default must be an array.');
        $default = $defaultValue;
        return [
            'max_attempts'  => int_value($default['max_attempts'] ?? 60),
            'decay_minutes' => int_value($default['decay_minutes'] ?? 1),
        ];
    }

    /**
     * Section 8.8 — Resolve config for an action using config('rate_limits.action_map').
     * Used by check() / retryAfter() / remaining() to avoid duplicating the
     * old hardcoded ACTIONS constant on every change.
     */
    /**
     * @return array{max_attempts:int, decay_minutes:int, window_seconds:int|null, message:mixed}
     */
    private function resolveActionConfig(string $action, ?string $limitKey): array
    {
        $map = config('rate_limits.action_map');
        if (is_array($map) && isset($map[$action]) && is_array($map[$action])) {
            $group    = (string)($map[$action][0] ?? '');
            $endpoint = (string)($map[$action][1] ?? ($limitKey ?? 'general'));
            $cfg = config("rate_limits.{$group}.{$endpoint}");
            if (is_array($cfg) && isset($cfg['max_attempts'])) {
                return [
                    'max_attempts' => int_value($cfg['max_attempts']),
                    'decay_minutes' => int_value($cfg['decay_minutes'] ?? 1),
                    'window_seconds' => isset($cfg['window_seconds']) ? (int)$cfg['window_seconds'] : null,
                    'message'       => $cfg['message'] ?? null,
                ];
            }
        }

        // Backward-compatible path: legacy ACTIONS constant + FeatureFlag.
        $featureName = self::ACTIONS[$action] ?? 'rate_limiting';
        $cfg = $this->getFeatureConfig($featureName, $limitKey ?? 'standard');
        return [
            'max_attempts'  => int_value($cfg['max_attempts']),
            'decay_minutes' => int_value($cfg['decay_minutes']),
            'window_seconds' => isset($cfg['window_seconds']) ? int_value($cfg['window_seconds']) : null,
            'message'       => null,
        ];
    }
    
    private function attemptSecondsWindow(string $key, int $maxAttempts, int $windowSeconds): bool
    {
        $redis = $this->redis;
        if ($redis->isAvailable()) {
            $client = $redis->getClient();
            if ($client === null) throw new \RuntimeException('Redis reported available without a client.');
            $current = int_value($client->get($key) ?: 0);
            if ($current >= $maxAttempts) {
                return false;
            }
            $newValue = $client->incr($key);
            if ($newValue === 1) {
                $client->expire($key, max(1, $windowSeconds));
            }
            return $newValue <= $maxAttempts;
        }

        return $this->getLimiter()->attempt($key, $maxAttempts, max(1, (int)ceil($windowSeconds / 60)));
    }

    public function retryAfter(string $action, string|int $identifier): int
    {
        $key = "rl_{$action}_{$identifier}";
        return $this->getLimiter()->availableIn($key);
    }

    public function remaining(string $action, string|int $identifier, ?string $limitKey = null): int
    {
        $config = $this->resolveActionConfig($action, $limitKey);
        $key = "rl_{$action}_{$identifier}";
        $attempts = $this->getLimiter()->getAttempts($key);
        return max(0, int_value($config['max_attempts']) - int_value($attempts));
    }

    public function tooManyResponse(string $action, string|int $identifier, bool $isAjax = false): void
    {
        $retryAfter = $this->retryAfter($action, $identifier);
        $retryMins = (int)ceil($retryAfter / 60);

        http_response_code(429);
        header('Retry-After: ' . $retryAfter);

        if ($isAjax || (str_contains(str_value(app()->request->header('accept') ?? ''), 'application/json'))) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'success' => false,
                'message' => "تعداد درخواست‌های شما بیش از حد مجاز است. لطفاً {$retryMins} دقیقه دیگر تلاش کنید.",
                'retry_after' => $retryAfter,
            ]);
        } else {
            echo "<h1>429 - Too Many Requests</h1>";
        }
        exit;
    }

    public static function enforce(string $action, string|int $identifier, bool $isAjax = false): void
    {
        $instance = app(self::class);
        if (!$instance->check($action, $identifier)) {
            $instance->tooManyResponse($action, $identifier, $isAjax);
        }
    }
}
