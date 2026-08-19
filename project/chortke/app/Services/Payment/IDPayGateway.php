<?php

declare(strict_types=1);

namespace App\Services\Payment;

use App\Models\PaymentGateway;
use App\Contracts\LoggerInterface;
use App\Exceptions\PaymentGatewayConnectionException;
use App\Exceptions\PaymentVerificationException;
use Core\CircuitBreaker;

/**
 * IDPayGateway - درگاه آیدی‌پی
 * 
 * یک درگاه پیمنٹ ایرانی دوم جو سریع ترین رفع العمل کے ساتھ جانا جاتا ہے۔
 * 
 * نوٹ: Amount Rial میں ہے لیکن IDPay Toman میں چاہتا ہے (divide by 10)
 * 
 * Retry Strategy:
 * - Connection timeouts: Retry 3x with exponential backoff
 * - Server errors (5xx): Retry 3x with exponential backoff
 * - Invalid API key (4xx): Do NOT retry
 */
class IDPayGateway extends BasePaymentGateway
{
    /**
     * Centralized toObject (root-cause normalization for DB results).
     * Guarantees object (never array/mixed) before any ->prop access.
     */
    private function toObject(mixed $data): ?object
    {
        if ($data === null || $data === false) return null;
        if (is_object($data)) return $data;
        if (is_array($data)) return (object)$data;
        return (object)(array)$data;
    }


    private ?\stdClass $config;
    private string $apiBaseUrl;
    private PaymentGateway $paymentGatewayModel;

    public function __construct(
        \App\Models\PaymentGateway   $paymentGatewayModel,
        CircuitBreaker               $circuitBreaker,
        \App\Contracts\LoggerInterface $logger
    ) {
        parent::__construct($logger, $circuitBreaker);
        $this->paymentGatewayModel = $paymentGatewayModel;
        $this->config = $paymentGatewayModel->getActiveGateway('idpay');
        $configuredBase = config('payment.idpay.api_url', 'https://api.idpay.ir/v1.1');
        $this->apiBaseUrl = rtrim(is_string($configuredBase) ? $configuredBase : 'https://api.idpay.ir/v1.1', '/');
    }

    protected function getGatewayConfig(): ?object
    {
        return $this->config;
    }

    /**
     * نیا پیمنٹ بنائیں
     * 
     * Retry Logic:
     * - Connection timeouts: ✅ Retry
     * - Server errors (5xx): ✅ Retry
     * - Invalid API key: ❌ Do not retry
     */
    public function createPayment(string $amount, string $description, string $callbackUrl, array $options = []): array
    {
        if (!$this->config) {
            return [
                'success' => false,
                'message' => 'درگاه آیدی‌پی غیرفعال است'
            ];
        }

        // Input validation (should not retry)
        if (bccomp($amount, '0', 4) <= 0) {
            throw new \InvalidArgumentException('Amount must be greater than 0');
        }
        if ($amount <= 0) {
            $this->logger->warning('payment.idpay.invalid_amount', ['amount' => $amount]);
            throw new \InvalidArgumentException('Amount must be greater than 0');
        }

        $orderId = \uniqid('idpay_');

        // IDPay expects amount in Rial, input is in Toman (IRT)
        // Conversion: Toman → Rial (1 Toman = 10 Rial) via BCMath (Rule #5)
        $data = [
            'order_id' => $orderId,
            'amount' => (int)bcmul(str_value($amount), '10', 0),
            'desc' => $description,
            'callback' => $callbackUrl,
            'phone' => $options['mobile'] ?? $options['phone'] ?? '',
            'mail' => $options['email'] ?? '',
        ];

        $url = $this->apiBaseUrl . '/payment';

        $headers = [
            'X-API-KEY: ' . $this->config->api_key,
            'X-SANDBOX: ' . ($this->config->is_test_mode ? '1' : '0')
        ];

        try {
            // 🔄 Execute with Circuit Breaker + retry and exponential backoff
            $response = $this->executeWithCircuitBreaker($url, $data, 'POST', $headers);

            // IDPay returns 201 on success
            if (!$response['success'] || $response['http_code'] !== 201) {
                return [
                    'success' => false,
                    'message' => 'خطا در اتصال به درگاه'
                ];
            }

            $result = is_array($response['data'] ?? null) ? $response['data'] : [];

            $rawId = $result['id'] ?? null;
            $rawLink = $result['link'] ?? null;
            if ((is_string($rawId) || is_int($rawId)) && is_string($rawLink) && $rawLink !== '') {
                $authority = (string)$rawId;
                $this->logger->info('payment.idpay.payment_created', [
                    'id' => $authority,
                    'amount_toman' => (int)$amount
                ]);

                return [
                    'success' => true,
                    'authority' => $authority,
                    'url' => $rawLink,
                    'message' => 'موفق',
                    // 💾 Save order_id to ensure verification retrieves and sends the SAME order_id
                    'order_id' => $orderId 
                ];
            }

            return [
                'success' => false,
                'message' => is_string($result['error_message'] ?? null) ? $result['error_message'] : 'خطای نامشخص'
            ];

        } catch (PaymentGatewayConnectionException $e) {
            // Connection failed after retries
            $this->logger->error('payment.idpay.connection_failed', ['error' => $e->getMessage()]);
            return [
                'success' => false,
                'message' => 'خطا در برقراری ارتباط با درگاه (بعد از تلاش مجدد)'
            ];
        } catch (\Exception $e) {
            \App\Services\Sentry\SentryExceptionHandler::captureException($e, null, ['operation' => 'payment.idpay.createPayment']);
            $this->logger->error('payment.idpay.request_failed', ['error' => $e->getMessage()]);
            return [
                'success' => false,
                'message' => 'خطا در برقراری ارتباط با درگاه'
            ];
        }
    }

