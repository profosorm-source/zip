<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Models\PredictionGame;
use App\Models\PredictionBet;
use App\Services\PredictionService;

class PredictionController extends BaseAdminController
{
    private const SPORT_TYPES = [
        'football'   => 'فوتبال',
        'basketball' => 'بسکتبال',
        'tennis'     => 'تنیس',
        'volleyball' => 'والیبال',
        'baseball'   => 'بیسبال',
        'hockey'     => 'هاکی',
        'cricket'    => 'کریکت',
        'other'      => 'سایر',
    ];

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

    // ─── لیست بازی‌ها ─────────────────────────────────────────────────
    public function index(): void
    {
        $filters = [
            'status'     => $this->request->get('status', ''),
            'sport_type' => $this->request->get('sport_type', ''),
            'search'     => trim($this->request->str('search')),
        ];
        $page    = max(1, $this->request->int('page', 1));
        $perPage = 30;
        $offset  = ($page - 1) * $perPage;

        $games = $this->gameModel->adminList($filters, $perPage, $offset);
        $total = $this->gameModel->adminCount($filters);
        $summary = $this->gameModel->adminSummary($filters);

        view('admin/prediction/index', [
            'title'      => 'مدیریت پیش‌بینی بازی‌ها',
            'games'      => $games,
            'filters'    => $filters,
            'page'       => $page,
            'perPage'    => $perPage,
            'total'      => $total,
            'totalPages' => max(1, (int)ceil($total / $perPage)),
            'sportTypes' => self::SPORT_TYPES,
            'summary'    => $summary,
            'rolloverReserve' => float_value(setting('prediction_rollover_reserve_usdt', 0)),
        ]);
    }

    // ─── فرم تعریف بازی ───────────────────────────────────────────────
    public function create(): void
    {
        view('admin/prediction/create', [
            'title'      => 'تعریف بازی جدید',
            'sportTypes' => self::SPORT_TYPES,
            'rolloverReserve' => float_value(setting('prediction_rollover_reserve_usdt', 0)),
        ]);
    }

    // ─── ذخیره بازی جدید ──────────────────────────────────────────────
    public function store(): void
    {
        $data = $this->request->body();

        $request = new \App\Validators\Requests\CreatePredictionGameRequest($data);

        if (!$request->validate()) {
            $errors = [];
            foreach ($request->errors() as $fieldErrors) {
                foreach ((array)$fieldErrors as $err) {
                    $errors[] = $err;
                }
            }
            $this->session->setFlash('errors', $errors);
            $this->session->setFlash('old', $data);
            redirect(url('/admin/prediction/create'));
        }

        $validatedData = $request->validated();

        $game = $this->gameModel->createGame(array_merge($validatedData, [
            'created_by'         => (int)user_id(),
            'min_bet_usdt'       => str_value($validatedData['min_bet_usdt'] ?? 1),
            'max_bet_usdt'       => str_value($validatedData['max_bet_usdt'] ?? 1000),
            'commission_percent' => str_value($validatedData['commission_percent'] ?? setting('prediction_commission_percent', 5)),
        ]));

        if (!$game) {
            $this->session->setFlash('error', 'خطا در ثبت بازی.');
            redirect(url('/admin/prediction/create'));
        }

        // Dispatch event for real-time search projection indexing (CQRS)
        try {
            \Core\EventDispatcher::getInstance()->dispatch('prediction.created', (array)$game);
        } catch (\Throwable $ignore) {
            // intentional: event dispatch non-critical — prediction proceeds without real-time index
        }

        $this->session->setFlash('success', 'بازی با موفقیت تعریف شد.');
        redirect(url("/admin/prediction/{$game->id}"));
    }

    // ─── جزئیات بازی ──────────────────────────────────────────────────
    public function show(int $id): void
    {
        $game = $this->gameModel->find($id);

        if (!$game) {
            $this->session->setFlash('error', 'بازی یافت نشد.');
            redirect(url('/admin/prediction'));
        }

        $bets = $this->betModel->getByGame($id);
        $dist = $this->betModel->getDistribution($id);

        view('admin/prediction/show', [
            'title' => 'جزئیات بازی: ' . $game->title,
            'game'  => $game,
            'bets'  => $bets,
            'dist'  => $dist,
        ]);
    }

