<?php

namespace Tests\Unit\Adapters;

use PHPUnit\Framework\TestCase;
use App\Adapters\BannerAdapter;
use Mockery as m;

class BannerAdapterTest extends TestCase
{
    /** @var \App\Models\Ads&\Mockery\MockInterface */
    private \App\Models\Ads $adModel;
    /** @var \App\Contracts\WalletServiceInterface&\Mockery\MockInterface */
    private \App\Contracts\WalletServiceInterface $walletService;
    /** @var \Core\Database&\Mockery\MockInterface */
    private \Core\Database $db;
    /** @var \App\Contracts\LoggerInterface&\Mockery\MockInterface */
    private \App\Contracts\LoggerInterface $logger;
    /** @var \App\Services\Settings\AppSettings&\Mockery\MockInterface */
    private \App\Services\Settings\AppSettings $appSettings;
    /** @var \App\Contracts\ValidatorFactoryInterface&\Mockery\MockInterface */
    private \App\Contracts\ValidatorFactoryInterface $validatorFactory;
    /** @var \App\Services\Shared\IdempotencyService&\Mockery\MockInterface */
    private \App\Services\Shared\IdempotencyService $idempotencyService;
    /** @var \App\Services\Ads\AdsBudgetSettlementService&\Mockery\MockInterface */
    private \App\Services\Ads\AdsBudgetSettlementService $adsBudgetSettlementService;
    private BannerAdapter $adapter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->adModel = m::mock('App\Models\Ads');
        $this->walletService = m::mock('App\Contracts\WalletServiceInterface');
        $this->db = m::mock('Core\Database');
        $this->logger = m::mock('App\Contracts\LoggerInterface');
        $this->appSettings = m::mock('App\Services\Settings\AppSettings');
        $this->validatorFactory = m::mock('App\Contracts\ValidatorFactoryInterface');
        $this->idempotencyService = m::mock('App\Services\Shared\IdempotencyService');

        $this->logger->shouldIgnoreMissing();
        $this->appSettings->shouldReceive('get')->byDefault()->andReturn(10);
        $this->appSettings->shouldReceive('get')
            ->with('banner_requires_admin_review', true)
            ->andReturn(true);

        $this->adsBudgetSettlementService = m::mock('App\\Services\\Ads\\AdsBudgetSettlementService');

        $this->adapter = new BannerAdapter(
            $this->adModel,
            $this->walletService,
            $this->db,
            $this->logger,
            $this->appSettings,
            $this->validatorFactory,
            $this->idempotencyService,
            $this->adsBudgetSettlementService
        );
    }

    protected function tearDown(): void
    {
        m::close();
        parent::tearDown();
    }

    /** @test */
    public function adapter_can_be_instantiated(): void
    {
        $this->assertInstanceOf(BannerAdapter::class, $this->adapter);
    }

    /** @test */
    public function get_type_returns_banner(): void
    {
        $this->assertEquals('banner', $this->adapter->getType());
    }

    /** @test */
    public function validate_accepts_valid_banner_data(): void
    {
        $data = [
            'title' => 'بنر تبلیغاتی تخفیف',
            'placement' => 'top_header',
            'image_path' => 'banners/sale.png',
            'link' => 'https://example.com/sale',
            'total_budget' => 500,
        ];

        $result = $this->adapter->validate($data);

        $this->assertTrue($result['valid']);
        $this->assertEmpty($result['errors']);
    }

    /** @test */
    public function validate_detects_empty_placement(): void
    {
        $data = [
            'title' => 'بنر تبلیغاتی تخفیف',
            'placement' => '',
            'image_path' => 'banners/sale.png',
            'link' => 'https://example.com/sale',
            'total_budget' => 500,
        ];

        $result = $this->adapter->validate($data);

        $this->assertFalse($result['valid']);
        $this->assertContains('انتخاب جایگاه تبلیغ الزامی است.', $result['errors']);
    }
}
