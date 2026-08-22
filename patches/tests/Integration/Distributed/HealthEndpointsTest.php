<?php

namespace Tests\Integration\Distributed;

use PHPUnit\Framework\TestCase;

/**
 * Tests the consolidated health/distributed and metrics endpoints.
 * Assumes the PHP built-in server or real web server is running on 127.0.0.1:8090
 * (or the test skips gracefully).
 */
class HealthEndpointsTest extends TestCase
{
    private string $base = 'http://127.0.0.1:8090';

    protected function setUp(): void
    {
        parent::setUp();
        // از همان قرارداد محیطیِ سوییت E2E پروژه استفاده می‌کنیم تا آدرس ثابت
        // در کد تست باقی نماند و در محیط‌های مختلف قابل تنظیم باشد.
        $configured = getenv('CHORTKE_E2E_BASE_URL');
        if (is_string($configured) && $configured !== '') {
            $this->base = rtrim($configured, '/');
        }
    }

    public function test_health_distributed_endpoint_exists_or_skips(): void
    {
        $ctx = stream_context_create(['http' => ['timeout' => 2]]);
        $response = @file_get_contents($this->base . '/health/distributed', false, $ctx);
        
        if ($response === false) {
            $this->fail('Web server not running on 8090 or endpoint not reachable. Start with php -S 127.0.0.1:8090 -t public');
        }
        
        $data = json_decode($response, true);
        $this->assertIsArray($data);
        $this->assertArrayHasKey('checks', $data);
        $this->assertArrayHasKey('outbox', $data['checks']);
        $this->assertArrayHasKey('dlq', $data['checks']);
    }

    public function test_metrics_distributed_returns_prometheus_or_json(): void
    {
        $ctx = stream_context_create(['http' => ['timeout' => 2, 'header' => "Accept: text/plain\r\n"]]);
        $response = @file_get_contents($this->base . '/metrics/distributed', false, $ctx);
        
        if ($response === false) {
            $this->fail('Web server not reachable for metrics test.');
        }
        
        // Either Prometheus text or JSON
        $this->assertTrue(
            str_contains($response, 'chortke_') || 
            str_contains($response, 'outbox_') ||
            json_decode($response) !== null
        );
    }
}
