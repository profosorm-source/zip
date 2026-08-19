<?php

namespace Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use Mockery as m;
use App\Services\Gamification\TrustService;
use App\Services\Gamification\XpService;
use App\Models\User;
use App\Enums\ModuleContext;

class GamificationTest extends TestCase
{
    protected function tearDown(): void
    {
        m::close();
        parent::tearDown();
    }

    /** @test */
    public function trust_service_evaluates_and_updates_scores_correctly(): void
    {
        $eventDispatcher = m::mock('Core\\EventDispatcher');
        $db = m::mock('Core\\Database');
        $logger = m::mock('App\\Contracts\\LoggerInterface');
        $trustStrategy = m::mock('App\\Domain\\Gamification\\Strategies\\TrustEvaluationStrategy');

        $logger->shouldIgnoreMissing();
        $eventDispatcher->shouldIgnoreMissing();
        $db->shouldIgnoreMissing();

        $user = new User($db);
        $user->id = 12;
        $user->full_name = 'علیرضا';
        $context = ModuleContext::SOCIAL_TASKS;

        // Calculate returns delta = -60 (critical drop)
        $trustStrategy->shouldReceive('calculate')
            ->with($user, $context, m::type('array'))
            ->once()
            ->andReturn(-60.0);

        $scoreService = m::mock('App\\Services\\ScoreService');
        $scoreService->shouldIgnoreMissing();
        $scoreService->shouldReceive('applyDelta')->once()->andReturn(true);
        $scoreService->shouldReceive('getScore')->once()->andReturn(-55.0);

        $outbox = m::mock('App\\Contracts\\OutboxServiceInterface');
        $outbox->shouldIgnoreMissing();

        $service = new TrustService($db, $logger, $scoreService, $trustStrategy, $outbox);

        $result = $service->evaluate($user, $context, 'task_failed', []);

        $this->assertTrue($result);
    }

    /** @test */
    public function xp_service_awards_xp_and_calculates_synergy_correctly(): void
    {
        $cache = m::mock('Core\\Cache');
        $db = m::mock('Core\\Database');
        $logger = m::mock('App\\Contracts\\LoggerInterface');
        $scoreService = m::mock('App\\Services\\ScoreService');
        $vacationModel = m::mock('App\\Models\\UserVacation');
        $synergyStrategy = m::mock('App\\Domain\\Gamification\\Strategies\\DailySynergyStrategy');
        $decayStrategy = m::mock('App\\Domain\\Gamification\\Strategies\\InactivityDecayStrategy');

        $logger->shouldIgnoreMissing();
        $db->shouldIgnoreMissing();
        $cache->shouldIgnoreMissing();
        $scoreService->shouldIgnoreMissing();
        $vacationModel->shouldIgnoreMissing();
        $synergyStrategy->shouldIgnoreMissing();
        $decayStrategy->shouldIgnoreMissing();

        $userId = 12;
        $context = ModuleContext::SOCIAL_TASKS;

        // User fetch from DB
        $userRow = (object)['id' => $userId, 'full_name' => 'علیرضا', 'level_slug' => 'user'];
        $stmtUser = m::mock(\PDOStatement::class);
        $stmtUser->shouldReceive('fetch')->andReturn($userRow);
        $db->shouldReceive('query')
            ->with("SELECT * FROM users WHERE id = ?", [$userId])
            ->andReturn($stmtUser);

        // ScoreService mocks
        $scoreService->shouldReceive('applyDelta')->andReturn(true);
        $scoreService->shouldReceive('getScore')->andReturn(100.0);

        // Synergy
        $cache->shouldReceive('get')->andReturn(1.0);
        $cache->shouldReceive('set')->andReturn(true);

        $stmtDomains = m::mock(\PDOStatement::class);
        $stmtDomains->shouldReceive('execute')->andReturn(true);
        $stmtDomains->shouldReceive('fetchColumn')->andReturn(2);
        $db->shouldReceive('prepare')
            ->with(m::pattern('/SELECT COUNT/'))
            ->andReturn($stmtDomains);

        $synergyStrategy->shouldReceive('calculate')->andReturn(1.5);

        $service = new XpService($cache, $db, $logger, $scoreService, $vacationModel, $synergyStrategy, $decayStrategy);

        $result = $service->award($userId, $context, 100.0, 'idempotency_key_123');

        $this->assertTrue($result);
    }
}
