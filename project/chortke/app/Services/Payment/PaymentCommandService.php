<?php

declare(strict_types=1);

namespace App\Services\Payment;

use App\Contracts\LoggerInterface;
use App\Contracts\WalletServiceInterface;
use App\Contracts\CircuitBreakerInterface;
use App\Models\PaymentLog;
use App\Services\OutboxService;
use App\Services\SagaOrchestrator;
use App\Services\Shared\IdempotencyService;
use Core\Database;
use Core\RateLimiter;
use Core\ValueObjects\Money;

/**
 * PaymentCommandService — ایجاد و پردازش callback پرداخت آنلاین
 *
 * @phpstan-type CallbackData array<string, scalar|null>
 * @phpstan-type PaymentResult array<string, mixed>
 * @phpstan-type GatewayVerification array<string, mixed>
 * @phpstan-type AmountValidation array{valid: bool, errors: array<string, string>}
 *
 * مسئولیت‌های این کلاس:
 *   - create()   : ارسال درخواست به درگاه و ثبت در payment_logs
 *   - callback() : تأیید برگشت از درگاه، واریز به wallet، ارسال event
 *
 * این کلاس از PaymentService بزرگ جدا شده تا Single Responsibility رعایت شود.
 * PaymentService اکنون Facade‌ای است که به این سرویس و سرویس‌های کوچک‌تر delegate می‌کند.
 */
class PaymentCommandService
{
    private LoggerInterface $logger;
    private PaymentLog $log;
    private PaymentGatewayFactory $gatewayFactory;
    private IdempotencyService $idempotencyService;
    private Database $db;
        private WalletServiceInterface $walletService;
    private SagaOrchestrator $sagaOrchestrator;
    private ?CircuitBreakerInterface $circuitBreaker;
    private ?RateLimiter $rateLimiter;
    private ?OutboxService $outbox;

    /**
     * Payment persistence and gateway adapters may return arrays in unit tests;
     * production database rows are always normalized to stdClass before field access.
     */
    private function toObject(mixed $data): ?\stdClass
    {
        if ($data instanceof \stdClass) return $data;
        if (is_array($data)) return (object)$data;
        return null;
    }

    public function __construct(
        LoggerInterface $logger,
        PaymentLog $log,
        PaymentGatewayFactory $gatewayFactory,
        IdempotencyService $idempotencyService,
        Database $db,
        WalletServiceInterface $walletService,
        SagaOrchestrator $sagaOrchestrator,
        ?CircuitBreakerInterface $circuitBreaker = null,
        ?RateLimiter $rateLimiter = null,
        ?OutboxService $outbox = null
    ) {
        $this->logger             = $logger;
        $this->log                = $log;
        $this->gatewayFactory     = $gatewayFactory;
        $this->idempotencyService = $idempotencyService;
        $this->db                 = $db;
        $this->walletService      = $walletService;
        $this->sagaOrchestrator   = $sagaOrchestrator;
        $this->circuitBreaker     = $circuitBreaker;
        $this->rateLimiter        = $rateLimiter;
        $this->outbox             = $outbox;
    }

    // ─── Public API ───────────────────────────────────────────────────────────

