<?php

declare(strict_types=1);

namespace App\Services\Auth;

use Core\Cache;
use Core\CircuitBreaker;
use App\Contracts\LoggerInterface;
use App\Traits\ExternalCallTrait;
use App\Traits\ValidatesExternalUrl;

class GoogleJwtVerifier
{
    use ExternalCallTrait;
    use ValidatesExternalUrl;

    private const JWKS_CACHE_KEY = 'google_oauth_jwks';
    private const JWKS_CACHE_MINUTES = 60;
    private const JWKS_URL = 'https://www.googleapis.com/oauth2/v3/certs';

    private Cache $cache;
    private LoggerInterface $logger;
    protected ?CircuitBreaker $circuitBreaker;
    private string $jwksUrl;
    private int $timeout;

    public function __construct(Cache $cache, LoggerInterface $logger, ?CircuitBreaker $circuitBreaker = null) {
        $this->cache = $cache;
        $this->logger = $logger;
        $this->circuitBreaker = $circuitBreaker;
        $url = config('services.google.jwks_url', self::JWKS_URL);
        $this->jwksUrl = is_string($url) && $url !== '' ? $url : self::JWKS_URL;
        $this->timeout = max(2, min(30, int_value(config('services.google.timeout', 10))));
    }

    /**
     * @param list<string> $validIssuers
     * @return array<string, mixed>
     */
    public function verifyIdToken(string $idToken, string $expectedAudience, array $validIssuers): array
    {
        $segments = explode('.', $idToken);
        if (count($segments) !== 3) {
            return ['success' => false, 'message' => 'Invalid ID Token format'];
        }

        [$encodedHeader, $encodedPayload, $encodedSignature] = $segments;
        $header = json_decode($this->base64UrlDecode($encodedHeader) ?: '', true);
        $payload = json_decode($this->base64UrlDecode($encodedPayload) ?: '', true);
        $signature = $this->base64UrlDecode($encodedSignature);

        if (!is_array($header) || !is_array($payload) || $signature === false) {
            return ['success' => false, 'message' => 'Malformed ID Token'];
        }

        if (($header['alg'] ?? '') !== 'RS256' || empty($header['kid'])) {
            return ['success' => false, 'message' => 'Unsupported ID Token algorithm or missing key ID'];
        }

        $jwks = $this->getJwks();
        if (!isset($jwks['keys']) || !is_array($jwks['keys'])) {
            return ['success' => false, 'message' => 'Unable to retrieve Google public keys'];
        }

        $jwk = null;
        foreach ($jwks['keys'] as $key) {
            if (isset($key['kid']) && $key['kid'] === $header['kid']) {
                $jwk = $key;
                break;
            }
        }

        if ($jwk === null) {
            $jwks = $this->fetchJwks(true);
            if (!isset($jwks['keys']) || !is_array($jwks['keys'])) {
                return ['success' => false, 'message' => 'Google public keys are unavailable'];
            }
            foreach ($jwks['keys'] as $key) {
                if (isset($key['kid']) && $key['kid'] === $header['kid']) {
                    $jwk = $key;
                    break;
                }
            }
        }

        if ($jwk === null) {
            return ['success' => false, 'message' => 'ID Token key ID not found'];
        }

        try {
            $publicKey = $this->buildPemFromJwk($jwk);
        } catch (\Throwable $e) {
            $this->logger->error('oauth.google.jwt_key_build_failed', ['error' => $e->getMessage()]);
            return ['success' => false, 'message' => 'Unable to build JWT verification key'];
        }

        $signedInput = $encodedHeader . '.' . $encodedPayload;
        $verification = openssl_verify($signedInput, $signature, $publicKey, OPENSSL_ALGO_SHA256);
        if ($verification !== 1) {
            return ['success' => false, 'message' => 'ID Token signature verification failed'];
        }

        if (!in_array($payload['iss'] ?? '', $validIssuers, true)) {
            return ['success' => false, 'message' => 'Issuer mismatch in ID Token'];
        }

        if (($payload['aud'] ?? '') !== $expectedAudience) {
            return ['success' => false, 'message' => 'Audience mismatch in ID Token'];
        }

        if (!isset($payload['exp']) || intval($payload['exp']) < time()) {
            return ['success' => false, 'message' => 'ID Token has expired'];
        }

        if (isset($payload['iat'])) {
            $iat = intval($payload['iat']);
            if ($iat > time() + 60 || $iat < time() - 86400) {
                return ['success' => false, 'message' => 'ID Token issued at invalid time'];
            }
        }

        return ['success' => true, 'payload' => $payload];
    }

    /** @return array<string, mixed> */
    private function getJwks(): array
    {
        $cached = $this->cache->get(self::JWKS_CACHE_KEY);
        if (is_array($cached)) {
            return $cached;
        }

        return $this->fetchJwks();
    }

