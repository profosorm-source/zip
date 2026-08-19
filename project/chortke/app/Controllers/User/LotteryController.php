<?php
// app/Controllers/User/LotteryController.php

declare(strict_types=1);

namespace App\Controllers\User;

use App\Models\LotteryRound;
use App\Models\LotteryParticipation;
use App\Models\LotteryDailyNumber;
use App\Models\LotteryVote;
use App\Services\Lottery\LotteryService;
use App\Services\Lottery\LotteryParticipationService;
use App\Policies\RateLimitPolicy;
use App\Controllers\User\BaseUserController;
use Core\Database;
use App\Validators\Requests\JoinLotteryRequest;
use App\Validators\Requests\VoteLotteryRequest;

class LotteryController extends BaseUserController
{
    private LotteryVote $lotteryVoteModel;
    private LotteryRound $lotteryRoundModel;
    private LotteryParticipation $lotteryParticipationModel;
    private LotteryDailyNumber $lotteryDailyNumberModel;
    private LotteryService $lotteryService;
    private LotteryParticipationService $participationService;

    public function __construct(
        LotteryDailyNumber $lotteryDailyNumberModel,
        LotteryParticipation $lotteryParticipationModel,
        LotteryRound $lotteryRoundModel,
        LotteryVote $lotteryVoteModel,
        LotteryService $lotteryService,
        LotteryParticipationService $participationService,
        ?\App\Contracts\LoggerInterface $logger = null
    ) {
        parent::__construct(null, null, null, null, $logger);

        $this->lotteryDailyNumberModel = $lotteryDailyNumberModel;
        $this->lotteryParticipationModel = $lotteryParticipationModel;
        $this->lotteryRoundModel = $lotteryRoundModel;
        $this->lotteryVoteModel = $lotteryVoteModel;
        $this->lotteryService = $lotteryService;
        $this->participationService = $participationService;
    }

    public function index(): string
    {
        $userId = (int) user_id();
        $roundModel = $this->lotteryRoundModel;
        $participationModel = $this->lotteryParticipationModel;
        $dailyModel = $this->lotteryDailyNumberModel;
        $voteModel = $this->lotteryVoteModel;
        $activeRound = $roundModel->getActiveRound();
        $participation = null;
        $todayNumbers = null;
        $userVote = null;
        $distribution = null;
        $dailyHistory = [];

        if ($activeRound) {
            $participation = $participationModel->findByUserAndRound($userId, $activeRound->id);
            $todayNumbers = $dailyModel->getToday($activeRound->id);
            $distribution = $participationModel->getChanceDistribution($activeRound->id);
            $dailyHistory = $dailyModel->getByRound($activeRound->id);

            if ($todayNumbers && $participation) {
                $userVote = $voteModel->getUserVote($userId, $todayNumbers->id);
            }
        }

        $completedRounds = $roundModel->getCompletedRounds(5);
        $myParticipations = $participationModel->getByUser($userId, 10);

        // BUGFIX-CTRL-RAW-SQL-2026-06: lookup through UserService (inherited from BaseUserController).
        $user = $this->userService->findById((int)$userId);

        // Rendering Bug Fix: استفاده از $this->view جهت تخصیص خروجی به شیء Response
        return $this->view('user/lottery/index', [
            'user' => $user,
            'activeRound' => $activeRound,
            'participation' => $participation,
            'todayNumbers' => $todayNumbers,
            'userVote' => $userVote,
            'distribution' => $distribution,
            'dailyHistory' => $dailyHistory,
            'completedRounds' => $completedRounds,
            'myParticipations' => $myParticipations,
            'transparencyText' => $this->lotteryService->getTransparencyText(),
        ]);
    }

    public function join(?int $id = null): void
    {
        $input = is_array($this->request->input()) ? $this->request->input() : [];
        if ($id !== null && $id > 0) {
            $input['round_id'] = $id;
        }

        $validator = new JoinLotteryRequest($input);
        $validator->validate();

        if ($validator->fails()) {
            $this->response->json([
                'success' => false,
                'message' => 'اطلاعات ورودی نامعتبر است.',
                'errors'  => $validator->errors(),
            ], 422);
        }

        $data = $validator->validated();
        $roundId = int_value($data['round_id'] ?? 0);
        $idempotencyKey = trim(str_value($data['idempotency_key'] ?? '')) ?: null;

        $result = $this->participationService->participate((int) user_id(), $roundId, $idempotencyKey);
        RateLimitPolicy::enforce('lottery_participate', (int)user_id(), true);

        $this->response->json($result, $result['success'] ? 200 : 422);
    }

    public function vote(?int $id = null): void
    {
        $input = is_array($this->request->input()) ? $this->request->input() : [];
        if ($id !== null && $id > 0) {
            $input['round_id'] = $id;
        }

        $validator = new VoteLotteryRequest($input);
        $validator->validate();

        if ($validator->fails()) {
            $this->response->json([
                'success' => false,
                'message' => 'اطلاعات ورودی نامعتبر است.',
                'errors'  => $validator->errors(),
            ], 422);
        }

        $data = $validator->validated();
        $roundId = int_value($data['round_id'] ?? 0);
        $dailyNumberId = int_value($data['daily_number_id'] ?? 0);
        $votedNumber = int_value($data['voted_number'] ?? -1);

        if ($dailyNumberId <= 0 && $roundId > 0) {
            $today = $this->lotteryDailyNumberModel->getToday($roundId);
            $dailyNumberId = (int)($today->id ?? 0);
        }

        if ($dailyNumberId <= 0) {
            $this->response->json([
                'success' => false,
                'message' => 'عدد روزانه برای رأی‌گیری یافت نشد.',
            ], 422);
        }

        $result = $this->participationService->vote((int) user_id(), $dailyNumberId, $votedNumber);
        RateLimitPolicy::enforce('lottery_vote', (int)user_id(), true);

        $this->response->json($result, $result['success'] ? 200 : 422);
    }

    /**
     * Master E2E Functional Browser Verification for Section 8.1 Lottery Operations (LT-01 to LT-05)
     */
}