    /** @return PaymentResult */
    public function create(
        int $userId,
        string $gatewayName,
        string $amount,
        int $bankCardId,
        string $idempotencyKey,
        string $clientIp = '',
        string $userAgent = ''
    ): array {
        $gatewayName = strtolower(trim($gatewayName));
        $val = $this->validateAmount($amount);
        if (!$val['valid']) {
            return ['success' => false, 'message' => 'مبلغ نامعتبر است', 'errors' => $val['errors']];
        }
        $amount = Money::fromString($amount, 'irt')->getAmount();

        $gw = $this->gateway($gatewayName);
        if (!$gw) {
            // circuit open → پیام واضح، یا درگاه نامعتبر → پیام عمومی
            if ($this->circuitBreaker?->isOpen("payment_gateway:{$gatewayName}")) {
                return $this->gatewayUnavailableResponse($gatewayName);
            }
            return ['success' => false, 'message' => 'درگاه پرداخت نامعتبر است'];
        }

        \App\Services\Sentry\SentryExceptionHandler::addBreadcrumb(
            'Payment create initiated',
            'payment',
            'info',
            ['user_id' => $userId, 'gateway' => $gatewayName, 'amount' => $amount]
        );

        $idemKey = "payment_create_{$userId}_" . hash('sha256', "{$gatewayName}:{$amount}:{$idempotencyKey}");

        return $this->idempotencyService->execute(
            'payment_create',
            $userId,
            ['amount' => $amount, 'gw' => $gatewayName],
            function () use ($userId, $gatewayName, $amount, $bankCardId, $clientIp, $userAgent, $gw) {
                $this->db->beginTransaction();
                try {
                    if ($bankCardId > 0) {
                        $card = $this->toObject($this->db->selectOne(
                            "SELECT * FROM bank_cards WHERE id = ? AND user_id = ? AND is_verified = 1 AND deleted_at IS NULL FOR UPDATE",
                            [$bankCardId, $userId]
                        ));
                        if (!$card) {
                            throw new \RuntimeException('کارت بانکی انتخاب شده تأیید نشده یا معتبر نیست.');
                        }
                    }

                    // H-06/H-08: nonce را پیش از ساخت callback URL تولید کن و آن را به‌صورت
                    // پارامتر کوئریِ استاندارد و rawurlencode‌شده به URL بازگشت بیفزای تا از طریق
                    // ریدایرکت درگاه به callback حمل شود و در آن‌جا با nonceِ ذخیره‌شده تطبیق یابد.
                    // پیش از این، nonce پس از فراخوانی درگاه ساخته می‌شد و هرگز به URL بازگشت
                    // نمی‌رسید؛ در نتیجه گاردِ nonce در checkPaymentIntegrity هر callback معتبری را رد می‌کرد.
                    $nonce = bin2hex(random_bytes(16));
                    $callbackUrl = $this->buildCallbackUrl($gatewayName, $nonce);
                    if (method_exists($gw, 'requestPayment')) {
                        $gwResponse = $gw->requestPayment($amount, $callbackUrl, [
                            'user_id'   => $userId,
                            'client_ip' => $clientIp,
                            'mobile'    => '',
                        ]);
                    } else {
                        $gwResponse = $gw->createPayment((string)$amount, 'شارژ کیف پول', $callbackUrl, [
                            'user_id'   => $userId,
                            'client_ip' => $clientIp,
                            'mobile'    => '',
                        ]);
                        if (!empty($gwResponse['url']) && empty($gwResponse['redirect_url'])) {
                            $gwResponse['redirect_url'] = $gwResponse['url'];
                        }
                    }

                    if (empty($gwResponse['success']) || empty($gwResponse['authority'])) {
                        throw new \RuntimeException($gwResponse['message'] ?? 'خطا در ارتباط با درگاه پرداخت');
                    }

                    $authority = strval($gwResponse['authority']);

                    $logId = $this->log->create([
                        'user_id'         => $userId,
                        'gateway'         => $gatewayName,
                        'authority'       => $authority,
                        'amount'          => $amount,
                        'bank_card_id'    => $bankCardId > 0 ? $bankCardId : null,
                        'status'          => 'pending',
                        'request_data'    => json_encode([
                            'callback_nonce' => $nonce,
                            'client_ip'      => $clientIp,
                            'user_agent'     => $userAgent,
                        ], JSON_UNESCAPED_UNICODE),
                        'idempotency_key' => $nonce,
                        'created_at'      => date('Y-m-d H:i:s'),
                    ]);

                    $this->db->commit();
                    $this->logStart($gatewayName, ['payment_id' => $logId, 'authority' => $authority, 'amount' => $amount]);

                    return [
                        'success'        => true,
                        'payment_id'     => $logId,
                        'authority'      => $authority,
                        'payment_url'    => is_string($gwResponse['redirect_url'] ?? null) ? $gwResponse['redirect_url'] : '',
                        'callback_nonce' => $nonce,
                    ];
                } catch (\Throwable $e) {
                    $this->db->rollback();
                    $this->logError($gatewayName, $e->getMessage(), ['user_id' => $userId, 'amount' => $amount]);
                    \App\Services\Sentry\SentryExceptionHandler::captureException($e, $userId, [
                        'operation' => 'payment.create',
                        'gateway'   => $gatewayName,
                        'amount'    => $amount,
                    ]);
                    return ['success' => false, 'message' => $e->getMessage()];
                }
            },
            $idemKey
        );
    }

