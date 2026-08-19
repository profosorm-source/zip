<?php
// app/Controllers/Admin/LotteryController.php

namespace App\Controllers\Admin;

use Core\Response;

use App\Models\LotteryRound;
use App\Models\LotteryParticipation;
use App\Models\LotteryDailyNumber;
use App\Models\LotteryChanceLog;
use App\Services\Lottery\LotteryService;
use App\Validators\Requests\LotteryRoundRequest;
use App\Controllers\Admin\BaseAdminController;

class LotteryController extends BaseAdminController
{
    private \App\Models\LotteryRound $lotteryRoundModel;
    private \App\Models\LotteryParticipation $lotteryParticipationModel;
    private \App\Models\LotteryDailyNumber $lotteryDailyNumberModel;
    private LotteryService $lotteryService;

    public function __construct(
        \App\Models\LotteryDailyNumber $lotteryDailyNumberModel,
        \App\Models\LotteryParticipation $lotteryParticipationModel,
        \App\Models\LotteryRound $lotteryRoundModel,
        \App\Services\Lottery\LotteryService $lotteryService, ?\App\Contracts\LoggerInterface $logger = null) {
        parent::__construct(null, null, null, null, $logger);
        $this->lotteryService = $lotteryService;
        $this->lotteryDailyNumberModel = $lotteryDailyNumberModel;
        $this->lotteryParticipationModel = $lotteryParticipationModel;
        $this->lotteryRoundModel = $lotteryRoundModel;
    }

    public function index(): string
    {
        $filters = ['status' => $this->request->get('status')];
        $page = \max(1, $this->request->int('page', 1));
        $perPage = 15;
        $offset = ($page - 1) * $perPage;

        $result = $this->lotteryService->listRounds($filters, $perPage, $offset);
        $rounds = $result['rounds'] ?? [];
        $total = $result['total'] ?? 0;
        $totalPages = \ceil($total / $perPage);
        $stats = $this->lotteryService->getStats();

        $roundIds = \array_map(fn($r) => (int)($r->id ?? 0), $rounds);
        $participationCounts = [];
        if (count($roundIds) > 0) {
            $participationCounts = $this->lotteryService->getParticipationCounts($roundIds);
            foreach ($roundIds as $rid) {
                if (!isset($participationCounts[$rid])) {
                    $participationCounts[$rid] = 0;
                }
            }
        }

        return view('admin.lottery.index', [
            'user' => user(),
            'rounds' => $rounds,
            'stats' => $stats,
            'total' => $total,
            'totalPages' => $totalPages,
            'currentPage' => $page,
            'filters' => $filters,
            'participationCounts' => $participationCounts,
        ]);
    }

    public function create(): string
    {
        return view('admin.lottery.create', ['user' => user()]);
    }

    public function store(): void
    {
        $input = is_array($this->request->input()) ? $this->request->input() : [];

        $request = new LotteryRoundRequest($input);
        if (!$request->validate()) {
            $this->response->json(['success' => false, 'errors' => $request->errors()], 422);
        }

        $data = $request->validated();
        $result = $this->lotteryService->createRound($this->requireAdminId(), $data);

        $this->response->json($result, $result['success'] ? 200 : 422);
    }

    public function generateNumbers(int $id): void
    {
        $result = $this->lotteryService->generateDailyNumbers($id);

        $this->response->json($result, $result['success'] ? 200 : 422);
    }

    public function finalizeDaily(int $did): void
    {
        $result = $this->lotteryService->finalizeDailyNumber($did);

        $this->response->json($result, $result['success'] ? 200 : 422);
    }

    public function selectWinner(int $id): void
    {
        $result = $this->lotteryService->selectWinner($id, $this->requireAdminId());

        $this->response->json($result, $result['success'] ? 200 : 422);
    }

    public function cancel(int $id): void
    {
        $result = $this->lotteryService->cancelRound($id, $this->requireAdminId());
        
        if (!$result['success']) {
            $this->response->json(['success' => false, 'message' => $result['message'] ?? 'دوره قابل لغو نیست.'], 422);
        }

        $this->logger->info('lottery_cancelled', ['message' => "Admin " . $this->requireAdminId() . " cancelled round #{$id}"]);

        $this->response->json(['success' => true, 'message' => 'دوره لغو شد.']);
    }

    public function show(int $id): string
    {
        $round = $this->lotteryRoundModel->find($id);
        if (!$round) { $this->session->setFlash('error', 'دوره قرعه‌کشی یافت نشد'); return redirect('/admin/lottery'); }
        $participantCount = 0;
        try { $participantCount = $this->lotteryParticipationModel->countByRound($id); } catch (\Throwable) {}
        $dailyNumbers = [];
        try { $dailyNumbers = $this->lotteryDailyNumberModel->getByRound($id); } catch (\Throwable) {}
        $distribution = ['high' => 0, 'medium' => 0, 'low' => 0];
        try {
            $dist = $this->lotteryParticipationModel->getChanceDistribution($id);
            $distribution = ['high' => $dist['high'], 'medium' => $dist['medium'], 'low' => $dist['low']];
        } catch (\Throwable) {}
        return view('admin.lottery.show', compact('round','participantCount','dailyNumbers','distribution'));
    }

}
