<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\CacheInterface;
use App\Contracts\LoggerInterface;

/**
 * CacheAdminService
 * مدیریت cache برای بخش ادمین
 */
class CacheAdminService
{
    private CacheInterface $cacheAdmin;
    private \Core\PathResolver $paths;
    private \App\Contracts\LoggerInterface $logger;
    public function __construct(
        \App\Contracts\LoggerInterface $logger,
        CacheInterface $cache,
        \Core\PathResolver $paths
    ) {        $this->logger = $logger;

        
        $this->cacheAdmin = $cache;
        $this->paths = $paths;
    }

    /**
     * پاک کردن cache بر اساس نوع
     */
    /** @return array<string, mixed> */
    public function clear(string $type = 'all', string $tag = ''): array
    {
        $cleared = 0;

        try {
            if ($type === 'settings') {
                // پاکسازی کلیدهای جدید و قدیم کش
                $this->cacheAdmin->delete('system:settings:v2');
                $this->cacheAdmin->delete('system:settings');

                // پاکسازی فایلهای باقیمانده و منسوخ جهت سبک‌سازی دیسک
                $legacyJson = $this->paths->storage('cache/system_settings.json');
                if (file_exists($legacyJson)) {
                    @unlink($legacyJson);
                }
                $legacyPhp = $this->paths->storage('cache/system_settings.php');
                if (file_exists($legacyPhp)) {
                    @unlink($legacyPhp);
                }
                
                $cleared = 1;
            } elseif ($type === 'kpi') {
                $this->cacheAdmin->delete('kpi:dashboard:summary');
                $this->cacheAdmin->delete('kpi:weekly_report');
                $cleared = 2;
            } elseif ($type === 'tags' && $tag !== '') {
                $this->cacheAdmin->tags([$tag])->flush();
                $cleared = "tag:{$tag}";
            } else {
                // ✅ به جای flush() کل — پاک کردن tag‌های اصلی
                // flush() همه cache از جمله session و rate-limit را هم پاک می‌کند
                // این رویکرد فقط cache‌های قابل بازسازی را پاک می‌کند
                $mainTags = [
                    'settings', 'kpi', 'analytics', 'user', 'wallet',
                    'payment', 'feature_flag', 'search', 'sentry',
                ];
                foreach ($mainTags as $t) {
                    try {
                        $this->cacheAdmin->tags([$t])->flush();
                    } catch (\Throwable) {
                        // tag ممکن است وجود نداشته باشد
                    }
                }
                // کلیدهای بدون tag که باید حذف شوند
                $standaloneKeys = [
                    'system:settings:v2', 'system:settings',
                    'kpi:dashboard:summary', 'kpi:weekly_report',
                    'sentry:dashboard:overview_v2',
                ];
                foreach ($standaloneKeys as $k) {
                    $this->cacheAdmin->delete($k);
                }
                $cleared = 'همه (tag-based)';
            }

            return ['success' => true, 'message' => "Cache پاک شد ({$cleared} آیتم)"];
        } catch (\Throwable $e) {
            $this->logger->error('cache.clear.failed', ['error' => $e->getMessage()]);
            return ['success' => false, 'message' => 'خطا در پاک کردن cache'];
        }
    }