    /**
     * @param array<string, mixed> $callbackData
     * @return PaymentResult
     */
    public function callback(
        string $gatewayName,
        array $callbackData,
        ?int $sessionUserId = null,
        string $clientIp = '',
        string $userAgent = ''
    ): array {
        $gatewayName = strtolower(trim((string)$gatewayName));
        if (!preg_match('/^[a-z0-9_-]{2,30}$/', $gatewayName)) {
            $this->logger->critical('payment.callback.invalid_gateway_name', ['gateway' => $gatewayName]);
            return ['success' => false, 'message' => 'درگاه پرداخت نامعتبر است'];
        }

        $callbackData = $this->sanitizeCallbackPayload($callbackData);

        if ($clientIp === '') {
            $clientIp = get_client_ip();
        }

        $rateLimitError = $this->checkRateLimit($gatewayName, $clientIp);
        if ($rateLimitError) return $rateLimitError;

        $ipError = $this->verifyIpWhitelist($gatewayName, $clientIp);
        if ($ipError) return $ipError;

        $authority = strval($callbackData['authority'] ?? $callbackData['Authority'] ??
            $callbackData['trans_id'] ?? $callbackData['id'] ??
            $callbackData['token'] ?? ''
        );

        $authError = $this->validateAuthorityFormat($gatewayName, $authority);
        if ($authError) return $authError;

        $pay = $this->toObject($this->log->where('authority', $authority)->first());
        if (!$pay) {
            return ['success' => false, 'message' => 'پرداخت یافت نشد'];
        }

        $integrityError = $this->checkPaymentIntegrity($pay, $gatewayName, $authority, $callbackData, $sessionUserId);
        if ($integrityError) return $integrityError;

        \App\Services\Sentry\SentryExceptionHandler::addBreadcrumb(
            'Payment callback received',
            'payment',
            'info',
            ['gateway' => $gatewayName, 'authority' => $authority, 'user_id' => (int)$pay->user_id]
        );

        $idemKey = "payment_cb:{$gatewayName}:{$authority}";
        $userId  = (int)$pay->user_id;

        $gw = $this->gateway($gatewayName);
        if (!$gw) {
            if ($this->circuitBreaker?->isOpen("payment_gateway:{$gatewayName}")) {
                return $this->gatewayUnavailableResponse($gatewayName);
            }
            return ['success' => false, 'message' => 'درگاه نامعتبر است'];
        }

        if (!$gw->verifyCallback($callbackData)) {
            return ['success' => false, 'message' => 'امضای بازگشت پرداخت معتبر نیست'];
        }

        $status = $this->normalizeCallbackStatus($callbackData['Status'] ?? $callbackData['status'] ?? null);
        $verify = $this->performPreVerification($gw, $pay, $gatewayName, $authority, $status);

        if (is_array($verify) && isset($verify['success']) && $verify['success'] === false
            && ($verify['message'] ?? '') === 'مبلغ پرداخت شده با مبلغ درگاه مطابقت ندارد') {
            $this->logger->critical('payment.callback.gateway_amount_mismatch', [
                'gateway'        => $gatewayName,
                'authority'      => $authority,
                'expected'       => $pay->amount ?? null,
                'gateway_amount' => $verify['gateway_amount'] ?? null,
            ]);
            return $verify;
        }

        if (isset($verify['is_pending_review'])) {
            return ['success' => false, 'message' => 'خطا در ارتباط با درگاه. درخواست شما در صف بررسی قرار گرفت.'];
        }

        $result = $this->idempotencyService->execute(
            'payment_callback',
            $userId,
            array_merge($callbackData, ['authority' => $authority]),
            function () use ($gatewayName, $callbackData, $authority, $pay, $status, $verify) {
                try {
                    // BUGFIX-PAYMENT-CALLBACK-TX-ROOT: متدهای کمکی زیر (lockPaymentRecord
                    // با FOR UPDATE، verifyLockedPaymentStatus، handleCancelledPayment،
                    // updatePaymentVerificationStatus) فرض می‌کردند یک تراکنش بیرونی از
                    // قبل باز است و مستقیم $this->db->commit() صدا می‌زدند — اما
                    // IdempotencyKey::wrapInstance() تراکنش idempotency-check خودش را
                    // (در check()) قبل از اجرای این callback می‌بندد، پس این callback
                    // اصلاً داخل هیچ تراکنشی اجرا نمی‌شد. نتیجه: هر بار که این مسیرهای
                    // commit() زودهنگام صدا زده می‌شدند، یک RuntimeException واقعی
                    // ("No active transaction to commit") پرتاب می‌شد که توسط catch
                    // عمومی زیر بی‌صدا به پیام «خطای سیستمی» تبدیل می‌شد — یعنی FOR UPDATE
                    // هم هرگز قفل واقعی نمی‌گرفت (چون بدون تراکنش فعال بی‌اثر است) و هم
                    // کل مسیر تأیید پرداخت عملاً همیشه fail می‌کرد. اکنون کل این بلوک در
                    // یک Transaction Root واقعی اجرا می‌شود.
                    return $this->db->transactional(function () use ($gatewayName, $callbackData, $authority, $pay, $status, $verify) {
                        $lockedPay = $this->lockPaymentRecord($pay, $gatewayName, $authority);
                        if (!$lockedPay) return ['success' => false, 'message' => 'خطا در قفل کردن رکورد پرداخت'];

                        $statusError = $this->verifyLockedPaymentStatus($lockedPay, $gatewayName, $authority, $pay);
                        if ($statusError) return $statusError;

                        if ($verify === null || in_array($status, ['nok', 'cancel', '0', 'failed'], true)) {
                            return $this->handleCancelledPayment($pay, $gatewayName, $authority, $callbackData);
                        }

                        $verifyResult = $this->updatePaymentVerificationStatus($pay, $verify, $gatewayName, $authority);
                        if (!$verifyResult['success']) {
                            return $verifyResult['error_response'];
                        }

                        $sagaResult = $this->executePaymentSaga($pay, $gatewayName, $authority, $verify);
                        if (!$sagaResult['success']) {
                            return ['success' => false, 'message' => $sagaResult['message']];
                        }

                        $this->dispatchPostPaymentEvents($pay, $gatewayName, $authority, $verify);

                        return ['success' => true, 'message' => 'پرداخت با موفقیت تکمیل شد', 'ref_id' => $verify['ref_id'] ?? null];
                    });
                } catch (\Throwable $e) {
                    $this->logger->critical('payment.callback.exception', [
                        'gateway'   => $gatewayName, 'authority' => $authority,
                        'user_id'   => $pay->user_id, 'amount'    => $pay->amount,
                        'exception' => get_class($e),  'message'   => $e->getMessage(),
                    ]);
                    \App\Services\Sentry\SentryExceptionHandler::captureException($e, (int)($pay->user_id ?? 0), [
                        'operation' => 'payment.callback',
                        'gateway'   => $gatewayName,
                        'authority' => $authority,
                        'amount'    => $pay->amount ?? null,
                    ]);
                    if (env('APP_ENV') === 'testing') throw $e;
                    return ['success' => false, 'message' => 'خطای سیستمی در پردازش پرداخت'];
                }
            },
            $idemKey
        );
        return is_array($result) ? $result : ['success' => false, 'message' => 'پاسخ idempotency پرداخت نامعتبر است'];
    }

