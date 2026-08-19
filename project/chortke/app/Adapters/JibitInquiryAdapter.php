<?php

declare(strict_types=1);

namespace App\Adapters;

use App\Traits\ValidatesExternalUrl;

use App\Contracts\CircuitBreakerInterface;
use App\Contracts\LoggerInterface;

/**
 * JibitInquiryAdapter
 * پیاده‌سازی آداپتر استعلام اطلاعات بانکی از طریق سرویس جی‌بیت
 */
class JibitInquiryAdapter implements BankInquiryAdapter
{
    use \App\Traits\ValidatesExternalUrl;
    use \App\Traits\ExternalCallTrait;

    private ?string $apiKey;
    private ?string $apiSecret;
    private string $baseUrl = 'https://api.jibit.ir/v1/';
    private LoggerInterface $logger;
    private \App\Contracts\CacheInterface $cache;
    private CircuitBreakerInterface $circuitBreaker;
    public function __construct(LoggerInterface $logger, \App\Contracts\CacheInterface $cache, CircuitBreakerInterface $circuitBreaker) {
        $this->logger = $logger;
        $this->cache  = $cache;
        $this->circuitBreaker = $circuitBreaker;
        // دریافت متغیرهای اتصال از .env
        $apiKey = config('services.jibit.api_key');
        $apiSecret = config('services.jibit.api_secret');
        $this->apiKey = is_string($apiKey) ? $apiKey : null;
        $this->apiSecret = is_string($apiSecret) ? $apiSecret : null;
        $configuredBase = config('services.jibit.base_url', 'https://api.jibit.ir/v1/');
        $this->baseUrl = rtrim(is_string($configuredBase) ? $configuredBase : 'https://api.jibit.ir/v1/', '/') . '/';
    }

    /**
     * بررسی می‌کند که آیا کلیدهای اتصال تنظیم شده‌اند یا خیر
     */
    public function isConfigured(): bool
    {
        return !empty($this->apiKey) && !empty($this->apiSecret);
    }

    /**
     * استعلام نام صاحب شبا
     */
    public function inquireIban(string $iban): array
    {
        if (!$this->isConfigured()) {
            return [
                'success' => false,
                'message' => 'پیکربندی Jibit انجام نشده است.'
            ];
        }

        $iban = strtoupper(str_replace(' ', '', trim($iban)));
        if (!$this->isValidIranianIban($iban)) {
            return ['success'=>false,'message'=>'شماره شبا نامعتبر است.'];
        }
        $cacheKey = 'iban_inquiry:' . hash('sha256', $iban);

        // 1. Check cache first
        try {
            $cached = $this->cache->get($cacheKey);
            if ($cached !== null) {
                $decoded = is_string($cached) ? (array)(json_decode($cached, true) ?? []) : $cached;
                if (is_array($decoded)) {
                    return $decoded;
                }
            }
        } catch (\Throwable $ignore) { /* intentional: non-blocking operation */ }
        
        try {
            // 2. Execute within Circuit Breaker and retry with backoff using central ExternalCallTrait
            $result = $this->circuitBreaker->call('jibit', function() use ($iban) {
                return $this->retryTransient(function() use ($iban) {
                    // ۱. دریافت Token
                    $token = $this->getAccessToken();
                    if (!$token) {
                        throw new \Core\Exceptions\PermanentFailure('خطا در احراز هویت با سرویس بانکی.');
                    }

                    // ۲. درخواست استعلام شبا
                    $response = $this->makeRequest('GET', 'services/iban?value=' . $iban, [], $token);

                    if (isset($response['name'])) {
                        return [
                            'success' => true,
                            'owner_name' => $response['name'] . ' ' . ($response['familyName'] ?? ''),
                            'bank' => $response['bank'] ?? null,
                            'message' => 'استعلام با موفقیت انجام شد.'
                        ];
                    }

                    $errorPayload = is_array($response['error'] ?? null) ? $response['error'] : [];
                    $errorMessage = is_string($errorPayload['message'] ?? null)
                        ? $errorPayload['message']
                        : 'پاسخ نامعتبر از سمت سرویس بانکی.';
                    throw new \Core\Exceptions\PermanentFailure($errorMessage);
                }, 3, 500, 3000);
            });

            if (!is_array($result)) {
                throw new \UnexpectedValueException('Jibit circuit breaker returned an invalid response.');
            }

            // 3. Cache successful results for 24 hours (1440 minutes)
            if (!empty($result['success'])) {
                try {
                    $this->cache->put($cacheKey, $result, 1440);
                } catch (\Throwable $ignore) { /* intentional: non-blocking operation */ }
            }

            return $result;

        } catch (\Throwable $e) {
            $this->logger->error('jibit.inquiry.failed', [
                'iban' => $iban,
                'error' => $e->getMessage()
            ]);

            if (strpos($e->getMessage(), 'Circuit breaker') !== false) {
                return [
                    'success' => false,
                    'message' => 'سرویس استعلام موقتاً در دسترس نیست. لطفا بعدا تلاش کنید.'
                ];
            }

            return [
                'success' => false,
                'message' => 'عدم برقراری ارتباط با سرویس استعلام شبا: ' . $e->getMessage()
            ];
        }
    }

