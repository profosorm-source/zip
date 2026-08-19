<?php

declare(strict_types=1);

namespace App\Jobs\Lottery;

use Core\Database;
use App\Contracts\LoggerInterface;
use App\Models\LotteryRound;

class VoteInLotteryJob
{
    public function __construct(
        private Database $db,
        private LoggerInterface $logger
    ) {}

    /** @return array<string, mixed> */
public function handle(int $userId, int $dailyNumberId, int $selectedNumber): array
    {
        if ($selectedNumber < 1 || $selectedNumber > 49) {
            return ['success' => false, 'message' => 'عدد انتخابی باید بین 1 تا 49 باشد.'];
        }

        $this->db->beginTransaction();

        try {
            $dailyNumber = $this->db->fetch(
                "SELECT * FROM lottery_daily_numbers WHERE id = ? AND is_deleted = 0 FOR UPDATE",
                [$dailyNumberId]
            );

            if (!$dailyNumber) {
                $this->db->rollback();
                return ['success' => false, 'message' => 'عدد روزانه یافت نشد.'];
            }

            if (!in_array((string)$dailyNumber->status, ['pending', 'open'], true)) {
                $this->db->rollback();
                return ['success' => false, 'message' => 'رأی‌گیری برای این عدد روزانه فعال نیست.'];
            }

            $allowedNumbers = array_filter([
                isset($dailyNumber->number1) ? (int)$dailyNumber->number1 : null,
                isset($dailyNumber->number2) ? (int)$dailyNumber->number2 : null,
                isset($dailyNumber->number3) ? (int)$dailyNumber->number3 : null,
            ], static fn($n) => $n !== null && $n > 0);

            if (!empty($allowedNumbers) && !in_array($selectedNumber, $allowedNumbers, true)) {
                $this->db->rollback();
                return ['success' => false, 'message' => 'عدد انتخابی جزو گزینه‌های رأی‌گیری امروز نیست.'];
            }

            $round = $this->db->fetch(
                "SELECT * FROM lottery_rounds WHERE id = ? AND is_deleted = 0 FOR UPDATE",
                [(int)$dailyNumber->round_id]
            );

            if (!$round || !in_array((string)$round->status, [LotteryRound::STATUS_ACTIVE, LotteryRound::STATUS_VOTING], true)) {
                $this->db->rollback();
                return ['success' => false, 'message' => 'دوره قرعه‌کشی برای رأی‌گیری فعال نیست.'];
            }

            $participation = $this->db->fetch(
                "SELECT * FROM lottery_participations
                 WHERE user_id = ? AND round_id = ? AND status = 'active' AND is_deleted = 0
                 FOR UPDATE",
                [$userId, (int)$dailyNumber->round_id]
            );

            if (!$participation) {
                $this->db->rollback();
                return ['success' => false, 'message' => 'شما در این دوره قرعه‌کشی شرکت نکرده‌اید.'];
            }

            $existingVote = $this->db->fetch(
                "SELECT id FROM lottery_votes
                 WHERE user_id = ? AND daily_number_id = ? AND is_deleted = 0
                 FOR UPDATE",
                [$userId, $dailyNumberId]
            );

            if ($existingVote) {
                $this->db->rollback();
                return ['success' => false, 'message' => 'رأی امروز شما قبلاً ثبت شده است.'];
            }

            $stmt = $this->db->prepare(
                "INSERT INTO lottery_votes
                 (user_id, round_id, daily_number_id, participation_id, voted_number, status, is_deleted, created_at)
                 VALUES (?, ?, ?, ?, ?, 'cast', 0, NOW())"
            );
            $stmt->execute([
                $userId,
                (int)$dailyNumber->round_id,
                $dailyNumberId,
                (int)$participation->id,
                $selectedNumber,
            ]);

            $voteId = $this->db->lastInsertId();
            $this->db->commit();

            $this->logger->info('lottery_vote.cast', [
                'user_id' => $userId,
                'round_id' => (int)$dailyNumber->round_id,
                'daily_number_id' => $dailyNumberId,
                'voted_number' => $selectedNumber,
                'vote_id' => $voteId,
            ]);

            return ['success' => true, 'message' => 'رأی شما با موفقیت ثبت شد.', 'vote_id' => $voteId];
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollback();
            }

            $this->logger->error('lottery_vote.error', ['message' => $e->getMessage()]);
            return ['success' => false, 'message' => 'خطا در ثبت رأی: ' . $e->getMessage()];
        }
    }
}
