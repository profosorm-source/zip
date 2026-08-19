<?php

declare(strict_types=1);

namespace App\Services\Wallet;

use App\Contracts\LoggerInterface;
use Core\Database;
use Core\EventDispatcher;
use App\Contracts\WalletServiceInterface;
use App\Services\DistributedLockService;
use App\Services\Shared\IdempotencyService;
use App\Services\Wallet\WalletQueryService;
use App\Services\Wallet\WalletMutationService;
use App\Services\Settings\AppSettings;
use Core\Exceptions\TransientException;
use App\Services\OutboxService;

/**
 * WalletService
 *
 * MIGRATION: از Core\IdempotencyKey مستقیم به App\Services\Shared\IdempotencyService مهاجرت کرد.
 * اکنون از IdempotencyService::executeWithLock() استفاده می‌کند که همان قابلیت‌های قبلی را
 * (distributed lock + DB transaction + idempotency) در یک لایه متمرکز ارائه می‌دهد.
 */
/**
 * @phpstan-type WalletMetadata array<string, mixed>
 * @phpstan-type WalletResult array{
 *     success: bool,
 *     transaction_id: string|null,
 *     message: string,
 *     new_balance: string|null,
 *     amount: string|null,
 *     currency: string|null,
 *     status: string|null,
 *     error: string|null,
 *     balance_before: string|null,
 *     balance_after: string|null,
 *     locked_before: string|null,
 *     locked_after: string|null,
 *     idempotent_replay: bool
 * }
 * @phpstan-type WalletFilters array<string, mixed>
 */
class WalletService implements WalletServiceInterface
{
    private Database $db;
    private LoggerInterface $logger;
    private DistributedLockService $lockService;
    private WalletQueryService $queryService;
    private WalletMutationService $mutationService;
    private EventDispatcher $events;
    private ?OutboxService $outbox;
    private IdempotencyService $idempotencyService;

    /** @var list<string> */
    private array $supportedCurrencies;

    public function __construct(
        EventDispatcher $eventDispatcher,
        Database $db,
        LoggerInterface $logger,
        IdempotencyService $idempotencyService,
        DistributedLockService $lockService,
        WalletQueryService $queryService,
        WalletMutationService $mutationService,
        AppSettings $appSettings,
        ?OutboxService $outbox = null
    ) {
        $this->db                 = $db;
        $this->logger             = $logger;
        $this->lockService        = $lockService;
        $this->queryService       = $queryService;
        $this->mutationService    = $mutationService;
        $this->events             = $eventDispatcher;
        $this->idempotencyService = $idempotencyService;
        $this->outbox             = $outbox;

        $this->supportedCurrencies = ['irt', 'usdt'];
        $configuredCurrencies = $appSettings->get('wallet_supported_currencies');
        if (is_array($configuredCurrencies) && $configuredCurrencies !== []) {
            $currencies = [];
            foreach ($configuredCurrencies as $configuredCurrency) {
                if (!is_string($configuredCurrency)) {
                    continue;
                }
                $configuredCurrency = strtolower(trim($configuredCurrency));
                if (in_array($configuredCurrency, ['irt', 'usdt'], true)) {
                    $currencies[] = $configuredCurrency;
                }
            }
            if ($currencies !== []) {
                /** @var list<string> $currencies */
                $this->supportedCurrencies = array_values(array_unique($currencies));
            }
        }
    }

    /** @param WalletMetadata $metadata */
    private function metadataString(array $metadata, string $key, string $fallback): string
    {
        $value = $metadata[$key] ?? null;
        if (!is_scalar($value)) {
            return $fallback;
        }

        $value = trim((string)$value);
        return $value === '' ? $fallback : $value;
    }

    private function nullableScalarString(mixed $value): ?string
    {
        return is_scalar($value) ? (string)$value : null;
    }

    /** @return WalletResult */
    private function requireWalletResult(mixed $result): array
    {
        if (!is_array($result)) {
            throw new \UnexpectedValueException('Wallet mutation must return an associative result array; got ' . get_debug_type($result));
        }
        foreach (array_keys($result) as $key) {
            if (!is_string($key)) {
                throw new \UnexpectedValueException('Wallet mutation result must use string keys');
            }
        }

        return $this->standardizeResponse($result);
    }

