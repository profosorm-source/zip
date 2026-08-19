<?php

declare(strict_types=1);

namespace Tests\Unit\Adapters;

use App\Adapters\BankInquiryAdapter;
use App\Adapters\BankInquiryManager;
use App\Contracts\LoggerInterface;
use Mockery as m;
use PHPUnit\Framework\TestCase;

final class BankInquiryManagerBehaviorTest extends TestCase
{
    protected function tearDown(): void { m::close(); parent::tearDown(); }

    public function test_chain_falls_back_and_stops_after_first_success(): void
    {
        $first=m::mock(BankInquiryAdapter::class); $second=m::mock(BankInquiryAdapter::class); $third=m::mock(BankInquiryAdapter::class);
        $first->shouldReceive('inquireIban')->once()->andReturn(['success'=>false,'message'=>'temporary']);
        $second->shouldReceive('inquireIban')->once()->andReturn(['success'=>true,'owner_name'=>'Owner','bank'=>'Bank']);
        $third->shouldNotReceive('inquireIban');
        $logger = m::mock(LoggerInterface::class);
        $logger->shouldIgnoreMissing();
        $result=(new BankInquiryManager($logger,[$first,$second,$third]))->inquireIban('IR820540102680020817909002');
        $this->assertTrue((bool)$result['success']);
        $this->assertSame('Owner',$result['owner_name']);
    }

    public function test_chain_contains_exception_and_returns_failure_after_all_providers(): void
    {
        $first=m::mock(BankInquiryAdapter::class); $second=m::mock(BankInquiryAdapter::class);
        $first->shouldReceive('inquireIban')->once()->andThrow(new \RuntimeException('network down'));
        $second->shouldReceive('inquireIban')->once()->andReturn(['success'=>false,'message'=>'provider rejected']);
        $logger = m::mock(LoggerInterface::class);
        $logger->shouldIgnoreMissing();
        $result=(new BankInquiryManager($logger,[$first,$second]))->inquireIban('IR820540102680020817909002');
        $this->assertFalse((bool)$result['success']);
        $this->assertStringContainsString('provider rejected',str_value($result['message'] ?? ''));
    }
}
