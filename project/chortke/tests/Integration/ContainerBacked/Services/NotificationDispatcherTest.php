<?php

declare(strict_types=1);

namespace Tests\Integration\ContainerBacked\Services;

use PHPUnit\Framework\TestCase;
use App\Services\Notification\NotificationDispatcher;
use App\Services\Notification\Channels\PushChannel;
use App\Services\Notification\Channels\SmsChannel;
use App\Services\Notification\Channels\FcmChannel;
use App\Services\Notification\Channels\LogChannel;
use App\Services\Notification\NotificationRetryPolicy;
use App\Services\Notification\NotificationPreferenceService;
use App\Contracts\LoggerInterface;
use Core\Queue;
use Mockery as m;

/**
 * تست NotificationDispatcher بر اساس معماری فعلی (Channel-based).
 *
 * نکته: نسخه‌ی قبلی این تست برای معماری قدیمی Adapter نوشته شده بود و $adapter->sendToUser
 * را mock می‌کرد. معماری فعلی از Channelها (Push/Sms/Fcm/LogChannel با متد send و getName)
 * استفاده می‌کند و سازنده‌ی آن (logger, pushChannel, smsChannel, fcmChannel, logChannel, queue, retryPolicy)
 * است. این فایل کاملاً برای معماری Channel بازنویسی شده است.
 */
class NotificationDispatcherTest extends TestCase
{
    /** @var PushChannel&\Mockery\MockInterface */
    private PushChannel $pushChannel;
    /** @var SmsChannel&\Mockery\MockInterface */
    private SmsChannel $smsChannel;
    /** @var FcmChannel&\Mockery\MockInterface */
    private FcmChannel $fcmChannel;
    /** @var LogChannel&\Mockery\MockInterface */
    private LogChannel $logChannel;
    /** @var LoggerInterface&\Mockery\MockInterface */
    private LoggerInterface $logger;
    /** @var NotificationRetryPolicy&\Mockery\MockInterface */
    private NotificationRetryPolicy $retryPolicy;
    /** @var NotificationPreferenceService&\Mockery\MockInterface */
    private NotificationPreferenceService $prefService;
    private NotificationDispatcher $dispatcher;
    /** @var \Core\Cache&\Mockery\MockInterface */
    private \Core\Cache $cache;

    protected function setUp(): void
    {
        parent::setUp();
        // هر Channel mock باید getName() را پاسخ دهد چون registerChannel از آن برای کلید map استفاده می‌کند.
        $this->pushChannel = m::mock(PushChannel::class);
        $this->pushChannel->shouldReceive('getName')->andReturn('push')->byDefault();

        $this->smsChannel = m::mock(SmsChannel::class);
        $this->smsChannel->shouldReceive('getName')->andReturn('sms')->byDefault();

        $this->fcmChannel = m::mock(FcmChannel::class);
        $this->fcmChannel->shouldReceive('getName')->andReturn('fcm')->byDefault();

        $this->logChannel = m::mock(LogChannel::class);
        $this->logChannel->shouldReceive('getName')->andReturn('log')->byDefault();

        $this->logger = m::mock(LoggerInterface::class);
        $this->logger->shouldIgnoreMissing();


        // retryPolicy::execute فقط callback را اجرا می‌کند (بدون منطق retry در تست).
        $this->retryPolicy = m::mock(NotificationRetryPolicy::class);
        $this->retryPolicy->shouldReceive('execute')
            ->andReturnUsing(fn ($channel, $callback) => $callback())
            ->byDefault();

        $this->prefService = m::mock(NotificationPreferenceService::class);
        $this->prefService->shouldIgnoreMissing();

        $this->cache = m::mock(\Core\Cache::class);
        $this->cache->shouldReceive('get')->andReturn(null)->byDefault();
        $this->cache->shouldReceive('putSeconds')->andReturn(true)->byDefault();

        $this->dispatcher = new NotificationDispatcher(
            $this->logger,
            $this->pushChannel,
            $this->smsChannel,
            $this->fcmChannel,
            $this->logChannel,
            $this->retryPolicy,
            $this->prefService,
            $this->cache
        );
    }

    protected function tearDown(): void
    {
        m::close();
        parent::tearDown();
    }

    /** @test */
    public function it_can_be_instantiated(): void
    {
        $this->assertInstanceOf(NotificationDispatcher::class, $this->dispatcher);
    }

    /** @test */
    public function it_dispatches_via_registered_sms_channel(): void
    {
        $userId = 123;
        $title = 'Welcome';
        $message = 'Hello User!';

        $this->smsChannel->shouldReceive('send')
            ->once()
            ->with($userId, $title, $message, null, null, null)
            ->andReturn(true);

        $result = $this->dispatcher->dispatch('sms', $userId, $title, $message);
        $this->assertTrue($result);
    }

    /** @test */
    public function it_dispatches_via_registered_fcm_channel(): void
    {
        $userId = 456;
        $title = 'Push Alert';
        $message = 'FCM Message';

        $this->fcmChannel->shouldReceive('send')
            ->once()
            ->with($userId, $title, $message, null, null, null)
            ->andReturn(true);

        $result = $this->dispatcher->dispatch('fcm', $userId, $title, $message);
        $this->assertTrue($result);
    }

    /** @test */
    public function it_processes_bulk_notifications_directly_in_one_hop_without_creating_jobs(): void
    {
        ob_start();
        $userIds = [1, 2];
        $title = 'Bulk Notification';
        $message = 'Hello to all!';

        $this->prefService->shouldReceive('prefetchPreferences')->once()->with($userIds)->andReturn(null);

        // کانال FCM باید دقیقاً دو بار (یکی برای هر کاربر) صدا زده شود.
        $this->fcmChannel->shouldReceive('send')->twice()->andReturn(true);

        $res = $this->dispatcher->dispatchBulk('fcm', $userIds, $title, $message);

        $this->assertTrue($res['success']);
        $this->assertEquals(2, $res['processed']);
        ob_end_clean();
    }

    /** @test */
    public function it_skips_duplicated_messages_in_bulk_dispatches_via_deduplication_cache(): void
    {
        $userIds = [1, 2];
        $title = 'Bulk Dedup';
        $message = 'Single message';
        $data = ['message_id' => 'msg_xyz_123'];

        $this->prefService->shouldReceive('prefetchPreferences')->once()->with($userIds)->andReturn(null);

        // شبیه‌سازی: کاربر ۱ قبلاً پیام را گرفته (کلید dedup موجود است)، کاربر ۲ نه.
        $this->cache->shouldReceive('get')
            ->with('notif_sent:fcm:msg_xyz_123:1')
            ->once()
            ->andReturn('1');
        $this->cache->shouldReceive('get')
            ->with('notif_sent:fcm:msg_xyz_123:2')
            ->once()
            ->andReturn(null);
        $this->cache->shouldReceive('putSeconds')
            ->with('notif_sent:fcm:msg_xyz_123:2', '1', 86400)
            ->once()
            ->andReturn(true);

        // فقط کاربر ۲ باید پیام دریافت کند (یک‌بار).
        $this->fcmChannel->shouldReceive('send')
            ->once()
            ->with(2, $title, $message, $data, null, null)
            ->andReturn(true);

        $res = $this->dispatcher->dispatchBulk('fcm', $userIds, $title, $message, $data);

        $this->assertTrue($res['success']);
        $this->assertEquals(1, $res['processed']);

    }
}
