<?php

declare(strict_types=1);

namespace Tests\Unit\Core;

use Core\Cache;
use PHPUnit\Framework\TestCase;

class CacheTest extends TestCase
{
    private string $cacheDir;
    private Cache $cache;

    protected function setUp(): void
    {
        $this->cacheDir = sys_get_temp_dir() . '/chortke_cache_test/';
        $this->cleanupCacheDir();
        $this->cache = new Cache(null, $this->cacheDir);
    }

    protected function tearDown(): void
    {
        $this->cache->flushAllLocks();
        $this->cleanupCacheDir();
    }

    public function testFileDriverPutGetHasForgetAndFlush(): void
    {
        $cache = $this->cache;

        $this->assertTrue($cache->put('foo', 'bar', 10));
        $this->assertTrue($cache->has('foo'));
        $this->assertSame('bar', $cache->get('foo'));
        $this->assertTrue($cache->forget('foo'));
        $this->assertFalse($cache->has('foo'));

        $this->assertTrue($cache->put('foo', 'baz', 10));
        $this->assertTrue($cache->flush());
        $this->assertFalse($cache->has('foo'));
    }

    public function testRememberCachesCallbackResult(): void
    {
        $cache = $this->cache;
        $count = 0;

        $result = $cache->remember('remember_key', 10, function () use (&$count) {
            $count++;
            return ['name' => 'ali'];
        });

        $this->assertSame(['name' => 'ali'], $result);
        $this->assertSame(1, $count);
        $this->assertSame(['name' => 'ali'], $cache->remember('remember_key', 10, function () use (&$count) {
            $count++;
            return ['name' => 'error'];
        }));
        $this->assertSame(1, $count);
    }

    public function testPullReturnsValueAndForgetsKey(): void
    {
        $cache = $this->cache;
        $cache->put('temp', 'value', 10);

        $this->assertSame('value', $cache->pull('temp'));
        $this->assertFalse($cache->has('temp'));
    }

    public function testIncrementDecrementAndTtl(): void
    {
        $cache = $this->cache;

        $this->assertSame(1, $cache->increment('counter', 1, 2));
        $this->assertSame(0, $cache->decrement('counter', 1, 2));
        $this->assertGreaterThanOrEqual(0, $cache->ttl('counter'));
    }

    public function testIncrementFloatWorksAndPreservesValue(): void
    {
        $cache = $this->cache;

        $this->assertSame(1.5, $cache->incrementFloat('float_counter', 1.5, 2));
        $this->assertSame(3.0, $cache->incrementFloat('float_counter', 1.5, 2));
    }

    public function testCleanupRemovesExpiredFiles(): void
    {
        $cache = $this->cache;
        $this->expectOutputRegex('/cache\.file\.cleanup\.completed/');

        $cache->put('old', 'expired', -1);
        $cleaned = $cache->cleanup();

        $this->assertGreaterThanOrEqual(1, $cleaned);
        $this->assertFalse($cache->has('old'));
    }

    public function testTaggedCacheFlushRemovesTaggedKeys(): void
    {
        $cache = $this->cache;
        $tagged = $cache->tags(['users']);

        $this->assertTrue($tagged->put('profile', ['name' => 'ali'], 10));
        $this->assertTrue($tagged->has('profile'));
        $this->assertSame(['name' => 'ali'], $tagged->get('profile'));

        $tagged->flush();
        $this->assertFalse($tagged->has('profile'));
    }

    public function testWithLockExecutesCallbackAndReleasesLock(): void
    {
        $cache = $this->cache;

        $result = $cache->withLock('lock-key', function () {
            return 'locked';
        }, 1);

        $this->assertSame('locked', $result);
        $this->assertTrue($cache->lock('lock-key', 1, 1));
        $this->assertTrue($cache->unlock('lock-key'));
    }

    private function cleanupCacheDir(): void
    {
        if (!is_dir($this->cacheDir)) {
            return;
        }

        $files = glob($this->cacheDir . '*') ?: [];
        foreach ($files as $file) {
            @unlink($file);
        }
    }
}
