<?php

declare(strict_types=1);

namespace App\Services\Payment;

use App\Contracts\PaymentGatewayInterface;
use App\Contracts\LoggerInterface;
use App\Exceptions\PaymentGatewayConnectionException;
use Core\RetryPolicy;
use Core\CircuitBreaker;
use Core\Exceptions\PermanentFailure;
use App\Traits\ValidatesExternalUrl;

/**
 * BasePaymentGateway - درگاه پیمنٹ بنیادی
 * 
 * تمام payment gateways کے لیے مشترک functionality:
 * - Circuit Breaker protection against cascading failures
 * - Retry logic with exponential backoff
 * - SSL/TLS verification
 * - Timeout handling
 * - Error logging
 */
abstract class BasePaymentGateway implements PaymentGatewayInterface
{
    use ValidatesExternalUrl;
    /**
     * Exceptions جن کے لیے retry کریں
     * 
     * @var array
     */
    /** @var list<class-string> */
    protected array $retryableExceptions = [
        PaymentGatewayConnectionException::class,
        \RuntimeException::class,
    ];

    /**
     * Exceptions جن کے لیے retry نہ کریں (whitelist)
     * @var list<string>
     */
    protected array $nonRetryableExceptions = [
        'InvalidArgumentException',      // Input validation
        'PaymentVerificationException',   // Invalid transaction
    ];

    /**
     * HTTP status codes جن کے لیے retry کریں
     * @var list<int>
     */
    protected array $retryableStatusCodes = [
        408,  // Request Timeout
        429,  // Too Many Requests
        500,  // Internal Server Error
        502,  // Bad Gateway
        503,  // Service Unavailable
        504,  // Gateway Timeout
    ];

    protected RetryPolicy $retryPolicy;


    /**
     * @internal exposed for ExternalCallTrait::resolveCircuitBreaker()
     */
    protected CircuitBreaker $circuitBreaker;

    protected \App\Contracts\LoggerInterface $logger;
    public function __construct(
        \App\Contracts\LoggerInterface $logger,
        CircuitBreaker $circuitBreaker
    ) {        $this->logger = $logger;

        
        $this->retryPolicy = new RetryPolicy();
        $this->circuitBreaker = $circuitBreaker;
    }

    /**
     * Retrieve the gateway configuration object from the concrete gateway implementation.
     */
    protected abstract function getGatewayConfig(): ?object;

    /**
     * Validate and canonicalize the internal payment amount contract.
     * Payment services pass IRT/Toman as a decimal string, while these Iranian
     * providers accept whole Toman units. Invalid, fractional, zero and negative
     * values are rejected before any external call.
     */
    protected function normalizePositiveTomanAmount(string $amount): string
    {
        $amount = trim($amount);
        if (preg_match('/^(?:0|[1-9]\d*)(?:\.\d+)?$/', $amount) !== 1
            || bccomp($amount, '0', 4) <= 0) {
            throw new \InvalidArgumentException('Amount must be a positive numeric Toman value');
        }

        $whole = bcadd($amount, '0', 0);
        if (bccomp($amount, $whole, 4) !== 0) {
            throw new \InvalidArgumentException('Amount must use whole Toman units');
        }

        return $whole;
    }

    /**
     * Retrieve an optional gateway callback secret for HMAC signature verification.
     */
    protected function getCallbackSecret(): ?string
    {
        $config = $this->getGatewayConfig();
        if ($config !== null) {
            if (isset($config->callback_secret) && $config->callback_secret !== '') {
                return (string)$config->callback_secret;
            }
            if (isset($config->config) && is_string($config->config)) {
                $parsed = json_decode($config->config, true);
                if (is_array($parsed) && !empty($parsed['callback_secret'])) {
                    return strval($parsed['callback_secret']);
                }
            }
        }

        $configuredSecret = config('payment.' . $this->getGatewayName() . '.callback_secret');
        return is_string($configuredSecret) && $configuredSecret !== '' ? $configuredSecret : null;
    }

