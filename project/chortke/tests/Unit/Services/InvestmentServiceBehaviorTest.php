<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\Investment\InvestmentCommandService;
use App\Services\Investment\InvestmentQueryService;
use App\Services\InvestmentService;
use Mockery as m;
use PHPUnit\Framework\TestCase;

final class InvestmentServiceBehaviorTest extends TestCase
{
    protected function tearDown(): void { m::close(); parent::tearDown(); }

    public function test_create_delegates_complete_command_without_mutating_payload(): void
    {
        $command=m::mock(InvestmentCommandService::class);
        $query=m::mock(InvestmentQueryService::class);
        $options=['risk_accepted'=>1,'idempotency_key'=>'runtime-key'];
        $command->shouldReceive('create')->once()->with(7,'100.25','usdt',$options)->andReturn(['success'=>true,'investment_id'=>91]);
        $result=(new InvestmentService($command,$query))->create(7,'100.25','usdt',$options);
        $this->assertTrue((bool)$result['success']);
        $this->assertSame(91,$result['investment_id']);
        $this->assertSame(['risk_accepted'=>1,'idempotency_key'=>'runtime-key'],$options);
    }

    public function test_risk_warning_delegates_to_command_service(): void
    {
        $command=m::mock(InvestmentCommandService::class);
        $query=m::mock(InvestmentQueryService::class);
        $command->shouldReceive('getRiskWarning')->once()->andReturn('هشدار ریسک واقعی');
        $this->assertSame('هشدار ریسک واقعی',(new InvestmentService($command,$query))->getRiskWarning());
    }

    public function test_settings_delegate_to_query_service_with_complete_contract(): void
    {
        $command=m::mock(InvestmentCommandService::class);
        $query=m::mock(InvestmentQueryService::class);
        $settings=['min_amount'=>10,'max_amount'=>10000,'site_fee_percent'=>2,'tax_percent'=>0,'withdrawal_cooldown'=>7,'deposit_lock'=>7];
        $query->shouldReceive('getSettings')->once()->andReturn($settings);
        $this->assertSame($settings,(new InvestmentService($command,$query))->getSettings());
    }
}
