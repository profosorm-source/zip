<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use Mockery as m;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use App\Services\Score\ScoreCommandService;
use App\Events\ScoreDeltaAppendedEvent;

/**
 * ScoreCommandService behavioral tests
 * 
 * تمرکز: business logic (addEvent, outbox, fraud, delta cap)
 * Redis internals (velocity, idempotency, alternating) در integration test بررسی میشه
 */
class ScoreCommandServiceTest extends TestCase
{
    // انتظاراتِ Mockery را به ادعای واقعی PHPUnit تبدیل می‌کند،
    // تا ادعای تهی برای دفعِ هشدارِ risky لازم نباشد.
    use MockeryPHPUnitIntegration;

    protected function tearDown(): void { m::close(); parent::tearDown(); }

    /**
     * @return array{
     *   svc: ScoreCommandService,
     *   model: \App\Models\Score&\Mockery\MockInterface,
     *   logger: \App\Contracts\LoggerInterface&\Mockery\MockInterface
     * }
     */
    private function make(?\App\Services\OutboxService $outbox = null, ?\App\Services\AntiFraud\FraudDetectionService $fraud = null): array
    {
        $ed = m::mock('Core\EventDispatcher'); $ed->shouldIgnoreMissing();
        $redis = m::mock(); $redis->shouldIgnoreMissing();
        $cache = m::mock('Core\Cache'); $cache->shouldReceive('redis')->andReturn($redis);
        $logger = m::mock('App\Contracts\LoggerInterface'); $logger->shouldIgnoreMissing();
        $model = m::mock('App\Models\Score'); $model->shouldIgnoreMissing();
        $rl = m::mock('Core\RateLimiter'); $rl->shouldIgnoreMissing();

        $lockService = m::mock('App\Services\DistributedLockService'); $lockService->shouldIgnoreMissing();
        $rateLimitPolicy = m::mock('App\Policies\RateLimitPolicy'); $rateLimitPolicy->shouldIgnoreMissing(); $rateLimitPolicy->shouldReceive('check')->andReturn(true)->byDefault();

        $svc = new ScoreCommandService($cache, $logger, $model, $rateLimitPolicy, $fraud, null, $outbox, $lockService);
        return ['svc' => $svc, 'model' => $model, 'logger' => $logger];
    }

    /** @test */
    public function apply_delta_records_event_in_db_and_dispatches_via_outbox(): void
    {
        $outbox = m::mock('App\Services\OutboxService');
        $c = $this->make($outbox);

        $c['model']->shouldReceive('addEvent')->once()->with(m::on(fn($d) =>
            $d['entity_type'] === 'user' && $d['entity_id'] === 1 && $d['delta'] === 10.0 && $d['domain'] === 'xp'
        ))->andReturn(true);

        $outbox->shouldReceive('recordEvent')->once()->with(m::type(ScoreDeltaAppendedEvent::class))->andReturn(true);

        $this->assertTrue($c['svc']->applyDelta('user', 1, 'xp', 10.0, 'task_complete'));
    }

    /** @test */
    public function apply_delta_does_not_dispatch_when_db_insert_fails(): void
    {
        $outbox = m::mock('App\Services\OutboxService');
        $c = $this->make($outbox);

        $c['model']->shouldReceive('addEvent')->once()->andReturn(false);
        $outbox->shouldNotReceive('recordEvent');

        $this->assertFalse($c['svc']->applyDelta('user', 1, 'xp', 10.0, 'test'));
    }

    /** @test */
    public function apply_delta_logs_warning_when_outbox_not_injected(): void
    {
        $c = $this->make(null); // no outbox

        $c['model']->shouldReceive('addEvent')->once()->andReturn(true);
        $c['logger']->shouldReceive('warning')->with('score.outbox_unavailable', m::type('array'))->once();

        $c['svc']->applyDelta('user', 1, 'xp', 10.0, 'test');
    }

    /** @test */
    public function positive_delta_is_capped_at_100(): void
    {
        $outbox = m::mock('App\Services\OutboxService'); $outbox->shouldIgnoreMissing();
        $c = $this->make($outbox);

        $c['model']->shouldReceive('addEvent')->once()
            ->with(m::on(fn($d) => $d['delta'] === 100.0))
            ->andReturn(true);

        $this->assertTrue($c["svc"]->applyDelta("user", 1, "xp", 500.0, "test"));
    }

    /** @test */
    public function negative_delta_is_capped_at_minus_100(): void
    {
        $outbox = m::mock('App\Services\OutboxService'); $outbox->shouldIgnoreMissing();
        $c = $this->make($outbox);

        // فرض: کاربر ۵۰۰ امتیاز فعلی داره → delta=-200 cap به -100 → projected=400 > 0 → OK
        $c['model']->shouldReceive('getTotal')->andReturn(500.0);
        $c['model']->shouldReceive('addEvent')->once()
            ->with(m::on(fn($d) => $d['delta'] === -100.0))
            ->andReturn(true);

        $c['svc']->applyDelta('user', 1, 'social_trust', -200.0, 'test');
    }

