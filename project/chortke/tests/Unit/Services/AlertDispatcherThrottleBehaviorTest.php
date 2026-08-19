<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use App\Events\AlertRequestedEvent;
use Mockery as m;

/**
 * تست حرفه‌ای رفتار throttle و store در AlertDispatcher::handleAlertRequest.
 *
 * پوشش:
 *   - alert اخیر (throttled) → بدون store، بازگشت false.
 *   - alert جدید + کانال نامعتبر → store انجام می‌شود ولی بدون ارسال، بازگشت false.
 *   - alert جدید + بدون کانال فعال → store انجام می‌شود، بازگشت false.
 */
class AlertDispatcherThrottleBehaviorTest extends TestCase
{
    protected function tearDown(): void { m::close(); parent::tearDown(); }

    /**
     * @return array{
     *   svc: \App\Services\Sentry\Alerting\AlertDispatcher,
     *   model: \App\Models\SentryModel&\Mockery\MockInterface,
     *   logger: \Core\Logger&\Mockery\MockInterface
     * }
     */
    private function make(): array
    {
        $model = m::mock(\App\Models\SentryModel::class);
        $model->shouldIgnoreMissing();
        $logger = m::mock(\Core\Logger::class);
        $logger->shouldIgnoreMissing();
        $dispatcher = m::mock(\Core\EventDispatcher::class);
        $dispatcher->shouldIgnoreMissing();
        $appSettings = m::mock(\App\Services\Settings\AppSettings::class);
        $appSettings->shouldIgnoreMissing();

        $svc = new \App\Services\Sentry\Alerting\AlertDispatcher(
            $model,
            $logger,
            $dispatcher,
            $appSettings
        );

        return ['svc' => $svc, 'model' => $model, 'logger' => $logger];
    }

    /** @param array<string, mixed> $overrides */
    private function alert(array $overrides = []): AlertRequestedEvent
    {
        return new AlertRequestedEvent(array_merge([
            'type'     => 'unit_test',
            'severity' => 'critical',
            'title'    => 'unit-test-alert',
            'message'  => 'message',
            'environment' => 'production',
        ], $overrides));
    }

    /** @test */
    public function recent_alert_is_throttled_without_being_stored(): void
    {
        $c = $this->make();
        // یک alert اخیر (created_at=now) → isThrottled=true
        $c['model']->shouldReceive('getLastAlert')->andReturn(
            (object)['created_at' => date('Y-m-d H:i:s')]
        );
        $c['model']->shouldNotReceive('storeAlert');
        $c['logger']->shouldReceive('info')->once();

        $this->assertFalse($c['svc']->handleAlertRequest($this->alert()));
    }

    /** @test */
    public function new_alert_with_invalid_channel_is_stored_but_not_sent(): void
    {
        $c = $this->make();
        // بدون alert قبلی → throttle نمی‌شود
        $c['model']->shouldReceive('getLastAlert')->andReturn(null);
        $c['model']->shouldReceive('storeAlert')->once()->andReturn(100);
        // یک کانال با channel_type ناشناخته → sendToChannel به default → false
        $c['model']->shouldReceive('getActiveChannels')->once()->andReturn([
            (object)['id' => 1, 'channel_type' => 'pager_duty', 'config' => ''],
        ]);
        $c['model']->shouldReceive('recordNotificationHistory')->once()->with(1, 100, 'failed');
        $c['model']->shouldNotReceive('markAlertAsSent');

        $this->assertFalse($c['svc']->handleAlertRequest($this->alert()));
    }

    /** @test */
    public function new_alert_with_no_active_channels_is_stored_but_returns_false(): void
    {
        $c = $this->make();
        $c['model']->shouldReceive('getLastAlert')->andReturn(null);
        $c['model']->shouldReceive('storeAlert')->once()->andReturn(200);
        $c['model']->shouldReceive('getActiveChannels')->once()->andReturn([]);
        $c['model']->shouldNotReceive('markAlertAsSent');

        $this->assertFalse($c['svc']->handleAlertRequest($this->alert()));
    }
}
