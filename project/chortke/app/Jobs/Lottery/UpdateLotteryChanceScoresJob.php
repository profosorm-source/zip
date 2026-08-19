<?php

declare(strict_types=1);

namespace App\Jobs\Lottery;

use Core\Database;
use Core\EventDispatcher;
use App\Models\LotteryDailyNumber;
use App\Models\LotteryParticipation;
use App\Models\LotteryRound;
use App\Models\LotteryVote;
use App\Models\LotteryChanceLog;
use App\Services\ScoreService;
use App\Contracts\LoggerInterface;

/**
 * UpdateLotteryChanceScoresJob
 * 
 * به‌روزرسانی امتیازات شانس (lottery_chance) کاربران شرکت‌کننده در قرعه‌کشی
 * بر اساس تطابق رأی آن‌ها با اعداد روزانه.
 * 
 * این Job توسط Cron فراخوانی می‌شود و نیازی به dispatch دستی ایونت ندارد
 * زیرا ScoreService.applyDelta خودش event sourcing را انجام می‌دهد.
 */
class UpdateLotteryChanceScoresJob
{
    private Database $db;
    private ScoreService $scoreService;
    private LoggerInterface $logger;
    private EventDispatcher $eventDispatcher;
    private LotteryDailyNumber $dailyNumberModel;
    private LotteryParticipation $participationModel;
    private LotteryVote $voteModel;
    private LotteryChanceLog $chanceLogModel;
    private LotteryRound $roundModel;

    /**
     * Thresholds for chance score adjustments
     */
    private const BASE_REWARD = 2.0;
    private const BASE_PENALTY = 1.0;
    private const MIN_CHANCE = 0.0;
    private const NO_VOTE_DECAY = -0.5;

    public function __construct(
        Database $db,
        ScoreService $scoreService,
        LoggerInterface $logger,
        EventDispatcher $eventDispatcher,
        LotteryDailyNumber $dailyNumberModel,
        LotteryParticipation $participationModel,
        LotteryVote $voteModel,
        LotteryChanceLog $chanceLogModel,
        LotteryRound $roundModel
    ) {
        $this->eventDispatcher = $eventDispatcher;
        $this->db = $db;
        $this->scoreService = $scoreService;
        $this->logger = $logger;
        $this->dailyNumberModel = $dailyNumberModel;
        $this->participationModel = $participationModel;
        $this->voteModel = $voteModel;
        $this->chanceLogModel = $chanceLogModel;
        $this->roundModel = $roundModel;
    }