    // ─── Private helpers ──────────────────────────────────────────────────────

    private function gateway(string $name): ?\App\Contracts\PaymentGatewayInterface
    {
        // ── Circuit Breaker pre-check ────────────────────────────────────────
        // قبل از ساخت instance و هر HTTP call، وضعیت مدار بررسی می‌شود.
        // کلید CB باید با کلیدی که BasePaymentGateway.executeWithCircuitBreaker()
        // استفاده می‌کند یکسان باشد: 'payment_gateway:{gatewayName}'
        if ($this->circuitBreaker !== null) {
            $cbKey = "payment_gateway:{$name}";
            if ($this->circuitBreaker->isOpen($cbKey)) {
                $this->logger->warning('payment.gateway.circuit_open', [
                    'gateway' => $name,
                    'circuit' => $cbKey,
                ]);
                // null برمی‌گرداند تا caller پیام مناسب به user نشان دهد
                return null;
            }
        }

        try {
            return $this->gatewayFactory->create($name);
        } catch (\Core\Exceptions\CircuitBreakerOpenException $e) {
            // CB از داخل gateway پرتاب شده (race condition بین check و call)
            $this->logger->warning('payment.gateway.circuit_open_race', [
                'gateway' => $name,
                'error'   => $e->getMessage(),
            ]);
            return null;
        } catch (\Exception $e) {
            $this->logger->error('payment.gateway_creation_failed', [
                'gateway' => $name,
                'error'   => $e->getMessage(),
            ]);
            \App\Services\Sentry\SentryExceptionHandler::captureException($e, null, [
                'operation' => 'payment.gateway_creation',
                'gateway'   => $name,
            ]);
            return null;
        }
    }

    /**
     * پیام خطای مناسب هنگامی که circuit باز است.
     * در create() و callback() استفاده می‌شود.
     */
    /** @return PaymentResult */
    private function gatewayUnavailableResponse(string $name): array
    {
        return [
            'success' => false,
            'message' => "درگاه پرداخت {$name} موقتاً در دسترس نیست. لطفاً چند دقیقه دیگر تلاش کنید.",
            'error_code' => 'GATEWAY_CIRCUIT_OPEN',
        ];
    }

