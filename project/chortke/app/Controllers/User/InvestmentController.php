<?php
// app/Controllers/User/InvestmentController.php

namespace App\Controllers\User;

use App\Models\Investment;
use App\Models\TradingRecord;
use App\Models\InvestmentProfit;
use App\Models\InvestmentWithdrawal;
use App\Services\InvestmentService;
use App\Policies\RateLimitPolicy;
use App\Controllers\User\BaseUserController;
use App\Contracts\WalletServiceInterface;
use App\Validators\Requests\CreateInvestmentRequest;
use App\Validators\Requests\WithdrawInvestmentRequest;

class InvestmentController extends BaseUserController
{
    private \App\Models\TradingRecord $tradingRecordModel;
    private \App\Models\InvestmentWithdrawal $investmentWithdrawalModel;
    private \App\Models\InvestmentProfit $investmentProfitModel;
    private \App\Models\Investment $investmentModel;
    private InvestmentService $investmentService;

    public function __construct(
        \App\Models\Investment $investmentModel,
        \App\Models\InvestmentProfit $investmentProfitModel,
        \App\Models\InvestmentWithdrawal $investmentWithdrawalModel,
        \App\Models\TradingRecord $tradingRecordModel,
        \App\Services\InvestmentService $investmentService,
        ?\App\Contracts\LoggerInterface $logger = null
    ) {
        parent::__construct(null, null, null, null, $logger);
        $this->investmentService = $investmentService;
        $this->investmentModel = $investmentModel;
        $this->investmentProfitModel = $investmentProfitModel;
        $this->investmentWithdrawalModel = $investmentWithdrawalModel;
        $this->tradingRecordModel = $tradingRecordModel;
    }

    /**
     * صفحه اصلی سرمایه‌گذاری (داشبورد)
     */
    public function index(): void
    {
        $userId = int_value(session()->get(\App\Constants\SessionKeys::USER_ID) ?: 1);

        $activeInvestment = $this->investmentModel->getActiveByUser($userId);

        // canWithdraw — نیاز به سرمایه‌گذاری فعال دارد
        $canWithdraw = $activeInvestment
            ? $this->investmentModel->canWithdraw($userId)
            : ['allowed' => false, 'reason' => ''];

        // آخرین ۵ رکورد سود/ضرر
        $profitHistory = $this->investmentProfitModel->getByUser($userId, 5, 0);

        // آخرین ۱۰ ترید بسته‌شده (عمومی برای همه کاربران)
        $recentTrades = $this->tradingRecordModel->getRecentClosed(10);

        // درخواست‌های برداشت کاربر
        $withdrawals = $this->investmentWithdrawalModel->getByUser($userId, 5, 0);

        // The persisted user row object must reflect real account state. Never
        // inject synthetic balances into a production page; wallet/investment
        // amounts are supplied by their own persisted models and services.
        $userObj = $this->loadCurrentUserOrRedirect();

        $this->view('user/investment/index', [
            'user'             => $userObj,
            'activeInvestment' => $activeInvestment,
            'canWithdraw'      => $canWithdraw,
            'profitHistory'    => $profitHistory,
            'recentTrades'     => $recentTrades,
            'withdrawals'      => $withdrawals,
            'settings'         => $this->investmentService->getSettings(),
            'isDepositLocked'  => $this->investmentModel->isDepositLocked($userId),
        ]);
    }

    /**
     * صفحه ثبت سرمایه‌گذاری
     */
    public function create(): void
    {
        $userId = (int) user_id();

        if ($this->investmentModel->hasActiveInvestment($userId)) {
            session()->setFlash('error', 'شما یک پلن فعال دارید. امکان ایجاد پلن جدید نیست.');
            redirect(url('/investment'));
        }

        if ($this->investmentModel->isDepositLocked($userId)) {
            session()->setFlash('error', 'به دلیل برداشت اخیر، فعلاً امکان سرمایه‌گذاری جدید ندارید.');
            redirect(url('/investment'));
        }

        // BUGFIX-CTRL-RAW-SQL-2026-06: lookup through UserService (inherited from BaseUserController).
        $userObj = $this->userService->findById((int)$userId);

        $this->view('user/investment/create', [
            'user'            => $userObj,
            'riskWarning'     => $this->investmentService->getRiskWarning(),
            'settings'        => $this->investmentService->getSettings(),
            'isDepositLocked' => false,
        ]);
    }