    // ─── ثبت نتیجه + پرداخت یکجا (atomic) ────────────────────────────
    public function settle(int $id): void
    {
        $result = trim($this->request->str('result'));

        if (!in_array($result, ['home', 'away', 'draw'], true)) {
            $this->response->json(['success' => false, 'message' => 'نتیجه نامعتبر است.']);
            return;
        }

        try {
            $summary = $this->predictionService->settleGame($id, $result, (int)user_id());
            if (empty($summary['success'])) {
                $this->response->json(['success' => false, 'message' => $summary['message'] ?? 'تسویه انجام نشد.'], 422);
                return;
            }

            $summaryData = is_array($summary['summary'] ?? null) ? $summary['summary'] : [];
            $this->logger->activity('prediction.settled', "تسویه بازی #{$id} با نتیجه: {$result}", (int)user_id(), $summaryData);

            $s = $summaryData;
            $msg = "نتیجه ثبت شد. به " . int_value($s['winners_paid'] ?? 0) . " برنده پرداخت شد.";
            if (!empty($s['no_winners'])) {
                $msg = "نتیجه ثبت شد. برنده‌ای وجود نداشت — ۵۰٪ استخر به چرخه بعدی منتقل و ۵۰٪ برای هزینه‌های سایت ثبت شد.";
            } elseif (!empty($s['all_winners'])) {
                $msg = "نتیجه ثبت شد. همه درست پیش‌بینی کرده بودند؛ اصل مبلغ‌ها برگشت داده شد و کمیسیون صفر بود.";
            }

            $this->response->json(['success' => true, 'message' => $msg, 'summary' => $s]);

        } catch (\InvalidArgumentException $e) {
            $this->response->json(['success' => false, 'message' => $e->getMessage()]);
        } catch (\Core\Exceptions\HttpResponseException $e) {
            throw $e;
        } catch (\RuntimeException $e) {
            $this->response->json(['success' => false, 'message' => $e->getMessage()]);
        } catch (\Exception $e) {
            $this->logger->error('prediction.settle.failed', ['id' => $id, 'error' => $e->getMessage()]);
            $this->response->json(['success' => false, 'message' => 'خطای سیستمی. لطفاً دوباره تلاش کنید.']);
        }
    }

    // ─── لغو بازی ─────────────────────────────────────────────────────
    public function cancel(int $id): void
    {
        try {
            $result = $this->predictionService->cancelGame($id, (int)user_id());

            $this->logger->activity('prediction.cancelled', "لغو بازی #{$id}", (int)user_id(), ['refunded_count' => $result['refunded_count'] ?? 0]);

            $this->response->json($result);

        } catch (\Core\Exceptions\HttpResponseException $e) {
            throw $e;
        } catch (\RuntimeException $e) {
            $this->response->json(['success' => false, 'message' => $e->getMessage()]);
        } catch (\Exception $e) {
            $this->logger->error('prediction.cancel.failed', ['id' => $id, 'error' => $e->getMessage()]);
            $this->response->json(['success' => false, 'message' => 'خطای سیستمی.']);
        }
    }

    // ─── بستن ثبت پیش‌بینی (بدون تغییر نتیجه) ────────────────────────────
    public function closeBetting(int $id): void
    {
        $ok = $this->gameModel->closeBetting($id);

        $this->response->json([
            'success' => $ok,
            'message' => $ok ? 'ثبت پیش‌بینی برای این بازی بسته شد.' : 'عملیات انجام نشد.',
        ]);
    }

    // ─── ویرایش بازی ──────────────────────────────────────────────────
    public function update(int $id): void
    {
        $game = $this->gameModel->find($id);

        if (!$game) {
            $this->response->json(['success' => false, 'message' => 'بازی یافت نشد.']);
            return;
        }

        $data = $this->request->body() ?? [];

        $betCount = $this->betModel->countByGame($id);
        if ($betCount > 0) {
            // P-1: Only allow updating specific fields if bets exist to prevent changing names/bet limits mid-game
            $allowedFields = ['description', 'status'];
            $data = array_intersect_key($data, array_flip($allowedFields));
        }

        if (empty($data)) {
            $this->response->json(['success' => false, 'message' => 'هیچ فیلد معتبری برای بروزرسانی ارسال نشده است یا بازی دارای پیش‌بینی فعال است.']);
            return;
        }

        // Validate only if there are fields requiring validation
        if (isset($data['title']) || isset($data['team_home']) || isset($data['team_away']) || isset($data['match_date']) || isset($data['bet_deadline'])) {
            $merged = array_merge((array)$game, $data);
            $request = new \App\Validators\Requests\CreatePredictionGameRequest($merged);
            if (!$request->validate()) {
                $errors = [];
                foreach ($request->errors() as $fieldErrors) {
                    foreach ((array)$fieldErrors as $err) {
                        $errors[] = $err;
                    }
                }
                $this->response->json(['success' => false, 'errors' => $errors]);
                return;
            }
        }

        $ok = $this->gameModel->update($id, $data);

        if ($ok) {
            try {
                $updatedGame = $this->gameModel->find($id);
                if ($updatedGame) {
                    \Core\EventDispatcher::getInstance()->dispatch('prediction.updated', (array)$updatedGame);
                }
            } catch (\Throwable $ignore) {
            // intentional: event dispatch non-critical — prediction proceeds without real-time index
        }
        }

        $this->response->json([
            'success' => $ok,
            'message' => $ok ? 'بازی با موفقیت بروزرسانی شد.' : 'تغییری اعمال نشد یا خطایی رخ داد.',
        ]);
    }


}