    /** @return array{valid: bool, errors: array<string, string>} */
    /** @return AmountValidation */
    protected function validateAmount(string $amount): array
    {
        if (!preg_match('/^(?:0|[1-9]\d*)(?:\.\d{1,2})?$/', $amount) || bccomp($amount, '0', 2) <= 0) {
            return ['valid' => false, 'errors' => ['amount' => 'Amount must be a positive decimal with at most 2 decimal places']];
        }
        return ['valid' => true, 'errors' => []];
    }

    private function normalizeCallbackStatus(mixed $status): string
    {
        return is_scalar($status) ? strtolower(trim((string)$status)) : '';
    }

    /**
     * @param array<string, mixed> $payload
     * @return CallbackData
     */
    private function sanitizeCallbackPayload(array $payload): array
    {
        $allowedScalar = [];
        foreach ((array)$payload as $key => $value) {
            $key = preg_replace('/[^A-Za-z0-9_:-]/', '', (string)$key);
            if ($key === '') continue;
            if (is_scalar($value) || $value === null) {
                $allowedScalar[$key] = is_string($value) ? mb_substr(trim((string)$value), 0, 500) : $value;
            }
        }
        return $allowedScalar;
    }

    /** @return ?PaymentResult */
    private function checkRateLimit(string $gatewayName, string $clientIp): ?array
    {
        if (!$this->rateLimiter) return null;
        $ip    = $clientIp ?: 'unknown';
        $configured = config('rate_limits.payment.callback', ['max_attempts' => 20, 'decay_minutes' => 1, 'fail_closed' => true]);
        $cbCfg = is_array($configured) ? $configured : [];
        $maxAttempts = is_numeric($cbCfg['max_attempts'] ?? null) ? (int)$cbCfg['max_attempts'] : 20;
        $decayMinutes = is_numeric($cbCfg['decay_minutes'] ?? null) ? (int)$cbCfg['decay_minutes'] : 1;
        if (!$this->rateLimiter->attempt(
            'payment_callback:' . $gatewayName . ':' . $ip,
            $maxAttempts,
            $decayMinutes,
            (bool)($cbCfg['fail_closed'] ?? true)
        )) {
            return ['success' => false, 'message' => 'تعداد درخواست‌های بازگشت پرداخت بیش از حد مجاز است'];
        }
        return null;
    }

    /** @return ?PaymentResult */
    private function verifyIpWhitelist(string $gatewayName, string $clientIp): ?array
    {
        $allowedIPs = [];
        try {
            $gatewayRow = $this->toObject($this->db->selectOne(
                "SELECT callback_ips FROM payment_gateways WHERE name = :name LIMIT 1",
                ['name' => $gatewayName]
            ));
            if ($gatewayRow !== null && !empty($gatewayRow->callback_ips)) {
                $decoded = (array)(json_decode($gatewayRow->callback_ips, true) ?? []);
                if (is_array($decoded)) $allowedIPs = $decoded;
            }
        } catch (\Throwable $e) {
            $this->logger->warning('paymentcommand.operation_failed', ['error' => $e->getMessage()]);
        }

        if ($allowedIPs === []) {
            $configuredAllowedIps = config('payment.' . $gatewayName . '.callback_ips', []);
            $allowedIPs = is_array($configuredAllowedIps)
                ? array_values(array_filter($configuredAllowedIps, static fn(mixed $ip): bool => is_string($ip) && $ip !== ''))
                : [];
        }

        $isTesting = (defined('PHPUNIT_COMPOSER_INSTALL') || defined('__PHPUNIT_PHAR__') || env('APP_ENV') === 'testing')
                     && empty($_SERVER['FORCE_IP_WHITELIST']);

        if (env('APP_ENV') === 'production' && empty($allowedIPs)) {
            throw new \RuntimeException('IP whitelist must be configured in production');
        }

        if (!$isTesting && !empty($allowedIPs)) {
            $isMatch = false;
            foreach ($allowedIPs as $allowedIP) {
                if (str_contains($allowedIP, '*')) {
                    $regex = '/^' . str_replace(['.', '*'], ['\\.', '.*'], $allowedIP) . '$/';
                    if (preg_match($regex, $clientIp)) { $isMatch = true; break; }
                } elseif ($clientIp === $allowedIP) {
                    $isMatch = true;
                    break;
                }
            }
            if (!$isMatch) return ['success' => false, 'message' => 'دسترسی غیرمجاز است'];
        }
        return null;
    }

