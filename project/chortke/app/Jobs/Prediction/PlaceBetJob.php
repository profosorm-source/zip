<?php

declare(strict_types=1);

namespace App\Jobs\Prediction;

class PlaceBetJob
{
    private \App\Services\Shared\IdempotencyService $idempotencyService;
    private \App\Services\DistributedLockService $lockService;
    private \Core\Database $db;
    private \App\Models\PredictionBet $betModel;
    private \App\Contracts\WalletServiceInterface $walletService;
    public function __construct(
        \Core\Database $db,
        \App\Models\PredictionBet $betModel,
        \App\Contracts\WalletServiceInterface $walletService,
        \App\Services\Shared\IdempotencyService $idempotencyService,
        \App\Services\DistributedLockService $lockService
    ) {        $this->db = $db;
        $this->betModel = $betModel;
        $this->walletService = $walletService;
        $this->idempotencyService = $idempotencyService;
        $this->lockService = $lockService;
}

    /** @return array<string, mixed> */
public function handle(int $userId, int $gameId, string $prediction, string $amount, ?string $idempotencyKey = null): array
    {
        // اعتبارسنجی اولیه (قبل از transaction)
        if (!in_array($prediction, ['home', 'away', 'draw'], true)) {
            throw new \InvalidArgumentException('پیش‌بینی باید home، away یا draw باشد.');
        }

        // float→decimal: مبلغ به‌صورت رشتهٔ decimal؛ گارد عددی و مقایسهٔ دقیق (USDT scale 8)
        $amount = is_numeric($amount) ? $amount : '0';
        if (bccomp($amount, '0', 8) <= 0) {
            throw new \InvalidArgumentException('مبلغ پیش‌بینی باید بیشتر از صفر باشد.');
        }

        $payload = [
            'user_id' => $userId,
            'game_id' => $gameId,
            'prediction' => $prediction,
            'amount' => $amount,
        ];

        $explicitKey = $idempotencyKey !== null && $idempotencyKey !== '' ? $idempotencyKey : null;

        // PT-06: برای پیش‌بینی، تکرار یک idempotency key صریح نباید نتیجه cached را
        // به‌عنوان موفقیت جدید برگرداند؛ باید به شکل business rejection گزارش شود.
        if ($explicitKey !== null) {
            $scopedKey = hash('sha256', 'prediction.placeBet' . '|' . $userId . '|' . $explicitKey);
            $existingKey = $this->db->fetch(
                "SELECT id, status FROM idempotency_keys WHERE `key` = ? AND user_id = ? LIMIT 1",
                [$scopedKey, $userId]
            );
            if ($existingKey) {
                return [
                    'success' => false,
                    'message' => 'درخواست پیش‌بینی با این کلید قبلاً ثبت شده است.',
                    'idempotency_status' => $existingKey->status ?? null,
                ];
            }
        }

        return $this->idempotencyService->execute('prediction.placeBet', $userId, $payload, function () use (
            $userId,
            $gameId,
            $prediction,
            $amount
        ) {
            return $this->lockService->synchronized("prediction_bet_{$userId}_{$gameId}", function() use (
                $userId,
                $gameId,
                $prediction,
                $amount
            ) {
                try {
                    $this->db->beginTransaction();

                    // P-3 Fix: Lock game and check deadline using authoritative database NOW() time to prevent TOCTOU race conditions
                    $game = $this->db->fetch(
                        "SELECT *, CASE WHEN bet_deadline > NOW() THEN 1 ELSE 0 END as is_deadline_valid 
                         FROM prediction_games 
                         WHERE id = ? AND deleted_at IS NULL FOR UPDATE",
                        [$gameId]
                    );

                    if (!$game) {
                        throw new \RuntimeException('بازی یافت نشد.');
                    }
                    if ($game->status !== 'open') {
                        throw new \RuntimeException('این بازی برای ثبت پیش‌بینی باز نیست.');
                    }
                    if (!(bool)($game->is_deadline_valid ?? false)) {
                        throw new \RuntimeException('مهلت ثبت پیش‌بینی تمام شده است.');
                    }
                    $minBet = is_numeric($game->min_bet_usdt ?? null) ? (string)$game->min_bet_usdt : '0';
                    $maxBet = is_numeric($game->max_bet_usdt ?? null) ? (string)$game->max_bet_usdt : '0';
                    if (bccomp($amount, $minBet, 8) < 0) {
                        throw new \InvalidArgumentException("حداقل مبلغ پیش‌بینی {$game->min_bet_usdt} USDT است.");
                    }
                    if (bccomp($amount, $maxBet, 8) > 0) {
                        throw new \InvalidArgumentException("حداکثر مبلغ پیش‌بینی {$game->max_bet_usdt} USDT است.");
                    }

                    // بررسی پیش‌بینی تکراری — با FOR UPDATE داخل transaction
                    if ($this->betModel->userHasBetForUpdate($userId, $gameId)) {
                        throw new \RuntimeException('شما قبلاً در این بازی پیش‌بینی ثبت کرده‌اید.');
                    }

                    assert_fraud_allowed($userId, 'prediction.bet', ['amount' => $amount]);

                    // کسر موجودی از کیف پول با استفاده از withdrawInTransaction
                    $debitResult = $this->walletService->withdrawInTransaction(
                        $userId,
                        $amount,
                        'usdt',
                        [
                            'type'        => 'prediction_bet_hold',
                            'description' => "نگهداری امن پیش‌بینی بازی #{$gameId}: {$game->title}",
                            'game_id'     => $gameId,
                            'ref_id'      => $gameId,
                            'ref_type'    => 'prediction_game',
                            'idempotency_key' => "prediction_bet_hold_{$userId}_{$gameId}",
                        ]
                    );

                    if (empty($debitResult['success'])) {
                        throw new \RuntimeException('موجودی کیف پول شما برای ثبت این پیش‌بینی کافی نیست.');
                    }
                    $transactionId = $debitResult['transaction_id'] ?? null;

                    // ثبت پیش‌بینی
                    $bet = $this->betModel->createBet([
                        'user_id'     => $userId,
                        'game_id'     => $gameId,
                        'prediction'  => $prediction,
                        'amount_usdt' => $amount,
                        'payment_transaction_id' => $transactionId
                    ]);

                    if (!$bet) {
                        throw new \RuntimeException('خطا در ثبت پیش‌بینی. لطفاً دوباره تلاش کنید.');
                    }

                    $this->db->commit();

                    return [
                        'success' => true,
                        'bet_id'  => $bet->id,
                        'message' => 'پیش‌بینی با موفقیت ثبت شد.',
                    ];

                } catch (\Exception $e) {
                    $this->db->rollback();
                    if (stripos($e->getMessage(), 'Insufficient balance') !== false || stripos($e->getMessage(), 'wallet frozen') !== false) {
                        throw new \RuntimeException('موجودی کیف پول شما برای ثبت این پیش‌بینی کافی نیست.');
                    }
                    throw $e;
                }
            });
        }, $explicitKey);
    }
}
