<?php

declare(strict_types=1);

namespace App\Services\Cache;

use Core\Cache;
use App\Contracts\CacheInterface;
use App\Contracts\LoggerInterface;

/**
 * CacheManager - Wrapper استاندارد برای دسترسی به سیستم کش
 * با پشتیبانی کامل از Tags و متدهای الحاقی قرارداد
 */
class CacheManager implements CacheInterface
{
    /** @var list<string> */
    private array $currentTags = [];

    private CacheInterface|\Core\Cache $cache;
    public function __construct(
        CacheInterface|\Core\Cache $cache
    ) {        $this->cache = $cache;
}

    /**
     * @return CacheInterface|\Core\Cache|\Core\TaggedCache
     */
    private function getHandler(): CacheInterface|\Core\Cache|\Core\TaggedCache
    {
        return empty($this->currentTags) ? $this->cache : $this->cache->tags($this->currentTags);
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->getHandler()->get($key, $default);
    }

    public function set(string $key, mixed $value, ?int $ttlSeconds = null): bool
    {
        if ($ttlSeconds === null) {
            if (empty($this->currentTags)) {
                if ($this->cache instanceof \Core\Cache) {
                    return $this->cache->forever($key, $value);
                }
                return $this->cache->set($key, $value);
            }
            // TaggedCache doesn't have forever(), emulate with 1 year
            return $this->cache->tags($this->currentTags)->put($key, $value, 525600);
        }

        $minutes = max(1, (int) ceil($ttlSeconds / 60));
        return $this->getHandler()->put($key, $value, $minutes);
    }

    public function put(string $key, mixed $value, int $minutes = 60): bool
    {
        return $this->set($key, $value, $minutes * 60);
    }

    public function delete(string $key): bool
    {
        if (empty($this->currentTags)) {
            return $this->cache->forget($key);
        }
        return $this->getHandler()->forget($key);
    }

    public function forget(string $key): bool
    {
        return $this->delete($key);
    }

    public function increment(string $key, int $step = 1): int
    {
        if (!empty($this->currentTags)) {
            throw new \BadMethodCallException('increment is not supported on tagged cache.');
        }
        $result = $this->cache->increment($key, $step);
        return $result !== false ? $result : 0;
    }

    public function decrement(string $key, int $step = 1): int
    {
        if (!empty($this->currentTags)) {
            throw new \BadMethodCallException('decrement is not supported on tagged cache.');
        }
        $result = $this->cache->decrement($key, $step);
        return $result !== false ? $result : 0;
    }

    public function has(string $key): bool
    {
        return $this->getHandler()->has($key);
    }

    public function ttl(string $key): int
    {
        if (!empty($this->currentTags)) {
            throw new \BadMethodCallException('ttl is not supported on tagged cache.');
        }
        return $this->cache->ttl($key);
    }

    public function flush(): bool
    {
        if (empty($this->currentTags)) {
            // ⚠️ WARNING: flush() کل cache را پاک می‌کند — فقط برای emergency.
            // در production به صورت پیش‌فرض ممنوع است چون می‌تواند session/rate-limit/cacheهای
            // توزیع‌شده را همزمان پاک کند. برای پنجره اضطراری باید صریحاً فعال شود.
            $isProduction = str_value(config('app.env', env('APP_ENV', 'production'))) === 'production';
            $allowFullFlush = (bool)env('ALLOW_FULL_CACHE_FLUSH', false);
            if ($isProduction && !$allowFullFlush) {
                if (function_exists('logger')) {
                    try {
                        logger()->critical('cache.full_flush_blocked', [
                            'caller' => debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2)[1]['function'] ?? 'unknown',
                            'reason' => 'Full cache flush is blocked in production. Use tags()->flush() or set ALLOW_FULL_CACHE_FLUSH=true for a short emergency window.',
                        ]);
                    } catch (\Throwable) {}
                }
                return false;
            }

            if (function_exists('logger')) {
                try {
                    logger()->warning('cache.full_flush_called', [
                        'caller' => debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2)[1]['function'] ?? 'unknown',
                        'note'   => 'Full cache flush — consider using tags()->flush() instead',
                    ]);
                } catch (\Throwable) {}
            }
            return $this->cache->flush();
        }
        $this->getHandler()->flush();
        return true;
    }

    public function tags(array $tags): self
    {
        $clone = clone $this;
        $clone->currentTags = $tags;
        return $clone;
    }

    public function remember(string $key, ?int $ttlSeconds, \Closure $callback): mixed
    {
        return $this->getOrSet($key, $callback, $ttlSeconds);
    }

    public function rememberForever(string $key, \Closure $callback): mixed
    {
        return $this->remember($key, null, $callback);
    }

    public function getOrSet(string $key, callable $callback, ?int $ttlSeconds = null): mixed
    {
        if ($ttlSeconds === null) {
            if (empty($this->currentTags)) {
                 return $this->cache->rememberForever($key, $callback);
             }
             $ttlSeconds = 525600 * 60; // 1 year for tagged
        }
        $minutes = max(1, (int) ceil($ttlSeconds / 60));
        return $this->getHandler()->remember($key, $minutes, $callback);
    }

    public function driver(): string
    {
        return $this->cache->driver();
    }

    public function redis(): ?\Redis
    {
        return $this->cache->redis();
    }

    public function redisKey(string $key): string
    {
        // Tags shouldn't affect redisKey directly unless requested, we forward to core Cache
        if (method_exists($this->cache, 'redisKey')) {
            return $this->cache->redisKey($key);
        }
        return $key;
    }
public function lock(string $key, int $ttl = 30, int $wait = 1): bool
    {
        if (!empty($this->currentTags)) {
            throw new \BadMethodCallException('lock is not supported on tagged cache.');
        }
        return $this->cache->lock($key, $ttl, $wait);
    }

    public function unlock(string $key): bool
    {
        if (!empty($this->currentTags)) {
            throw new \BadMethodCallException('unlock is not supported on tagged cache.');
        }
        return $this->cache->unlock($key);
    }
}