    /** @return ?PaymentResult */
    private function validateAuthorityFormat(string $gatewayName, string $authority): ?array
    {
        $isTesting = (defined('PHPUNIT_COMPOSER_INSTALL') || defined('__PHPUNIT_PHAR__') || env('APP_ENV') === 'testing')
                     && empty($_SERVER['FORCE_IP_WHITELIST']);
        $pattern = '/^[A-Za-z0-9\-_]{10,100}$/';
        if (!$isTesting) {
            $patterns = [
                'zarinpal' => '/^[A-Z0-9]{36}$/',
                'idpay'    => '/^[a-f0-9]{32}$/',
                'nextpay'  => '/^[0-9a-f\-]{20,50}$/i',
                'dgpay'    => '/^[A-Za-z0-9]{20,40}$/',
            ];
            $pattern = $patterns[$gatewayName] ?? $pattern;
        }
        if ($authority === '' || !preg_match($pattern, $authority)) {
            return ['success' => false, 'message' => 'کد رهگیری نامعتبر است'];
        }
        return null;
    }

    /**
     * H-06/H-08: ساخت URL بازگشت (callback) با builder استاندارد و rawurlencode پارامترها.
     * nonce به‌صورت پارامتر کوئری حمل می‌شود تا از طریق ریدایرکت درگاه به callback بازگردد و
     * با nonceِ ذخیره‌شده در رکورد پرداخت تطبیق داده شود.
     */
    private function buildCallbackUrl(string $gatewayName, string $nonce): string
    {
        $base = url("/payment/callback/{$gatewayName}");
        $query = http_build_query(['nonce' => $nonce], '', '&', PHP_QUERY_RFC3986);
        $separator = (strpos($base, '?') === false) ? '?' : '&';
        return $base . $separator . $query;
    }

    /**
     * @param CallbackData $callbackData
     * @return ?PaymentResult
     */
    private function checkPaymentIntegrity(
        \stdClass $pay,
        string $gatewayName,
        string $authority,
        array $callbackData,
        ?int $sessionUserId
    ): ?array {
        if ((string)($pay->gateway ?? $gatewayName) !== $gatewayName) {
            return ['success' => false, 'message' => 'درگاه پرداخت نامعتبر است'];
        }

        $createdAt = strtotime($pay->created_at ?? '');
        if ($createdAt > 0 && (time() - $createdAt) > 7200) {
            $this->logger->warning('payment.callback.expired', ['gateway' => $gatewayName, 'authority' => $authority]);
            return ['success' => false, 'message' => 'زمان مجاز برای تکمیل این تراکنش (۲ ساعت) به پایان رسیده است'];
        }

        $storedRequestData = @(array)(json_decode($pay->request_data ?? '', true) ?? []) ?: [];
        $expectedNonceValue = $storedRequestData['callback_nonce'] ?? null;
        $callbackNonceValue = $callbackData['nonce'] ?? null;
        $expectedNonce     = is_scalar($expectedNonceValue) ? strval($expectedNonceValue) : '';
        $callbackNonce     = is_scalar($callbackNonceValue) ? strval($callbackNonceValue) : '';
        if ($expectedNonce !== '' && !hash_equals($expectedNonce, $callbackNonce)) {
            $this->logger->critical('payment.callback.invalid_nonce', ['gateway' => $gatewayName, 'authority' => $authority]);
            return ['success' => false, 'message' => 'نشانه بازگشت پرداخت نامعتبر است'];
        }

        if ($sessionUserId === null && $expectedNonce === '') {
            return ['success' => false, 'message' => 'callback نامعتبر است'];
        }

        $userId = (int)$pay->user_id;
        if ($sessionUserId !== null && $sessionUserId !== $userId) {
            $this->logger->critical('payment.callback.user_mismatch', [
                'gateway' => $gatewayName, 'authority' => $authority,
                'session_user_id' => $sessionUserId, 'payment_user_id' => $userId,
            ]);
            return ['success' => false, 'message' => 'کاربر جلسه فعلی با پرداخت تطابق ندارد'];
        }

        $callbackAmount = isset($callbackData['amount'])
            ? strval($callbackData['amount'])
            : (isset($callbackData['Amount']) ? strval($callbackData['Amount']) : null);

        $expectedAmount = (string)$pay->amount;
        if ($callbackAmount !== null && (!is_numeric($callbackAmount) || bccomp($callbackAmount, $expectedAmount, 2) !== 0)) {
            $this->logger->critical('payment.callback.amount_mismatch', [
                'gateway' => $gatewayName, 'authority' => $authority,
                'expected' => $pay->amount ?? null, 'received' => $callbackAmount,
            ]);
            return ['success' => false, 'message' => 'مبلغ پرداخت شده با مبلغ تراکنش مطابقت ندارد'];
        }

        if ($pay->status === 'completed') {
            return ['success' => false, 'message' => 'این پرداخت قبلاً تکمیل شده است', 'ref_id' => $pay->ref_id ?? null];
        }

        if (!in_array($pay->status, ['pending', 'failed', 'pending_verification'], true)) {
            return ['success' => false, 'message' => 'وضعیت پرداخت نامعتبر است'];
        }

        return null;
    }

