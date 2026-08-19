<?php
// app/Controllers/Admin/InvestmentController.php

namespace App\Controllers\Admin;

use Core\Response;

use App\Models\Investment;
use App\Models\TradingRecord;
use App\Models\InvestmentProfit;
use App\Models\InvestmentWithdrawal;
use App\Services\InvestmentService;
use App\Services\Search\SearchOrchestrator;
use App\Controllers\Admin\BaseAdminController;

class InvestmentController extends BaseAdminController
{
    private \App\Models\TradingRecord $tradingRecordModel;
    private \App\Models\InvestmentWithdrawal $investmentWithdrawalModel;
    private \App\Models\InvestmentProfit $investmentProfitModel;
    private \App\Models\Investment $investmentModel;
    private InvestmentService $investmentService;
    private SearchOrchestrator $searchService;

    public function __construct(
        \App\Models\Investment $investmentModel,
        \App\Models\InvestmentProfit $investmentProfitModel,
        \App\Models\InvestmentWithdrawal $investmentWithdrawalModel,
        \App\Models\TradingRecord $tradingRecordModel,
        \App\Services\InvestmentService $investmentService,
        SearchOrchestrator $searchService, ?\App\Contracts\LoggerInterface $logger = null) {
        parent::__construct(null, null, null, null, $logger);
        $this->investmentService = $investmentService;
        $this->investmentModel = $investmentModel;
        $this->investmentProfitModel = $investmentProfitModel;
        $this->investmentWithdrawalModel = $investmentWithdrawalModel;
        $this->tradingRecordModel = $tradingRecordModel;
        $this->searchService = $searchService;
    }

    /**
     * داشبورد سرمایه‌گذاری (ادمین)
     */
    public function index(): void
    {
        $investModel = $this->investmentModel;
        $tradingModel = $this->tradingRecordModel;

        $filters = [
            'status' => $this->request->query('status'),
        ];

        $search = trim(str_value($this->request->query('search', '')));
        $page = max(1, int_value($this->request->query('page', 1)));
        $perPage = 15;
        $offset = ($page - 1) * $perPage;

        // استفاده از SearchOrchestrator برای جستجو
        if (!empty($search)) {
            $query = \App\Services\Search\SearchQuery::fromArray(['q' => $search, 'filters' => $filters, 'limit' => $perPage, 'offset' => $offset]);
            $result = $this->searchService->searchAdminModule('investment', $query);
            $investments = $result['items'] ?? [];
            $total = $result['total'] ?? 0;
        } else {
            $investments = $investModel->getAll($filters, $perPage, $offset);
            $total = $investModel->countAll($filters);
        }

        $totalPages = ceil($total / $perPage);
        $stats = $investModel->getStats();
        $tradeStats = $tradingModel->getStats();

        view('admin.investment.index', [
            'investments' => $investments,
            'stats' => $stats,
            'tradeStats' => $tradeStats,
            'total' => $total,
            'totalPages' => $totalPages,
            'currentPage' => $page,
            'filters' => $filters,
            'search' => $search,
        ]);
    }

    /**
     * جزئیات سرمایه‌گذاری
     */
    public function show(int $id): void
    {
        $investModel = $this->investmentModel;
        $profitModel = $this->investmentProfitModel;
        $withdrawalModel = $this->investmentWithdrawalModel;

        $investment = $investModel->findWithUser($id);
        if (!$investment) {
            view('errors.404');
            return;
        }

        $profits = $profitModel->getByInvestment($id);
        $totalStats = $profitModel->getTotalByInvestment($id);
        $withdrawals = $withdrawalModel->getAll(['status' => null], 50, 0);

        view('admin.investment.show', [
            'investment' => $investment,
            'profits' => $profits,
            'totalStats' => $totalStats,
            'withdrawals' => $withdrawals,
        ]);
    }

    /**
     * لیست تریدها
     */
    public function trades(): void
    {
        $tradingModel = $this->tradingRecordModel;

        $filters = ['status' => $this->request->query('status')];
        $page = max(1, int_value($this->request->query('page', 1)));
        $perPage = 15;
        $offset = ($page - 1) * $perPage;

        $trades = $tradingModel->getAll($filters, $perPage, $offset);
        $total = $tradingModel->countAll($filters);
        $totalPages = ceil($total / $perPage);
        $stats = $tradingModel->getStats();

        view('admin.investment.trades', [
            'trades' => $trades,
            'stats' => $stats,
            'total' => $total,
            'totalPages' => $totalPages,
            'currentPage' => $page,
            'filters' => $filters,
        ]);
    }

    /**
     * فرم ثبت ترید جدید
     */
    public function tradeCreate(): void
    {
        view('admin.investment.trade-create', []);
    }

