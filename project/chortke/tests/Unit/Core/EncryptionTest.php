<?php

declare(strict_types=1);

namespace Tests\Unit\Core;

use Core\Encryption;
use PHPUnit\Framework\TestCase;

/**
 * Security regression tests for core/Encryption.php
 *
 * These tests pin the AEAD invariants we depend on (non-determinism,
 * context binding, fail-loud decryption) so the legacy CBC+static-IV
 * behavior can never reappear silently.
 */
class EncryptionTest extends TestCase
{
    private Encryption $enc;

    protected function setUp(): void
    {
        parent::setUp();
        // bootstrap/testing.php has already loaded helpers + config; secure_key()
        // will read the test APP_KEY from .env.
        if (!function_exists('secure_key')) {
            require __DIR__ . '/../../../helpers/security.php';
        }
        // Make sure v1 reads are disabled by default in tests.
        putenv('ENCRYPTION_ALLOW_V1_READ=false');
        $_ENV['ENCRYPTION_ALLOW_V1_READ'] = 'false';

        $this->enc = new Encryption();
    }

    private function appKey(): string
    {
        return secure_key();
    }

    public function test_same_plaintext_yields_different_ciphertext_each_call(): void
    {
        $a = $this->enc->encrypt('1234567890', 'kyc.national_code');
        $b = $this->enc->encrypt('1234567890', 'kyc.national_code');
        $this->assertNotSame($a, $b, 'AEAD ciphertext must be non-deterministic.');
        $this->assertSame('1234567890', $this->enc->decrypt($a));
        $this->assertSame('1234567890', $this->enc->decrypt($b));
    }

    public function test_decrypt_returns_original_plaintext(): void
    {
        $c = $this->enc->encrypt('hello world', 'unit.test');
        $this->assertSame('hello world', $this->enc->decrypt($c));
    }

    public function test_decrypt_throws_on_tampered_ciphertext(): void
    {
        $c = $this->enc->encrypt('secret', 'unit.test');
        $tampered = substr($c, 0, -2) . 'AA';
        $this->expectException(\RuntimeException::class);
        $this->enc->decrypt($tampered);
    }

    public function test_decrypt_throws_on_swapped_context_aad(): void
    {
        $c = $this->enc->encrypt('1234567890', 'kyc.national_code');
        $parts = explode(':', $c);
        $parts[1] = base64_encode('bank.card_number'); // forge a different context
        $forged = implode(':', $parts);
        $this->expectException(\RuntimeException::class);
        $this->enc->decrypt($forged);
    }

    public function test_decrypt_throws_on_unknown_or_unversioned_payload(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->enc->decrypt('plaintext-no-prefix');
    }

    public function test_try_decrypt_returns_null_on_failure(): void
    {
        $this->assertNull($this->enc->tryDecrypt('garbage'));
        $this->assertNull($this->enc->tryDecrypt('v2:bad:payload'));
    }

    public function test_legacy_v1_read_is_disabled_by_default(): void
    {
        $key = $this->appKey();
        $iv  = substr($key, 0, 16);
        $v1  = openssl_encrypt('legacy', 'aes-256-cbc', $key, 0, $iv);

        $this->expectException(\RuntimeException::class);
        $this->enc->decrypt('v1:' . $v1);
    }

    public function test_legacy_v1_read_works_when_explicitly_enabled(): void
    {
        $_ENV['ENCRYPTION_ALLOW_V1_READ'] = 'true';
        putenv('ENCRYPTION_ALLOW_V1_READ=true');

        try {
            $key = $this->appKey();
            $iv  = substr($key, 0, 16);
            $v1  = openssl_encrypt('legacy', 'aes-256-cbc', $key, 0, $iv);

            $this->assertSame('legacy', $this->enc->decrypt('v1:' . $v1));
            // And re-encryption upgrades it to v2:
            $upgraded = $this->enc->reencryptToV2('v1:' . $v1, 'unit.test');
            $this->assertTrue($this->enc->isV2($upgraded));
            $this->assertSame('legacy', $this->enc->decrypt($upgraded));
        } finally {
            putenv('ENCRYPTION_ALLOW_V1_READ=false');
            $_ENV['ENCRYPTION_ALLOW_V1_READ'] = 'false';
        }
    }

    public function test_redact_keeps_only_trailing_chars(): void
    {
        $this->assertSame('******7890', $this->enc->redact('1234567890', 4));
        $this->assertSame('****',       $this->enc->redact('1234',       4));
    }

    public function test_encrypt_rejects_empty_context(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->enc->encrypt('x', '');
    }
}
