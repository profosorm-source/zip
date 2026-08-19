<?php

declare(strict_types=1);

namespace App\Jobs\Payment;

class CreateCryptoDepositIntentJob
{
    private \App\Contracts\LoggerInterface $logger;
    private \App\Services\Settings\AppSettings $appSettings;
    private \App\Models\CryptoDepositIntent $intentModel;
    private \App\Services\CryptoDeposit\CryptoDepositService $cryptoDepositService;
    private \App\Contracts\AntiFraud\FraudGuardInterface $fraudGuard;
    public function __construct(
        \App\Contracts\LoggerInterface $logger,
        \App\Services\Settings\AppSettings $appSettings,
        \App\Models\CryptoDepositIntent $intentModel,
        \App\Services\CryptoDeposit\CryptoDepositService $cryptoDepositService,
        \App\Contracts\AntiFraud\FraudGuardInterface $fraudGuard
    ) {        $this->logger = $logger;
        $this->appSettings = $appSettings;
        $this->intentModel = $intentModel;
        $this->cryptoDepositService = $cryptoDepositService;
        $this->fraudGuard = $fraudGuard;
}

    /** @return array<string, mixed> */
public function handle(
        int $userId,
        string $network,
        string $requestedAmount,
        ?string $ipAddress = null,
        ?string $userAgent = null
    ): array {
        $network = strtoupper(trim((string)$network));
        $intentValidation = $this->validateCryptoIntentInput($userId, $network, $requestedAmount);
        if ($intentValidation !== null) {
            return $intentValidation;
        }

        // LOW-09: Defensively sanitize raw user-supplied IP addresses to safeguard system telemetry
        $cleanIp = null;
        if ($ipAddress !== null) {
            $cleanIp = \filter_var($ipAddress, \FILTER_VALIDATE_IP) ?: null;
        }

        $this->logger->info('crypto.intent.create.started', [
            'user_id' => $userId,
            'network' => $network,
            'requested_amount' => $requestedAmount
        ]);

        // 🛡️ گیت ضدتقلب تراکنش کریپتو (Velocity check & Global policies)
        $risk = $this->fraudGuard->checkAction($userId, 'crypto.deposit', [
            'amount'      => $requestedAmount,
            'currency'    => 'usdt',
            'network'     => $network,
            'ip'          => $cleanIp,
            'user_agent'  => $userAgent
        ]);

        if (!$risk['allowed']) {
            $reason = (string)($risk['reason'] ?? 'security_block');
            $this->logger->warning('crypto.intent_blocked_by_fraud_guard', [
                'user_id' => $userId,
                'amount'  => $requestedAmount,
                'reason'  => $reason
            ]);
            $reasonLabel = $reason === 'velocity_limit' ? 'تجاوز از سقف مجاز واریز کریپتو' : $reason;
            return ['success' => false, 'message' => 'امکان ثبت درخواست شارژ رمزارز به دلایل امنیتی مسدود شد. دلیل: ' . $reasonLabel];
        }

        $expireMinutes = int_value($this->appSettings->get('crypto_intent_expire_minutes', \App\Constants\CryptoConstants::DEFAULT_INTENT_EXPIRE_MINUTES));

        $open = $this->intentModel->getOpenIntentForUser($userId);
        if ($open && \strtotime($open->expires_at) < \time()) {
            // Auto-expire it right here to avoid blocking new intent creations (H-01)
            $this->intentModel->expireIfPassed((int)$open->id);
            $open = null;
        }

        if ($open) {
            $this->logger->info('crypto.intent.existing', [
                'user_id' => $userId,
                'intent_id' => $open->id ?? null
            ]);
            return [
                'success' => true,
                'message' => 'شما یک درخواست فعال دارید',
                'intent' => $open,
            ];
        }

        $toWallet = match ($network) {
            'BNB20' => $this->appSettings->get('site_usdt_bnb20_address'),
            'TRC20' => $this->appSettings->get('site_usdt_trc20_address'),
            'TON'   => $this->appSettings->get('site_usdt_ton_address'),
            'SOL'   => $this->appSettings->get('site_usdt_sol_address'),
            default => null,
        };
        if (!$toWallet) {
            $this->logger->error('crypto.intent.no_wallet', [
                'user_id' => $userId,
                'network' => $network
            ]);
            return ['success' => false, 'message' => 'ولت این شبکه تنظیم نشده است'];
        }

        // H-01: Auto-cleanup expired intents before creating a new one to prevent memory leak / stale claims
        try {
            $this->cleanupExpiredIntents();
        } catch (\Throwable $cleanupErr) {
            $this->logger->warning('crypto.intent.cleanup.failed', ['error' => $cleanupErr->getMessage()]);
        }

        $maxRetryIntents = 5;
        $attempt = 0;
        $id = null;
        $expected = null;
        $expiresAt = null;

        try {
            while ($attempt < $maxRetryIntents) {

                try {
                    // Generate a unique expected amount candidate
                    $expected = $this->cryptoDepositService->generateUniqueAmount($network, $requestedAmount);
                    $this->intentModel->validateIntentData($network, $expected, str_value($toWallet));
                    $expiresAt = \date('Y-m-d H:i:s', \time() + ($expireMinutes * 60));

                    $id = $this->intentModel->create([
                        'user_id' => $userId,
                        'currency' => 'USDT',
                        'amount' => $requestedAmount,
                        'address' => $toWallet,
                        'network' => $network,
                        'requested_amount' => $requestedAmount,
                        'expected_amount' => $expected,
                        'to_wallet' => $toWallet,
                        'expires_at' => $expiresAt,
                        'status' => 'open',
                        'ip_address' => $cleanIp,
                        'user_agent' => $userAgent,
                        'created_at' => \date('Y-m-d H:i:s'),
                        'updated_at' => \date('Y-m-d H:i:s'),
                    ]);

                    break; // Success! Exit retry loop
                } catch (\Exception $e) {

                    // If it is a duplicate entry exception (23000), let's retry
                    if ($e instanceof \PDOException && ($e->getCode() === '23000' || \str_contains($e->getMessage(), 'Duplicate entry'))) {
                        $attempt++;
                        if ($attempt >= $maxRetryIntents) {
                            throw new \RuntimeException("امکان تولید درخواست واریز منحصر به فرد به دلیل ترافیک بالا در این لحظه وجود ندارد. لطفا مجددا تلاش کنید.");
                        }
                        continue; // Retry with next attempt
                    }
                    throw $e; // Rethrow other exceptions
                }
            }
        } catch (\Exception $e) {
            $this->logger->error('crypto.intent.create.failed', [
                'channel' => 'crypto',
                'user_id' => $userId,
                'network' => $network,
                'requested_amount' => $requestedAmount,
                'error' => $e->getMessage(),
                'exception' => \get_class($e),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
            return ['success' => false, 'message' => ($e instanceof \RuntimeException) ? $e->getMessage() : 'خطای سیستمی در ساخت درخواست'];
        }

        $this->logger->info('crypto.intent.created', [
            'user_id' => $userId,
            'intent_id' => $id,
            'network' => $network,
            'requested_amount' => $requestedAmount,
            'expected_amount' => $expected,
            'expires_at' => $expiresAt
        ]);

        return [
            'success' => true,
            'message' => 'Intent ساخته شد',
            'intent_id' => (int) $id,
            'network' => $network,
            'requested_amount' => $requestedAmount,
            'expected_amount' => $expected,
            'to_wallet' => $toWallet,
            'expires_at' => $expiresAt,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function validateCryptoIntentInput(int $userId, string $network, string $requestedAmount): ?array
    {
        if ($userId <= 0) {
            return ['success' => false, 'message' => 'کاربر نامعتبر است'];
        }

        $networkDefaults = [
            'TRC20' => true,
            'BNB20' => true,
            'TON' => false,
            'SOL' => false,
        ];
        if (!array_key_exists($network, $networkDefaults)) {
            return ['success' => false, 'message' => 'شبکه نامعتبر است'];
        }
        // Admin-managed settings can enable a shipped network without a code
        // deploy. Defaults preserve the existing feature policy (TRC20/BNB20).
        $configured = $this->appSettings->get('crypto_network_' . strtolower($network) . '_enabled', $networkDefaults[$network] ? 'true' : 'false');
        $enabled = is_bool($configured) ? $configured : filter_var($configured, FILTER_VALIDATE_BOOLEAN);
        if (!$enabled) {
            return ['success' => false, 'message' => 'این شبکه در حال حاضر توسط مدیریت غیرفعال است'];
        }

        if (!is_numeric($requestedAmount) || \bccomp($requestedAmount, '0', 8) <= 0) {
            return ['success' => false, 'message' => 'مبلغ واریز باید مثبت باشد'];
        }

        $minRaw = $this->appSettings->get('min_crypto_deposit_usdt', 1);
        $min = is_numeric($minRaw) ? (string)$minRaw : '1';
        if (\bccomp($requestedAmount, $min, 8) < 0) {
            return ['success' => false, 'message' => "حداقل مبلغ واریز {$min} USDT است"];
        }

        return null;
    }

    private function cleanupExpiredIntents(): int
    {
        return $this->cryptoDepositService->cleanupExpiredIntents();
    }

}
