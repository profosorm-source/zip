<?php

declare(strict_types=1);

namespace App\Jobs\Prediction;

use App\Contracts\WalletServiceInterface;
use App\Domain\Financial\Services\LedgerService;
use App\Models\PredictionBet;
use App\Models\Transaction;
use App\Services\AuditTrail;
use App\Services\ScoreService;
use App\Services\StateMachineService;
use Core\Database;
use Core\ValueObjects\Money;

class SettleGameJob
{
    #[ \Core\Attributes\Inject ]
    private Database $db;

    #[ \Core\Attributes\Inject ]
    private StateMachineService $stateMachine;

    #[ \Core\Attributes\Inject ]
    private PredictionBet $betModel;

    #[ \Core\Attributes\Inject ]
    private AuditTrail $auditTrail;

    #[ \Core\Attributes\Inject ]
    private ?ScoreService $scoreService = null;

    #[ \Core\Attributes\Inject ]
    private WalletServiceInterface $walletService;

    #[ \Core\Attributes\Inject ]
    private Transaction $transactionModel;

    #[ \Core\Attributes\Inject ]
    private LedgerService $ledgerService;

    public function __construct(
        Database $db,
        StateMachineService $stateMachine,
        PredictionBet $betModel,
        AuditTrail $auditTrail,
        WalletServiceInterface $walletService,
        Transaction $transactionModel,
        LedgerService $ledgerService,
        ?ScoreService $scoreService = null
    ) {
        $this->db = $db;
        $this->stateMachine = $stateMachine;
        $this->betModel = $betModel;
        $this->auditTrail = $auditTrail;
        $this->walletService = $walletService;
        $this->transactionModel = $transactionModel;
        $this->ledgerService = $ledgerService;
        if ($scoreService !== null) {
            $this->scoreService = $scoreService;
        }
    }

