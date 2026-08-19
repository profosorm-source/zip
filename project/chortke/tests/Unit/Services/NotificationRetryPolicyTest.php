<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use Mockery as m;
use App\Services\Notification\NotificationRetryPolicy;
use Core\CircuitBreaker;

/**
 * تست‌های رفتاری NotificationRetryPolicy
 *
 * بررسی: Channel-specific retry, Circuit breaker, Failure recording
 */
/**
 * @group architecture
 */
class NotificationRetryPolicyTest extends TestCase
{
    protected function tearDown(): void { m::close(); parent::tearDown(); }

    /** @return array{svc:NotificationRetryPolicy,cache:\Core\Cache&\Mockery\MockInterface,logger:\App\Contracts\LoggerInterface&\Mockery\MockInterface} */
    private function make(?CircuitBreaker $circuit = null): array
    {
        $cache = m::mock('Core\\Cache');
        $cache->shouldIgnoreMissing();
        $logger = m::mock('App\\Contracts\\LoggerInterface');
        $logger->shouldIgnoreMissing();

        $svc = new NotificationRetryPolicy($cache, $logger, $circuit);
        return compact('svc', 'cache', 'logger');
    }

    // ─── Success ────────────────────────────────────────────────

    /** @test */
    public function returns_true_on_successful_operation(): void
    {
        $c = $this->make();
        $c['cache']->shouldReceive('get')->andReturn(false); // circuit closed

        $result = $c['svc']->execute('fcm', fn() => true);
        $this->assertTrue(true);
        $this->assertTrue($result);
    }

    // ─── Retry on failure ───────────────────────────────────────

    /** @test */
    public function retries_on_failure_then_succeeds(): void
    {
        $c = $this->make();
        $c['cache']->shouldReceive('get')->andReturn(false);

        $callCount = 0;
        $result = $c['svc']->execute('fcm', function () use (&$callCount) {
            $callCount++;
            if ($callCount < 2) {
                throw new \RuntimeException('temporary failure');
            }
            return true;
        });

        $this->assertTrue($result);
        $this->assertEquals(2, $callCount);
    }

    // ─── Max attempts ───────────────────────────────────────────

    /** @test */
    public function returns_false_after_all_attempts_exhausted(): void
    {
        $c = $this->make();
        $c['cache']->shouldReceive('get')->with(m::pattern('/circuit_open/'), m::any())->andReturn(false);
        $c['cache']->shouldReceive('increment')->andReturn(1);

        $callCount = 0;
        $result = $c['svc']->execute('sms', function () use (&$callCount) {
            $callCount++;
            throw new \RuntimeException('persistent failure');
        });

        $this->assertFalse($result);
        // sms policy: attempts = 2
        $this->assertEquals(2, $callCount);
    }

    // ─── Circuit breaker ────────────────────────────────────────

    /** @test */
    public function skips_execution_when_circuit_open(): void
    {
        $c = $this->make();
        $c['cache']->shouldReceive('get')->with('notif_circuit_open:email', m::any())->andReturn(true);

        $called = false;
        $result = $c['svc']->execute('email', function () use (&$called) {
            $called = true;
            return true;
        });

        $this->assertFalse($result);
        $this->assertFalse($called, 'Operation should not execute when circuit is open');
    }

    /** @test */
    public function opens_circuit_after_threshold_failures(): void
    {
        $c = $this->make();
        $c['cache']->shouldReceive('get')->with(m::pattern('/circuit_open/'), m::any())->andReturn(false);
        // sms: circuit_failures = 3
        $c['cache']->shouldReceive('increment')->andReturn(3);
        $c['cache']->shouldReceive('setSeconds')->with('notif_circuit_open:sms', true, 120)->once();

        $c['svc']->execute('sms', fn() => throw new \RuntimeException('fail'));
        $this->assertTrue(true);
    }

    // ─── Channel policies ───────────────────────────────────────

    /** @test */
    public function log_channel_has_single_attempt(): void
    {
        $c = $this->make();
        $c['cache']->shouldReceive('get')->andReturn(false);

        $callCount = 0;
        $c['svc']->execute('log', function () use (&$callCount) {
            $callCount++;
            return false;
        });

        $this->assertEquals(1, $callCount, 'Log channel should only try once');
    }

    // ─── Reset on success ───────────────────────────────────────

    /** @test */
    public function resets_failure_counter_on_success(): void
    {
        $c = $this->make();
        $c['cache']->shouldReceive('get')->andReturn(false);
        $c['cache']->shouldReceive('forget')->with('notif_failures:fcm')->once();
        $c['cache']->shouldReceive('forget')->with('notif_circuit_open:fcm')->once();

        $c['svc']->execute('fcm', fn() => true);
        $this->assertTrue(true);
    }

    // ─── Architecture ───────────────────────────────────────────


    /** @test */
    public function integrates_with_core_circuit_breaker(): void
    {
        $circuit = m::mock('Core\\CircuitBreaker');
        $circuit->shouldReceive('call')
            ->with('notif_fcm', m::type('callable'))
            ->once()
            ->andReturnUsing(fn($ctx, $cb) => $cb());

        $c = $this->make($circuit);
        $c['cache']->shouldReceive('get')->andReturn(false);

        $result = $c['svc']->execute('fcm', fn() => true);
        $this->assertTrue(true);
        $this->assertTrue($result);
    }
}