    /**
     * Verify a generic callback signature using HMAC-SHA256.
     */
    /** @param array<string, mixed> $callbackData */
    protected function verifyCallbackSignature(array $callbackData, string $secret): bool
    {
        $signatureRaw = $callbackData['signature'] ?? $callbackData['sign'] ?? '';
        $signature = is_scalar($signatureRaw) ? (string) $signatureRaw : '';
        if ($signature === '') {
            return false;
        }

        $payload = [];
        if (isset($callbackData['Authority'])) {
            $payload[] = $callbackData['Authority'];
        } elseif (isset($callbackData['authority'])) {
            $payload[] = $callbackData['authority'];
        }

        if (isset($callbackData['Status'])) {
            $payload[] = $callbackData['Status'];
        } elseif (isset($callbackData['status'])) {
            $payload[] = $callbackData['status'];
        }

        if (isset($callbackData['amount'])) {
            $payload[] = is_scalar($callbackData['amount']) ? (string) $callbackData['amount'] : '';
        } elseif (isset($callbackData['Amount'])) {
            $payload[] = is_scalar($callbackData['Amount']) ? (string) $callbackData['Amount'] : '';
        }

        $data = implode('|', $payload);
        return hash_equals(hash_hmac('sha256', $data, $secret), $signature);
    }

    /**
     * H-07: آیا این درگاه callback را امضا می‌کند؟
     * اکثر درگاه‌های ایرانی (NextPay/DgPay/ZarinPal/IDPay) callback را امضا نمی‌کنند و صحتِ
     * تراکنش با تأیید سمت‌سرور (verifyPayment) به‌همراه تطابق nonce احراز می‌شود. adapterهایی که
     * واقعاً امضا/HMAC ارسال می‌کنند باید این متد را override کرده و true برگردانند.
     */
    protected function usesSignedCallbacks(): bool
    {
        return false;
    }

    public function verifyCallback(array $callbackData): bool
    {
        $secret = $this->getCallbackSecret();

        // H-07: بررسی امضا فقط زمانی «مورد انتظار» است که درگاه امضا می‌فرستد، یا payload
        // حاوی فیلد امضاست، یا کلید callback پیکربندی شده است. در غیر این صورت نباید callbackِ
        // معتبر را صرفاً به‌دلیل نبودِ امضا رد کرد و نه بررسی امضا را سراسری غیرفعال ساخت؛
        // اعتبارسنجی به nonce (H-06) و تأیید سمت‌سرورِ verifyPayment واگذار می‌شود.
        $signatureFieldRaw = $callbackData['signature'] ?? $callbackData['sign'] ?? '';
        $hasSignatureField = (is_scalar($signatureFieldRaw) ? (string) $signatureFieldRaw : '') !== '';
        $signatureExpected = $this->usesSignedCallbacks()
            || $hasSignatureField
            || ($secret !== null && $secret !== '');

        if (!$signatureExpected) {
            $this->logger->info("payment.{$this->getGatewayName()}.callback_signature_not_applicable", [
                'gateway' => $this->getGatewayName(),
            ]);
            return true;
        }

        $isProduction = (env('APP_ENV') === 'production');
        
        // 🛡️ در محیط تولید، وجود و قدرت کلید الزامی و حیاتی است
        if ($isProduction) {
            if ($secret === null || $secret === '') {
                $this->logger->critical("payment.{$this->getGatewayName()}.callback_hmac_key_missing_production", [
                    'gateway' => $this->getGatewayName(),
                    'message' => 'Callback secret MUST be configured in production!'
                ]);
                throw new \RuntimeException("Callback secret is REQUIRED in production for gateway: {$this->getGatewayName()}");
            }
            
            // 🔒 بررسی طول کلید برای جلوگیری از حملات Brute-force امضا (حداقل ۳۲ کاراکتر)
            if (strlen((string)$secret) < 32) {
                $this->logger->critical("payment.{$this->getGatewayName()}.callback_hmac_key_weak", [
                    'gateway' => $this->getGatewayName(),
                    'length' => strlen((string)$secret)
                ]);
                throw new \RuntimeException("Callback secret is too weak (must be at least 32 characters) for gateway: {$this->getGatewayName()}");
            }
        }
        
        // 🚧 طبق سناریوی PG-05، حتی در محیط توسعه و تست نیز وجود و قدرت کلید WEBHOOK_SECRET الزامی است و نباید با mock_secret پذیرفته شود
        if ($secret === null || $secret === '') {
            $this->logger->critical("payment.{$this->getGatewayName()}.callback_hmac_key_missing", [
                'gateway' => $this->getGatewayName()
            ]);
            throw new \RuntimeException("WEBHOOK_SECRET is completely empty or missing for gateway: {$this->getGatewayName()}");
        }
        
        return $this->verifyCallbackSignature($callbackData, $secret);
    }

