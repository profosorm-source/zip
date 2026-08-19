<?php

namespace Tests\Unit\Adapters;

use PHPUnit\Framework\TestCase;
use App\Adapters\VandarInquiryAdapter;
use Mockery as m;

class VandarInquiryAdapterTest extends TestCase
{
    /** @var \App\Contracts\LoggerInterface&\Mockery\MockInterface */
    private \App\Contracts\LoggerInterface $logger;
    private VandarInquiryAdapter $adapter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->logger = m::mock('App\Contracts\LoggerInterface');
        $this->logger->shouldIgnoreMissing();

        $cache = m::mock('App\Contracts\CacheInterface');
        $circuit = m::mock('App\Contracts\CircuitBreakerInterface');
        $this->adapter = new VandarInquiryAdapter($this->logger, $cache, $circuit);
    }

    protected function tearDown(): void
    {
        m::close();
        parent::tearDown();
    }

    /** @test */
    public function adapter_can_be_instantiated(): void
    {
        $this->assertInstanceOf(VandarInquiryAdapter::class, $this->adapter);
    }

    /** @test */
    public function is_configured_returns_false_without_credentials(): void
    {
        $this->assertFalse($this->adapter->isConfigured());
    }

    /** @test */
    public function inquire_iban_rejects_invalid_input_without_transport(): void
    {
        $result = $this->adapter->inquireIban('IR123456789012345678901234');
        $this->assertFalse($result['success']);
        $this->assertIsString($result['message']);
        $this->assertStringContainsString('نامعتبر', $result['message']);
    }
}
