<?php

declare(strict_types=1);

namespace App\Services\Payment;

use App\Models\PaymentGateway;
use App\Contracts\LoggerInterface;
use App\Exceptions\PaymentGatewayConnectionException;
use Core\CircuitBreaker;
use App\Exceptions\PaymentVerificationException;

/**
 * DgPayGateway - درگاه دی‌جی‌پی
 * 
 * یک درگاه پیمنٹ ایرانی قابل اعتماد۔
 * 
 * نوٹ: Amount Rial میں ہے لیکن DgPay Toman میں چاہتا ہے (divide by 10)
 * 
 * Retry Strategy:
 * - Connection timeouts: Retry 3x with exponential backoff
 * - Server errors (5xx): Retry 3x with exponential backoff
 * - Invalid merchant key (4xx): Do NOT retry
 */
class DgPayGateway extends BasePaymentGateway
{
    private ?\stdClass $config;

    public function __construct(
        \App\Models\PaymentGateway $paymentGatewayModel,
        CircuitBreaker $circuitBreaker,
        \App\Contracts\LoggerInterface $logger
    ) {
        parent::__construct($logger, $circuitBreaker);
        $this->config = $paymentGatewayModel->getActiveGateway('dgpay');
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
     * - Invalid merchant key: ❌ Do not retry
     */
    public function createPayment(string $amount, string $description, string $callbackUrl, array $options = []): array
    {
        if (!$this->config) {
            return [
                'success' => false,
                'message' => 'درگاه دی‌جی‌پی غیرفعال است'
            ];
        }

        $amount = $this->normalizePositiveTomanAmount($amount);

        // DgPay expects amount in Rial, input is in Toman (IRT)
        // Conversion: Toman → Rial (1 Toman = 10 Rial) via BCMath (Rule #5)
        $data = [
            'merchant' => $this->config->merchant_id,
            'amount' => (int)bcmul(str_value($amount), '10', 0),
            'description' => $description,
            'callback' => $callbackUrl,
            'mobile' => $options['mobile'] ?? '',
        ];

        $url = str_value(config('payment.dgpay.request_url'));

        $headers = [
            'Content-Type: application/json',
        ];

        try {
            // 🔄 Execute with Circuit Breaker + retry and exponential backoff
            $response = $this->executeWithCircuitBreaker($url, $data, 'POST', $headers);

            // DgPay returns 200 on success
            if (!$response['success'] || $response['http_code'] !== 200) {
                return [
                    'success' => false,
                    'message' => 'خطا در اتصال به درگاه'
                ];
            }

            $result = is_array($response['data'] ?? null) ? $response['data'] : [];
            $status = is_string($result['status'] ?? null) ? $result['status'] : '';
            $token = is_scalar($result['token'] ?? null) ? trim((string)$result['token']) : '';

            if ($status === 'success' && $token !== '') {
                $this->logger->info('payment.dgpay.payment_created', [
                    'token' => $token,
                    'amount_toman' => (int)$amount
                ]);

                return [
                    'success' => true,
                    'authority' => $token,
                    'url' => rtrim(str_value(config('payment.dgpay.payment_url')), '/') . '/' . rawurlencode($token),
                    'message' => 'موفق'
                ];
            }

            $providerMessage = $result['message'] ?? null;
            return [
                'success' => false,
                'message' => $status === 'success'
                    ? 'توکن پرداخت نامعتبر است'
                    : (is_string($providerMessage) ? $providerMessage : 'خطای نامشخص')
            ];

        } catch (PaymentGatewayConnectionException $e) {
            // Connection failed after retries
            $this->logger->error('payment.dgpay.connection_failed', ['error' => $e->getMessage()]);
            return [
                'success' => false,
                'message' => 'خطا در برقراری ارتباط با درگاه (بعد از تلاش مجدد)'
            ];
        } catch (\Exception $e) {
            \App\Services\Sentry\SentryExceptionHandler::captureException($e, null, ['operation' => 'payment.dgpay.createPayment']);
            $this->logger->error('payment.dgpay.request_failed', ['error' => $e->getMessage()]);
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
                'message' => 'درگاه دی‌جی‌پی غیرفعال است'
            ];
        }

        // Input validation (should not retry)
        if (empty($authority)) {
            throw new \InvalidArgumentException('Authority cannot be empty');
        }

        $amount = $this->normalizePositiveTomanAmount($amount);

        // Safely forward amount normalized to Rial via BCMath.
        $data = [
            'merchant' => $this->config->merchant_id,
            'token' => $authority,
            'amount' => (int)bcmul(str_value($amount), '10', 0)
        ];

        $url = str_value(config('payment.dgpay.verify_url'));

        $headers = [
            'Content-Type: application/json',
        ];

        try {
            // 🔄 Execute with Circuit Breaker + retry and exponential backoff
            $response = $this->executeWithCircuitBreaker($url, $data, 'POST', $headers);

            // DgPay returns 200 on success
            if (!$response['success'] || $response['http_code'] !== 200) {
                throw new PaymentVerificationException(
                    'Failed to verify payment',
                    $authority,
                    ['http_code' => $response['http_code']]
                );
            }

            $result = is_array($response['data'] ?? null) ? $response['data'] : [];
            $status = is_string($result['status'] ?? null) ? $result['status'] : '';
            $rawRefId = $result['ref_id'] ?? null;
            $refId = is_scalar($rawRefId) ? trim((string)$rawRefId) : '';
            $rawAmount = $result['amount'] ?? null;
            $hasAmount = is_int($rawAmount) || (is_string($rawAmount) && is_numeric($rawAmount));
            $verifiedAmount = $hasAmount ? bcdiv((string)$rawAmount, '10', 0) : '';

            if ($status === 'success' && $refId !== '' && $hasAmount) {
                if (bccomp($verifiedAmount, $amount, 4) !== 0) {
                    return ['success' => false, 'message' => 'مبلغ تأییدشده با مبلغ درخواست مطابقت ندارد'];
                }
                $this->logger->info('payment.dgpay.verified', [
                    'authority' => $authority,
                    'ref_id' => $refId,
                ]);

                return [
                    'success' => true,
                    'ref_id' => $refId,
                    'amount' => $verifiedAmount,
                    'message' => 'پرداخت با موفقیت انجام شد'
                ];
            }

            $providerMessage = $result['message'] ?? null;
            return [
                'success' => false,
                'message' => $status === 'success'
                    ? 'پاسخ تأیید درگاه ناقص است'
                    : (is_string($providerMessage) ? $providerMessage : 'تراکنش ناموفق')
            ];

        } catch (PaymentGatewayConnectionException $e) {
            // Connection failed after retries
            $this->logger->error('payment.dgpay.verification_connection_failed', ['error' => $e->getMessage()]);
            return [
                'success' => false,
                'message' => 'خطا در تأیید پرداخت (خطای شبکه)'
            ];
        } catch (PaymentVerificationException $e) {
            $this->logger->warning('payment.dgpay.verification_failed', ['error' => $e->getMessage()]);
            return [
                'success' => false,
                'message' => 'خطا در تأیید پرداخت'
            ];
        } catch (\Exception $e) {
            \App\Services\Sentry\SentryExceptionHandler::captureException($e, null, ['operation' => 'payment.dgpay.verifyPayment']);
            $this->logger->error('payment.dgpay.verify_failed', ['error' => $e->getMessage()]);
            return [
                'success' => false,
                'message' => 'خطا در تأیید پرداخت'
            ];
        }
    }

    public function refundPayment(string $authority): array
    {
        // DgPay refund support - implement if needed
        return [
            'success' => false,
            'message' => 'بازگشت پرداخت در این درگاه پشتیبانی نمی‌شود'
        ];
    }

    public function getName(): string
    {
        return 'dgpay';
    }

    public function getGatewayName(): string
    {
        return 'dgpay';
    }

    public function isActive(): bool
    {
        return $this->config !== null;
    }
}