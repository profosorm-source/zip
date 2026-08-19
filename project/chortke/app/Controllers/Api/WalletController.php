<?php

namespace App\Controllers\Api;

use App\Contracts\WalletServiceInterface;

/**
 * API\WalletController - کیف‌پول
 *
 * GET  /api/v1/wallet              → موجودی
 * GET  /api/v1/wallet/transactions → تاریخچه تراکنش‌ها
 */
class WalletController extends BaseApiController
{
    private WalletServiceInterface $walletService;
    private \App\Services\BankCardService $bankCardService;
    private \App\Services\Withdrawal\WithdrawalUserService $withdrawalUserService;
    private \App\Services\ManualDepositService $manualDepositService;
    private \App\Services\CryptoDeposit\CryptoDepositService $cryptoDepositService;

    public function __construct(
        WalletServiceInterface $walletService,
        \App\Services\BankCardService $bankCardService,
        \App\Services\Withdrawal\WithdrawalUserService $withdrawalUserService,
        \App\Services\ManualDepositService $manualDepositService,
        \App\Services\CryptoDeposit\CryptoDepositService $cryptoDepositService,
        ?\App\Contracts\LoggerInterface $logger = null
    ) {
        parent::__construct(null, null, null, null, $logger);
        $this->walletService = $walletService;
        $this->bankCardService = $bankCardService;
        $this->withdrawalUserService = $withdrawalUserService;
        $this->manualDepositService = $manualDepositService;
        $this->cryptoDepositService = $cryptoDepositService;
    }

    /** موجودی کیف‌پول */
    public function balance(): void
    {
        $userId = (int)$this->userId();
        $balances = $this->walletService->getWalletBalances($userId);

        if (empty($balances)) {
            $this->error('کیف‌پول یافت نشد', 404, 'WALLET_NOT_FOUND');
        }

        $this->success($balances);
    }

    /** تاریخچه تراکنش‌ها */
    public function transactions(): void
    {
        $userId = (int)$this->userId();
        [$page, $perPage, $offset] = $this->paginationParams(20);

        $filters = [];
        if ($type = $this->request->get('type')) {
            $filters['type'] = $type;
        }
        if ($status = $this->request->get('status')) {
            $filters['status'] = $status;
        }
        if ($currency = $this->request->get('currency')) {
            $filters['currency'] = $currency;
        }

        $items = $this->walletService->getUserTransactions($userId, $perPage, $offset, $filters);
        $total = $this->walletService->countUserTransactions($userId, $filters);

        // پاکسازی داده‌های حساس
        $items = array_map(fn($tx) => [
            'id'          => $tx->id,
            'type'        => $tx->type,
            'amount'      => (string)$tx->amount,
            'currency'    => $tx->currency,
            'status'      => $tx->status,
            'description' => $tx->description,
            'created_at'  => $tx->created_at,
        ], $items);

        $this->paginated($items, $total, $page, $perPage);
    }

    /** لیست کارت‌های بانکی */
    public function bankCardsList(): void
    {
        $userId = (int)$this->userId();
        $cards = $this->bankCardService->listForUser($userId);
        $this->success($cards);
    }

    /** افزودن کارت بانکی */
    public function bankCardsStore(): void
    {
        $userId = (int)$this->userId();
        $data = $this->request->body();
        $result = $this->bankCardService->create($userId, $data);
        if (!$result['success']) {
            $this->error(str_value($result['message'] ?? 'خطا در ثبت کارت'), 422);
        }
        $this->success($result, 'کارت بانکی با موفقیت ثبت شد', 201);
    }

    /** حذف کارت بانکی */
    public function bankCardsDelete(): void
    {
        $userId = (int)$this->userId();
        $id = $this->request->int('id');
        $result = $this->bankCardService->softDeleteByUser($userId, $id);
        if (!$result['success']) {
            $this->error(str_value($result['message'] ?? 'خطا در حذف کارت'), 400);
        }
        $this->success(null, 'کارت بانکی حذف شد');
    }

    /** انتخاب کارت اصلی */
    public function bankCardsSetPrimary(): void
    {
        $userId = (int)$this->userId();
        $id = $this->request->int('id');
        $result = $this->bankCardService->setPrimary($userId, $id);
        if (!$result['success']) {
            $this->error(str_value($result['message'] ?? 'خطا در انتخاب کارت اصلی'), 400);
        }
        $this->success(null, 'کارت بانکی به عنوان کارت اصلی انتخاب شد');
    }

    /** ثبت درخواست برداشت وجه */
    public function withdrawSubmit(): void
    {
        $userId = (int)$this->userId();
        $payload = $this->request->body();
        try {
            $result = $this->withdrawalUserService->requestFromUser($userId, $payload);
            if (!empty($result['success'])) {
                $this->success($result, 'درخواست برداشت با موفقیت ثبت شد', 201);
            }
            $this->error(str_value($result['message'] ?? 'خطا در ثبت درخواست برداشت'), 400);
        } catch (\Throwable $e) {
            $this->error($e->getMessage(), 422);
        }
    }

    /** سقف و قوانین برداشت */
    public function withdrawLimits(): void
    {
        $limits = [
            'min_irt' => 50000,
            'max_irt' => 50000000,
            'min_usdt' => 10,
            'max_usdt' => 5000,
            'daily_count_limit' => 3,
            'fee_percentage' => 1.0,
        ];
        $this->success($limits);
    }

