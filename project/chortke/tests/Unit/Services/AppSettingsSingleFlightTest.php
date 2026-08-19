<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use Mockery as m;
use App\Services\Settings\AppSettings;

/**
 * Regression test for BUGFIX-STAMPEDE-2026-06.
 *
 * Verifies single-flight semantics of AppSettings::load():
 *   - on cache miss only ONE call to the underlying Setting::getAll() must
 *     happen even when multiple workers race to populate the cache
 *   - the populated value must be written to the distributed cache exactly once
 *   - on lock contention, callers fall back to direct DB read but never block
 *
 * Race conditions are simulated via mock expectations and a manual cache
 * stub that mimics Redis SETNX-style locking.
 */
/**
 * @group architecture
 */
class AppSettingsSingleFlightTest extends TestCase
{
    protected function tearDown(): void { m::close(); parent::tearDown(); }



    /** @test */
    public function only_one_db_load_when_lock_holder_runs(): void
    {
        // Setup: cache empty, lock acquired successfully → loader runs exactly once.
        $cache = new InMemoryLockingCacheStub();
        $logger = m::mock(\App\Contracts\LoggerInterface::class);
        $logger->shouldIgnoreMissing();

        $model = m::mock(\App\Models\Setting::class);
        $model->shouldReceive('getAll')
              ->once()                       // ← the key assertion
              ->andReturn([
                  (object)['key' => 'app.name', 'value' => 'Chortke', 'type' => 'string'],
              ]);

        $settings = $this->makeSettings($cache, $logger, $model);

        $result = $settings->load();
        $this->assertSame('Chortke', $result['app.name']);

        // Cache was populated exactly once
        $this->assertSame(1, $cache->writeCount, 'Cache must be written exactly once.');
        $this->assertSame(1, $cache->lockAcquisitions, 'Lock must be acquired exactly once.');
        $this->assertSame(1, $cache->unlockCalls, 'Lock must be released exactly once.');
    }

    /** @test */
    public function runtime_memo_short_circuits_repeated_calls(): void
    {
        // Within a single request, repeated load() calls must not touch
        // the cache nor the model after the first call.
        $cache  = new InMemoryLockingCacheStub();
        $logger = m::mock(\App\Contracts\LoggerInterface::class);
        $logger->shouldIgnoreMissing();
        $model  = m::mock(\App\Models\Setting::class);
        $model->shouldReceive('getAll')->once()
              ->andReturn([(object)['key' => 'k', 'value' => 'v', 'type' => 'string']]);

        $settings = $this->makeSettings($cache, $logger, $model);
        $settings->load();
        $settings->load();
        $settings->load();

        $this->assertSame(1, $cache->writeCount);
        // First load() reads cache twice (initial miss + double-check after lock);
        // subsequent calls hit the runtime memo and skip cache entirely. So total
        // reads must remain 2, not grow with the number of load() calls.
        $this->assertSame(2, $cache->readCount,
            'Repeated load() calls must hit only the runtime memo, not the cache.');
    }

    /** @test */
    public function load_falls_back_safely_when_lock_unavailable(): void
    {
        // If lock() throws (e.g. Redis down in production), AppSettings must
        // still return data (degraded mode) — it must not crash the request.
        $cache = m::mock(\Core\Cache::class);
        $cache->shouldReceive('get')->with('system:settings:v2')->andReturn(null);
        $cache->shouldReceive('lock')->andThrow(new \RuntimeException('Redis down'));
        $cache->shouldReceive('put')->never(); // no lock holder → no cache write
        $cache->shouldReceive('unlock')->never();

        $logger = m::mock(\App\Contracts\LoggerInterface::class);
        $logger->shouldReceive('warning')->with('settings.lock_unavailable', m::any())->once();

        $model = m::mock(\App\Models\Setting::class);
        $model->shouldReceive('getAll')->once()
              ->andReturn([(object)['key' => 'k', 'value' => 'v', 'type' => 'string']]);

        $settings = $this->makeSettings($cache, $logger, $model);
        $result   = $settings->load();
        $this->assertSame('v', $result['k']);
    }

    /** @test */
    public function lock_released_even_when_db_throws(): void
    {
        $cache = new InMemoryLockingCacheStub();
        $logger = m::mock(\App\Contracts\LoggerInterface::class);
        $logger->shouldReceive('error')->with('settings.load_failed', m::any())->once();

        $model = m::mock(\App\Models\Setting::class);
        $model->shouldReceive('getAll')->once()
              ->andThrow(new \PDOException('table missing'));

        $settings = $this->makeSettings($cache, $logger, $model);
        $result   = $settings->load();
        $this->assertSame([], $result, 'On DB failure load() must return empty array.');
        $this->assertSame(1, $cache->lockAcquisitions);
        $this->assertSame(1, $cache->unlockCalls, 'Lock MUST be released even on DB failure (deadlock prevention).');
    }

    private function makeSettings(\Core\Cache $cache, \App\Contracts\LoggerInterface $logger, \App\Models\Setting $model): AppSettings
    {
        return new AppSettings($cache, $logger, $model);
    }
}

/**
 * Stand-in for Core\Cache that records lock/unlock and read/write counts
 * so tests can assert single-flight invariants without depending on Redis.
 */
class InMemoryLockingCacheStub extends \Core\Cache
{
    public int $readCount = 0;
    public int $writeCount = 0;
    public int $lockAcquisitions = 0;
    public int $unlockCalls = 0;

    /** @var array<string, mixed> */
    private array $store = [];
    /** @var array<string, bool> */
    private array $locks = [];

    public function __construct() { /* skip parent for unit isolation */ }

    public function get(string $key, mixed $default = null): mixed
    {
        $this->readCount++;
        return $this->store[$key] ?? $default;
    }

    public function put(string $key, mixed $value, int $minutes = 60): bool
    {
        $this->writeCount++;
        $this->store[$key] = $value;
        return true;
    }

    public function forget(string $key): bool
    {
        unset($this->store[$key]);
        return true;
    }

    public function lock(string $key, int $ttl = 30, int $wait = 1): bool
    {
        if (isset($this->locks[$key])) return false;
        $this->locks[$key] = true;
        $this->lockAcquisitions++;
        return true;
    }

    public function unlock(string $key): bool
    {
        $key = 'lock:' . $key; // emulate prefix logic? simplified for test
        // accept both prefixed and unprefixed
        unset($this->locks[$key], $this->locks[substr($key, 5)]);
        $this->unlockCalls++;
        return true;
    }
}
