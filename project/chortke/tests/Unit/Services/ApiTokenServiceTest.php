<?php

namespace Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use App\Services\ApiTokenService;
use Mockery as m;

class ApiTokenServiceTest extends TestCase
{
    /** @var \App\Contracts\LoggerInterface&\Mockery\MockInterface */
    private \App\Contracts\LoggerInterface $logger;
    /** @var \App\Models\ApiToken&\Mockery\MockInterface */
    private \App\Models\ApiToken $apiTokenModel;
    /** @var \App\Models\User&\Mockery\MockInterface */
    private \App\Models\User $userModel;
    /** @var \Core\RateLimiter&\Mockery\MockInterface */
    private \Core\RateLimiter $rateLimiter;
    /** @var \App\Services\Auth\TwoFactorService&\Mockery\MockInterface */
    private \App\Services\Auth\TwoFactorService $twoFactorService;
    private ApiTokenService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->logger = m::mock('App\Contracts\LoggerInterface');
        $this->apiTokenModel = m::mock('App\Models\ApiToken');
        $this->userModel = m::mock('App\Models\User');
        $this->rateLimiter = m::mock('Core\RateLimiter');
        $this->twoFactorService = m::mock('App\Services\Auth\TwoFactorService');

        $this->logger->shouldIgnoreMissing();

        $this->service = new ApiTokenService(
            $this->logger,
            $this->apiTokenModel,
            $this->userModel,
            $this->rateLimiter,
            $this->twoFactorService
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
        $this->assertInstanceOf(ApiTokenService::class, $this->service);
    }

    /** @test */
    public function get_tokens_for_admin_paginates_and_returns_correct_stats(): void
    {
        $tokensMock = [
            (object)['id' => 1, 'name' => 'Test Token', 'scope' => 'read']
        ];
        
        $this->apiTokenModel->shouldReceive('findAllPaginated')
            ->with(30, 0, null, null)
            ->once()
            ->andReturn($tokensMock);

        $this->apiTokenModel->shouldReceive('countAll')
            ->with(null, null)
            ->once()
            ->andReturn(1);

        $this->apiTokenModel->shouldReceive('getStats')
            ->once()
            ->andReturn(['active' => 1]);

        $result = $this->service->getTokensForAdmin();

        $tokens = $result['tokens'] ?? null;
        $this->assertIsArray($tokens);
        $this->assertCount(1, $tokens);
        $this->assertEquals(1, $result['total']);
        $this->assertEquals(1, $result['totalPages']);
    }

    /** @test */
    public function revoke_token_by_id_calls_model_properly(): void
    {
        $tokenId = 5;
        $this->apiTokenModel->shouldReceive('revokeById')
            ->with($tokenId)
            ->once()
            ->andReturn(true);

        $result = $this->service->revokeToken($tokenId);
        $this->assertTrue($result);
    }

    /** @test */
    public function list_tokens_for_user_returns_user_tokens(): void
    {
        $userId = 12;
        $userTokens = [
            (object)['id' => 1, 'user_id' => $userId, 'name' => 'My Token']
        ];

        $this->apiTokenModel->shouldReceive('findByUserId')
            ->with($userId)
            ->once()
            ->andReturn($userTokens);

        $result = $this->service->listTokensForUser($userId);
        $this->assertSame($userTokens, $result);
    }

    /** @test */
    public function create_token_for_user_prevents_unprivileged_scopes_for_non_admins(): void
    {
        $userId = 12;
        $name = 'Production Token';

        // Non-admin user mockup
        $userMock = (object)[
            'id' => $userId,
            'role' => 'user'
        ];

        // The service calls findById once for privileged-scope check and returns early
        $this->userModel->shouldReceive('findById')
            ->with($userId)
            ->once()
            ->andReturn($userMock);

        // Active tokens limit check (under 10)
        $this->apiTokenModel->shouldReceive('countActiveByUserId')
            ->with($userId)
            ->once()
            ->andReturn(3);

        // Non-admin users are now blocked from requesting privileged scopes entirely
        $result = $this->service->createTokenForUser($userId, $name, 30, 'admin');

        $this->assertFalse($result['success']);
        $this->assertEquals('ADMIN_SCOPE_FORBIDDEN', $result['code']);
    }

    /** @test */
    public function create_token_for_user_allows_privileged_scopes_for_admins(): void
    {
        $userId = 12;
        $name = 'Admin Token';
        
        // Admin user mockup
        $userMock = (object)[
            'id' => $userId,
            'role' => 'admin'
        ];

        // The service calls findById twice: once for privileged-scope check and once inside validateAndFilterScopes
        $this->userModel->shouldReceive('findById')
            ->with($userId)
            ->twice()
            ->andReturn($userMock);

        $this->apiTokenModel->shouldReceive('countActiveByUserId')
            ->with($userId)
            ->once()
            ->andReturn(1);

        // Admin should be allowed to have 'admin' scope
        $this->apiTokenModel->shouldReceive('createToken')
            ->with($userId, m::type('string'), $name, 'admin', m::any(), m::any())
            ->once();

        $result = $this->service->createTokenForUser($userId, $name, 30, 'admin');

        $this->assertTrue($result['success']);
        $payload = $result['payload'] ?? null;
        $this->assertIsArray($payload);
        $this->assertEquals('admin', $payload['scopes']);
    }

    /** @test */
    public function create_token_fails_if_active_count_exceeded(): void
    {
        $userId = 12;

        $this->apiTokenModel->shouldReceive('countActiveByUserId')
            ->with($userId)
            ->once()
            ->andReturn(10); // Exceeded

        $result = $this->service->createTokenForUser($userId, 'Too many', 30);

        $this->assertFalse($result['success']);
        $this->assertEquals('TOKEN_LIMIT_REACHED', $result['code']);
    }

    /** @test */
    public function issue_token_fails_if_email_or_password_incorrect(): void
    {
        $email = 'user@example.com';
        $password = 'wrong_password';

        // The service calls RateLimiter::attempt twice (IP-level and identifier-level)
        $this->rateLimiter->shouldReceive('attempt')
            ->andReturn(true);

        $this->userModel->shouldReceive('findByEmail')
            ->with($email)
            ->once()
            ->andReturn(null); // User not found

        $result = $this->service->issueToken($email, $password, 'Token Name', 'read');

        $this->assertFalse($result['success']);
        $this->assertEquals('INVALID_CREDENTIALS', $result['code']);
    }
}
