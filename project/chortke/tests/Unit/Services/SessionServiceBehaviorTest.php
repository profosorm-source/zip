<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use Mockery as m;

class SessionServiceBehaviorTest extends TestCase
{
    protected function tearDown(): void { m::close(); parent::tearDown(); }

    /** @test */
    public function service_instantiates_with_outbox(): void
    {
        $redis = m::mock('Core\\Redis'); $redis->shouldIgnoreMissing();
        $db = m::mock('Core\\Database'); $db->shouldIgnoreMissing();
        $logger = m::mock('App\\Contracts\\LoggerInterface'); $logger->shouldIgnoreMissing();
        $model = m::mock('App\\Models\\SecurityModel'); $model->shouldIgnoreMissing();
        $policy = m::mock('App\Services\AntiFraud\RiskPolicyService'); $policy->shouldIgnoreMissing();
        $notif = m::mock('App\\Services\\Notification\\NotificationService'); $notif->shouldIgnoreMissing();
        $lock = m::mock('App\\Services\\DistributedLockService'); $lock->shouldIgnoreMissing();
        $outbox = m::mock('App\\Contracts\\OutboxServiceInterface'); $outbox->shouldIgnoreMissing();

        $svc = new \App\Services\Auth\SessionService($redis, $db, $logger, $model, $policy, $lock, $outbox);
        $this->assertInstanceOf(\App\Services\Auth\SessionService::class, $svc);
    }

    /** @test */
    public function service_instantiates_without_outbox(): void
    {
        $redis = m::mock('Core\\Redis'); $redis->shouldIgnoreMissing();
        $db = m::mock('Core\\Database'); $db->shouldIgnoreMissing();
        $logger = m::mock('App\\Contracts\\LoggerInterface'); $logger->shouldIgnoreMissing();
        $model = m::mock('App\\Models\\SecurityModel'); $model->shouldIgnoreMissing();
        $policy = m::mock('App\Services\AntiFraud\RiskPolicyService'); $policy->shouldIgnoreMissing();
        $notif = m::mock('App\\Services\\Notification\\NotificationService'); $notif->shouldIgnoreMissing();
        $lock = m::mock('App\\Services\\DistributedLockService'); $lock->shouldIgnoreMissing();

        $svc = new \App\Services\Auth\SessionService($redis, $db, $logger, $model, $policy, $lock);
        $this->assertInstanceOf(\App\Services\Auth\SessionService::class, $svc);
    }

    /** @test */
    public function terminate_session_rejects_when_not_owned_by_user(): void
    {
        $redis = m::mock('Core\Redis'); $redis->shouldIgnoreMissing();
        $db = m::mock('Core\Database'); $db->shouldIgnoreMissing();
        $logger = m::mock('App\Contracts\LoggerInterface'); $logger->shouldIgnoreMissing();
        $model = m::mock('App\Models\SecurityModel');
        $policy = m::mock('App\Services\AntiFraud\RiskPolicyService'); $policy->shouldIgnoreMissing();
        $notif = m::mock('App\Services\Notification\NotificationService'); $notif->shouldIgnoreMissing();
        $lock = m::mock('App\Services\DistributedLockService'); $lock->shouldIgnoreMissing();

        // نشست متعلق به کاربر دیگر است (IDOR guard)
        $model->shouldReceive('findSessionById')->once()->with(99)->andReturn((object)['id' => 99, 'user_id' => 10, 'is_active' => 1]);

        $svc = new \App\Services\Auth\SessionService($redis, $db, $logger, $model, $policy, $lock);
        $result = $svc->terminateSession(99, 5);

        $this->assertFalse($result['success']);
        $this->assertIsString($result['message']);
        $this->assertStringContainsString('یافت نشد', $result['message']);
    }

