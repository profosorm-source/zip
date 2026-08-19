<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use App\Middleware\AuthMiddleware;
use App\Middleware\ApiAuthMiddleware;
use App\Middleware\CaptchaMiddleware;
use Mockery as m;

class MiddlewareTest extends TestCase
{
    protected function tearDown(): void
    {
        m::close();
        parent::tearDown();
    }

    /** @test */
    public function auth_middleware_is_instantiable(): void
    {
        $session = m::mock('Core\Session');
        $redis = m::mock('Core\Redis');
        $settings = m::mock('App\Services\Settings\AppSettings');
        $userModel = m::mock('App\Models\User');
        $logger = m::mock('App\Contracts\LoggerInterface');
        $userSettings = m::mock('App\Services\User\UserSettingsService');

        $middleware = new AuthMiddleware($session, $redis, $settings, $userModel, $logger, $userSettings);
        $this->assertInstanceOf(AuthMiddleware::class, $middleware);
    }

    /** @test */
    public function api_auth_middleware_is_instantiable(): void
    {
        $db = m::mock('Core\Database');
        $limiter = m::mock('Core\RateLimiter');

        $logger = m::mock('App\Contracts\LoggerInterface');
        $middleware = new ApiAuthMiddleware($db, $limiter, $logger);
        $this->assertInstanceOf(ApiAuthMiddleware::class, $middleware);
    }

    /** @test */
    public function captcha_middleware_is_instantiable(): void
    {
        $service = m::mock('App\Services\CaptchaService');
        $loginRisk = m::mock('App\Services\Auth\LoginRiskService');

        $middleware = new CaptchaMiddleware($service, $loginRisk);
        $this->assertInstanceOf(CaptchaMiddleware::class, $middleware);
    }
}
