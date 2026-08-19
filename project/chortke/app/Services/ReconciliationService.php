<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Transaction;
use App\Models\LedgerEntry;
use App\Models\Wallet;
use Core\Database;
use App\Contracts\LoggerInterface;
use App\Contracts\WalletServiceInterface;
use App\Services\AuditTrail;
use App\Domain\Financial\Services\LedgerService;
use App\Services\OutboxService;

/**
 * ReconciliationService — dynamic payment reconciliation with hardened security (HMAC & Ledger precision)
 */
/**
 * @phpstan-type ReconciliationResult array{success: bool, message: string, transaction_id?: int|string}
 * @phpstan-type ConsistencyResult array{valid: bool, message: string}
 * @phpstan-type TransactionRow object{
 *     id: int|string,
 *     user_id: int|string|null,
 *     type: string,
 *     amount: int|float|string,
 *     currency: string,
 *     status: string,
 *     transaction_id: string|null,
 *     metadata: string|null
 * }
 * @phpstan-type StuckWithdrawalRow object{
 *     id: int|string,
 *     user_id: int|string,
 *     amount: int|float|string,
 *     currency: string,
 *     status: string,
 *     transaction_id: string|null,
 *     created_at: string,
 *     transaction_status: string|null,
 *     transaction_updated_at: string|null
 * }
 */
class ReconciliationService
{
    // Unused constant removed - no longer needed

    /** Section 8.5 / 8.7 — Stuck Withdrawal Review thresholds. */
    public const DEFAULT_STUCK_MINUTES = 120;
    public const STUCK_SCAN_BATCH      = 200;

    private \Core\Database $db;
    private \App\Contracts\LoggerInterface $logger;
    private Transaction $transactionModel;
    private LedgerEntry $ledgerModel;
    private Wallet $walletModel;
    private WalletServiceInterface $walletService;
    private LedgerService $ledgerService;
    private AuditTrail $auditTrail;
    private ?OutboxService $outbox;

    /**
     * @param object $transaction
     * @return TransactionRow
     */
    private function requireTransactionRow(object $transaction): object
    {
        $values = get_object_vars($transaction);
        foreach (['id', 'type', 'amount', 'currency', 'status'] as $field) {
            if (!array_key_exists($field, $values) || !is_scalar($values[$field])) {
                throw new \UnexpectedValueException("Invalid transaction row: missing or non-scalar {$field}");
            }
        }
        foreach (['user_id', 'transaction_id', 'metadata'] as $field) {
            if (!array_key_exists($field, $values)
                || ($values[$field] !== null && !is_scalar($values[$field]))) {
                throw new \UnexpectedValueException("Invalid transaction row: {$field}");
            }
        }

        /** @var TransactionRow $transaction */
        return $transaction;
    }

    /**
     * @param object $withdrawal
     * @return StuckWithdrawalRow
     */
    private function requireStuckWithdrawalRow(object $withdrawal): object
    {
        $values = get_object_vars($withdrawal);
        foreach (['id', 'user_id', 'amount', 'currency', 'status', 'created_at'] as $field) {
            if (!array_key_exists($field, $values) || !is_scalar($values[$field])) {
                throw new \UnexpectedValueException("Invalid stuck-withdrawal row: {$field}");
            }
        }
        foreach (['transaction_id', 'transaction_status', 'transaction_updated_at'] as $field) {
            if (!array_key_exists($field, $values)
                || ($values[$field] !== null && !is_scalar($values[$field]))) {
                throw new \UnexpectedValueException("Invalid stuck-withdrawal row: {$field}");
            }
        }

        /** @var StuckWithdrawalRow $withdrawal */
        return $withdrawal;
    }

    private function scalarString(mixed $value, string $default = ''): string
    {
        if (!is_scalar($value)) {
            return $default;
        }

        $value = trim((string)$value);
        return $value === '' ? $default : $value;
    }

    public function __construct(
        \Core\Database $db,
        \App\Contracts\LoggerInterface $logger,
        Transaction $transactionModel,
        LedgerEntry $ledgerModel,
        Wallet $walletModel,
        WalletServiceInterface $walletService,
        LedgerService $ledgerService,
        AuditTrail $auditTrail,
        ?OutboxService $outbox = null
    ) {        $this->db = $db;
        $this->logger = $logger;
        $this->transactionModel = $transactionModel;
        $this->ledgerModel = $ledgerModel;
        $this->walletModel = $walletModel;
        $this->walletService = $walletService;
        $this->ledgerService = $ledgerService;
        $this->auditTrail = $auditTrail;
        $this->outbox = $outbox;

        
    }