    /**
     * ثبت ترید (POST - AJAX)
     */
    public function tradeStore(): void
    {
        $input = $this->request->all();

        $validator = $this->validatorFactory()->make($input, [
            'direction' => 'required|in:buy,sell',
            'open_price' => 'required|numeric|min:0',
            'open_time' => 'required',
            'pair' => 'max:20',
            'lot_size' => 'numeric|min:0',
        ]);

        if ($validator->fails()) {
            $this->response->json([
                'success' => false, 'message' => 'اطلاعات ورودی نامعتبر.',
                'errors' => $validator->errors()
            ], 422);
        }

        $data = $validator->data();
        $result = $this->investmentService->createTrade($this->requireAdminId(), (array)$data);

        $this->response->json($result, $result['success'] ? 200 : 422);
    }

    /**
     * بستن ترید (AJAX)
     */
    public function tradeClose(int $id): void
    {
        $input = $this->request->all();

        $validator = $this->validatorFactory()->make($input, [
            'close_price' => 'required|numeric|min:0',
            'profit_loss_percent' => 'required|numeric',
            'profit_loss_amount' => 'required|numeric',
        ]);

        if ($validator->fails()) {
            $this->response->json([
                'success' => false, 'errors' => $validator->errors()
            ], 422);
        }

        $data = $validator->data();
        $result = $this->investmentService->closeTrade($id, $this->requireAdminId(), (array)$data);

        $this->response->json($result, $result['success'] ? 200 : 422);
    }

    /**
     * فرم اعمال سود/ضرر هفتگی
     */
    public function applyProfitForm(): void
    {
        $tradingModel = $this->tradingRecordModel;
        $closedTrades = $tradingModel->getRecentClosed(20);

        view('admin.investment.apply-profit', [
            'closedTrades' => $closedTrades,
            'settings' => $this->investmentService->getSettings(),
        ]);
    }

    /**
     * اعمال سود/ضرر هفتگی (POST - AJAX)
     */
    public function applyProfit(): void
    {
        $input = $this->request->all();

        $validator = $this->validatorFactory()->make($input, [
            'trading_record_id' => 'required|numeric',
            'profit_loss_percent' => 'required|numeric',
            'period' => 'required|max:10',
        ]);

        if ($validator->fails()) {
            $this->response->json([
                'success' => false, 'errors' => $validator->errors()
            ], 422);
        }

        $data = $validator->data();
        $result = $this->investmentService->applyWeeklyProfitLoss(
            $this->requireAdminId(),
            int_value($data['trading_record_id']),
            is_scalar($data['profit_loss_percent'] ?? null) ? (string)$data['profit_loss_percent'] : '0',
            str_value($data['period'] ?? '')
        );

        $this->response->json($result, $result['success'] ? 200 : 422);
    }

    /**
     * لیست درخواست‌های برداشت
     */
    public function withdrawals(): void
    {
        $model = $this->investmentWithdrawalModel;

        $filters = ['status' => $this->request->query('status')];
        $page = max(1, int_value($this->request->query('page', 1)));
        $perPage = 15;
        $offset = ($page - 1) * $perPage;

        $withdrawals = $model->getAll($filters, $perPage, $offset);
        $total = $model->countAll($filters);
        $totalPages = ceil($total / $perPage);

        view('admin.investment.withdrawals', [
            'withdrawals' => $withdrawals,
            'total' => $total,
            'totalPages' => $totalPages,
            'currentPage' => $page,
            'filters' => $filters,
        ]);
    }

    /**
     * تأیید برداشت (AJAX)
     */
    public function withdrawalApprove(int $id): void
    {
        $result = $this->investmentService->approveWithdrawal($id, $this->requireAdminId());

        $this->response->json($result, $result['success'] ? 200 : 422);
    }

    /**
     * رد برداشت (AJAX)
     */
    public function withdrawalReject(int $id): void
    {
        $input = is_array($this->request->input()) ? $this->request->input() : [];

        $validator = $this->validatorFactory()->make($input, [
            'reason' => 'required|min:10|max:500',
        ]);

        if ($validator->fails()) {
            $this->response->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        $data = $validator->data();
        $result = $this->investmentService->rejectWithdrawal(
            $id,
            $this->requireAdminId(),
            str_value($data['reason'] ?? '')
        );

        $this->response->json($result, $result['success'] ? 200 : 422);
    }

    /**
     * تعلیق سرمایه‌گذاری (AJAX)
     */
    public function suspend(int $id): void
    {
        $input = $this->request->all();

        $investModel = $this->investmentModel;
        $investment = $investModel->find($id);

        if (!$investment) {
            $this->response->json(['success' => false, 'message' => 'سرمایه‌گذاری یافت نشد.'], 404);
        }

        $investModel->update($id, [
            'status' => Investment::STATUS_SUSPENDED,
            'admin_notes' => $input['reason'] ?? 'تعلیق توسط مدیر',
        ]);

        $this->logger->info('investment_suspended', ['message' => "Admin " . $this->requireAdminId() . " suspended investment #{$id}"]);

        $this->response->json(['success' => true, 'message' => 'سرمایه‌گذاری تعلیق شد.']);
    }

    /**
     * گزارش توانگری و حلالیت سیستم سرمایه‌گذاری (AJAX / GET)
     */
    public function solvencyReport(): void
    {
        $report = $this->investmentService->getSolvencyReport();
        $this->response->json($report, 200);
    }
}