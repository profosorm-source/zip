<?php

namespace Tests\Unit\Adapters;

use PHPUnit\Framework\TestCase;
use App\Adapters\AdTubeAdapter;
use Mockery as m;

class AdTubeAdapterTest extends TestCase
{
    /** @var \App\Models\Ads&\Mockery\MockInterface */
    private \App\Models\Ads $adModel;
    /** @var \App\Contracts\LoggerInterface&\Mockery\MockInterface */
    private \App\Contracts\LoggerInterface $logger;
    /** @var \App\Services\Settings\AppSettings&\Mockery\MockInterface */
    private \App\Services\Settings\AppSettings $appSettings;
    /** @var \App\Contracts\ValidatorFactoryInterface&\Mockery\MockInterface */
    private \App\Contracts\ValidatorFactoryInterface $validatorFactory;
    private AdTubeAdapter $adapter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->adModel = m::mock('App\Models\Ads');
        $this->logger = m::mock('App\Contracts\LoggerInterface');
        $this->appSettings = m::mock('App\Services\Settings\AppSettings');
        $this->validatorFactory = m::mock('App\Contracts\ValidatorFactoryInterface');

        $this->logger->shouldIgnoreMissing();
        $this->appSettings->shouldReceive('get')->byDefault()->andReturn(10);

        $this->adapter = new AdTubeAdapter(
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
        $this->assertInstanceOf(AdTubeAdapter::class, $this->adapter);
    }

    /** @test */
    public function get_type_returns_adtube(): void
    {
        $this->assertEquals('adtube', $this->adapter->getType());
    }

    /** @test */
    public function validate_accepts_valid_youtube_links(): void
    {
        $data = [
            'title' => 'ویدیو تست یوتیوب',
            'link' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
            'price_per_task' => 120,
            'total_count' => 100
        ];

        $this->appSettings->shouldReceive('get')
            ->with('adtube_min_price_per_view', 100)
            ->once()
            ->andReturn(100);

        $result = $this->adapter->validate($data);

        $this->assertTrue($result['valid']);
        $this->assertEmpty($result['errors']);
    }

    /** @test */
    public function validate_detects_invalid_youtube_links(): void
    {
        $data = [
            'title' => 'ویدیو تست یوتیوب',
            'link' => 'https://vimeo.com/12345',
            'price_per_task' => 120,
            'total_count' => 100
        ];

        $result = $this->adapter->validate($data);

        $this->assertFalse($result['valid']);
        $this->assertContains('لینک وارد شده باید یک لینک معتبر یوتیوب باشد', $result['errors']);
    }
}
