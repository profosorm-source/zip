<?php

declare(strict_types=1);

namespace App\Services\Payment;

use App\Models\PaymentGateway;
use App\Contracts\LoggerInterface;
use App\Exceptions\PaymentGatewayConnectionException;
use App\Exceptions\PaymentVerificationException;
use Core\CircuitBreaker;

/**
 * ZarinPalGateway - درگاه زرین‌پال
 * 
 * یک درگاه پیمنٹ ایرانی جو ریئل ٹائم میں پرداخت کی سہولت دیتا ہے۔
 * 
 * Retry Strategy:
 * - Connection timeouts: Retry 3x with exponential backoff
 * - Server errors (5xx): Retry 3x with exponential backoff
 * - Client errors (4xx): Do NOT retry
 */
class ZarinPalGateway extends BasePaymentGateway
{
    private ?\stdClass $config;

    public function __construct(
        \App\Models\PaymentGateway $paymentGatewayModel,
        CircuitBreaker $circuitBreaker,
        \App\Contracts\LoggerInterface $logger
    ) {
        parent::__construct($logger, $circuitBreaker);
        $this->config = $paymentGatewayModel->getActiveGateway('zarinpal');
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
     * - Invalid amount: ❌ Do not retry
     */
    public function createPayment(string $amount, string $description, string $callbackUrl, array $options = []): array
    {
        if (!$this->config) {
            return [
                'success' => false,
                'message' => 'درگاه زرین‌پال غیرفعال است'
            ];
        }

        $amount = $this->normalizePositiveTomanAmount($amount);

        $data = [
            'merchant_id' => $this->config->merchant_id,
            'amount' => $amount,
            'description' => $description,
            'callback_url' => $callbackUrl,
            'metadata' => [
                'mobile' => is_scalar($options['mobile'] ?? null) ? (string)$options['mobile'] : '',
                'email' => is_scalar($options['email'] ?? null) ? (string)$options['email'] : '']
        ];

        $url = $this->config->is_test_mode 
            ? config('payment.zarinpal.sandbox_url') . '/PaymentRequest.json'
            : config('payment.zarinpal.api_url') . '/request.json';

        try {
            // 🔄 Execute with Circuit Breaker + retry and exponential backoff
            $response = $this->executeWithCircuitBreaker($url, $data, 'POST');

            if (!$response['success'] || $response['http_code'] !== 200) {
                return [
                    'success' => false,
                    'message' => 'خطا در اتصال به درگاه'
                ];
            }

            $result = is_array($response['data'] ?? null) ? $response['data'] : [];
            $payload = is_array($result['data'] ?? null) ? $result['data'] : [];
            $rawCode = $payload['code'] ?? null;
            $code = is_int($rawCode) || is_string($rawCode) ? (int)$rawCode : null;
            $rawAuthority = $payload['authority'] ?? null;
            $authority = is_scalar($rawAuthority) ? trim((string)$rawAuthority) : '';

            if ($code === 100 && $authority !== '') {
                $payBase = (bool)$this->config->is_test_mode
                    ? str_value(config('payment.zarinpal.sandbox_pay_url'))
                    : str_value(config('payment.zarinpal.payment_url'));
                $paymentUrl = rtrim($payBase, '/') . '/' . rawurlencode($authority);

                $this->logger->info('payment.zarinpal.payment_created', [
                    'authority' => $authority,
                    'amount' => $amount
                ]);

                return [
                    'success' => true,
                    'authority' => $authority,
                    'url' => $paymentUrl,
                    'message' => 'موفق'
                ];
            }

            $errors = is_array($result['errors'] ?? null) ? $result['errors'] : [];
            $providerMessage = $errors['message'] ?? null;
            return [
                'success' => false,
                'message' => $code === 100
                    ? 'شناسه پرداخت درگاه نامعتبر است'
                    : (is_string($providerMessage) ? $providerMessage : 'خطای نامشخص')
            ];

        } catch (PaymentGatewayConnectionException $e) {
            // Connection failed after retries
            $this->logger->error('payment.zarinpal.connection_failed', ['error' => $e->getMessage()]);
            return [
                'success' => false,
                'message' => 'خطا در برقراری ارتباط با درگاه (بعد از تلاش مجدد)'
            ];
        } catch (\Exception $e) {
            \App\Services\Sentry\SentryExceptionHandler::captureException($e, null, ['operation' => 'payment.zarinpal.createPayment']);
            $this->logger->error('payment.zarinpal.request_failed', ['error' => $e->getMessage()]);
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
                'message' => 'درگاه زرین‌پال غیرفعال است'
            ];
        }

        // Input validation (should not retry)
        if (empty($authority)) {
            throw new \InvalidArgumentException('Authority cannot be empty');
        }

        $amount = $this->normalizePositiveTomanAmount($amount);

        $data = [
            'merchant_id' => $this->config->merchant_id,
            'authority' => $authority,
            'amount' => $amount,
        ];

        $url = $this->config->is_test_mode
            ? config('payment.zarinpal.sandbox_url') . '/PaymentVerification.json'
            : config('payment.zarinpal.api_url') . '/verify.json';

        try {
            // 🔄 Execute with Circuit Breaker + retry and exponential backoff
            $response = $this->executeWithCircuitBreaker($url, $data, 'POST');

            if (!$response['success'] || $response['http_code'] !== 200) {
                throw new PaymentVerificationException(
                    'Failed to verify payment',
                    $authority,
                    ['http_code' => $response['http_code']]
                );
            }

            $result = $response['data'];
            $payload = is_array($result) && isset($result['data']) && is_array($result['data'])
                ? $result['data']
                : null;

            // کدهای 100 و 101 به‌ترتیب «موفق» و «قبلاً تأییدشده» هستند.
            // پاسخ درگاه قبل از هر cast اعتبارسنجی می‌شود تا تغییر schema یا payload
            // نامعتبر هرگز به offset access روی scalar منجر نشود.
            $rawCode = $payload['code'] ?? null;
            $code = is_int($rawCode) || is_string($rawCode) ? (int)$rawCode : 0;
            $rawRefId = $payload['ref_id'] ?? null;
            $hasValidRefId = (is_int($rawRefId) || is_string($rawRefId))
                && (string)$rawRefId !== '';

            $rawVerifiedAmount = $payload['amount'] ?? null;
            $verifiedAmount = is_int($rawVerifiedAmount)
                || is_float($rawVerifiedAmount)
                || is_string($rawVerifiedAmount)
                    ? trim((string)$rawVerifiedAmount)
                    : '';

            if (in_array($code, [100, 101], true)
                && $hasValidRefId
                && is_numeric($verifiedAmount)) {
                if (bccomp($verifiedAmount, $amount, 4) !== 0) {
                    return ['success' => false, 'message' => 'مبلغ تأییدشده با مبلغ درخواست مطابقت ندارد'];
                }
                $refId = (string)$rawRefId;

                $this->logger->info('payment.zarinpal.verified', [
                    'authority' => $authority,
                    'ref_id' => $refId,
                ]);

                return [
                    'success' => true,
                    'ref_id' => $refId,
                    'amount' => $verifiedAmount,
                    'message' => 'پرداخت با موفقیت انجام شد',
                ];
            }

            $errorMessage = is_array($result)
                && isset($result['errors'])
                && is_array($result['errors'])
                && is_string($result['errors']['message'] ?? null)
                    ? $result['errors']['message']
                    : 'تراکنش ناموفق یا شناسه مرجع نامعتبر';

            return [
                'success' => false,
                'message' => $errorMessage,
            ];

        } catch (PaymentGatewayConnectionException $e) {
            // Connection failed after retries
            $this->logger->error('payment.zarinpal.verification_connection_failed', ['error' => $e->getMessage()]);
            return [
                'success' => false,
                'message' => 'خطا در تأیید پرداخت (خطای شبکه)'
            ];
        } catch (PaymentVerificationException $e) {
            $this->logger->warning('payment.zarinpal.verification_failed', ['error' => $e->getMessage()]);
            return [
                'success' => false,
                'message' => 'خطا در تأیید پرداخت'
            ];
        } catch (\Exception $e) {
            \App\Services\Sentry\SentryExceptionHandler::captureException($e, null, ['operation' => 'payment.zarinpal.verifyPayment']);
            $this->logger->error('payment.zarinpal.verify_failed', ['error' => $e->getMessage()]);
            return [
                'success' => false,
                'message' => 'خطا در تأیید پرداخت'
            ];
        }
    }

    public function refundPayment(string $authority): array
    {
        // ZarinPal refund support - implement if needed
        return [
            'success' => false,
            'message' => 'بازگشت پرداخت در این درگاه پشتیبانی نمی‌شود'
        ];
    }

    public function getName(): string
    {
        return 'zarinpal';
    }

    public function getGatewayName(): string
    {
        return 'zarinpal';
    }

    public function isActive(): bool
    {
        return $this->config !== null;
    }
}