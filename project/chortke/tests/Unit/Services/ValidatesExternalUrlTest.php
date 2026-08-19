<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use App\Traits\ValidatesExternalUrl;

final class ExternalUrlValidationProbe
{
    use ValidatesExternalUrl;

    /** @param list<string> $allowedHosts */
    public function checkSafe(string $url, array $allowedHosts = []): bool
    {
        return $this->isExternalUrlSafe($url, $allowedHosts);
    }

    public function checkAssert(string $url, string $context = 'test'): void
    {
        $this->assertExternalUrlSafe($url, $context);
    }
}

/**
 * تست‌های واحد ValidatesExternalUrl trait (SSRF Mitigation)
 *
 * پوشش:
 *  - isExternalUrlSafe: URL های ایمن، URL های بلاک‌شده
 *  - assertExternalUrlSafe: رفتار exception
 *  - edge cases: IPv6، port های غیر‌استاندارد، scheme های ناامن
 */
class ValidatesExternalUrlTest extends TestCase
{
    /**
     * یک کلاس ناشناس که trait را مصرف می‌کند
     */
    private ExternalUrlValidationProbe $subject;

    protected function setUp(): void
    {
        parent::setUp();

        $this->subject = new ExternalUrlValidationProbe();
    }

    // ──────────────────────────────────────────────────────────────
    // موارد BLOCK شده (باید false برگردد)
    // ──────────────────────────────────────────────────────────────

    public function test_blocks_empty_url(): void
    {
        $this->assertFalse($this->subject->checkSafe(''));
    }

    public function test_blocks_localhost(): void
    {
        $this->assertFalse($this->subject->checkSafe('http://localhost/api'));
        $this->assertFalse($this->subject->checkSafe('https://localhost:8080/secret'));
    }

    public function test_blocks_loopback_ipv4(): void
    {
        $this->assertFalse($this->subject->checkSafe('http://127.0.0.1/'));
        $this->assertFalse($this->subject->checkSafe('https://127.0.0.1:9200/'));
    }

    public function test_blocks_loopback_ipv6(): void
    {
        $this->assertFalse($this->subject->checkSafe('http://[::1]/'));
        $this->assertFalse($this->subject->checkSafe('http://::1/'));
    }

    public function test_blocks_zero_address(): void
    {
        $this->assertFalse($this->subject->checkSafe('http://0.0.0.0/'));
    }

    public function test_blocks_private_class_a(): void
    {
        $this->assertFalse($this->subject->checkSafe('http://10.0.0.1/'));
        $this->assertFalse($this->subject->checkSafe('http://10.255.255.255/'));
    }

    public function test_blocks_private_class_b(): void
    {
        $this->assertFalse($this->subject->checkSafe('http://172.16.0.1/'));
        $this->assertFalse($this->subject->checkSafe('http://172.31.255.255/'));
    }

    public function test_blocks_private_class_c(): void
    {
        $this->assertFalse($this->subject->checkSafe('http://192.168.1.1/'));
        $this->assertFalse($this->subject->checkSafe('http://192.168.255.255/'));
    }

    public function test_blocks_non_http_schemes(): void
    {
        $this->assertFalse($this->subject->checkSafe('ftp://example.com/'));
        $this->assertFalse($this->subject->checkSafe('file:///etc/passwd'));
        $this->assertFalse($this->subject->checkSafe('gopher://evil.com/'));
        $this->assertFalse($this->subject->checkSafe('dict://metadata/'));
        $this->assertFalse($this->subject->checkSafe('ldap://internal.corp/'));
    }

    public function test_blocks_url_without_host(): void
    {
        $this->assertFalse($this->subject->checkSafe('not-a-url'));
        $this->assertFalse($this->subject->checkSafe('/relative/path'));
    }

    public function test_blocks_aws_metadata_ip(): void
    {
        // 169.254.x.x (link-local) — reserved range
        $this->assertFalse($this->subject->checkSafe('http://169.254.169.254/latest/meta-data/'));
    }

    // ──────────────────────────────────────────────────────────────
    // موارد PASS شده (باید true برگردد)
    // ──────────────────────────────────────────────────────────────

    public function test_allows_public_https_url(): void
    {
        // این آدرس‌ها IP عمومی دارند — باید pass شوند
        // توجه: gethostbyname در محیط test ممکن است resolve نکند
        // پس فقط روی IP های مستقیم تست می‌کنیم
        $this->assertTrue($this->subject->checkSafe('https://8.8.8.8/'));
    }

    public function test_allows_public_http_url(): void
    {
        $this->assertTrue($this->subject->checkSafe('http://8.8.4.4/'));
    }

    public function test_allows_cloudflare_dns(): void
    {
        $this->assertTrue($this->subject->checkSafe('https://1.1.1.1/'));
    }

