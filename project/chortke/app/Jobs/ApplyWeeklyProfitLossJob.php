<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\InvestmentService;

class ApplyWeeklyProfitLossJob
{
    private InvestmentService $investmentService;

    public function __construct(InvestmentService $investmentService) {
        $this->investmentService = $investmentService;
    }

    /** @param array<string, mixed> $data */
    /** @param array<string, mixed> $data */
public function handle(array $data): void
    {
        $rawInvestmentIds = $data['investment_ids'] ?? [];
        $investmentIds = is_array($rawInvestmentIds)
            ? array_values(array_filter($rawInvestmentIds, static fn(mixed $id): bool => is_int($id) || (is_string($id) && ctype_digit($id))))
            : [];
        $investmentIds = array_map(static fn(int|string $id): int => (int)$id, $investmentIds);
        $tradingRecordId = is_scalar($data['trading_record_id'] ?? null) && is_numeric((string)$data['trading_record_id']) ? (int)$data['trading_record_id'] : 0;
        $profitLossPercent = is_scalar($data['profit_loss_percent'] ?? null) ? (string)$data['profit_loss_percent'] : '0';
        $period = is_string($data['period'] ?? null) ? $data['period'] : '';
        $adminId = is_scalar($data['admin_id'] ?? null) && is_numeric((string)$data['admin_id']) ? (int)$data['admin_id'] : 0;

        if (empty($investmentIds) || !$tradingRecordId) {
            return;
        }

        $this->investmentService->applyProfitLossToBatch($investmentIds, $tradingRecordId, $profitLossPercent, $period, $adminId);
    }
}
