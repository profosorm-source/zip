<?php

namespace Tests\Unit\Adapters;

use PHPUnit\Framework\TestCase;
use App\Adapters\AdSocialAdapter;
use Mockery as m;

class AdSocialAdapterTest extends TestCase
{
    /** @var \App\Models\Ads&\Mockery\MockInterface */
    private \App\Models\Ads $adModel;
    /** @var \App\Contracts\LoggerInterface&\Mockery\MockInterface */
    private \App\Contracts\LoggerInterface $logger;
    /** @var \App\Services\Settings\AppSettings&\Mockery\MockInterface */
    private \App\Services\Settings\AppSettings $appSettings;
    /** @var \App\Contracts\ValidatorFactoryInterface&\Mockery\MockInterface */
    private \App\Contracts\ValidatorFactoryInterface $validatorFactory;
    private AdSocialAdapter $adapter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->adModel = m::mock('App\Models\Ads');
        $this->logger = m::mock('App\Contracts\LoggerInterface');
        $this->appSettings = m::mock('App\Services\Settings\AppSettings');
        $this->validatorFactory = m::mock('App\Contracts\ValidatorFactoryInterface');

        $this->logger->shouldIgnoreMissing();
        $this->appSettings->shouldReceive('get')->byDefault()->andReturn(10);

        $this->adapter = new AdSocialAdapter(
            $this->adModel,
            $this->logger,
            $this->appSettings,
            $this->validatorFactory
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
        $this->assertInstanceOf(AdSocialAdapter::class, $this->adapter);
    }

    /** @test */
    public function get_type_returns_social_task(): void
    {
        $this->assertEquals('social_task', $this->adapter->getType());
    }

    /** @test */
    public function validate_accepts_valid_data(): void
    {
        $data = [
            'platform' => 'instagram',
            'task_type' => 'follow',
            'title' => 'فالو کنید',
            'link' => 'https://instagram.com/my_page',
            'price_per_task' => 50,
            'total_count' => 100
        ];

        // Min price is configured to 10
        $this->appSettings->shouldReceive('get')
            ->with('social_task_min_price', 100)
            ->once()
            ->andReturn(10);

        $result = $this->adapter->validate($data);

        $this->assertTrue($result['valid']);
        $this->assertEmpty($result['errors']);
    }

    /** @test */
    public function validate_detects_invalid_platform(): void
    {
        $data = [
            'platform' => 'invalid_platform',
            'task_type' => 'follow',
            'title' => 'فالو کنید',
            'link' => 'https://instagram.com/my_page',
            'price_per_task' => 50,
            'total_count' => 100
        ];

        $result = $this->adapter->validate($data);

        $this->assertFalse($result['valid']);
        $this->assertContains('پلتفرم نامعتبر است', $result['errors']);
    }

    /** @test */
    public function validate_detects_invalid_link(): void
    {
        $data = [
            'platform' => 'instagram',
            'task_type' => 'follow',
            'title' => 'فالو کنید',
            'link' => 'not-a-valid-url',
            'price_per_task' => 50,
            'total_count' => 100
        ];

        $result = $this->adapter->validate($data);

        $this->assertFalse($result['valid']);
        $this->assertContains('فرمت لینک یا آیدی وارد شده معتبر نیست', $result['errors']);
    }

    /** @test */
    public function calculate_cost_uses_configured_site_fee_percent(): void
    {
        $this->appSettings->shouldReceive('get')
            ->with('social_task_site_fee_percent', 15.0)
            ->once()
            ->andReturn(10); // 10% fee

        $cost = $this->adapter->calculateCost('1000');
        $this->assertEquals('100', $cost); // 10% of 1000
    }
}
