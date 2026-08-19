<?php
namespace App\Contracts;

interface CacheInterface
{
    public function get(string $key, mixed $default = null): mixed;
    public function set(string $key, mixed $value, ?int $ttlSeconds = null): bool;
    public function delete(string $key): bool;
    public function put(string $key, mixed $value, int $minutes = 60): bool;
    public function forget(string $key): bool;
    public function increment(string $key, int $step = 1): int;
    public function decrement(string $key, int $step = 1): int;
    public function getOrSet(string $key, callable $callback, ?int $ttlSeconds = null): mixed;
    public function remember(string $key, ?int $ttlSeconds, \Closure $callback): mixed;
    public function rememberForever(string $key, \Closure $callback): mixed;
    public function has(string $key): bool;
    public function ttl(string $key): int;
    public function flush(): bool;
    public function driver(): string;
    /** @param list<string> $tags */
    public function tags(array $tags): self;
    public function redis(): ?\Redis;
    public function lock(string $key, int $ttl = 30, int $wait = 1): bool;
    public function unlock(string $key): bool;
    public function redisKey(string $key): string;
}
