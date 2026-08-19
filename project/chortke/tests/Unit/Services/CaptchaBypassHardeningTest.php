<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use PHPUnit\Framework\TestCase;

/**
 * Regression test for SEC-1 / CAPTCHA-BYPASS-2026-06.
 *
 * Background:
 *   The original CaptchaService::verify() accepted the magic literal
 *   `BYPASS_CAPTCHA_TOKEN` as a universal bypass, AND also short-circuited
 *   when env('APP_ENV') === 'testing' regardless of the request channel.
 *   Either of these gave a remote, unauthenticated attacker a one-line
 *   captcha bypass via a normal POST request.
 *
 * After the fix:
 *   1. The magic literal must be gone from the source entirely (no
 *      web-reachable bypass exists at all).
 *   2. The test shortcut must require an explicit CAPTCHA_TEST_BYPASS flag
 *      gated on PHP_SAPI === 'cli', so APP_ENV=testing alone never bypasses.
 *
 * These string-level assertions are intentional: they guarantee that even
 * a refactor that accidentally re-introduces the old code path will fail
 * CI before it reaches review.
 */
/**
 * @group architecture
 */
class CaptchaBypassHardeningTest extends TestCase
{
    private string $sourcePath;
    private string $source;

    protected function setUp(): void
    {
        $this->sourcePath = dirname(__DIR__, 3) . '/app/Services/CaptchaService.php';
        $source = file_get_contents($this->sourcePath);
        $this->assertIsString($source);

        // Drop /* ... */ and // ... comments so docblock references to the
        // historical magic string do not falsely trip the assertions below.
        $source = preg_replace('!/\*.*?\*/!s', '', $source);
        $this->assertIsString($source);
        $source = preg_replace('!//[^\n]*!', '', $source);
        $this->assertIsString($source);
        $this->source = $source;
    }

    /** @test */
    public function magic_bypass_string_is_not_present_in_executable_code(): void
    {
        $this->assertStringNotContainsString(
            'BYPASS_CAPTCHA_TOKEN',
            $this->source,
            'The literal `BYPASS_CAPTCHA_TOKEN` must not appear in executable code. '
            . 'It was a remote-reachable captcha bypass — see SEC-1.'
        );
    }

    /** @test */
    public function test_bypass_requires_explicit_flag_and_cli_sapi(): void
    {
        $this->assertStringNotContainsString("env('APP_ENV') === 'testing'", $this->source,
            'APP_ENV=testing alone must never bypass captcha validation.');

        $this->assertMatchesRegularExpressionCompat(
            '/PHP_SAPI\s*===\s*[\'"]cli[\'"].*CAPTCHA_TEST_BYPASS|CAPTCHA_TEST_BYPASS.*PHP_SAPI\s*===\s*[\'"]cli[\'"]/s',
            $this->source,
            'The captcha test bypass must require CAPTCHA_TEST_BYPASS and PHP_SAPI === "cli".'
        );
    }

    /** @test */
    public function verify_method_still_exists_with_expected_signature(): void
    {
        // Smoke test: don't accidentally rename verify() or break callers.
        $ref = new \ReflectionMethod(\App\Services\CaptchaService::class, 'verify');
        $params = array_map(fn($p) => $p->getName(), $ref->getParameters());
        $this->assertSame(['token', 'response', 'recaptchaResponse', 'behavioralState'], $params);
    }

    // PHPUnit 8 doesn't ship assertMatchesRegularExpression(); use assertRegExp.
    private function assertMatchesRegularExpressionCompat(string $pattern, string $string, string $msg = ''): void
    {
        $this->assertRegExp($pattern, $string, $msg);
    }
}