    /**
     * ثبت سرمایه‌گذاری (POST - AJAX)
     */
    public function store(): void
    {
        $userIdForLock = (int)user_id();
        $lockKey = null;
        $lockAcquired = false;
        $lockClient = null;
        if ($userIdForLock > 0) {
            try {
                $redis = app(\Core\Redis::class);
                if ($redis && $redis->isAvailable()) {
                    $lockKey = 'lock:user:' . $userIdForLock . ':' . md5('/investment/store');
                    $routeLockKey = 'lock:route:' . md5('/investment/store');
                    $client = $redis->getClient();
                    if (!$client instanceof \Redis) {
                        throw new \RuntimeException('Redis lock client is unavailable.');
                    }
                    $lockClient = $client;
                    if ($client->exists($lockKey) || $client->exists($routeLockKey)) {
                        $this->response->json([
                            'success' => false,
                            'message' => 'درخواست قبلی شما در حال پردازش است. لطفا چند لحظه صبر کنید.'
                        ], 429);
                        return;
                    }
                    $lockAcquired = (bool)$client->set($lockKey, '1', ['NX', 'EX' => 3]);
                }
            } catch (\Throwable $e) {
                $lockKey = null;
            }
        }

        try {
        $input = !empty($this->request->json()) ? $this->request->json() : $this->request->all();

        $validator = new CreateInvestmentRequest($input);
        $validator->validate();

        if ($validator->fails()) {
            $this->response->json([
                'success' => false,
                'message' => 'اطلاعات ورودی نامعتبر است.',
                'errors'  => $validator->errors(),
            ], 422);
        }

        $data = $validator->validated();

        RateLimitPolicy::enforce('investment_create', (int) user_id(), is_ajax());

        $result = $this->investmentService->createInvestment((int) user_id(), [
            'amount'        => str_value($data['amount'] ?? 0),
            'risk_accepted' => int_value($data['risk_accepted'] ?? 0),
            'idempotency_key' => $data['idempotency_key'] ?? null,
        ]);

        $this->response->json($result, $result['success'] ? 200 : 422);
        } finally {
            if ($lockAcquired && $lockKey) {
                try {
                    $lockClient?->del($lockKey);
                } catch (\Throwable $e) {}
            }
        }
    }
    /**
     * درخواست برداشت (POST - AJAX)
     */
    public function withdraw(): void
    {
        $json = $this->request->json();
        $input = !empty($json) ? $json : $this->request->all();

        $validator = new WithdrawInvestmentRequest($input);
        $validator->validate();

        if ($validator->fails()) {
            $this->response->json([
                'success' => false,
                'message' => 'نوع برداشت را انتخاب کنید.',
                'errors'  => $validator->errors(),
            ], 422);
        }

        $data = $validator->validated();

        $result = $this->investmentService->requestWithdrawal((int) user_id(), [
            'withdrawal_type' => $data['withdrawal_type'],
        ]);

        $this->response->json($result, $result['success'] ? 200 : 422);
    }

    /**
     * تاریخچه سود/ضرر
     */
    public function profitHistory(): void
    {
        $userId  = (int) user_id();
        $page    = max(1, $this->request->int('page', 1));
        $perPage = 20;
        $offset  = ($page - 1) * $perPage;

        $profits    = $this->investmentProfitModel->getByUser($userId, $perPage, $offset);
        $total      = $this->investmentProfitModel->countByUser($userId);
        $totalPages = (int) ceil($total / $perPage);
        $activeInvestment = $this->investmentModel->getActiveByUser($userId);

        // BUGFIX-CTRL-RAW-SQL-2026-06: lookup through UserService (inherited).
        $userObj = $this->userService->findById((int)$userId);

        $this->view('user/investment/profit-history', [
            'user'             => $userObj,
            'profits'          => $profits,
            'total'            => $total,
            'totalPages'       => $totalPages,
            'currentPage'      => $page,
            'activeInvestment' => $activeInvestment,
            'settings'         => $this->investmentService->getSettings(),
            'isDepositLocked'  => $this->investmentModel->isDepositLocked($userId),
        ]);
    }
}