    // ─── Core atomic operation ───────────────────────────────────────────────

    /**
     * اجرای ایمن عملیات wallet با distributed lock + DB transaction + idempotency.
     * از IdempotencyService::executeWithLock() استفاده می‌کند (نقطه مرکزی).
     */
    /**
     * @param WalletMetadata $metadata
     * @param callable(string, string, string, string): mixed $logic
     * @return WalletResult
     */
    private function executeAtomicOperation(
        int $userId,
        string $action,
        string $amount,
        string $currency,
        array $metadata,
        callable $logic,
        bool $skipLock = false
    ): array {
        $currency = strtolower((string)$currency);
        $this->validateCurrency($currency);

        if (!is_numeric($amount) || bccomp($amount, '0', 8) <= 0) {
            throw new \InvalidArgumentException('مبلغ باید بیشتر از صفر باشد');
        }

        $requestFallback = function_exists('get_request_id') ? get_request_id() : uniqid('req_');
        $ipFallback = function_exists('get_client_ip') ? get_client_ip() : '127.0.0.1';
        $deviceFallback = function_exists('generate_device_fingerprint') ? generate_device_fingerprint() : 'system';
        $requestId = $this->metadataString($metadata, 'request_id', is_scalar($requestFallback) ? (string)$requestFallback : uniqid('req_'));
        $ipAddress = $this->metadataString($metadata, 'ip_address', is_scalar($ipFallback) ? (string)$ipFallback : '127.0.0.1');
        $deviceFingerprint = $this->metadataString($metadata, 'device_fingerprint', is_scalar($deviceFallback) ? (string)$deviceFallback : 'system');
        $logId = strtoupper($action) . "_{$requestId}";

        // All idempotency material is normalized to non-empty strings before it
        // reaches the key store; arrays/objects cannot silently collide.
        $uniqueParts = array_filter([
            (string)$userId,
            $action,
            $amount,
            $currency,
            $this->metadataString($metadata, 'gateway_transaction_id', ''),
            $this->metadataString($metadata, 'ref_id', ''),
            // 🔐 M-15 FIX: include the recipient in the canonical idempotency material.
            // Without it, two legitimate transfers from the same sender with the same
            // amount/currency/ip to DIFFERENT recipients collided into one key, and the
            // second transfer was silently treated as an idempotent replay (recipient B
            // never received the funds). Only transfers set 'to_user_id', so other
            // operations are unaffected (empty value is filtered out).
            $this->metadataString($metadata, 'to_user_id', ''),
            $ipAddress,
        ], static fn(string $value): bool => $value !== '');
        $explicitKey = $this->metadataString(
            $metadata,
            'idempotency_key',
            hash('sha256', implode('|', $uniqueParts))
        );

        // callback اصلی که داخل lock+transaction اجرا می‌شود (TransactionWrapper در IdempotencyService تراکنش را مدیریت می‌کند)
        $innerCallback = function () use (
            $userId, $amount, $currency, $explicitKey,
            $requestId, $ipAddress, $deviceFingerprint, $logId, $logic, $action
        ) {
            $res = $logic($requestId, $ipAddress, $deviceFingerprint, $explicitKey);

            if ($this->outbox && is_array($res) && !empty($res['success'])) {
                $this->outbox->recordEvent(new \Core\GenericEvent([
                    'user_id'        => $userId,
                    'transaction_id' => $res['transaction_id'] ?? null,
                    'amount'         => $amount,
                    'currency'       => $currency,
                    'action'         => $action,
                    'result'         => $res,
                ]));
            }

            $this->logger->info("wallet.{$action}.success", [
                'channel'  => 'wallet',
                'log_id'   => $logId,
                'user_id'  => $userId,
                'amount'   => $amount,
                'currency' => $currency,
            ]);

            return is_array($res) ? $this->standardizeResponse($res) : $res;
        };

        $lockResource = $skipLock ? '' : "wallet:mut:{$userId}";

        $result = $this->idempotencyService->executeWithLock(
            scope:           "wallet_{$action}",
            actorId:         $userId,
            payload:         ['amount' => $amount, 'currency' => $currency, 'ip' => $ipAddress, 'to_user_id' => $this->metadataString($metadata, 'to_user_id', '')],
            callback:        $innerCallback,
            explicitKey:     $explicitKey,
            lockResource:    $lockResource,
            lockTtl:         15,
            lockWaitTimeout: 10,
            dbRetries:       3
        );

        if ($result instanceof \stdClass) {
            $result = get_object_vars($result);
        }
        return $this->requireWalletResult($result);
    }

