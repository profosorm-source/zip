<?php

declare(strict_types=1);

namespace App\Services;

use App\Services\Investment\InvestmentCommandService;
use App\Services\Investment\InvestmentQueryService;

/**
 * InvestmentService — Facade proxy for Investment
 *
 * Delegates to InvestmentCommandService (write) and InvestmentQueryService (read).
 * این کلاس backward-compatibility رو حفظ می‌کنه — controllerها هیچ تغییری نمی‌خوان.
 */
/**
 * @phpstan-type CommandResult array<string, mixed>
 * @phpstan-type InvestmentInput array<string, mixed>
 * @phpstan-type TradingInput array<string, mixed>
 * @phpstan-type WithdrawalInput array<string, mixed>
 */
class InvestmentService
{
    private InvestmentCommandService $commandService;
    private InvestmentQueryService $queryService;

    public function __construct(
        ?InvestmentCommandService $commandService = null,
        ?InvestmentQueryService $queryService = null
    ) {
        if ($commandService !== null && $queryService !== null) {
            $this->commandService = $commandService;
            $this->queryService   = $queryService;
        } else {
            $container = app();
            $this->commandService = $commandService ?? $container->make(InvestmentCommandService::class);
            $this->queryService   = $queryService ?? $container->make(InvestmentQueryService::class);
        }
    }

    // ─── Command delegation ───────────────────────────────────────────────

    /**
     * @param array<string, mixed> $options
     * @return CommandResult
     */
    public function create(int $userId, string $amount, string $currency = 'usdt', array $options = []): array
    {
        return $this->commandService->create($userId, $amount, $currency, $options);
    }

    /**
     * @param InvestmentInput $data
     * @return CommandResult
     */
    public function createInvestment(int $userId, array $data): array
    {
        return $this->commandService->createInvestment($userId, $data);
    }

    /**
     * @param TradingInput $data
     * @return CommandResult
     */
    public function createTrade(int $adminId, array $data): array
    {
        return $this->commandService->createTrade($adminId, $data);
    }

    /**
     * @param TradingInput $data
     * @return CommandResult
     */
    public function closeTrade(int $tradeId, int $adminId, array $data): array
    {
        return $this->commandService->closeTrade($tradeId, $adminId, $data);
    }

    /** @return CommandResult */
    public function applyWeeklyProfitLoss(int $adminId, int $tradingRecordId, string $profitLossPercent, string $period): array
    {
        return $this->commandService->applyWeeklyProfitLoss($adminId, $tradingRecordId, $profitLossPercent, $period);
    }

    /**
     * @param list<int> $investmentIds
     * @return CommandResult
     */
    public function applyProfitLossToBatch(array $investmentIds, int $tradingRecordId, string $percent, string $period, int $adminId): array
    {
        return $this->commandService->applyProfitLossToBatch($investmentIds, $tradingRecordId, $percent, $period, $adminId);
    }

    /**
     * @param WithdrawalInput $data
     * @return CommandResult
     */
    public function requestWithdrawal(int $userId, array $data): array
    {
        return $this->commandService->requestWithdrawal($userId, $data);
    }

    /** @return CommandResult */
    public function approveWithdrawal(int $withdrawalId, int $adminId): array
    {
        return $this->commandService->approveWithdrawal($withdrawalId, $adminId);
    }

    /** @return CommandResult */
    public function rejectWithdrawal(int $withdrawalId, int $adminId, string $reason): array
    {
        return $this->commandService->rejectWithdrawal($withdrawalId, $adminId, $reason);
    }

    public function applyLoss(int $investmentId, string $lossAmount, string $adminOperator): bool
    {
        return $this->commandService->applyLoss($investmentId, $lossAmount, $adminOperator);
    }

    /** @return CommandResult */
    public function withdrawProfit(int $investmentId, int $userId): array
    {
        return $this->commandService->withdrawProfit($investmentId, $userId);
    }

    public function getRiskWarning(): string
    {
        return $this->commandService->getRiskWarning();
    }

    // ─── Query delegation ─────────────────────────────────────────────────

    /** @return array<string, mixed> */
    public function getSolvencyReport(): array
    {
        return $this->queryService->getSolvencyReport();
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    public function searchInvestments(string $q, array $filters, int $limit, int $offset): array
    {
        return $this->queryService->searchInvestments($q, $filters, $limit, $offset);
    }

    /** @return array<string, mixed> */
    public function getSettings(): array
    {
        return $this->queryService->getSettings();
    }
}
