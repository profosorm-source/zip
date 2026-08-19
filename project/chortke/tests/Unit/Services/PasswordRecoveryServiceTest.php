<?php

namespace Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use App\Services\Auth\PasswordRecoveryService;
use Mockery as m;

class PasswordRecoveryServiceTest extends TestCase
{
    /** @var \App\Models\SecurityModel&\Mockery\MockInterface */
    private \App\Models\SecurityModel $securityModel;
    /** @var \App\Models\User&\Mockery\MockInterface */
    private \App\Models\User $userModel;
    /** @var \App\Services\User\UserService&\Mockery\MockInterface */
    private \App\Services\User\UserService $userService;
    /** @var \Core\RateLimiter&\Mockery\MockInterface */
    private \Core\RateLimiter $rateLimiter;
    /** @var \App\Contracts\LoggerInterface&\Mockery\MockInterface */
    private \App\Contracts\LoggerInterface $logger;
    /** @var \Core\EventDispatcher&\Mockery\MockInterface */
    private \Core\EventDispatcher $eventDispatcher;
    private PasswordRecoveryService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->securityModel = m::mock('App\Models\SecurityModel');
        $this->userModel = m::mock('App\Models\User');
        $this->userService = m::mock('App\Services\User\UserService');
        $this->rateLimiter = m::mock('Core\RateLimiter');
        $this->logger = m::mock('App\Contracts\LoggerInterface');
        $this->eventDispatcher = m::mock('Core\EventDispatcher');

        $this->logger->shouldIgnoreMissing();
        $this->eventDispatcher->shouldIgnoreMissing();

        $this->service = new PasswordRecoveryService(
            $this->securityModel,
            $this->userModel,
            $this->userService,
            $this->rateLimiter,
            $this->logger,
            null
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
        $this->assertInstanceOf(PasswordRecoveryService::class, $this->service);
    }

    /** @test */
    public function verify_password_checks_correctly(): void
    {
        $password = 'secret123';
        
        // Hash for BCRYPT of base64(sha384(password))
        $inputPassword = base64_encode(hash('sha384', $password, true));
        $hash = password_hash($inputPassword, PASSWORD_BCRYPT);

        $this->assertTrue($this->service->verifyPassword($password, $hash));
        $this->assertFalse($this->service->verifyPassword('wrong_password', $hash));
    }

    /** @test */
    public function request_password_reset_succeeds_and_creates_token(): void
    {
        $email = 'user@example.com';
        
        // Rate limiter allows
        $this->rateLimiter->shouldReceive('attempt')->once()->andReturn(true);

        // User Mock
        $userMock = (object)['id' => 12, 'email' => $email];
        $this->userModel->shouldReceive('findByEmail')->with($email)->once()->andReturn($userMock);

        // Create reset token
        $this->securityModel->shouldReceive('createPasswordResetToken')
            ->with($email, m::type('string'))
            ->once();

        $result = $this->service->requestPasswordReset($email);

        $this->assertTrue($result['success']);
    }

    /** @test */
    public function validate_password_reset_token_returns_true_for_valid_tokens(): void
    {
        $token = 'valid_token_123';
        
        $this->securityModel->shouldReceive('findPasswordResetByToken')
            ->with($token, 3600)
            ->once()
            ->andReturn((object)['email' => 'user@example.com']);

        $this->assertTrue($this->service->validatePasswordResetToken($token));
    }
}
