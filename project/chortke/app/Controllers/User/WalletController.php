<?php

namespace App\Controllers\User;

use App\Contracts\WalletServiceInterface;
use App\Controllers\User\BaseUserController;

class WalletController extends BaseUserController
{
    private WalletServiceInterface $walletService;

    public function __construct(WalletServiceInterface $walletService, ?\App\Contracts\LoggerInterface $logger = null) {
        parent::__construct(null, null, null, null, $logger);
        $this->walletService = $walletService;
    }

    /**
     * صفحه اصلی کیف پول (نمایش موجودی)
     */
    public function index(): void
    {
        $userId = (int)$this->userId();
        
        $summary = $this->walletService->getWalletSummary($userId);
        $siteCurrency = config('site_currency', 'irt');
        
        view('user.wallet.index', [
            'summary' => $summary,
            'siteCurrency' => $siteCurrency,
            'pageTitle' => 'کیف پول من'
        ]);
    }

    /**
     * صفحه انتخاب روش افزایش موجودی
     */
    public function depositIndex(): void
    {
        $siteCurrency = config('site_currency', 'irt');
        
        view('user.wallet.deposit-select', [
            'siteCurrency' => $siteCurrency,
            'pageTitle' => 'افزایش موجودی'
        ]);
    }

    /**
     * تاریخچه تراکنش‌ها
     */
    public function history(): void
    {
                $userId = (int)$this->userId();
        
        $page = max(1, $this->request->int('page', 1));
        $type = $this->request->get('type');
        $currency = $this->request->get('currency');
        $limit = 20;
        $offset = ($page - 1) * $limit;

        $filters = ['type' => $type, 'currency' => $currency];
            
            $transactions = $this->walletService->getUserTransactions(
                $userId,
                $limit,
                $offset,
                $filters
            );
            
            $total = $this->walletService->countUserTransactions($userId, $filters);
            $totalPages = (int)\ceil($total / $limit);

            view('user.wallet.history', [
                'transactions' => $transactions,
                'currentPage' => $page,
                'totalPages' => $totalPages,
                'type' => $type,
                'currency' => $currency,
                'pageTitle' => 'تاریخچه تراکنش‌ها'
            ]);

    }
}