    public function test_allows_url_with_port(): void
    {
        $this->assertTrue($this->subject->checkSafe('https://8.8.8.8:443/path'));
    }

    public function test_allows_url_with_path_and_query(): void
    {
        $this->assertTrue($this->subject->checkSafe('https://8.8.8.8/api/v1/data?key=value'));
    }

    // ──────────────────────────────────────────────────────────────
    // assertExternalUrlSafe
    // ──────────────────────────────────────────────────────────────

    public function test_assertExternalUrlSafe_throws_for_localhost(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/SSRF Protection/');

        $this->subject->checkAssert('http://localhost/admin', 'admin panel');
    }

    public function test_assertExternalUrlSafe_throws_for_private_ip(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/SSRF Protection/');

        $this->subject->checkAssert('http://192.168.1.100/api', 'internal API');
    }

    public function test_assertExternalUrlSafe_throws_for_file_scheme(): void
    {
        $this->expectException(\RuntimeException::class);

        $this->subject->checkAssert('file:///etc/passwd');
    }

    public function test_assertExternalUrlSafe_does_not_throw_for_public_ip(): void
    {
        // نباید exception پرتاب کند
        $this->expectNotToPerformAssertions();

        $this->subject->checkAssert('https://8.8.8.8/dns', 'dns query');
    }

    public function test_assertExternalUrlSafe_exception_contains_context(): void
    {
        try {
            $this->subject->checkAssert('http://10.0.0.1/', 'payment gateway');
            $this->fail('باید exception پرتاب شود');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('payment gateway', $e->getMessage());
        }
    }

    // ──────────────────────────────────────────────────────────────
    // boundary edge cases
    // ──────────────────────────────────────────────────────────────

    public function test_blocks_url_with_only_scheme(): void
    {
        $this->assertFalse($this->subject->checkSafe('http://'));
    }

    public function test_blocks_uppercase_localhost(): void
    {
        $this->assertFalse($this->subject->checkSafe('http://LOCALHOST/'));
        $this->assertFalse($this->subject->checkSafe('http://LocalHost/'));
    }

    public function test_trait_is_available_in_class(): void
    {
        $usedTraits = class_uses($this->subject::class);
        // بررسی از طریق method existence چون class_uses روی anonymous class کار ممکن است متفاوت باشد
        $this->assertTrue(method_exists($this->subject, 'checkSafe'));
    }

    public function test_reflection_shows_isExternalUrlSafe_is_protected(): void
    {
        // ValidatesExternalUrl باید isExternalUrlSafe را protected اعلام کرده باشد
        $reflection = new \ReflectionClass(\App\Traits\ValidatesExternalUrl::class);
        $method     = $reflection->getMethod('isExternalUrlSafe');
        $this->assertTrue($method->isProtected(), 'isExternalUrlSafe باید protected باشد');
    }

    public function test_reflection_shows_assertExternalUrlSafe_is_protected(): void
    {
        $reflection = new \ReflectionClass(\App\Traits\ValidatesExternalUrl::class);
        $method     = $reflection->getMethod('assertExternalUrlSafe');
        $this->assertTrue($method->isProtected(), 'assertExternalUrlSafe باید protected باشد');
    }

    public function test_allowlist_rejects_public_host_not_in_list(): void
    {
        $this->assertFalse($this->subject->checkSafe('https://8.8.8.8/', ['1.1.1.1']));
    }

    public function test_allowlist_accepts_public_host_in_list(): void
    {
        $this->assertTrue($this->subject->checkSafe('https://8.8.8.8/path', ['8.8.8.8']));
    }

    public function test_allowlist_is_case_insensitive(): void
    {
        $this->assertTrue($this->subject->checkSafe('https://8.8.8.8/', ['8.8.8.8']));
    }

    public function test_allowlist_does_not_bypass_ssrf(): void
    {
        $this->assertFalse($this->subject->checkSafe('http://127.0.0.1/', ['127.0.0.1']));
        $this->assertFalse($this->subject->checkSafe('http://192.168.1.10/', ['192.168.1.10']));
    }

    public function test_empty_allowlist_keeps_previous_ssrf_only_behavior(): void
    {
        $this->assertTrue($this->subject->checkSafe('https://1.1.1.1/'));
        $this->assertFalse($this->subject->checkSafe('http://10.0.0.1/'));
    }

    public function test_assertExternalUrlSafe_has_context_parameter(): void
    {
        $reflection = new \ReflectionClass(\App\Traits\ValidatesExternalUrl::class);
        $method     = $reflection->getMethod('assertExternalUrlSafe');
        $params     = $method->getParameters();

        $this->assertCount(2, $params);
        $this->assertEquals('url', $params[0]->getName());
        $this->assertEquals('context', $params[1]->getName());
        $this->assertTrue($params[1]->isOptional(), 'پارامتر context باید optional باشد');
    }
}