    /** @test */
    public function terminate_session_rejects_when_already_inactive(): void
    {
        $redis = m::mock('Core\Redis'); $redis->shouldIgnoreMissing();
        $db = m::mock('Core\Database'); $db->shouldIgnoreMissing();
        $logger = m::mock('App\Contracts\LoggerInterface'); $logger->shouldIgnoreMissing();
        $model = m::mock('App\Models\SecurityModel');
        $policy = m::mock('App\Services\AntiFraud\RiskPolicyService'); $policy->shouldIgnoreMissing();
        $notif = m::mock('App\Services\Notification\NotificationService'); $notif->shouldIgnoreMissing();
        $lock = m::mock('App\Services\DistributedLockService'); $lock->shouldIgnoreMissing();

        $model->shouldReceive('findSessionById')->once()->with(7)->andReturn((object)['id' => 7, 'user_id' => 5, 'is_active' => 0, 'session_id' => 's1']);

        $svc = new \App\Services\Auth\SessionService($redis, $db, $logger, $model, $policy, $lock);
        $result = $svc->terminateSession(7, 5);

        $this->assertFalse($result['success']);
        $this->assertIsString($result['message']);
        $this->assertStringContainsString('از قبل غیرفعال', $result['message']);
    }

    /** @test */
    public function terminate_session_deactivates_and_clears_redis(): void
    {
        $redis = m::mock('Core\Redis');
        $db = m::mock('Core\Database'); $db->shouldIgnoreMissing();
        $logger = m::mock('App\Contracts\LoggerInterface'); $logger->shouldIgnoreMissing();
        $model = m::mock('App\Models\SecurityModel');
        $policy = m::mock('App\Services\AntiFraud\RiskPolicyService'); $policy->shouldIgnoreMissing();
        $notif = m::mock('App\Services\Notification\NotificationService'); $notif->shouldIgnoreMissing();
        $lock = m::mock('App\Services\DistributedLockService'); $lock->shouldIgnoreMissing();

        $model->shouldReceive('findSessionById')->once()->with(7)->andReturn((object)['id' => 7, 'user_id' => 5, 'is_active' => 1, 'session_id' => 's1']);
        $model->shouldReceive('deactivateSession')->once()->with(7)->andReturn(true);
        $redis->shouldReceive('isAvailable')->once()->andReturn(true);
        $redis->shouldReceive('delete')->once()->with('session:activity:s1');

        $svc = new \App\Services\Auth\SessionService($redis, $db, $logger, $model, $policy, $lock);
        $result = $svc->terminateSession(7, 5);

        $this->assertTrue($result['success']);
    }

    /** @test */
    public function terminate_session_revokes_persisted_payload_by_session_id(): void
    {
        $redis = m::mock('Core\\Redis');
        $db = m::mock('Core\\Database'); $db->shouldIgnoreMissing();
        $logger = m::mock('App\\Contracts\\LoggerInterface'); $logger->shouldIgnoreMissing();
        $model = m::mock('App\\Models\\SecurityModel');
        $policy = m::mock('App\\Services\\AntiFraud\\RiskPolicyService'); $policy->shouldIgnoreMissing();
        $notif = m::mock('App\\Services\\Notification\\NotificationService'); $notif->shouldIgnoreMissing();
        $lock = m::mock('App\\Services\\DistributedLockService'); $lock->shouldIgnoreMissing();

        $sessionId = 'phpunitterm' . bin2hex(random_bytes(4));
        $sessionsDir = dirname(__DIR__, 3) . '/storage/sessions';
        if (!is_dir($sessionsDir)) {
            mkdir($sessionsDir, 0700, true);
        }
        $path = $sessionsDir . '/sess_' . $sessionId;
        file_put_contents($path, 'user_id|i:5;');

        $model->shouldReceive('findSessionById')->once()->with(7)->andReturn((object)[
            'id' => 7,
            'user_id' => 5,
            'is_active' => 1,
            'session_id' => $sessionId,
        ]);
        $model->shouldReceive('deactivateSession')->once()->with(7)->andReturn(true);
        $redis->shouldReceive('isAvailable')->once()->andReturn(true);
        $redis->shouldReceive('delete')->once()->with('session:activity:' . $sessionId);
        $redis->shouldReceive('delete')->once()->with('CHORTKE_SESSION:' . $sessionId);

        $svc = new \App\Services\Auth\SessionService($redis, $db, $logger, $model, $policy, $lock);
        $result = $svc->terminateSession(7, 5);

        $this->assertTrue($result['success']);
        $this->assertFalse(is_file($path));
    }
}
