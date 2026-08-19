<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use App\Contracts\CacheInterface;

final class CorruptSearchCache implements CacheInterface
{
    public function get(string $key, mixed $default = null): mixed { return 'corrupt-cache-payload'; }
    public function set(string $key, mixed $value, ?int $ttlSeconds = null): bool { return true; }
    public function delete(string $key): bool { return true; }
    public function put(string $key, mixed $value, int $minutes = 60): bool { return true; }
    public function forget(string $key): bool { return true; }
    public function increment(string $key, int $step = 1): int { return $step; }
    public function decrement(string $key, int $step = 1): int { return -$step; }
    public function getOrSet(string $key, callable $callback, ?int $ttlSeconds = null): mixed { return 'corrupt-cache-payload'; }
    public function remember(string $key, ?int $ttlSeconds, \Closure $callback): mixed { return 'corrupt-cache-payload'; }
    public function rememberForever(string $key, \Closure $callback): mixed { return 'corrupt-cache-payload'; }
    public function has(string $key): bool { return true; }
    public function ttl(string $key): int { return 60; }
    public function flush(): bool { return true; }
    public function driver(): string { return 'corrupt-test-fixture'; }
    public function tags(array $tags): CacheInterface { return $this; }
    public function redis(): ?\Redis { return null; }
    public function lock(string $key, int $ttl = 30, int $wait = 1): bool { return true; }
    public function unlock(string $key): bool { return true; }
    public function redisKey(string $key): string { return $key; }
}
