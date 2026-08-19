<?php

declare(strict_types=1);

namespace App\Services\AntiFraud\Strategies;

use App\Contracts\AntiFraud\FraudCheckStrategyInterface;
use App\Jobs\AntiFraud\CheckTaskFraudJob;

final class TaskFraudStrategy implements FraudCheckStrategyInterface
{
    public function __construct(
        private CheckTaskFraudJob $checkTaskFraudJob
    ) {}

    /**
     * Web automation, bot, and engagement checks for all task types.
     *
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    public function check(int $userId, string $action, array $context): array
    {
        return $this->checkTaskFraudJob->handle($userId, $action, $context);
    }
}
