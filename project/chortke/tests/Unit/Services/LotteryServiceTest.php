<?php

namespace Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use App\Services\Lottery\LotteryService;
use Mockery as m;

class LotteryServiceTest extends TestCase
{
    /** @var \Core\Database&\Mockery\MockInterface */
    private \Core\Database $db;
    /** @var \App\Models\LotteryRound&\Mockery\MockInterface */
    private \App\Models\LotteryRound $roundModel;
    /** @var \App\Models\LotteryParticipation&\Mockery\MockInterface */
    private \App\Models\LotteryParticipation $participationModel;
    /** @var \App\Models\LotteryDailyNumber&\Mockery\MockInterface */
    private \App\Models\LotteryDailyNumber $dailyModel;
    /** @var \App\Contracts\LoggerInterface&\Mockery\MockInterface */
    private \App\Contracts\LoggerInterface $logger;
    /** @var \Core\Cache&\Mockery\MockInterface */
    private \Core\Cache $cache;
    /** @var \Core\EventDispatcher&\Mockery\MockInterface */
    private \Core\EventDispatcher $eventDispatcher;
    /** @var \App\Contracts\WalletServiceInterface&\Mockery\MockInterface */
    private \App\Contracts\WalletServiceInterface $walletService;
    /** @var \App\Services\Shared\IdempotencyService&\Mockery\MockInterface */
    private \App\Services\Shared\IdempotencyService $idempotencyService;
    private LotteryService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->db = m::mock('Core\Database');
        $this->roundModel = m::mock('App\Models\LotteryRound');
        $this->participationModel = m::mock('App\Models\LotteryParticipation');
        $this->dailyModel = m::mock('App\Models\LotteryDailyNumber');
        $this->logger = m::mock('App\Contracts\LoggerInterface');
        $this->cache = m::mock('Core\Cache');
        $this->eventDispatcher = m::mock('Core\EventDispatcher');
        $this->walletService = m::mock('App\Contracts\WalletServiceInterface');
        $this->idempotencyService = m::mock('App\Services\Shared\IdempotencyService');

        $this->logger->shouldIgnoreMissing();
        $this->eventDispatcher->shouldIgnoreMissing();
        
        // Default Cache Mock to return null (cache miss)
        $this->cache->shouldReceive('get')->byDefault()->andReturn(null);
        $this->cache->shouldReceive('put')->byDefault();

        $this->service = new LotteryService(
            $this->db,
            $this->roundModel,
            $this->participationModel,
            $this->dailyModel,
            $this->logger,
            $this->cache,
            $this->eventDispatcher,
            $this->walletService,
            $this->idempotencyService,
            m::mock('App\Services\SagaOrchestrator')
        );
    }

    protected function tearDown(): void
    {
        m::close();
        parent::tearDown();
    }

    /** @test */
    public function service_can_be_instantiated(): void
    {
        $this->assertInstanceOf(LotteryService::class, $this->service);
    }

    /** @test */
    public function get_transparency_text_returns_persian_transparency_statement(): void
    {
        $text = $this->service->getTransparencyText();
        $this->assertStringContainsString('شفافیت و اعتمادسازی سیستم قرعه‌کشی چرتکه', $text);
        $this->assertStringContainsString('امنیت', $text);
    }

    /** @test */
    public function get_round_statistics_computes_correctly(): void
    {
        $roundId = 5;

        $roundMock = (object)[
            'id' => $roundId,
            'title' => 'بزرگ هفتگی',
            'ticket_price' => '2.5',
            'prize_pool' => '500.0',
            'status' => 'active'
        ];

        $this->roundModel->shouldReceive('find')
            ->with($roundId)
            ->once()
            ->andReturn($roundMock);

        $this->participationModel->shouldReceive('getAllActiveByRound')
            ->with($roundId)
            ->once()
            ->andReturn([
                (object)['id' => 1, 'user_id' => 10],
                (object)['id' => 2, 'user_id' => 11]
            ]);

        $this->participationModel->shouldReceive('getTotalChanceScore')
            ->with($roundId)
            ->once()
            ->andReturn(150.0);

        $this->participationModel->shouldReceive('getChanceDistribution')
            ->with($roundId)
            ->once()
            ->andReturn(['range_1_10' => 2]);

        $this->dailyModel->shouldReceive('getByRound')
            ->with($roundId)
            ->once()
            ->andReturn([]);

        $stats = $this->service->getRoundStatistics($roundId);

        $this->assertTrue($stats['success']);
        $round = $stats['round'] ?? null;
        $this->assertInstanceOf(\stdClass::class, $round);
        $this->assertEquals($roundId, $round->id);
        $this->assertEquals(2, $stats['total_participants']);
        $this->assertEquals(150.0, $stats['total_chance_score']);
        $this->assertEquals(75.0, $stats['average_score']);
    }
}
