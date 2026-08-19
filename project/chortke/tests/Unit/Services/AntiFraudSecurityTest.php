<?php

namespace Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use App\Services\AntiFraud\BrowserFingerprintService;
use App\Services\AntiFraud\GeolocationIntelligenceService;
use App\Services\AntiFraud\IPQualityService;
use App\Middleware\RateLimitMiddleware;
use App\Models\VelocityAndScoreModel;
use App\Services\AntiFraud\GraphAnalysisService;
use App\Models\FraudAnalyticsModel;
use App\Models\IpAndDeviceModel;
use App\Services\AntiFraud\BehavioralBiometricsService;
use App\Services\AntiFraud\FraudDashboardService;

class AntiFraudSecurityTest extends TestCase
{
    /** @test */
    public function test_browser_fingerprint_similarity_calculation(): void
    {
        $service = $this->getMockBuilder(BrowserFingerprintService::class)
            ->disableOriginalConstructor()
            ->getMock();

        $reflection = new \ReflectionClass(BrowserFingerprintService::class);
        $method = $reflection->getMethod('calculateFingerprintSimilarity');
        $method->setAccessible(true);

        // Scenario 1: Perfect match (100% similarity)
        $fp1 = ['user_agent' => 'Mozilla', 'language' => 'en', 'timezone' => 'UTC'];
        $fp2 = ['user_agent' => 'Mozilla', 'language' => 'en', 'timezone' => 'UTC'];
        $similarity = $method->invoke($service, $fp1, $fp2);
        $this->assertEquals(1.0, $similarity);

        // Scenario 2: Partial match (66.6% similarity)
        $fp3 = ['user_agent' => 'Mozilla', 'language' => 'en', 'timezone' => 'UTC'];
        $fp4 = ['user_agent' => 'Mozilla', 'language' => 'fa', 'timezone' => 'UTC'];
        $similarity = $method->invoke($service, $fp3, $fp4);
        $this->assertEquals(2/3, $similarity);

        // Scenario 3: Complete mismatch (0% similarity)
        $fp5 = ['user_agent' => 'Mozilla', 'language' => 'en'];
        $fp6 = ['user_agent' => 'Chrome', 'language' => 'fa'];
        $similarity = $method->invoke($service, $fp5, $fp6);
        $this->assertEquals(0.0, $similarity);
    }

    /** @test */
    public function test_geoip_distance_calculation(): void
    {
        $service = $this->getMockBuilder(GeolocationIntelligenceService::class)
            ->disableOriginalConstructor()
            ->getMock();

        $reflection = new \ReflectionClass(GeolocationIntelligenceService::class);
        $method = $reflection->getMethod('calculateDistance');
        $method->setAccessible(true);

        // Tehran Coordinates: 35.6892, 51.3890
        // Paris Coordinates: 48.8566, 2.3522
        // Approximate distance is around 4200-4300 km
        $distance = $method->invoke($service, 35.6892, 51.3890, 48.8566, 2.3522);

        $this->assertGreaterThan(4000, $distance);
        $this->assertLessThan(4500, $distance);
    }

    /** @test */
    public function test_ip_quality_checks(): void
    {
        $modelMock = $this->getMockBuilder(\App\Models\IpAndDeviceModel::class)
            ->disableOriginalConstructor()
            ->getMock();

        $loggerMock = $this->getMockBuilder(\App\Contracts\LoggerInterface::class)->getMock();

        $ipQuality = new IPQualityService($modelMock);

        // Scenario 1: Tor IP Check (Suspicious, score = 100)
        $modelMock->expects($this->exactly(3))
            ->method('isTorNode')
            ->willReturnMap([
                ['1.1.1.1', true],
                ['2.2.2.2', false],
                ['3.3.3.3', false],
            ]);

        $modelMock->expects($this->exactly(3))
            ->method('getSuspiciousIpRanges')
            ->willReturn([
                (object)['ip_range' => '2.2.2.0/24']
            ]);

        $resTor = $ipQuality->check('1.1.1.1');
        $this->assertTrue($resTor['is_suspicious']);
        $this->assertEquals(100, $resTor['score']);
        $this->assertContains('Tor Exit Node', $resTor['reasons']);

        // Scenario 2: VPN IP Check (Suspicious, score = 70)
        $resVPN = $ipQuality->check('2.2.2.2');
        $this->assertTrue($resVPN['is_suspicious']);
        $this->assertEquals(70, $resVPN['score']);
        $this->assertContains('Commercial VPN', $resVPN['reasons']);

        // Scenario 3: Clean IP Check (Not suspicious, score = 0)
        $resClean = $ipQuality->check('3.3.3.3');
        $this->assertFalse($resClean['is_suspicious']);
        $this->assertEquals(0, $resClean['score']);
        $this->assertEmpty($resClean['reasons']);
    }

