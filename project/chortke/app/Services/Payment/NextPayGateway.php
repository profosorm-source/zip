<?php

declare(strict_types=1);

namespace App\Services\Payment;

use App\Models\PaymentGateway;
use App\Contracts\LoggerInterface;
use App\Exceptions\PaymentGatewayConnectionException;
use Core\CircuitBreaker;
use App\Exceptions\PaymentVerificationException;

/**
 * NextPayGateway - درگاه نکست‌پی
 * 
 * یک درگاه پیمنٹ ایرانی سریع و قابل اعتماد۔
 * 
 * نوٹ: Amount Rial میں ہے لیکن NextPay Toman میں چاہتا ہے (divide by 10)
 * 
 * Retry Strategy:
 * - Connection timeouts: Retry 3x with exponential backoff
 * - Server errors (5xx): Retry 3x with exponential backoff
 * - Invalid API key (4xx): Do NOT retry
 */
class NextPayGateway extends BasePaymentGateway
{
    private ?\stdClass $config;

    public function __construct(
        \App\Models\PaymentGateway $paymentGatewayModel,
        CircuitBreaker $circuitBreaker,
        \App\Contracts\LoggerInterface $logger
    ) {
        parent::__construct($logger, $circuitBreaker);
        $this->config = $paymentGatewayModel->getActiveGateway('nextpay');
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
                'message' => 'درگاه نکست‌پی غیرفعال است'
            ];
        }

        $amount = $this->normalizePositiveTomanAmount($amount);

        // NextPay expects amount in Toman, input is in Toman.
        $data = [
            'api_key' => $this->config->api_key,
            'amount' => (int)$amount,
            'order_id' => \uniqid('nextpay_'),
            'callback_uri' => $callbackUrl,
            'customer_phone' => $options['mobile'] ?? '',
        ];

        $url = str_value(config('payment.nextpay.token_url'));

        try {
            // 🔄 Execute with Circuit Breaker + retry and exponential backoff (Using explicitly mapped 'form' payload type)
            $response = $this->executeWithCircuitBreaker($url, $data, 'POST', [], 'form');

            // NextPay returns 200 on success
            if (!$response['success'] || $response['http_code'] !== 200) {
                return [
                    'success' => false,
                    'message' => 'خطا در اتصال به درگاه'
                ];
            }

            $result = is_array($response['data'] ?? null) ? $response['data'] : [];
            $rawCode = $result['code'] ?? null;
            $code = is_int($rawCode) || is_string($rawCode) ? (int)$rawCode : null;
            $rawTransId = $result['trans_id'] ?? null;
            $transId = is_scalar($rawTransId) ? trim((string)$rawTransId) : '';

            if ($code === -1 && $transId !== '') {
                $this->logger->info('payment.nextpay.payment_created', [
                    'trans_id' => $transId,
                    'amount_toman' => (int)$amount
                ]);

                return [
                    'success' => true,
                    'authority' => $transId,
                    'url' => rtrim(str_value(config('payment.nextpay.payment_url')), '/') . '/' . rawurlencode($transId),
                    'message' => 'موفق'
                ];
            }

            return [
                'success' => false,
                'message' => $code === -1 ? 'شناسه تراکنش درگاه نامعتبر است' : 'خطا در ایجاد تراکنش'
            ];

        } catch (PaymentGatewayConnectionException $e) {
            // Connection failed after retries
            $this->logger->error('payment.nextpay.connection_failed', ['error' => $e->getMessage()]);
            return [
                'success' => false,
                'message' => 'خطا در برقراری ارتباط با درگاه (بعد از تلاش مجدد)'
            ];
        } catch (\Exception $e) {
            \App\Services\Sentry\SentryExceptionHandler::captureException($e, null, ['operation' => 'payment.nextpay.createPayment']);
            $this->logger->error('payment.nextpay.request_failed', ['error' => $e->getMessage()]);
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
                'message' => 'درگاه نکست‌پی غیرفعال است'
            ];
        }

        // Input validation (should not retry)
        if (empty($authority)) {
            throw new \InvalidArgumentException('Authority cannot be empty');
        }

        $amount = $this->normalizePositiveTomanAmount($amount);

        // NextPay expects amount in Toman, input is in Toman.
        $data = [
            'api_key' => $this->config->api_key,
            'trans_id' => $authority,
            'amount' => (int)$amount, // Toman amount for verification
        ];

        $url = str_value(config('payment.nextpay.verify_url'));

        try {
            // 🔄 Execute with Circuit Breaker + retry and exponential backoff
            $response = $this->executeWithCircuitBreaker($url, $data, 'POST', [], 'form');

            // NextPay returns 200 on success
            if (!$response['success'] || $response['http_code'] !== 200) {
                throw new PaymentVerificationException(
                    'Failed to verify payment',
                    $authority,
                    ['http_code' => $response['http_code']]
                );
            }

            $result = is_array($response['data'] ?? null) ? $response['data'] : [];
            $rawCode = $result['code'] ?? null;
            $code = is_int($rawCode) || is_string($rawCode) ? (int)$rawCode : null;
            $rawRefId = $result['Shaparak_Ref_Id'] ?? null;
            $refId = is_scalar($rawRefId) ? trim((string)$rawRefId) : '';
            $rawAmount = $result['amount'] ?? null;
            $verifiedAmount = is_int($rawAmount) || is_float($rawAmount) || is_string($rawAmount)
                ? trim((string)$rawAmount)
                : '';

            if ($code === 0 && $refId !== '' && is_numeric($verifiedAmount)) {
                if (bccomp($verifiedAmount, $amount, 4) !== 0) {
                    return ['success' => false, 'message' => 'مبلغ تأییدشده با مبلغ درخواست مطابقت ندارد'];
                }
                $this->logger->info('payment.nextpay.verified', [
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

            return [
                'success' => false,
                'message' => $code === 0 ? 'پاسخ تأیید درگاه ناقص است' : 'تراکنش ناموفق'
            ];

        } catch (PaymentGatewayConnectionException $e) {
            // Connection failed after retries
            $this->logger->error('payment.nextpay.verification_connection_failed', ['error' => $e->getMessage()]);
            return [
                'success' => false,
                'message' => 'خطا در تأیید پرداخت (خطای شبکه)'
            ];
        } catch (PaymentVerificationException $e) {
            $this->logger->warning('payment.nextpay.verification_failed', ['error' => $e->getMessage()]);
            return [
                'success' => false,
                'message' => 'خطا در تأیید پرداخت'
            ];
        } catch (\Exception $e) {
            \App\Services\Sentry\SentryExceptionHandler::captureException($e, null, ['operation' => 'payment.nextpay.verifyPayment']);
            $this->logger->error('payment.nextpay.verify_failed', ['error' => $e->getMessage()]);
            return [
                'success' => false,
                'message' => 'خطا در تأیید پرداخت'
            ];
        }
    }

    public function refundPayment(string $authority): array
    {
        // NextPay refund support - implement if needed
        return [
            'success' => false,
            'message' => 'بازگشت پرداخت در این درگاه پشتیبانی نمی‌شود'
        ];
    }

    public function getName(): string
    {
        return 'nextpay';
    }

    public function getGatewayName(): string
    {
        return 'nextpay';
    }

    public function isActive(): bool
    {
        return $this->config !== null;
    }
}