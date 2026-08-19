<?php

namespace Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use App\Services\BannerService;
use Mockery as m;

class BannerServiceTest extends TestCase
{
    /** @var \App\Models\Ads&\Mockery\MockInterface */
    private \App\Models\Ads $adsModel;
    /** @var \App\Models\BannerPlacement&\Mockery\MockInterface */
    private \App\Models\BannerPlacement $placementModel;
    /** @var \App\Contracts\WalletServiceInterface&\Mockery\MockInterface */
    private \App\Contracts\WalletServiceInterface $walletService;
    /** @var \App\Services\UploadService&\Mockery\MockInterface */
    private \App\Services\UploadService $uploadService;
    /** @var \Core\Database&\Mockery\MockInterface */
    private \Core\Database $database;
    /** @var \Core\Cache&\Mockery\MockInterface */
    private \Core\Cache $cache;
    /** @var \App\Contracts\LoggerInterface&\Mockery\MockInterface */
    private \App\Contracts\LoggerInterface $logger;
    private BannerService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->adsModel = m::mock('App\Models\Ads');
        $this->placementModel = m::mock('App\Models\BannerPlacement');
        $this->walletService = m::mock('App\Contracts\WalletServiceInterface');
        $this->uploadService = m::mock('App\Services\UploadService');
        $this->database = m::mock('Core\Database');
        $this->cache = m::mock('Core\Cache');
        $this->logger = m::mock('App\Contracts\LoggerInterface');
        
        // ترتیب صحیح سازنده‌ی واقعی:
        // (eventDispatcher, cache, db, logger, ads, placement, walletService, uploadService, outbox?)
        $this->service = new BannerService(
            $this->cache,
            $this->database,
            $this->logger,
            $this->adsModel,
            $this->placementModel,
            $this->walletService,
            $this->uploadService,
            null,
            null,
            m::mock('App\Services\Ads\AdsBudgetSettlementService')
        );
    }

    protected function tearDown(): void
    {
        m::close();
    }

    /** @test */
    public function service_can_be_instantiated(): void
    {
        $this->assertInstanceOf(BannerService::class, $this->service);
    }

    /** @test */
    public function service_has_required_methods(): void
    {
        $methods = ['getAllPlacements', 'findPlacement', 'findPlacementBySlug', 'getActivePlacements', 'getStats'];
        
        foreach ($methods as $method) {
            $this->assertTrue(method_exists($this->service, $method), "Method {$method} not found");
        }
    }
}
