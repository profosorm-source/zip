<?php

namespace Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use App\Services\Interaction\RatingService;
use App\Services\Interaction\ReportService;
use App\Enums\ModuleContext;
use App\Enums\InteractionType;
use Mockery as m;

class UserInteractionsTest extends TestCase
{
    protected function tearDown(): void
    {
        m::close();
        parent::tearDown();
    }

    /** @test */
    public function rating_service_can_be_instantiated(): void
    {
        $transactionWrapper = m::mock('Core\TransactionWrapper');
        $db = m::mock('Core\Database');
        $logger = m::mock('App\Contracts\LoggerInterface');
        $rateLimiter = m::mock('Core\RateLimiter');

        $service = new RatingService($transactionWrapper, $db, $logger, $rateLimiter);
        $this->assertInstanceOf(RatingService::class, $service);
    }

    /** @test */
    public function rating_service_records_and_updates_scores_with_rate_limiting(): void
    {
        $transactionWrapper = m::mock('Core\TransactionWrapper');
        $db = m::mock('Core\Database');
        $logger = m::mock('App\Contracts\LoggerInterface');
        $rateLimiter = m::mock('Core\RateLimiter');

        $logger->shouldIgnoreMissing();
        
        // Rate limiter allows
        $rateLimiter->shouldReceive('attempt')->once()->andReturn(true);

        // Transaction retry mock
        $transactionWrapper->shouldReceive('runWithRetry')
            ->once()
            ->andReturnUsing(function($callback) {
                return $callback();
            });

        // Fetch query for existing rating mock
        $stmtFetch = m::mock(\PDOStatement::class);
        $stmtFetch->shouldReceive('execute')->once();
        $stmtFetch->shouldReceive('fetchColumn')->once()->andReturn(null); // no existing rating

        $db->shouldReceive('prepare')
            ->with(m::pattern('/SELECT id FROM interactions/'))
            ->once()
            ->andReturn($stmtFetch);

        // Insert query mock
        $stmtInsert = m::mock(\PDOStatement::class);
        $stmtInsert->shouldReceive('execute')->once()->andReturn(true);

        $db->shouldReceive('prepare')
            ->with(m::pattern('/INSERT INTO interactions/'))
            ->once()
            ->andReturn($stmtInsert);

        $service = new RatingService($transactionWrapper, $db, $logger, $rateLimiter);

        $result = $service->rate(12, 'ad', 101, ModuleContext::SOCIAL_TASKS, 5);

        $this->assertTrue($result);
    }

    /** @test */
    public function rating_service_calculates_bayesian_average_correctly(): void
    {
        $transactionWrapper = m::mock('Core\TransactionWrapper');
        $db = m::mock('Core\Database');
        $logger = m::mock('App\Contracts\LoggerInterface');
        $rateLimiter = m::mock('Core\RateLimiter');

        $stmt = m::mock(\PDOStatement::class);
        $stmt->shouldReceive('execute')->once();
        
        // Mock 12 votes with average rating of 4.5
        $stmt->shouldReceive('fetch')->once()->andReturn([
            'total_votes' => 12,
            'average_rating' => 4.5
        ]);

        $db->shouldReceive('prepare')->once()->andReturn($stmt);

        $service = new RatingService($transactionWrapper, $db, $logger, $rateLimiter);

        $avg = $service->getAverageRating('ad', 101);

        // Score = (12 / (12+5)) * 4.5 + (5 / (12+5)) * 3.0 = (12/17)*4.5 + (5/17)*3.0 = 3.176 + 0.882 = 4.06
        $this->assertEquals(4.06, $avg);
    }

    /** @test */
    public function report_service_submits_reports_and_triggers_threshold_events(): void
    {
        $eventDispatcher = m::mock('Core\EventDispatcher');
        $db = m::mock('Core\Database');
        $logger = m::mock('App\Contracts\LoggerInterface');

        $logger->shouldIgnoreMissing();
        $db->shouldReceive('beginTransaction')->once();
        $db->shouldReceive('commit')->once();

        // Model mocks (raw SQL removed — delegated to InteractionModel)
        $interactionModel = m::mock('App\\Models\\InteractionModel');
        $interactionModel->shouldIgnoreMissing();
        $interactionModel->shouldReceive('createReport')->andReturn(1);
        $interactionModel->shouldReceive('countReports')->andReturn(5);

        $outbox = m::mock('App\\Contracts\\OutboxServiceInterface');
        $outbox->shouldIgnoreMissing();
        $outbox->shouldReceive('record')->once();

        $service = new ReportService($db, $logger, $interactionModel, $outbox);

        $result = $service->submit(12, 'ad', 101, ModuleContext::SOCIAL_TASKS, 'inappropriate_content');

        $this->assertTrue($result);
    }
}