    /** @return ?GatewayVerification */
    private function performPreVerification(
        \App\Contracts\PaymentGatewayInterface $gw,
        \stdClass $pay,
        string $gatewayName,
        string $authority,
        string $status
    /** @return ?GatewayVerification */
    ): ?array {
        if (in_array($status, ['nok', 'cancel', '0', 'failed'], true)) return null;
        try {
            $verify = $gw->verifyPayment($authority, (string)$pay->amount);
            if ($verify !== null && isset($verify['amount'])) {
                if (!is_scalar($verify['amount'])) {
                    return [
                        'success' => false,
                        'message' => 'مبلغ بازگشتی درگاه نامعتبر است',
                    ];
                }
                if (bccomp((string)$verify['amount'], (string)$pay->amount, 4) !== 0) {
                    return [
                        'success'        => false,
                        'message'        => 'مبلغ پرداخت شده با مبلغ درگاه مطابقت ندارد',
                        'gateway_amount' => $verify['amount'],
                    ];
                }
            }
            return $verify;
        } catch (\Throwable $e) {
            $this->log->update((int)$pay->id, ['status' => 'pending_verification']);
            return ['is_pending_review' => true];
        }
    }

    private function lockPaymentRecord(\stdClass $pay, string $gatewayName, string $authority): ?\stdClass
    {
        $locked = $this->toObject($this->toObject($this->log->where('id', '=', $pay->id)->lockForUpdate()->first()));
        return $locked;
    }

    /** @return ?PaymentResult */
    private function verifyLockedPaymentStatus(\stdClass $lockedPay, string $gatewayName, string $authority, \stdClass $pay): ?array
    {
        if (!in_array($lockedPay->status, ['pending', 'failed', 'pending_verification'], true)) {
            // BUGFIX-PAYMENT-CALLBACK-TX-ROOT: commit() زودهنگام حذف شد؛ کل عملیات
            // اکنون توسط db->transactional() در callback() مدیریت می‌شود.
            if ($lockedPay->status === 'completed') {
                return ['success' => false, 'message' => 'این پرداخت قبلاً تکمیل شده است', 'ref_id' => $lockedPay->ref_id];
            }
            return ['success' => false, 'message' => 'این پرداخت قبلاً پردازش شده یا لغو شده است'];
        }
        return null;
    }

    /**
     * @param CallbackData $callbackData
     * @return PaymentResult
     */
    private function handleCancelledPayment(\stdClass $pay, string $gatewayName, string $authority, array $callbackData): array
    {
        $this->log->update((int)$pay->id, [
            'status'        => 'cancelled',
            'response_data' => \json_encode($callbackData, JSON_UNESCAPED_UNICODE),
        ]);
        return ['success' => false, 'message' => 'پرداخت لغو شد یا در انتظار تایید باقی ماند'];
    }

    /**
     * @param GatewayVerification $verify
     * @return PaymentResult
     */
    private function updatePaymentVerificationStatus(\stdClass $pay, array $verify, string $gatewayName, string $authority): array
    {
        $paymentStatus      = ($verify['success'] ?? false) === true ? 'verified' : 'failed';
        $pendingVerification = false;
        $verificationSucceeded = ($verify['success'] ?? false) === true;
        $verificationMessage = is_string($verify['message'] ?? null) ? $verify['message'] : '';
        if (!$verificationSucceeded && preg_match('/(timeout|network|connection|اتصال|شبکه)/iu', $verificationMessage)) {
            $paymentStatus       = 'pending_verification';
            $pendingVerification = true;
        }

        $this->log->update((int)$pay->id, [
            'status'        => $paymentStatus,
            'ref_id'        => $verify['ref_id'] ?? null,
            'paid_at'       => ($verify['success'] ?? false) === true ? date('Y-m-d H:i:s') : null,
            'response_data' => \json_encode($verify, JSON_UNESCAPED_UNICODE),
        ]);

        if (($verify['success'] ?? false) !== true) {
            // BUGFIX-PAYMENT-CALLBACK-TX-ROOT: commit()های زودهنگام حذف شدند؛ کل
            // عملیات اکنون توسط db->transactional() در callback() مدیریت می‌شود.
            if ($pendingVerification) {
                $this->createPendingVerificationReview($pay, $verify);
                return ['success' => false, 'error_response' => ['success' => false, 'message' => 'پرداخت در انتظار بررسی دستی است. نتیجه ظرف 24 ساعت اعلام می‌شود.']];
            }
            return ['success' => false, 'error_response' => ['success' => false, 'message' => $verificationMessage !== '' ? $verificationMessage : 'تأیید پرداخت ناموفق']];
        }

        return ['success' => true];
    }

