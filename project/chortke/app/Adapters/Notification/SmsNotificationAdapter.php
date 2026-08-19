<?php

namespace App\Adapters\Notification;

use Core\Logger;
use Core\CircuitBreaker;
use App\Models\User;
use App\Traits\ExternalCallTrait;
use App\Traits\ValidatesExternalUrl;

/**
 * SmsNotificationAdapter — ارسال پیامک برای نوتیفیکیشن‌های فوری
 *
 * ─── وضعیت فعلی ────────────────────────────────────────────────────────────
 *  آماده‌سازی برای اتصال به پنل پیامکی — پنل هنوز انتخاب نشده.
 *  برای فعال‌سازی، متد sendViaSmsProvider() را با SDK پنل موردنظر پر کنید.
 *
 * ─── تنظیمات .env مورد نیاز (بعد از اتصال) ──────────────────────────────
 *  SMS_PROVIDER=kavenegar        # kavenegar | melipayamak | idehpayam
 *  SMS_API_KEY=your-api-key
 *  SMS_FROM=1000...              # شماره فرستنده
 *  SMS_ENABLED=false
 *
 * ─── Section 8.3/8.4 ─────────────────────────────────────────────────────
 *  متدهای provider-specific (sendKavenegar/sendMelipayamak/sendIdehpayam)
 *  در صورت خطای انتقال یا HTTP، یک Failure استاندارد throw می‌کنند تا
 *  CircuitBreaker و retryTransient (در send()) بتوانند به‌درستی رفتار کنند:
 *
 *    - HTTP 408/429/5xx یا curl errno (timeout/DNS/reset) → Transient/Provider/RateLimited
 *      (retry با backoff نمایی، در صورت تکرار خطا CB ترک می‌خورد)
 *    - HTTP 4xx یا خطای منطقی provider (api_error) → PermanentFailure
 *      (بدون retry، شکست بازگردانده می‌شود)
 */
class SmsNotificationAdapter
{
    use ExternalCallTrait;
    use ValidatesExternalUrl;

    private \App\Services\Notification\NotificationOrchestrator $orchestrator;
    private User   $userModel;
    private \App\Contracts\LoggerInterface $logger;
    /**
     * @internal exposed for ExternalCallTrait::resolveCircuitBreaker()
     */
    protected CircuitBreaker $circuit;
    private \App\Contracts\OutboxServiceInterface $outbox;
    private \Core\Queue $queue;
    private bool   $enabled;
    private string $provider;
    private string $apiKey;
    private string $from;

    public function __construct(
        User $userModel,
        Logger $logger,
        CircuitBreaker $circuit,
        \App\Services\Notification\NotificationOrchestrator $orchestrator,
        \App\Contracts\OutboxServiceInterface $outbox,
        \Core\Queue $queue
    ) {
        $this->orchestrator = $orchestrator;
        $this->logger       = $this->orchestrator->logger();
        unset($logger, $circuit);
        $this->circuit      = $this->orchestrator->circuitBreaker();
        
        $this->userModel = $userModel;
        $this->outbox   = $outbox;
        $this->queue    = $queue;
        $this->enabled  = (bool)config('services.sms.enabled', false);
        $provider = config('services.sms.provider', '');
        $apiKey = config('services.sms.api_key', '');
        $from = config('services.sms.from', '');
        $this->provider = is_string($provider) ? $provider : '';
        $this->apiKey = is_string($apiKey) ? $apiKey : '';
        $this->from = is_string($from) ? $from : '';
    }

