<?php

namespace Tests\Unit\Middleware;

use PHPUnit\Framework\TestCase;
use App\Middleware\AdvancedFraudMiddleware;
use Core\Request;
use Core\Response;
use Mockery as m;

class AdvancedFraudMiddlewareTest extends TestCase
{
    /** @var \App\Services\AntiFraud\BrowserFingerprintService&\Mockery\MockInterface */
    private \App\Services\AntiFraud\BrowserFingerprintService $fingerprintService;
    /** @var \App\Services\AntiFraud\GeoIPService&\Mockery\MockInterface */
    private \App\Services\AntiFraud\GeoIPService $ipQualityService;
    /** @var \App\Services\Auth\SessionService&\Mockery\MockInterface */
    private \App\Services\Auth\SessionService $sessionService;
    /** @var \App\Services\AntiFraud\AccountTakeoverService&\Mockery\MockInterface */
    private \App\Services\AntiFraud\AccountTakeoverService $accountTakeoverService;
    /** @var \App\Services\ScoreService&\Mockery\MockInterface */
    private \App\Services\ScoreService $scoreService;
    /** @var \App\Services\AntiFraud\RiskDecisionService&\Mockery\MockInterface */
    private \App\Services\AntiFraud\RiskDecisionService $decisionService;
    /** @var \App\Contracts\LoggerInterface&\Mockery\MockInterface */
    private \App\Contracts\LoggerInterface $logger;
    /** @var \Core\Session&\Mockery\MockInterface */
    private \Core\Session $session;
    private AdvancedFraudMiddleware $middleware;
    /** @var \App\Contracts\NotificationServiceInterface&\Mockery\MockInterface */
    private \App\Contracts\NotificationServiceInterface $notifications;

    private function mockRequest(): \Core\Request
    {
        $request = m::mock(Request::class);
        $request->shouldIgnoreMissing();
        $request->shouldReceive('uri')->andReturn('/test');
        $request->shouldReceive('header')->with('accept-language')->andReturnUsing(fn() => $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? null);
        $request->shouldReceive('header')->with('accept-encoding')->andReturnUsing(fn() => $_SERVER['HTTP_ACCEPT_ENCODING'] ?? null);
        return $request;
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->fingerprintService = m::mock('App\Services\AntiFraud\BrowserFingerprintService');
        $this->ipQualityService = m::mock('App\Services\AntiFraud\GeoIPService');
        $this->sessionService = m::mock('App\Services\Auth\SessionService');
        $this->accountTakeoverService = m::mock('App\Services\AntiFraud\AccountTakeoverService');
        $this->scoreService = m::mock('App\Services\ScoreService');
        $this->decisionService = m::mock('App\Services\AntiFraud\RiskDecisionService');
        $this->logger = m::mock('App\Contracts\LoggerInterface');
        $this->session = m::mock('Core\Session');
        $this->notifications = m::mock('App\Contracts\NotificationServiceInterface');

        $this->logger->shouldIgnoreMissing();
        $this->notifications->shouldIgnoreMissing();

        $this->middleware = new AdvancedFraudMiddleware(
            $this->fingerprintService,
            $this->ipQualityService,
            $this->sessionService,
            $this->accountTakeoverService,
            $this->scoreService,
            $this->decisionService,
            $this->logger,
            $this->session,
            $this->notifications
        );
    }

    protected function tearDown(): void
    {
        m::close();
        parent::tearDown();
    }

    /** @test */
    public function guests_bypass_the_middleware(): void
    {
        $this->session->shouldReceive('has')->with('user_id')->once()->andReturn(false);

        $request = $this->mockRequest();
        $response = new Response();
        $next = function ($req) use ($response) {
            return $response;
        };

        $result = $this->middleware->handle($request, $next);
        $this->assertSame($response, $result);
    }

