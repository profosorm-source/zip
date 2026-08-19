<?php

declare(strict_types=1);

namespace Core;

/**
 * Redis connection wrapper for application services.
 *
 * This wrapper uses the PHP Redis extension and exposes Redis commands via __call().
 *
 * @method mixed    get(string $key)
 * @method bool     set(string $key, mixed $value, mixed $options = null)
 * @method bool     setex(string $key, int $ttl, mixed $value)
 * @method bool     setnx(string $key, mixed $value)
 * @method bool|int delete(string ...$keys)
 * @method bool|int del(string ...$keys)
 * @method bool     expire(string $key, int $ttl)
 * @method int      incr(string $key)
 * @method int      incrBy(string $key, int $value)
 * @method int      decr(string $key)
 * @method int      decrBy(string $key, int $value)
 * @method int      hIncrBy(string $key, string $field, int $value)
 * @method float    hIncrByFloat(string $key, string $field, float $value)
 * @method bool     hSet(string $key, string $field, mixed $value)
 * @method mixed    hGet(string $key, string $field)
 * @method array    hGetAll(string $key)
 * @method bool     hDel(string $key, string ...$fields)
 * @method bool     hExists(string $key, string $field)
 * @method int      lPush(string $key, mixed ...$values)
 * @method int      rPush(string $key, mixed ...$values)
 * @method mixed    lPop(string $key)
 * @method mixed    rPop(string $key)
 * @method int      lLen(string $key)
 * @method array    lRange(string $key, int $start, int $end)
 * @method int      sAdd(string $key, mixed ...$members)
 * @method int      sRem(string $key, mixed ...$members)
 * @method array    sMembers(string $key)
 * @method bool     sIsMember(string $key, mixed $member)
 * @method int      sCard(string $key)
 * @method float    zScore(string $key, mixed $member)
 * @method int      zAdd(string $key, float $score, mixed $member)
 * @method array    zRange(string $key, int $start, int $end, bool $withScores = false)
 * @method array    zRangeByScore(string $key, string $min, string $max, array $options = [])
 * @method array    zRevRangeByScore(string $key, string $max, string $min, array $options = [])
 * @method int      zRem(string $key, mixed ...$members)
 * @method int      zCard(string $key)
 * @method int      zCount(string $key, string $min, string $max)
 * @method int      publish(string $channel, string $message)
 * @method int      lTrim(string $key, int $start, int $end)
 * @method array    blPop(string $key, int $timeout)
 * @method array    brPop(string $key, int $timeout)
 * @method bool     select(int $db)
 * @method mixed    getSet(string $key, mixed $value)
 * @method bool     exists(string ...$keys)
 * @method bool     persist(string $key)
 * @method int      ttl(string $key)
 * @method bool     rename(string $key, string $newKey)
 * @method array    keys(string $pattern)
 * @method mixed    eval(string $script, array $args = [], int $numKeys = 0)
 * @method array    scan(mixed $iterator, string ...$options)
 * @method bool     multi()
 * @method array    exec()
 * @method bool     discard()
 * @method bool     flushDB()
 * @method bool     flushAll()
 * @method array    info(string $section = null)
 * @method bool     ping()
 */
class Redis
{
    private ?\Redis $client = null;
    private bool $connected = false;
    /** @var array<string, true> */
    private array $warnedUnavailableMethods = [];

    public function __construct() {
        if (!extension_loaded('redis')) {
            $this->connected = false;
            return;
        }

        $rawConfig = config('redis');
        $config = is_array($rawConfig) ? $rawConfig : [];
        $enabled = $config['enabled'] ?? true;
        if (!$enabled || in_array(strtolower((string)$enabled), ['false', '0', 'no', 'off'], true)) {
            $this->connected = false;
            return;
        }

        $rawHost = $config['host'] ?? '127.0.0.1';
        $rawPort = $config['port'] ?? 6379;
        $rawTimeout = $config['timeout'] ?? 1.5;
        $rawPassword = $config['password'] ?? '';
        $rawDb = $config['db'] ?? 0;

        $host = is_string($rawHost) && $rawHost !== '' ? $rawHost : '127.0.0.1';
        $port = is_int($rawPort) || (is_string($rawPort) && ctype_digit($rawPort)) ? (int)$rawPort : 6379;
        $timeout = is_int($rawTimeout) || is_float($rawTimeout) || (is_string($rawTimeout) && is_numeric($rawTimeout))
            ? (float)$rawTimeout
            : 1.5;
        $password = is_string($rawPassword) ? $rawPassword : '';
        $db = is_int($rawDb) || (is_string($rawDb) && ctype_digit($rawDb)) ? (int)$rawDb : 0;

        try {
            $redis = new \Redis();
            // Apply the same bound to socket reads. A TCP connection can be
            // established while the Redis event loop is stalled; without a
            // read timeout AUTH/SELECT/PING may block indefinitely.
            if (!$redis->connect($host, $port, $timeout, null, 0, $timeout)) {
                return;
            }
            // Some phpredis builds do not consistently apply connect()'s
            // read_timeout argument to commands issued immediately afterwards.
            $redis->setOption(\Redis::OPT_READ_TIMEOUT, $timeout);

            if ($password !== '') {
                $redis->auth((string)$password);
            }

            $redis->select($db);
            $redis->setOption(\Redis::OPT_SCAN, \Redis::SCAN_RETRY);
            $redis->ping();

            $this->client = $redis;
            $this->connected = true;
        } catch (\Throwable) {
            $this->client = null;
            $this->connected = false;
        }
    }

