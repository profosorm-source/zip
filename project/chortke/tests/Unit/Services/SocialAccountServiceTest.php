<?php

namespace Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use App\Services\SocialAccountService;
use Mockery as m;

class SocialAccountServiceTest extends TestCase
{
    /** @var \App\Contracts\LoggerInterface&\Mockery\MockInterface */
    private \App\Contracts\LoggerInterface $logger;
    /** @var \App\Models\SocialAccount&\Mockery\MockInterface */
    private \App\Models\SocialAccount $socialAccountModel;
    /** @var \App\Models\User&\Mockery\MockInterface */
    private \App\Models\User $userModel;
    /** @var \App\Models\Notification&\Mockery\MockInterface */
    private \App\Models\Notification $notificationModel;
    private SocialAccountService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->logger = m::mock('App\Contracts\LoggerInterface');
        $this->socialAccountModel = m::mock('App\Models\SocialAccount');
        $this->userModel = m::mock('App\Models\User');
        $this->notificationModel = m::mock('App\Models\Notification');
        
        $this->service = new SocialAccountService(
            $this->logger,
            $this->socialAccountModel,
            $this->userModel,
            $this->notificationModel
        );
    }

    protected function tearDown(): void
    {
        m::close();
    }

    /** @test */
    public function service_can_be_instantiated(): void
    {
        $this->assertInstanceOf(SocialAccountService::class, $this->service);
    }

    /** @test */
    public function service_has_required_methods(): void
    {
        $methods = ['getAllForAdmin', 'countForAdmin', 'findForAdmin', 'searchForAdmin'];
        
        foreach ($methods as $method) {
            $this->assertTrue(method_exists($this->service, $method), "Method {$method} not found");
        }
    }
}
