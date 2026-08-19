<?php

declare(strict_types=1);

namespace Tests\Unit\Jobs;

use PHPUnit\Framework\TestCase;
use Mockery as m;
use App\Jobs\Investment\CreateTradeJob;
use App\Models\TradingRecord;
use App\Contracts\LoggerInterface;
use App\Services\AuditTrail;

/**
 * تست رگرسیون برای CreateTradeJob::handle()
 *
 * این کلاس قبلاً یک باگ داشت: در خط ثبت وضعیت ترید، به‌جای استفاده از
 * `use App\Models\TradingRecord;`، از نام کوتاه `TradingRecord::STATUS_*`
 * استفاده می‌شد. چون این کلاس در namespace App\Jobs\Investment قرار دارد
 * و import لازم وجود نداشت، PHP این نام را به کلاس ناموجود
 * `App\Jobs\Investment\TradingRecord` resolve می‌کرد و هر بار که یک ترید
 * با close_time بسته می‌شد، خطای Fatal "Class not found" رخ می‌داد.
 *
 * این تست مشخصاً همان مسیر (بستن ترید) را پوشش می‌دهد تا این باگ دیگر
 * بدون شکست تست بازنگردد.
 */
class CreateTradeJobTest extends TestCase
{
    protected function tearDown(): void
    {
        m::close();
        parent::tearDown();
    }

    /** @param array<string,mixed> $overrides
     *  @return array<string,mixed> */
    private function baseData(array $overrides = []): array
    {
        return array_merge([
            'direction'  => TradingRecord::DIRECTION_BUY,
            'open_price' => 100.5,
            'pair'       => 'BTC/USDT',
            'lot_size'   => 1.0,
        ], $overrides);
    }

    /** @test */
    public function it_creates_an_open_trade_when_close_time_is_not_provided(): void
    {
        $tradingModel = m::mock(TradingRecord::class);
        $tradingModel->shouldReceive('create')
            ->once()
            ->with(m::on(function (array $data) {
                return $data['status'] === TradingRecord::STATUS_OPEN;
            }))
            ->andReturn(101);

        $logger = m::mock(LoggerInterface::class);
        $logger->shouldReceive('info')->once();

        $auditTrail = m::mock(AuditTrail::class);
        $auditTrail->shouldReceive('record')->once();

        $job = new CreateTradeJob($tradingModel, $logger, $auditTrail);

        $result = $job->handle(1, $this->baseData());

        $this->assertTrue($result['success']);
        $this->assertSame(101, $result['trade_id']);
    }

    /** @test */
    public function it_creates_a_closed_trade_when_close_time_is_provided(): void
    {
        // این تست دقیقاً باگ اصلی را پوشش می‌دهد: بستن ترید (close_time ست شده)
        $tradingModel = m::mock(TradingRecord::class);
        $tradingModel->shouldReceive('create')
            ->once()
            ->with(m::on(function (array $data) {
                return $data['status'] === TradingRecord::STATUS_CLOSED;
            }))
            ->andReturn(202);

        $logger = m::mock(LoggerInterface::class);
        $logger->shouldReceive('info')->once();

        $auditTrail = m::mock(AuditTrail::class);
        $auditTrail->shouldReceive('record')->once();

        $job = new CreateTradeJob($tradingModel, $logger, $auditTrail);

        $result = $job->handle(1, $this->baseData([
            'close_time'  => date('Y-m-d H:i:s'),
            'close_price' => 105.0,
        ]));

        $this->assertTrue($result['success']);
        $this->assertSame(202, $result['trade_id']);
    }

    /** @test */
    public function it_returns_a_failure_message_when_trade_creation_fails(): void
    {
        $tradingModel = m::mock(TradingRecord::class);
        $tradingModel->shouldReceive('create')->once()->andReturn(null);

        $logger = m::mock(LoggerInterface::class);
        // handle() should not reach the success-path logging when creation fails
        $logger->shouldNotReceive('info');

        $auditTrail = m::mock(AuditTrail::class);
        $auditTrail->shouldNotReceive('record');

        $job = new CreateTradeJob($tradingModel, $logger, $auditTrail);

        $result = $job->handle(1, $this->baseData());

        $this->assertFalse($result['success']);
    }
}
