<?php

declare(strict_types=1);

namespace Tests\Integration\ContainerBacked\Services;

use PHPUnit\Framework\TestCase;
use Mockery as m;
use App\Services\Auth\AuthService;

/**
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class AuthServiceTest extends TestCase
{
    /** @var \Core\EventDispatcher&\Mockery\MockInterface */
    private \Core\EventDispatcher $eventDispatcher;
    /** @var \Core\Database&\Mockery\MockInterface */
    private \Core\Database $db;
    /** @var \App\Contracts\LoggerInterface&\Mockery\MockInterface */
    private \App\Contracts\LoggerInterface $logger;
    /** @var \App\Services\User\UserService&\Mockery\MockInterface */
    private \App\Services\User\UserService $userService;
    /** @var \App\Models\User&\Mockery\MockInterface */
    private \App\Models\User $userModel;
    /** @var \Core\RateLimiter&\Mockery\MockInterface */
    private \Core\RateLimiter $rateLimiter;
    /** @var \App\Services\Auth\AuthSessionManager&\Mockery\MockInterface */
    private \App\Services\Auth\AuthSessionManager $sessionManager;
    /** @var \App\Services\Auth\PasswordRecoveryService&\Mockery\MockInterface */
    private \App\Services\Auth\PasswordRecoveryService $passwordService;
    /** @var \App\Contracts\OutboxServiceInterface&\Mockery\MockInterface */
    private \App\Contracts\OutboxServiceInterface $outbox;
    private AuthService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->eventDispatcher = m::mock('Core\\EventDispatcher');
        $this->db = m::mock('Core\\Database');
        $this->logger = m::mock('App\\Contracts\\LoggerInterface');
        $this->userService = m::mock('App\\Services\\User\\UserService');
        $this->userModel = m::mock('App\\Models\\User');
        $this->rateLimiter = m::mock('Core\\RateLimiter');
        $this->sessionManager = m::mock('App\\Services\\Auth\\AuthSessionManager');
        $this->passwordService = m::mock('App\\Services\\Auth\\PasswordRecoveryService');
        $this->outbox = m::mock('App\\Contracts\\OutboxServiceInterface');

        $this->eventDispatcher->shouldIgnoreMissing();
        $this->logger->shouldIgnoreMissing();
        $this->db->shouldIgnoreMissing();

        // Register mock DB in Container so Core\Validator can resolve it
        // without attempting a real database connection
        \Core\Container::getInstance()->instance(\Core\Database::class, $this->db);

        $verify2FAJob = m::mock('App\\Jobs\\Auth\\Verify2FAJob');
        $processRegistrationJob = m::mock('App\\Jobs\\Auth\\ProcessRegistrationJob');
        $resetPasswordJob = m::mock('App\\Jobs\\Auth\\ResetPasswordJob');

        $this->service = new AuthService(
            $this->db,
            $this->logger,
            $this->userService,
            $this->userModel,
            $this->rateLimiter,
            $this->sessionManager,
            $this->passwordService,
            $verify2FAJob,
            $processRegistrationJob,
            $resetPasswordJob,
            $this->outbox
        );
    }

    protected function tearDown(): void
    {
        // Clean up mock DB from container
        m::close();
        parent::tearDown();
    }

    // ─── Login Success ──────────────────────────────────────────

    /** @test */
    public function login_succeeds_with_correct_credentials(): void
    {
        $user = (object)[
            'id' => 1,
            'email' => 'test@test.com',
            'password' => password_hash('correct123', PASSWORD_DEFAULT),
            'status' => 'active',
            'role' => 'user',
            'two_factor_enabled' => false,
            'email_verified_at' => '2024-01-01',
        ];

        $this->rateLimiter->shouldReceive('attempt')->andReturn(true);
        $this->userModel->shouldReceive('findByCredentialsForUpdate')->andReturn($user);
        $this->passwordService->shouldReceive('verifyPassword')->andReturn(true);
        $this->rateLimiter->shouldReceive('clear')->twice();
        $this->sessionManager->shouldReceive('createSession')->once();

        // outbox باید auth.login record کنه
        $this->outbox->shouldReceive('record')
            ->with('auth', 1, 'auth.login', m::type('array'))
            ->once()
            ->andReturn(true);

        $result = $this->service->login('test@test.com', 'correct123');

        $this->assertTrue($result['success']);
        $this->assertEquals('خوش آمدید.', $result['message']);
        $user = $result['user'] ?? null;
        $this->assertInstanceOf(\stdClass::class, $user);
        $this->assertEquals(1, $user->id);
        $this->assertFalse($result['requires_2fa']);
    }

    // ─── Login Failed — Wrong Password ──────────────────────────

    /** @test */
    public function login_fails_with_wrong_password(): void
    {
        $user = (object)[
            'id' => 1,
            'email' => 'test@test.com',
            'password' => password_hash('correct123', PASSWORD_DEFAULT),
            'status' => 'active',
            'role' => 'user',
            'email_verified_at' => '2024-01-01',
        ];

        $this->rateLimiter->shouldReceive('attempt')->andReturn(true);
        $this->userModel->shouldReceive('findByCredentialsForUpdate')->andReturn($user);
        $this->passwordService->shouldReceive('verifyPassword')->andReturn(false);

        // Cache mock for login attempts
        $cacheMock = m::mock();
        $cacheMock->shouldReceive('increment')->andReturn(1);
        $cacheMock->shouldReceive('forget')->andReturn(true);

        $cacheRef = new \ReflectionMethod('Core\\Cache', 'getInstance');

        // outbox نباید صدا زده بشه
        $this->outbox->shouldNotReceive('record');

        $result = $this->service->login('test@test.com', 'wrong_password');

        $this->assertFalse($result['success']);
        $this->assertIsString($result['message']);
        $this->assertStringContainsString('اشتباه', $result['message']);
    }

    // ─── Login Failed — User Not Found ──────────────────────────

    /** @test */
    public function login_fails_when_user_not_found(): void
    {
        $this->rateLimiter->shouldReceive('attempt')->andReturn(true);
        $this->userModel->shouldReceive('findByCredentialsForUpdate')->andReturn(null);
        $this->passwordService->shouldReceive('getDummyHash')->andReturn('$2y$10$dummy');
        $this->passwordService->shouldReceive('verifyPassword')->andReturn(false);

        $this->outbox->shouldNotReceive('record');

        $result = $this->service->login('nonexist@test.com', 'any_password');

        $this->assertFalse($result['success']);
    }

    // ─── Login Failed — Rate Limited ────────────────────────────

    /** @test */
    public function login_fails_when_rate_limited(): void
    {
        $this->rateLimiter->shouldReceive('attempt')->andReturn(false);

        $this->outbox->shouldNotReceive('record');

        $result = $this->service->login('test@test.com', 'password');

        $this->assertFalse($result['success']);
        $this->assertIsString($result['message']);
        $this->assertStringContainsString('بیش از حد', $result['message']);
    }

    // ─── Login — Locked Account ─────────────────────────────────

    /** @test */
    public function login_fails_when_account_locked(): void
    {
        $user = (object)[
            'id' => 1,
            'email' => 'test@test.com',
            'password' => 'hashed',
            'status' => 'locked',
            'role' => 'user',
            'email_verified_at' => '2024-01-01',
        ];

        $this->rateLimiter->shouldReceive('attempt')->andReturn(true);
        $this->userModel->shouldReceive('findByCredentialsForUpdate')->andReturn($user);
        $this->passwordService->shouldReceive('verifyPassword')->andReturn(true);
        $this->passwordService->shouldReceive('getDummyHash')->andReturn('$2y$10$dummy');

        $this->outbox->shouldNotReceive('record');

        $result = $this->service->login('test@test.com', 'password');

        $this->assertFalse($result['success']);
        $this->assertIsString($result['message']);
        $this->assertStringContainsString('قفل', $result['message']);
    }

    // ─── Login — Banned Account ─────────────────────────────────

    /** @test */
    public function login_fails_when_account_banned(): void
    {
        $user = (object)[
            'id' => 2,
            'email' => 'banned@test.com',
            'password' => 'hashed',
            'status' => 'banned',
            'role' => 'user',
            'email_verified_at' => '2024-01-01',
        ];

        $this->rateLimiter->shouldReceive('attempt')->andReturn(true);
        $this->userModel->shouldReceive('findByCredentialsForUpdate')->andReturn($user);
        $this->passwordService->shouldReceive('verifyPassword')->andReturn(true);
        $this->passwordService->shouldReceive('getDummyHash')->andReturn('$2y$10$dummy');

        $result = $this->service->login('banned@test.com', 'password');

        $this->assertFalse($result['success']);
        $this->assertIsString($result['message']);
        $this->assertStringContainsString('غیرفعال', $result['message']);
    }

    // ─── Login — Email Not Verified ─────────────────────────────

    /** @test */
    public function login_fails_when_email_not_verified(): void
    {
        $user = (object)[
            'id' => 3,
            'email' => 'unverified@test.com',
            'password' => 'hashed',
            'status' => 'active',
            'role' => 'user',
            'email_verified_at' => null,
        ];

        $this->rateLimiter->shouldReceive('attempt')->andReturn(true);
        $this->userModel->shouldReceive('findByCredentialsForUpdate')->andReturn($user);
        $this->passwordService->shouldReceive('verifyPassword')->andReturn(true);
        $this->passwordService->shouldReceive('getDummyHash')->andReturn('$2y$10$dummy');

        $result = $this->service->login('unverified@test.com', 'password');

        $this->assertFalse($result['success']);
        $this->assertTrue($result['email_unverified'] ?? false);
    }

    // ─── Login — 2FA Required ───────────────────────────────────

    /** @test */
    public function login_returns_2fa_required_when_enabled(): void
    {
        $user = (object)[
            'id' => 4,
            'email' => '2fa@test.com',
            'password' => password_hash('pass123', PASSWORD_DEFAULT),
            'status' => 'active',
            'role' => 'user',
            'two_factor_enabled' => true,
            'email_verified_at' => '2024-01-01',
        ];

        $this->rateLimiter->shouldReceive('attempt')->andReturn(true);
        $this->userModel->shouldReceive('findByCredentialsForUpdate')->andReturn($user);
        $this->passwordService->shouldReceive('verifyPassword')->andReturn(true);
        $this->sessionManager->shouldReceive('createPending2FASession')->once();

        $this->outbox->shouldReceive('record')->once()->andReturn(true);

        $result = $this->service->login('2fa@test.com', 'pass123');

        $this->assertTrue($result['success']);
        $this->assertTrue($result['requires_2fa']);
    }

    // ─── Login — Admin access denied for normal user ────────────

    /** @test */
    public function admin_login_fails_for_normal_user(): void
    {
        $user = (object)[
            'id' => 5,
            'email' => 'user@test.com',
            'password' => 'hashed',
            'status' => 'active',
            'role' => 'user',
            'email_verified_at' => '2024-01-01',
        ];

        $this->rateLimiter->shouldReceive('attempt')->andReturn(true);
        // After fix: admin check is inside transaction, so findByCredentialsForUpdate is called (not findByCredentials)
        $this->userModel->shouldReceive('findByCredentialsForUpdate')->andReturn($user);
        $this->passwordService->shouldReceive('verifyPassword')->andReturn(true);
        $this->passwordService->shouldReceive('getDummyHash')->andReturn('$2y$10$dummy');

        $result = $this->service->loginAsAdmin('user@test.com', 'password');

        $this->assertFalse($result['success']);
    }

    // ─── Outbox event contains correct data ─────────────────────

    /** @test */
    public function login_outbox_event_contains_user_id_and_ip(): void
    {
        $user = (object)[
            'id' => 10,
            'email' => 'outbox@test.com',
            'password' => password_hash('pass', PASSWORD_DEFAULT),
            'status' => 'active',
            'role' => 'user',
            'two_factor_enabled' => false,
            'email_verified_at' => '2024-01-01',
        ];

        $this->rateLimiter->shouldReceive('attempt')->andReturn(true);
        $this->userModel->shouldReceive('findByCredentialsForUpdate')->andReturn($user);
        $this->passwordService->shouldReceive('verifyPassword')->andReturn(true);
        $this->rateLimiter->shouldReceive('clear');
        $this->sessionManager->shouldReceive('createSession');

        $capturedPayload = null;
        $this->outbox->shouldReceive('record')
            ->with('auth', 10, 'auth.login', m::on(function($payload) use (&$capturedPayload) {
                $capturedPayload = $payload;
                return true;
            }))
            ->once()
            ->andReturn(true);

        $this->service->login('outbox@test.com', 'pass');

        $this->assertArrayHasKey('user_id', $capturedPayload);
        $this->assertEquals(10, $capturedPayload['user_id']);
        $this->assertArrayHasKey('ip', $capturedPayload);
        $this->assertArrayHasKey('user_agent', $capturedPayload);
    }

    // ─── Validate Register ──────────────────────────────────────

    /** @test */
    public function validate_register_returns_errors_for_duplicate_email(): void
    {
        $this->userService->shouldReceive('emailExists')->with('dup@test.com')->andReturn(true);

        $data = [
            'email' => 'dup@test.com',
            'password' => 'StrongPass123!',
            'password_confirmation' => 'StrongPass123!',
            'full_name' => 'Test User',
            'username' => 'testuser',
        ];

        $errors = $this->service->validateRegister($data);

        // باید حداقل خطای ایمیل تکراری داشته باشه
        $this->assertNotEmpty($errors);
        $found = false;
        foreach ($errors as $err) {
            if (str_contains($err, 'ایمیل')) {
                $found = true;
                break;
            }
        }
        $this->assertTrue($found, 'Expected duplicate email error');
    }
}
