<?php

declare(strict_types=1);

namespace App\Services;

class PredictionService
{


    private \App\Jobs\Prediction\PlaceBetJob $placeBetJob;
    private \App\Jobs\Prediction\SettleGameJob $settleGameJob;
    private \App\Jobs\Prediction\CancelGameJob $cancelGameJob;

    public function __construct(
        \App\Jobs\Prediction\PlaceBetJob $placeBetJob,
        \App\Jobs\Prediction\SettleGameJob $settleGameJob,
        \App\Jobs\Prediction\CancelGameJob $cancelGameJob
    ) {
        $this->placeBetJob = $placeBetJob;
        $this->settleGameJob = $settleGameJob;
        $this->cancelGameJob = $cancelGameJob;
    }

    /** @return array<string, mixed> */
    public function placeBet(int $userId, int $gameId, string $prediction, string $amount, ?string $idempotencyKey = null): array
    {
        try {
            return $this->placeBetJob->handle($userId, $gameId, $prediction, $amount, $idempotencyKey);
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /** @return array<string, mixed> */
    public function settleGame(int $gameId, string $result, int $adminId): array
    {
        try {
            return $this->settleGameJob->handle($gameId, $result, $adminId);
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /** @return array<string, mixed> */
    public function cancelGame(int $gameId, int $adminId): array
    {
        try {
            return $this->cancelGameJob->handle($gameId, $adminId);
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }
}
