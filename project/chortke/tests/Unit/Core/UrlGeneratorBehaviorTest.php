<?php

declare(strict_types=1);

namespace Tests\Unit\Core;

use Core\UrlGenerator;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class UrlGeneratorBehaviorTest extends TestCase
{
    public function test_application_asset_and_base_path_urls_are_canonical(): void
    {
        $generator = new UrlGenerator(
            'https://example.test',
            'https://cdn.example.test/static',
            '/platform/',
            'testing'
        );

        $this->assertSame('https://example.test/platform', $generator->base());
        $this->assertSame('https://example.test/platform/users/42', $generator->to('/users/42'));
        $this->assertSame(
            'https://example.test/platform/users/42',
            $generator->to('/platform/users/42'),
            'An already-prefixed path must not duplicate the application base path.'
        );
        $this->assertSame('https://cdn.example.test/static/app.css', $generator->asset('/app.css'));
    }

    public function test_request_host_header_never_changes_container_backed_url_helper(): void
    {
        $previousHost = $_SERVER['HTTP_HOST'] ?? null;
        $_SERVER['HTTP_HOST'] = 'attacker.example';

        try {
            $this->assertSame('http://127.0.0.1:8090/security', url('/security'));
        } finally {
            if ($previousHost === null) {
                unset($_SERVER['HTTP_HOST']);
            } else {
                $_SERVER['HTTP_HOST'] = $previousHost;
            }
        }
    }

    /**
     * @dataProvider unsafePathProvider
     */
    public function test_external_or_malformed_path_is_rejected(string $path): void
    {
        $generator = new UrlGenerator('https://example.test', null, null, 'testing');
        $this->expectException(InvalidArgumentException::class);
        $generator->to($path);
    }

    /** @return array<string, array{0: string}> */
    public function unsafePathProvider(): array
    {
        return [
            'absolute HTTPS URL' => ['https://attacker.example/path'],
            'protocol relative URL' => ['//attacker.example/path'],
            'javascript scheme' => ['javascript://alert'],
            'null byte' => ["path\0suffix"],
        ];
    }

    /**
     * @dataProvider invalidConfiguredUrlProvider
     */
    public function test_invalid_configured_url_is_rejected(string $url): void
    {
        $this->expectException(InvalidArgumentException::class);
        new UrlGenerator($url, null, null, 'testing');
    }

    /** @return array<string, array{0: string}> */
    public function invalidConfiguredUrlProvider(): array
    {
        return [
            'relative URL' => ['/relative'],
            'FTP URL' => ['ftp://example.test'],
            'credentials' => ['https://user:pass@example.test'],
            'query' => ['https://example.test?x=1'],
            'fragment' => ['https://example.test#fragment'],
        ];
    }

    public function test_conflicting_base_paths_are_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new UrlGenerator('https://example.test/one', null, '/two', 'testing');
    }

    public function test_production_loopback_origin_is_rejected(): void
    {
        $this->expectException(RuntimeException::class);
        new UrlGenerator('http://127.0.0.1:8090', null, null, 'production');
    }
}
