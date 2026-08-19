<?php

declare(strict_types=1);

namespace App\Services\Lottery;

use Core\Database;
use Core\Cache;
use App\Models\LotteryRound;
use App\Models\LotteryParticipation;
use App\Models\LotteryDailyNumber;
use App\Contracts\LoggerInterface;
use App\Contracts\WalletServiceInterface;
use App\Services\Shared\IdempotencyService;

/**
 * LotteryService - Facade proxy for Lottery
 * Delegates to LotteryCommandService and LotteryQueryService.
 */
class LotteryService
{
    private LotteryCommandService $commandService;
    private LotteryQueryService $queryService;

    public function __construct(
        Database $db,
        LotteryRound $roundModel,
        LotteryParticipation $participationModel,
        LotteryDailyNumber $dailyModel,
        LoggerInterface $logger,
        Cache $cache,
        \Core\EventDispatcher $eventDispatcher,
        WalletServiceInterface $walletService,
        IdempotencyService $idempotencyService,
        \App\Services\SagaOrchestrator $sagaOrchestrator
    ) {
        $this->commandService = new LotteryCommandService(
            $db,
            $roundModel,
            $participationModel,
            $dailyModel,
            $logger,
            $eventDispatcher,
            $walletService,
            $idempotencyService,
            $sagaOrchestrator,
            null
        );
        $this->queryService = new LotteryQueryService(
            $roundModel, $participationModel, $dailyModel, $cache
        );
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function createRound(int $userId, array $data): array
    {
        return $this->commandService->createRound($userId, $data);
    }

    /** @return array<string, mixed> */
    public function generateDailyNumbers(int $roundId): array
    {
        return $this->commandService->generateDailyNumbers($roundId);
    }

    /** @return array<string, mixed> */
    public function finalizeDailyNumber(int $dailyId): array
    {
        return $this->commandService->finalizeDailyNumber($dailyId);
    }

    /**
     * @param array<string, mixed> $filters
     * @return array{items: list<\stdClass>, total: int}
     */
    public function listRounds(array $filters = [], int $limit = 20, int $offset = 0): array
    {
        return $this->queryService->listRounds($filters, $limit, $offset);
    }

    public function getStats(): object
    {
        return $this->queryService->getStats();
    }

    /**
     * @param list<int> $roundIds
     * @return array<int, int>
     */
    public function getParticipationCounts(array $roundIds): array
    {
        return $this->queryService->getParticipationCounts($roundIds);
    }

    /** @return array<string, mixed> */
    public function participate(int $userId, int $roundId, ?string $idempotencyKey = null): array
    {
        return $this->commandService->participate($userId, $roundId, $idempotencyKey);
    }

    /** @return array<string, mixed> */
    public function selectWinner(int $roundId, int $adminId): array
    {
        return $this->commandService->selectWinner($roundId, $adminId);
    }

    /** @return array<string, mixed> */
    public function cancelRound(int $roundId, int $adminId, string $reason = ''): array
    {
        return $this->commandService->cancelRound($roundId, $adminId, $reason);
    }

    /** @return array<string, mixed> */
    public function getRoundStatistics(int $roundId): array
    {
        return $this->queryService->getRoundStatistics($roundId);
    }

    public function getTransparencyText(): string
    {
        return $this->queryService->getTransparencyText();
    }
}
