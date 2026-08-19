<?php

namespace Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use App\Services\Auth\GoogleJwtVerifier;
use Mockery as m;

class GoogleJwtVerifierTest extends TestCase
{
    /** @var \Core\Cache&\Mockery\MockInterface */
    private \Core\Cache $cache;
    /** @var \App\Contracts\LoggerInterface&\Mockery\MockInterface */
    private \App\Contracts\LoggerInterface $logger;
    private GoogleJwtVerifier $verifier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cache = m::mock('Core\Cache');
        $this->logger = m::mock('App\Contracts\LoggerInterface');

        $this->logger->shouldIgnoreMissing();

        $this->verifier = new GoogleJwtVerifier($this->cache, $this->logger);
    }

    protected function tearDown(): void
    {
        m::close();
        parent::tearDown();
    }

    /** @test */
    public function verifier_can_be_instantiated(): void
    {
        $this->assertInstanceOf(GoogleJwtVerifier::class, $this->verifier);
    }

    /** @test */
    public function verify_id_token_returns_error_on_invalid_jwt_format(): void
    {
        // Missing segments (only 1 or 2 parts instead of 3)
        $result = $this->verifier->verifyIdToken('invalid_token', 'expected_aud', ['issuer']);
        
        $this->assertFalse($result['success']);
        $this->assertEquals('Invalid ID Token format', $result['message']);
    }

    /** @test */
    public function verify_id_token_returns_error_on_unsupported_algorithm(): void
    {
        // Build JWT with non-RS256 alg (e.g. HS256)
        $headerJson = json_encode(['alg' => 'HS256', 'kid' => 'key_123']);
        $payloadJson = json_encode(['iss' => 'google.com', 'aud' => 'app_id']);
        $this->assertIsString($headerJson);
        $this->assertIsString($payloadJson);
        $header = base64_encode($headerJson);
        $payload = base64_encode($payloadJson);
        $signature = base64_encode('some_sig');

        $token = "{$header}.{$payload}.{$signature}";

        $result = $this->verifier->verifyIdToken($token, 'app_id', ['google.com']);

        $this->assertFalse($result['success']);
        $this->assertEquals('Unsupported ID Token algorithm or missing key ID', $result['message']);
    }

    /** @test */
    public function base64_url_decode_handles_standard_jwt_url_safe_characters(): void
    {
        $ref = new \ReflectionClass(GoogleJwtVerifier::class);
        $method = $ref->getMethod('base64UrlDecode');
        $method->setAccessible(true);

        // Test standard string
        $encoded = 'Y2hvcnRrZQ'; // 'chortke' without padding
        $decoded = $method->invokeArgs($this->verifier, [$encoded]);
        $this->assertEquals('chortke', $decoded);

        // Test URL safe replacements ('-' to '+', '_' to '/')
        $encodedSafe = 'Y2hvcnRrZS1wbGF0Zm9ybV8xMjM'; // 'chortke-platform_123' encoded urlsafe
        $decodedSafe = $method->invokeArgs($this->verifier, [$encodedSafe]);
        $this->assertEquals('chortke-platform_123', $decodedSafe);
    }

    /** @test */
    public function jwks_building_throws_on_invalid_modulus_or_exponent(): void
    {
        $ref = new \ReflectionClass(GoogleJwtVerifier::class);
        $method = $ref->getMethod('buildPemFromJwk');
        $method->setAccessible(true);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('JWK is missing modulus or exponent');

        // Missing exponent ('e') and modulus ('n')
        $method->invokeArgs($this->verifier, [['kid' => 'key_123']]);
    }
}