    /** @return array<string, mixed> */
public function handle(int $roundId, string $date): array
    {
        $dailyNumber = $this->dailyNumberModel->getByRoundAndDate($roundId, $date);

        if (!$dailyNumber) {
            return ['success' => false, 'message' => 'عدد روزانه برای این تاریخ یافت نشد.'];
        }

        $round = $this->roundModel->find($roundId);
        if (!$round) {
            return ['success' => false, 'message' => 'دوره قرعه‌کشی یافت نشد.'];
        }

        $participants = $this->participationModel->getAllActiveByRound($roundId);

        if (empty($participants)) {
            return ['success' => false, 'message' => 'شرکت‌کننده‌ای یافت نشد.'];
        }

        $updatedCount = 0;
        $totalReward = 0;
        $totalPenalty = 0;

        $this->db->beginTransaction();

        try {
            foreach ($participants as $p) {
                $userVote = $this->voteModel->getUserVote($p->user_id, $dailyNumber->id);

                if (!$userVote) {
                    // کاربر رأی نداده → کاهش امتیاز (No-Vote Decay)
                    $this->applyNoVoteDecay($p, $roundId, $date);
                    $updatedCount++;
                    continue;
                }

                $selectedNumber = (int)$userVote->voted_number;
                $matched = $this->checkMatch($p->ticket_number ?? $p->code, $selectedNumber);

                $scoreBefore = (float)($p->chance_score ?? 0);
                $change = 0;
                $reason = '';

                if ($matched) {
                    $change = self::BASE_REWARD;
                    $reason = 'match_success';
                    $totalReward += $change;
                } else {
                    $change = -self::BASE_PENALTY;
                    $reason = 'match_fail';
                    $totalPenalty += abs($change);
                }

                // Update participation record
                $scoreAfter = round($scoreBefore + $change, 4);
                if ($scoreAfter < self::MIN_CHANCE) {
                    $scoreAfter = self::MIN_CHANCE;
                }

                $this->participationModel->update((int)$p->id, ['chance_score' => $scoreAfter]);

                // Apply score delta through ScoreService (with idempotency)
                $this->scoreService->applyDelta(
                    'user',
                    (int)$p->user_id,
                    'lottery_chance',
                    $change,
                    'lottery_daily_vote',
                    ['round_id' => $roundId, 'daily_id' => (int)$dailyNumber->id, 'date' => $date],
                    "lottery:vote:{$roundId}:{$p->user_id}:{$date}"
                );

                // Log chance change
                $this->chanceLogModel->create([
                    'participation_id' => (int)$p->id,
                    'user_id' => (int)$p->user_id,
                    'round_id' => $roundId,
                    'date' => $date,
                    'score_before' => $scoreBefore,
                    'score_change' => $change,
                    'score_after' => $scoreAfter,
                    'reason' => $reason,
                    'details' => "selected:{$selectedNumber}, matched:" . ($matched ? 'yes' : 'no'),
                ]);

                $updatedCount++;
            }

            $this->db->commit();

            // cache.invalidate — internal in-process event، نیازی به Outbox ندارد
            // ScoreService.applyDelta خودش event sourcing را انجام می‌دهد
            try {
                $this->eventDispatcher->dispatch('cache.invalidate', ['key' => "participants_{$roundId}"]);
            } catch (\Throwable $e) {
                // Non-blocking
            }

            $this->logger->info('lottery_chance_updated', [
                'message' => "Round {$roundId}, updated {$updatedCount} participants",
                'stats' => ['total_reward' => round($totalReward, 2), 'total_penalty' => round($totalPenalty, 2)],
            ]);

            return [
                'success' => true,
                'message' => 'امتیازات شانس با موفقیت به‌روزرسانی شدند.',
                'updated_count' => $updatedCount,
                'stats' => ['total_reward' => round($totalReward, 2), 'total_penalty' => round($totalPenalty, 2)],
            ];

        } catch (\Throwable $e) {
            $this->db->rollback();
            $this->logger->error('lottery_chance_error', ['message' => $e->getMessage()]);
            return ['success' => false, 'message' => 'خطا در به‌روزرسانی امتیازات شانس.'];
        }
    }

    /**
     * بررسی تطابق شماره انتخاب شده کاربر با اعداد روزانه
     */
    private function checkMatch(string $ticketNumber, int $selectedNumber): bool
    {
        // Logic: اگر selectedNumber در بین اعداد بلیط کاربر باشد → تطابق
        $numbers = explode(',', $ticketNumber);
        return in_array($selectedNumber, array_map('intval', $numbers), true);
    }

    /**
     * اعمال کاهش امتیاز برای کاربرانی که رأی نداده‌اند
     */
    private function applyNoVoteDecay(\stdClass $participation, int $roundId, string $date): void
    {
        $scoreBefore = (float)($participation->chance_score ?? 0);
        $change = self::NO_VOTE_DECAY;
        $scoreAfter = round(max($scoreBefore + $change, self::MIN_CHANCE), 4);

        $this->participationModel->update((int)$participation->id, ['chance_score' => $scoreAfter]);

        $this->scoreService->applyDelta(
            'user',
            (int)$participation->user_id,
            'lottery_chance',
            $change,
            'lottery_no_vote',
            ['round_id' => $roundId, 'date' => $date],
            "lottery:novote:{$roundId}:{$participation->user_id}:{$date}"
        );

        $this->chanceLogModel->create([
            'participation_id' => (int)$participation->id,
            'user_id' => (int)$participation->user_id,
            'round_id' => $roundId,
            'date' => $date,
            'score_before' => $scoreBefore,
            'score_change' => $change,
            'score_after' => $scoreAfter,
            'reason' => 'no_vote',
            'details' => 'User did not vote for daily numbers',
        ]);
    }
}