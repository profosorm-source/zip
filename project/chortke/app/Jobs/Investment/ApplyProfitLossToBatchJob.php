<?php

declare(strict_types=1);

namespace App\Jobs\Investment;

use App\Domain\Financial\Services\LedgerService;
use App\Models\Investment;
use App\Models\Transaction;

/**
 * @phpstan-type FeeTier array{min: string, fee: string}
 * @phpstan-type BatchResult array{success: bool, processed: int, message?: string}
 */
class ApplyProfitLossToBatchJob
{
    private \Core\Database $db;
    private \App\Models\Investment $investmentModel;
    private \App\Services\Settings\AppSettings $appSettings;
    private ?\App\Services\FeatureFlagService $featureFlagService;
    private \App\Models\InvestmentProfit $profitModel;
    private \App\Services\StateMachineService $stateMachine;
    private ?\App\Services\CurrencyService $currencyService;
    private \App\Contracts\LoggerInterface $logger;
    private ?\App\Contracts\OutboxServiceInterface $outbox = null;
    private Transaction $transactionModel;
    private LedgerService $ledgerService;

    public function __construct(
        \Core\Database $db,
        \App\Models\Investment $investmentModel,
        \App\Services\Settings\AppSettings $appSettings,
        \App\Models\InvestmentProfit $profitModel,
        \App\Services\StateMachineService $stateMachine,
        \App\Contracts\LoggerInterface $logger,
        Transaction $transactionModel,
        LedgerService $ledgerService,
        ?\App\Services\FeatureFlagService $featureFlagService = null,
        ?\App\Services\CurrencyService $currencyService = null,
        ?\App\Contracts\OutboxServiceInterface $outbox = null
    ) {
        $this->db = $db;
        $this->investmentModel = $investmentModel;
        $this->appSettings = $appSettings;
        $this->profitModel = $profitModel;
        $this->stateMachine = $stateMachine;
        $this->logger = $logger;
        $this->transactionModel = $transactionModel;
        $this->ledgerService = $ledgerService;
        $this->featureFlagService = $featureFlagService;
        $this->currencyService = $currencyService;
        $this->outbox = $outbox;
    }

    private function decimalValue(mixed $value, string $default = '0'): string
    {
        if (!is_scalar($value)) return $default;
        $decimal = trim((string)$value);
        return is_numeric($decimal) ? $decimal : $default;
    }

    /**
     * @param list<FeeTier> $default
     * @return list<FeeTier>
     */
    private function normalizeFeeTiers(mixed $value, array $default): array
    {
        if (!is_array($value)) return $default;
        $tiers = [];
        foreach ($value as $tier) {
            if (!is_array($tier)) continue;
            $min = $this->decimalValue($tier['min'] ?? null, '0');
            $fee = $this->decimalValue($tier['fee'] ?? null, '0');
            if (bccomp($min, '0', 8) >= 0 && bccomp($fee, '0', 8) >= 0) {
                $tiers[] = ['min' => $min, 'fee' => $fee];
            }
        }
        return $tiers === [] ? $default : $tiers;
    }

