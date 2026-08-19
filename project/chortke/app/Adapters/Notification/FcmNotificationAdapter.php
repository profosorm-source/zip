<?php

namespace App\Adapters\Notification;

use Core\Logger;
use Core\Cache;
use Core\Database;
use Core\CircuitBreaker;
use App\Contracts\MetricsCollectorInterface;
use App\Traits\ExternalCallTrait;
use App\Traits\ValidatesExternalUrl;

/**
 * FcmNotificationAdapter — ارسال Push Notification با Firebase Cloud Messaging (FCM)
 *
 * ─── تنظیمات .env مورد نیاز ────────────────────────────────────────────────
 *  FCM_SERVICE_ACCOUNT_JSON=/path/to/storage/firebase-service-account.json
 *  FCM_PROJECT_ID=your-firebase-project-id
 *
 * ─── نحوه استفاده ──────────────────────────────────────────────────────────
 *  $fcm->sendToToken($fcmToken, 'عنوان', 'متن', ['key' => 'val']);
 *  $fcm->sendToTokens([$token1, $token2], 'عنوان', 'متن');
 */

class FcmNotificationAdapter
{
    use \App\Traits\ValidatesExternalUrl;
    use ExternalCallTrait;

    private \App\Services\Notification\NotificationOrchestrator $orchestrator;
    private \App\Contracts\LoggerInterface $logger;
    private MetricsCollectorInterface $metrics;
    /** @internal used by ExternalCallTrait */
    protected CircuitBreaker $circuit;
    private Database $db;
    private ?\App\Contracts\OutboxServiceInterface $outbox;
    private ?\Core\Queue $queue;
    private ?string   $projectId;
    private ?string   $serviceAccountPath;
    private string $endpointTemplate;
    private string $oauthUrl;

    private const FCM_ENDPOINT     = 'https://fcm.googleapis.com/v1/projects/%s/messages:send';
    private const OAUTH_ENDPOINT   = 'https://oauth2.googleapis.com/token';
    private const TOKEN_CACHE_KEY  = 'fcm:access_token';
    private const TOKEN_TTL        = 55;   // دقیقه (access token هر ساعت expire می‌شود)


    public function __construct(
        MetricsCollectorInterface $metrics,
        \App\Services\Notification\NotificationOrchestrator $orchestrator,
        Database $db,
        ?\App\Contracts\OutboxServiceInterface $outbox = null,
        ?\Core\Queue $queue = null
    ) {
        $this->orchestrator       = $orchestrator;
        $this->logger             = $this->orchestrator->logger();
        $this->circuit            = $this->orchestrator->circuitBreaker();
        $this->db                 = $db;
        $this->outbox             = $outbox;
        $this->queue              = $queue;
        
        $this->metrics            = $metrics;
        $projectId = config('services.fcm.project_id');
        $serviceAccount = config('services.fcm.service_account_json');
        $this->projectId = is_string($projectId) ? $projectId : null;
        $this->serviceAccountPath = is_string($serviceAccount) ? $serviceAccount : null;
        $endpoint = config('services.fcm.endpoint', self::FCM_ENDPOINT);
        $oauthUrl = config('services.fcm.oauth_url', self::OAUTH_ENDPOINT);
        $this->endpointTemplate = is_string($endpoint) && $endpoint !== '' ? $endpoint : self::FCM_ENDPOINT;
        $this->oauthUrl = is_string($oauthUrl) && $oauthUrl !== '' ? $oauthUrl : self::OAUTH_ENDPOINT;
    }

    /**
     * ارسال به یک token
     */
    /** @param array<string, mixed> $data */
    public function sendToToken(
        string $fcmToken,
        string $title,
        string $body,
        array  $data      = [],
        ?string $imageUrl = null,
        ?string $clickUrl = null
    ): bool {
        if (!$this->isConfigured()) {
            $this->logger->warning('fcm.not_configured');
            return false;
        }

        if ($this->isFcmCircuitOpen()) {
            $this->logger->warning('fcm.circuit_open_skipped', ['token' => substr($fcmToken, 0, 8) . '...']);
            $this->metrics->increment('fcm.circuit.open_skipped');
            return false;
        }

        $payload = $this->buildPayload($title, $body, $data, $imageUrl, $clickUrl);
        $message = is_array($payload['message'] ?? null) ? $payload['message'] : [];
        $message['token'] = $fcmToken;
        $payload['message'] = $message;

        $startTime = microtime(true);
        try {
            $success = $this->dispatch($payload);
            $duration = microtime(true) - $startTime;
            $this->metrics->timing('fcm.dispatch.latency', $duration);

            if ($success) {
                $this->recordFcmSuccess();
                $this->metrics->increment('fcm.send.success');
            } else {
                $this->recordFcmFailure();
                $this->metrics->increment('fcm.send.failure');
            }
            return $success;
        } catch (\Throwable $e) {
            $duration = microtime(true) - $startTime;
            $this->metrics->timing('fcm.dispatch.latency', $duration);
            $this->recordFcmFailure();
            $this->metrics->increment('fcm.send.error');
            throw $e;
        }
    }