    /**
     * @param GatewayVerification $verify
     * @return PaymentResult
     */
    private function executePaymentSaga(\stdClass $pay, string $gatewayName, string $authority, array $verify): array
    {
        $saga = $this->sagaOrchestrator;
        $result = $saga
            ->setSaga('online_payment_fulfillment', ['payment_id' => $pay->id, 'user_id' => $pay->user_id])
            ->addStep('credit_wallet', function ($ctx) use ($pay, $gatewayName, $authority, $verify) {
                $res = $this->walletService->deposit((int)$pay->user_id, (string)$pay->amount, 'irt', [
                    'type'                   => 'gateway_deposit',
                    'gateway'                => $gatewayName,
                    'gateway_transaction_id' => $authority,
                    'ref_id'                 => $verify['ref_id'] ?? null,
                ]);
                if (empty($res['success'])) throw new \Core\Exceptions\ApplicationException(is_string($res['message'] ?? null) ? $res['message'] : 'خطا در واریز به کیف پول');
                return ['tx_id' => $res['transaction_id']];
            }, function ($err, $res) {
                if (isset($res['tx_id'])) {
                    $this->walletService->reverseTransaction($res['tx_id'], null, 'سیستمی: لغو واریز درگاه');
                }
            })
            ->addStep('update_log', function ($ctx) use ($pay) {
                $this->log->update((int)$pay->id, ['status' => 'completed']);
                return $ctx;
            })
            ->execute();

        return ['success' => true, 'context' => $result];
    }

    /** @param GatewayVerification $verify */
    private function dispatchPostPaymentEvents(\stdClass $pay, string $gatewayName, string $authority, array $verify): void
    {
        if ($this->outbox) {
            $this->outbox->record('payment', (string)$pay->id, 'payment.completed', [
                'user_id'   => (int)$pay->user_id,
                'ref_id'    => is_scalar($verify['ref_id'] ?? null) ? (string)$verify['ref_id'] : $authority,
                'amount'    => (string)$pay->amount,
                'currency'  => 'IRT',
                'gateway'   => $gatewayName,
                'authority' => $authority,
            ]);
            $this->outbox->record('payment', (string)$pay->id, 'notification.deposit_success', [
                'notification' => ['method' => 'depositSuccess', 'args' => [(int)$pay->user_id, (string)$pay->amount, 'IRT']],
            ]);
        }
    }

    /** @param GatewayVerification $verify */
    private function createPendingVerificationReview(\stdClass $pay, array $verify): void
    {
        $existingResponse = @(array)(json_decode($pay->response_data ?? '', true) ?? []);
        if (!is_array($existingResponse)) $existingResponse = [];

        $existingResponse['pending_verification']   = true;
        $existingResponse['verification_error']     = $verify['message'] ?? 'Unknown verification failure';
        $existingResponse['verification_timestamp'] = date('Y-m-d H:i:s');
        $existingResponse['verification_attempts']  = ($existingResponse['verification_attempts'] ?? 0) + 1;

        $this->log->update((int)$pay->id, [
            'response_data' => \json_encode($existingResponse, JSON_UNESCAPED_UNICODE),
        ]);

        $this->logger->warning('payment.callback.pending_verification', [
            'gateway'        => $pay->gateway,
            'authority'      => $pay->authority,
            'user_id'        => $pay->user_id,
            'amount'         => $pay->amount,
            'verify_message' => $verify['message'] ?? 'unknown',
        ]);
    }

    /** @param array<string, mixed> $ctx */
    private function logStart(string $op, array $ctx): void  { $this->logger->info("payment.{$op}.started", $ctx); }
    /** @param array<string, mixed> $ctx */
    private function logError(string $op, string $err, array $ctx = []): void
    {
        $this->logger->error("payment.{$op}.failed", array_merge($ctx, ['error' => $err]));
    }
}
