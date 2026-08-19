<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use App\Models\VitrineListing;
use App\Models\VitrineRequest;
use App\Services\VitrineService;
use App\Services\FeatureFlagService;
use App\Services\Settings\AppSettings;
use App\Services\User\UserService;
use App\Services\EscrowService;
use App\Domain\Financial\Services\FinancialEscrowService;
use Core\Database;
use Core\Container;
use Core\EventDispatcher;
use App\Contracts\LoggerInterface;
use Mockery as m;

/**
 * VitrineRatingAndReportTest — تست‌های رفتاری جدید سیستم امتیازدهی و گزارش تخلفات آگهی‌های ویترین
 */
class VitrineRatingAndReportTest extends TestCase
{
    /** @var Database&\Mockery\MockInterface */
    private Database $db;
    /** @var LoggerInterface&\Mockery\MockInterface */
    private LoggerInterface $logger;
    /** @var VitrineListing&\Mockery\MockInterface */
    private VitrineListing $listingModel;
    /** @var VitrineRequest&\Mockery\MockInterface */
    private VitrineRequest $requestModel;
    /** @var FeatureFlagService&\Mockery\MockInterface */
    private FeatureFlagService $flags;
    private VitrineService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->db = m::mock(Database::class);
        $this->logger = m::mock(LoggerInterface::class);
        $this->logger->shouldIgnoreMissing();
        $this->listingModel = m::mock(VitrineListing::class);
        $this->requestModel = m::mock(VitrineRequest::class);
        $this->flags = m::mock(FeatureFlagService::class);
        $eventDispatcher = m::mock(EventDispatcher::class);
        $eventDispatcher->shouldIgnoreMissing();
        $settings = m::mock(AppSettings::class);
        $userService = m::mock(UserService::class);
        $escrowService = m::mock(EscrowService::class);
        $financialEscrow = m::mock(FinancialEscrowService::class);
        $container = m::mock(Container::class);

        $this->service = new VitrineService(
            $eventDispatcher,
            $this->listingModel,
            $this->requestModel,
            $this->flags,
            $settings,
            $userService,
            $escrowService,
            $financialEscrow,
            $this->db,
            $container,
            $this->logger
        );
    }

    protected function tearDown(): void
    {
        m::close();
        parent::tearDown();
    }

    /** @test */
    public function toggle_watch_toggles_bookmark_state(): void
    {
        $this->listingModel->shouldReceive('find')->once()->with(10)->andReturn((object)[
            'id' => 10, 'seller_id' => 1, 'status' => 'active', 'price_usdt' => '100', 'category' => 'cat', 'platform' => 'tg', 'title' => 'Title'
        ]);
        $this->listingModel->shouldReceive('isWatched')->once()->with(5, 10)->andReturn(false);
        $this->listingModel->shouldReceive('addWatch')->once()->with(5, 10)->andReturn(true);

        $result = $this->service->toggleWatch(5, 10);

        $this->assertTrue($result['success']);
        $this->assertTrue(($result['watched'] ?? false));
    }

    /** @test */
    public function toggle_watch_removes_existing_bookmark(): void
    {
        $this->listingModel->shouldReceive('find')->once()->with(10)->andReturn((object)[
            'id' => 10, 'seller_id' => 1, 'status' => 'active', 'price_usdt' => '100', 'category' => 'cat', 'platform' => 'tg', 'title' => 'Title'
        ]);
        $this->listingModel->shouldReceive('isWatched')->once()->with(5, 10)->andReturn(true);
        $this->listingModel->shouldReceive('removeWatch')->once()->with(5, 10)->andReturn(true);

        $result = $this->service->toggleWatch(5, 10);

        $this->assertTrue($result['success']);
        $this->assertFalse(($result['watched'] ?? false));
    }

    /** @test */
    public function cancel_listing_succeeds_for_owner(): void
    {
        $listingObj = (object)[
            'id' => 15, 'seller_id' => 8, 'status' => 'active', 'price_usdt' => '100', 'category' => 'cat', 'platform' => 'tg', 'title' => 'Title'
        ];
        $this->db->shouldReceive('transactional')->once()->andReturnUsing(fn($cb) => $cb());
        $this->listingModel->shouldReceive('find')->once()->with(15)->andReturn($listingObj);
        $this->listingModel->shouldReceive('updateStatus')->once()->with(15, 'cancelled', m::type('array'))->andReturn(true);

        $result = $this->service->cancelListing(8, 15);

        $this->assertTrue($result['success']);
    }
}
