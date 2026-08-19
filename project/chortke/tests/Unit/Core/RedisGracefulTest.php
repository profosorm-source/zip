<?php

declare(strict_types=1);

namespace Tests\Unit\Core;

use PHPUnit\Framework\TestCase;

/**
 * @group redis-unavailable
 */
class RedisGracefulTest extends TestCase
{
    public function testCallDoesNotThrowWhenUnavailable(): void
    {
        $redis = new \Core\Redis();

        // اگه Redis واقعاً connect نباشه (test environment)
        if ($redis->isAvailable()) {
            $this->markTestSkipped('Redis is available — cannot test unavailable path');
        }
        $this->expectOutputRegex('/redis\.unavailable\.graceful_skip/');

        // باید بدون exception false/null برگردونه
        $this->assertNull($redis->get('nonexistent_key'));
        $this->assertFalse($redis->set('key', 'val'));
        $this->assertFalse($redis->del('key'));
        $this->assertFalse($redis->incr('key'));
        $this->assertIsArray($redis->keys('*'));
        $this->assertFalse($redis->exists('key'));
        $this->assertFalse($redis->eval('return 1', [], 0));
    }

    public function testScanKeysReturnsEmptyWhenUnavailable(): void
    {
        $redis = new \Core\Redis();
        if ($redis->isAvailable()) {
            $this->markTestSkipped('Redis is available');
        }

        $this->assertSame([], $redis->scanKeys('test:*'));
    }

    public function testNoExceptionOnAnyMethod(): void
    {
        $redis = new \Core\Redis();
        if ($redis->isAvailable()) {
            $this->markTestSkipped('Redis is available');
        }
        $this->expectOutputRegex('/redis\.unavailable\.graceful_skip/');

        // هیچ method ای نباید exception بده
        $methods = ['get', 'set', 'del', 'incr', 'decr', 'hGet', 'hSet',
                    'exists', 'expire', 'setex', 'setnx', 'ping', 'eval'];

        foreach ($methods as $method) {
            try {
                $redis->{$method}('test_key', 'test_val');
                $this->assertTrue(true); // no exception = pass
            } catch (\Throwable $e) {
                $this->fail("Redis::{$method}() threw exception when unavailable: " . $e->getMessage());
            }
        }
    }
}
