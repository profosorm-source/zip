<?php

namespace Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use App\Services\Auth\GoogleJwtVerifier;
use App\Services\Auth\LoginRiskService;
use App\Services\Auth\OAuthService;
use App\Constants\SessionKeys;
use Mockery as m;

class ThirdPartyAuthTest extends TestCase
{
    protected function tearDown(): void
    {
        m::close();
        parent::tearDown();
    }

    /** @test */
    public function google_jwt_verifier_detects_malformed_tokens(): void
    {
        $cache = m::mock('Core\Cache');
        $logger = m::mock('App\Contracts\LoggerInterface');

        $verifier = new GoogleJwtVerifier($cache, $logger);

        // Malformed JWT with wrong count of segments
        $result = $verifier->verifyIdToken('invalid.token', 'expected_aud', ['iss1']);
        $this->assertFalse($result['success']);
        $this->assertEquals('Invalid ID Token format', $result['message']);
    }

    /** @test */
    public function login_risk_service_calculates_risk_scores_properly(): void
    {
        $redis = m::mock('Core\Redis');
        $logger = m::mock('App\Contracts\LoggerInterface');
        $cache = m::mock('Core\Cache');
        $appSettings = m::mock('App\Services\Settings\AppSettings');

        $logger->shouldIgnoreMissing();
        
        // Mock cache connectivity test to keep cache active
        $cache->shouldReceive('get')->with('connectivity_test')->andReturn(null);

        // Automatic fallback for any settings using our elegant return-default callback
        $appSettings->shouldReceive('get')->byDefault()->andReturnUsing(function($key, $default = null) {
            return $default;
        });

        // Create partial mock of LoginRiskService to stub getFailCount directly
        $service = m::mock(LoginRiskService::class, [$redis, $cache, $logger, $appSettings])->makePartial();
        
        // Mock getFailCount to return 3 failures (triggers FAIL_LIMIT_3 / score3)
        $service->shouldReceive('getFailCount')->andReturn(3);

        $score = $service->getRiskScore('login', '1.1.1.1', 'user@example.com');

        // Score should be score3 which has default value of 65
        $this->assertEquals(65, $score);
        $this->assertEquals('recaptcha_v2', $service->getCaptchaType('login', '1.1.1.1', 'user@example.com'));
    }

    /** @test */
    public function oauth_service_generates_correct_auth_urls_and_regenerates_sessions(): void
    {
        $db = m::mock('Core\Database');
        $session = m::mock('Core\Session');

        // Expect session regeneration for session fixation protection
        $session->shouldReceive('regenerate')->with(true)->once();
        $session->shouldReceive('getId')->once()->andReturn('session_id_123');
        
        // Expect state storage in session
        $session->shouldReceive('set')
            ->with(SessionKeys::OAUTH_STATE, m::type('array'))
            ->once();

        $config = [
            'google_client_id' => 'google_id_123',
            'google_redirect_uri' => 'https://mysite.com/auth/callback/google'
        ];

        $job1 = m::mock('App\\Jobs\\Auth\\HandleGoogleCallbackJob');
        $job2 = m::mock('App\\Jobs\\Auth\\HandleFacebookCallbackJob');
        $job3 = m::mock('App\\Jobs\\Auth\\LinkSocialAccountJob');
        $job4 = m::mock('App\\Jobs\\Auth\\LinkSocialAccountSafeJob');
        $job5 = m::mock('App\\Jobs\\Auth\\UnlinkSocialAccountJob');

        $service = new OAuthService(
            $db,
            $session,
            $job1,
            $job2,
            $job3,
            $job4,
            $job5,
            new \Core\UrlGenerator('http://127.0.0.1:8090', null, null, 'testing'),
            $config
        );

        $url = $service->getGoogleAuthUrl();

        $this->assertStringContainsString('https://accounts.google.com/o/oauth2/v2/auth', $url);
        $this->assertStringContainsString('client_id=google_id_123', $url);
        $this->assertStringContainsString('redirect_uri=https%3A%2F%2Fmysite.com%2Fauth%2Fcallback%2Fgoogle', $url);
    }
}
