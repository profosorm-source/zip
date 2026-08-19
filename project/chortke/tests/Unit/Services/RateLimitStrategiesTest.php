<?php

namespace Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use Core\Strategies\TokenBucketStrategy;
use Core\Strategies\FixedWindowStrategy;
use Mockery as m;

class RateLimitStrategiesTest extends TestCase
{
    protected function tearDown(): void
    {
        m::close();
        parent::tearDown();
    }

    /** @test */
    public function token_bucket_strategy_is_instantiable_and_returns_correct_name(): void
    {
        $cache = m::mock('Core\Cache');
        $strategy = new TokenBucketStrategy($cache);

        $this->assertEquals('token_bucket', $strategy->getName());
    }

    /** @test */
    public function token_bucket_strategy_attempt_updates_tokens_under_lock(): void
    {
        $cache = m::mock('Core\Cache');
        $strategy = new TokenBucketStrategy($cache);

        // Expect lock to be acquired
        $cache->shouldReceive('withLock')
            ->with('lock:rl:tb:api_key', m::any(), 5)
            ->once()
            ->andReturnUsing(function($lockKey, $callback) {
                return $callback(); // Execute the callback immediately
            });

        // Mock bucket retrieval (cache miss)
        $cache->shouldReceive('get')
            ->with('rl:tb:api_key')
            ->once()
            ->andReturn(null);

        // Expect state saving using forever()
        $cache->shouldReceive('forever')
            ->with('rl:tb:api_key', m::type('array'))
            ->once();

        $result = $strategy->attempt('api_key', 10, 1);

        $this->assertTrue($result);
    }

    /** @test */
    public function fixed_window_strategy_is_instantiable_and_uses_cache_increment(): void
    {
        $cache = m::mock('Core\Cache');
        $strategy = new FixedWindowStrategy($cache);

        $this->assertEquals('fixed_window', $strategy->getName());

        $cache->shouldReceive('redis')->once()->andReturn(null);

        // Increments with atomic flock
        $cache->shouldReceive('increment')
            ->with('rl:fw:ip_key', 1, 60)
            ->once()
            ->andReturn(1); // first increment

        $result = $strategy->attempt('ip_key', 10, 1);

        $this->assertTrue($result);
    }
}