    public function sendToUser(int $userId, string $message): bool
    {
        try {
            $user = $this->userModel->find((int)$userId);
            if (!$user || empty($user->mobile)) {
                $this->logger->warning('sms.user_missing_mobile', ['user_id' => $userId]);
                return false;
            }

            return $this->send((string)$user->mobile, $message);
        } catch (\Throwable $e) {
            $this->logger->error('sms.send_to_user_failed', [
                'user_id' => $userId,
                'error'   => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * ارسال پیامک
     *
     * @param  string $mobile  شماره موبایل (مثلاً 09121234567)
     * @param  string $message متن پیامک
     * @return bool
     */
    public function send(string $mobile, string $message): bool
    {
        if (!$this->enabled) {
            $this->logger->info('sms.disabled', ['mobile' => $this->maskMobile($mobile)]);
            return false;
        }

        if (!$this->isValidMobile($mobile)) {
            $this->logger->warning('sms.invalid_mobile', ['mobile' => $this->maskMobile($mobile)]);
            return false;
        }

        try {
            $providerName = 'sms_' . ($this->provider !== '' ? $this->provider : 'unknown');
            $result = (bool) $this->callWithBreaker($providerName, function () use ($mobile, $message): bool {
                // retry روی خطاهای transient (timeout, 5xx, 429)
                return $this->retryTransient(function () use ($mobile, $message): bool {
                    return $this->sendViaSmsProvider($mobile, $message);
                }, 3, 300, 3000);
            }, function (\Core\Exceptions\CircuitBreakerOpenException $e) use ($mobile, $message) {
                // Fallback: If circuit is open, queue the SMS instead of dropping it
                $this->logger->warning('sms.circuit_open_fallback_to_queue', ['mobile' => $this->maskMobile($mobile)]);
                
                // Outbox-First: circuit open → outbox record → publisher retry
                try {
                    $this->outbox->record('notification', 0, 'notification.sms.circuit_retry', [
                        'job' => 'App\\Jobs\\SendSmsJob',
                        'notification' => ['mobile' => $mobile, 'message' => $message],
                    ]);
                    return true;
                } catch (\Throwable $outboxErr) {
                    // Last resort: queue مستقیم
                    try {
                        $this->queue->push('App\\Jobs\\SendSmsJob', [
                            'mobile' => $mobile,
                            'message' => $message
                        ], 'notifications', 60);
                        return true;
                    } catch (\Throwable $qe) {
                        $this->logger->error('sms.all_fallbacks_failed', ['error' => $qe->getMessage()]);
                        return false;
                    }
                }
            });

            $this->logger->info('sms.sent', [
                'mobile'   => $this->maskMobile($mobile),
                'provider' => $this->provider,
                'success'  => $result,
            ]);

            return $result;

        } catch (\Core\Exceptions\PermanentFailure $e) {
            $this->logger->warning('sms.permanent_failure', [
                'mobile' => $this->maskMobile($mobile),
                'error'  => $e->getMessage(),
            ]);
            return false;
        } catch (\Throwable $e) {
            $this->logger->error('sms.send_failed', [
                'mobile' => $this->maskMobile($mobile),
                'class'  => get_class($e),
                'error'  => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * پیامک هشدار امنیتی (OTP / login alert)
     */
    public function sendSecurityAlert(string $mobile, string $message): bool
    {
        return $this->send($mobile, "هشدار امنیتی چرتکه:\n{$message}");
    }

    public function sendSecurityAlertToUser(int $userId, string $message): bool
    {
        try {
            $user = $this->userModel->find((int)$userId);
            if (!$user || empty($user->mobile)) return false;
            return $this->sendSecurityAlert((string)$user->mobile, $message);
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * پیامک تأیید برداشت
     */
    public function sendWithdrawalAlert(string $mobile, string|int $amount, string $currency): bool
    {
        $msg = "برداشت {$amount} {$currency} از حساب چرتکه شما پردازش شد.";
        return $this->send($mobile, $msg);
    }

    public function sendWithdrawalAlertToUser(int $userId, string|int $amount, string $currency): bool
    {
        try {
            $user = $this->userModel->find((int)$userId);
            if (!$user || empty($user->mobile)) return false;
            return $this->sendWithdrawalAlert((string)$user->mobile, $amount, $currency);
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * بررسی فعال بودن سرویس
     */
    public function isEnabled(): bool
    {
        return $this->enabled && !empty($this->apiKey) && !empty($this->provider);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Internal — اتصال به پنل (پر کردن این متد بعد از انتخاب پنل)
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * @throws \Core\Exceptions\TransientException|\Core\Exceptions\PermanentFailure|\Core\Exceptions\ProviderUnavailable|\Core\Exceptions\RateLimitedFailure
     */
    private function sendViaSmsProvider(string $mobile, string $message): bool
    {
        return match ($this->provider) {
            'kavenegar'   => $this->sendKavenegar($mobile, $message),
            'melipayamak' => $this->sendMelipayamak($mobile, $message),
            'idehpayam'   => $this->sendIdehpayam($mobile, $message),
            default       => throw new \Core\Exceptions\PermanentFailure(
                "SMS provider not configured: '{$this->provider}'"
            ),
        };
    }

    /**
     * @throws \Throwable Failure standardized
     */
    private function sendKavenegar(string $mobile, string $message): bool
    {
        if (empty($this->apiKey)) {
            $this->logger->error('sms.kavenegar.missing_apikey');
            throw new \Core\Exceptions\PermanentFailure('Kavenegar API key missing');
        }

        $baseUrl = config('services.sms.kavenegar_base_url', 'https://api.kavenegar.com');
        $baseUrl = rtrim(is_string($baseUrl) ? $baseUrl : 'https://api.kavenegar.com', '/');
        $url = "{$baseUrl}/v1/{$this->apiKey}/sms/send.json";
        $this->assertExternalUrlSafe($url, 'sms provider');
        $sender = !empty($this->from) ? $this->from : '10008663';

        $params = [
            'receptor' => $mobile,
            'sender'   => $sender,
            'message'  => $message
        ];

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query($params),
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_CONNECTTIMEOUT => 5,
        ]);

        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $errno    = (int) curl_errno($ch);
        curl_close($ch);

        if ($httpCode === 200 && is_string($response) && $response !== '') {
            $decoded = json_decode($response, true);
            $data = is_array($decoded) ? $decoded : [];
            $returnData = is_array($data['return'] ?? null) ? $data['return'] : [];
            $status = is_numeric($returnData['status'] ?? null) ? (int)$returnData['status'] : 0;
            if ($status === 200) {
                return true;
            }
            $this->logger->error('sms.kavenegar.api_error', ['status' => $status, 'msg' => $data['return']['message'] ?? '']);
            // Kavenegar status code-های نوع 4xx/5xx مستندند:
            // 401-414 → permanent (auth, validation, balance), 5xx → transient
            if ($status >= 500) {
                throw new \Core\Exceptions\ProviderUnavailable("Kavenegar transient error status={$status}");
            }
            throw new \Core\Exceptions\PermanentFailure("Kavenegar api_error status={$status}");
        }

        $this->logger->error('sms.kavenegar.http_error', ['http_code' => $httpCode, 'errno' => $errno]);
        throw $this->classifyHttpFailure($httpCode, $errno, (string)$response, ['provider' => 'kavenegar']);
    }

    /**
     * @throws \Throwable Failure standardized
     */
    private function sendMelipayamak(string $mobile, string $message): bool
    {
        if (empty($this->apiKey)) {
            $this->logger->error('sms.melipayamak.missing_apikey');
            throw new \Core\Exceptions\PermanentFailure('Melipayamak API key missing');
        }

        $baseUrl = config('services.sms.melipayamak_base_url', 'https://rest.payamak-panel.com');
        $baseUrl = rtrim(is_string($baseUrl) ? $baseUrl : 'https://rest.payamak-panel.com', '/');
        $url = $baseUrl . '/api/SendSMS/SendSMS';
        $this->assertExternalUrlSafe($url, 'sms provider');
        $username = config('services.sms.username', '');

        $params = [
            'username' => $username,
            'password' => $this->apiKey,
            'to'       => $mobile,
            'from'     => $this->from,
            'text'     => $message,
            'isFlash'  => false
        ];

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query($params),
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_CONNECTTIMEOUT => 5,
        ]);

        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $errno    = (int) curl_errno($ch);
        curl_close($ch);

        if ($httpCode === 200 && is_string($response) && $response !== '') {
            $decoded = json_decode($response, true);
            $data = is_array($decoded) ? $decoded : [];
            if (is_numeric($data['RetStatus'] ?? null) && (int)$data['RetStatus'] === 1) {
                return true;
            }
            $this->logger->error('sms.melipayamak.api_error', ['data' => $data]);
            // Melipayamak RetStatus != 1 → معمولاً خطای منطقی permanent
            throw new \Core\Exceptions\PermanentFailure('Melipayamak api_error RetStatus=' . ($data['RetStatus'] ?? 'null'));
        }

        $this->logger->error('sms.melipayamak.http_error', ['http_code' => $httpCode, 'errno' => $errno]);
        throw $this->classifyHttpFailure($httpCode, $errno, (string)$response, ['provider' => 'melipayamak']);
    }

    /**
     * @throws \Throwable Failure standardized
     */
    private function sendIdehpayam(string $mobile, string $message): bool
    {
        if (empty($this->apiKey)) {
            $this->logger->error('sms.idehpayam.missing_apikey');
            throw new \Core\Exceptions\PermanentFailure('Idehpayam API key missing');
        }

        $baseUrl = config('services.sms.idehpayam_base_url', 'https://panel.idehpayam.com');
        $baseUrl = rtrim(is_string($baseUrl) ? $baseUrl : 'https://panel.idehpayam.com', '/');
        $url = $baseUrl . '/api/v1/sms/send/simple';
        $this->assertExternalUrlSafe($url, 'sms provider');
        $params = [
            'receptor' => $mobile,
            'sender'   => $this->from,
            'message'  => $message
        ];

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($params),
            CURLOPT_HTTPHEADER     => [
                'ApiKey: ' . $this->apiKey,
                'Content-Type: application/json'
            ],
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_CONNECTTIMEOUT => 5,
        ]);

        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $errno    = (int) curl_errno($ch);
        curl_close($ch);

        if ($httpCode === 200 && is_string($response) && $response !== '') {
            $data = (array)(json_decode(is_string($response) ? $response : "", true) ?? []);
            if (isset($data['status']) && $data['status'] === 'success') {
                return true;
            }
            $this->logger->error('sms.idehpayam.api_error', ['data' => $data]);
            throw new \Core\Exceptions\PermanentFailure('Idehpayam api_error status=' . ($data['status'] ?? 'null'));
        }

        $this->logger->error('sms.idehpayam.http_error', ['http_code' => $httpCode, 'errno' => $errno]);
        throw $this->classifyHttpFailure($httpCode, $errno, (string)$response, ['provider' => 'idehpayam']);
    }

    private function isValidMobile(string $mobile): bool
    {
        return (bool)preg_match('/^09[0-9]{9}$/', $mobile);
    }

    private function maskMobile(string $mobile): string
    {
        return substr($mobile, 0, 4) . '****' . substr($mobile, -3);
    }
}