    /** @return array<string, mixed> */
public function handle(int $gameId, string $result, int $adminId): array
    {
        if (!in_array($result, ['home', 'away', 'draw'], true)) {
            throw new \InvalidArgumentException('نتیجه باید home، away یا draw باشد.');
        }

        try {
            $this->db->beginTransaction();

            $game = $this->db->fetch("SELECT * FROM prediction_games WHERE id = ? FOR UPDATE", [$gameId]);
            if (!$game) {
                throw new \RuntimeException('بازی یافت نشد.');
            }
            if (!in_array((string)$game->status, ['open', 'closed'], true)) {
                throw new \RuntimeException('این بازی قابل تسویه نیست (وضعیت فعلی: ' . $game->status . ')');
            }
            if ((int)($game->winners_paid ?? 0) === 1) {
                throw new \RuntimeException('جوایز این بازی قبلاً تعیین تکلیف شده است.');
            }
            if (!$this->stateMachine->canTransition('prediction_game', (string)$game->status, 'finished')) {
                throw new \RuntimeException('تغییر وضعیت بازی به finished مجاز نیست.');
            }

            $affected = $this->db->execute("UPDATE prediction_games SET winners_paid = 1 WHERE id = ? AND winners_paid = 0", [$gameId]);
            if ($affected === 0) {
                throw new \RuntimeException('جوایز این بازی قبلاً پرداخت شده است.');
            }

            $pendingBets = $this->betModel->getPendingByGame($gameId);
            $totalPool = $this->sumBets($pendingBets);
            $winnerBets = array_values(array_filter($pendingBets, static fn($bet): bool => (string)$bet->prediction === $result));
            $winnerPool = $this->sumBets($winnerBets);
            $loserPool = Money::fromString($totalPool)->subtract(Money::fromString($winnerPool))->getAmount();
            $bonusPool = (string)($game->bonus_pool_usdt ?? '0');
            $commissionPercent = (string)($game->commission_percent ?? '5');

            $summary = [
                'game_id' => $gameId,
                'result' => $result,
                'total_pool' => $totalPool,
                'winner_pool' => $winnerPool,
                'loser_pool' => $loserPool,
                'bonus_pool' => $bonusPool,
                'commission_pct' => $commissionPercent,
                'settlement_policy' => 'stake_return_plus_loser_pool_profit',
                'site_fee_amount' => '0',
                'rollover_amount' => '0',
                'profit_pool' => '0',
                'winners_paid' => 0,
                'losers_marked' => 0,
            ];

            $this->auditTrail->record('prediction.settle_start', $adminId, [
                'game_id' => $gameId,
                'result' => $result,
                'total_pool' => $totalPool,
                'winner_pool' => $winnerPool,
            ]);

            if (empty($pendingBets) || bccomp($totalPool, '0', 8) <= 0) {
                $summary['settlement_policy'] = 'empty_pool';
            } elseif (bccomp($winnerPool, '0', 8) > 0) {
                $commissionRatio = bcdiv($commissionPercent, '100', 8);
                $siteFee = bccomp($loserPool, '0', 8) > 0
                    ? Money::fromString($loserPool)->multiply($commissionRatio)->getAmount()
                    : '0';
                $profitFromLosers = Money::fromString($loserPool)->subtract(Money::fromString($siteFee))->getAmount();
                $profitPool = Money::fromString($profitFromLosers)->add(Money::fromString($bonusPool))->getAmount();

                $summary['site_fee_amount'] = $siteFee;
                $summary['profit_pool'] = $profitPool;

                $totalPaidOut = '0';
                foreach ($winnerBets as $bet) {
                    $amount = (string)$bet->amount_usdt;
                    $ratio = bcdiv($amount, $winnerPool, 12);
                    $profitShare = bccomp($profitPool, '0', 8) > 0
                        ? Money::fromString($profitPool)->multiply($ratio)->getAmount()
                        : '0';
                    $payout = Money::fromString($amount)->add(Money::fromString($profitShare))->getAmount();

                    // Last-winner rounding guard: never pay more than stake+profit pool.
                    $maxPayoutPool = Money::fromString($winnerPool)->add(Money::fromString($profitPool))->getAmount();
                    $newTotalPaid = Money::fromString($totalPaidOut)->add(Money::fromString($payout))->getAmount();
                    if (bccomp($newTotalPaid, $maxPayoutPool, 8) > 0) {
                        $overflow = bcsub($newTotalPaid, $maxPayoutPool, 8);
                        $payout = bcsub($payout, $overflow, 8);
                        $newTotalPaid = $maxPayoutPool;
                    }

                    $this->completeBetHold($bet, $gameId);
                    $txId = $this->payWinner($bet, $payout, $gameId);
                    $this->betModel->markWon((int)$bet->id, $payout, $txId);
                    $totalPaidOut = $newTotalPaid;
                    $summary['winners_paid']++;

                    $this->scoreService?->applyDelta('user', (int)$bet->user_id, 'prediction_accuracy', 5.0, 'prediction_win_' . $gameId);
                }

                foreach ($pendingBets as $bet) {
                    if ((string)$bet->prediction === $result) {
                        continue;
                    }
                    $this->completeBetHold($bet, $gameId);
                    $this->betModel->markLost((int)$bet->id);
                    $summary['losers_marked']++;
                    $this->scoreService?->applyDelta('user', (int)$bet->user_id, 'prediction_accuracy', -2.0, 'prediction_loss_' . $gameId);
                }

                if (bccomp($loserPool, '0', 8) === 0) {
                    $summary['all_winners'] = true;
                }
            } else {
                // No correct prediction: all bets are real losses. 50% goes to next-game reserve, 50% to site costs.
                $rolloverPercent = $this->getNumericSetting('prediction_no_winner_rollover_percent', '50');
                $rolloverFromCurrent = Money::fromString($totalPool)->multiply(bcdiv($rolloverPercent, '100', 8))->getAmount();
                $siteFee = Money::fromString($totalPool)->subtract(Money::fromString($rolloverFromCurrent))->getAmount();
                $rolloverAmount = Money::fromString($rolloverFromCurrent)->add(Money::fromString($bonusPool))->getAmount();

                foreach ($pendingBets as $bet) {
                    $this->completeBetHold($bet, $gameId);
                    $this->betModel->markLost((int)$bet->id);
                    $summary['losers_marked']++;
                    $this->scoreService?->applyDelta('user', (int)$bet->user_id, 'prediction_accuracy', -2.0, 'prediction_loss_' . $gameId);
                }

                $this->addRolloverReserve($rolloverAmount);
                $summary['settlement_policy'] = 'no_winner_50_site_50_rollover';
                $summary['site_fee_amount'] = $siteFee;
                $summary['rollover_amount'] = $rolloverAmount;
                $summary['no_winners'] = true;
            }

            $this->db->execute(
                "UPDATE prediction_games
                 SET result = ?, status = 'finished', finished_at = NOW(), paid_at = NOW(), settled_by = ?,
                     site_fee_usdt = ?, rollover_amount_usdt = ?, settlement_policy = ?, settlement_summary = ?, updated_at = NOW()
                 WHERE id = ?",
                [
                    $result,
                    $adminId,
                    $summary['site_fee_amount'],
                    $summary['rollover_amount'],
                    $summary['settlement_policy'],
                    json_encode($summary, JSON_UNESCAPED_UNICODE),
                    $gameId,
                ]
            );

            // Financial allocations are part of settlement correctness, not a
            // best-effort audit concern. A missing transaction_id or ledger row
            // must roll back the whole settlement instead of silently losing the
            // platform/rollover accounting trail.
            $this->recordPoolAllocation(
                $adminId,
                $gameId,
                (string)$summary['site_fee_amount'],
                'prediction_platform_fee',
                'platform_revenue',
                "کارمزد پلتفرم از بازی پیش‌بینی #{$gameId}"
            );
            $this->recordPoolAllocation(
                $adminId,
                $gameId,
                (string)$summary['rollover_amount'],
                'prediction_rollover',
                'prediction_rollover_reserve',
                "ذخیره رول‌اور برای بازی پیش‌بینی #{$gameId}"
            );

            $this->db->commit();

            // 🛡️ Fail-Safe Audit Log Fix (Issue #6): Audit logging after commit must not convert a successful settlement into an exception response!
            try {
                $this->auditTrail->record('prediction.settled', $adminId, [
                    'game_id' => $gameId,
                    'result' => $result,
                    'summary' => $summary,
                    'timestamp' => microtime(true),
                ]);
            } catch (\Throwable $auditErr) {
                // Non-critical audit error: log warning without breaking successful settlement response
                error_log('[SettleGameJob] Audit trail recording failed: ' . $auditErr->getMessage());
            }

            return ['success' => true, 'summary' => $summary];
        } catch (\Exception $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollback();
            }
            throw $e;
        }
    }

        /** @param array<string, mixed> $bets */
    /** @param array<string, mixed> $bets */
    /** @param list<\stdClass> $bets */
    private function sumBets(array $bets): string
    {
        $sum = '0';
        foreach ($bets as $bet) {
            $sum = bcadd($sum, (string)$bet->amount_usdt, 8);
        }
        return $sum;
    }

    private function completeBetHold(\stdClass $bet, int $gameId): void
    {
        $txId = $this->findPaymentTransactionId($bet, $gameId);
        if (!$txId) {
            throw new \RuntimeException('تراکنش نگهداری مبلغ پیش‌بینی یافت نشد.');
        }
        $walletService = $this->walletService;
        $spent = $walletService->spendLockedFunds(
            (int)$bet->user_id,
            (string)$bet->amount_usdt,
            'usdt',
            [
                'type' => 'prediction_stake_settlement',
                'description' => "انتقال مبلغ پیش‌بینی بازی #{$gameId} به استخر تسویه",
                'ref_id' => $gameId,
                'ref_type' => 'prediction_game',
                'bet_id' => (int)$bet->id,
                'ledger_credit_account' => 'prediction_pool',
                'idempotency_key' => "prediction_stake_settlement_{$bet->id}_{$gameId}",
            ]
        );
        if (empty($spent['success'])) {
            throw new \RuntimeException('مصرف مبلغ نگهداری‌شده پیش‌بینی انجام نشد.');
        }
        if (!$walletService->finalizeLockedSpend((int)$bet->user_id, $txId)) {
            throw new \RuntimeException('نهایی‌سازی تراکنش نگهداری پیش‌بینی انجام نشد.');
        }
    }

    private function findPaymentTransactionId(\stdClass $bet, int $gameId): ?string
    {
        if (!empty($bet->payment_transaction_id)) {
            return (string)$bet->payment_transaction_id;
        }

        $row = $this->db->fetch(
            "SELECT transaction_id FROM transactions
             WHERE user_id = ? AND type = 'withdraw' AND currency = 'usdt'
               AND status IN ('pending','processing','completed','cancelled')
               AND ABS(amount - ?) < 0.00000001
               -- M-tier FIX: the prediction_bet_hold marker must be scoped to THIS game.
               -- Previously it was a standalone OR, so a hold for a DIFFERENT game with an
               -- identical amount could be matched and paid against the wrong transaction.
               AND (ref_id = ? OR (metadata LIKE ? AND metadata LIKE ?))
             ORDER BY id DESC LIMIT 1",
            [
                (int)$bet->user_id,
                (string)$bet->amount_usdt,
                (string)$gameId,
                '%"game_id":' . (int)$gameId . '%',
                '%"type":"prediction_bet_hold"%',
            ]
        );

        return $row->transaction_id ?? null;
    }

    private function payWinner(\stdClass $bet, string $payout, int $gameId): ?string
    {
        if (bccomp($payout, '0', 8) <= 0) {
            return null;
        }
        $walletService = $this->walletService;
        $res = $walletService->depositInTransaction((int)$bet->user_id, $payout, 'usdt', [
            'type' => 'prediction_payout',
            'description' => "پاداش پیش‌بینی بازی #{$gameId}",
            'game_id' => $gameId,
            'bet_id' => (int)$bet->id,
            'ref_id' => $gameId,
            'ref_type' => 'prediction_game',
            'ledger_debit_account' => 'prediction_pool',
            'idempotency_key' => "prediction_payout_{$bet->id}_{$gameId}",
        ]);
        if (empty($res['success'])) {
            $message = $res['message'] ?? null;
            throw new \RuntimeException(is_string($message) ? $message : 'پرداخت پاداش پیش‌بینی انجام نشد.');
        }
        $txId = $res['transaction_id'] ?? null;
        return is_scalar($txId) ? (string)$txId : null;
    }

    private function recordPoolAllocation(
        int $adminId,
        int $gameId,
        string $amount,
        string $type,
        string $creditAccount,
        string $description
    ): void {
        if (bccomp($amount, '0', 8) <= 0) {
            return;
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
            'ref_id' => $gameId,
            'ref_type' => 'prediction_game',
            'request_id' => "prediction_settlement_{$gameId}",
            'ip_address' => 'system',
            'device_fingerprint' => 'prediction-settlement',
            'idempotency_key' => "{$type}_{$gameId}",
            'metadata' => [
                'game_id' => $gameId,
                'allocation_type' => $type,
            ],
        ]);
        if (!$transaction) {
            throw new \RuntimeException('ثبت تراکنش تخصیص استخر پیش‌بینی ناموفق بود.');
        }

        if (!$this->ledgerService->recordDoubleEntry(
            (string)$transaction->transaction_id,
            'prediction_pool',
            $creditAccount,
            $amount,
            'usdt',
            $description,
            ['game_id' => $gameId, 'allocation_type' => $type]
        )) {
            throw new \RuntimeException('ثبت دفتر کل تخصیص استخر پیش‌بینی ناموفق بود.');
        }
    }

    private function getNumericSetting(string $key, string $default): string
    {
        $value = (string)($this->db->fetchColumn("SELECT `value` FROM system_settings WHERE `key` = ? LIMIT 1", [$key]) ?? $default);
        return is_numeric($value) ? $value : $default;
    }

    private function addRolloverReserve(string $amount): void
    {
        if (bccomp($amount, '0', 8) <= 0) {
            return;
        }
        $current = (string)($this->db->fetchColumn("SELECT `value` FROM system_settings WHERE `key` = 'prediction_rollover_reserve_usdt' LIMIT 1") ?? '0');
        $new = bcadd(is_numeric($current) ? $current : '0', $amount, 8);
        $this->db->query(
            "INSERT INTO system_settings (`key`, `value`, `group`, `type`, `description`, is_public, created_at, updated_at)
             VALUES ('prediction_rollover_reserve_usdt', ?, 'prediction', 'numeric', 'ذخیره انتقالی پیش‌بینی‌ها برای بازی‌های بعدی', 0, NOW(), NOW())
             ON DUPLICATE KEY UPDATE `value` = VALUES(`value`), updated_at = NOW()",
            [$new]
        );
    }
}