    /**
     * @param list<int> $investmentIds
     * @return BatchResult
     */
    public function handle(array $investmentIds, int $tradingRecordId, string $percent, string $period, int $adminId): array
    {
        $investmentIds = array_values(array_unique(array_filter(
            $investmentIds,
            static fn(mixed $id): bool => is_int($id) && $id > 0
        )));
        if ($investmentIds === []) {
            throw new \InvalidArgumentException('حداقل یک سرمایه‌گذاری معتبر برای تسویه لازم است.');
        }
        if (!is_numeric($percent)
            || bccomp($percent, '0', 8) === 0
            || bccomp($percent, '-100', 8) < 0
            || bccomp($percent, '1000', 8) > 0) {
            throw new \InvalidArgumentException('درصد سود یا ضرر خارج از محدوده مجاز است.');
        }
        $period = trim($period);
        if ($period === '' || strlen($period) > 32) {
            throw new \InvalidArgumentException('دوره تسویه نامعتبر است.');
        }

        $this->db->beginTransaction();
        try {
            $trade = $this->db->fetch(
                "SELECT id, status FROM trading_records WHERE id = ? AND is_deleted = 0 FOR UPDATE",
                [$tradingRecordId]
            );
            if (!$trade || !in_array((string)$trade->status, ['closed', 'stopped'], true)) {
                throw new \Core\Exceptions\InvalidStateException('فقط رکورد ترید بسته‌شده قابل تسویه است.');
            }
            $unprocessedInvestmentIds = [];
            $investments = [];

            // H-I4 Fix: Exact Idempotency Check per investment to support safe retries in batched chunks
            $inClause = implode(',', array_fill(0, count($investmentIds), '?'));
            $stmt = $this->db->prepare(
                "SELECT investment_id FROM investment_profits 
                 WHERE trading_record_id = ? AND investment_id IN ($inClause)"
            );
            $stmt->execute(array_merge([$tradingRecordId], $investmentIds));
            $processedIds = $stmt->fetchAll(\PDO::FETCH_COLUMN) ?: [];

            // Filter out already processed investment IDs to ensure exact idempotency without batch lockouts
            $unprocessedInvestmentIds = array_diff($investmentIds, $processedIds);

            if (empty($unprocessedInvestmentIds)) {
                throw new \Core\Exceptions\InvalidStateException('تمام سرمایه‌گذاری‌های این بچ قبلاً پردازش شده‌اند.');
            }

            $investments = $this->investmentModel->findInIdsForUpdate($unprocessedInvestmentIds);
            if (empty($investments)) {
                throw new \Core\Exceptions\NotFoundException('سرمایه‌گذاری فعالی یافت نشد.');
            }

            // Re-check after acquiring investment row locks. The first check is
            // only an optimization; under REPEATABLE READ two workers may both
            // pass it before one waits on these locks. This locking current-read,
            // together with the unique DB index, owns the exactly-once invariant.
            $lockedInClause = implode(',', array_fill(0, count($unprocessedInvestmentIds), '?'));
            $lockedStmt = $this->db->prepare(
                "SELECT investment_id FROM investment_profits
                 WHERE trading_record_id = ? AND investment_id IN ($lockedInClause)
                 FOR UPDATE"
            );
            $lockedStmt->execute(array_merge([$tradingRecordId], $unprocessedInvestmentIds));
            $processedAfterLock = array_map('intval', $lockedStmt->fetchAll(\PDO::FETCH_COLUMN) ?: []);
            $unprocessedInvestmentIds = array_values(array_diff($unprocessedInvestmentIds, $processedAfterLock));
            if ($unprocessedInvestmentIds === []) {
                throw new \Core\Exceptions\InvalidStateException('تمام سرمایه‌گذاری‌های این بچ قبلاً پردازش شده‌اند.');
            }

            // Index only still-unprocessed investments by ID.
            $investmentMap = [];
            foreach ($investments as $inv) {
                if (in_array((int)$inv->id, $unprocessedInvestmentIds, true)) {
                    $investmentMap[(int)$inv->id] = $inv;
                }
            }

            // ── PRECISION FIX: خواندن درصدها به صورت string برای سازگاری با bcmath ──
            $defaultSiteFeePercent = $this->decimalValue($this->appSettings->get('investment_site_fee_percent', '10'), '10');
            $defaultTaxPercent = $this->decimalValue($this->appSettings->get('investment_tax_percent', '9'), '9');

            $siteFeePercent = $this->featureFlagService
                ? $this->decimalValue($this->featureFlagService->getConfig('investment_fees', 'site_fee_percent', $defaultSiteFeePercent), $defaultSiteFeePercent)
                : $defaultSiteFeePercent;
            $taxPercent = $this->featureFlagService
                ? $this->decimalValue($this->featureFlagService->getConfig('investment_fees', 'tax_percent', $defaultTaxPercent), $defaultTaxPercent)
                : $defaultTaxPercent;

            // خواندن ساختار پلکانی کارمزد برای جریان V2
            // min و fee به صورت string نگهداری می‌شوند تا bccomp بتواند مقایسه دقیق انجام دهد
            $defaultTiers = [
                ['min' => '0',     'fee' => $siteFeePercent],
                ['min' => '1000',  'fee' => bcsub($siteFeePercent, '2', 8)],
                ['min' => '5000',  'fee' => bcsub($siteFeePercent, '5', 8)],
                ['min' => '10000', 'fee' => bcsub($siteFeePercent, '8', 8)],
            ];
            $configuredTiers = $this->featureFlagService
                ? $this->featureFlagService->getConfig('investment_fees', 'fee_tiers', $defaultTiers)
                : $defaultTiers;
            $tiers = $this->normalizeFeeTiers($configuredTiers, $defaultTiers);

            $count = 0;

            foreach ($unprocessedInvestmentIds as $invId) {
                $inv = $investmentMap[$invId] ?? null;
                if (!$inv || $inv->status !== Investment::STATUS_ACTIVE) {
                    continue;
                }

                // ── PRECISION FIX: تمام محاسبات با Money / bcmath ────────────
                $currency         = 'USDT';
                $investBalance    = \Core\ValueObjects\Money::of((string)$inv->current_balance, $currency);
                $percentStr       = $percent;
                // سود/ضرر = موجودی × (درصد ÷ 100)
                $profitLossMoney  = $investBalance->percentage($percentStr);
                $isProfit         = $profitLossMoney->isPositive();

                $siteFee   = '0';
                $taxAmount = '0';
                $netAmount = $profitLossMoney->getAmount();

                if ($isProfit) {
                    // جریان‌های نسخه‌بندی شده (Versioned Flows)
                    if ($this->featureFlagService && $this->featureFlagService->isEnabled('investment_v2_calculation', $inv->user_id)) {
                        [$siteFee, $taxAmount, $netAmount] = $this->calculateNetProfitV2(
                            $profitLossMoney->getAmount(),
                            $investBalance->getAmount(),
                            $siteFeePercent,
                            $taxPercent,
                            $tiers,
                            $currency
                        );
                    } else {
                        [$siteFee, $taxAmount, $netAmount] = $this->calculateNetProfitV1(
                            $profitLossMoney->getAmount(),
                            $siteFeePercent,
                            $taxPercent,
                            $currency
                        );
                    }
                }

                $balanceBefore = $investBalance->getAmount();
                // balanceAfter = موجودی + سود/ضرر خالص  (با bcmath)
                $balanceAfterMoney = $investBalance->add(
                    \Core\ValueObjects\Money::of($netAmount, $currency)
                );
                $balanceAfter = $balanceAfterMoney->getAmount();

                $grossAmount = $profitLossMoney->getAmount();
                $settlementTransactionId = $this->recordSettlementAccounting(
                    (int)$inv->id,
                    (int)$inv->user_id,
                    $tradingRecordId,
                    $adminId,
                    $grossAmount,
                    $siteFee,
                    $taxAmount,
                    $period
                );

                $profitId = $this->profitModel->create([
                    'investment_id'       => $inv->id,
                    'user_id'             => $inv->user_id,
                    'amount'              => $grossAmount,
                    'gross_amount'        => $grossAmount,
                    'site_fee_amount'     => $siteFee,
                    'tax_amount'          => $taxAmount,
                    'net_amount'          => $netAmount,
                    'balance_before'      => $balanceBefore,
                    'balance_after'       => bccomp($balanceAfter, '0', 8) < 0 ? '0' : $balanceAfter,
                    'trading_record_id'   => $tradingRecordId,
                    'currency'            => 'usdt',
                    'profit_type'         => $isProfit ? 'profit' : 'loss',
                    'status'              => 'paid',
                    'transaction_id'      => $settlementTransactionId,
                    'period_date'         => date('Y-m-d'),
                    'period'              => $period,
                ]);
                if (!$profitId) {
                    throw new \Core\Exceptions\ApplicationException('ثبت رکورد سود یا ضرر سرمایه‌گذاری ناموفق بود.');
                }

                $existingTotalProfit = $this->decimalValue($inv->total_profit ?? '0');
                $existingTotalLoss = $this->decimalValue($inv->total_loss ?? '0');
                $existingProfitEarned = $this->decimalValue($inv->profit_earned ?? '0');
                $updateData = [
                    'current_balance' => bccomp($balanceAfter, '0', 8) < 0 ? '0' : $balanceAfter,
                    'total_profit' => $isProfit
                        ? bcadd($existingTotalProfit, $netAmount, 8)
                        : $existingTotalProfit,
                    'total_loss' => $isProfit
                        ? $existingTotalLoss
                        : bcadd($existingTotalLoss, ltrim($netAmount, '-'), 8),
                    'profit_earned' => bcadd($existingProfitEarned, $netAmount, 8),
                    'last_profit_date' => date('Y-m-d H:i:s'),
                ];

                if (bccomp($balanceAfter, '0', 8) <= 0
                    && $this->stateMachine->canTransition('investment', (string)$inv->status, Investment::STATUS_FROZEN)) {
                    $updateData['status'] = Investment::STATUS_FROZEN;
                }

                if (!$this->investmentModel->update((int)$inv->id, $updateData)) {
                    throw new \Core\Exceptions\ApplicationException('بروزرسانی مانده سرمایه‌گذاری ناموفق بود.');
                }

                $amountFormatted = $this->currencyService
                    ? $this->currencyService->formatAmount($netAmount, 'usdt')
                    : ($netAmount . ' USDT');
                
                $this->outbox?->record('investment', $inv->user_id, 'investment.profit_applied', [
                    'user_id' => $inv->user_id,
                    'amount_formatted' => $amountFormatted,
                    'period' => $period
                ]);
                $count++;
            }

            $this->db->commit();

            return ['success' => true, 'processed' => $count, 'idempotent_replay' => false];

        } catch (\Core\Exceptions\InvalidStateException $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollback();
            }
            $isReplay = str_contains($e->getMessage(), 'قبلاً پردازش');
            return [
                'success' => $isReplay,
                'message' => $e->getMessage(),
                'processed' => 0,
                'idempotent_replay' => $isReplay,
            ];
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollback();
            }
            $this->logger->error('investment_profit_error', ['message' => $e->getMessage()]);
            throw $e;
        }
    }

    private function recordSettlementAccounting(
        int $investmentId,
        int $userId,
        int $tradingRecordId,
        int $adminId,
        string $grossAmount,
        string $siteFee,
        string $taxAmount,
        string $period
    ): string {
        $baseMetadata = [
            'investment_id' => $investmentId,
            'user_id' => $userId,
            'trading_record_id' => $tradingRecordId,
            'period' => $period,
        ];

        if (bccomp($grossAmount, '0', 8) > 0) {
            $primaryTransactionId = $this->recordAccountingTransfer(
                $adminId,
                'investment_trading_profit',
                'external_trading_return',
                'investment_pool',
                $grossAmount,
                "سود ناخالص ترید #{$tradingRecordId} برای سرمایه‌گذاری #{$investmentId}",
                "investment_profit_{$tradingRecordId}_{$investmentId}",
                $baseMetadata
            );
            $this->recordAccountingTransfer(
                $adminId,
                'investment_platform_fee',
                'investment_pool',
                'platform_revenue',
                $siteFee,
                "کارمزد سود سرمایه‌گذاری #{$investmentId}",
                "investment_fee_{$tradingRecordId}_{$investmentId}",
                $baseMetadata
            );
            $this->recordAccountingTransfer(
                $adminId,
                'investment_tax',
                'investment_pool',
                'tax_payable',
                $taxAmount,
                "مالیات سود سرمایه‌گذاری #{$investmentId}",
                "investment_tax_{$tradingRecordId}_{$investmentId}",
                $baseMetadata
            );
            return $primaryTransactionId;
        }

        $lossAmount = ltrim($grossAmount, '-');
        return $this->recordAccountingTransfer(
            $adminId,
            'investment_trading_loss',
            'investment_pool',
            'external_trading_loss',
            $lossAmount,
            "زیان ترید #{$tradingRecordId} برای سرمایه‌گذاری #{$investmentId}",
            "investment_loss_{$tradingRecordId}_{$investmentId}",
            $baseMetadata
        );
    }

    /** @param array<string, mixed> $metadata */
    private function recordAccountingTransfer(
        int $adminId,
        string $type,
        string $debitAccount,
        string $creditAccount,
        string $amount,
        string $description,
        string $idempotencyKey,
        array $metadata
    ): string {
        if (bccomp($amount, '0', 8) <= 0) {
            return '';
        }

        $transaction = $this->transactionModel->createTransaction([
            'user_id' => $adminId,
            'type' => $type,
            'currency' => 'usdt',
            'amount' => $amount,
            'balance_before' => null,
            'balance_after' => null,
            'status' => 'completed',
            'description' => $description,
            'ref_id' => (string)int_value($metadata['investment_id'] ?? 0),
            'ref_type' => 'investment',
            'request_id' => 'investment_settlement_' . int_value($metadata['trading_record_id'] ?? 0),
            'ip_address' => 'system',
            'device_fingerprint' => 'investment-settlement',
            'idempotency_key' => $idempotencyKey,
            'metadata' => $metadata,
        ]);
        if (!$transaction) {
            throw new \Core\Exceptions\ApplicationException('ثبت تراکنش حسابداری تسویه سرمایه‌گذاری ناموفق بود.');
        }

        if (!$this->ledgerService->recordDoubleEntry(
            (string)$transaction->transaction_id,
            $debitAccount,
            $creditAccount,
            $amount,
            'usdt',
            $description,
            $metadata
        )) {
            throw new \Core\Exceptions\ApplicationException('ثبت دفتر کل تسویه سرمایه‌گذاری ناموفق بود.');
        }

        return (string)$transaction->transaction_id;
    }

    /** @return array{0: string, 1: string, 2: string} */
    private function calculateNetProfitV1(string $profitLossAmount, string $siteFeePercent, string $taxPercent, string $currency = 'USDT'): array
    {
        $siteFee = bcdiv(bcmul($profitLossAmount, $siteFeePercent, 8), '100', 8);
        $afterFee = bcsub($profitLossAmount, $siteFee, 8);
        $tax = bcdiv(bcmul($afterFee, $taxPercent, 8), '100', 8);
        $net = bcsub($afterFee, $tax, 8);
        return [$siteFee, $tax, $net];
    }

    /**
     * @param list<FeeTier> $tiers
     * @return array{0: string, 1: string, 2: string}
     */
    private function calculateNetProfitV2(string $profitLossAmount, string $investAmount, string $baseSiteFeePercent, string $taxPercent, array $tiers, string $currency = 'USDT'): array
    {
        usort($tiers, static fn(array $a, array $b): int => bccomp($a['min'], $b['min'], 8));
        $effective = $baseSiteFeePercent;
        foreach ($tiers as $tier) {
            if (bccomp($investAmount, $tier['min'], 8) >= 0) {
                $effective = $tier['fee'];
            }
        }
        if (bccomp($effective, '0', 8) < 0) {
            $effective = '0';
        }
        return $this->calculateNetProfitV1($profitLossAmount, $effective, $taxPercent, $currency);
    }
}
