<?php

namespace Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use App\Services\Notification\NotificationTemplateService;
use App\Services\Notification\NotificationPreferenceService;
use App\Services\Notification\NotificationPolicyService;
use Mockery as m;

class AdvancedNotificationsTest extends TestCase
{
    protected function tearDown(): void
    {
        m::close();
        parent::tearDown();
    }

    /** @test */
    public function template_service_renders_and_interpolates_successfully(): void
    {
        $cache = m::mock('App\Contracts\CacheInterface');
        $model = m::mock('App\Models\Notification');

        // Bypassing cache
        $cache->shouldReceive('get')->andReturn(null);
        $cache->shouldReceive('put')->byDefault();

        // Mock DB Template
        $dbTemplate = (object)[
            'title' => 'واریز موفق ✅',
            'message' => 'مبلغ {{amount}} {{currency}} با موفقیت به کیف پول شما واریز شد.',
            'variables' => json_encode(['amount', 'currency'])
        ];
        $model->shouldReceive('getTemplateFromDb')->with('deposit')->once()->andReturn($dbTemplate);

        $service = new NotificationTemplateService($cache, $model);

        $result = $service->renderTemplate('deposit', [
            'amount' => '1000',
            'currency' => 'تومان'
        ]);

        $this->assertEquals('واریز موفق ✅', $result['title']);
        $this->assertEquals('مبلغ 1000 تومان با موفقیت به کیف پول شما واریز شد.', $result['message']);
    }

    /** @test */
    public function preference_service_gets_and_stores_preferences_in_cache(): void
    {
        $prefModel = m::mock('App\Models\NotificationPreference');
        $cacheInvalidation = m::mock('App\Services\Cache\CacheInvalidationService');
        $cache = m::mock('App\Contracts\CacheInterface');

        $cache->shouldReceive('get')->andReturn(null);
        $cache->shouldReceive('put')->once();

        $prefMock = (object)[
            'user_id' => 12,
            'in_app_notifications' => true,
            'push_notifications' => false,
            'sms_notifications' => true,
            'email_notifications' => false,
            'dnd_start' => '22:00:00',
            'dnd_end' => '08:00:00',
            'in_app_enabled' => true,
        ];
        $prefModel->shouldReceive('getOrCreate')->with(12)->once()->andReturn($prefMock);
        // isInAppEnabled فراخوانی می‌کند getEnabledChannelsForType که خالی باشد تا از fallback استفاده کند
        $prefModel->shouldReceive('getEnabledChannelsForType')->with(12, 'deposit')->andReturn([]);

        // Mock the getEnabledChannelsForType method that isInAppEnabled calls internally
        $prefModel->shouldReceive('getEnabledChannelsForType')
            ->with(12, 'deposit')
            ->andReturn(['in_app' => true, 'push' => false, 'sms' => true, 'email' => false]);

        // Mock isChannelEnabledForType used by isInAppEnabled, isPushEnabled, isSmsEnabled, isEmailEnabled
        $prefModel->shouldReceive('isChannelEnabledForType')->with(12, 'in_app', 'deposit')->andReturn(true);
        $prefModel->shouldReceive('isChannelEnabledForType')->with(12, 'push', 'deposit')->andReturn(false);
        $prefModel->shouldReceive('isChannelEnabledForType')->with(12, 'sms', 'deposit')->andReturn(true);
        $prefModel->shouldReceive('isChannelEnabledForType')->with(12, 'email', 'deposit')->andReturn(false);

        // Standard constructor
        $service = new NotificationPreferenceService($prefModel, $cacheInvalidation, $cache);

        $result = $service->getPreferences(12);

        $this->assertSame($prefMock, $result);
        $this->assertTrue($service->isInAppEnabled(12, 'deposit'));
        $this->assertFalse($service->isPushEnabled(12, 'deposit'));
        $this->assertTrue($service->isSmsEnabled(12, 'deposit'));
        $this->assertFalse($service->isEmailEnabled(12, 'deposit'));
    }

    /** @test */
    public function policy_service_checks_rate_limit_and_resolves_dnd_schedule(): void
    {
        $rateLimiter = m::mock('Core\RateLimiter');
        $appSettings = m::mock('App\Services\Settings\AppSettings');
        $preferenceService = m::mock('App\Services\Notification\NotificationPreferenceService');
        $logger = m::mock('App\Contracts\LoggerInterface');

        $logger->shouldIgnoreMissing();

        // 1. Rate Limiter checks
        $appSettings->shouldReceive('get')->with('notif_rate_max_hour', 20)->andReturn(20);
        $appSettings->shouldReceive('get')->with('notif_rate_window_minutes', 60)->andReturn(60);
        
        $rateLimiter->shouldReceive('attempt')
            ->with('notif_rl_user_12', 20, 60)
            ->once()
            ->andReturn(true);

        // 2. DND deferment checks
        $preferenceService->shouldReceive('isInDndMode')->with(12)->once()->andReturn(true);
        $preferenceService->shouldReceive('getNextDndEndTime')->with(12)->once()->andReturn('2026-06-04 08:00:00');

        $service = new NotificationPolicyService($rateLimiter, $appSettings, $preferenceService, $logger);

        $this->assertTrue($service->checkRateLimit(12));
        
        // Non-urgent priority with active DND mode should get deferred
        $deferred = $service->resolveScheduledTime(12, 'normal', null);
        $this->assertEquals('2026-06-04 08:00:00', $deferred);
    }
}