    /**
     * فراموشی یک کلید خاص
     */
    public function forget(string $key): bool
    {
        try {
            $this->cacheAdmin->delete($key);
            return true;
        } catch (\Throwable $e) {
            $this->logger->error('cache.forget.failed', ['key' => $key, 'error' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * ریست کردن Circuit Breaker
     */
    public function resetCircuitBreaker(string $name): bool
    {
        try {
            $key = "circuit_breaker:{$name}";
            $this->cacheAdmin->delete($key);
            
            // همچنین حذف هرگونه قفل احتمالی باقیمانده
            $this->cacheAdmin->delete("cb_state_{$name}");
            
            $this->logger->info('cache.circuit_breaker.reset', ['name' => $name]);
            return true;
        } catch (\Throwable $e) {
            $this->logger->error('cache.circuit_breaker.reset_failed', ['name' => $name, 'error' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * آمار cache
     */
    /** @return array<string, mixed> */
    public function getStats(): array
    {
        $driver = $this->cacheAdmin->driver();

        if ($driver === 'redis') {
            $stats = $this->getRedisStats();
        } else {
            $stats = $this->getFileStats();
        }

        $stats['driver'] = $driver;
        return $stats;
    }

    /**
     * آمار Redis
     */
    /** @return array<string, mixed> */
    private function getRedisStats(): array
    {
        try {
            $redis  = $this->cacheAdmin->redis();
            if (!$redis instanceof \Redis) {
                throw new \RuntimeException('Redis client is unavailable.');
            }
            $prefix = config('redis.prefix', 'chortke') . ':';

            $infoResult = $redis->info();
            $info = is_array($infoResult) ? $infoResult : [];
            // استفاده از SCAN به‌جای KEYS برای جلوگیری از blocking در مقیاس بزرگ
            $keys = [];
            $cursor = null;
            do {
                $batch = $redis->scan($cursor, $prefix . '*', 100);
                if ($batch === false) {
                    break;
                }
                $keys = array_merge($keys, $batch);
            } while ($cursor !== 0);
            $sample = [];

            foreach (array_slice($keys, 0, 50) as $k) {
                $ttl    = $redis->ttl($k);
                $type   = $redis->type($k); // phpredis: 1 = REDIS_STRING
                $sizeResult = $type === 1 ? $redis->strlen((string)$k) : 0;
                $sample[] = (object)[
                    'key'       => str_replace($prefix, '', $k),
                    'ttl'       => $ttl,
                    'expire_at' => (is_int($ttl) && $ttl > 0) ? time() + $ttl : 0,
                    'type'      => $type,
                    // فقط برای مقادیر رشته‌ای اندازه‌ی بایت محاسبه می‌شود (STRLEN روی غیررشته خطا می‌دهد)
                    'size_bytes' => is_int($sizeResult) ? $sizeResult : 0,
                ];
            }

            return [
                'total_keys'        => count($keys),
                'used_memory'       => $info['used_memory_human'] ?? '—',
                'connected_clients' => $info['connected_clients'] ?? '—',
                'uptime_days'       => isset($info['uptime_in_seconds'])
                    ? round($info['uptime_in_seconds'] / 86400, 1)
                    : '—',
                'hit_rate'          => $this->calcHitRate($info),
                'keys'              => $sample,
                'total_files'       => 0,
                'valid_files'       => 0,
                'expired_files'     => 0,
                'total_size_kb'     => 0,
            ];
        } catch (\Throwable $e) {
            $this->logger->error('cache.redis_stats.failed', ['error' => $e->getMessage()]);
            return [
                'error' => 'internal_error',
                'keys'  => [],
                'total_files'   => 0,
                'valid_files'   => 0,
                'expired_files' => 0,
                'total_size_kb' => 0,
            ];
        }
    }

    /**
     * آمار فایل‌های cache
     */
    /** @return array<string, mixed> */
    private function getFileStats(): array
    {
        $cacheDir   = rtrim($this->paths->storage('cache/app'), '/\\') . DIRECTORY_SEPARATOR;
        $files      = glob($cacheDir . '*.cache') ?: [];
        $totalFiles = count($files);
        $validFiles = $expiredFiles = $totalBytes = 0;
        $keys       = [];

        foreach ($files as $file) {
            $raw = file_get_contents($file);
            if ($raw === false || $raw === '') {
                continue;
            }

            // امن‌تر از unserialize خام
            try {
                $data = unserialize($raw, ['allowed_classes' => false]);
            } catch (\Throwable $e) {
                $data = null;
            }

            if (!is_array($data)) {
                continue;
            }

            $sz = filesize($file);
            if ($sz !== false) {
                $totalBytes += $sz;
            }

            $expireAt = intval($data['expire_at'] ?? 0);

            if ($expireAt > 0 && $expireAt < time()) {
                $expiredFiles++;
            } else {
                $validFiles++;
            }

            $keys[] = (object)[
                'key'       => basename($file, '.cache'),
                'expire_at' => $expireAt,
                'ttl'       => $expireAt > 0 ? max(0, $expireAt - time()) : 0,
                'type'      => 'string',
                'size_bytes' => (int)($sz ?: 0),
            ];
        }

        return [
            'total_files'   => $totalFiles,
            'valid_files'   => $validFiles,
            'expired_files' => $expiredFiles,
            'total_size_kb' => round($totalBytes / 1024, 1),
            'total_keys'    => $validFiles,
            'keys'          => array_slice($keys, 0, 50),
        ];
    }

    /**
     * محاسبه نرخ موفقیت cache
     */
    /** @param array<string, mixed> $info */
    private function calcHitRate(array $info): string
    {
        $hitsValue = $info['keyspace_hits'] ?? 0;
        $missesValue = $info['keyspace_misses'] ?? 0;
        $hits = is_numeric($hitsValue) ? (int)$hitsValue : 0;
        $misses = is_numeric($missesValue) ? (int)$missesValue : 0;
        $total  = $hits + $misses;
        if ($total === 0) {
            return '—';
        }
        return round($hits / $total * 100, 1) . '%';
    }
}
