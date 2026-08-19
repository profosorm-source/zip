<?php

declare(strict_types=1);

namespace App\Services\Settings;

use App\Models\Setting;
use Core\Cache;
use App\Contracts\LoggerInterface;
use Core\Database;

class AppSettings
{
    private Setting $model;
    /** @var array<string, mixed>|null */
    private ?array $runtimeCache = null;

    private const CACHE_KEY = 'system:settings:v2';
    private const CACHE_TTL = 60; // minutes

    private \Core\Cache $cache;
    private \App\Contracts\LoggerInterface $logger;

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
        \Core\Cache $cache,
        \App\Contracts\LoggerInterface $logger,
        Setting $model
    ) {        $this->cache = $cache;
        $this->logger = $logger;

                $this->model = $model;
        }

    /**
     * BUGFIX-STAMPEDE-2026-06: Single-flight settings load.
     *
     * The previous implementation suffered from a classic cache-stampede
     * (a.k.a. thundering-herd) problem: when the Redis key expired (every
     * 60 minutes) or after a cache flush, N concurrent FPM workers would
     * all observe a cache miss simultaneously and each issue the SAME
     * `SELECT * FROM system_settings` query against MariaDB. Under realistic
     * load this multiplies DB pressure by the number of concurrent in-flight
     * requests and is a known DoS vector against the settings table.
     *
     * The fix is the standard single-flight / mutex pattern:
     *   1. fast paths: runtime memo → cache → return
     *   2. on miss, acquire a short distributed lock (Redis SETNX)
     *   3. inside the critical section, double-check the cache (someone
     *      may have populated it while we waited)
     *   4. load from DB exactly once, populate cache, release lock
     *   5. if the lock cannot be acquired in $wait seconds, fall back to
     *      a direct DB read so callers never block indefinitely
     */
    /** @return array<string, mixed> */
    public function load(): array
    {
        // ── Fast path 1: per-request memoization (no I/O) ────────────────
        if ($this->runtimeCache !== null) {
            return $this->runtimeCache;
        }

        // ── Fast path 2: distributed cache hit ───────────────────────────
        $cachedData = $this->cache->get(self::CACHE_KEY);
        if (is_array($cachedData)) {
            $this->runtimeCache = $cachedData;
            return $cachedData;
        }

        // ── Slow path: single-flight under distributed lock ──────────────
        $lockKey  = self::CACHE_KEY . ':load_lock';
        $haveLock = false;
        try {
            // Lock TTL 5s is well above the typical SELECT * FROM system_settings
            // latency (~1-10 ms). Wait up to 2s for an in-flight loader.
            $haveLock = $this->cache->lock($lockKey, 5, 2);
        } catch (\Throwable $e) {
            // lock() may throw in production without Redis. Degrade safely.
            $this->logger->warning('settings.lock_unavailable', ['error' => $e->getMessage()]);
        }

        // Double-check cache after the wait — another worker may have populated it.
        if ($haveLock) {
            $cachedData = $this->cache->get(self::CACHE_KEY);
            if (is_array($cachedData)) {
                $this->safeUnlock($lockKey);
                $this->runtimeCache = $cachedData;
                return $cachedData;
            }
        }

        try {
            $rawSettings = $this->model->getAll();
            $parsedSettings = [];

            foreach ($rawSettings as $row) {
                $key = (string)($row->key ?? '');
                if ($key === '') continue;
                $parsedSettings[$key] = $this->castValue($row->value ?? '', (string)($row->type ?? 'string'));
            }

            // Only the lock holder writes — prevents redundant cache writes
            // when many workers raced and all got past the initial miss check.
            if ($haveLock) {
                $this->cache->put(self::CACHE_KEY, $parsedSettings, self::CACHE_TTL);
            }
            $this->runtimeCache = $parsedSettings;

            return $parsedSettings;

        } catch (\Throwable $e) {
            $this->logger->error('settings.load_failed', ['error' => $e->getMessage()]);
            return [];
        } finally {
            // Release lock regardless of success / failure to avoid deadlocks
            // if the DB read throws.
            if ($haveLock) {
                $this->safeUnlock($lockKey);
            }
        }
    }

    /**
     * Best-effort lock release. Never propagates exceptions so it can be
     * called from a `finally` block on any error path.
     */
    private function safeUnlock(string $lockKey): void
    {
        try {
            $this->cache->unlock($lockKey);
        } catch (\Throwable $e) {
            // Lock will expire on its own (TTL=5s). Log and move on.
            $this->logger->warning('settings.unlock_failed', ['error' => $e->getMessage()]);
        }
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $all = $this->load();

        if (array_key_exists($key, $all)) {
            return $all[$key];
        }

        // Fallback: if not found in database/cache, read from file config
        return \config($key, $default);
    }

    public function set(string $key, mixed $value): void
    {
        if ($this->runtimeCache === null) {
            $this->load();
        }
        $this->runtimeCache[$key] = $value;
    }

    public function getFloat(string $key, float $default = 0.0): float
    {
        $value = $this->get($key, $default);
        return is_numeric($value) ? (float)$value : $default;
    }

    /** @return array<int, \stdClass> */
    public function getByCategory(string $category): array
    {
        $settings = $this->model->getByCategory($category);
        
        // 🛡️ ADMIN SETTINGS AUTO-SEED GUARD: شیدینگ خودکار رکوردهای تنظیمات تبلیغات ویدیویی
        // حل مشکل هاردکد بودن و عدم نمایش رکوردهای کانفیگ در پنل مدیریت ادمین
        if ($category === 'video_ads' && empty($settings)) {
            $defaultVideoAds = [
                ['key' => 'tapsell_base_rate_irt', 'value' => '150', 'type' => 'number', 'desc' => 'مبلغ پایه تپسل (تومان)'],
                ['key' => 'tapsell_user_share', 'value' => '70', 'type' => 'number', 'desc' => 'درصد سهم کاربر از تپسل'],
                ['key' => 'admob_base_rate_usdt', 'value' => '0.02', 'type' => 'number', 'desc' => 'مبلغ پایه ادموب (USDT)'],
                ['key' => 'admob_user_share', 'value' => '70', 'type' => 'number', 'desc' => 'درصد سهم کاربر از ادموب'],
                ['key' => 'withdraw_priority_hours', 'value' => '2', 'type' => 'number', 'desc' => 'ساعات تسریع در واریز برداشت'],
                ['key' => 'vip_task_multiplier', 'value' => '1.5', 'type' => 'number', 'desc' => 'ضریب پاداش تسک‌های VIP'],
                ['key' => 'lottery_chance_boost', 'value' => '25', 'type' => 'number', 'desc' => 'درصد افزایش شانس قرعه‌کشی'],
                ['key' => 'xp_growth_rate', 'value' => '50', 'type' => 'number', 'desc' => 'درصد سرعت ارتقای سطح کاربری'],
                ['key' => 'ticket_vip_sla_minutes', 'value' => '30', 'type' => 'number', 'desc' => 'دقایق اولویت پاسخگویی تیکت'],
                ['key' => 'dispute_express_queue', 'value' => '1', 'type' => 'boolean', 'desc' => 'پرچم ارجاع فوری داوری به داور ارشد'],
                ['key' => 'kyc_express_minutes', 'value' => '15', 'type' => 'number', 'desc' => 'دقایق بررسی و تأیید فوری مدارک هویتی'],
                ['key' => 'referral_boost_percent', 'value' => '2', 'type' => 'number', 'desc' => 'شتاب‌دهنده ۲۴ ساعته کمیسیون زیرمجموعه‌گیری'],
                ['key' => 'vitrine_free_bump_enabled', 'value' => '1', 'type' => 'boolean', 'desc' => 'پرچم نردبان و درخشان کردن آگهی در ویترین'],
                ['key' => 'deposit_fast_track_enabled', 'value' => '1', 'type' => 'boolean', 'desc' => 'پرچم تسریع در تأیید فیش واریزی'],
            ];

            $db = \Core\Database::getInstance();
            foreach ($defaultVideoAds as $item) {
                try {
                    $db->prepare("INSERT IGNORE INTO settings (`key`, `value`, `category`, `type`, `description`, `created_at`, `updated_at`) VALUES (?, ?, 'video_ads', ?, ?, NOW(), NOW())")
                       ->execute([$item['key'], $item['value'], $item['type'], $item['desc']]);
                } catch (\Throwable $ignore) {}
            }
            $settings = $this->model->getByCategory($category);
        }

        return $settings;
    }

    public function find(int $id): ?\stdClass
    {
        $rec = $this->toObject($this->model->find($id));
        if (!$rec) { return null; }
        return $rec;
    }

    public function findByKey(string $key): ?object
    {
        return $this->model->findByKey($key);
    }

    /** @return array<string, mixed> */
    public function loadAll(): array
    {
        return $this->load();
    }

    public function clearInstanceCache(): void
    {
        $this->runtimeCache = null;
    }

    public function clearCache(): void
    {
        $this->runtimeCache = null;
        try {
            $this->cache->forget(self::CACHE_KEY);
        } catch (\Throwable $e) {
            try {
                $this->cache->forget(self::CACHE_KEY);
            } catch (\Throwable $ignored) {
                $this->logger->warning('settings.cache_clear_failed', ['error' => $e->getMessage()]);
            }
        }
    }

    private function castValue(?string $value, string $type): mixed
    {
        if ($value === null) {
            return null;
        }

        $type = strtolower(trim((string)$type));

        switch ($type) {
            case 'boolean':
            case 'bool':
                if (in_array(strtolower((string)$value), ['false', '0', 'no', 'off', ''], true)) {
                    return false;
                }
                return true;

            case 'integer':
            case 'int':
                return (int) $value;

            case 'float':
            case 'double':
            case 'numeric':
                return (float) $value;

            case 'json':
            case 'array':
                $decoded = (array)(json_decode($value, true) ?? []);
                return is_array($decoded) ? $decoded : [];

            case 'string':
            default:
                return (string) $value;
        }
    }
}