    /**
     * Reconcile external transaction webhook data with local ledger and wallet.
     *
     * @param array<string, mixed> $webhookData   Webhook payload (باید شامل signature باشد، مگر internal call)
     * @param  bool   $isInternal    اگر true باشد، یعنی فراخوانی از داخل سیستم است.
     *                               در این حالت signature بر اساس secret محاسبه و inject می‌شود.
     *                               ⚠️  هرگز از خارج از سیستم با isInternal=true نباشد.
     * @return ReconciliationResult
     */
    public function reconcilePayment(array $webhookData, bool $isInternal = false): array
    {
        $externalIdValue = $webhookData['transaction_id'] ?? $webhookData['reference_id'] ?? null;
        $amountValue = $webhookData['amount'] ?? null;
        $currencyValue = $webhookData['currency'] ?? null;
        $statusValue = $webhookData['status'] ?? null;
        if (!is_scalar($externalIdValue) || trim((string)$externalIdValue) === '') {
            $this->logger->error('reconciliation.invalid_input', ['message' => 'External ID is missing or invalid from webhook']);
            return ['success' => false, 'message' => 'کد پیگیری معتبر نیست'];
        }
        $externalId = (string)$externalIdValue;
        if ($amountValue !== null && (!is_scalar($amountValue) || !is_numeric((string)$amountValue))) {
            return ['success' => false, 'message' => 'مبلغ وب‌هوک معتبر نیست'];
        }
        if ($currencyValue !== null && (!is_string($currencyValue) || trim($currencyValue) === '')) {
            return ['success' => false, 'message' => 'ارز وب‌هوک معتبر نیست'];
        }
        $amount = $amountValue === null ? '0' : (string)$amountValue;
        $currency = strtolower($currencyValue ?? 'irt');
        $status = is_string($statusValue) ? strtolower($statusValue) : 'pending'; // success, failed, pending

        if ($externalId === '') {
            $this->logger->error('reconciliation.invalid_input', ["message" => "External ID is missing from webhook"]);
            return ['success' => false, 'message' => 'کد پیگیری معتبر نیست'];
        }

        try {
            $this->db->beginTransaction();

            // ─── SECURITY FIX (VULN-02): Webhook HMAC Signature Validation ───────────
            // اولویت تأمین secret:
            //   1. config/webhook.php  ← env('WEBHOOK_SECRET')
            //   2. جدول settings در دیتابیس (legacy fallback)
            //   3. اگر هیچ‌کدام موجود نبود → RuntimeException (fail-fast)
            //
            // ⛔  هرگز به مقدار hardcoded یا mock fallback نمی‌شود.
            // ⛔  اگر signature در request خارجی وجود نداشته باشد، درخواست رد می‌شود.
            // ✅  برای internal callها، signature بر اساس secret امن محاسبه می‌شود.
            // ─────────────────────────────────────────────────────────────────────────
            $secret = config('webhook.secret');

            if (!is_string($secret) || trim($secret) === '') {
                // Legacy fallback: خواندن از جدول settings (فقط یک‌بار تلاش)
                try {
                    $dbSecret = $this->db->fetchColumn(
                        "SELECT value FROM settings WHERE key_name = 'webhook_secret' LIMIT 1"
                    );
                    if (is_string($dbSecret) && trim($dbSecret) !== '') {
                        $secret = $dbSecret;
                    }
                } catch (\Throwable $e) {
                    $this->logger->warning('reconciliation.webhook_hmac_key_lookup_failed', [
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            // Fail-fast: اگر secret هنوز خالی است، پردازش متوقف می‌شود
            if (!is_string($secret) || trim($secret) === '') {
                $this->logger->error('reconciliation.webhook_hmac_key_not_configured', [
                    'hint' => 'Set WEBHOOK_SECRET in .env or add webhook_secret to the settings table.',
                ]);
                $this->db->rollback();
                throw new \RuntimeException(
                    'WEBHOOK_SECRET is not configured. Cannot process webhook. ' .
                    'Set WEBHOOK_SECRET in .env or add webhook_secret to the settings table.'
                );
            }

            // ── آماده‌سازی payload برای محاسبه HMAC ──────────────────────────────────
            $payloadData = $webhookData;
            unset($payloadData['signature']);
            ksort($payloadData);
            $payloadJson = json_encode($payloadData, JSON_UNESCAPED_SLASHES);
            if (!is_string($payloadJson)) {
                $this->db->rollback();
                return ['success' => false, 'message' => 'دادهٔ وب‌هوک قابل امضای معتبر نیست'];
            }
            $computed = hash_hmac('sha256', $payloadJson, $secret);

            if ($isInternal) {
                // ✅ Internal call: signature با همان secret تولید می‌شود (مطمئن است)
                // زیرا secret از config/env می‌آید، نه hardcoded
                $signature = $computed;
                $this->logger->info('reconciliation.internal_call_auto_signed', [
                    'external_id' => $externalId,
                ]);
            } else {
                // 🌐 External webhook: signature باید در header یا payload موجود باشد
                $configuredHeader = config('webhook.signature_header', 'X-Signature');
                $headerName = is_string($configuredHeader) && trim($configuredHeader) !== ''
                    ? $configuredHeader
                    : 'X-Signature';
                $signatureHeaderKey = 'HTTP_' . strtoupper(str_replace('-', '_', $headerName));
                $signature = $webhookData['signature'] ?? (function_exists('app') && app()->request
                    ? app()->request->header(str_replace('HTTP_', '', strtolower($signatureHeaderKey)))
                    : null);

                // ⛔  اگر signature غایب یا غیررشته‌ای باشد → رد درخواست
                if (!is_string($signature) || $signature === '') {
                    $this->logger->error('reconciliation.missing_webhook_signature', [
                        'external_id' => $externalId,
                        'hint'        => 'Provide HMAC-SHA256 signature in the ' .
                                         $headerName . ' header or payload.',
                    ]);
                    $this->db->rollback();
                    return ['success' => false, 'message' => 'امضای وب‌هوک ارائه نشده است'];
                }
            }

            // مقایسه constant-time (جلوگیری از timing attack)
            if (!hash_equals($computed, $signature)) {
                $this->logger->error('reconciliation.invalid_signature', [
                    'external_id' => $externalId,
                    'is_internal' => $isInternal,
                ]);
                $this->db->rollback();
                return ['success' => false, 'message' => 'امضای وب‌هوک معتبر نیست'];
            }
            // ─────────────────────────────────────────────────────────────────────────

            // Find and lock the matching transaction immediately inside the transaction block
            $transaction = $this->db->fetch(
                "SELECT * FROM transactions WHERE (external_id = :ext_id OR gateway_transaction_id = :ext_id OR transaction_id = :ext_id) FOR UPDATE LIMIT 1",
                ['ext_id' => $externalId]
            );

            // Register orphan transaction inside the transaction block with lock if not exists.
            if ($transaction === null) {
                $transaction = $this->createOrphanTransaction($webhookData);
            }
            $transaction = $this->requireTransactionRow($transaction);

            // MED-05: Lock Wallet first to establish consistent lock order hierarchy (Wallet -> Transaction)
            $userId = $transaction->user_id;
            if ($userId !== null && (int)$userId > 0) {
                $this->db->fetch("SELECT id FROM wallets WHERE user_id = ? FOR UPDATE", [(int)$userId]);
            }

            // Re-fetch transaction row FOR UPDATE under the wallet lock to strictly enforce locking order hierarchy
            $transaction = $this->db->fetch(
                "SELECT * FROM transactions WHERE id = :id FOR UPDATE",
                ['id' => $transaction->id]
            );

            if ($transaction === null) {
                $this->db->rollback();
                return ['success' => false, 'message' => 'تراکنش در زمان قفل‌گذاری یافت نشد'];
            }
            $transaction = $this->requireTransactionRow($transaction);
            if ($transaction->user_id === null || (int)$transaction->user_id <= 0) {
                $this->db->rollback();
                $this->logger->error('reconciliation.orphan_no_user', ['external_id' => $externalId]);
                return ['success' => false, 'message' => 'تراکنش ناشناخته بدون کاربر مشخص مجاز نیست'];
            }

            if (in_array($transaction->status, ['completed', 'failed', 'cancelled'], true)) {
                $this->db->rollback();
                return ['success' => true, 'message' => 'این تراکنش قبلاً پردازش و نهایی شده بود'];
            }

            $result = match ($status) {
                'success' => $this->processSuccessfulPayment($transaction, $webhookData),
                'failed'  => $this->processFailedPayment($transaction, $webhookData),
                default   => ['success' => true, 'message' => 'وضعیت معلق یا نامشخص']
            };

            if (!$result['success']) {
                $this->db->rollback();
                return $result;
            }

            // 5. Verification check
            if ($transaction->user_id) {
                $consistency = $this->verifyConsistency((int)$transaction->user_id, $currency);
                if (!$consistency['valid']) {
                    $this->logger->error('reconciliation.consistency_drift_detected', [
                        'user_id' => $transaction->user_id,
                        'error' => $consistency['message']
                    ]);

                    // 🚨 captureAnomaly: خرابی مالی پنهان — wallet با ledger مطابقت ندارد
                    try {
                        $handler = \App\Services\Sentry\SentryExceptionHandler::getInstance();
                        $handler->getErrorMonitor()->captureAnomaly(
                            'wallet_ledger_mismatch',
                            $consistency['message'],
                            [
                                'user_id'        => $transaction->user_id,
                                'transaction_id' => $transaction->id,
                                'external_id'    => $externalId,
                                'currency'       => $currency,
                                'amount'         => $amount,
                            ],
                            'critical',
                            (int)$transaction->user_id
                        );
                    } catch (\Throwable $e) {
            $this->logger->warning('reconciliationservice.operation_failed', ['error' => $e->getMessage()]);
        }
                    
                    $this->auditTrail->record('reconciliation.consistency_drift', (int)$transaction->user_id, [
                        'error' => $consistency['message'],
                        'transaction_id' => $transaction->id
                    ]);

                    throw new \RuntimeException("Reconciliation aborted: Financial consistency drift detected: " . $consistency['message']);
                }
            }

            $this->auditTrail->record('payment_reconciled', $transaction->user_id ? (int)$transaction->user_id : null, [
                'message' => "Payment $externalId reconciled dynamically.",
                'transaction_id' => $transaction->id,
                'type' => $transaction->type,
                'amount' => $amount,
                'status' => $status,
            ]);

            $this->db->commit();

            return [
                'success' => true,
                'transaction_id' => $transaction->id,
                'message' => 'تطبیق تراکنش با موفقیت انجام شد'
            ];

        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollback();
            }
            $this->logger->error('reconciliation.error', [
                'external_id' => $externalId,
                'error' => $e->getMessage()
            ]);
            \App\Services\Sentry\SentryExceptionHandler::captureException($e, null, [
                'operation'   => 'reconciliation.reconcilePayment',
                'external_id' => $externalId,
                'currency'    => $currency,
                'amount'      => $amount,
            ]);
            return ['success' => false, 'message' => 'خطای سیستمی در فرآیند تطبیق تراکنش رخ داد'];
        }
    }

    /**
     * Process successful payment securely
     * @param TransactionRow $transaction
     * @param array<string, mixed> $webhookData
     * @return ReconciliationResult
     */
    private function processSuccessfulPayment(object $transaction, array $webhookData): array
    {
        // 🛡️ CRIT-04: Strict Ledger-based Idempotency Check only. Float balance comparisons are deleted.
        $txId = (string)($transaction->transaction_id ?? $transaction->id);
        $existingLedger = $this->ledgerModel->getByTransactionId($txId);
        if (!empty($existingLedger)) {
            return ['success' => true, 'message' => 'این تراکنش قبلاً در دفتر کل ثبت شده است و پردازش مجدد نادیده گرفته شد'];
        }

        $webhookAmount = $webhookData['amount'] ?? null;
        if ($webhookAmount !== null && (!is_scalar($webhookAmount) || !is_numeric((string)$webhookAmount))) {
            return ['success' => false, 'message' => 'مبلغ وب‌هوک معتبر نیست'];
        }
        $scale = strtolower((string)($transaction->currency ?? 'irt')) === 'usdt' ? 8 : 4;
        if ($webhookAmount !== null && bccomp((string)$webhookAmount, (string)$transaction->amount, $scale) !== 0) {
            $this->logger->error('reconciliation.amount_mismatch', [
                'transaction_id' => $transaction->id,
                'transaction_amount' => $transaction->amount,
                'webhook_amount' => $webhookAmount,
            ]);
            return ['success' => false, 'message' => 'مبلغ تراکنش با مبلغ پرداخت شده مطابقت ندارد'];
        }

        $currency = strtolower((string)($transaction->currency ?? 'irt'));
        $webhookCurrencyValue = $webhookData['currency'] ?? null;
        if ($webhookCurrencyValue !== null && !is_string($webhookCurrencyValue)) {
            return ['success' => false, 'message' => 'ارز وب‌هوک معتبر نیست'];
        }
        $webhookCurrency = $webhookCurrencyValue === null ? null : strtolower($webhookCurrencyValue);
        if ($webhookCurrency !== null && $webhookCurrency !== $currency) {
            $this->logger->error('reconciliation.currency_mismatch', [
                'transaction_id' => $transaction->id,
                'transaction_currency' => $currency,
                'webhook_currency' => $webhookCurrency,
            ]);
            return ['success' => false, 'message' => 'ارز تراکنش با ارز پرداخت شده مطابقت ندارد'];
        }

        $amount = (string)$transaction->amount;

        // 1. Update status atomicaly
        $affected = $this->db->execute(
            "UPDATE transactions 
             SET status = 'completed', 
                 updated_at = :updated_at, 
                 verified_at = :verified_at, 
                 metadata = :metadata 
             WHERE id = :id AND status = 'pending'",
            [
                'id' => (int)$transaction->id,
                'updated_at' => date('Y-m-d H:i:s'),
                'verified_at' => date('Y-m-d H:i:s'),
                'metadata' => json_encode(array_merge((array)(json_decode($transaction->metadata ?? '{}', true) ?? []), ['reconciled_webhook' => $webhookData]))
            ]
        );

        if ($affected === 0) {
            return ['success' => false, 'message' => 'تراکنش قبلاً پردازش شده یا در وضعیت معلق نیست'];
        }

        // 2. Perform financial operations in the current transaction
        switch ($transaction->type) {
            case 'deposit':
            case 'crypto_deposit':
            case 'payment':
                if ($transaction->user_id) {
                    $this->walletService->depositInTransaction(
                        (int)$transaction->user_id,
                        $amount,
                        $currency,
                        [
                            'type' => $transaction->type,
                            'transaction_id' => $transaction->id,
                            'idempotency_key' => "recon_dep_" . $transaction->id
                        ]
                    );
                }
                break;

            case 'withdrawal':
                if ($transaction->user_id) {
                    $this->walletService->completeWithdrawal(
                        (int)$transaction->user_id,
                        $amount,
                        $currency,
                        (string)($transaction->transaction_id ?? null)
                    );
                }
                break;
            
            default:
                $this->logger->info('reconciliation.type_handled_default', ['type' => $transaction->type, 'tx' => $transaction->id]);
                break;
        }

        return ['success' => true, 'message' => 'تراکنش با موفقیت نهایی شد'];
    }

    /**
     * Process failed payment securely
     * @param TransactionRow $transaction
     * @param array<string, mixed> $webhookData
     * @return ReconciliationResult
     */
    private function processFailedPayment(object $transaction, array $webhookData): array
    {
        $affected = $this->db->execute(
            "UPDATE transactions 
             SET status = 'failed', 
                 updated_at = :updated_at, 
                 metadata = :metadata 
             WHERE id = :id AND status = 'pending'",
            [
                'id' => (int)$transaction->id,
                'updated_at' => date('Y-m-d H:i:s'),
                'metadata' => json_encode(array_merge((array)(json_decode($transaction->metadata ?? '{}', true) ?? []), [
                    'failure_reason' => $webhookData['failure_reason'] ?? 'Gateway failure signal',
                    'reconciled_webhook' => $webhookData
                ]))
            ]
        );

        if ($affected === 0) {
            return ['success' => false, 'message' => 'تراکنش قبلاً پردازش شده یا در وضعیت معلق نیست'];
        }

        if ($transaction->type === 'withdrawal' && $transaction->user_id) {
            $this->walletService->cancelWithdrawal(
                (int)$transaction->user_id,
                (string)$transaction->amount,
                (string)$transaction->currency,
                (string)($transaction->transaction_id ?? null)
            );
        }

        return ['success' => true, 'message' => 'وضعیت تراکنش به شکست تغییر یافت'];
    }

    /**
     * Create orphan transaction record safely.
     *
     * @param array<string, mixed> $webhookData
     * @return TransactionRow
     */
    private function createOrphanTransaction(array $webhookData): object
    {
        $externalId = $this->scalarString(
            $webhookData['transaction_id'] ?? $webhookData['reference_id'] ?? null,
            'orphan_' . time()
        );
        $existing = $this->db->fetch(
            "SELECT * FROM transactions WHERE external_id = ? LIMIT 1 FOR UPDATE",
            [$externalId]
        );
        if ($existing !== null) {
            return $this->requireTransactionRow($existing);
        }

        $amount = $this->scalarString($webhookData['amount'] ?? null, '0');
        if (!is_numeric($amount)) {
            $amount = '0';
        }
        $currency = strtolower($this->scalarString($webhookData['currency'] ?? null, 'irt'));
        if (!in_array($currency, ['irt', 'usdt'], true)) {
            $currency = 'irt';
        }
        $created = $this->transactionModel->createTransaction([
            'user_id' => null, // Never trust or assign user_id directly from raw webhook data
            'type' => 'orphan_payment',
            'amount' => $amount,
            'currency' => $currency,
            'status' => 'pending',
            'external_id' => $externalId,
            'gateway' => $this->scalarString($webhookData['gateway'] ?? null, 'unknown'),
            'metadata' => json_encode($webhookData, JSON_UNESCAPED_UNICODE),
            // These identify a system-originated orphan record; raw webhook values are never trusted.
            'ip_address' => '0.0.0.0',
            'device_fingerprint' => 'webhook-orphan:' . hash('sha256', $externalId),
            'created_at' => date('Y-m-d H:i:s'),
        ]);
        if ($created === null) {
            throw new \RuntimeException('Failed to create orphan transaction.');
        }

        return $this->requireTransactionRow($created);
    }

    /**
     * Verification check for accounting consistency (Wallet balance vs Ledger history)
     * @return ConsistencyResult
     */
    public function verifyConsistency(int $userId, string $currency = 'irt'): array
    {
        try {
            $currency = strtolower((string)$currency);
            $scale = $currency === 'usdt' ? 8 : 4;

            $wallet = $this->walletModel->findByUserId($userId);
            $balanceField = $currency === 'usdt' ? 'balance_usdt' : 'balance_irt';
            if ($wallet === null) {
                return ['valid' => false, 'message' => 'کیف پول کاربر برای تطبیق یافت نشد'];
            }
            $walletBalance = (string)($wallet->$balanceField ?? '0');

            $account = "wallet:{$userId}";
            $ledgerResult = $this->db->fetch(
                "SELECT SUM(debit) as total_debit, SUM(credit) as total_credit
                 FROM ledger_entries
                 WHERE account = ? AND currency = ?",
                [$account, $currency]
            );
            
            $debitSum = $ledgerResult ? (string)($ledgerResult->total_debit ?? '0') : '0';
            $creditSum = $ledgerResult ? (string)($ledgerResult->total_credit ?? '0') : '0';
            $ledgerBalance = \Core\ValueObjects\Money::fromString((string)($debitSum))->subtract(\Core\ValueObjects\Money::fromString((string)($creditSum)))->getAmount();

            $diff = \Core\ValueObjects\Money::fromString((string)($walletBalance))->subtract(\Core\ValueObjects\Money::fromString((string)($ledgerBalance)))->getAmount();
            $absDiff = (\Core\ValueObjects\Money::fromString((string)('0'))->isGreaterThan(\Core\ValueObjects\Money::fromString((string)($diff)))) ? \Core\ValueObjects\Money::fromString((string)($diff))->multiply((string)('-1'))->getAmount() : $diff;

            $tolerance = $currency === 'usdt' ? '0.0001' : '1.0000';

            if (\Core\ValueObjects\Money::fromString((string)($absDiff))->isGreaterThan(\Core\ValueObjects\Money::fromString((string)($tolerance)))) {
                return [
                    'valid' => false,
                    'message' => "عدم همخوانی بالانس. کیف پول: {$walletBalance}، دفتر کل: {$ledgerBalance}، تفاضل: {$absDiff}"
                ];
            }

            return ['valid' => true, 'message' => 'تراز مالی صحیح است'];
        } catch (\Throwable $e) {
            $this->logger->error('reconciliation.consistency_check.failed', [
                'user_id' => $userId,
                'currency' => $currency,
                'error' => $e->getMessage()
            ]);
            return ['valid' => false, 'message' => 'خطای سیستمی در سیستم ترازگیری: ' . $e->getMessage()];
        }
    }


    /**
     * Passive financial integrity audit. Does not mutate balances; only reports drift.
     * @return array<string, mixed>
     */
    public function auditLedgerIntegrity(int $walletLimit = 500): array
    {
        $walletLimit = max(1, min(5000, $walletLimit));
        $result = [
            'ledger_imbalances' => [],
            'wallet_mismatches' => [],
            'checked_wallets' => 0,
            'ok' => true,
        ];

        try {
            $imbalances = $this->ledgerService->findImbalancedTransactions(100);
            foreach ($imbalances as $row) {
                $result['ledger_imbalances'][] = [
                    'transaction_id' => (string)($row->transaction_id ?? ''),
                    'currency' => (string)($row->currency ?? ''),
                    'debit' => (string)($row->total_debit ?? '0'),
                    'credit' => (string)($row->total_credit ?? '0'),
                    'legs' => (int)($row->legs ?? 0),
                ];
            }

            $wallets = $this->db->fetchAll(
                "SELECT user_id, balance_irt, balance_usdt
                 FROM wallets
                 ORDER BY updated_at DESC
                 LIMIT {$walletLimit}"
            );

            // ✅ N+1 FIX: one batch ledger query for all wallet rows.
            $validWallets = array_filter($wallets, fn(\stdClass $wallet): bool => (int)($wallet->user_id ?? 0) > 0);
            $accountKeys  = array_map(fn($w) => "wallet:{$w->user_id}", $validWallets);

            $ledgerBalances = $this->ledgerService->getBatchAccountBalance(
                $accountKeys,
                ['irt', 'usdt']
            );
            // ──────────────────────────────────────────────────────────────

            foreach ($validWallets as $wallet) {
                $userId = (int)($wallet->user_id ?? 0);
                $result['checked_wallets']++;
                foreach (['irt' => 4, 'usdt' => 8] as $currency => $scale) {
                    $field         = $currency === 'usdt' ? 'balance_usdt' : 'balance_irt';
                    $walletBalance = (string)($wallet->{$field} ?? '0');
                    // مقدار از map — بدون query اضافه
                    $ledgerBalance = $ledgerBalances["wallet:{$userId}:{$currency}"] ?? '0';
                    $diff    = \Core\ValueObjects\Money::fromString($walletBalance)->subtract(\Core\ValueObjects\Money::fromString($ledgerBalance))->getAmount();
                    $absDiff = \Core\ValueObjects\Money::fromString('0')->isGreaterThan(\Core\ValueObjects\Money::fromString($diff))
                        ? \Core\ValueObjects\Money::fromString($diff)->multiply('-1')->getAmount()
                        : $diff;
                    $tolerance = $currency === 'usdt' ? '0.0001' : '1.0000';

                    if (\Core\ValueObjects\Money::fromString($absDiff)->isGreaterThan(\Core\ValueObjects\Money::fromString($tolerance))) {
                        $result['wallet_mismatches'][] = [
                            'user_id'        => $userId,
                            'currency'       => $currency,
                            'wallet_balance' => $walletBalance,
                            'ledger_balance' => $ledgerBalance,
                            'diff' => $absDiff,
                        ];
                    }
                }
            }

            $result['ok'] = empty($result['ledger_imbalances']) && empty($result['wallet_mismatches']);
            if (!$result['ok']) {
                $this->logger->critical('reconciliation.ledger_integrity_drift', [
                    'ledger_imbalances' => count($result['ledger_imbalances']),
                    'wallet_mismatches' => count($result['wallet_mismatches']),
                ]);
            }

            return $result;
        } catch (\Throwable $e) {
            $this->logger->error('reconciliation.ledger_integrity_audit_failed', [
                'error' => $e->getMessage(),
            ]);
            return array_merge($result, ['ok' => false, 'error' => $e->getMessage()]);
        }
    }

    // =========================================================================
    // Section 8.5 / 8.7 — Safe Stuck Withdrawal Review Workflow
    // =========================================================================
    //
    // اصول طراحی:
    //   - هرگز یک برداشت را خودسرانه completed نمی‌کنیم.
    //   - Detect → Flag → Notify-admin-via-Outbox.
    //   - Auto-fix (refund) متعلق به WithdrawalAdminService::autoResolveStuck() است
    //     چون قفل wallet + قفل withdrawal و state-machine آنجا متمرکز است.
    //
    // این متدها فقط روی جدول withdrawal_reviews کار می‌کنند و یک admin
    // notification از طریق Outbox (durable, retryable) صادر می‌کنند.

    /**
     * شناسایی برداشت‌های گیرکرده و ثبت در withdrawal_reviews (idempotent).
     *
     * @return array{scanned: int, flagged: int, skipped: int, notified: int}
     */
    public function flagStuckWithdrawals(
        int $olderThanMinutes = self::DEFAULT_STUCK_MINUTES,
        int $limit = self::STUCK_SCAN_BATCH
    ): array {
        $olderThanMinutes = max(15, min(10080, $olderThanMinutes));
        $limit = max(1, min(500, $limit));

        $result = ['scanned' => 0, 'flagged' => 0, 'skipped' => 0, 'notified' => 0];

        if (!$this->stuckReviewTablesExist()) {
            $this->logger->warning('reconciliation.stuck_review.skipped', [
                'reason' => 'required tables (withdrawals/withdrawal_reviews) missing',
            ]);
            return $result;
        }

        try {
            // Discovery is read-only. Each candidate is locked together with its review row
            // in flagOneStuckWithdrawal(); FOR UPDATE here would be released under autocommit.
            $rows = $this->db->fetchAll(
                "SELECT w.id, w.user_id, w.amount, w.currency, w.status,
                        w.transaction_id, w.created_at, w.updated_at,
                        t.status AS transaction_status, t.updated_at AS transaction_updated_at
                 FROM withdrawals w
                 LEFT JOIN transactions t ON t.transaction_id = w.transaction_id
                 WHERE w.status IN ('pending','processing')
                   AND w.created_at < DATE_SUB(NOW(), INTERVAL ? MINUTE)
                 ORDER BY w.created_at ASC
                 LIMIT ?",
                [$olderThanMinutes, $limit]
            );
        } catch (\Throwable $e) {
            $this->logger->error('reconciliation.stuck_review.detect_failed', [
                'error' => $e->getMessage(),
            ]);
            return $result;
        }

        foreach ($rows as $row) {
            $result['scanned']++;
            try {
                $row = $this->requireStuckWithdrawalRow($row);
                $flag = $this->flagOneStuckWithdrawal($row);
                if ($flag['flagged']) {
                    $result['flagged']++;
                    if ($flag['notified']) {
                        $result['notified']++;
                    }
                } else {
                    $result['skipped']++;
                }
            } catch (\Throwable $e) {
                $result['skipped']++;
                $this->logger->error('reconciliation.stuck_review.flag_failed', [
                    'withdrawal_id' => $row->id ?? null,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        if ($result['flagged'] > 0) {
            $this->logger->warning('reconciliation.stuck_review.flagged_batch', $result);
        }

        return $result;
    }

    /**
     * @param StuckWithdrawalRow $row
     * @return array{flagged: bool, notified: bool, review_id: int|null}
     */
    private function flagOneStuckWithdrawal(object $row): array
    {
        $withdrawalId = (int)$row->id;
        $userId       = (int)$row->user_id;
        $detected     = (string)$row->status;
        $txStatus     = $row->transaction_status !== null ? (string)$row->transaction_status : null;
        $stuckMinutes = $this->stuckMinutesSince((string)$row->created_at);
        $severity     = $this->classifyStuckSeverity($stuckMinutes, $txStatus);
        $reasonCode   = $this->classifyStuckReason($detected, $txStatus);

        $startedTx = !$this->db->inTransaction();
        if ($startedTx) {
            $this->db->beginTransaction();
        }

        try {
            $existing = $this->db->fetch(
                "SELECT id FROM withdrawal_reviews
                 WHERE withdrawal_id = :wid
                   AND review_status IN ('open','in_progress')
                 LIMIT 1 FOR UPDATE",
                ['wid' => $withdrawalId]
            );

            if ($existing) {
                if ($startedTx) { $this->db->commit(); }
                return ['flagged' => false, 'notified' => false, 'review_id' => (int)$existing->id];
            }

            $stmt = $this->db->prepare(
                "INSERT INTO withdrawal_reviews
                    (withdrawal_id, user_id, detected_status, transaction_status,
                     stuck_minutes, review_status, severity, reason_code,
                     details, created_at, updated_at)
                 VALUES
                    (?, ?, ?, ?, ?, 'open', ?, ?, ?, NOW(), NOW())"
            );

            $details = json_encode([
                'amount'                 => (string)($row->amount ?? '0'),
                'currency'               => (string)($row->currency ?? ''),
                'transaction_id'         => $row->transaction_id ?? null,
                'created_at'             => $row->created_at ?? null,
                'transaction_updated_at' => $row->transaction_updated_at ?? null,
            ], JSON_UNESCAPED_UNICODE);

            $stmt->execute([
                $withdrawalId, $userId, $detected, $txStatus,
                $stuckMinutes, $severity, $reasonCode, $details,
            ]);

            $reviewId = (int)$this->db->getPdo()->lastInsertId();

            $notified = $this->recordStuckAdminOutbox(
                $reviewId, $withdrawalId, $userId, $stuckMinutes,
                $severity, $reasonCode,
                (string)($row->amount ?? '0'), (string)($row->currency ?? '')
            );

            if ($notified) {
                $this->db->execute(
                    "UPDATE withdrawal_reviews
                     SET notified_admin_at = NOW(), updated_at = NOW()
                     WHERE id = ?",
                    [$reviewId]
                );
            }

            if ($startedTx) { $this->db->commit(); }

            $this->auditTrail->record('withdrawal.review.opened', $userId, [
                'review_id'     => $reviewId,
                'withdrawal_id' => $withdrawalId,
                'stuck_minutes' => $stuckMinutes,
                'severity'      => $severity,
                'reason_code'   => $reasonCode,
            ]);

            return ['flagged' => true, 'notified' => $notified, 'review_id' => $reviewId];
        } catch (\Throwable $e) {
            if ($startedTx && $this->db->inTransaction()) {
                $this->db->rollback();
            }
            throw $e;
        }
    }

    private function recordStuckAdminOutbox(
        int $reviewId, int $withdrawalId, int $userId, int $stuckMinutes,
        string $severity, string $reasonCode, string $amount, string $currency
    ): bool {
        if (!$this->outbox) {
            return false;
        }

        $title   = 'برداشت گیرکرده — بررسی نیاز است';
        $message = sprintf(
            'برداشت #%d مربوط به کاربر #%d بیش از %d دقیقه در وضعیت مانده است. (severity=%s, reason=%s)',
            $withdrawalId, $userId, $stuckMinutes, $severity, $reasonCode
        );
        $priority = match ($severity) {
            'critical' => 'urgent',
            'high'     => 'high',
            'medium'   => 'normal',
            default    => 'low',
        };

        try {
            return (bool)$this->outbox->record(
                'withdrawal_review',
                (string)$reviewId,
                'notification.stuck_withdrawal_detected',
                [
                    'notification' => [
                        'method' => 'sendToAdmins',
                        'args'   => [
                            'withdrawal_review',
                            $title,
                            $message,
                            [
                                'review_id'     => $reviewId,
                                'withdrawal_id' => $withdrawalId,
                                'user_id'       => $userId,
                                'stuck_minutes' => $stuckMinutes,
                                'severity'      => $severity,
                                'reason_code'   => $reasonCode,
                                'amount'        => $amount,
                                'currency'      => $currency,
                                'action_url'    => '/admin/withdrawals/review?id=' . $withdrawalId,
                                'action_text'   => 'بررسی درخواست',
                            ],
                            $priority,
                        ],
                    ],
                ]
            );
        } catch (\Throwable $e) {
            $this->logger->warning('reconciliation.stuck_review.outbox_failed', [
                'review_id' => $reviewId,
                'error'     => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Candidates eligible for safe auto-resolution. Only deterministic cases:
     *   processing withdrawal whose linked transaction has been
     *   failed/cancelled for at least $stableMinutes minutes.
     *
     * Used by WithdrawalAdminService::autoResolveStuck() — kept here because the
     * query touches the reconciliation domain (withdrawals + transactions + reviews).
     * @return list<\stdClass>
     */
    public function findAutoFixCandidates(int $stableMinutes, int $limit): array
    {
        $stableMinutes = max(5, min(1440, $stableMinutes));
        $limit         = max(1, min(200, $limit));

        if (!$this->stuckReviewTablesExist()) {
            return [];
        }

        try {
            return $this->db->fetchAll(
                "SELECT r.id AS review_id, w.id AS withdrawal_id, w.user_id, w.amount,
                        w.currency, w.status AS withdrawal_status, w.transaction_id,
                        t.status AS transaction_status, t.updated_at AS tx_updated_at
                 FROM withdrawal_reviews r
                 JOIN withdrawals w ON w.id = r.withdrawal_id
                 LEFT JOIN transactions t ON t.transaction_id = w.transaction_id
                 WHERE r.review_status = 'open'
                   AND w.status = 'processing'
                   AND t.status IN ('failed','cancelled')
                   AND t.updated_at IS NOT NULL
                   AND t.updated_at < DATE_SUB(NOW(), INTERVAL ? MINUTE)
                 ORDER BY r.created_at ASC
                 LIMIT ?",
                [$stableMinutes, $limit]
            );
        } catch (\Throwable $e) {
            $this->logger->error('reconciliation.stuck_review.candidates_query_failed', [
                'error' => $e->getMessage(),
            ]);
            return [];
        }
    }

    /** @return list<\stdClass> */
    public function listOpenReviews(int $limit = 50, int $offset = 0): array
    {
        $limit  = max(1, min(500, $limit));
        $offset = max(0, $offset);
        if (!$this->stuckReviewTablesExist()) {
            return [];
        }
        return $this->db->fetchAll(
            "SELECT r.*, w.amount AS withdrawal_amount, w.currency AS withdrawal_currency,
                    w.status AS current_withdrawal_status
             FROM withdrawal_reviews r
             JOIN withdrawals w ON w.id = r.withdrawal_id
             WHERE r.review_status IN ('open','in_progress')
             ORDER BY FIELD(r.severity,'critical','high','medium','low'), r.created_at ASC
             LIMIT ? OFFSET ?",
            [$limit, $offset]
        );
    }

    public function adminResolveReview(int $reviewId, int $adminId, string $note): bool
    {
        $note = mb_substr(trim((string)$note), 0, 1000);
        if ($note === '') {
            throw new \InvalidArgumentException('Resolution note is required.');
        }
        $affected = (int)$this->db->execute(
            "UPDATE withdrawal_reviews
             SET review_status = 'admin_resolved',
                 resolved_at = NOW(), resolved_by = ?,
                 resolution_note = ?, updated_at = NOW()
             WHERE id = ? AND review_status IN ('open','in_progress')",
            [$adminId, $note, $reviewId]
        );
        if ($affected > 0) {
            $this->auditTrail->record('withdrawal.review.admin_resolved', null, [
                'review_id' => $reviewId, 'note' => $note,
            ], $adminId);
        }
        return $affected > 0;
    }

    public function dismissReview(int $reviewId, int $adminId, string $note): bool
    {
        $note = mb_substr(trim((string)$note), 0, 1000);
        $affected = (int)$this->db->execute(
            "UPDATE withdrawal_reviews
             SET review_status = 'dismissed',
                 resolved_at = NOW(), resolved_by = ?,
                 resolution_note = ?, updated_at = NOW()
             WHERE id = ? AND review_status IN ('open','in_progress')",
            [$adminId, $note, $reviewId]
        );
        if ($affected > 0) {
            $this->auditTrail->record('withdrawal.review.dismissed', null, [
                'review_id' => $reviewId, 'note' => $note,
            ], $adminId);
        }
        return $affected > 0;
    }

    /**
     * Used internally by WithdrawalAdminService::autoResolveStuck() to mark the
     * review row as in_progress / auto_resolved without breaking the
     * single-source-of-truth principle. Returns affected row count.
     */
    public function markReviewInProgress(int $reviewId, ?int $adminBotId): int
    {
        return (int)$this->db->execute(
            "UPDATE withdrawal_reviews
             SET review_status = 'in_progress', updated_at = NOW(),
                 assigned_admin_id = COALESCE(assigned_admin_id, ?)
             WHERE id = ? AND review_status = 'open'",
            [$adminBotId, $reviewId]
        );
    }

    public function markReviewAutoResolved(int $reviewId, ?int $adminBotId, string $note): int
    {
        return (int)$this->db->execute(
            "UPDATE withdrawal_reviews
             SET review_status = 'auto_resolved',
                 resolved_at = NOW(),
                 resolved_by = ?,
                 resolution_note = CONCAT_WS(' | ', resolution_note, ?),
                 updated_at = NOW()
             WHERE id = ?",
            [$adminBotId, mb_substr($note, 0, 500), $reviewId]
        );
    }

    public function markReviewOpenAgain(int $reviewId, string $note): int
    {
        return (int)$this->db->execute(
            "UPDATE withdrawal_reviews
             SET review_status = 'open',
                 resolution_note = CONCAT_WS(' | ', resolution_note, ?),
                 updated_at = NOW()
             WHERE id = ?",
            [mb_substr($note, 0, 500), $reviewId]
        );
    }

    /**
     * Backward-compatible alias: the previous passive detector returned raw rows.
     * Kept for any external caller; new code should use flagStuckWithdrawals().
     * @return list<\stdClass>
     */
    public function detectStuckWithdrawals(int $olderThanMinutes = 120, int $limit = 100): array
    {
        $olderThanMinutes = max(15, min(10080, $olderThanMinutes));
        $limit            = max(1, min(500, $limit));
        try {
            $rows = $this->db->fetchAll(
                "SELECT w.id, w.user_id, w.amount, w.currency, w.status,
                        w.transaction_id, w.created_at, t.status AS transaction_status
                 FROM withdrawals w
                 LEFT JOIN transactions t ON t.transaction_id = w.transaction_id
                 WHERE w.status IN ('pending','processing')
                   AND w.created_at < DATE_SUB(NOW(), INTERVAL ? MINUTE)
                 ORDER BY w.created_at ASC
                 LIMIT ?",
                [$olderThanMinutes, $limit]
            );
            if ($rows !== []) {
                $this->logger->warning('reconciliation.stuck_withdrawals_detected', [
                    'count' => count($rows),
                    'older_than_minutes' => $olderThanMinutes,
                ]);
            }
            return $rows;
        } catch (\Throwable $e) {
            $this->logger->error('reconciliation.stuck_withdrawals_detection_failed', [
                'error' => $e->getMessage(),
            ]);
            return [];
        }
    }

    private function stuckReviewTablesExist(): bool
    {
        try {
            $withdrawals = $this->db->fetchColumn('SHOW TABLES LIKE ?', ['withdrawals']);
            $reviews = $this->db->fetchColumn('SHOW TABLES LIKE ?', ['withdrawal_reviews']);
            return $withdrawals !== null && $reviews !== null;
        } catch (\Throwable) {
            return false;
        }
    }

    private function stuckMinutesSince(string $datetime): int
    {
        $ts = strtotime($datetime);
        if (!$ts) { return 0; }
        return max(0, (int)floor((time() - $ts) / 60));
    }

    private function classifyStuckSeverity(int $stuckMinutes, ?string $txStatus): string
    {
        if ($txStatus !== null && in_array($txStatus, ['failed', 'cancelled'], true)) {
            return 'critical';
        }
        if ($stuckMinutes >= 24 * 60) return 'critical';
        if ($stuckMinutes >= 6  * 60) return 'high';
        if ($stuckMinutes >= 2  * 60) return 'medium';
        return 'low';
    }

    private function classifyStuckReason(string $detected, ?string $txStatus): string
    {
        if ($detected === 'processing' && $txStatus !== null && in_array($txStatus, ['failed','cancelled'], true)) {
            return 'orphan_processing';
        }
        if ($detected === 'processing' && $txStatus === 'pending') {
            return 'processing_tx_pending';
        }
        if ($detected === 'pending' && $txStatus === null) {
            return 'pending_no_tx';
        }
        if ($detected === 'pending') {
            return 'pending_too_long';
        }
        return 'unknown';
    }


    /**
     * Gateway-independent hourly audit for pending transactions.
     *
     * This service has no provider verification client, so it must not mutate a
     * pending payment or claim it was reconciled. Callers receive the number of
     * candidates explicitly deferred to the provider-specific verification flow.
     *
     * @return array{total: int, reconciled: 0, failed: 0, deferred: int}
     */
    public function autoReconcilePendingTransactions(int $limit = 50): array
    {
        $limit = max(1, min(500, $limit));
        $pendingTransactions = $this->db->fetchAll(
            "SELECT id FROM transactions
             WHERE status = 'pending'
               AND created_at < DATE_SUB(NOW(), INTERVAL 1 HOUR)
             ORDER BY created_at ASC LIMIT ?",
            [$limit]
        );
        $total = count($pendingTransactions);

        if ($total > 0) {
            $this->logger->info('reconciliation.pending_transactions_deferred', ['count' => $total]);
        }

        return ['total' => $total, 'reconciled' => 0, 'failed' => 0, 'deferred' => $total];
    }
}