    /** @test */
    public function fraud_score_above_85_blocks_delta(): void
    {
        $fraud = m::mock('App\Services\AntiFraud\FraudDetectionService');
        $fraud->shouldReceive('calculateFraudScore')->with(1)->andReturn(90);

        $c = $this->make(null, $fraud);
        $c['model']->shouldReceive('addEvent')->never();

        $this->assertFalse($c['svc']->applyDelta('user', 1, 'xp', 10.0, 'test'));
    }

    /** @test */
    public function fraud_score_between_50_and_85_penalizes_delta(): void
    {
        $fraud = m::mock('App\Services\AntiFraud\FraudDetectionService');
        $fraud->shouldReceive('calculateFraudScore')->with(1)->andReturn(60);

        $outbox = m::mock('App\Services\OutboxService'); $outbox->shouldIgnoreMissing();
        $c = $this->make($outbox, $fraud);

        $c['model']->shouldReceive('addEvent')->once()
            ->with(m::on(fn($d) =>
                $d['delta'] === 5.0 &&
                ($d['meta']['antifraud_penalty'] ?? false) === true &&
                ($d['meta']['fraud_score'] ?? 0) === 60
            ))
            ->andReturn(true);

        $this->assertTrue($c['svc']->applyDelta('user', 1, 'xp', 10.0, 'test'));
    }

    /** @test */
    public function fraud_score_below_50_does_not_penalize(): void
    {
        $fraud = m::mock('App\Services\AntiFraud\FraudDetectionService');
        $fraud->shouldReceive('calculateFraudScore')->with(1)->andReturn(30);

        $outbox = m::mock('App\Services\OutboxService'); $outbox->shouldIgnoreMissing();
        $c = $this->make($outbox, $fraud);

        $c['model']->shouldReceive('addEvent')->once()
            ->with(m::on(fn($d) =>
                $d['delta'] === 10.0 && !isset($d['meta']['antifraud_penalty'])
            ))
            ->andReturn(true);

        $this->assertTrue($c['svc']->applyDelta('user', 1, 'xp', 10.0, 'test'));
    }

    /** @test */
    public function domain_is_normalized(): void
    {
        $outbox = m::mock('App\Services\OutboxService'); $outbox->shouldIgnoreMissing();
        $c = $this->make($outbox);

        $c['model']->shouldReceive('addEvent')->once()
            ->with(m::on(fn($d) => !empty($d['domain'])))
            ->andReturn(true);

        $c['svc']->applyDelta('user', 1, 'XP', 5.0, 'test');
    }

    /** @test */
    public function clear_scores_cache_deletes_the_exact_redis_key(): void
    {
        // به‌جای «پرتاب نکردن»، رفتار واقعی سنجیده می‌شود: کلیدِ دقیقی که
        // clearScoresCache باید حذف کند، طبق ScoreCommandService::clearScoresCache
        // برابر است با "score:{entityType}:{entityId}".
        $redis = m::mock(\Redis::class);
        $redis->shouldReceive('del')->with('score:user:42')->once();

        $cache = m::mock('Core\\Cache');
        $cache->shouldReceive('redis')->once()->andReturn($redis);

        $logger = m::mock('App\\Contracts\\LoggerInterface');
        $logger->shouldIgnoreMissing();
        $model = m::mock('App\\Models\\Score');
        $model->shouldIgnoreMissing();
        $rateLimitPolicy = m::mock('App\\Policies\\RateLimitPolicy');
        $rateLimitPolicy->shouldIgnoreMissing();
        $lockService = m::mock('App\\Services\\DistributedLockService');
        $lockService->shouldIgnoreMissing();

        $svc = new ScoreCommandService(
            $cache, $logger, $model, $rateLimitPolicy, null, null, null, $lockService
        );

        $svc->clearScoresCache('user', 42);
    }

    /** @test */
    public function clear_scores_cache_swallows_redis_failure_and_logs_debug(): void
    {
        // شکستِ Redis نباید به فراخواننده نشت کند؛ اما باید ثبت شود.
        $redis = m::mock(\Redis::class);
        $redis->shouldReceive('del')->andThrow(new \RuntimeException('redis down'));

        $cache = m::mock('Core\\Cache');
        $cache->shouldReceive('redis')->andReturn($redis);

        $logger = m::mock('App\\Contracts\\LoggerInterface');
        $logger->shouldReceive('debug')
            ->with('score.cache_clear_failed', m::on(
                static fn(array $ctx): bool => ($ctx['error'] ?? null) === 'redis down'
            ))
            ->once();

        $model = m::mock('App\\Models\\Score');
        $model->shouldIgnoreMissing();
        $rateLimitPolicy = m::mock('App\\Policies\\RateLimitPolicy');
        $rateLimitPolicy->shouldIgnoreMissing();
        $lockService = m::mock('App\\Services\\DistributedLockService');
        $lockService->shouldIgnoreMissing();

        $svc = new ScoreCommandService(
            $cache, $logger, $model, $rateLimitPolicy, null, null, null, $lockService
        );

        $svc->clearScoresCache('user', 42);
    }
}
