<?php

declare(strict_types=1);

namespace App\Domain\Financial\Services;

use App\Models\LedgerEntry;
use Core\Database;
use App\Contracts\LoggerInterface;

class LedgerService
{
    private LedgerEntry $ledgerEntry;
    protected Database $db;
    private LoggerInterface $logger;

    public function __construct(LedgerEntry $ledgerEntry, Database $db, LoggerInterface $logger) {
        $this->ledgerEntry = $ledgerEntry;
        $this->db = $db;
        $this->logger = $logger;
    }

    /** @param array<string, mixed> $data */
    public function recordEntry(array $data): ?object
    {
        return $this->ledgerEntry->createEntry($data);
    }

    /** @param array<string, mixed> $context */
    private function logError(string $operation, array $context = []): void
    {
        $this->logger->error($operation, $context);
    }

    /** @param array<string, mixed> $metadata */
    public function recordDoubleEntry(
        string $transactionId,
        string $debitAccount,
        string $creditAccount,
        string $amount,
        string $currency = 'irt',
        ?string $description = null,
        array $metadata = []
    ): bool {
        if (bccomp($amount, '0', 8) <= 0) {
            return false;
        }

        if (!$this->db->inTransaction()) {
            throw new \RuntimeException(
                'recordDoubleEntry MUST be called within an active transaction'
            );
        }

        try {
            $common = [
                'transaction_id' => $transactionId,
                'description' => $description,
                'metadata' => $metadata,
            ];

            $debit = $this->recordEntry(array_merge($common, [
                'account' => $debitAccount,
                'debit' => $amount,
                'credit' => 0,
                'currency' => $currency,
            ]));

            $credit = $this->recordEntry(array_merge($common, [
                'account' => $creditAccount,
                'debit' => 0,
                'credit' => $amount,
                'currency' => $currency,
            ]));

            if (!$debit || !$credit) {
                throw new \RuntimeException("Failed to write debit/credit ledger entries for transaction ID: {$transactionId}");
            }

            return true;
        } catch (\Throwable $e) {
            $this->logError('ledger.record_double_entry.failed', [
                'transaction_id' => $transactionId,
                'error' => $e->getMessage(),
            ]);
            \App\Services\Sentry\SentryExceptionHandler::captureException($e, null, [
                'operation'      => 'ledger.recordDoubleEntry',
                'transaction_id' => $transactionId,
                'debit_account'  => $debitAccount,
                'credit_account' => $creditAccount,
                'amount'         => $amount,
                'currency'       => $currency,
            ]);
            throw $e;
        }
    }

    private function fetchObject(\PDOStatement $statement): ?\stdClass
    {
        $row = $statement->fetch(\PDO::FETCH_OBJ);
        return $row instanceof \stdClass ? $row : null;
    }

    public function verifyTransactionBalance(string $transactionId): bool
    {
        try {
            $stmt = $this->db->prepare("SELECT SUM(debit) as total_debit, SUM(credit) as total_credit FROM ledger_entries WHERE transaction_id = ?");
            $stmt->execute([$transactionId]);
            $row = $this->fetchObject($stmt);
        } catch (\Throwable $e) {
            $this->logError('ledger.verify_transaction_balance.failed', [
                'transaction_id' => $transactionId,
                'error' => $e->getMessage(),
            ]);
            \App\Services\Sentry\SentryExceptionHandler::captureException($e, null, [
                'operation'      => 'ledger.verifyTransactionBalance',
                'transaction_id' => $transactionId,
            ]);
            return false;
        }

        if ($row === null) {
            $this->logError('ledger.verify_transaction_balance.no_rows', ['transaction_id' => $transactionId]);
            return false;
        }

        $debit  = (string) ($row->total_debit  ?? '0');
        $credit = (string) ($row->total_credit ?? '0');

        $balanced = \Core\ValueObjects\Money::fromString($debit)->getAmount()
                 === \Core\ValueObjects\Money::fromString($credit)->getAmount();

        // 🚨 captureAnomaly: debit ≠ credit — خرابی ساکت در دفتر مالی
        if (!$balanced) {
            try {
                $handler = \App\Services\Sentry\SentryExceptionHandler::getInstance();
                $handler->getErrorMonitor()->captureAnomaly(
                    'ledger_transaction_imbalance',
                    "Ledger imbalance: debit={$debit} credit={$credit} for transaction_id={$transactionId}",
                    [
                        'transaction_id' => $transactionId,
                        'total_debit'    => $debit,
                        'total_credit'   => $credit,
                        'diff'           => bcsub($debit, $credit, 8),
                    ],
                    'critical'
                );
            } catch (\Throwable $e) {
            \App\Services\Sentry\SentryExceptionHandler::captureException($e, null, ['operation' => 'ledger.internal']);
        }
        }

        return $balanced;
    }

