<?php

declare(strict_types=1);

namespace App\Jobs\Prediction;

use App\Contracts\WalletServiceInterface;
use App\Models\PredictionBet;
use App\Services\StateMachineService;
use Core\Database;

class CancelGameJob
{
    #[ \Core\Attributes\Inject ]
    private Database $db;

    #[ \Core\Attributes\Inject ]
    private StateMachineService $stateMachine;

    #[ \Core\Attributes\Inject ]
    private PredictionBet $betModel;

    #[ \Core\Attributes\Inject ]
    private WalletServiceInterface $walletService;

    public function __construct(
        Database $db,
        StateMachineService $stateMachine,
        PredictionBet $betModel,
        WalletServiceInterface $walletService
    ) {
        $this->db = $db;
        $this->stateMachine = $stateMachine;
        $this->betModel = $betModel;
        $this->walletService = $walletService;
    }

    /** @return array<string, mixed> */
public function handle(int $gameId, int $adminId): array
    {
        try {
            $this->db->beginTransaction();

            $game = $this->db->fetch("SELECT * FROM prediction_games WHERE id = ? FOR UPDATE", [$gameId]);
            if (!$game) {
                throw new \RuntimeException('بازی یافت نشد.');
            }
            if (!in_array((string)$game->status, ['open', 'closed'], true)) {
                throw new \RuntimeException('فقط بازی‌های باز یا بسته قابل لغو هستند.');
            }
            if (!$this->stateMachine->canTransition('prediction_game', (string)$game->status, 'cancelled')) {
                throw new \RuntimeException('تغییر وضعیت بازی به cancelled مجاز نیست.');
            }

            $this->db->execute(
                "UPDATE prediction_games SET status = 'cancelled', cancelled_at = NOW(), cancelled_by = ?, updated_at = NOW() WHERE id = ?",
                [$adminId, $gameId]
            );

            $bets = $this->betModel->getPendingByGame($gameId);
            $refunded = 0;
            foreach ($bets as $bet) {
                $txId = $this->cancelBetHold($bet, $gameId);
                $this->betModel->markRefunded((int)$bet->id, $txId);
                $refunded++;
            }

            $this->db->commit();

            return [
                'success' => true,
                'message' => "بازی لغو شد و {$refunded} پیش‌بینی برگشت داده شد.",
                'refunded_count' => $refunded,
            ];
        } catch (\Exception $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollback();
            }
            throw $e;
        }
    }

    private function cancelBetHold(\stdClass $bet, int $gameId): string
    {
        $txId = $this->findPaymentTransactionId($bet, $gameId);
        if (!$txId) {
            throw new \RuntimeException('تراکنش نگهداری مبلغ پیش‌بینی یافت نشد.');
        }
        if (!$this->walletService->cancelWithdrawal((int)$bet->user_id, (string)$bet->amount_usdt, 'usdt', $txId)) {
            throw new \RuntimeException('لغو مبلغ نگهداری‌شده پیش‌بینی انجام نشد.');
        }
        return $txId;
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
}