    /**
     * تولید Access Token جی‌بیت
     */
    private function getAccessToken(): ?string
    {
        $payload = [
            'apiKey' => $this->apiKey,
            'secretKey' => $this->apiSecret,
        ];

        $result = $this->makeRequest('POST', 'tokens/generate', $payload);
        $token = $result['accessToken'] ?? null;
        return is_string($token) ? $token : null;
    }

    /**
     * اجرای درخواست خام با CURL - with comprehensive timeout and error handling
     */
    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function makeRequest(string $method, string $endpoint, array $data = [], ?string $token = null): array
    {
        $url = $this->baseUrl . $endpoint;
        // ✅ SSRF check — baseUrl از config می‌آید اما باید verify شود
        if (!$this->isExternalUrlSafe($url)) {
            throw new \RuntimeException('SSRF Protection: Jibit URL blocked: ' . $url);
        }
        $ch = curl_init($url);
        
        $headers = [
            'Content-Type: application/json',
            'Accept: application/json',
        ];

        if ($token) {
            $headers[] = 'Authorization: Bearer ' . $token;
        }

        // Comprehensive timeout handling to prevent hanging requests
        $timeoutValue = config('services.jibit.timeout', 10);
        $timeout = is_numeric($timeoutValue) ? (int)$timeoutValue : 10;
        $connectTimeout = max(2, (int)floor($timeout / 3));

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeout,                    // Total timeout
            CURLOPT_CONNECTTIMEOUT => $connectTimeout,      // Connection timeout
            CURLOPT_DNS_CACHE_TIMEOUT => 120,               // Cache DNS for 2 minutes
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_FAILONERROR => false,                   // Don't fail silently on HTTP errors
        ]);

        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        }

        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr = curl_errno($ch);
        $curlErrMsg = curl_error($ch);
        curl_close($ch);

        if ($httpCode >= 200 && $httpCode < 300 && $response) {
            $decoded = json_decode((string)$response, true);
            return is_array($decoded) ? $decoded : [];
        }

        throw $this->classifyHttpFailure($httpCode, $curlErr, (string)$response, ['provider' => 'jibit']);
    }

    private function isValidIranianIban(string $iban): bool
    {
        if (preg_match('/^IR\d{24}$/', $iban) !== 1) return false;
        $rearranged = substr($iban, 4) . '1827' . substr($iban, 2, 2);
        $remainder = 0;
        foreach (str_split($rearranged) as $digit) {
            $remainder = ($remainder * 10 + (int)$digit) % 97;
        }
        return $remainder === 1;
    }
}