    /** @test */
    public function authenticated_user_with_clean_record_is_allowed(): void
    {
        $userId = 42;
        $sessionId = 'sess_xyz';
        $ip = '1.1.1.1';
        $userAgent = 'Mozilla/5.0';
        $acceptLanguage = 'fa-IR';
        $acceptEncoding = 'gzip, deflate';
        $geoData = ['country_code' => 'IR', 'city' => 'Tehran'];

        // Mock Server Globals
        $_SERVER['HTTP_ACCEPT_LANGUAGE'] = $acceptLanguage;
        $_SERVER['HTTP_ACCEPT_ENCODING'] = $acceptEncoding;
        $_SERVER['HTTP_USER_AGENT'] = $userAgent;
        $_SERVER['REMOTE_ADDR'] = $ip;

        // Mock Session calls
        $this->session->shouldReceive('has')->with('user_id')->andReturn(true);
        $this->session->shouldReceive('get')->with('user_id')->andReturn($userId);
        $this->session->shouldReceive('getId')->andReturn($sessionId);
        $this->session->shouldReceive('get')->with('fraud_check_done')->andReturn(false);
        $this->session->shouldReceive('set')->with('fraud_check_done', true)->once();
        $this->session->shouldReceive('remove')->with('under_manual_review')->once();

        // Service expectations
        $this->ipQualityService->shouldReceive('getGeolocation')->with($ip)->once()->andReturn($geoData);
        $this->sessionService->shouldReceive('updateActivity')->with($sessionId)->once();
        $this->sessionService->shouldReceive('recordSession')
            ->with($userId, $sessionId, $userAgent, $ip, $acceptLanguage, $acceptEncoding, $geoData)
            ->once();

        $this->ipQualityService->shouldReceive('isIPBlacklisted')->with($ip)->once()->andReturn(false);

        // IP Check
        $ipCheck = ['is_suspicious' => false, 'score' => 0];
        $this->ipQualityService->shouldReceive('check')->with($ip)->once()->andReturn($ipCheck);

        // Session Check
        $sessionCheck = ['is_anomaly' => false, 'score' => 0];
        $this->sessionService->shouldReceive('analyzeAnomaly')->with($userId, $sessionId)->once()->andReturn($sessionCheck);

        // Fingerprint Service
        $this->fingerprintService->shouldReceive('generate')->once()->andReturn('fingerprint_123');

        // Takeover Check
        $takeoverCheck = ['is_takeover' => false, 'risk_score' => 0];
        $this->accountTakeoverService->shouldReceive('detect')
            ->with($userId, $ip, $userAgent, 'fingerprint_123')
            ->once()
            ->andReturn($takeoverCheck);

        // Risk Decision Service
        $this->decisionService->shouldReceive('decide')
            ->with($userId, ['action' => 'general'])
            ->once()
            ->andReturn(['result' => 'allow']);

        $request = $this->mockRequest();
        $response = new Response();
        $next = function ($req) use ($response) {
            return $response;
        };

        $result = $this->middleware->handle($request, $next);
        $this->assertInstanceOf(Response::class, $result);
    }

    /** @test */
    public function blacklisted_ip_triggers_immediate_session_destroy_and_redirect(): void
    {
        $userId = 42;
        $sessionId = 'sess_xyz';
        $ip = '1.1.1.1';

        $_SERVER['REMOTE_ADDR'] = $ip;

        $this->session->shouldReceive('has')->with('user_id')->andReturn(true);
        $this->session->shouldReceive('get')->with('user_id')->andReturn($userId);
        $this->session->shouldReceive('getId')->andReturn($sessionId);
        $this->session->shouldReceive('get')->with('fraud_check_done')->andReturn(true);

        $this->ipQualityService->shouldReceive('getGeolocation')->with($ip)->once()->andReturn([]);
        $this->sessionService->shouldReceive('updateActivity')->with($sessionId)->once();

        // Mock Blacklist IP
        $this->ipQualityService->shouldReceive('isIPBlacklisted')->with($ip)->once()->andReturn(true);
        $this->session->shouldReceive('destroy')->once();

        $request = $this->mockRequest();
        $next = function ($req) {
            return new Response();
        };

        try {
            $result = $this->middleware->handle($request, $next);
        } catch (\Core\Exceptions\HttpResponseException $e) {
            $result = $e->getResponse();
        }

        $this->assertInstanceOf(Response::class, $result);
        $this->assertContains($result->getStatusCode(), [200, 301, 302], "Expected a redirect");
    }

    /** @test */
    public function tor_node_ip_causes_blacklist_and_tor_blocked_redirect(): void
    {
        $userId = 42;
        $sessionId = 'sess_xyz';
        $ip = '1.1.1.1';

        $_SERVER['REMOTE_ADDR'] = $ip;

        $this->session->shouldReceive('has')->with('user_id')->andReturn(true);
        $this->session->shouldReceive('get')->with('user_id')->andReturn($userId);
        $this->session->shouldReceive('getId')->andReturn($sessionId);
        $this->session->shouldReceive('get')->with('fraud_check_done')->andReturn(true);

        $this->ipQualityService->shouldReceive('getGeolocation')->with($ip)->once()->andReturn([]);
        $this->sessionService->shouldReceive('updateActivity')->with($sessionId)->once();

        $this->ipQualityService->shouldReceive('isIPBlacklisted')->with($ip)->once()->andReturn(false);

        // TOR IP Check Result
        $ipCheck = [
            'is_suspicious' => true,
            'score' => 90,
            'reasons' => ['استفاده از شبکه Tor'],
            'details' => ['is_tor' => true]
        ];
        $this->ipQualityService->shouldReceive('check')->with($ip)->once()->andReturn($ipCheck);
        $this->ipQualityService->shouldReceive('logIPCheck')->with($userId, $ip, $ipCheck)->once();
        
        // Delta points application
        $this->scoreService->shouldReceive('applyDelta')
            ->with('user', $userId, \App\Enums\ScoreDomain::Fraud->value, 22.5, 'ip_quality', m::type('array'))
            ->once();

        // Blacklist and destroy
        $this->ipQualityService->shouldReceive('blacklistIP')->with($ip, 'Tor Network', 86400 * 7)->once();
        $this->session->shouldReceive('destroy')->once();

        $request = $this->mockRequest();
        $next = function ($req) {
            return new Response();
        };

        try {
            $result = $this->middleware->handle($request, $next);
        } catch (\Core\Exceptions\HttpResponseException $e) {
            $result = $e->getResponse();
        }

        $this->assertInstanceOf(Response::class, $result);
        $this->assertContains($result->getStatusCode(), [200, 301, 302], "Expected a redirect");
    }

