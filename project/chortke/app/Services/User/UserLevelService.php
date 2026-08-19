<?php

declare(strict_types=1);

namespace App\Services\User;

use App\Models\UserLevel;
use App\Models\UserLevelHistory;
use App\Models\Score;
use Core\Database;
use App\Contracts\LoggerInterface;
use App\Services\Settings\AppSettings;
use Core\TransactionWrapper;

/**
 * UserLevelService - Facade proxy for User Levels
 * Delegates to UserLevelCommandService and UserLevelQueryService.
 */
class UserLevelService
{
    private UserLevelCommandService $commandService;
    private UserLevelQueryService $queryService;

    public function __construct(
        TransactionWrapper $transactionWrapper,
        Database $db,
        LoggerInterface $logger,
        UserLevel $levelModel,
        AppSettings $appSettings,
        \App\Contracts\WalletServiceInterface $walletService,
        ?UserLevelHistory $historyModel = null,
        ?Score $scoreModel = null
    ) {
        $this->commandService = new UserLevelCommandService(
            $transactionWrapper, $db, $logger, $levelModel, $appSettings,
            $walletService, $historyModel, $scoreModel
        );
        $this->queryService = new UserLevelQueryService($levelModel);
    }

    public function isEnabled(): bool
    {
        return $this->commandService->isEnabled();
    }

    public function recordDailyActivity(int $userId): void
    {
        $this->commandService->recordDailyActivity($userId);
    }

    /** @return array<int, object> */
    public function getUserLevels(): array
    {
        return $this->queryService->getUserLevels();
    }

    public function getUserBonuses(int $userId): object
    {
        return $this->queryService->getUserBonuses($userId);
    }

    public function getProgress(int $userId): object
    {
        return $this->queryService->getProgress($userId);
    }

    public function applyEarningBonus(int $userId, string $baseAmount): string
    {
        return $this->queryService->applyEarningBonus($userId, $baseAmount);
    }

    public function adminChangeLevel(int $userId, string $newLevel, string $reason = 'تغییر توسط مدیر'): bool
    {
        return $this->commandService->adminChangeLevel($userId, $newLevel, $reason);
    }

    /** @return array<string, mixed> */
    public function purchaseLevel(int $userId, string $levelSlug, string $currency = 'irt'): array
    {
        return $this->commandService->purchaseLevel($userId, $levelSlug, $currency);
    }

    public function checkUpgrade(int $userId): void
    {
        $this->commandService->checkUpgrade($userId);
    }

    /** @return array<int, array<string, int|string>> */
    public function checkDowngrades(): array
    {
        return $this->commandService->checkDowngrades();
    }

    public function checkExpiredPurchases(): int
    {
        return $this->commandService->checkExpiredPurchases();
    }

    public function monthlyReset(): int
    {
        return $this->commandService->monthlyReset();
    }
}

\class_alias(\App\Services\User\UserLevelService::class, 'App\Services\UserLevelService');

