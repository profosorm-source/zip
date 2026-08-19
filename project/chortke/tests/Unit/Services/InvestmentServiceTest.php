<?php

namespace Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use App\Services\InvestmentService;
use App\Services\Investment\InvestmentCommandService;
use App\Services\Investment\InvestmentQueryService;
use Mockery as m;

class InvestmentServiceTest extends TestCase
{
    private InvestmentService $service;
    /** @var InvestmentCommandService&\Mockery\MockInterface */
    private InvestmentCommandService $commandService;
    /** @var InvestmentQueryService&\Mockery\MockInterface */
    private InvestmentQueryService $queryService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->commandService = m::mock(InvestmentCommandService::class);
        $this->queryService = m::mock(InvestmentQueryService::class);

        // ساخت facade بدون constructor (از reflection برای set کردن sub-service ها)
        $this->service = (new \ReflectionClass(InvestmentService::class))
            ->newInstanceWithoutConstructor();

        $ref = new \ReflectionClass(InvestmentService::class);

        $cmdProp = $ref->getProperty('commandService');
        $cmdProp->setAccessible(true);
        $cmdProp->setValue($this->service, $this->commandService);

        $qryProp = $ref->getProperty('queryService');
        $qryProp->setAccessible(true);
        $qryProp->setValue($this->service, $this->queryService);
    }

    protected function tearDown(): void
    {
        m::close();
        parent::tearDown();
    }

    /** @test */
    public function service_can_be_instantiated(): void
    {
        $this->assertInstanceOf(InvestmentService::class, $this->service);
    }

    /** @test */
    public function get_risk_warning_delegates_to_command_service(): void
    {
        $this->commandService->shouldReceive('getRiskWarning')
            ->once()
            ->andReturn('هشدار ریسک سرمایه‌گذاری: فعالیت در بازار فارکس/طلا با ریسک بالایی همراه است');

        $warning = $this->service->getRiskWarning();
        $this->assertStringContainsString('هشدار ریسک سرمایه‌گذاری', $warning);
    }

    /** @test */
    public function get_settings_delegates_to_query_service(): void
    {
        $this->queryService->shouldReceive('getSettings')
            ->once()
            ->andReturn([
                'min_amount' => 50.0,
                'max_amount' => 5000.0,
                'site_fee_percent' => 5,
                'tax_percent' => 4,
            ]);

        $settings = $this->service->getSettings();

        $this->assertEquals(50.0, $settings['min_amount']);
        $this->assertEquals(5000.0, $settings['max_amount']);
    }

    /** @test */
    public function get_solvency_report_delegates_to_query_service(): void
    {
        $this->queryService->shouldReceive('getSolvencyReport')
            ->once()
            ->andReturn([
                'ratio' => '1.50000000',
                'shortfall' => '0',
                'total_investments' => '10000.00',
                'real_assets' => '15000',
                'status' => 'solvent',
            ]);

        $report = $this->service->getSolvencyReport();

        $this->assertEquals('10000.00', $report['total_investments']);
        $this->assertEquals('15000', $report['real_assets']);
        $this->assertEquals('1.50000000', $report['ratio']);
    }
}