    /**
     * CURL request کو retry کے ساتھ چلائیں
     * 
     * @param string $url درگاہ کا URL
     * @param array<string, mixed> $data بھیجنے کا ڈیٹا
     * @param string $method HTTP method (POST, GET, etc.)
     * @param list<string> $headers اضافی headers
     * @param string $contentType 'json' or 'form'
     * @return array<string, mixed> Response
     * @throws PaymentGatewayConnectionException
     */
    protected function executeWithRetry(
        string $url,
        array $data = [],
        string $method = 'POST',
        array $headers = [],
        string $contentType = 'json'
    ): array {
        $context = 'service:payment_gateway:' . $this->getGatewayName();
        if (!$this->isExternalUrlSafe($url)) {
            $this->logger->critical("payment.{$this->getGatewayName()}.unsafe_gateway_url_blocked", [
                'host' => parse_url($url, PHP_URL_HOST),
                'scheme' => parse_url($url, PHP_URL_SCHEME),
            ]);
            throw new PaymentGatewayConnectionException('Unsafe payment gateway URL blocked by SSRF policy', $this->getGatewayName());
        }
        
        try {
            $result = $this->retryPolicy->executeWithContext(
                $context,
                fn() => $this->makeCurlRequest($url, $data, $method, $headers, $contentType),
                $this->retryableExceptions
            );
            if (!is_array($result)) {
                throw new \UnexpectedValueException('Payment HTTP transport returned a non-array response.');
            }
            return $result;
        } catch (\Exception $e) {
            $this->logger->error("payment.{$this->getGatewayName()}.request_failed", [
                'error' => $e->getMessage(),
                'url' => $url,
                'attempt' => 'exhausted',
                'context' => $context
            ]);
            \App\Services\Sentry\SentryExceptionHandler::captureException($e, null, [
                'operation' => 'payment.gateway_http_request',
                'gateway'   => $this->getGatewayName(),
                'url'       => $url,
            ]);
            throw new PaymentGatewayConnectionException(
                "Failed to connect to {$this->getGatewayName()} after retries: " . $e->getMessage(),
                $this->getGatewayName()
            );
        }
    }

    /**
     * CURL request کو execute کریں
     * 
     * @param string $url
     * @param array<string, mixed> $data
     * @param string $method
     * @param list<string> $headers
     * @param string $contentType 'json' | 'form'
     * @return array<string, mixed>
     * @throws \Exception
     */
    private function makeCurlRequest(
        string $url,
        array $data,
        string $method,
        array $headers,
        string $contentType = 'json'
    ): array {
        $ch = \curl_init($url);
        $method = strtoupper((string)$method);

        try {
            // 🔒 SSL/TLS Security Options
            \curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
            \curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);

            // ⏱️ Optimized Timeout Options (Reduced from 30s to 20s max for UI response preservation)
            \curl_setopt($ch, CURLOPT_TIMEOUT, 20);
            \curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 8);

