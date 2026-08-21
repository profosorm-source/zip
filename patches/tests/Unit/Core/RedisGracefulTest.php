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

        // هیچ method ای نباید exception بده، و مقدارِ بازگشتی باید دقیقاً همان
        // «پیش‌فرضِ امن»ی باشد که Core\Redis::__call تعریف کرده است. سنجشِ صرفِ
        // «پرتاب نشدن» اجازه می‌داد متدی مقدارِ نادرست برگرداند و تست سبز بماند.
        $expectedDefaults = [
            'get'    => null,
            'hGet'   => null,
            'set'    => false,
            'del'    => false,
            'incr'   => false,
            'decr'   => false,
            'hSet'   => false,
            'exists' => false,
            'expire' => false,
            'setex'  => false,
            'setnx'  => false,
            'ping'   => false,
            'eval'   => false,
        ];

        foreach ($expectedDefaults as $method => $expected) {
            try {
                $actual = $redis->{$method}('test_key', 'test_val');
            } catch (\Throwable $e) {
                $this->fail("Redis::{$method}() threw exception when unavailable: " . $e->getMessage());
            }

            $this->assertSame(
                $expected,
                $actual,
                "Redis::{$method}() هنگام در دسترس نبودن باید پیش‌فرضِ امن برگرداند."
            );
        }
    }
}
