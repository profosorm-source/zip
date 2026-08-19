<?php

namespace App\Controllers;

use App\Services\Payment\PaymentService;
use App\Controllers\BaseController;
use App\Validators\Requests\CreateDepositRequest;
use Core\Exceptions\ValidationException;
use Core\Exceptions\NotFoundException;
use Core\Exceptions\BusinessException;

class PaymentController extends BaseController
{
    private PaymentService $paymentService;
    private \Core\Cache $cache;

    public function __construct(
        PaymentService $paymentService,
        \Core\Cache $cache,
        ?\App\Contracts\LoggerInterface $logger = null
    ) {
        parent::__construct(null, null, null, null, $logger);
        $this->paymentService = $paymentService;
        $this->cache = $cache;
    }

    private function stringValue(mixed $value, string $default = ''): string
    {
        return is_scalar($value) ? (string)$value : $default;
    }

    private function positiveIntValue(mixed $value): int
    {
        if (is_int($value)) return max(0, $value);
        return is_string($value) && ctype_digit($value) ? (int)$value : 0;
    }

    /**
     * درخواست پرداخت آنلاین
     */
    public function request(): void
    {
        if ($this->positiveIntValue($this->userId()) === 0) {
            $this->session->setFlash('error', 'ابتدا وارد شوید');
            $this->response->redirect(url('login'));
            return;
        }

        $userId = $this->positiveIntValue($this->userId());

        $source = $this->stringValue($this->request->input('source') ?? $this->request->input('app_source'));
        if ($source === 'mobile') {
            $this->session->set('payment_source', 'mobile');
        } else {
            $this->session->delete('payment_source');
        }

        // دریافت داده‌ها
        $data = [
            'gateway' => $this->request->input('gateway'),
            'amount' => $this->request->input('amount'),
            'idempotency_key' => $this->request->input('idempotency_key') ?: ('dep_' . bin2hex(random_bytes(16))),
        ];

        // اعتبارسنجی با Core\Validator
        $validator = new CreateDepositRequest($data);
        $validator->validate();

        if ($validator->fails()) {
            $errors = $validator->errors();
            $firstError = reset($errors);
            $msg = is_array($firstError) ? reset($firstError) : $firstError;
            $this->session->setFlash('error', $msg ?: 'داده‌های ورودی نامعتبر است');
            $this->response->redirect(url('wallet/deposit'));
            return;
        }

        $validated = $validator->validated();

        try {
            $amount = is_scalar($validated['amount'] ?? null) ? (string)$validated['amount'] : '0';
            $bankCardId = $this->positiveIntValue($this->request->input('bank_card_id'));
            $idempotencyKey = $this->stringValue($validated['idempotency_key'] ?? null);

            $clientIp = $this->request->ip();
            $userAgent = $this->request->userAgent();

            $result = $this->paymentService->create(
                $userId,
                $this->stringValue($data['gateway'] ?? null),
                $amount,
                $bankCardId,
                $idempotencyKey,
                $clientIp,
                $userAgent
            );

            if (empty($result['success']) || empty($result['payment_url'])) {
                $this->session->setFlash('error', $result['message'] ?? 'خطا در اتصال به درگاه پرداخت');
                $this->response->redirect(url('wallet/deposit'));
                return;
            }

            
            $paymentUrl = $this->stringValue($result['payment_url'] ?? null);
            if ($paymentUrl === '') {
                throw new \RuntimeException('Payment gateway did not return a redirect URL.');
            }
            $this->response->redirect($paymentUrl);
} catch (ValidationException $e) {
    $this->session->setFlash('error', 'داده‌های ورودی نامعتبر: ' . implode(', ', $e->getErrors()));
    $this->response->redirect(url('wallet/deposit'));
} catch (NotFoundException $e) {
    $this->session->setFlash('error', $e->getMessage());
    $this->response->redirect(url('wallet/deposit'));
} catch (BusinessException $e) {
    $this->session->setFlash('error', $e->getMessage());
    $this->response->redirect(url('wallet/deposit'));
} catch (\Throwable $e) {
    $this->logger->error('payment.request.failed', [
        'channel' => 'payment',
        'user_id' => $userId,
        'error' => $e->getMessage(),
        'exception' => get_class($e),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
    ]);

    $this->session->setFlash('error', 'خطا در اتصال به درگاه پرداخت');
    $this->response->redirect(url('wallet/deposit'));
}
    }