    /** @return array<string, mixed> */
    private function fetchJwks(bool $force = false): array
    {
        if (!$force) {
            $cached = $this->cache->get(self::JWKS_CACHE_KEY);
            if (is_array($cached)) {
                return $cached;
            }
        }

        if (!$this->isExternalUrlSafe($this->jwksUrl)) {
            $this->logger->critical('oauth.google.jwks_ssrf_blocked', ['host'=>parse_url($this->jwksUrl, PHP_URL_HOST)]);
            return [];
        }

        try {
            $raw = $this->callWithBreaker('google_jwks', function (): string {
                return $this->retryTransient(function (): string {
                    $ch = curl_init($this->jwksUrl);
                    if ($ch === false) throw new \Core\Exceptions\ProviderUnavailable('Unable to initialize Google JWKS transport');
                    curl_setopt_array($ch, [
                        CURLOPT_RETURNTRANSFER=>true,
                        CURLOPT_SSL_VERIFYPEER=>true,
                        CURLOPT_SSL_VERIFYHOST=>2,
                        CURLOPT_TIMEOUT=>$this->timeout,
                        CURLOPT_CONNECTTIMEOUT=>max(1,min(5,$this->timeout-1)),
                        CURLOPT_FOLLOWLOCATION=>false,
                        CURLOPT_HTTPHEADER=>['Accept: application/json'],
                    ]);
                    $body=curl_exec($ch); $code=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE); $errno=(int)curl_errno($ch); curl_close($ch);
                    if ($code===200 && is_string($body) && $body!=='') return $body;
                    throw $this->classifyHttpFailure($code,$errno,(string)$body,['provider'=>'google_jwks']);
                },3,300,3000);
            });
        } catch (\Throwable $e) {
            $this->logger->error('oauth.google.jwks_fetch_failed', ['error'=>$e->getMessage()]);
            return [];
        }

        $jwks = json_decode($raw, true);
        if (!is_array($jwks) || !isset($jwks['keys']) || !is_array($jwks['keys'])) {
            $this->logger->error('oauth.google.jwks_invalid_response', ['response'=>$jwks]);
            return [];
        }

        $this->cache->set(self::JWKS_CACHE_KEY, $jwks, self::JWKS_CACHE_MINUTES);
        return $jwks;
    }

    private function base64UrlDecode(string $value): string|false
    {
        $value = str_replace(['-', '_'], ['+', '/'], $value);
        $padding = strlen((string)$value) % 4;
        if ($padding > 0) {
            $value .= str_repeat('=', 4 - $padding);
        }
        return base64_decode($value, true);
    }

    /** @param array<string, mixed> $jwk */
    private function buildPemFromJwk(array $jwk): string
    {
        if (empty($jwk['n']) || empty($jwk['e'])) {
            throw new \InvalidArgumentException('JWK is missing modulus or exponent');
        }

        $modulus = $this->base64UrlDecode(str_value($jwk['n']));
        $exponent = $this->base64UrlDecode(str_value($jwk['e']));
        if ($modulus === false || $exponent === false) {
            throw new \InvalidArgumentException('Invalid JWK encoding');
        }

        $modulus = ltrim($modulus, "\x00");
        if (ord($modulus[0]) > 0x7f) {
            $modulus = "\x00" . $modulus;
        }

        $components = $this->encodeSequence([
            $this->encodeInteger($modulus),
            $this->encodeInteger($exponent),
        ]);

        $rsaOid = hex2bin('300d06092a864886f70d0101010500');
        if ($rsaOid === false) {
            throw new \RuntimeException('Unable to build RSA public key OID');
        }

        $bitString = "\x03" . $this->encodeLength(strlen((string)$components) + 1) . "\x00" . $components;
        $der = $this->encodeSequence([$rsaOid, $bitString]);

        return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END PUBLIC KEY-----\n";
    }

    private function encodeInteger(string $data): string
    {
        return "\x02" . $this->encodeLength(strlen((string)$data)) . $data;
    }

    /** @param list<string> $elements */
    private function encodeSequence(array $elements): string
    {
        $payload = implode('', $elements);
        return "\x30" . $this->encodeLength(strlen((string)$payload)) . $payload;
    }

    private function encodeLength(int $length): string
    {
        if ($length < 128) {
            return chr($length);
        }

        $hexLength = dechex($length);
        if (strlen((string)$hexLength) % 2 !== 0) {
            $hexLength = '0' . $hexLength;
        }

        $lengthBytes = hex2bin($hexLength);
        return chr(0x80 | strlen((string)$lengthBytes)) . $lengthBytes;
    }
}
