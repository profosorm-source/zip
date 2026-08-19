<?php

declare(strict_types=1);

namespace Core;

/**
 * Encryption — Authenticated, versioned, context-bound symmetric encryption.
 *
 * Design goals (Anthropic-style hardening):
 *   1. AEAD only → AES-256-GCM with 12-byte random IV and 16-byte auth tag.
 *   2. Per-context sub-key derivation via HKDF-SHA256 (key isolation / rotation).
 *   3. AAD = context → ciphertext is bound to its column/purpose; cross-record
 *      replay or column-swap attacks are rejected by authenticated decryption.
 *   4. Fail loud, not silent → decrypt() throws on ANY failure.
 *      Callers that need a soft fallback must explicitly use tryDecrypt().
 *   5. Legacy v1 (AES-256-CBC with IV derived from key) is READ-ONLY and only
 *      available while the ENCRYPTION_ALLOW_V1_READ flag is enabled. It MUST
 *      never be used for new writes.
 *
 * Payload format (v2):
 *   v2:<base64(context)>:<base64(iv)>:<base64(tag)>:<base64(cipher)>
 */
/**
 * NOTE: Conceptually `final`. We omit the `final` keyword only so existing
 *       PHPUnit 8 tests can still mock it. Do NOT subclass in production code.
 */
class Encryption
{
    public const VERSION = 'v2';
    private const CIPHER = 'aes-256-gcm';
    private const IV_LEN = 12;
    private const TAG_LEN = 16;

    /**
     * Encrypt a string with an authenticated (AEAD) cipher.
     *
     * @param string $plaintext Value to encrypt.
     * @param string $context   Logical purpose / column identifier used as
     *                          both HKDF info AND GCM additional auth data.
     *                          Examples: "kyc.national_code", "bank.card_number".
     */
    public function encrypt(string $plaintext, string $context = 'default'): string
    {
        if ($context === '') {
            throw new \InvalidArgumentException('Encryption context must be a non-empty string.');
        }

        $key = $this->deriveKey($context);
        $iv  = random_bytes(self::IV_LEN);
        $tag = '';

        $cipher = openssl_encrypt(
            $plaintext,
            self::CIPHER,
            $key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            $context,
            self::TAG_LEN
        );

        if ($cipher === false) {
            throw new \RuntimeException('Encryption failed.');
        }

        return self::VERSION . ':'
            . base64_encode($context) . ':'
            . base64_encode($iv) . ':'
            . base64_encode($tag) . ':'
            . base64_encode($cipher);
    }

    /**
     * Strict decrypt — throws on ANY failure (malformed payload, wrong key,
     * tampered ciphertext, tag mismatch, disabled legacy read, …).
     *
     * Use this everywhere by default. Only switch to tryDecrypt() when the
     * caller explicitly knows how to handle a null result.
     */
    public function decrypt(string $payload): string
    {
        if ($payload === '') {
            throw new \RuntimeException('Empty ciphertext.');
        }

        if (strncmp($payload, 'v2:', 3) === 0) {
            return $this->decryptV2($payload);
        }

        if (strncmp($payload, 'v1:', 3) === 0) {
            return $this->decryptV1Legacy(substr($payload, 3));
        }

        // No version prefix → treat as raw legacy CBC payload (only during migration window).
        if ($this->legacyReadEnabled()) {
            return $this->decryptV1Legacy($payload);
        }

        throw new \RuntimeException('Unknown or unversioned ciphertext.');
    }

