<?php

namespace Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use App\Services\Auth\TwoFactorService;
use Mockery as m;

class TwoFactorServiceTest extends TestCase
{
    /** @var \Core\Database&\Mockery\MockInterface */
    private \Core\Database $db;
    /** @var \App\Contracts\LoggerInterface&\Mockery\MockInterface */
    private \App\Contracts\LoggerInterface $logger;
    /** @var \App\Models\User&\Mockery\MockInterface */
    private \App\Models\User $userModel;
    /** @var \App\Models\SecurityModel&\Mockery\MockInterface */
    private \App\Models\SecurityModel $securityModel;
    /** @var \Core\Session&\Mockery\MockInterface */
    private \Core\Session $session;
    /** @var \App\Services\Notification\NotificationService&\Mockery\MockInterface */
    private \App\Services\Notification\NotificationService $notificationService;
    /** @var \App\Services\AuditTrail&\Mockery\MockInterface */
    private \App\Services\AuditTrail $auditTrail;
    /** @var \App\Services\DistributedLockService&\Mockery\MockInterface */
    private \App\Services\DistributedLockService $lockService;
    /** @var \Core\RateLimiter&\Mockery\MockInterface */
    private \Core\RateLimiter $rateLimiter;
    private TwoFactorService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->db = m::mock('Core\Database');
        $this->logger = m::mock('App\Contracts\LoggerInterface');
        $this->userModel = m::mock('App\Models\User');
        $this->securityModel = m::mock('App\Models\SecurityModel');
        $this->session = m::mock('Core\Session');
        $this->notificationService = m::mock('App\Services\Notification\NotificationService');
        $this->auditTrail = m::mock('App\Services\AuditTrail');
        $this->lockService = m::mock('App\Services\DistributedLockService');
        $this->rateLimiter = m::mock('Core\RateLimiter');

        $this->logger->shouldIgnoreMissing();
        $this->auditTrail->shouldIgnoreMissing();

        $this->service = new TwoFactorService(
            $this->db,
            $this->logger,
            $this->userModel,
            $this->securityModel,
            $this->session,
            $this->notificationService,
            $this->auditTrail,
            $this->lockService,
            $this->rateLimiter
        );
    }

    protected function tearDown(): void
    {
        m::close();
        parent::tearDown();
    }

    /** @test */
    public function service_can_be_instantiated(): void
    {
        $this->assertInstanceOf(TwoFactorService::class, $this->service);
    }

    /** @test */
    public function generate_secret_returns_valid_base32_string(): void
    {
        $secret = $this->service->generateSecret();
        $this->assertEquals(32, strlen($secret));
        $this->assertRegExp('/^[A-Z2-7]+$/', $secret);
    }

    /** @test */
    public function verify_totp_code_succeeds_with_correct_code(): void
    {
        // Use a static 32-char Base32 secret (plaintext fallback supported by decryptSecret)
        $secret = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        
        // Generate valid TOTP code for the current timeslice using reflection
        $ref = new \ReflectionClass(TwoFactorService::class);
        $method = $ref->getMethod('generateTOTP');
        $method->setAccessible(true);
        
        $timeSlice = (int)floor(time() / 30);
        $validCode = $method->invokeArgs($this->service, [$secret, $timeSlice]);
        $this->assertIsString($validCode);

        $this->userModel->shouldReceive('find')->with(1)->andReturn((object)['id' => 1, 'last_2fa_timeslice' => null]);
        
        // Mock timeslice update to prevent replay attacks
        $this->userModel->shouldReceive('update2FATimeslice')->with(1, $timeSlice)->once()->andReturn(true);

        $this->assertTrue($this->service->verifyTOTPCode($secret, $validCode, 1));
    }

    /** @test */
    public function verify_totp_code_fails_with_incorrect_code(): void
    {
        $secret = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $invalidCode = '999999';

        $this->userModel->shouldReceive('find')->with(1)->andReturn((object)['id' => 1, 'last_2fa_timeslice' => null]);

        $this->assertFalse($this->service->verifyTOTPCode($secret, $invalidCode, 1));
    }
}
