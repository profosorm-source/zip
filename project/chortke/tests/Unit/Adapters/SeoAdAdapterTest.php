<?php

namespace Tests\Unit\Adapters;

use PHPUnit\Framework\TestCase;
use App\Adapters\SeoAdAdapter;
use Core\ValueObjects\Money;
use Mockery as m;

class SeoAdAdapterTest extends TestCase
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
    private SeoAdAdapter $adapter;

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

        $this->adapter = new SeoAdAdapter(
            $this->adModel,
            $this->walletService,
            $this->db,
            $this->logger,
            $this->appSettings,
            $this->validatorFactory,
            $this->idempotencyService
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
        $this->assertInstanceOf(SeoAdAdapter::class, $this->adapter);
    }

    /** @test */
    public function get_type_returns_seo(): void
    {
        $this->assertEquals('seo', $this->adapter->getType());
    }

    /** @test */
    public function validate_accepts_valid_seo_data(): void
    {
        $data = [
            'title' => 'بهبود سئو رتبه یک گوگل',
            'site_url' => 'https://mywebsite.com',
            'keyword' => 'آموزش برنامه نویسی',
            'price_per_click' => 150,
            'budget' => 50000
        ];

        $result = $this->adapter->validate($data);

        $this->assertTrue($result['valid']);
        $this->assertEmpty($result['errors']);
    }

    /** @test */
    public function validate_detects_invalid_url(): void
    {
        $data = [
            'title' => 'بهبود سئو رتبه یک گوگل',
            'site_url' => 'not-a-valid-url',
            'keyword' => 'آموزش برنامه نویسی',
            'price_per_click' => 150,
            'budget' => 50000
        ];

        $result = $this->adapter->validate($data);

        $this->assertFalse($result['valid']);
        $this->assertContains('آدرس سایت معتبر نیست.', $result['errors']);
    }

    /**
     * رگرسیون float→decimal: محاسبهٔ کارمزد باید از مسیر Money/BCMath عبور کند،
     * نه float+round که خطای اعشار تولید می‌کند.
     * @test
     */
    public function calculate_cost_uses_bcmath_for_irt_and_matches_money(): void
    {
        // فیِ پیش‌فرض ۱۰٪ از setUp تأمین می‌شود
        $amount = '1000000';
        $expected = Money::fromString($amount, 'irt')->percentage('10')->getAmount();

        $result = $this->adapter->calculateCost($amount, ['currency' => 'irt']);

        $this->assertSame('100000', $result);
        $this->assertSame($expected, $result);
    }

    /**
     * رگرسیون float→decimal: نتیجه باید با scale رسمیِ IRT (۴ رقم اعشار) و دقیقِ رشته‌ای باشد،
     * نه خروجیِ round(...,8) روی float.
     * @test
     */
    public function calculate_cost_is_exact_and_respects_irt_scale(): void
    {
        $amount = '333.3333';
        $expected = Money::fromString($amount, 'irt')->percentage('10')->getAmount();

        $result = $this->adapter->calculateCost($amount, ['currency' => 'irt']);

        $this->assertSame('33.3333', $result);
        $this->assertSame($expected, $result);
    }

    /**
     * رگرسیون float→decimal: خروجی normalize() که در دیتابیس ذخیره می‌شود باید
     * رشتهٔ decimal دقیق باشد، نه float. مقدار کسریٔ بودجه اگر از float عبور کند
     * به صورت float ذخیره می‌شود و این assertSame روی رشته شکست می‌خورد.
     * @test
     */
    public function create_persists_budget_as_exact_decimal_string_not_float(): void
    {
        $captured = null;
        $this->adModel->shouldReceive('create')->once()->with(m::on(function ($arg) use (&$captured) {
            $captured = $arg;
            return true;
        }))->andReturn(123);

        $this->adapter->create(7, [
            'title' => 'بهبود سئو رتبه یک گوگل',
            'site_url' => 'https://mywebsite.com',
            'keyword' => 'آموزش برنامه نویسی',
            'budget' => '123456.78',
            'min_payout' => '5000',
            'max_payout' => '10000',
        ]);

        $this->assertNotNull($captured, 'مدل create فراخوانی نشد (احتمالاً validation رد شد)');
        $this->assertIsString($captured['budget']);
        $this->assertSame('123456.78', $captured['budget']);
        $this->assertSame('123456.78', $captured['total_budget']);
        $this->assertSame('123456.78', $captured['remaining_budget']);
        $this->assertSame('5000', $captured['min_payout']);
    }
}