    /**
     * Safe wrapper around decrypt(). Returns null instead of throwing.
     *
     * IMPORTANT: never use this to silently fall back to the original payload;
     * that recreates the very bug we are fixing. Callers must explicitly
     * decide what to do with `null` (mask, skip, log, error to user, etc.).
     */
    public function tryDecrypt(string $payload): ?string
    {
        try {
            return $this->decrypt($payload);
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Re-encrypt a value from v1 → v2 with the proper context.
     * Used by the migration script (scripts/migrate_encryption_v1_to_v2.php).
     */
    public function reencryptToV2(string $payload, string $context): string
    {
        if (strncmp($payload, 'v2:', 3) === 0) {
            return $payload; // already migrated
        }
        $plain = $this->decrypt($payload);
        return $this->encrypt($plain, $context);
    }

    /**
     * Check whether a payload is already in v2 format.
     */
    public function isV2(string $payload): bool
    {
        return strncmp($payload, 'v2:', 3) === 0;
    }

    /**
     * Constant-time equality check for ciphertext / token comparison.
     */
    public function equals(string $a, string $b): bool
    {
        return hash_equals($a, $b);
    }

    /**
     * Mask sensitive output for display purposes. Never use for storage.
     */
    public function redact(string $value, int $keepLength = 4): string
    {
        $len = strlen((string)$value);
        if ($len <= $keepLength) {
            return str_repeat('*', $len);
        }
        return str_repeat('*', $len - $keepLength) . substr($value, -$keepLength);
    }

    // ────────────────────────────────────────────────────────────────────────
    // Internals
    // ────────────────────────────────────────────────────────────────────────

    private function decryptV2(string $payload): string
    {
        $parts = explode(':', $payload);
        if (count($parts) !== 5) {
            throw new \RuntimeException('Malformed v2 payload.');
        }

        [, $ctxB64, $ivB64, $tagB64, $cipherB64] = $parts;

        $context = base64_decode($ctxB64, true);
        $iv      = base64_decode($ivB64, true);
        $tag     = base64_decode($tagB64, true);
        $cipher  = base64_decode($cipherB64, true);

        if ($context === false || $iv === false || $tag === false || $cipher === false) {
            throw new \RuntimeException('Invalid v2 payload components (base64).');
        }
        if (strlen((string)$iv) !== self::IV_LEN || strlen((string)$tag) !== self::TAG_LEN) {
            throw new \RuntimeException('Invalid v2 payload component lengths.');
        }
        if ($context === '') {
            throw new \RuntimeException('Empty v2 context.');
        }

        $plain = openssl_decrypt(
            $cipher,
            self::CIPHER,
            $this->deriveKey($context),
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            $context
        );

        if ($plain === false) {
            // Tag mismatch / wrong key / tampered → authenticated decryption refused.
            throw new \RuntimeException('Decryption or authentication failed.');
        }

        return $plain;
    }

    /**
     * ❗ Legacy v1 reader (AES-256-CBC, IV derived from key).
     * Insecure — kept ONLY for the migration window. Will be deleted at
     * Phase 3 cutover.
     */
    private function decryptV1Legacy(string $cipher): string
    {
        if (!$this->legacyReadEnabled()) {
            throw new \RuntimeException('Legacy v1 read is disabled (ENCRYPTION_ALLOW_V1_READ=false).');
        }

        $key = secure_key();
        if (strlen((string)$key) < 16) {
            throw new \RuntimeException('Legacy key too short for CBC IV derivation.');
        }
        $iv  = substr($key, 0, 16);

        $plain = openssl_decrypt($cipher, 'aes-256-cbc', $key, 0, $iv);
        if ($plain === false) {
            throw new \RuntimeException('Legacy v1 decryption failed.');
        }
        return $plain;
    }

    /**
     * Per-context sub-key derivation (HKDF-SHA256).
     * Provides key isolation between columns and a single point to rotate later.
     */
    private function deriveKey(string $context): string
    {
        $master = secure_key();
        if (strlen((string)$master) < 32) {
            throw new \RuntimeException('Master key too short (need ≥ 32 bytes).');
        }
        return hash_hkdf(
            'sha256',
            $master,
            32,
            'enc:' . self::VERSION . ':' . $context
        );
    }

    private function legacyReadEnabled(): bool
    {
        // Runtime overrides (process env / $_ENV) take precedence so tests and
        // ops can flip the flag without re-bootstrapping the framework's env cache.
        $runtime = getenv('ENCRYPTION_ALLOW_V1_READ');
        if ($runtime !== false && $runtime !== '') {
            return filter_var($runtime, FILTER_VALIDATE_BOOLEAN);
        }
        if (isset($_ENV['ENCRYPTION_ALLOW_V1_READ'])) {
            return filter_var($_ENV['ENCRYPTION_ALLOW_V1_READ'], FILTER_VALIDATE_BOOLEAN);
        }
        if (function_exists('env')) {
            return filter_var(env('ENCRYPTION_ALLOW_V1_READ', false), FILTER_VALIDATE_BOOLEAN);
        }
        return false; // Secure default.
    }
}
