<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use App\Services\Notification\SmsNotificationService;
use App\Adapters\Notification\SmsNotificationAdapter;
use App\Contracts\LoggerInterface;
use Mockery as m;

class SmsNotificationServiceTest extends TestCase
{
    /** @var SmsNotificationAdapter&\Mockery\MockInterface */
    private SmsNotificationAdapter $adapter;
    private SmsNotificationService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->adapter = m::mock(SmsNotificationAdapter::class);
        $this->service = new SmsNotificationService($this->adapter);
    }

    protected function tearDown(): void
    {
        m::close();
        parent::tearDown();
    }

    public function test_send_delegates_to_adapter(): void
    {
        $this->adapter->shouldReceive('send')
            ->once()
            ->with('09123456789', 'Hello Test')
            ->andReturn(true);

        $this->assertTrue($this->service->send('09123456789', 'Hello Test'));
    }

    public function test_send_security_alert_delegates_to_adapter(): void
    {
        $this->adapter->shouldReceive('sendSecurityAlert')
            ->once()
            ->with('09123456789', 'Security Alert')
            ->andReturn(true);

        $this->assertTrue($this->service->sendSecurityAlert('09123456789', 'Security Alert'));
    }

    public function test_send_security_alert_to_user_delegates_to_adapter(): void
    {
        $this->adapter->shouldReceive('sendSecurityAlertToUser')
            ->once()
            ->with(123, 'Security Alert')
            ->andReturn(true);

        $this->assertTrue($this->service->sendSecurityAlertToUser(123, 'Security Alert'));
    }

    public function test_send_withdrawal_alert_delegates_to_adapter(): void
    {
        $this->adapter->shouldReceive('sendWithdrawalAlert')
            ->once()
            ->with('09123456789', '50000', 'irt')
            ->andReturn(true);

        $this->assertTrue($this->service->sendWithdrawalAlert('09123456789', '50000', 'irt'));
    }

    public function test_send_withdrawal_alert_to_user_delegates_to_adapter(): void
    {
        $this->adapter->shouldReceive('sendWithdrawalAlertToUser')
            ->once()
            ->with(123, '50000', 'irt')
            ->andReturn(true);

        $this->assertTrue($this->service->sendWithdrawalAlertToUser(123, '50000', 'irt'));
    }

    public function test_is_enabled_delegates_to_adapter(): void
    {
        $this->adapter->shouldReceive('isEnabled')
            ->once()
            ->andReturn(true);

        $this->assertTrue($this->service->isEnabled());
    }
}
