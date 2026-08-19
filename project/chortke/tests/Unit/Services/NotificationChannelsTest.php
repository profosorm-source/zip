<?php

namespace Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use App\Services\Notification\Channels\LogChannel;
use App\Services\Notification\Channels\PushChannel;
use App\Services\Notification\Channels\SmsChannel;
use App\Services\Notification\Channels\FcmChannel;
use App\Services\Notification\NotificationRetryPolicy;
use Mockery as m;

class NotificationChannelsTest extends TestCase
{
    protected function tearDown(): void
    {
        m::close();
        parent::tearDown();
    }

    /** @test */
    public function log_channel_delegates_to_log_adapter(): void
    {
        $logAdapter = m::mock('App\Adapters\Notification\LogNotificationAdapter');
        
        $logAdapter->shouldReceive('sendAlert')
            ->with('واریز موفق', 'پیام واریز وجه')
            ->once()
            ->andReturn(true);

        $channel = new LogChannel($logAdapter);

        $this->assertEquals('log', $channel->getName());
        $this->assertTrue($channel->send(12, 'واریز موفق', 'پیام واریز وجه'));
    }

    /** @test */
    public function push_channel_delegates_to_push_adapter(): void
    {
        $pushAdapter = m::mock('App\Adapters\Notification\PushNotificationAdapter');

        $pushAdapter->shouldReceive('sendToUser')
            ->with(12, 'تخفیف ویژه', 'کد تخفیف چرتکه', [], null, null)
            ->once()
            ->andReturn(true);

        $channel = new PushChannel($pushAdapter);

        $this->assertEquals('push', $channel->getName());
        $this->assertTrue($channel->send(12, 'تخفیف ویژه', 'کد تخفیف چرتکه'));
    }

    /** @test */
    public function sms_channel_delegates_to_sms_adapter(): void
    {
        $smsAdapter = m::mock('App\Adapters\Notification\SmsNotificationAdapter');

        $smsAdapter->shouldReceive('sendToUser')
            ->with(12, 'کد فعال‌سازی شما: ۱۲۳۴')
            ->once()
            ->andReturn(true);

        $channel = new SmsChannel($smsAdapter);

        $this->assertEquals('sms', $channel->getName());
        $this->assertTrue($channel->send(12, 'عنوان نادیده', 'کد فعال‌سازی شما: ۱۲۳۴'));
    }

    /** @test */
    public function fcm_channel_delegates_to_fcm_service(): void
    {
        $fcmService = m::mock('App\Services\Notification\FcmService');

        $fcmService->shouldReceive('sendToUser')
            ->with(12, 'تسک جدید', 'یک تسک جدید برای شما ثبت شد', [], null, null)
            ->once()
            ->andReturn(true);

        $channel = new FcmChannel($fcmService);

        $this->assertEquals('fcm', $channel->getName());
        $this->assertTrue($channel->send(12, 'تسک جدید', 'یک تسک جدید برای شما ثبت شد'));
    }

    /** @test */
    public function retry_policy_executes_retry_loop_on_failure_and_respects_circuit(): void
    {
        $cache = m::mock('Core\Cache');
        $logger = m::mock('App\Contracts\LoggerInterface');
        $circuit = m::mock('Core\CircuitBreaker');

        $logger->shouldIgnoreMissing();

        // 1. First execution: Circuit is closed, operation succeeds on second attempt
        $cache->shouldReceive('get')->with('notif_circuit_open:push', false)->once()->andReturn(false);
        $cache->shouldReceive('forget')->with('notif_failures:push')->once();
        $cache->shouldReceive('forget')->with('notif_circuit_open:push')->once();

        $policy = new NotificationRetryPolicy($cache, $logger, null); // bypass core CB for pure retry loop test

        $attempts = 0;
        $operation = function() use (&$attempts) {
            $attempts++;
            return $attempts === 2; // Fail first, succeed on second (policy allows 2 attempts for push)
        };

        $result = $policy->execute('push', $operation);

        $this->assertTrue($result);
        $this->assertEquals(2, $attempts);
    }
}