    /**
     * ارسال به چند token (batch) - بازطراحی شده با curl_multi جهت حذف گلوگاه I/O مسدودکننده
     */
    /**
     * @param list<string> $fcmTokens
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function sendToTokens(
        array  $fcmTokens,
        string $title,
        string $body,
        array  $data      = [],
        ?string $imageUrl = null,
        ?string $clickUrl = null
    ): array {
        if (!$this->isConfigured() || empty($fcmTokens)) {
            return ['sent' => 0, 'failed' => count($fcmTokens)];
        }

        if ($this->isFcmCircuitOpen()) {
            $this->logger->warning('fcm.circuit_open_skipped_batch');
            $this->metrics->increment('fcm.circuit.open_skipped', ['count' => count($fcmTokens)]);
            return ['sent' => 0, 'failed' => count($fcmTokens)];
        }

        $accessToken = $this->getAccessToken();
        if (!$accessToken) {
            return ['sent' => 0, 'failed' => count($fcmTokens)];
        }

        $sent   = 0;
        $failed = 0;
        $url    = sprintf($this->endpointTemplate, rawurlencode((string)$this->projectId));
        if (!$this->isExternalUrlSafe($url)) {
            $this->logger->critical('fcm.unsafe_endpoint_blocked', ['host' => parse_url($url, PHP_URL_HOST)]);
            return ['sent' => 0, 'failed' => count($fcmTokens)];
        }
        $basePayload = $this->buildPayload($title, $body, $data, $imageUrl, $clickUrl);

        $startTime = microtime(true);

        // پردازش دسته‌ای موازی با curl_multi جهت اجرای بهینه بدون قفل کردن صف‌ها
        foreach (array_chunk($fcmTokens, 50) as $batch) {
            $mh = curl_multi_init();
            $handles = [];

            foreach ($batch as $token) {
                $payload = $basePayload;
                $message = is_array($payload['message'] ?? null) ? $payload['message'] : [];
                $message['token'] = $token;
                $payload['message'] = $message;

                $json = json_encode(array_filter($message, fn($v) => $v !== null), JSON_UNESCAPED_UNICODE);
                $decodedJson = is_string($json) ? json_decode($json, true) : [];
                $bodyJson = json_encode(['message' => is_array($decodedJson) ? $decodedJson : []], JSON_UNESCAPED_UNICODE);

                $ch = curl_init($url);
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_POST           => true,
                    CURLOPT_POSTFIELDS     => $bodyJson,
                    CURLOPT_HTTPHEADER     => [
                        'Authorization: Bearer ' . $accessToken,
                        'Content-Type: application/json',
                    ],
                    CURLOPT_TIMEOUT        => 10,
                    CURLOPT_CONNECTTIMEOUT => 5,
                ]);

                curl_multi_add_handle($mh, $ch);
                $handles[$token] = ['ch' => $ch, 'body' => $bodyJson];
            }

            $active = null;
            do {
                $mrc = curl_multi_exec($mh, $active);
            } while ($mrc === CURLM_CALL_MULTI_PERFORM);

            while ($active && $mrc === CURLM_OK) {
                if (curl_multi_select($mh) === -1) {
                    usleep(100);
                }
                do {
                    $mrc = curl_multi_exec($mh, $active);
                } while ($mrc === CURLM_CALL_MULTI_PERFORM);
            }

            foreach ((array)$handles as $token => $item) {
                $ch = $item['ch'];
                $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $response = curl_multi_getcontent($ch);

                if ($httpCode === 200) {
                    $sent++;
                    $this->metrics->increment('fcm.send.success');
                } else {
                    $failed++;
                    $this->metrics->increment('fcm.send.failure');

                    if ($httpCode === 401) {
                        $this->orchestrator->cache()->forget(self::TOKEN_CACHE_KEY);
                    }
                    if ($httpCode === 404 || $httpCode === 400 || str_contains((string)$response, 'UNREGISTERED') || str_contains((string)$response, 'INVALID_ARGUMENT')) {
                        try {
                            $this->db->query("DELETE FROM user_devices WHERE fcm_token = ?", [$token]);
                            $this->logger->info('fcm.dead_token_purged_batch', ['token' => substr((string)$token, 0, 10)]);
                        } catch (\Throwable) { /* intentional: non-blocking operation */ }
                    }
                }
                curl_multi_remove_handle($mh, $ch);
                curl_close($ch);
            }
            curl_multi_close($mh);
        }

        $duration = microtime(true) - $startTime;
        $this->metrics->timing('fcm.batch_dispatch.latency', $duration);
        $this->logger->info('fcm.batch_sent_parallel', ['sent' => $sent, 'failed' => $failed, 'time' => $duration]);

        return ['sent' => $sent, 'failed' => $failed];
    }

    /**
     * بررسی آماده بودن FCM
     */
    public function isConfigured(): bool
    {
        return !empty($this->projectId)
            && !empty($this->serviceAccountPath)
            && file_exists($this->serviceAccountPath);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Internal — Authentication (OAuth2 با Service Account)
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * دریافت access token از Google (با cache)
     */
    private function getAccessToken(): ?string
    {
        // بررسی cache
        $cached = $this->orchestrator->cache()->get(self::TOKEN_CACHE_KEY);
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        try {
            if (!$this->isConfigured()) {
                $this->logger->warning('fcm.auth_not_configured');
                return null;
            }
            $rawCredentials = file_get_contents((string)$this->serviceAccountPath);
            $serviceAccount = is_string($rawCredentials) ? json_decode($rawCredentials, true) : null;
            if (!is_array($serviceAccount) || !is_string($serviceAccount['client_email'] ?? null) || !is_string($serviceAccount['private_key'] ?? null) || $serviceAccount['client_email'] === '' || $serviceAccount['private_key'] === '') {
                $this->logger->error('fcm.service_account_invalid');
                return null;
            }

            $now     = time();
            $expiry  = $now + 3600;
            $scope   = 'https://www.googleapis.com/auth/firebase.messaging';

            $headerJson = json_encode(['alg' => 'RS256', 'typ' => 'JWT']);
            $payloadJson = json_encode([
                'iss'   => $serviceAccount['client_email'],
                'scope' => $scope,
                'aud'   => 'https://oauth2.googleapis.com/token',
                'iat'   => $now,
                'exp'   => $expiry,
            ]);
            if (!is_string($headerJson) || !is_string($payloadJson)) {
                return null;
            }
            $header  = $this->base64UrlEncode($headerJson);
            $payload = $this->base64UrlEncode($payloadJson);

            $signingInput = "{$header}.{$payload}";
            $signature = '';
            if (!openssl_sign($signingInput, $signature, $serviceAccount['private_key'], 'SHA256') || !is_string($signature) || $signature === '') {
                $this->logger->error('fcm.jwt_signing_failed');
                return null;
            }
            $jwt = "{$signingInput}." . $this->base64UrlEncode($signature);

            // Section 8.3/8.4 — exchange JWT → access token (under CircuitBreaker + retry)
            // OAuth با Google نیز یک external call است و باید با همان الگوی FCM dispatch
            // محافظت شود تا یک خرابی OAuth، کل سیستم نوتیفیکیشن را قفل نکند.
            try {
                $response = $this->callWithBreaker('fcm_oauth', function () use ($jwt): string {
                    return $this->retryTransient(function () use ($jwt): string {
                        if (!$this->isExternalUrlSafe($this->oauthUrl)) {
                            throw new \Core\Exceptions\PermanentFailure('Unsafe FCM OAuth endpoint blocked');
                        }
                        $ch = curl_init($this->oauthUrl);
                        curl_setopt_array($ch, [
                            CURLOPT_RETURNTRANSFER => true,
                            CURLOPT_POST           => true,
                            CURLOPT_POSTFIELDS     => http_build_query([
                                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                                'assertion'  => $jwt,
                            ]),
                            CURLOPT_TIMEOUT        => 10,
                            CURLOPT_CONNECTTIMEOUT => 5,
                        ]);
                        $body  = curl_exec($ch);
                        $code  = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
                        $errno = (int) curl_errno($ch);
                        curl_close($ch);

                        if ($code === 200 && is_string($body) && $body !== '') {
                            return $body;
                        }
                        throw $this->classifyHttpFailure($code, $errno, (string)$body, ['provider' => 'fcm_oauth']);
                    });
                });
            } catch (\Core\Exceptions\PermanentFailure $e) {
                // اطلاعات اعتباری اشتباه/ساعت سیستم اشتباه → 4xx — retry بی‌فایده است.
                $this->logger->error('fcm.token_exchange_permanent', ['error' => $e->getMessage()]);
                return null;
            } catch (\Throwable $e) {
                // CB-open، یا خطای transient که retry نتوانست برطرف کند.
                $this->logger->error('fcm.token_exchange_failed', [
                    'class' => get_class($e),
                    'error' => $e->getMessage(),
                ]);
                return null;
            }

            $data  = is_string($response) ? json_decode($response, true) : null;
            $token = is_array($data) && is_string($data['access_token'] ?? null) ? $data['access_token'] : null;

            if ($token !== null && $token !== '') {
                $this->orchestrator->cache()->put(self::TOKEN_CACHE_KEY, $token, self::TOKEN_TTL);
                return $token;
            }
            $this->logger->warning('fcm.oauth_response_invalid');
            return null;

        } catch (\Throwable $e) {
            $this->logger->error('fcm.auth_failed', ['error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * ساخت payload پیام FCM
     */
    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function buildPayload(
        string  $title,
        string  $body,
        array   $data,
        ?string $imageUrl,
        ?string $clickUrl
    ): array {
        $isSilent = ($title === '' && $body === '');

        $notification = null;
        if (!$isSilent) {
            $notification = [
                'title' => $title,
                'body'  => $body,
            ];
            if ($imageUrl) {
                $notification['image'] = $imageUrl;
            }
        }

        $webpush = [];
        if ($clickUrl && !$isSilent) {
            $webpush = [
                'fcm_options' => ['link' => $clickUrl],
            ];
        }

        // data باید string-string باشد
        $stringData = [];
        foreach ($data as $key => $value) {
            if (is_string($key) && is_scalar($value)) {
                $stringData[$key] = (string)$value;
            }
        }

        $message = [
            'data'    => $stringData,
            'webpush' => $webpush ?: null,
        ];

        if ($notification !== null) {
            $message['notification'] = $notification;
            $message['android'] = [
                'notification' => [
                    'sound'       => 'default',
                    'click_action'=> 'FLUTTER_NOTIFICATION_CLICK',
                ],
            ];
            $message['apns'] = [
                'payload' => [
                    'aps' => ['sound' => 'default'],
                ],
            ];
        } else {
            // ساختار پوش سایلنت (Silent Push / Background Data Sync)
            $message['android'] = [
                'priority' => 'high',
            ];
            $message['apns'] = [
                'payload' => [
                    'aps' => [
                        'content-available' => 1,
                    ],
                ],
            ];
        }

        return ['message' => $message];
    }

    /**
     * ارسال واقعی به FCM API
     */
    /**
     * Section 8.3/8.4 — wraps HTTP call in Core\CircuitBreaker via the trait
     * and classifies failures into the standard hierarchy.
     */
    /** @param array<string, mixed> $payload */
    private function dispatch(array $payload): bool
    {
        $accessToken = $this->getAccessToken();
        if (!$accessToken) {
            return false;
        }

        $url  = sprintf($this->endpointTemplate, rawurlencode((string)$this->projectId));
        if (!$this->isExternalUrlSafe($url)) {
            $this->logger->critical('fcm.unsafe_endpoint_blocked', ['host' => parse_url($url, PHP_URL_HOST)]);
            return false;
        }
        $message = is_array($payload['message'] ?? null) ? $payload['message'] : $payload;
        $json = json_encode(array_filter($message, fn($v) => $v !== null), JSON_UNESCAPED_UNICODE);
        $decodedJson = is_string($json) ? json_decode($json, true) : [];
        $body = json_encode(['message' => is_array($decodedJson) ? $decodedJson : []], JSON_UNESCAPED_UNICODE);

        try {
            return (bool) $this->callWithBreaker('fcm', function () use ($url, $body, $accessToken): bool {
                return $this->retryTransient(function () use ($url, $body, $accessToken): bool {
                    $ch = curl_init($url);
                    curl_setopt_array($ch, [
                        CURLOPT_RETURNTRANSFER => true,
                        CURLOPT_POST           => true,
                        CURLOPT_POSTFIELDS     => $body,
                        CURLOPT_HTTPHEADER     => [
                            'Authorization: Bearer ' . $accessToken,
                            'Content-Type: application/json',
                        ],
                        CURLOPT_TIMEOUT        => 10,
                        CURLOPT_CONNECTTIMEOUT => 5,
                    ]);
                    $response = curl_exec($ch);
                    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
                    $errno    = (int) curl_errno($ch);
                    $error    = curl_error($ch);
                    curl_close($ch);

                    if ($httpCode === 200) {
                        return true;
                    }
                    if ($httpCode === 401) {
                        // token expired → invalidate cache so the next attempt re-issues
                        $this->orchestrator->cache()->forget(self::TOKEN_CACHE_KEY);
                    }
                    if ($httpCode === 404 || $httpCode === 400 || str_contains((string)$response, 'UNREGISTERED') || str_contains((string)$response, 'INVALID_ARGUMENT')) {
                        // اصلاح راهبردی معماری پاکسازی توکن‌های غیرفعال موبایل (FCM Auto-Purge Guard):
                        // حذف خودکار توکن‌های مسدود یا حذف‌شده از سوی کاربران موبایل جهت جلوگیری از اسپم سرورهای گوگل و هدررفت منابع شبکه
                        $decodedBody = is_string($body) ? json_decode($body, true) : null;
                        $deadTokenRaw = null;
                        if (is_array($decodedBody)) {
                            $messagePayload = $decodedBody['message'] ?? null;
                            if (is_array($messagePayload)) {
                                $deadTokenRaw = $messagePayload['token'] ?? null;
                            }
                        }
                        $deadToken = is_string($deadTokenRaw) ? $deadTokenRaw : null;
                        if ($deadToken) {
                            try {
                                $this->db->query("DELETE FROM user_devices WHERE fcm_token = ?", [$deadToken]);
                                $this->logger->info('fcm.dead_token_purged', ['token' => substr($deadToken, 0, 10)]);
                            } catch (\Throwable) { /* intentional: non-blocking operation */ }
                        }
                    }
                    $this->logger->warning('fcm.send_failed', [
                        'http'  => $httpCode,
                        'errno' => $errno,
                        'error' => $error ?: (is_string($response) ? mb_substr($response, 0, 200) : ''),
                    ]);
                    throw $this->classifyHttpFailure($httpCode, $errno, (string)$response, ['provider' => 'fcm']);
                });
            }, function (\Core\Exceptions\CircuitBreakerOpenException $e) use ($payload) {
                // Fallback: circuit open → outbox-first (publisher بعداً retry میکنه)
                $this->logger->warning('fcm.circuit_open_fallback_to_outbox');
                
                try {
                    if ($this->outbox === null) {
                        throw new \RuntimeException('Outbox service not injected');
                    }
                    $this->outbox->record('notification', 0, 'notification.fcm.circuit_retry', [
                        'job' => 'App\\Jobs\\SendFcmJob',
                        'notification' => ['payload' => $payload],
                    ]);
                    return true;
                } catch (\Throwable $outboxErr) {
                    // Last resort: queue مستقیم
                    try {
                        if ($this->queue === null) {
                            throw new \RuntimeException('Queue service not injected');
                        }
                        $this->queue->push('App\\Jobs\\SendFcmJob', [
                            'payload' => $payload
                        ], 'notifications', 60);
                        return true;
                    } catch (\Throwable $qe) {
                        $this->logger->error('fcm.all_fallbacks_failed', ['error' => $qe->getMessage()]);
                        return false;
                    }
                }
            });
        } catch (\Core\Exceptions\PermanentFailure $e) {
            // Permanent 4xx — log + return false (do NOT propagate up to caller as exception)
            $this->logger->warning('fcm.permanent_failure', ['error' => $e->getMessage()]);
            return false;
        } catch (\Throwable $e) {
            // Transient/Provider/RateLimited or CB-open → log + return false (matches old behavior)
            $this->logger->warning('fcm.transient_failure', [
                'class' => get_class($e),
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * رمزگذاری Base64 URL-safe بدون padding (الزامی برای JWT)
     */
    private function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    // -------------------------------------------------------------------------
    // Legacy home-grown circuit breaker — kept as no-ops for binary compatibility.
    // The real CB is Core\CircuitBreaker invoked via ExternalCallTrait::callWithBreaker('fcm').
    // -------------------------------------------------------------------------
    private function isFcmCircuitOpen(): bool { return false; }
    private function recordFcmSuccess(): void {}
    private function recordFcmFailure(): void {}
}
