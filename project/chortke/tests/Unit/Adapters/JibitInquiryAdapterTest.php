<?php

namespace Tests\Unit\Adapters;

use PHPUnit\Framework\TestCase;
use App\Adapters\JibitInquiryAdapter;
use Mockery as m;

class JibitInquiryAdapterTest extends TestCase
{
    /** @var \App\Contracts\LoggerInterface&\Mockery\MockInterface */
    private \App\Contracts\LoggerInterface $logger;
    /** @var \App\Contracts\CacheInterface&\Mockery\MockInterface */
    private \App\Contracts\CacheInterface $cache;
    /** @var \App\Contracts\CircuitBreakerInterface&\Mockery\MockInterface */
    private \App\Contracts\CircuitBreakerInterface $circuitBreaker;
    private JibitInquiryAdapter $adapter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->logger = m::mock('App\Contracts\LoggerInterface');
        $this->cache = m::mock('App\Contracts\CacheInterface');
        $this->circuitBreaker = m::mock('App\Contracts\CircuitBreakerInterface');

        $this->logger->shouldIgnoreMissing();
        $this->cache->shouldReceive('get')->byDefault()->andReturn(null);
        $this->cache->shouldReceive('put')->byDefault();

        $this->adapter = new JibitInquiryAdapter($this->logger, $this->cache, $this->circuitBreaker);
    }

    protected function tearDown(): void
    {
        m::close();
        parent::tearDown();
    }

    /** @test */
    public function adapter_can_be_instantiated(): void
    {
        $this->assertInstanceOf(JibitInquiryAdapter::class, $this->adapter);
    }

    /** @test */
    public function is_configured_returns_false_by_default_if_keys_missing(): void
    {
        $this->assertFalse($this->adapter->isConfigured());
    }

    /** @test */
    public function inquire_iban_returns_error_if_not_configured(): void
    {
        $result = $this->adapter->inquireIban('IR123456789012345678901234');
        
        $this->assertFalse($result['success']);
        $this->assertIsString($result['message']);
        $this->assertStringContainsString('پیکربندی Jibit انجام نشده است', $result['message']);
    }
}