    // ─── Validation ──────────────────────────────────────────────────────────

    private function validateCurrency(string $currency): void
    {
        if (!in_array($currency, $this->supportedCurrencies, true)) {
            $list = implode("'، '", $this->supportedCurrencies);
            throw new \InvalidArgumentException("ارز '{$currency}' پشتیبانی نمی‌شود. فقط '{$list}' معتبر است.");
        }
    }

    /**
     * @param array<string, mixed> $response
     * @return WalletResult
     */
    private function standardizeResponse(array $response): array
    {
        return [
            'success'        => (bool)($response['success'] ?? false),
            'transaction_id' => $this->nullableScalarString($response['transaction_id'] ?? null),
            'message'        => $this->nullableScalarString($response['message'] ?? null) ?? '',
            'new_balance'    => $this->nullableScalarString($response['new_balance'] ?? null),
            'amount'         => $this->nullableScalarString($response['amount'] ?? null),
            'currency'       => $this->nullableScalarString($response['currency'] ?? null),
            'status'         => $this->nullableScalarString($response['status'] ?? null),
            'error'          => $this->nullableScalarString($response['error'] ?? null),
            'balance_before' => $this->nullableScalarString($response['balance_before'] ?? null),
            'balance_after'  => $this->nullableScalarString($response['balance_after'] ?? null),
            'locked_before'  => $this->nullableScalarString($response['locked_before'] ?? null),
            'locked_after'   => $this->nullableScalarString($response['locked_after'] ?? null),
            'idempotent_replay' => (bool)($response['idempotent_replay'] ?? false),
        ];
    }

    // ─── Public mutation methods ─────────────────────────────────────────────

    /**
     * @param WalletMetadata $metadata
     * @return WalletResult
     */
    public function deposit(int $userId, string $amount, string $currency = 'irt', array $metadata = []): array
    {
        return $this->requireWalletResult($this->executeAtomicOperation(
            $userId, 'deposit', $amount, $currency, $metadata,
            fn($reqId, $ip, $device, $idemKey) => $this->mutationService->processDeposit(
                $userId, $amount, $currency,
                array_merge($metadata, ['idempotency_key' => $idemKey]),
                $reqId, $ip, $device
            )
        ));
    }

    /**
     * @param WalletMetadata $metadata
     * @return WalletResult
     */
    public function depositInTransaction(int $userId, string $amount, string $currency = 'irt', array $metadata = []): array
    {
        return $this->deposit($userId, $amount, $currency, $metadata);
    }

    /**
     * @param WalletMetadata $metadata
     * @return WalletResult
     */
    public function withdraw(int $userId, string $amount, string $currency = 'irt', array $metadata = []): array
    {
        return $this->requireWalletResult($this->executeAtomicOperation(
            $userId, 'withdraw', $amount, $currency, $metadata,
            fn($reqId, $ip, $device, $idemKey) => $this->mutationService->processWithdraw(
                $userId, $amount, $currency,
                array_merge($metadata, ['idempotency_key' => $idemKey]),
                $reqId, $ip, $device
            )
        ));
    }

    /**
     * @param WalletMetadata $metadata
     * @return WalletResult
     */
    public function withdrawInTransaction(int $userId, string $amount, string $currency = 'irt', array $metadata = []): array
    {
        return $this->withdraw($userId, $amount, $currency, $metadata);
    }

