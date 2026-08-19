<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Events\AlertRequestedEvent;
use App\Services\Sentry\Alerting\AlertDispatcher;
use App\Services\Sentry\ErrorMonitoring\SentryErrorMonitor;
use PHPUnit\Framework\TestCase;

final class SentryProducerContractBehaviorTest extends TestCase
{
    public function test_alert_dispatcher_rejects_malformed_internal_alert_before_persistence(): void
    {
        $model = $this->createMock(\App\Models\SentryModel::class);
        $model->expects($this->never())->method('storeAlert');
        $logger = $this->createMock(\Core\Logger::class);
        $logger->expects($this->once())->method('error');
        $service = new AlertDispatcher(
            $model,
            $logger,
            $this->createMock(\Core\EventDispatcher::class),
            $this->createMock(\App\Services\Settings\AppSettings::class)
        );

        $result = $service->handleAlertRequest(new AlertRequestedEvent([
            'severity' => ['critical'],
            'title' => 'broken',
            'message' => 'must not persist',
        ]));

        $this->assertFalse($result);
    }

    public function test_before_send_must_return_array_or_null(): void
    {
        $model = $this->createMock(\App\Models\SentryModel::class);
        $model->expects($this->never())->method('storeEventRecord');
        $monitor = new SentryErrorMonitor(
            $model,
            $this->createMock(AlertDispatcher::class),
            $this->createMock(\App\Contracts\CacheInterface::class),
            [
                'enabled' => true,
                'sample_rate' => 1.0,
                'before_send' => static fn(array $event): string => 'broken-contract',
            ]
        );

        $this->expectOutputRegex('/sentry\.error_monitor\.failed/');
        $this->assertNull($monitor->captureException(new \RuntimeException('test')));
    }
}
