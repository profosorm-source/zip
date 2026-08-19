<?php

declare(strict_types=1);

namespace App\Services\Wallet;

use App\Models\Wallet;
use App\Models\Transaction;
use App\Domain\Financial\Services\LedgerService;
use Core\Database;
use App\Services\Settings\AppSettings;
use App\Exceptions\BusinessException;
use App\Contracts\LoggerInterface;
use Core\ValueObjects\Money;

class WalletMutationService
{
    private Database $db;
    private Wallet $walletModel;
    private Transaction $transactionModel;
    private LedgerService $ledgerService;
    private AppSettings $appSettings;
    private LoggerInterface $logger;
    /** @var list<string> */
    private array $supportedCurrencies;

    public function __construct(
        Database $db,
        Wallet $walletModel,
        Transaction $transactionModel,
        LedgerService $ledgerService,
        AppSettings $appSettings,
        LoggerInterface $logger,
    ) {
        $this->db = $db;
        $this->walletModel = $walletModel;
        $this->transactionModel = $transactionModel;
        $this->ledgerService = $ledgerService;
        $this->appSettings = $appSettings;
        $this->logger = $logger;
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

    /**
     * @param array<string, mixed> $metadata
     */
    private function idempotencyKey(array $metadata): ?string
    {
        $key = $metadata['idempotency_key'] ?? null;
        if ($key === null || $key === '') {
            return null;
        }
        if (!is_string($key)) {
            throw new \InvalidArgumentException('کلید idempotency باید رشته باشد');
        }

        $key = trim($key);
        return $key === '' ? null : $key;
    }

    /**
     * Root fix: the idempotency pre-check (SELECT ... findByIdempotencyKey) is not a fence — two
     * concurrent requests can both pass it. `transactions.idempotency_key` carries a UNIQUE index,
     * so the database is the real arbiter: the loser gets a duplicate-key violation and must be
     * treated as "already processed" instead of surfacing a system error to the user.
     */
    private function isDuplicateKeyViolation(\Throwable $e): bool
    {
        if ($e instanceof \PDOException) {
            if ((string)$e->getCode() === '23000') {
                return true;
            }
            $driverCode = isset($e->errorInfo[1]) ? (int)$e->errorInfo[1] : 0;
            if (in_array($driverCode, [1062, 1586], true)) {
                return true;
            }
        }
        return str_contains(strtolower($e->getMessage()), 'duplicate entry');
    }

    /**
     * Root fix: withdraw/pay had no idempotency handling at all, so a retried request could lock
     * or debit the balance twice and create duplicate rows. This builds the canonical "already
     * processed" response from the winning transaction, matching processDeposit's behaviour.
     *
     * @return array<string, mixed>
     */
    private function existingTransactionResult(\stdClass $existing, int $userId, string $currency): array
    {
        return [
            'success'        => true,
            'transaction_id' => $existing->transaction_id,
            'message'        => 'این تراکنش قبلاً پردازش شده است',
            'new_balance'    => $this->walletModel->getBalance($userId, $currency),
            'amount'         => $existing->amount,
            'currency'       => $existing->currency,
            'status'         => $existing->status,
            'balance_before' => $existing->balance_before,
            'balance_after'  => $existing->balance_after,
            'duplicate'      => true,
        ];
    }

    private function balanceField(string $currency): string
    {
        return strtolower((string)$currency) === 'usdt' ? 'balance_usdt' : 'balance_irt';
    }

    /**
     * @param array<string, mixed> $metadata
     * @return array<string, mixed>
     */
    public function processDeposit(int $userId, string $amount, string $currency, array $metadata, string $requestId, string $ipAddress, string $deviceFingerprint): array
    {
        $currency = strtolower($currency);
        $idempotencyKey = $this->idempotencyKey($metadata);

        \App\Services\Sentry\SentryExceptionHandler::addBreadcrumb(
            'Wallet deposit started',
            'wallet',
            'info',
            ['user_id' => $userId, 'amount' => $amount, 'currency' => $currency, 'type' => $metadata['type'] ?? 'deposit']
        );
        
        // 🛡️ SECURITY: Validation for positive amount (D-03, D-04)
        if (bccomp($amount, '0', 8) <= 0) {
            throw new \InvalidArgumentException('مبلغ واریز باید مقدار مثبت باشد');
        }

        $spanId = \App\Services\Sentry\SentryExceptionHandler::startSpan(
            'wallet.deposit',
            "Deposit {$amount} {$currency} for user {$userId}"
        );

        $startedTransaction = !$this->db->inTransaction();
        try {
            if ($startedTransaction) $this->db->beginTransaction();

            // 🛡️ SECURITY: Idempotency Check (D-05) under transaction boundary
            if ($idempotencyKey !== null) {
                $existingTransaction = $this->transactionModel->findByIdempotencyKey($idempotencyKey);
                if ($existingTransaction) {
                    if ($startedTransaction) $this->db->commit();
                    return [
                        'success'        => true,
                        'transaction_id' => $existingTransaction->transaction_id,
                        'message'        => 'این تراکنش قبلاً پردازش شده است',
                        'new_balance'    => $this->walletModel->getBalance($userId, $currency),
                        'amount'         => $existingTransaction->amount,
                        'currency'       => $existingTransaction->currency,
                        'status'         => $existingTransaction->status,
                        'balance_before' => $existingTransaction->balance_before,
                        'balance_after'  => $existingTransaction->balance_after,
                    ];
                }
            }

            $wallet = $this->walletModel->findByUserIdForUpdate($userId);
            if (!$wallet) {
                throw new \Core\Exceptions\NotFoundException('کیف پول یافت نشد');
            }

            $refundTypes = ['withdrawal_refund', 'refund', 'deposit_refund', 'scheduled_payment_refund'];
            if (!in_array($metadata['type'] ?? 'deposit', $refundTypes, true)) {
                if ((bool)($wallet->is_frozen ?? 0)) {
                    throw new \Core\Exceptions\InvalidStateException('کیف پول شما مسدود شده است');
                }
            }

            $balanceField = $this->balanceField($currency);
            $balanceBefore = (string)($wallet->$balanceField ?? '0');

            // Atomic DB Update
            if (!$this->walletModel->updateBalance($userId, $amount, $currency)) {
                throw new \Core\Exceptions\ApplicationException('خطا در بروزرسانی موجودی');
            }

            // Fetch new balance to ensure log consistency (تحت قفل FOR UPDATE)
            $balanceAfter = $this->walletModel->getBalanceForUpdate($userId, $currency);

            $transaction = $this->transactionModel->createTransaction([
                'user_id'                => $userId,
                'type'                   => $metadata['type'] ?? 'deposit',
                'currency'               => $currency,
                'amount'                 => $amount,
                'balance_before'         => $balanceBefore,
                'balance_after'          => $balanceAfter,
                'status'                 => 'completed',
                'description'            => $metadata['description'] ?? 'واریز وجه',
                'gateway'                => $metadata['gateway']                ?? null,
                'gateway_transaction_id' => $metadata['gateway_transaction_id'] ?? null,
                'ref_id'                 => $metadata['ref_id']                 ?? null,
                'ref_type'               => $metadata['ref_type']               ?? null,
                'request_id'             => $requestId,
                'ip_address'             => $ipAddress,
                'device_fingerprint'     => $deviceFingerprint,
                'idempotency_key'        => $idempotencyKey,
                'metadata'               => json_encode(array_merge($metadata, [
                    'request_id' => $requestId, 'ip' => $ipAddress,
                    'device' => $deviceFingerprint,
                    'timestamp' => date('Y-m-d H:i:s'),
                ]), JSON_UNESCAPED_UNICODE),
            ]);

            if (!$transaction) {
                throw new \Core\Exceptions\ApplicationException('خطا در ثبت تراکنش');
            }

            // A normal deposit is funded externally. Internal settlements may
            // debit only explicitly allowlisted clearing accounts; arbitrary
            // account names from request metadata are never accepted.
            $requestedDebitAccount = $metadata['ledger_debit_account'] ?? null;
            $ledgerDebitAccount = is_string($requestedDebitAccount)
                && in_array($requestedDebitAccount, ['escrow_payout', 'prediction_pool', 'lottery_pool', 'investment_pool'], true)
                    ? $requestedDebitAccount
                    : 'external_payment';

            // Ledger check: ensure consistency (D-07)
            $this->ledgerService->recordDoubleEntry(
                $transaction->transaction_id,
                $ledgerDebitAccount,
                "wallet:{$userId}",
                $amount,
                $currency,
                is_string($metadata['description'] ?? null) ? $metadata['description'] : 'واریز وجه',
                [
                    'gateway' => $metadata['gateway'] ?? null,
                    'ref_id' => $metadata['ref_id'] ?? null,
                    'balance_before' => $balanceBefore,
                    'balance_after' => $balanceAfter,
                    'funding_source' => $ledgerDebitAccount,
                ]
            );

            if ($startedTransaction) $this->db->commit();

            \App\Services\Sentry\SentryExceptionHandler::finishSpan($spanId, ['status' => 'ok', 'balance_after' => $balanceAfter]);

            return [
                'success'        => true,
                'transaction_id' => $transaction->transaction_id,
                'message'        => 'واریز با موفقیت انجام شد',
                'new_balance'    => $balanceAfter,
                'amount'         => $amount,
                'currency'       => $currency,
                'status'         => 'completed',
                'balance_before' => $balanceBefore,
                'balance_after'  => $balanceAfter,
            ];
        } catch (\Throwable $e) {
            // Root fix: a duplicate idempotency key means a concurrent request already performed
            // this exact deposit. Roll back our half-applied attempt and return the winner's
            // transaction, which is what the pre-check would have returned had it not raced.
            if ($idempotencyKey !== null && $startedTransaction && $this->isDuplicateKeyViolation($e)) {
                if ($this->db->inTransaction()) {
                    $this->db->rollback();
                }
                $existingTransaction = $this->transactionModel->findByIdempotencyKey($idempotencyKey);
                if ($existingTransaction) {
                    \App\Services\Sentry\SentryExceptionHandler::finishSpan($spanId, ['status' => 'ok', 'deduplicated' => true]);
                    $this->logger->info('wallet.deposit.idempotent_replay', [
                        'user_id'         => $userId,
                        'idempotency_key' => $idempotencyKey,
                    ]);
                    return [
                        'success'        => true,
                        'transaction_id' => $existingTransaction->transaction_id,
                        'message'        => 'این تراکنش قبلاً پردازش شده است',
                        'new_balance'    => $this->walletModel->getBalance($userId, $currency),
                        'amount'         => $existingTransaction->amount,
                        'currency'       => $existingTransaction->currency,
                        'status'         => $existingTransaction->status,
                        'balance_before' => $existingTransaction->balance_before,
                        'balance_after'  => $existingTransaction->balance_after,
                    ];
                }
            }

            \App\Services\Sentry\SentryExceptionHandler::finishSpan($spanId, ['status' => 'error', 'error' => $e->getMessage()]);
            $this->logger->warning('wallet.mutation.error', ['file' => __FILE__, 'line' => __LINE__, 'error' => $e->getMessage()]);
            \App\Services\Sentry\SentryExceptionHandler::captureException($e, $userId, [
                'operation'  => 'processDeposit',
                'amount'     => $amount,
                'currency'   => $currency,
                'request_id' => $requestId,
            ]);
            if ($startedTransaction && $this->db->inTransaction()) {
                $this->db->rollback();
            }
            throw $e;
        }
    }

    /**
     * @param array<string, mixed> $metadata
     * @return array<string, mixed>
     */
    public function processWithdraw(int $userId, string $amount, string $currency, array $metadata, string $requestId, string $ipAddress, string $deviceFingerprint): array
    {
        $currency = strtolower($currency);
        $idempotencyKey = $this->idempotencyKey($metadata);

        \App\Services\Sentry\SentryExceptionHandler::addBreadcrumb(
            'Wallet withdraw started',
            'wallet',
            'info',
            ['user_id' => $userId, 'amount' => $amount, 'currency' => $currency]
        );

        $spanId = \App\Services\Sentry\SentryExceptionHandler::startSpan(
            'wallet.withdraw',
            "Withdraw {$amount} {$currency} for user {$userId}"
        );

        $startedTransaction = !$this->db->inTransaction();
        try {
            if ($startedTransaction) $this->db->beginTransaction();

            $wallet = $this->walletModel->findByUserIdForUpdate($userId);
            if (!$wallet) {
                throw new \Core\Exceptions\NotFoundException('کیف پول یافت نشد');
            }
            if ((bool)($wallet->is_frozen ?? 0)) {
                throw new \Core\Exceptions\InvalidStateException('کیف پول شما مسدود شده است');
            }

            // Idempotency check under the wallet lock: a duplicate request must not lock funds twice.
            if ($idempotencyKey !== null) {
                $existingTransaction = $this->transactionModel->findByIdempotencyKey($idempotencyKey);
                if ($existingTransaction) {
                    if ($startedTransaction) $this->db->commit();
                    \App\Services\Sentry\SentryExceptionHandler::finishSpan($spanId, ['status' => 'ok', 'deduplicated' => true]);
                    return $this->existingTransactionResult($existingTransaction, $userId, $currency);
                }
            }

            $balanceField = $this->balanceField($currency);
            $balanceBefore = (string)($wallet->$balanceField ?? '0');
            
            // Atomic lock balance update
            if (!$this->walletModel->lockBalance($userId, $amount, $currency)) {
                throw new \Core\Exceptions\InsufficientBalanceException('موجودی کافی نیست یا کیف پول قفل شده است');
            }

            $balanceAfter = $this->walletModel->getBalanceForUpdate($userId, $currency);

            $this->walletModel->updateLastWithdrawal($userId);

            $transaction = $this->transactionModel->createTransaction([
                'user_id'            => $userId,
                'type'               => 'withdraw',
                'currency'           => $currency,
                'amount'             => $amount,
                'balance_before'     => $balanceBefore,
                'balance_after'      => $balanceAfter,
                'status'             => 'pending',
                'description'        => $metadata['description'] ?? 'برداشت وجه',
                'ref_id'             => $metadata['ref_id'] ?? null,
                'ref_type'           => $metadata['ref_type'] ?? null,
                'request_id'         => $requestId,
                'ip_address'         => $ipAddress,
                'device_fingerprint' => $deviceFingerprint,
                'idempotency_key'    => $idempotencyKey,
                'metadata'           => json_encode(array_merge($metadata, [
                    'request_id' => $requestId, 'ip' => $ipAddress,
                    'device' => $deviceFingerprint,
                ]), JSON_UNESCAPED_UNICODE),
            ]);

            if (!$transaction) {
                throw new \Core\Exceptions\ApplicationException('خطا در ثبت تراکنش');
            }

            // A withdrawal request is a reserve operation, not an external
            // payout yet. Record the actual balance -> locked transition so
            // later complete/cancel/escrow settlement can reconcile it.
            $this->ledgerService->recordDoubleEntry(
                $transaction->transaction_id,
                "wallet:{$userId}",
                'locked_reserve',
                $amount,
                $currency,
                is_string($metadata['description'] ?? null) ? $metadata['description'] : 'رزرو موجودی برای برداشت',
                ['hold_transaction' => $transaction->transaction_id]
            );

            if ($startedTransaction) $this->db->commit();
            \App\Services\Sentry\SentryExceptionHandler::finishSpan($spanId, ['status' => 'ok']);

            return [
                'success'        => true,
                'transaction_id' => $transaction->transaction_id,
                'message'        => 'درخواست برداشت ثبت شد',
                'new_balance'    => $balanceAfter,
                'amount'         => $amount,
                'currency'       => $currency,
                'status'         => 'pending',
                'balance_before' => $balanceBefore,
                'balance_after'  => $balanceAfter,
            ];
        } catch (\Throwable $e) {
            if ($idempotencyKey !== null && $startedTransaction && $this->isDuplicateKeyViolation($e)) {
                if ($this->db->inTransaction()) {
                    $this->db->rollback();
                }
                $existingTransaction = $this->transactionModel->findByIdempotencyKey($idempotencyKey);
                if ($existingTransaction) {
                    \App\Services\Sentry\SentryExceptionHandler::finishSpan($spanId, ['status' => 'ok', 'deduplicated' => true]);
                    return $this->existingTransactionResult($existingTransaction, $userId, $currency);
                }
            }
            \App\Services\Sentry\SentryExceptionHandler::finishSpan($spanId, ['status' => 'error', 'error' => $e->getMessage()]);
            \App\Services\Sentry\SentryExceptionHandler::captureException($e, $userId, [
                'operation'  => 'processWithdraw',
                'amount'     => $amount,
                'currency'   => $currency,
                'request_id' => $requestId,
            ]);
            if ($startedTransaction && $this->db->inTransaction()) {
                $this->db->rollback();
            }
            throw $e;
        }
    }

    /**
     * @param array<string, mixed> $metadata
     * @return array<string, mixed>
     */
    public function processPay(int $userId, string $amount, string $currency, array $metadata, string $requestId, string $ipAddress, string $deviceFingerprint): array
    {
        $currency = strtolower($currency);
        $idempotencyKey = $this->idempotencyKey($metadata);

        $spanId = \App\Services\Sentry\SentryExceptionHandler::startSpan(
            'wallet.pay',
            "Pay {$amount} {$currency} for user {$userId}"
        );

        $startedTransaction = !$this->db->inTransaction();
        try {
            if ($startedTransaction) $this->db->beginTransaction();

            $wallet = $this->walletModel->findByUserIdForUpdate($userId);
            if (!$wallet) {
                throw new \Core\Exceptions\NotFoundException('کیف پول یافت نشد');
            }
            if ((bool)($wallet->is_frozen ?? 0)) {
                throw new \Core\Exceptions\InvalidStateException('کیف پول شما مسدود شده است');
            }

            // Idempotency check under the wallet lock: a duplicate request must not debit twice.
            if ($idempotencyKey !== null) {
                $existingTransaction = $this->transactionModel->findByIdempotencyKey($idempotencyKey);
                if ($existingTransaction) {
                    if ($startedTransaction) $this->db->commit();
                    \App\Services\Sentry\SentryExceptionHandler::finishSpan($spanId, ['status' => 'ok', 'deduplicated' => true]);
                    return $this->existingTransactionResult($existingTransaction, $userId, $currency);
                }
            }

            $balanceField = $this->balanceField($currency);
            $balanceBefore = (string)($wallet->$balanceField ?? '0');
            
            $negativeAmount = bcmul($amount, '-1', 8);
            
            // Atomic balance update
            if (!$this->walletModel->updateBalance($userId, $negativeAmount, $currency)) {
                throw new \Core\Exceptions\InsufficientBalanceException('موجودی کافی نیست یا کیف پول مسدود شده است');
            }
            
            $balanceAfter = $this->walletModel->getBalanceForUpdate($userId, $currency);

            $transaction = $this->transactionModel->createTransaction([
                'user_id'            => $userId,
                'type'               => $metadata['type'] ?? 'payment',
                'currency'           => $currency,
                'amount'             => $negativeAmount,
                'balance_before'     => $balanceBefore,
                'balance_after'      => $balanceAfter,
                'status'             => 'completed',
                'description'        => $metadata['description'] ?? 'پرداخت هزینه',
                'ref_id'             => $metadata['ref_id'] ?? null,
                'ref_type'           => $metadata['ref_type'] ?? null,
                'request_id'         => $requestId,
                'ip_address'         => $ipAddress,
                'device_fingerprint' => $deviceFingerprint,
                'idempotency_key'    => $idempotencyKey,
                'metadata'           => json_encode(array_merge($metadata, [
                    'request_id' => $requestId, 'ip' => $ipAddress,
                    'device' => $deviceFingerprint,
                ]), JSON_UNESCAPED_UNICODE),
            ]);

            if (!$transaction) {
                throw new \Core\Exceptions\ApplicationException('خطا در ثبت تراکنش پرداخت');
            }

            $this->ledgerService->recordDoubleEntry(
                $transaction->transaction_id,
                "wallet:{$userId}",
                "platform_revenue",
                $amount,
                $currency,
                is_string($metadata['description'] ?? null) ? $metadata['description'] : 'پرداخت هزینه',
                [
                    'type' => $metadata['type'] ?? 'payment',
                    'balance_before' => $balanceBefore,
                    'balance_after' => $balanceAfter,
                ]
            );

            if ($startedTransaction) $this->db->commit();
            \App\Services\Sentry\SentryExceptionHandler::finishSpan($spanId, ['status' => 'ok']);

            return [
                'success'        => true,
                'transaction_id' => $transaction->transaction_id,
                'message'        => 'پرداخت با موفقیت انجام شد',
                'new_balance'    => $balanceAfter,
                'amount'         => $amount,
                'currency'       => $currency,
                'status'         => 'completed',
                'balance_before' => $balanceBefore,
                'balance_after'  => $balanceAfter,
            ];
        } catch (\Throwable $e) {
            if ($idempotencyKey !== null && $startedTransaction && $this->isDuplicateKeyViolation($e)) {
                if ($this->db->inTransaction()) {
                    $this->db->rollback();
                }
                $existingTransaction = $this->transactionModel->findByIdempotencyKey($idempotencyKey);
                if ($existingTransaction) {
                    \App\Services\Sentry\SentryExceptionHandler::finishSpan($spanId, ['status' => 'ok', 'deduplicated' => true]);
                    return $this->existingTransactionResult($existingTransaction, $userId, $currency);
                }
            }
            \App\Services\Sentry\SentryExceptionHandler::finishSpan($spanId, ['status' => 'error', 'error' => $e->getMessage()]);
            \App\Services\Sentry\SentryExceptionHandler::captureException($e, $userId, [
                'operation'  => 'processPay',
                'amount'     => $amount,
                'currency'   => $currency,
                'request_id' => $requestId,
            ]);
            if ($startedTransaction && $this->db->inTransaction()) {
                $this->db->rollback();
            }
            throw $e;
        }
    }
    
    public function processTransfer(int $fromUserId, int $toUserId, string $amount, string $currency, string $description): object
    {
        $currency = strtolower((string)$currency);

        \App\Services\Sentry\SentryExceptionHandler::addBreadcrumb(
            'Wallet transfer started',
            'wallet',
            'info',
            ['from' => $fromUserId, 'to' => $toUserId, 'amount' => $amount, 'currency' => $currency]
        );

        $spanId = \App\Services\Sentry\SentryExceptionHandler::startSpan(
            'wallet.transfer',
            "Transfer {$amount} {$currency} from user {$fromUserId} to {$toUserId}"
        );

        $startedTransaction = !$this->db->inTransaction();
        try {
            if ($startedTransaction) $this->db->beginTransaction();

            // 🛡️ SECURITY FIX: قفل‌گذاری همگام و جلوگیری از Deadlock با رعایت ترتیب اولویت شناسه‌ها
            $firstId  = min($fromUserId, $toUserId);
            $secondId = max($fromUserId, $toUserId);

            $firstWallet  = $this->walletModel->findByUserIdForUpdate($firstId);
            $secondWallet = $this->walletModel->findByUserIdForUpdate($secondId);

            $fromWallet = ($fromUserId === $firstId) ? $firstWallet : $secondWallet;
            $toWallet   = ($toUserId === $firstId) ? $firstWallet : $secondWallet;

            if (!$fromWallet || !$toWallet) {
                throw new \Core\Exceptions\NotFoundException('کیف پول یافت نشد');
            }

            if ((bool)($fromWallet->is_frozen ?? 0) || (bool)($toWallet->is_frozen ?? 0)) {
                throw new \Core\Exceptions\InvalidStateException('کیف پول یکی از کاربران مسدود شده است');
            }

            $balanceField = $this->balanceField($currency);
            $fromBalanceBefore = (string)($fromWallet->$balanceField ?? '0');
            $toBalanceBefore   = (string)($toWallet->$balanceField ?? '0');
            
            $negativeAmount = bcmul($amount, '-1', 8);

            // Perform updates and check results
            if (!$this->walletModel->updateBalance($fromUserId, $negativeAmount, $currency)) {
                throw new \Core\Exceptions\InsufficientBalanceException('موجودی فرستنده کافی نیست');
            }
            $fromBalanceAfter = $this->walletModel->getBalanceForUpdate($fromUserId, $currency);

            if (!$this->walletModel->updateBalance($toUserId, $amount, $currency)) {
                throw new \Core\Exceptions\ApplicationException('خطا در افزایش موجودی گیرنده');
            }
            $toBalanceAfter = $this->walletModel->getBalanceForUpdate($toUserId, $currency);

            $ipAddress = function_exists('get_client_ip') ? get_client_ip() : '127.0.0.1';
            $deviceFingerprint = function_exists('generate_device_fingerprint') ? generate_device_fingerprint() : 'system_internal';

            $fromTransaction = $this->transactionModel->createTransaction([
                'user_id'            => $fromUserId,
                'type'               => 'transfer',
                'currency'           => $currency,
                'amount'             => $negativeAmount,
                'balance_before'     => $fromBalanceBefore,
                'balance_after'      => $fromBalanceAfter,
                'status'             => 'completed',
                'description'        => $description ?: "انتقال به کاربر {$toUserId}",
                'ip_address'         => $ipAddress,
                'device_fingerprint' => $deviceFingerprint,
                'metadata'           => json_encode(['to_user_id' => $toUserId]),
            ]);

            $toTransaction = $this->transactionModel->createTransaction([
                'user_id'            => $toUserId,
                'type'               => 'transfer',
                'currency'           => $currency,
                'amount'             => $amount,
                'balance_before'     => $toBalanceBefore,
                'balance_after'      => $toBalanceAfter,
                'status'             => 'completed',
                'description'        => $description ?: "دریافت از کاربر {$fromUserId}",
                'ip_address'         => $ipAddress,
                'device_fingerprint' => $deviceFingerprint,
                'metadata'           => json_encode(['from_user_id' => $fromUserId]),
            ]);

            if ($fromTransaction && $toTransaction) {
                $this->ledgerService->recordDoubleEntry(
                    $fromTransaction->transaction_id,
                    "wallet:{$fromUserId}",
                    "wallet:{$toUserId}",
                    $amount,
                    $currency,
                    $description ?: "انتقال وجه از {$fromUserId} به {$toUserId}",
                    [
                        'from_user_id' => $fromUserId,
                        'to_user_id' => $toUserId,
                        'from_balance_before' => $fromBalanceBefore,
                        'from_balance_after' => $fromBalanceAfter,
                        'to_balance_before' => $toBalanceBefore,
                        'to_balance_after' => $toBalanceAfter,
                    ]
                );
            }

            if ($startedTransaction) $this->db->commit();

            \App\Services\Sentry\SentryExceptionHandler::finishSpan($spanId, ['status' => 'ok']);
            return (object)$fromTransaction;
        } catch (\Throwable $e) {
            \App\Services\Sentry\SentryExceptionHandler::finishSpan($spanId, ['status' => 'error', 'error' => $e->getMessage()]);
            \App\Services\Sentry\SentryExceptionHandler::captureException($e, $fromUserId, [
                'operation'    => 'processTransfer',
                'from_user_id' => $fromUserId,
                'to_user_id'   => $toUserId,
                'amount'       => $amount,
                'currency'     => $currency,
            ]);
            if ($startedTransaction && $this->db->inTransaction()) {
                $this->db->rollback();
            }
            throw $e;
        }
    }

    /**
     * Finalize the lifecycle of a pending wallet hold after another atomic
     * primitive (for example escrow spendLockedFunds) already reduced locked
     * balance. Calling completeWithdrawal here would deduct locked funds twice.
     */
    public function finalizeLockedSpend(string $transactionId, int $userId): bool
    {
        $transaction = $this->transactionModel->findByTransactionId($transactionId);
        if (!$transaction || (int)$transaction->user_id !== $userId) {
            return false;
        }
        if ($transaction->status === 'completed') {
            return true;
        }
        if (!in_array((string)$transaction->status, ['pending', 'processing'], true) || (string)$transaction->type !== 'withdraw') {
            return false;
        }

        $startedTransaction = !$this->db->inTransaction();
        try {
            if ($startedTransaction) {
                $this->db->beginTransaction();
            }
            if (!$this->transactionModel->updateStatusByTransactionId($transactionId, $userId, 'completed')) {
                throw new \Core\Exceptions\ApplicationException('خطا در نهایی‌کردن وضعیت hold کیف پول');
            }
            if ($startedTransaction) {
                $this->db->commit();
            }
            return true;
        } catch (\Throwable $e) {
            if ($startedTransaction && $this->db->inTransaction()) {
                $this->db->rollback();
            }
            $this->logger->warning('wallet.mutation.error', ['operation' => 'finalizeLockedSpend', 'transaction_id' => $transactionId, 'error' => $e->getMessage()]);
            throw $e;
        }
    }

    /** Finalize the lifecycle after releaseLockedFunds already refunded the hold. */
    public function finalizeLockedRefund(string $transactionId, int $userId): bool
    {
        $transaction = $this->transactionModel->findByTransactionId($transactionId);
        if (!$transaction || (int)$transaction->user_id !== $userId) return false;
        if (in_array((string)$transaction->status, ['cancelled', 'failed'], true)) return true;
        if (!in_array((string)$transaction->status, ['pending', 'processing'], true) || (string)$transaction->type !== 'withdraw') return false;
        $started = !$this->db->inTransaction();
        try {
            if ($started) $this->db->beginTransaction();
            if (!$this->transactionModel->updateStatusByTransactionId($transactionId, $userId, 'cancelled')) {
                throw new \Core\Exceptions\ApplicationException('خطا در نهایی‌کردن refund hold کیف پول');
            }
            if ($started) $this->db->commit();
            return true;
        } catch (\Throwable $e) {
            if ($started && $this->db->inTransaction()) $this->db->rollback();
            throw $e;
        }
    }

    public function completeWithdrawal(string $transactionId, int $userId): bool
    {
        $transaction = $this->transactionModel->findByTransactionId($transactionId);
        if (!$transaction || (int)$transaction->user_id !== $userId) {
            return false;
        }

        if ($transaction->status === 'completed') return true;
        if (!in_array($transaction->status, ['pending', 'processing'], true) || $transaction->type !== 'withdraw') {
            return false;
        }

        $currency = strtolower((string)($transaction->currency ?? 'irt'));
        $amount = (string)($transaction->amount ?? '0');

        $startedTransaction = !$this->db->inTransaction();
        try {
            if ($startedTransaction) $this->db->beginTransaction();

            $this->walletModel->deductLocked($userId, $amount, $currency);

            if (!$this->transactionModel->updateStatusByTransactionId($transactionId, $userId, 'completed')) {
                throw new \Core\Exceptions\ApplicationException('خطا در بروزرسانی وضعیت تراکنش');
            }

            $this->ledgerService->recordDoubleEntry(
                $transactionId,
                'locked_reserve',
                'withdrawal_payout',
                $amount,
                $currency,
                'تسویه برداشت',
                ['withdrawal_transaction' => $transactionId]
            );

            if ($startedTransaction) $this->db->commit();
            return true;
        } catch (\Throwable $e) {
            $this->logger->warning('wallet.mutation.error', ['file' => __FILE__, 'line' => __LINE__, 'error' => $e->getMessage()]);
            \App\Services\Sentry\SentryExceptionHandler::captureException($e, $userId, [
                'operation'      => 'settleWithdrawal',
                'transaction_id' => $transactionId,
            ]);
            if ($startedTransaction && $this->db->inTransaction()) $this->db->rollback();
            throw $e;
        }
    }

    public function cancelWithdrawal(string $transactionId, int $userId): bool
    {
        $transaction = $this->transactionModel->findByTransactionId($transactionId);
        if (!$transaction || (int)$transaction->user_id !== $userId) {
            return false;
        }

        if (in_array($transaction->status, ['cancelled', 'failed'], true)) return true;
        if (!in_array($transaction->status, ['pending', 'processing'], true) || $transaction->type !== 'withdraw') {
            return false;
        }

        $currency = strtolower((string)($transaction->currency ?? 'irt'));
        $amount = (string)($transaction->amount ?? '0');

        $startedTransaction = !$this->db->inTransaction();
        try {
            if ($startedTransaction) $this->db->beginTransaction();

            $this->walletModel->unlockBalance($userId, $amount, $currency);

            if (!$this->transactionModel->updateStatusByTransactionId($transactionId, $userId, 'cancelled')) {
                throw new \Core\Exceptions\ApplicationException('خطا در بروزرسانی وضعیت تراکنش');
            }

            $this->ledgerService->recordDoubleEntry(
                $transactionId,
                'locked_reserve',
                "wallet:{$userId}",
                $amount,
                $currency,
                'لغو برداشت و بازگشت وجه قفل‌شده',
                ['withdrawal_transaction' => $transactionId]
            );

            if ($startedTransaction) $this->db->commit();
            return true;
        } catch (\Throwable $e) {
            $this->logger->warning('wallet.mutation.error', ['file' => __FILE__, 'line' => __LINE__, 'error' => $e->getMessage()]);
            \App\Services\Sentry\SentryExceptionHandler::captureException($e, $userId, [
                'operation'      => 'cancelWithdrawal',
                'transaction_id' => $transactionId,
            ]);
            if ($startedTransaction && $this->db->inTransaction()) $this->db->rollback();
            throw $e;
        }
    }

    /**
     * مصرف نهایی وجه قفل‌شده بدون برگشت آن به available balance.
     *
     * قرارداد مالی:
     *   locked -= amount
     *   balance بدون تغییر
     *   ledger: locked_reserve -> settlement account
     *
     * این تنها primitive مجاز برای escrow payout است. در مقابل،
     * releaseLockedFunds یک refund واقعی است و locked -> balance انجام می‌دهد.
     *
     * @param array<string,mixed> $metadata
     * @return array<string,mixed>
     */
    public function spendLockedFunds(
        int $userId,
        string $amount,
        string $currency,
        array $metadata,
        string $requestId,
        string $ipAddress,
        string $deviceFingerprint
    ): array {
        $currency = strtolower($currency);
        $idempotencyKey = $this->idempotencyKey($metadata);
        if (bccomp($amount, '0', 8) <= 0) {
            throw new \InvalidArgumentException('مبلغ مصرف موجودی قفل‌شده باید بیشتر از صفر باشد');
        }

        $allowedSettlementAccounts = ['escrow_payout', 'platform_revenue', 'prediction_pool', 'lottery_pool', 'investment_pool'];
        $settlementAccount = $metadata['ledger_credit_account'] ?? 'escrow_payout';
        if (!is_string($settlementAccount) || !in_array($settlementAccount, $allowedSettlementAccounts, true)) {
            throw new \InvalidArgumentException('حساب تسویهٔ دفتر کل نامعتبر است');
        }
        $description = isset($metadata['description']) && is_string($metadata['description'])
            ? $metadata['description']
            : 'مصرف نهایی موجودی قفل‌شده';

        $lockField = $this->balanceField($currency) === 'balance_usdt' ? 'locked_usdt' : 'locked_irt';
        $balanceField = $this->balanceField($currency);
        $startedTransaction = !$this->db->inTransaction();

        try {
            if ($startedTransaction) {
                $this->db->beginTransaction();
            }

            if ($idempotencyKey !== null) {
                $existing = $this->transactionModel->findByIdempotencyKey($idempotencyKey);
                if ($existing) {
                    if ($startedTransaction) {
                        $this->db->commit();
                    }
                    return [
                        'success' => true,
                        'transaction_id' => $existing->transaction_id,
                        'amount' => (string)$existing->amount,
                        'currency' => (string)$existing->currency,
                        'balance_before' => (string)($existing->balance_before ?? '0'),
                        'balance_after' => (string)($existing->balance_after ?? '0'),
                        'idempotent_replay' => true,
                    ];
                }
            }

            $wallet = $this->walletModel->findByUserIdForUpdate($userId);
            if (!$wallet) {
                throw new \Core\Exceptions\NotFoundException('کیف پول یافت نشد');
            }

            $balanceBefore = (string)($wallet->$balanceField ?? '0');
            $lockedBefore = (string)($wallet->$lockField ?? '0');
            if (bccomp($lockedBefore, $amount, 8) < 0) {
                throw new \Core\Exceptions\InsufficientBalanceException('موجودی قفل‌شده برای تسویه کافی نیست');
            }

            // deductLocked is an atomic WHERE locked >= amount mutation and does
            // not credit available balance.
            $this->walletModel->deductLocked($userId, $amount, $currency);

            $afterWallet = $this->walletModel->findByUserIdForUpdate($userId);
            if (!$afterWallet) {
                throw new \Core\Exceptions\ApplicationException('کیف پول پس از تسویه یافت نشد');
            }
            $balanceAfter = (string)($afterWallet->$balanceField ?? '0');
            $lockedAfter = (string)($afterWallet->$lockField ?? '0');

            $transaction = $this->transactionModel->createTransaction([
                'user_id' => $userId,
                'type' => $metadata['type'] ?? 'locked_funds_spend',
                'currency' => $currency,
                'amount' => $amount,
                'balance_before' => $balanceBefore,
                'balance_after' => $balanceAfter,
                'status' => 'completed',
                'description' => $description,
                'ref_id' => $metadata['ref_id'] ?? null,
                'ref_type' => $metadata['ref_type'] ?? null,
                'request_id' => $requestId,
                'ip_address' => $ipAddress,
                'device_fingerprint' => $deviceFingerprint,
                'idempotency_key' => $idempotencyKey,
                'metadata' => json_encode(array_merge($metadata, [
                    'balance_before' => $balanceBefore,
                    'balance_after' => $balanceAfter,
                    'locked_before' => $lockedBefore,
                    'locked_after' => $lockedAfter,
                    'request_id' => $requestId,
                    'timestamp' => date('Y-m-d H:i:s'),
                ]), JSON_UNESCAPED_UNICODE),
            ]);
            if (!$transaction) {
                throw new \Core\Exceptions\ApplicationException('خطا در ثبت تراکنش تسویهٔ موجودی قفل‌شده');
            }

            if (!$this->ledgerService->recordDoubleEntry(
                $transaction->transaction_id,
                'locked_reserve',
                $settlementAccount,
                $amount,
                $currency,
                $description,
                [
                    'ref_id' => $metadata['ref_id'] ?? null,
                    'ref_type' => $metadata['ref_type'] ?? null,
                    'locked_before' => $lockedBefore,
                    'locked_after' => $lockedAfter,
                ]
            )) {
                throw new \Core\Exceptions\ApplicationException('ثبت دفتر کل تسویهٔ موجودی قفل‌شده ناموفق بود');
            }

            if ($startedTransaction) {
                $this->db->commit();
            }

            return [
                'success' => true,
                'transaction_id' => $transaction->transaction_id,
                'amount' => $amount,
                'currency' => $currency,
                'balance_before' => $balanceBefore,
                'balance_after' => $balanceAfter,
                'locked_before' => $lockedBefore,
                'locked_after' => $lockedAfter,
                'idempotent_replay' => false,
            ];
        } catch (\Throwable $e) {
            if ($startedTransaction && $this->db->inTransaction()) {
                $this->db->rollback();
            }
            $this->logger->warning('wallet.mutation.error', [
                'operation' => 'spendLockedFunds',
                'user_id' => $userId,
                'amount' => $amount,
                'currency' => $currency,
                'error' => $e->getMessage(),
            ]);
            \App\Services\Sentry\SentryExceptionHandler::captureException($e, $userId, [
                'operation' => 'spendLockedFunds',
                'amount' => $amount,
                'currency' => $currency,
                'ref_id' => $metadata['ref_id'] ?? null,
            ]);
            throw $e;
        }
    }

    /**
     * آزادسازی وجوه قفل‌شده و برگشت به موجودی قابل برداشت.
     *
     * Signature هماهنگ با processDeposit / processWithdraw / processPay
     * (پارامترهای requestId, ipAddress, deviceFingerprint اجباری شده‌اند).
     *
     * عملیات: lockedField - amount, balanceField + amount, ثبت transaction, ثبت ledger
     *
     * مناسب برای refund اسکرو، بازگشت بودجه تبلیغات، refund سفارش و سایر موارد.
     *
     * @param array<string, mixed> $metadata
     * @return array<string, mixed>
     */
    public function releaseLockedFunds(
        int $userId,
        string $amount,
        string $currency,
        array $metadata,
        string $requestId,
        string $ipAddress,
        string $deviceFingerprint
    ): array {
        $currency = strtolower($currency);
        $idempotencyKey = $this->idempotencyKey($metadata);
        if (bccomp($amount, '0', 8) <= 0) {
            return ['success' => false, 'message' => 'مبلغ باید بیشتر از صفر باشد'];
        }

        $lockField = $this->balanceField($currency) === 'balance_usdt' ? 'locked_usdt' : 'locked_irt';
        $balanceField = $this->balanceField($currency);
        $description = isset($metadata['description']) && is_string($metadata['description'])
            ? $metadata['description']
            : 'آزادسازی وجوه قفل‌شده';

        $startedTransaction = !$this->db->inTransaction();
        try {
            if ($startedTransaction) $this->db->beginTransaction();

            // قفل‌گذاری ردیف کیف پول
            $wallet = $this->walletModel->findByUserIdForUpdate($userId);
            if (!$wallet) {
                throw new \Core\Exceptions\NotFoundException('کیف پول یافت نشد');
            }

            $balanceBefore = (string)($wallet->$balanceField ?? '0');
            $lockedBefore = (string)($wallet->$lockField ?? '0');

            // بررسی موجودی قفل‌شده کافی
            if (bccomp($lockedBefore, $amount, 8) < 0) {
                throw new \Core\Exceptions\InsufficientBalanceException('موجودی قفل‌شده برای آزادسازی کافی نیست');
            }

            // عملیات اتمیک: unlock + credit
            if (!$this->walletModel->unlockBalance($userId, $amount, $currency)) {
                throw new \Core\Exceptions\ApplicationException('خطا در آزادسازی موجودی قفل‌شده');
            }

            $balanceAfter = $this->walletModel->getBalanceForUpdate($userId, $currency);

            $transaction = $this->transactionModel->createTransaction([
                'user_id'            => $userId,
                'type'               => $metadata['type'] ?? 'locked_funds_release',
                'currency'           => $currency,
                'amount'             => $amount,
                'balance_before'     => $balanceBefore,
                'balance_after'      => $balanceAfter,
                'status'             => 'completed',
                'description'        => $description,
                'ref_id'             => $metadata['ref_id'] ?? null,
                'ref_type'           => $metadata['ref_type'] ?? null,
                'request_id'         => $requestId,
                'ip_address'         => $ipAddress,
                'device_fingerprint' => $deviceFingerprint,
                'idempotency_key'    => $idempotencyKey,
                'metadata'           => json_encode(array_merge($metadata, [
                    'balance_before' => $balanceBefore,
                    'locked_before'  => $lockedBefore,
                    'request_id'     => $requestId,
                    'timestamp'      => date('Y-m-d H:i:s'),
                ]), JSON_UNESCAPED_UNICODE),
            ]);

            if ($transaction) {
                $this->ledgerService->recordDoubleEntry(
                    $transaction->transaction_id,
                    'locked_reserve',
                    "wallet:{$userId}",
                    $amount,
                    $currency,
                    $description,
                    ['ref_id' => $metadata['ref_id'] ?? null, 'ref_type' => $metadata['ref_type'] ?? null]
                );
            }

            if ($startedTransaction) $this->db->commit();

            return [
                'success'        => true,
                'transaction_id' => $transaction ? $transaction->transaction_id : null,
                'amount'         => $amount,
                'currency'       => $currency,
                'balance_before' => $balanceBefore,
                'balance_after'  => $balanceAfter,
            ];
        } catch (\Throwable $e) {
            \App\Services\Sentry\SentryExceptionHandler::captureException($e, $userId, [
                'operation' => 'releaseLockedFunds',
                'amount'    => $amount,
                'currency'  => $currency,
                'ref_id'    => $metadata['ref_id'] ?? null,
            ]);
            if ($startedTransaction && $this->db->inTransaction()) {
                $this->db->rollback();
            }
            $this->logger->warning('wallet.mutation.error', ['file' => __FILE__, 'line' => __LINE__, 'error' => $e->getMessage()]);
            throw $e;
        }
    }

    public function reverseTransaction(string $transactionId, ?int $adminId = null, string $reason = ''): bool
    {
        $transaction = $this->transactionModel->findByTransactionId($transactionId);
        if (!$transaction || $transaction->status !== 'completed') {
            return false;
        }

        // H-02: تراکنش‌های تحت مدیریت Escrow نباید از مسیر reverse عمومی برگردانده شوند.
        // hold متناظر آن‌ها ممکن است قبلاً در payout/refund مصرف شده باشد؛ اعتباردهی مجدد
        // اینجا وجهِ تسویه‌شده را دوباره برمی‌گرداند و invariant کیف پول/escrow را می‌شکند.
        // مسیر درست، refund/dispute خودِ زیرسیستم escrow است.
        if ($this->transactionModel->isEscrowManaged($transaction)) {
            $this->logger->warning('wallet.reversal.escrow_managed_blocked', [
                'transaction_id' => $transactionId,
                'admin_id'       => $adminId,
                'ref_type'       => $transaction->ref_type ?? null,
            ]);
            return false;
        }

        $userId = (int)$transaction->user_id;
        $currency = strtolower((string)($transaction->currency ?? 'irt'));
        $amount = (string)($transaction->amount ?? '0');
        
        $absoluteAmount = bccomp($amount, '0', 8) < 0 ? bcmul($amount, '-1', 8) : $amount;
        $shouldCreditUser = bccomp($amount, '0', 8) < 0 || $transaction->type === 'withdraw';

        $startedTransaction = !$this->db->inTransaction();
        try {
            if ($startedTransaction) $this->db->beginTransaction();

            // H-01: ادعای اتمیک (compare-and-swap) — دقیقاً یک فراخوان مجاز به reversal است.
            // جلوی ریسمان double-reversal در حالت هم‌زمان/retry را می‌گیرد که پیش‌تر
            // به دلیل تغییر وضعیت در انتهای کار وجود داشت.
            if (!$this->transactionModel->claimForReversal($transactionId, $userId, $adminId)) {
                if ($startedTransaction && $this->db->inTransaction()) {
                    $this->db->rollback();
                }
                $this->logger->warning('wallet.reversal.already_claimed', [
                    'transaction_id' => $transactionId,
                    'admin_id'       => $adminId,
                ]);
                return false;
            }

            $balanceBefore = $this->walletModel->getBalance($userId, $currency);

            if ($shouldCreditUser) {
                $this->walletModel->updateBalance($userId, $absoluteAmount, $currency);
                $debitAccount = 'reversal_settlement';
                $creditAccount = "wallet:{$userId}";
            } else {
                $negativeAmount = bcmul($absoluteAmount, '-1', 8);
                $this->walletModel->updateBalance($userId, $negativeAmount, $currency);
                $debitAccount = "wallet:{$userId}";
                $creditAccount = 'reversal_settlement';
            }

            $balanceAfter = $this->walletModel->getBalance($userId, $currency);

            $reversalTransaction = $this->transactionModel->createTransaction([
                'user_id'            => $userId,
                'type'               => 'reversal',
                'currency'           => $currency,
                'amount'             => ($shouldCreditUser ? $absoluteAmount : bcmul($absoluteAmount, '-1', 8)),
                'balance_before'     => $balanceBefore,
                'balance_after'      => $balanceAfter,
                'status'             => 'completed',
                'description'        => $reason ?: "بازگشت تراکنش {$transactionId}",
                'ref_id'             => $transactionId,
                'ref_type'           => 'transaction_reversal',
                'ip_address'         => function_exists('get_client_ip') ? get_client_ip() : '127.0.0.1',
                'device_fingerprint' => function_exists('generate_device_fingerprint') ? generate_device_fingerprint() : 'system_reversal',
                'metadata'           => json_encode([
                    'original_transaction_id' => $transactionId,
                    'admin_id' => $adminId,
                    'reason' => $reason,
                ], JSON_UNESCAPED_UNICODE),
            ]);

            $this->ledgerService->recordDoubleEntry(
                $reversalTransaction->transaction_id ?? 'unknown',
                $debitAccount,
                $creditAccount,
                $absoluteAmount,
                $currency,
                $reason ?: "بازگشت تراکنش {$transactionId}",
                ['original_transaction_id' => $transactionId]
            );

            // وضعیت completed -> reversed پیش‌تر توسط claimForReversal() به‌صورت اتمیک ثبت شده است.
            if ($startedTransaction) $this->db->commit();
            return true;
        } catch (\Throwable $e) {
            $this->logger->warning('wallet.mutation.error', ['file' => __FILE__, 'line' => __LINE__, 'error' => $e->getMessage()]);
            \App\Services\Sentry\SentryExceptionHandler::captureException($e, $userId, [
                'operation'      => 'reverseTransaction',
                'transaction_id' => $transactionId,
                'admin_id'       => $adminId,
            ]);
            if ($startedTransaction && $this->db->inTransaction()) $this->db->rollback();
            throw $e;
        }
    }
}
