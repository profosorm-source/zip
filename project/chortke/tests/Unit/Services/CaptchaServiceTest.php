<?php

namespace Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use App\Services\CaptchaService;
use Mockery as m;

class CaptchaServiceTest extends TestCase
{
    /** @var \App\Contracts\LoggerInterface&\Mockery\MockInterface */
    private \App\Contracts\LoggerInterface $logger;
    /** @var \App\Models\CaptchaLog&\Mockery\MockInterface */
    private \App\Models\CaptchaLog $captchaLogModel;
    /** @var \App\Services\Settings\AppSettings&\Mockery\MockInterface */
    private \App\Services\Settings\AppSettings $appSettings;
    /** @var \Core\Session&\Mockery\MockInterface */
    private \Core\Session $session;
    private CaptchaService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->logger = m::mock('App\Contracts\LoggerInterface');
        $this->captchaLogModel = m::mock('App\Models\CaptchaLog');
        $this->appSettings = m::mock('App\Services\Settings\AppSettings');
        $this->session = m::mock('Core\Session');

        $this->logger->shouldIgnoreMissing();
        $this->appSettings->shouldReceive('get')->byDefault()->andReturn(true);

        $this->service = new CaptchaService(
            $this->logger,
            $this->captchaLogModel,
            $this->appSettings,
            $this->session,
            new \Core\PathResolver(dirname(__DIR__, 3))
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
        $this->assertInstanceOf(CaptchaService::class, $this->service);
    }

    /** @test */
    public function verify_returns_false_on_empty_token(): void
    {
        $result = $this->service->verify('', 'response');
        $this->assertFalse($result);
    }

    /** @test */
    public function verify_returns_false_if_no_session_data_found(): void
    {
        $token = 'test_token_123';
        $key = "captcha_{$token}";

        $this->session->shouldReceive('get')
            ->with($key)
            ->once()
            ->andReturn(null);

        $result = $this->service->verify($token, 'response');
        $this->assertFalse($result);
    }

    /** @test */
    public function verify_fails_on_expired_captcha(): void
    {
        $token = 'expired_token';
        $key = "captcha_{$token}";

        $captchaData = [
            'type' => 'math',
            'created_at' => time() - (10 * 60), // 10 minutes ago (expired)
            'attempts' => 0,
            'answer' => '5'
        ];

        $this->session->shouldReceive('get')
            ->with($key)
            ->once()
            ->andReturn($captchaData);

        // App expired minutes setting
        $this->appSettings->shouldReceive('get')
            ->with('captcha_expire_minutes', 5)
            ->once()
            ->andReturn(5);

        $this->session->shouldReceive('delete')->with($key)->once();

        $result = $this->service->verify($token, '5');
        $this->assertFalse($result);
    }

    /** @test */
    public function verify_fails_if_attempts_exceeded(): void
    {
        $token = 'blocked_token';
        $key = "captcha_{$token}";

        $captchaData = [
            'type' => 'math',
            'created_at' => time(),
            'attempts' => 3, // 3 attempts already (exceeded)
            'answer' => '5'
        ];

        $this->session->shouldReceive('get')
            ->with($key)
            ->once()
            ->andReturn($captchaData);

        $this->appSettings->shouldReceive('get')
            ->with('captcha_expire_minutes', 5)
            ->once()
            ->andReturn(5);

        $this->appSettings->shouldReceive('get')
            ->with('captcha_max_attempts', 3)
            ->once()
            ->andReturn(3);

        $this->session->shouldReceive('delete')->with($key)->once();

        $result = $this->service->verify($token, '5');
        $this->assertFalse($result);
    }

    /** @test */
    public function verify_challenge_success_with_math_captcha(): void
    {
        $token = 'valid_token';
        $key = "captcha_{$token}";

        $captchaData = [
            'type' => 'math',
            'created_at' => time(),
            'attempts' => 1,
            'answer' => '9',
            'challenge' => '4 + 5'
        ];

        $this->session->shouldReceive('get')
            ->with($key)
            ->once()
            ->andReturn($captchaData);

        $this->appSettings->shouldReceive('get')
            ->with('captcha_expire_minutes', 5)
            ->once()
            ->andReturn(5);

        $this->appSettings->shouldReceive('get')
            ->with('captcha_max_attempts', 3)
            ->once()
            ->andReturn(3);

        // Expect captchaLogModel to record attempt
        $this->captchaLogModel->shouldReceive('recordAttempt')->once();

        $this->session->shouldReceive('delete')->with($key)->once();

        $result = $this->service->verify($token, '9');
        $this->assertTrue($result);
    }

    /** @test */
    public function is_enabled_returns_correct_configured_state(): void
    {
        $this->appSettings->shouldReceive('get')
            ->with('captcha_enabled', true)
            ->andReturn(true);

        $this->assertTrue($this->service->isEnabled());
    }

    /** @test */
    public function get_captcha_type_by_risk_levels(): void
    {
        $this->assertEquals('math', $this->service->getCaptchaTypeByRisk(10));
        $this->assertEquals('behavioral', $this->service->getCaptchaTypeByRisk(45));
        $this->assertEquals('image', $this->service->getCaptchaTypeByRisk(65));
        $this->assertEquals('recaptcha_v2', $this->service->getCaptchaTypeByRisk(85));
    }
}