    /** @test */
    public function test_rate_limit_ip_normalization(): void
    {
        $limiterMock = $this->getMockBuilder(\Core\RateLimiter::class)
            ->disableOriginalConstructor()
            ->getMock();
        $loggerMock = $this->getMockBuilder(\App\Contracts\LoggerInterface::class)->getMock();
        $sessionMock = $this->getMockBuilder(\Core\Session::class)
            ->disableOriginalConstructor()
            ->getMock();

        $middleware = new RateLimitMiddleware($limiterMock, $loggerMock, $sessionMock);

        $reflection = new \ReflectionClass(RateLimitMiddleware::class);
        $method = $reflection->getMethod('normalizeIp');
        $method->setAccessible(true);

        // IPv4 remains unmodified
        $this->assertEquals('192.168.1.1', $method->invoke($middleware, '192.168.1.1'));

        // IPv6 is normalized to its /64 prefix
        $ipv6 = '2001:db8:85a3:8d3:1319:8a2e:370:7348';
        $normalized = $method->invoke($middleware, $ipv6);
        
        $expected = '2001:db8:85a3:8d3::';
        $this->assertEquals($expected, $normalized);
    }
    /** @test */
    public function test_sybil_detection_uses_the_model_account_count_contract(): void
    {
        $model = $this->getMockBuilder(VelocityAndScoreModel::class)
            ->disableOriginalConstructor()
            ->getMock();
        $logger = $this->createMock(\App\Contracts\LoggerInterface::class);
        $model->expects($this->once())
            ->method('getDeviceSharing')
            ->with(42)
            ->willReturn([(object)['device_fingerprint' => 'shared-device', 'account_count' => '3']]);

        $result = (new GraphAnalysisService($logger, $model))->detectSybilNetwork(42);

        $this->assertTrue($result['is_sybil']);
        $this->assertCount(1, $result['shared_devices']);
    }

    /** @test */
    public function test_dashboard_uses_the_dedicated_analytics_model_contract(): void
    {
        $model = $this->getMockBuilder(FraudAnalyticsModel::class)
            ->disableOriginalConstructor()
            ->getMock();
        $logger = $this->createMock(\App\Contracts\LoggerInterface::class);
        $model->expects($this->once())
            ->method('getOverviewCounts')
            ->willReturn((object)[
                'total_frauds' => '4',
                'active_alerts' => '2',
                'blocked_users' => '1',
                'total_sessions' => '20',
                'high_risk_users' => '3',
                'blacklisted_users' => '1',
                'today_suspicious' => '4',
                'today_rejected' => '1',
            ]);

        $overview = (new FraudDashboardService($logger, $model))->getOverview(24);

        $this->assertSame(4, $overview['total_frauds']);
        $this->assertSame(20, $overview['total_sessions']);
        $this->assertSame(20.0, $overview['detection_rate_percent']);
    }

    /** @test */
    public function test_biometrics_preserves_repeated_key_down_events_when_calculating_history(): void
    {
        $cache = new \Core\Cache();
        $cacheKey = 'biometrics:typing:21';
        $cache->forget($cacheKey);
        $model = $this->getMockBuilder(IpAndDeviceModel::class)
            ->disableOriginalConstructor()
            ->getMock();
        $model->method('getLastTypingPattern')->willReturn(null);
        $model->expects($this->once())->method('saveTypingPattern')->with(
            21,
            $this->callback(static fn(array $pattern): bool => $pattern['keystroke_count'] === 20)
        )->willReturn(true);

        $events = [];
        for ($i = 0; $i < 10; $i++) {
            $events[] = ['type' => 'down', 'key' => 'a', 'timestamp' => $i * 120];
            $events[] = ['type' => 'up', 'key' => 'a', 'timestamp' => ($i * 120) + 45];
        }
        try {
            $result = (new BehavioralBiometricsService($cache, $model))->analyzeTypingPattern(21, $events);
            $metrics = $result['metrics'] ?? null;
            $this->assertIsArray($metrics);
            $this->assertSame(20, $metrics['keystroke_count']);
        } finally {
            $cache->forget($cacheKey);
        }
    }

}
