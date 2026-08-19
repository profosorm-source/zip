<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use Mockery as m;
use App\Services\Gamification\TrustService;
use App\Models\User;
use App\Enums\ModuleContext;
use App\Contracts\OutboxServiceInterface;

class TrustServiceTest extends TestCase
{
    protected function tearDown(): void { m::close(); parent::tearDown(); }

    /** @return array{svc:TrustService,scoreService:\App\Services\ScoreService&\Mockery\MockInterface,trustStrategy:\App\Domain\Gamification\Strategies\TrustEvaluationStrategy&\Mockery\MockInterface,logger:\App\Contracts\LoggerInterface&\Mockery\MockInterface,user:User} */
    private function make(?OutboxServiceInterface $outbox = null): array
    {
        $logger = m::mock('App\\Contracts\\LoggerInterface'); $logger->shouldIgnoreMissing();
        $scoreService = m::mock('App\\Services\\ScoreService'); $scoreService->shouldIgnoreMissing();
        $trustStrategy = m::mock('App\\Domain\\Gamification\\Strategies\\TrustEvaluationStrategy');
        $db = m::mock('Core\\Database'); $db->shouldIgnoreMissing();

        $svc = new TrustService($db, $logger, $scoreService, $trustStrategy, $outbox);
        $user = new User($db); $user->id = 10; $user->full_name = 'Test';
        return compact('svc', 'scoreService', 'trustStrategy', 'logger', 'user');
    }

    /** @test */
    public function evaluate_returns_false_when_delta_is_zero(): void
    {
        $c = $this->make();
        $c['trustStrategy']->shouldReceive('calculate')->once()->andReturn(0.0);
        $c['scoreService']->shouldNotReceive('applyDelta');

        $this->assertFalse($c['svc']->evaluate($c['user'], ModuleContext::SOCIAL_TASKS, 'neutral_action'));
    }

    /** @test */
    public function evaluate_applies_positive_delta(): void
    {
        $c = $this->make();
        $c['trustStrategy']->shouldReceive('calculate')->once()->andReturn(15.0);
        $c['scoreService']->shouldReceive('applyDelta')->once()->with(
            'user', 10, m::type('string'), 15.0, 'task_complete', [], m::type('string')
        )->andReturn(true);
        $c['scoreService']->shouldReceive('getScore')->once()->andReturn(80.0);

        $this->assertTrue($c['svc']->evaluate($c['user'], ModuleContext::SOCIAL_TASKS, 'task_complete'));
    }

    /** @test */
    public function evaluate_applies_negative_delta_and_dispatches_critical_event(): void
    {
        $outbox = m::mock('App\\Contracts\\OutboxServiceInterface');
        $c = $this->make($outbox);

        $c['trustStrategy']->shouldReceive('calculate')->once()->andReturn(-60.0);
        $c['scoreService']->shouldReceive('applyDelta')->once()->andReturn(true);
        $c['scoreService']->shouldReceive('getScore')->once()->andReturn(-55.0);

        // Trust < -50 → critical event باید outbox بشه
        $outbox->shouldReceive('recordEvent')->once()->with(m::on(function($event) {
            $data = $event->getData();
            return $data['event_name'] === 'trust.critical_drop' && $data['user_id'] === 10;
        }))->andReturn(true);

        $this->assertTrue($c['svc']->evaluate($c['user'], ModuleContext::SOCIAL_TASKS, 'fraud_detected'));
    }

    /** @test */
    public function evaluate_negative_delta_no_critical_when_above_threshold(): void
    {
        $outbox = m::mock('App\\Contracts\\OutboxServiceInterface');
        $c = $this->make($outbox);

        $c['trustStrategy']->shouldReceive('calculate')->andReturn(-10.0);
        $c['scoreService']->shouldReceive('applyDelta')->andReturn(true);
        $c['scoreService']->shouldReceive('getScore')->andReturn(-20.0); // above -50

        $outbox->shouldNotReceive('recordEvent');

        $this->assertTrue($c['svc']->evaluate($c['user'], ModuleContext::SOCIAL_TASKS, 'minor_issue'));
    }

    /** @test */
    public function evaluate_returns_false_on_exception(): void
    {
        $c = $this->make();
        $c['trustStrategy']->shouldReceive('calculate')->andReturn(10.0);
        $c['scoreService']->shouldReceive('applyDelta')->andThrow(new \RuntimeException('DB error'));

        $this->assertFalse($c['svc']->evaluate($c['user'], ModuleContext::SOCIAL_TASKS, 'test'));
    }

    /** @test */
    public function get_trust_score_delegates_to_score_service(): void
    {
        $c = $this->make();
        $c['scoreService']->shouldReceive('getScore')->with('user', 10, m::type('string'))->once()->andReturn(75.5);

        $this->assertEquals(75.5, $c['svc']->getTrustScore($c['user'], ModuleContext::SOCIAL_TASKS));
    }

    /** @test */
    public function recover_weekly_uses_stdclass_rows_from_database(): void
    {
        $logger = m::mock('App\\Contracts\\LoggerInterface'); $logger->shouldIgnoreMissing();
        $scoreService = m::mock('App\\Services\\ScoreService');
        $trustStrategy = m::mock('App\\Domain\\Gamification\\Strategies\\TrustEvaluationStrategy');
        $db = m::mock('Core\\Database');

        $db->shouldReceive('fetchAll')->twice()->andReturn(
            [(object)['user_id' => 10]],
            [(object)['user_id' => 11, 'reject_count' => 4]]
        );
        $scoreService->shouldReceive('getScore')->once()->andReturn(40.0);
        $scoreService->shouldReceive('applyDelta')->twice()->andReturn(true);

        $svc = new TrustService($db, $logger, $scoreService, $trustStrategy);
        $result = $svc->recoverWeekly(ModuleContext::SOCIAL_TASKS);

        $this->assertSame(1, $result['recovered']);
        $this->assertSame(1, $result['penalized']);
    }
}