            // 📝 Request Options
            \curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            \curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);

            // 🚀 Safe Non-GET Body Distribution Handling
            $body = '';
            $mimeType = 'application/json';
            if ($contentType === 'form') {
                $body = \http_build_query($data);
                $mimeType = 'application/x-www-form-urlencoded';
            } else {
                $body = \json_encode($data);
            }

            if ($method === 'POST') {
                \curl_setopt($ch, CURLOPT_POST, true);
                \curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
            } elseif ($method !== 'GET') {
                \curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
                if (!empty($data)) {
                    \curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
                }
            }

            // 📨 Headers
            $defaultHeaders = [
                "Content-Type: {$mimeType}",
                'Accept: application/json',
                'User-Agent: ChortkePaymentClient/1.0'
            ];
            $allHeaders = array_merge($defaultHeaders, trace_headers(), $headers);
            \curl_setopt($ch, CURLOPT_HTTPHEADER, $allHeaders);

            // Execute request
            $response = \curl_exec($ch);
            $httpCode = (int)\curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = \curl_error($ch);

            \curl_close($ch);

            // Check for CURL errors (connection failures, timeouts, etc.)
            if ($curlError) {
                $this->logRetryAttempt($curlError, $httpCode);
                throw new \RuntimeException("CURL Error: {$curlError}");
            }

            // Check for server errors (should retry)
            if (in_array($httpCode, $this->retryableStatusCodes)) {
                $this->logRetryAttempt("HTTP {$httpCode} - Retrying", $httpCode);
                throw new \RuntimeException("HTTP {$httpCode} - Retryable Server Error");
            }

            // Check for client errors (should not retry)
            if ($httpCode >= 400 && $httpCode < 500 && !in_array($httpCode, $this->retryableStatusCodes)) {
                $this->logger->warning("payment.{$this->getGatewayName()}.client_error", [
                    'http_code' => $httpCode,
                    'response' => $response
                ]);
                throw new PermanentFailure("HTTP {$httpCode} - خطای درگاه پرداخت (بدون تلاش مجدد)", $httpCode);
            }

            // 🔍 Parse & Audit JSON integrity via strict error checks
            $result = \json_decode(is_string($response) ? $response : '{}', true);
            if (\json_last_error() !== JSON_ERROR_NONE) {
                $this->logger->error("payment.{$this->getGatewayName()}.json_parse_error", [
                    'error' => \json_last_error_msg(),
                    'raw' => substr((string)$response, 0, 250)
                ]);
                throw new \RuntimeException("Malformed JSON response from gateway: " . \json_last_error_msg());
            }

            return [
                'success' => $httpCode >= 200 && $httpCode < 300,
                'http_code' => $httpCode,
                'data' => $result,
                'raw_response' => $response
            ];

        } catch (\Exception $e) {
            // 🛡️ H08 Fix: Replace deprecated is_resource() with instanceof CurlHandle for PHP 8.1+ compatibility
            if ($ch instanceof \CurlHandle) {
                \curl_close($ch);
            }
            throw $e;
        }
    }

    /**
     * Retry attempt کو log کریں
     * 
     * @param string $reason
     * @param int $httpCode
     */
    private function logRetryAttempt(string $reason, int $httpCode): void
    {
        $this->logger->debug("payment.{$this->getGatewayName()}.retry_attempt", [
            'reason' => $reason,
            'http_code' => $httpCode
        ]);
    }

    /**
     * درگاہ کا نام حاصل کریں
     */
    abstract public function getGatewayName(): string;

    /**
     * CircuitBreaker کے ساتھ درخواست کو execute کریں
     * 
     * یہ method CircuitBreaker کے ذریعے درخواستوں کو wrap کرتا ہے تاکہ:
     * - مسلسل ناکامیوں سے بچا جا سکے (Cascading Failures)
     * - درگاہ کے بند ہونے کی فوری صورت میں fast fail کریں
     * - سسٹم کو بوجھ سے بچائے
     * 
     * @param string $url درگاہ کا URL
     * @param array<string, mixed> $data بھیجنے کا ڈیٹا
     * @param string $method HTTP method
     * @param list<string> $headers اضافی headers
     * @param string $contentType 'json' or 'form'
     * @return array<string, mixed> Response
     * @throws PaymentGatewayConnectionException
     */
    protected function executeWithCircuitBreaker(
        string $url,
        array $data = [],
        string $method = 'POST',
        array $headers = [],
        string $contentType = 'json'
    ): array {
        $gatewayName = 'payment_gateway:' . $this->getGatewayName();
        
        $result = $this->circuitBreaker->call($gatewayName, function() use ($url, $data, $method, $headers, $contentType) {
            return $this->executeWithRetry($url, $data, $method, $headers, $contentType);
        });
        if (!is_array($result)) {
            throw new \UnexpectedValueException('Payment circuit breaker returned a non-array response.');
        }
        return $result;
    }
}