    public function isLedgerBalanced(): bool
    {
        try {
            $stmt = $this->db->query("SELECT SUM(debit) as total_debit, SUM(credit) as total_credit FROM ledger_entries");
            $row = $this->fetchObject($stmt);
        } catch (\Throwable $e) {
            $this->logError('ledger.is_ledger_balanced.failed', ['error' => $e->getMessage()]);
            \App\Services\Sentry\SentryExceptionHandler::captureException($e, null, [
                'operation' => 'ledger.isLedgerBalanced',
            ]);
            return false;
        }

        if ($row === null) {
            $this->logError('ledger.is_ledger_balanced.no_rows');
            return false;
        }

        $debit  = (string) ($row->total_debit  ?? '0');
        $credit = (string) ($row->total_credit ?? '0');

        $balanced = \Core\ValueObjects\Money::fromString($debit)->getAmount()
                 === \Core\ValueObjects\Money::fromString($credit)->getAmount();

        // 🚨 captureAnomaly: کل دفتر مالی نامتعادل است
        if (!$balanced) {
            try {
                $handler = \App\Services\Sentry\SentryExceptionHandler::getInstance();
                $handler->getErrorMonitor()->captureAnomaly(
                    'ledger_global_imbalance',
                    "Global ledger imbalance: total_debit={$debit} total_credit={$credit}",
                    [
                        'total_debit'  => $debit,
                        'total_credit' => $credit,
                        'diff'         => bcsub($debit, $credit, 8),
                    ],
                    'critical'
                );
            } catch (\Throwable $e) {
            \App\Services\Sentry\SentryExceptionHandler::captureException($e, null, ['operation' => 'ledger.internal']);
        }
        }

        return $balanced;
    }

    public function getAccountBalance(string $account, string $currency = 'irt'): string
    {
        $currency = strtolower((string)$currency);
        $stmt = $this->db->prepare("SELECT COALESCE(SUM(debit), 0) AS total_debit, COALESCE(SUM(credit), 0) AS total_credit FROM ledger_entries WHERE account = ? AND currency = ?");
        $stmt->execute([$account, $currency]);
        $row = $this->fetchObject($stmt);
        return \Core\ValueObjects\Money::fromString((string)($row->total_debit ?? '0'))
            ->subtract(\Core\ValueObjects\Money::fromString((string)($row->total_credit ?? '0')))
            ->getAmount();
    }

    /**
     * getBatchAccountBalance — حل N+1 در ReconciliationService
     *
     * به جای N×2 query جداگانه، یک query واحد برای همه account‌ها اجرا می‌کند.
     *
     * @param  array<string>  $accounts  آرایه‌ای از account key مانند ['wallet:1','wallet:2']
     * @param  array<string>  $currencies ['irt','usdt'] یا ['irt']
     * @return array<string, string>  key: "account:currency"  value: net balance
     */
    public function getBatchAccountBalance(array $accounts, array $currencies = ['irt', 'usdt']): array
    {
        if (empty($accounts) || empty($currencies)) {
            return [];
        }

        $accountPlaceholders  = implode(',', array_fill(0, count($accounts), '?'));
        $currencyPlaceholders = implode(',', array_fill(0, count($currencies), '?'));
        $params = array_merge(array_values($accounts), array_values($currencies));

        $rows = $this->db->fetchAll(
            "SELECT account, currency,
                    COALESCE(SUM(debit), 0)  AS total_debit,
                    COALESCE(SUM(credit), 0) AS total_credit
             FROM ledger_entries
             WHERE account   IN ({$accountPlaceholders})
               AND currency  IN ({$currencyPlaceholders})
             GROUP BY account, currency",
            $params
        ) ?: [];

        $result = [];
        // مقدار پیش‌فرض صفر برای هر ترکیب account×currency
        foreach ($accounts as $account) {
            foreach ($currencies as $currency) {
                $result["{$account}:{$currency}"] = '0';
            }
        }

        foreach ($rows as $row) {
            $net = \Core\ValueObjects\Money::fromString((string)($row->total_debit ?? '0'))
                ->subtract(\Core\ValueObjects\Money::fromString((string)($row->total_credit ?? '0')))
                ->getAmount();
            $result["{$row->account}:{$row->currency}"] = $net;
        }

        return $result;
    }

    /** @return list<\stdClass> */
    public function findImbalancedTransactions(int $limit = 100): array
    {
        $limit = max(1, min(1000, $limit));
        return $this->db->fetchAll(
            "SELECT transaction_id, currency, COALESCE(SUM(debit), 0) AS total_debit, COALESCE(SUM(credit), 0) AS total_credit, COUNT(*) AS legs
             FROM ledger_entries
             GROUP BY transaction_id, currency
             HAVING ABS(total_debit - total_credit) > 0.00000001
             ORDER BY MAX(created_at) DESC
             LIMIT {$limit}"
        );
    }

    /** @return list<\stdClass> */
    public function findByTransactionId(string $transactionId): array
    {
        return $this->ledgerEntry->getByTransactionId($transactionId);
    }
}

