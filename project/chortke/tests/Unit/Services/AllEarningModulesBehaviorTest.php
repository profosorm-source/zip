<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use App\Services\SocialTask\SocialTaskService;
use App\Services\ContentService;
use App\Services\InvestmentService;
use App\Services\Investment\InvestmentQueryService;
use App\Services\Investment\InvestmentCommandService;
use App\Services\PredictionService;
use App\Services\Lottery\LotteryService;
use App\Services\Lottery\LotteryCommandService;
use App\Services\Lottery\LotteryQueryService;
use App\Services\Shared\ReferralService;
use App\Services\Shared\CouponService;
use App\Models\SocialTaskModel;
use App\Models\ContentSubmission;
use App\Models\ContentRevenue;
use App\Models\ContentAgreement;
use App\Models\PredictionGame;
use App\Models\LotteryRound;
use App\Models\LotteryParticipation;
use App\Models\LotteryDailyNumber;
use App\Models\LotteryVote;
use App\Models\LotteryChanceLog;
use App\Models\Coupon;
use App\Models\CouponRedemption;
use App\Contracts\WalletServiceInterface;
use App\Services\Settings\AppSettings;
use App\Contracts\LoggerInterface;
use App\Services\EscrowService;
use App\Services\Shared\IdempotencyService;
use Core\Database;
use Core\EventDispatcher;
use Core\TransactionWrapper;
use Core\Cache;
use Core\Container;
use Mockery as m;

/**
 * AllEarningModulesBehaviorTest — تست‌های رفتاری و اکشن‌محور تمامی ۱۰ ماژول کسب درآمد پلتفرم
 */
class AllEarningModulesBehaviorTest extends TestCase
{
    /** @var Database&\Mockery\MockInterface */
    private Database $db;
    /** @var LoggerInterface&\Mockery\MockInterface */
    private LoggerInterface $logger;
    /** @var WalletServiceInterface&\Mockery\MockInterface */
    private WalletServiceInterface $wallet;
    /** @var AppSettings&\Mockery\MockInterface */
    private AppSettings $settings;
    /** @var TransactionWrapper&\Mockery\MockInterface */
    private TransactionWrapper $wrapper;

    protected function setUp(): void
    {
        parent::setUp();
        $this->db = m::mock(Database::class);
        $this->db->shouldReceive('fetch')->andReturn((object)[
            'id' => 5,
            'round_number' => 10,
            'status' => 'active',
            'prize_pool' => '100000',
            'draw_date' => '2026-12-31'
        ])->byDefault();
        $this->db->shouldReceive('fetchAll')->andReturn([])->byDefault();
        $this->db->shouldReceive('query')->andReturn([])->byDefault();

        $this->logger = m::mock(LoggerInterface::class);
        $this->logger->shouldIgnoreMissing();
        $this->wallet = m::mock(WalletServiceInterface::class);
        $this->settings = m::mock(AppSettings::class);
        $this->settings->shouldIgnoreMissing();
        $this->settings->shouldReceive('get')->with('content_max_pending_submissions', 1)->andReturn(5)->byDefault();
        $this->wrapper = m::mock(TransactionWrapper::class);
        $this->wrapper->shouldReceive('run')->andReturnUsing(fn($cb) => $cb())->byDefault();
        $this->wrapper->shouldReceive('runWithRetry')->andReturnUsing(fn($cb) => $cb())->byDefault();
    }

    protected function tearDown(): void
    {
        m::close();
        parent::tearDown();
    }

    // ─── ۱. Social Tasks (تسک‌های اجتماعی) ──────────────────────────

    /** @test */
    public function social_task_service_can_be_instantiated(): void
    {
        $taskModel = m::mock(SocialTaskModel::class);
        $taskModel->shouldReceive('getDb')->andReturn($this->db)->byDefault();
        $trustService = m::mock(\App\Services\Gamification\TrustService::class);
        $antiFraud = m::mock(\App\Services\SocialTask\SilentAntiFraudService::class);
        $userService = m::mock(\App\Services\User\UserService::class);
        $socialAccountService = m::mock(\App\Services\SocialAccountService::class);
        $interactionRatingService = m::mock(\App\Services\Interaction\RatingService::class);
        $scoreService = m::mock(\App\Services\ScoreService::class);
        $idempotencyService = m::mock(IdempotencyService::class);
        $container = m::mock(\Core\Container::class);
        $adsBudgetSettlement = m::mock(\App\Services\Ads\AdsBudgetSettlementService::class);
        $adSystemManager = m::mock(\App\Services\AdSystemManager::class);

        $service = new SocialTaskService(
            $this->logger,
            $taskModel,
            $trustService,
            $antiFraud,
            $userService,
            $socialAccountService,
            $interactionRatingService,
            $scoreService,
            $idempotencyService,
            $container,
            $adsBudgetSettlement,
            $adSystemManager
        );

        $this->assertInstanceOf(SocialTaskService::class, $service);
    }

    // ─── ۲. Content Creator Revenue (تولید محتوا) ───────────────────

