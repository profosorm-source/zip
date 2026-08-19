<?php

declare(strict_types=1);

namespace Tests\Unit\Middleware;

use App\Middleware\SecurityHeadersMiddleware;
use Mockery;
use PHPUnit\Framework\TestCase;

/**
 * Regression tests for CSP source normalization.
 *
 * Configuration is an administrative boundary: malformed values must not cause
 * a request-time TypeError and must not become invalid CSP source tokens.
 */
final class SecurityHeadersMiddlewareTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $previousConfigOverrides = [];

    /** @var string|false */
    private string|false $previousCspEnv;

    private bool $hadCspEnvEntry = false;

    protected function setUp(): void
    {
        parent::setUp();

        global $configOverrides;
        $this->previousConfigOverrides = is_array($configOverrides ?? null) ? $configOverrides : [];
        $this->previousCspEnv = getenv('CSP_CDN_WHITELIST');
        $this->hadCspEnvEntry = array_key_exists('CSP_CDN_WHITELIST', $_ENV);

        // Each test must select the config branch unless it deliberately tests
        // the environment-variable override.
        unset($_ENV['CSP_CDN_WHITELIST']);
        putenv('CSP_CDN_WHITELIST');
    }

    protected function tearDown(): void
    {
        global $configOverrides;
        $configOverrides = $this->previousConfigOverrides;

        if ($this->previousCspEnv === false) {
            putenv('CSP_CDN_WHITELIST');
        } else {
            putenv('CSP_CDN_WHITELIST=' . $this->previousCspEnv);
        }

        if ($this->hadCspEnvEntry) {
            $_ENV['CSP_CDN_WHITELIST'] = (string) $this->previousCspEnv;
        } else {
            unset($_ENV['CSP_CDN_WHITELIST']);
        }

        Mockery::close();
        parent::tearDown();
    }

    /** @test */
    public function config_whitelist_ignores_non_string_and_blank_entries(): void
    {
        $sources = $this->cdnWhitelistFromConfig([
            '  https://cdn.example.test  ',
            null,
            123,
            ['https://must-not-be-converted.example.test'],
            new \stdClass(),
            '',
            " \t ",
            'https://static.example.test',
            'https://cdn.example.test',
        ]);

        $this->assertSame([
            'https://cdn.example.test',
            'https://static.example.test',
        ], $sources);
    }

    /** @test */
    public function defaults_are_used_when_config_contains_no_valid_string_source(): void
    {
        $sources = $this->cdnWhitelistFromConfig([null, 0, false, [], new \stdClass(), '   ']);

        $this->assertSame([
            'https://cdn.jsdelivr.net',
            'https://code.jquery.com',
        ], $sources);
    }

    /** @test */
    public function non_empty_environment_whitelist_takes_precedence_over_config(): void
    {
        putenv('CSP_CDN_WHITELIST= https://env-cdn.example.test , https://env-static.example.test ');

        $sources = $this->cdnWhitelistFromConfig(['https://config-cdn.example.test']);

        $this->assertSame([
            'https://env-cdn.example.test',
            'https://env-static.example.test',
        ], $sources);
    }

    /**
     * @param array<int, mixed> $sources
     * @return list<string>
     */
    private function cdnWhitelistFromConfig(array $sources): array
    {
        global $configOverrides;
        $configOverrides = [
            'security' => ['csp_cdn_whitelist' => $sources],
            'app' => ['asset_url' => ''],
        ];

        $middleware = new SecurityHeadersMiddleware();
        $method = new \ReflectionMethod(SecurityHeadersMiddleware::class, 'cdnWhitelist');
        $method->setAccessible(true);

        /** @var list<string> $result */
        $result = $method->invoke($middleware);
        return $result;
    }
}