    /** @test */
    public function high_risk_decision_blocks_user(): void
    {
        $userId = 42;
        $sessionId = 'sess_xyz';
        $ip = '1.1.1.1';

        $_SERVER['REMOTE_ADDR'] = $ip;

        $this->notifications->shouldReceive('send')->once()->andReturn(1234);

        $this->session->shouldReceive('has')->with('user_id')->andReturn(true);
        $this->session->shouldReceive('get')->with('user_id')->andReturn($userId);
        $this->session->shouldReceive('getId')->andReturn($sessionId);
        $this->session->shouldReceive('get')->with('fraud_check_done')->andReturn(true);

        $this->ipQualityService->shouldReceive('getGeolocation')->with($ip)->once()->andReturn([]);
        $this->sessionService->shouldReceive('updateActivity')->with($sessionId)->once();
        $this->ipQualityService->shouldReceive('isIPBlacklisted')->with($ip)->once()->andReturn(false);

        $this->ipQualityService->shouldReceive('check')->andReturn(['is_suspicious' => false]);
        $this->sessionService->shouldReceive('analyzeAnomaly')->andReturn(['is_anomaly' => false]);
        $this->fingerprintService->shouldReceive('generate')->andReturn('fp');
        $this->accountTakeoverService->shouldReceive('detect')->andReturn(['is_takeover' => false]);

        // Mock Decision: block
        $this->decisionService->shouldReceive('decide')->andReturn(['result' => 'block']);
        $this->session->shouldReceive('destroy')->once();

        $request = $this->mockRequest();
        $next = function ($req) {
            return new Response();
        };

        try {
            $result = $this->middleware->handle($request, $next);
        } catch (\Core\Exceptions\HttpResponseException $e) {
            $result = $e->getResponse();
        }

        $this->assertInstanceOf(Response::class, $result);
        $this->assertContains($result->getStatusCode(), [200, 301, 302], "Expected a redirect");
    }

    /** @test */
    public function manual_review_decision_sets_manual_review_session_flag(): void
    {
        $userId = 42;
        $sessionId = 'sess_xyz';
        $ip = '1.1.1.1';

        $_SERVER['REMOTE_ADDR'] = $ip;

        $this->session->shouldReceive('has')->with('user_id')->andReturn(true);
        $this->session->shouldReceive('get')->with('user_id')->andReturn($userId);
        $this->session->shouldReceive('getId')->andReturn($sessionId);
        $this->session->shouldReceive('get')->with('fraud_check_done')->andReturn(true);

        $this->ipQualityService->shouldReceive('getGeolocation')->with($ip)->once()->andReturn([]);
        $this->sessionService->shouldReceive('updateActivity')->with($sessionId)->once();
        $this->ipQualityService->shouldReceive('isIPBlacklisted')->with($ip)->once()->andReturn(false);

        $this->ipQualityService->shouldReceive('check')->andReturn(['is_suspicious' => false]);
        $this->sessionService->shouldReceive('analyzeAnomaly')->andReturn(['is_anomaly' => false]);
        $this->fingerprintService->shouldReceive('generate')->andReturn('fp');
        $this->accountTakeoverService->shouldReceive('detect')->andReturn(['is_takeover' => false]);

        // Mock Decision: review
        $this->decisionService->shouldReceive('decide')->andReturn(['result' => 'review']);
        $this->session->shouldReceive('set')->with('under_manual_review', true)->once();

        $request = $this->mockRequest();
        $response = new Response();
        $next = function ($req) use ($response) {
            return $response;
        };

        $result = $this->middleware->handle($request, $next);
        $this->assertSame($response, $result);
    }
}
