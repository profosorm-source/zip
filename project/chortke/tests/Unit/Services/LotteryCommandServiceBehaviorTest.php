<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use Mockery as m;
use App\Services\Lottery\LotteryCommandService;
use App\Contracts\OutboxServiceInterface;

/**
 * @group architecture
 */
class LotteryCommandServiceBehaviorTest extends TestCase
{
    protected function tearDown(): void { m::close(); parent::tearDown(); }

    /** @return array{svc:LotteryCommandService,db:\Core\Database&\Mockery\MockInterface,roundModel:\App\Models\LotteryRound&\Mockery\MockInterface,participationModel:\App\Models\LotteryParticipation&\Mockery\MockInterface,dailyModel:\App\Models\LotteryDailyNumber&\Mockery\MockInterface,logger:\App\Contracts\LoggerInterface&\Mockery\MockInterface,wallet:\App\Contracts\WalletServiceInterface&\Mockery\MockInterface,idem:\App\Services\Shared\IdempotencyService&\Mockery\MockInterface,outbox:OutboxServiceInterface|null} */
    private function make(?OutboxServiceInterface $outbox = null): array
    {
        $db = m::mock('Core\\Database'); $db->shouldIgnoreMissing();
        $roundModel = m::mock('App\\Models\\LotteryRound'); $roundModel->shouldIgnoreMissing();
        $participationModel = m::mock('App\\Models\\LotteryParticipation'); $participationModel->shouldIgnoreMissing();
        $dailyModel = m::mock('App\\Models\\LotteryDailyNumber'); $dailyModel->shouldIgnoreMissing();
        $voteModel = m::mock('App\\Models\\LotteryVote'); $voteModel->shouldIgnoreMissing();
        $chanceLogModel = m::mock('App\\Models\\LotteryChanceLog'); $chanceLogModel->shouldIgnoreMissing();
        $logger = m::mock('App\\Contracts\\LoggerInterface'); $logger->shouldIgnoreMissing();
        $cache = m::mock('Core\\Cache'); $cache->shouldIgnoreMissing();
        $ed = m::mock('Core\\EventDispatcher'); $ed->shouldIgnoreMissing();
        $wallet = m::mock('App\\Contracts\\WalletServiceInterface'); $wallet->shouldIgnoreMissing();
        $idem = m::mock('App\\Services\\Shared\\IdempotencyService'); $idem->shouldIgnoreMissing();
        $escrow = m::mock('App\\Services\\EscrowService'); $escrow->shouldIgnoreMissing();

        $saga = m::mock('App\Services\SagaOrchestrator'); $saga->shouldIgnoreMissing();
        $svc = new LotteryCommandService($db, $roundModel, $participationModel, $dailyModel, $logger, $ed, $wallet, $idem, $saga, $outbox);
        return compact('svc', 'db', 'roundModel', 'participationModel', 'dailyModel', 'logger', 'wallet', 'idem', 'outbox');
    }

    /** @test */
    public function create_round_success(): void
    {
        $c = $this->make();
        $c['roundModel']->shouldReceive('create')->once()->andReturn(1);

        $result = $c['svc']->createRound(1, ['title' => 'Test Round']);
        $this->assertTrue($result['success']);
        $this->assertSame(1, int_value($result['round_id'] ?? 0));
    }

    /** @test */
    public function create_round_failure(): void
    {
        $c = $this->make();
        $c['roundModel']->shouldReceive('create')->once()->andReturn(null);

        $result = $c['svc']->createRound(1, []);
        $this->assertFalse($result['success']);
    }

    /** @test */
    public function generate_daily_numbers_creates_3_unique_numbers(): void
    {
        $c = $this->make();
        $c['dailyModel']->shouldReceive('getByRoundAndDate')->once()->andReturn(null);
        $c['dailyModel']->shouldReceive('create')->once()->andReturn(1);

        $result = $c['svc']->generateDailyNumbers(1);
        $this->assertTrue($result['success']);
        $numbers = $result['numbers'] ?? null;
        $this->assertIsArray($numbers);
        $this->assertCount(3, $numbers);
        $this->assertEquals(count(array_unique($numbers)), 3);
    }

    /** @test */
    public function generate_daily_numbers_fails_if_already_exists(): void
    {
        $c = $this->make();
        $c['dailyModel']->shouldReceive('getByRoundAndDate')->once()->andReturn((object)['id' => 1]);

        $result = $c['svc']->generateDailyNumbers(1);
        $this->assertFalse($result['success']);
    }

    /** @test */
    public function finalize_daily_number_success(): void
    {
        $c = $this->make();
        $c['dailyModel']->shouldReceive('update')->with(5, ['status' => 'finalized'])->once()->andReturn(true);

        $result = $c['svc']->finalizeDailyNumber(5);
        $this->assertTrue($result['success']);
    }

    /** @test */
    public function participate_fails_when_round_not_active(): void
    {
        $c = $this->make();
        $c['roundModel']->shouldReceive('find')->with(99)->andReturn(null);

        $result = $c['svc']->participate(1, 99);
        $this->assertFalse($result['success']);
    }

    /** @test */
    public function participate_fails_when_round_closed(): void
    {
        $c = $this->make();
        $c['roundModel']->shouldReceive('find')->with(1)->andReturn((object)['id' => 1, 'status' => 'completed', 'ticket_price' => '1000']);

        $result = $c['svc']->participate(1, 1);
        $this->assertFalse($result['success']);
    }

}