    /** ثبت فیش واریز دستی */
    public function manualDepositStore(): void
    {
        $userId = (int)$this->userId();
        $data = $this->request->body();
        $receiptPath = $data['receipt_path'] ?? null;
        $result = $this->manualDepositService->create($userId, $data, str_value($receiptPath));
        if (!$result['success']) {
            $this->error(str_value($result['message'] ?? 'خطا در ثبت واریز دستی'), 422);
        }
        $this->success($result, 'واریز دستی با موفقیت ثبت شد و در انتظار تایید است', 201);
    }

    /** ثبت نیت واریز رمزارز */
    public function cryptoDepositCreateIntent(): void
    {
        $userId = (int)$this->userId();
        $data = $this->request->body();
        $network = str_value($data['network'] ?? 'TRC20');
        $amount = is_numeric($data['amount'] ?? null) ? (string)$data['amount'] : '0';
        $result = $this->cryptoDepositService->createIntent($userId, $network, $amount, $this->request->ip(), $this->request->userAgent());
        if (!empty($result['success'])) {
            $this->success($result, 'درخواست واریز رمزارز ایجاد شد', 201);
        }
        $this->error(str_value($result['message'] ?? 'خطا در ایجاد درخواست واریز رمزارز'), 422);
    }

    /** آدرس ولت‌های سایت */
    public function cryptoDepositWallets(): void
    {
        $appSettings = $this->container->make(\App\Services\Settings\AppSettings::class);
        $wallets = [
            'BNB20' => $appSettings->get('site_usdt_bnb20_address', ''),
            'TRC20' => $appSettings->get('site_usdt_trc20_address', ''),
            'TON'   => $appSettings->get('site_usdt_ton_address', ''),
            'SOL'   => $appSettings->get('site_usdt_sol_address', ''),
        ];
        $this->success($wallets);
    }

    /** لیست آمار سرمایه‌گذاری */
    public function investmentList(): void
    {
        $userId = (int)$this->userId();
        $invService = $this->container->make(\App\Services\InvestmentService::class);
        $result = $invService->searchInvestments('', ['user_id' => $userId], 100, 0);
        $this->success($result);
    }

    /** ایجاد سرمایه‌گذاری جدید */
    public function investmentStore(): void
    {
        $userId = (int)$this->userId();
        $data = $this->request->body();
        $invService = $this->container->make(\App\Services\InvestmentService::class);
        try {
            $result = $invService->createInvestment($userId, $data);
            if (!empty($result['success'])) {
                $this->success($result, 'سرمایه‌گذاری با موفقیت انجام شد', 201);
            }
            $this->error(str_value($result['message'] ?? 'خطا در ثبت سرمایه‌گذاری'), 422);
        } catch (\Throwable $e) {
            $this->error($e->getMessage(), 422);
        }
    }

    /** درخواست برداشت سود سرمایه‌گذاری */
    public function investmentWithdraw(): void
    {
        $userId = (int)$this->userId();
        $data = $this->request->body();
        $invService = $this->container->make(\App\Services\InvestmentService::class);
        try {
            $result = $invService->requestWithdrawal($userId, $data);
            if (!empty($result['success'])) {
                $this->success($result, 'درخواست برداشت سود ثبت شد', 201);
            }
            $this->error(str_value($result['message'] ?? 'خطا در درخواست برداشت سود'), 422);
        } catch (\Throwable $e) {
            $this->error($e->getMessage(), 422);
        }
    }

    /** آمار زیرمجموعه‌گیری (Referral) */
    public function referralStats(): void
    {
        $userId = (int)$this->userId();
        $refService = $this->container->make(\App\Services\Shared\ReferralService::class);
        $stats = $refService->getReferrerStats($userId);
        $tier = $refService->getCurrentTier($userId);
        $this->success(['stats' => $stats, 'tier' => $tier]);
    }

    /** لیست زیرمجموعه‌ها */
    public function referralUsers(): void
    {
        $userId = (int)$this->userId();
        [$page, $perPage, $offset] = $this->paginationParams(20);
        $refService = $this->container->make(\App\Services\Shared\ReferralService::class);
        $users = $refService->getReferredUsers($userId, $perPage, $offset);
        $total = $refService->countReferredUsers($userId);
        $this->paginated($users, $total, $page, $perPage);
    }

    /** لیست دوره‌های قرعه‌کشی */
    public function lotteryList(): void
    {
        [$page, $perPage, $offset] = $this->paginationParams(20);
        $lotteryService = $this->container->make(\App\Services\Lottery\LotteryService::class);
        $rounds = $lotteryService->listRounds(['status' => 'active'], $perPage, $offset);
        $this->paginated(is_array($rounds['items'] ?? null) ? $rounds['items'] : [], int_value($rounds['total'] ?? 0), $page, $perPage);
    }

    /** شرکت در قرعه‌کشی */
    public function lotteryJoin(): void
    {
        $userId = (int)$this->userId();
        $data = $this->request->body();
        $roundId = int_value($data['round_id'] ?? 0);
        $lotteryService = $this->container->make(\App\Services\Lottery\LotteryService::class);
        try {
            $result = $lotteryService->participate($userId, $roundId);
            if (!empty($result['success'])) {
                $this->success($result, 'ثبت‌نام در قرعه‌کشی با موفقیت انجام شد', 201);
            }
            $this->error(str_value($result['message'] ?? 'خطا در ثبت‌نام قرعه‌کشی'), 422);
        } catch (\Throwable $e) {
            $this->error($e->getMessage(), 422);
        }
    }
}
