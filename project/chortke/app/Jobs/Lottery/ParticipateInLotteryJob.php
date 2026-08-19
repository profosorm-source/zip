<?php

declare(strict_types=1);

namespace App\Jobs\Lottery;

use App\Services\Lottery\LotteryService;

class ParticipateInLotteryJob
{
    public function __construct(
        private LotteryService $lotteryService
    ) {}

    /** @return array<string, mixed> */
public function handle(int $userId, int $roundId, ?string $idempotencyKey = null): array
    {
        try {
            $result = $this->lotteryService->participate($userId, $roundId, $idempotencyKey);
            if (empty($result['success'])) {
                return ['success' => false, 'message' => $result['message'] ?? 'خطا در شرکت در قرعه‌کشی.'];
            }
            return [
                'success' => true,
                'message' => 'با موفقیت در قرعه‌کشی شرکت کردید.',
                'code' => $result['code'] ?? 'LT-' . random_int(1000, 9999)
            ];
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }
}
