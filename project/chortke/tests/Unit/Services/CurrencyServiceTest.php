<?php

namespace Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use App\Services\CurrencyService;
use Mockery as m;

class CurrencyServiceTest extends TestCase
{
    /** @var \App\Services\Settings\AppSettings&\Mockery\MockInterface */
    private \App\Services\Settings\AppSettings $appSettings;
    /** @var \Core\Request&\Mockery\MockInterface */
    private \Core\Request $request;
    private CurrencyService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->appSettings = m::mock('App\Services\Settings\AppSettings');
        $this->request = m::mock('Core\Request');
        $this->service = new CurrencyService($this->appSettings, $this->request);
    }

    protected function tearDown(): void
    {
        m::close();
        parent::tearDown();
    }

    /** @test */
    public function service_can_be_instantiated(): void
    {
        $this->assertInstanceOf(CurrencyService::class, $this->service);
    }

    /** @test */
    public function get_current_mode_returns_configured_mode(): void
    {
        $this->appSettings->shouldReceive('get')
            ->with('currency_mode', 'irt')
            ->andReturn('USDT');

        $this->assertEquals('usdt', $this->service->getCurrentMode());
    }

    /** @test */
    public function get_current_mode_falls_back_to_irt_if_invalid(): void
    {
        $this->appSettings->shouldReceive('get')
            ->with('currency_mode', 'irt')
            ->andReturn('invalid_mode');

        $this->assertEquals('irt', $this->service->getCurrentMode());
    }

    /** @test */
    public function is_irt_returns_true_if_mode_is_irt(): void
    {
        $this->appSettings->shouldReceive('get')
            ->with('currency_mode', 'irt')
            ->andReturn('irt');

        $this->assertTrue($this->service->isIRT());
        $this->assertFalse($this->service->isUSDT());
    }

    /** @test */
    public function is_usdt_returns_true_if_mode_is_usdt(): void
    {
        $this->appSettings->shouldReceive('get')
            ->with('currency_mode', 'irt')
            ->andReturn('usdt');

        $this->assertTrue($this->service->isUSDT());
        $this->assertFalse($this->service->isIRT());
    }

    /** @test */
    public function get_currency_symbol(): void
    {
        $this->appSettings->shouldReceive('get')
            ->with('currency_mode', 'irt')
            ->andReturn('irt', 'usdt');
            
        $this->assertEquals('تومان', $this->service->getCurrencySymbol());
        $this->assertEquals('USDT', $this->service->getCurrencySymbol());
    }

    /** @test */
    public function format_amount_irt(): void
    {
        $formatted = $this->service->formatAmount(12500, 'irt');
        $this->assertEquals('12,500 تومان', $formatted);
    }

    /** @test */
    public function format_amount_usdt_with_decimals(): void
    {
        $formatted = $this->service->formatAmount(125.456, 'usdt');
        $this->assertEquals('125.456 USDT', $formatted);

        $formattedNoDecimals = $this->service->formatAmount(100, 'usdt');
        $this->assertEquals('100.00 USDT', $formattedNoDecimals);
    }

    /** @test */
    public function is_investment_section_by_uri(): void
    {
        $this->assertTrue($this->service->isInvestmentSection('/investment'));
        $this->assertTrue($this->service->isInvestmentSection('/investment/create'));
        $this->assertFalse($this->service->isInvestmentSection('/wallet'));
        $this->assertFalse($this->service->isInvestmentSection('/'));
    }

    /** @test */
    public function get_section_currency_for_investment(): void
    {
        $this->request->shouldReceive('uri')->andReturn('/investment/plans');
        
        $this->assertEquals('USDT', $this->service->getSectionCurrency());
    }

    /** @test */
    public function get_section_currency_for_standard(): void
    {
        $this->request->shouldReceive('uri')->andReturn('/dashboard');
        $this->appSettings->shouldReceive('get')
            ->with('currency_mode', 'irt')
            ->andReturn('irt');

        $this->assertEquals('irt', $this->service->getSectionCurrency());
    }
}