    public function isAvailable(): bool
    {
        return $this->connected && $this->client !== null;
    }

    public function getClient(): ?\Redis
    {
        return $this->client;
    }

    /**
     * Get keys matching pattern using SCAN (non-blocking alternative to keys())
     * 
     * ✅ Performance: O(N) with server-side iteration (doesn't block Redis)
     * ❌ keys(): O(N) but blocks Redis server completely
     * 
     * @param string $pattern Key pattern to match (e.g., "user:*")
     * @param int $count Hint about number of keys to return per iteration
     * @return array<string> All matching keys
     */
    public function scanKeys(string $pattern, int $count = 100, int $maxIterations = 0): array
    {
        if (!$this->isAvailable()) {
            return [];
        }

        $client = $this->client;
        if ($client === null) {
            return [];
        }
        $keys = [];
        $iterator = null;

        // scan expects &$iterator, performs iterations and updates the pointer
        while (false !== ($batch = $client->scan($iterator, $pattern, $count))) {
            foreach ($batch as $key) {
                $keys[] = $key;
            }
            if ($iterator === 0 || $iterator === '0') {
                break;
            }
        }

        return $keys;
    }

    /**
     * Graceful degradation: وقتی Redis در دسترس نیست، بجای crash:
     * - warning log میزنه (debug possible)
     * - false/null برمیگردونه (caller میتونه handle کنه)
     * - هرگز process رو kill نمیکنه
     *
     * callerها هنوز میتونن isAvailable() چک کنن — ولی اگه فراموش کردن crash نمیکنه.
     */
    /**
     * @param array<int|string, mixed> $arguments
     */
    public function __call(string $name, array $arguments): mixed
    {
        if (!$this->isAvailable()) {
            // Log once per Redis wrapper instance to avoid spam while keeping
            // independent requests/tests observable and isolated.
            if (!isset($this->warnedUnavailableMethods[$name])) {
                $this->warnedUnavailableMethods[$name] = true;
                try {
                    $eventContext = ['method' => $name];
                    if (function_exists('logger')) {
                        logger()->warning('redis.unavailable.graceful_skip', $eventContext);
                    }
                    if (PHP_SAPI === 'cli') {
                        // Keep graceful-degradation events observable for CLI
                        // workers even when the application logger is unavailable.
                        echo 'redis.unavailable.graceful_skip' . PHP_EOL;
                    }
                } catch (\Throwable) {
                    if (PHP_SAPI === 'cli') {
                        echo 'redis.unavailable.graceful_skip' . PHP_EOL;
                    }
                }
            }

            // Return safe defaults based on method type
            return match($name) {
                'get', 'hGet', 'lPop', 'rPop', 'sPop' => null,
                'set', 'setex', 'setnx', 'hSet', 'del', 'delete', 'expire',
                'lPush', 'rPush', 'sAdd', 'sRem' => false,
                'incr', 'incrBy', 'decr', 'decrBy' => false,
                'hIncrBy', 'hIncrByFloat' => false,
                'exists', 'sIsMember' => false,
                'mGet', 'hGetAll', 'keys', 'lRange', 'sMembers', 'scan' => [],
                'ping' => false,
                'multi', 'exec', 'pipeline' => false,
                'eval' => false,
                default => false,
            };
        }

        // BUGFIX-REDIS-DEPRECATED-2026-06:
        //   Redis::delete() emits a PHP DEPRECATED notice in phpredis >= 6.0;
        //   callers across the codebase still use the legacy name. We
        //   silently translate delete()/setTimeout() to their canonical
        //   replacements so callers don't have to change and the logs
        //   stop being polluted with `redis_clear_failed` warnings on
        //   every successful login.
        $aliases = [
            'delete'     => 'del',
            'setTimeout' => 'expire',
        ];
        if (isset($aliases[$name])) {
            $name = $aliases[$name];
        }

        return $this->client->{$name}(...$arguments);
    }
}