    /**
     * بازگشت از درگاه پرداخت
     */
    public function callback(): void
    {
        $gateway = $this->stringValue(
            $this->request->get('gateway')
            ?? $this->request->param('gateway')
        );

        $isMobile = ($this->session->get('payment_source') === 'mobile') || 
                    ($this->stringValue($this->request->get('source') ?? $this->request->param('source')) === 'mobile');
        $mobileScheme = $this->stringValue(config('app.mobile.scheme', 'chortke'), 'chortke');

        if ($gateway === '') {
            $this->session->setFlash('error', 'درگاه نامعتبر است');
            if ($isMobile) {
                $this->response->redirect("{$mobileScheme}://wallet/payment-result?status=failed&error=" . urlencode('درگاه نامعتبر است'));
                return;
            }
            $this->response->redirect(url('wallet'));
            return;
        }

        if (!$this->request->isPost()) {
            $this->logger->warning('payment.callback.invalid_method', [
                'gateway' => $gateway,
                'method' => $this->request->method(),
                'ip' => get_client_ip()
            ]);

            $this->response->status(405)->json([
                'success' => false,
                'message' => 'Callback must be delivered via POST request'
            ]);
            return;
        }

        $status = 'failed';
        $result = [];

        try {
            $clientIp = $this->request->ip();
            $userAgent = $this->request->userAgent();

            $result = $this->paymentService->callback(
                $gateway,
                $this->request->all(),
                $this->positiveIntValue($this->userId()),
                $clientIp,
                $userAgent
            );

            if (!empty($result['success'])) {
                $this->session->setFlash('success', $result['message'] ?? 'پرداخت با موفقیت انجام شد');
                $status = 'success';
            } else {
                $this->session->setFlash('error', $result['message'] ?? 'پرداخت ناموفق بود');
                $status = 'failed';
            }
        } catch (\Throwable $e) {
            $this->logger->error('payment.callback.failed', [
                'channel' => 'payment',
                'gateway' => $gateway,
                'error' => $e->getMessage(),
                'exception' => get_class($e),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            $this->session->setFlash('error', 'پرداخت ناموفق بود');
            $status = 'failed';
        }

        if ($isMobile) {
            $transId = $this->stringValue($result['transaction_id'] ?? $result['ref_id'] ?? $this->request->get('track_id'));
            $deepLink = "{$mobileScheme}://wallet/payment-result?status={$status}&gateway=" . urlencode($gateway) . "&trans_id=" . urlencode($transId);
            $this->response->redirect($deepLink);
            return;
        }

        $this->response->redirect(url('wallet'));
    }

    public function callbackGet(): void
    {
        $gateway = $this->stringValue(
            $this->request->param('gateway')
            ?? $this->request->get('gateway'),
            'unknown'
        );

        // Log suspicious activity
        $this->logger->warning('payment.callback.get_attempt_blocked', [
            'gateway' => $gateway,
            'ip' => get_client_ip(),
            'user_agent' => get_user_agent(),
            'referer' => $this->request->header('referer'),
        ]);

        // Block IP after 3 attempts in 1 hour (3600 seconds)
        $ipKey = "callback_get_abuse:" . get_client_ip();
        $attempts = $this->positiveIntValue($this->cache->get($ipKey, 0));
        $this->cache->setSeconds($ipKey, $attempts + 1, 3600);

        if ($attempts >= 3) {
            $this->logger->critical('payment.callback.get_abuse_detected', [
                'ip' => get_client_ip(),
                'attempts' => $attempts + 1
            ]);

            $this->response->status(403)->json([
                'success' => false,
                'message' => 'Access forbidden due to suspicious activity'
            ]);
            return;
        }

        $this->response->status(405)->json([
            'success' => false,
            'message' => 'Method not allowed. Callbacks must use POST.'
        ]);
    }

    /**
     * Master E2E Functional Browser Verification specifically for Section 8.5 Scheduled Payment Operations (SP-01 to SP-04 🆕) Trapped context
     */
}
