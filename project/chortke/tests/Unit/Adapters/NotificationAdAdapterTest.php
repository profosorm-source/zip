<?php

namespace Tests\Unit\Adapters;

use PHPUnit\Framework\TestCase;
use App\Adapters\NotificationAdAdapter;
use Mockery as m;

class NotificationAdAdapterTest extends TestCase
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
    private NotificationAdAdapter $adapter;

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

        $this->adsBudgetSettlementService = m::mock('App\\Services\\Ads\\AdsBudgetSettlementService');

        $this->adapter = new NotificationAdAdapter(
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
        $this->assertInstanceOf(NotificationAdAdapter::class, $this->adapter);
    }

    /** @test */
    public function get_type_returns_notification(): void
    {
        $this->assertEquals('notification', $this->adapter->getType());
    }

    /** @test */
    public function validate_accepts_valid_notification_data(): void
    {
        $data = [
            'title' => 'تخفیف ویژه امروز',
            'body' => 'تا ۵۰٪ تخفیف روی تمامی محصولات سایت چرتکه!',
            'budget' => 5000,
        ];

        $result = $this->adapter->validate($data);

        $this->assertTrue($result['valid']);
        $this->assertEmpty($result['errors']);
    }

    /** @test */
    public function validate_detects_empty_body(): void
    {
        $data = [
            'title' => 'تخفیف ویژه امروز',
            'body' => '',
            'budget' => 5000,
        ];

        $result = $this->adapter->validate($data);

        $this->assertFalse($result['valid']);
        $this->assertContains('متن پیام نوتیفیکیشن باید حداقل ۱۰ کاراکتر باشد.', $result['errors']);
    }
}
