<?php

declare(strict_types=1);

namespace App\Services\Lottery;

class LotteryParticipationService
{
    private \App\Models\LotteryParticipation $model;
    private \App\Jobs\Lottery\ParticipateInLotteryJob $participateJob;
    private \App\Jobs\Lottery\VoteInLotteryJob $voteJob;
    private \App\Jobs\Lottery\UpdateLotteryChanceScoresJob $updateChanceScoresJob;

    public function __construct(
        \App\Models\LotteryParticipation $model,
        \App\Jobs\Lottery\ParticipateInLotteryJob $participateJob,
        \App\Jobs\Lottery\VoteInLotteryJob $voteJob,
        \App\Jobs\Lottery\UpdateLotteryChanceScoresJob $updateChanceScoresJob
    ) {
        $this->model = $model;
        $this->participateJob = $participateJob;
        $this->voteJob = $voteJob;
        $this->updateChanceScoresJob = $updateChanceScoresJob;
    }

    /** @return array<string, mixed> */
    public function participate(int $userId, int $roundId, ?string $idempotencyKey = null): array
    {
        return $this->participateJob->handle($userId, $roundId, $idempotencyKey);
    }

    /** @return array<string, mixed> */
    public function vote(int $userId, int $dailyNumberId, int $selectedNumber): array
    {
        return $this->voteJob->handle($userId, $dailyNumberId, $selectedNumber);
    }

    /** @return array<string, mixed> */
    public function updateChanceScores(int $roundId, string $date): array
    {
        return $this->updateChanceScoresJob->handle($roundId, $date);
    }

    /** @return array<string, mixed> */
    public function getUserChanceHistory(int $userId, int $roundId): array
    {
        $participation = $this->model->findParticipationByUserAndRound($userId, $roundId);
        
        if (!$participation) {
            return ['success' => false, 'message' => 'شما در این قرعه‌کشی شرکت نکرده‌اید.'];
        }

        $logs = $this->model->getChanceLogsByParticipation($participation->id, 50);

        return [
            'success' => true,
            'participation' => $participation,
            'history' => $logs,
            'current_score' => $participation->chance_score,
        ];
    }
}
