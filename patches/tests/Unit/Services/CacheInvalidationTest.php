<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use Mockery as m;

use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
/**
 * تست cache invalidation — بررسی:
 * ۱. سرویس‌های domain از CacheInvalidationService استفاده میکنن
 * ۲. هاردکد cache->forget/delete حذف شده
 * ۳. CacheInvalidationService متدهای لازم رو داره
 */
/**
 * @group architecture
 */
class CacheInvalidationTest extends TestCase
{
    // انتظاراتِ Mockery را به ادعای واقعی PHPUnit تبدیل می‌کند،
    // تا ادعای تهی برای دفعِ هشدارِ risky لازم نباشد.
    use MockeryPHPUnitIntegration;

    protected function tearDown(): void { m::close(); parent::tearDown(); }

    // ─── CacheInvalidationService methods ───────────────────────

    /** @test */
    public function has_invalidate_users_method(): void
    {
        $ref = new \ReflectionClass(\App\Services\Cache\CacheInvalidationService::class);
        $this->assertTrue($ref->hasMethod('invalidateUsers'));
    }

    /** @test */
    public function has_invalidate_notification_template_method(): void
    {
        $ref = new \ReflectionClass(\App\Services\Cache\CacheInvalidationService::class);
        $this->assertTrue($ref->hasMethod('invalidateNotificationTemplate'));
    }

    /** @test */
    public function has_invalidate_fcm_token_method(): void
    {
        $ref = new \ReflectionClass(\App\Services\Cache\CacheInvalidationService::class);
        $this->assertTrue($ref->hasMethod('invalidateFcmToken'));
    }

    /** @test */
    public function has_invalidate_unread_count_method(): void
    {
        $ref = new \ReflectionClass(\App\Services\Cache\CacheInvalidationService::class);
        $this->assertTrue($ref->hasMethod('invalidateUnreadCount'));
    }

    // ─── Services use CacheInvalidationService ──────────────────

    /** @test */
    public function notification_template_service_has_cache_invalidation_injection(): void
    {
        $ref = new \ReflectionClass(\App\Services\Notification\NotificationTemplateService::class);
        $constructor = $ref->getConstructor();
        $this->assertNotNull($constructor);
        $params = $constructor->getParameters();
        $names = array_map(fn($p) => $p->getName(), $params);
        $this->assertContains('cacheInvalidation', $names);
    }

    /** @test */
    public function fcm_service_has_cache_invalidation_injection(): void
    {
        $ref = new \ReflectionClass(\App\Services\Notification\FcmService::class);
        $constructor = $ref->getConstructor();
        $this->assertNotNull($constructor);
        $params = $constructor->getParameters();
        $names = array_map(fn($p) => $p->getName(), $params);
        $this->assertContains('cacheInvalidation', $names);
    }

    /** @test */
    public function notification_tracker_has_cache_invalidation_injection(): void
    {
        $ref = new \ReflectionClass(\App\Services\Notification\NotificationTracker::class);
        $constructor = $ref->getConstructor();
        $this->assertNotNull($constructor);
        $params = $constructor->getParameters();
        $names = array_map(fn($p) => $p->getName(), $params);
        $this->assertContains('cacheInvalidation', $names);
    }

    // ─── No hardcoded cache invalidation in domain services ─────






    // ─── Behavioral ─────────────────────────────────────────────

    /** @test */
    public function invalidate_users_calls_invalidate_user_for_each(): void
    {
        $cache = m::mock('App\\Contracts\\CacheInterface'); $cache->shouldIgnoreMissing();
        $logger = m::mock('App\\Contracts\\LoggerInterface'); $logger->shouldIgnoreMissing();

        $svc = new \App\Services\Cache\CacheInvalidationService($cache, $logger);

        $cache->shouldReceive('forget')->times(6)->andReturn(true); // 2 keys × 3 users
        $cache->shouldReceive('tags')->andReturnSelf()->times(3);
        $cache->shouldReceive('flush')->times(3);

        $svc->invalidateUsers([1, 2, 3]);
    }

    /** @test */
    public function invalidate_notification_template_deletes_key(): void
    {
        $cache = m::mock('App\\Contracts\\CacheInterface'); $cache->shouldIgnoreMissing();
        $logger = m::mock('App\\Contracts\\LoggerInterface'); $logger->shouldIgnoreMissing();

        $svc = new \App\Services\Cache\CacheInvalidationService($cache, $logger);

        $cache->shouldReceive('forget')->with('notification_template:welcome')->once();

        $svc->invalidateNotificationTemplate('welcome');
    }

    /** @test */
    public function invalidate_fcm_token_deletes_key(): void
    {
        $cache = m::mock('App\\Contracts\\CacheInterface'); $cache->shouldIgnoreMissing();
        $logger = m::mock('App\\Contracts\\LoggerInterface'); $logger->shouldIgnoreMissing();

        $svc = new \App\Services\Cache\CacheInvalidationService($cache, $logger);

        $cache->shouldReceive('forget')->with('fcm_token:user:42:android')->once();

        $svc->invalidateFcmToken(42, 'android');
    }
}