    /**
     * @param WalletMetadata $metadata
     * @return WalletResult
     */
    public function pay(int $userId, string $amount, string $currency = 'irt', array $metadata = []): array
    {
        return $this->requireWalletResult($this->executeAtomicOperation(
            $userId, 'pay', $amount, $currency, $metadata,
            fn($reqId, $ip, $device, $idemKey) => $this->mutationService->processPay(
                $userId, $amount, $currency,
                array_merge($metadata, ['idempotency_key' => $idemKey]),
                $reqId, $ip, $device
            )
        ));
    }

    public function transfer(int $fromUserId, int $toUserId, string $amount, string $currency = 'irt', string $description = ''): ?object
    {
        if ($fromUserId === $toUserId) {
            throw new \InvalidArgumentException('امکان انتقال به خود وجود ندارد');
        }

        $recipientExists = (int)$this->db->fetchColumn('SELECT COUNT(*) FROM users WHERE id = ? AND deleted_at IS NULL', [$toUserId]);
        if ($recipientExists <= 0) {
            throw new \InvalidArgumentException('کاربر گیرنده یافت نشد');
        }

        $senderExists = (int)$this->db->fetchColumn('SELECT COUNT(*) FROM users WHERE id = ? AND deleted_at IS NULL', [$fromUserId]);
        if ($senderExists <= 0) {
            throw new \InvalidArgumentException('کاربر فرستنده یافت نشد');
        }

        // Fire event AFTER all validations pass, BEFORE lock acquisition
        $this->events->dispatch(
            \App\Events\WalletTransferInitiatingEvent::class,
            new \App\Events\WalletTransferInitiatingEvent($fromUserId, $toUserId, $amount, $currency)
        );

        // ترتیب قفل‌گذاری: همیشه کوچکترین ID اول — جلوگیری از deadlock
        $firstId  = min($fromUserId, $toUserId);
        $secondId = max($fromUserId, $toUserId);

        // M-26: money mutations are fail-closed — if the distributed lock backend
        // (Redis) is unavailable we refuse to fall back to the multi-server-unsafe
        // file lock, which could otherwise allow a concurrent double-spend.
        $result = $this->lockService->synchronized(
            "wallet:mut:{$firstId}",
            fn() => $this->lockService->synchronized(
                "wallet:mut:{$secondId}",
                fn() => $this->executeAtomicOperation(
                    $fromUserId, 'transfer', $amount, $currency,
                    ['type' => 'transfer', 'to_user_id' => $toUserId],
                    fn() => $this->mutationService->processTransfer($fromUserId, $toUserId, $amount, $currency, $description),
                    true // skipLock — قفل‌ها قبلاً گرفته شده‌اند
                ),
                null,
                15,
                true // failClosed
            ),
            null,
            15,
            true // failClosed
        );

        return is_array($result) ? (object)$result : (is_object($result) ? $result : null);
    }

    public function completeWithdrawal(int $userId, string $amount, string $currency, ?string $transactionId): bool
    {
        if (!$transactionId) return false;
        return $this->mutationService->completeWithdrawal($transactionId, $userId);
    }

    public function cancelWithdrawal(int $userId, string $amount, string $currency, ?string $transactionId): bool
    {
        if (!$transactionId) return false;
        return $this->mutationService->cancelWithdrawal($transactionId, $userId);
    }

    public function finalizeLockedSpend(int $userId, string $transactionId): bool
    {
        return $this->mutationService->finalizeLockedSpend($transactionId, $userId);
    }

    public function finalizeLockedRefund(int $userId, string $transactionId): bool
    {
        return $this->mutationService->finalizeLockedRefund($transactionId, $userId);
    }

