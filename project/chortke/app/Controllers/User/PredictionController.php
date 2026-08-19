<?php

declare(strict_types=1);

namespace App\Controllers\User;

use App\Models\PredictionGame;
use App\Models\PredictionBet;
use App\Services\PredictionService;
use App\Validators\Requests\PlacePredictionBetRequest;

class PredictionController extends BaseUserController
{
    private PredictionGame $gameModel;
    private PredictionBet $betModel;
    private PredictionService $predictionService;
    public function __construct(
        PredictionGame $gameModel,
        PredictionBet $betModel,
        PredictionService $predictionService
    , ?\App\Contracts\LoggerInterface $logger = null) {        $this->gameModel = $gameModel;
        $this->betModel = $betModel;
        $this->predictionService = $predictionService;

        parent::__construct(null, null, null, null, $logger);
    }

    // ─── لیست بازی‌های باز ────────────────────────────────────────────
    public function index(): void
    {
        $userId = (int)user_id();
        $games  = $this->gameModel->getOpen(30, 0);

        // وضعیت پیش‌بینی کاربر برای هر بازی باز؛ read-only و بدون مسیر مالی.
        $userBets = [];
        foreach ($games as $g) {
            $userBets[(int)$g->id] = $this->betModel->userHasBet($userId, (int)$g->id);
        }

        $recentBets = $this->betModel->getByUser($userId, 50, 0);
        $summary    = $this->betModel->getUserSummary($userId);
        $recentGames = $this->gameModel->getPublicRecent(['finished', 'cancelled'], 12, 0);

        $this->view('user/prediction/index', [
            'title'           => 'پیش‌بینی بازی‌های ورزشی',
            'games'           => $games,
            'userBets'        => $userBets,
            'recentBets'      => $recentBets,
            'summary'         => $summary,
            'recentGames'     => $recentGames,
            'rolloverReserve' => float_value(setting('prediction_rollover_reserve_usdt', 0)),
        ]);
    }

    // ─── صفحه جزئیات بازی + فرم پیش‌بینی ────────────────────────────
    public function show(int $id): void
    {
        $game = $this->gameModel->find($id);

        if (!$game || $game->deleted_at) {
            $this->session->setFlash('error', 'بازی یافت نشد.');
            redirect(url('/prediction'));
        }

        $userId = (int)user_id();
        $hasBet = $this->betModel->userHasBet($userId, $id);
        $myBet  = null;

        if ($hasBet) {
            $myBets = $this->betModel->getByUser($userId, 1, 0);
            foreach ($myBets as $b) {
                if ((int)$b->game_id === $id) {
                    $myBet = $b;
                    break;
                }
            }
        }

        $this->view('user/prediction/show', [
            'title'  => 'پیش‌بینی: ' . e($game->title),
            'game'   => $game,
            'hasBet' => $hasBet,
            'myBet'  => $myBet,
        ]);
    }

    // ─── ثبت پیش‌بینی ──────────────────────────────────────────────────────
    public function placeBet(): void
    {
        $userId = (int)user_id();
        $gameId = int_value($this->request->param('id'));
        if ($gameId <= 0) {
            $gameId = int_value($this->request->input('game_id') ?? $this->request->input('match_id') ?? $this->request->input('id') ?? 0);
        }
        $input  = $this->request->all();

        $validator = new PlacePredictionBetRequest($input);
        $validator->validate();

        if ($validator->fails()) {
            $this->response->json([
                'success' => false,
                'message' => 'اطلاعات ورودی نامعتبر است.',
                'errors'  => $validator->errors(),
            ], 422);
            return;
        }

        $data = $validator->validated();
        $prediction = trim(str_value($data['prediction'] ?? ''));
        // float→decimal: مبلغ پیش‌بینی به‌صورت رشتهٔ decimal حمل می‌شود
        $rawAmount  = $data['amount'] ?? $data['amount_usdt'] ?? null;
        $amount     = is_numeric($rawAmount) ? (string)$rawAmount : '0';
        $idempotencyKey = trim(str_value($data['idempotency_key'] ?? '')) ?: null;

        $expectsJson = $this->request->isJson() || $this->request->isAjax();
        $redirectTo = '/prediction/' . $gameId;

        try {
            $result = $this->predictionService->placeBet($userId, $gameId, $prediction, $amount, $idempotencyKey);
        } catch (\InvalidArgumentException|\RuntimeException $e) {
            if ($expectsJson) {
                $this->response->json(['success' => false, 'message' => $e->getMessage()], 422);
                return;
            }
            $this->session->setFlash('error', $e->getMessage());
            $this->session->flashInput($input);
            $this->response->redirect(url($redirectTo));
            return;
        } catch (\Exception $e) {
            $this->logger->error('prediction.placeBet.failed', [
                'user_id' => $userId,
                'game_id' => $gameId,
                'error'   => $e->getMessage(),
            ]);
            if ($expectsJson) {
                $this->response->json(['success' => false, 'message' => 'خطای سیستمی. لطفاً دوباره تلاش کنید.'], 500);
                return;
            }
            $this->session->setFlash('error', 'خطای سیستمی. لطفاً دوباره تلاش کنید.');
            $this->response->redirect(url($redirectTo));
            return;
        }

        if ($expectsJson) {
            $this->response->json($result, !empty($result['success']) ? 200 : 422);
            return;
        }

        if (!empty($result['success'])) {
            $this->session->setFlash('success', $result['message'] ?? 'پیش‌بینی با موفقیت ثبت شد.');
        } else {
            $this->session->setFlash('error', $result['message'] ?? 'خطا در ثبت پیش‌بینی.');
            $this->session->flashInput($input);
        }
        $this->response->redirect(url($redirectTo));
    }

    // ─── تاریخچه پیش‌بینی‌های کاربر ────────────────────────────────────────
    public function myBets(): void
    {
        // COMPATIBILITY_REDIRECT: تاریخچه پیش‌بینی‌ها از Phase 2 داخل Hub اصلی نمایش داده می‌شود.
        $this->response->redirect(url('/prediction?section=my-bets'));
    }

    /**
     * Master E2E Functional Browser Verification for Section 8.2 Prediction Bounded Domain Operations (PT-01 to PT-04)
     */
}