    /** @test */
    public function content_submission_workflow_validates_and_submits(): void
    {
        $eventDispatcher = m::mock(EventDispatcher::class);
        $eventDispatcher->shouldIgnoreMissing();
        $submissionModel = m::mock(ContentSubmission::class);
        $revenueModel = m::mock(ContentRevenue::class);
        $agreementModel = m::mock(ContentAgreement::class);

        $submissionModel->shouldReceive('countByUser')->once()->with(7, 'pending')->andReturn(0);
        $submissionModel->shouldReceive('isUrlExists')->once()->andReturn(false);
        $submissionModel->shouldReceive('create')->once()->andReturn(50);
        $agreementModel->shouldReceive('create')->once()->andReturn(12);

        $service = new ContentService(
            $this->logger,
            $this->db,
            $submissionModel,
            $revenueModel,
            $agreementModel,
            $this->wrapper,
            $this->settings
        );

        $result = $service->submitContent(7, [
            'title' => 'ویدیو آموزش معامله در چرتکه',
            'video_url' => 'https://www.youtube.com/watch?v=abcdef12345',
            'platform' => 'youtube',
            'category' => 'education',
            'agreement_accepted' => 1
        ]);

        $this->assertTrue($result['success']);
        $data = $result['data'] ?? null;
        $this->assertIsArray($data);
        $this->assertSame(50, $data['submission_id']);
    }

    // ─── ۳. Investment & Yield (سرمایه‌گذاری) ──────────────────────

    /** @test */
    public function investment_service_queries_solvency_report(): void
    {
        $commandService = m::mock(InvestmentCommandService::class);
        $queryService = m::mock(InvestmentQueryService::class);

        $queryService->shouldReceive('getSolvencyReport')->once()->andReturn([
            'is_solvent' => true,
            'coverage_ratio' => 1.25
        ]);

        $service = new InvestmentService($commandService, $queryService);
        $report = $service->getSolvencyReport();

        $this->assertTrue($report['is_solvent']);
        $this->assertEquals(1.25, $report['coverage_ratio']);
    }

    // ─── ۴. Predictive Games & Bets (پیش‌بینی) ──────────────────────

    /** @test */
    public function prediction_service_delegates_place_bet(): void
    {
        $placeBetJob = m::mock(\App\Jobs\Prediction\PlaceBetJob::class);
        $settleGameJob = m::mock(\App\Jobs\Prediction\SettleGameJob::class);
        $cancelGameJob = m::mock(\App\Jobs\Prediction\CancelGameJob::class);

        $placeBetJob->shouldReceive('handle')
            ->once()
            ->with(5, 20, 'home', '100000', 'key123')
            ->andReturn(['success' => true, 'bet_id' => 88]);

        $service = new PredictionService($placeBetJob, $settleGameJob, $cancelGameJob);
        $result = $service->placeBet(5, 20, 'home', '100000', 'key123');

        $this->assertTrue($result['success']);
        $this->assertSame(88, $result['bet_id']);
    }

    // ─── ۵. Daily Weighted Lottery (لاتاری و بخت‌آزمایی) ─────────────

    /** @test */
    public function lottery_service_generates_daily_numbers(): void
    {
        $roundModel = m::mock(LotteryRound::class);
        $roundModel->shouldReceive('find')->andReturn((object)[
            'id' => 5, 'round_number' => 10, 'status' => 'active', 'prize_pool' => '100000'
        ])->byDefault();

        $dailyModel = m::mock(LotteryDailyNumber::class);
        $dailyModel->shouldReceive('getByRoundAndDate')->once()->with(5, date('Y-m-d'))->andReturn(null);
        $dailyModel->shouldReceive('create')->once()->andReturn(12);

        $service = new LotteryService(
            $this->db,
            $roundModel,
            m::mock(LotteryParticipation::class),
            $dailyModel,
            $this->logger,
            m::mock(Cache::class),
            m::mock(EventDispatcher::class),
            $this->wallet,
            m::mock(IdempotencyService::class),
            m::mock(\App\Services\SagaOrchestrator::class)
        );

        $result = $service->generateDailyNumbers(5);

        $this->assertTrue($result['success']);
        $this->assertSame(12, $result['daily_id']);
    }

    // ─── ۶. Coupons & Gift Codes (کدهای هدیه) ────────────────────────

    /** @test */
    public function coupon_service_applies_cap_and_calculates_discount(): void
    {
        $couponModel = m::mock(Coupon::class);
        $redemptionModel = m::mock(CouponRedemption::class);

        $couponModel->shouldReceive('findByCode')->once()->with('GIFT2026')->andReturn((object)[
            'id' => 1,
            'code' => 'GIFT2026',
            'type' => 'percent',
            'value' => '15',
            'max_discount' => '100000',
            'min_purchase' => '500000',
            'usage_limit' => 100,
            'usage_count' => 10,
            'applicable_to' => 'all',
            'active' => 1,
            'start_date' => null,
            'end_date' => null,
            'status' => 'active'
        ]);

        $redemptionModel->shouldReceive('hasUserUsedCoupon')->once()->with(12, 1)->andReturn(false);

        $service = new CouponService(
            $this->wrapper,
            $this->db,
            $this->logger,
            $couponModel,
            $redemptionModel
        );

        $calc = $service->validateAndCalculate('GIFT2026', '1000000', 'irt', 12);

        $this->assertTrue($calc['valid']);
        $this->assertEquals(100000.0, $calc['discount_amount']); // Capped at max_discount 100,000
        $this->assertEquals(900000.0, $calc['final_amount']);
    }
}