    /**
     * مصرف نهایی وجه قفل‌شده (locked → settlement) بدون برگشت به balance مالک.
     *
     * این primitive برای payout escrow است. برخلاف releaseLockedFunds، هرگز
     * balance کاربر مبدا را credit نمی‌کند. executeAtomicOperation قفل ردیف،
     * تراکنش و idempotency را برای این mutation تضمین می‌کند.
     */
    /**
     * @param WalletMetadata $metadata
     * @return WalletResult
     */
    public function spendLockedFunds(int $userId, string $amount, string $currency = 'irt', array $metadata = []): array
    {
        return $this->requireWalletResult($this->executeAtomicOperation(
            $userId, 'spend_locked', $amount, $currency, $metadata,
            fn($reqId, $ip, $device, $idemKey) => $this->mutationService->spendLockedFunds(
                $userId, $amount, $currency,
                array_merge($metadata, ['idempotency_key' => $idemKey]),
                $reqId, $ip, $device
            )
        ));
    }

    /**
     * آزادسازی وجوه قفل‌شده (locked → balance) — فقط برای refund اسکرو،
     * بازگشت بودجه و cancellation. استفاده از آن برای payout ممنوع است.
     */
    /**
     * @param WalletMetadata $metadata
     * @return WalletResult
     */
    public function releaseLockedFunds(int $userId, string $amount, string $currency = 'irt', array $metadata = []): array
    {
        return $this->requireWalletResult($this->executeAtomicOperation(
            $userId, 'release_locked', $amount, $currency, $metadata,
            fn($reqId, $ip, $device, $idemKey) => $this->mutationService->releaseLockedFunds(
                $userId, $amount, $currency,
                array_merge($metadata, ['idempotency_key' => $idemKey]),
                $reqId, $ip, $device
            )
        ));
    }

    public function reverseTransaction(string $transactionId, ?int $adminId = null, string $reason = ''): bool
    {
        return $this->mutationService->reverseTransaction($transactionId, $adminId, $reason);
    }

    // ─── Query delegation ────────────────────────────────────────────────────

    public function getOrCreateWallet(int $userId): ?object           { return $this->queryService->getOrCreateWallet($userId); }
    /** @return array<string, string|null> */
    public function getWalletBalances(int $userId): array              { return $this->queryService->getWalletBalances($userId); }
    /** @return array{can_withdraw: bool, message: string} */
    public function canWithdraw(int $userId, string $amount, string $currency = 'irt'): array
    {
        $result = $this->queryService->canWithdraw($userId, $amount, $currency);
        return [
            'can_withdraw' => (bool)($result['can_withdraw'] ?? false),
            'message' => is_string($result['message'] ?? null) ? $result['message'] : '',
        ];
    }
    public function getWalletSummary(int $userId): \stdClass              { return $this->queryService->getWalletSummary($userId); }
    /**
     * @param WalletFilters $filters
     * @return list<\stdClass>
     */
    public function getUserTransactions(int $userId, int $limit, int $offset, array $filters = []): array { return $this->queryService->getUserTransactions($userId, $limit, $offset, $filters); }
    /** @param WalletFilters $filters */
    public function countUserTransactions(int $userId, array $filters = []): int { return $this->queryService->countUserTransactions($userId, $filters); }
    /** @return list<\stdClass> */
    public function getAllTransactions(?string $status = null, ?string $type = null, ?string $currency = null, int $limit = 50, int $offset = 0): array { return $this->queryService->getAllTransactions($status, $type, $currency, $limit, $offset); }
    public function countAllTransactions(?string $status = null, ?string $type = null, ?string $currency = null): int { return $this->queryService->countAllTransactions($status, $type, $currency); }
    public function findTransactionById(int $id): ?\stdClass             { return $this->queryService->findTransactionById($id); }
    /** @return list<\stdClass> */
    public function quickSearchTransactions(string $term, ?int $userId = null, int $limit = 5): array { return $this->queryService->quickSearchTransactions($term, $userId, $limit); }
    public function getBalance(int $userId, string $currency = 'irt'): string { return $this->queryService->getBalance($userId, $currency); }
    public function getBalanceForUpdate(int $userId, string $currency = 'irt'): string { return $this->queryService->getBalanceForUpdate($userId, $currency); }
    public function isWalletFrozen(int $userId): bool                  { return $this->queryService->isWalletFrozen($userId); }
    public function hasBalance(int $userId, string $amount, string $currency = 'irt'): bool
    {
        return bccomp($this->getBalance($userId, $currency), $amount, 8) >= 0;
    }
}
