<?php

namespace Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use App\Services\Notification\NotificationAnalyticsService;
use App\Services\Notification\NotificationTracker;
use Mockery as m;

class NotificationTrackerAndAnalyticsTest extends TestCase
{
    protected function tearDown(): void
    {
        m::close();
        parent::tearDown();
    }

    /** @test */
    public function analytics_service_remembers_stats_in_cache_correctly(): void
    {
        $cache = m::mock('App\Contracts\CacheInterface');
        $model = m::mock('App\Models\Notification');

        $stats = ['delivered' => 500, 'opened' => 450];

        // Cache remember mock
        $cache->shouldReceive('remember')
            ->with('notif_analytics:overview:30', 15, m::type('callable'))
            ->once()
            ->andReturn($stats);

        $service = new NotificationAnalyticsService($cache, $model);

        $result = $service->getAnalyticsOverview(30);

        $this->assertSame($stats, $result);
    }

    /** @test */
    public function tracker_gets_unread_count_from_cache_and_model_correctly(): void
    {
        $model = m::mock('App\Models\Notification');

        // Setup global Cache mock using reflection
        $cache = m::mock('App\Contracts\CacheInterface');
        $cache->shouldReceive('get')->with('notif_unread:45')->once()->andReturn(null); // cache miss
        $cache->shouldReceive('put')->with('notif_unread:45', 5, 5)->once();

        // Model returns count = 5
        $model->shouldReceive('countUnread')->with(45)->once()->andReturn(5);

        $tracker = new NotificationTracker($model, $cache, null);

        $this->assertEquals(5, $tracker->getUnreadCount(45));
    }

    /** @test */
    public function tracker_marks_notification_as_read_and_invalidates_cache(): void
    {
        $model = m::mock('App\Models\Notification');

        $model->shouldReceive('markAsRead')->with(101, 45)->once()->andReturn(true);

        // Setup global Cache mock using reflection
        $cache = m::mock('App\Contracts\CacheInterface');
        $cache->shouldReceive('forget')->with('notif_unread:45')->once();

        $tracker = new NotificationTracker($model, $cache, null);

        $this->assertTrue($tracker->markAsRead(101, 45));
    }
}