    /**
     * پرداخت کی تصدیق کریں
     * 
     * Retry Logic:
     * - Connection timeouts: ✅ Retry
     * - Server errors (5xx): ✅ Retry
     * - Invalid transaction: ❌ Do not retry
     */
    public function verifyPayment(string $authority, string $amount): array
    {
        if (!$this->config) {
            return [
                'success' => false,
                'message' => 'درگاه آیدی‌پی غیرفعال است'
            ];
        }

        // Input validation (should not retry)
        if (empty($authority)) {
            throw new \InvalidArgumentException('Authority cannot be empty');
        }

        if (bccomp($amount, '0', 4) <= 0) {
        }

        if ($amount <= 0) {
            throw new \InvalidArgumentException('Amount must be greater than 0');
        }

        // 🕵️ Stateful Matching: Retrieve the EXACT same order_id originally sent during createPayment
        $orderId = null;
        try {
            $log = $this->toObject($this->paymentGatewayModel->getDb()->table('payment_logs')
                ->where('authority', '=', $authority)
                ->first());

            if ($log && isset($log->id) && !empty($log->response_data)) {
                $savedRes = \json_decode((string)$log->response_data, true);
                $orderId = is_array($savedRes) && is_scalar($savedRes['order_id'] ?? null)
                    ? (string)$savedRes['order_id']
                    : null;
            }
        } catch (\Throwable $e) {
            \App\Services\Sentry\SentryExceptionHandler::captureException($e, null, ['operation' => 'payment.idpay.verifyPayment']);
            $this->logger->error('payment.idpay.order_id_lookup_exception', ['error' => $e->getMessage()]);
        }

        // Failover to current tracking ID if historical record lookup falls short
        if (!$orderId) {
            $orderId = $authority;
        }

        $data = [
            'id' => $authority,
            'order_id' => $orderId,
        ];

        $url = $this->apiBaseUrl . '/payment/verify';

        $headers = [
            'X-API-KEY: ' . $this->config->api_key,
            'X-SANDBOX: ' . ($this->config->is_test_mode ? '1' : '0')
        ];

        try {
            // 🔄 Execute with Circuit Breaker + retry and exponential backoff
            $response = $this->executeWithCircuitBreaker($url, $data, 'POST', $headers);

            // IDPay returns 200 on success
            if (!$response['success'] || $response['http_code'] !== 200) {
                throw new PaymentVerificationException(
                    'Failed to verify payment',
                    $authority,
                    ['http_code' => $response['http_code']]
                );
            }

            $result = is_array($response['data'] ?? null) ? $response['data'] : [];

            $rawStatus = $result['status'] ?? null;
            $status = is_int($rawStatus) || (is_string($rawStatus) && is_numeric($rawStatus))
                ? (int)$rawStatus
                : 0;
            if (in_array($status, [100, 101], true)) {
                $rawTrackId = $result['track_id'] ?? null;
                $trackId = is_scalar($rawTrackId) ? (string)$rawTrackId : $authority;
                $this->logger->info('payment.idpay.verified', [
                    'authority' => $authority,
                    'track_id' => $trackId
                ]);

                $rawAmount = $result['amount'] ?? null;
                $verifiedAmount = is_int($rawAmount) || (is_string($rawAmount) && is_numeric($rawAmount))
                    ? bcdiv((string)$rawAmount, '10', 0)
                    : $amount;

                return [
                    'success' => true,
                    'ref_id' => $trackId,
                    'amount' => $verifiedAmount,
                    'message' => 'پرداخت با موفقیت انجام شد'
                ];
            }

            return [
                'success' => false,
                'message' => is_string($result['error_message'] ?? null) ? $result['error_message'] : 'تراکنش ناموفق'
            ];

        } catch (PaymentGatewayConnectionException $e) {
            // Connection failed after retries
            $this->logger->error('payment.idpay.verification_connection_failed', ['error' => $e->getMessage()]);
            return [
                'success' => false,
                'message' => 'خطا در تأیید پرداخت (خطای شبکه)'
            ];
        } catch (PaymentVerificationException $e) {
            $this->logger->warning('payment.idpay.verification_failed', ['error' => $e->getMessage()]);
            return [
                'success' => false,
                'message' => 'خطا در تأیید پرداخت'
            ];
        } catch (\Exception $e) {
            \App\Services\Sentry\SentryExceptionHandler::captureException($e, null, ['operation' => 'payment.idpay.verifyPayment']);
            $this->logger->error('payment.idpay.verify_failed', ['error' => $e->getMessage()]);
            return [
                'success' => false,
                'message' => 'خطا در تأیید پرداخت'
            ];
        }
    }

    public function refundPayment(string $authority): array
    {
        // IDPay refund support - implement if needed
        return [
            'success' => false,
            'message' => 'بازگشت پرداخت در این درگاه پشتیبانی نمی‌شود'
        ];
    }

    public function getName(): string
    {
        return 'idpay';
    }

    public function getGatewayName(): string
    {
        return 'idpay';
    }

    public function isActive(): bool
    {
        return $this->config !== null;
    }
}