<?php

namespace App\Controllers\Admin;
use App\Services\User\UserService;

use App\Contracts\WalletServiceInterface;
use App\Controllers\Admin\BaseAdminController;

class TransactionController extends BaseAdminController
{
    protected UserService $userService;
    // $userService inherited from parent
    private WalletServiceInterface $walletService;
    // BUGFIX-CTRL-RAW-SQL-2026-06: lookup of single transactions by surrogate
    // id moved out of inline SQL into Transaction::findById().
    private \App\Models\Transaction $transactionModel;

    public function __construct(UserService $userService,
        WalletServiceInterface $walletService,
        \App\Models\Transaction $transactionModel,
        ?\App\Contracts\LoggerInterface $logger = null) {
        parent::__construct(null, null, null, null, $logger);
        $this->userService = $userService;
        $this->walletService = $walletService;
        $this->transactionModel = $transactionModel;
    }

    /**
     * لیست تمام تراکنش‌ها
     */
    public function index(): void
{
    
    $page = max(1, $this->request->int('page', 1));
    if ($page < 1) $page = 1;

    $status = $this->request->str('status') !== '' ? $this->request->str('status') : null;
    $type = $this->request->str('type') !== '' ? $this->request->str('type') : null;
    $currency = $this->request->str('currency') !== '' ? $this->request->str('currency') : null;

    $limit = 50;
    $offset = ($page - 1) * $limit;

    try {
        $transactions = $this->walletService->getAllTransactions($status, $type, $currency, $limit, $offset);
        $total = $this->walletService->countAllTransactions($status, $type, $currency);
        $totalPages = (int) \ceil($total / $limit);

        $this->view('admin/transactions/index', [
            'transactions' => $transactions,
            'currentPage' => $page,
            'totalPages' => $totalPages,
            'total' => $total,
            'status' => $status,
            'type' => $type,
            'currency' => $currency,
            'pageTitle' => 'تراکنش‌های مالی',
        ]);
        return;

    } catch (\Throwable $e) {
    try {
        $this->logger->error('admin.transactions.index.failed', [
            'channel' => 'admin',
            'error' => $e->getMessage(),
            'exception' => get_class($e),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
        ]);
    } catch (\Throwable $ignore) { /* intentional: non-blocking operation */ }

        $this->session->setFlash('error', 'خطا در دریافت لیست تراکنش‌ها');

        // ✅ به جای redirect به داشبورد، همان صفحه را با لیست خالی نشان بده
        $this->view('admin/transactions/index', [
            'transactions' => [],
            'currentPage' => 1,
            'totalPages' => 1,
            'total' => 0,
            'status' => $status,
            'type' => $type,
            'currency' => $currency,
            'pageTitle' => 'تراکنش‌های مالی',
        ]);
        return;
    }
}

    /**
     * نمایش جزئیات تراکنش
     */
    public function show(): void
    {
                $transactionId = $this->request->int('id');

        try {
            $transaction = $this->walletService->findTransactionById($transactionId);

            if (!$transaction) {
                $this->session->setFlash('error', 'تراکنش یافت نشد');
                redirect('/admin/transactions');
            }

            // دریافت اطلاعات کاربر
            $user = $this->userService->find($transaction->user_id);

            // تبدیل metadata از JSON
            $metadata = null;
            if ($transaction->metadata) {
                $metadata = \json_decode($transaction->metadata, true);
            }

            $this->view('admin/transactions/show', [
                'transaction' => $transaction,
                'user' => $user,
                'metadata' => $metadata,
                'pageTitle' => 'جزئیات تراکنش'
            ]);

        } catch (\Exception $e) {
    $this->logger->error('admin.transaction.show.failed', [
        'channel' => 'admin',
        'transaction_id' => $transactionId,
        'error' => $e->getMessage(),
        'exception' => get_class($e),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
    ]);

            $this->session->setFlash('error', 'خطا در دریافت اطلاعات');
            redirect('/admin/transactions');
        }
    }
    public function reverse(): void
    {
        $id = $this->request->int('id');
        $reason = trim($this->request->str('reason'));
        if ($id <= 0 || $reason === '') {
            $this->response->json(['success' => false, 'message' => 'شناسه تراکنش و علت برگشت الزامی است.'], 422);
            return;
        }

        try {
            // BUGFIX-CTRL-RAW-SQL-2026-06: lookup moved to Transaction::findById().
            $tx = $this->transactionModel->findById((int)$id);
            if (!$tx) {
                $this->response->json(['success' => false, 'message' => 'تراکنش یافت نشد.'], 404);
                return;
            }
            if (($tx->status ?? '') === 'reversed') {
                $this->response->json(['success' => false, 'message' => 'این تراکنش قبلاً برگشت داده شده است.'], 422);
                return;
            }

            $adminId = $this->requireAdminId();
            $ok = $this->walletService->reverseTransaction((string)$tx->transaction_id, $adminId, $reason);
            if (!$ok) {
                $this->response->json(['success' => false, 'message' => 'امکان برگشت این تراکنش وجود ندارد.'], 422);
                return;
            }

            $this->auditLog('transaction_reversed', 'transaction', $id, ['status' => $tx->status], ['status' => 'reversed', 'reason' => $reason]);
            $this->response->json(['success' => true, 'message' => 'تراکنش با موفقیت برگشت داده شد.']);
        } catch (\Throwable $e) {
            $this->logger->error('admin.transaction.reverse.failed', [
                'transaction_id' => $id,
                'error' => $e->getMessage(),
            ]);
            $this->response->json(['success' => false, 'message' => 'خطا در برگشت تراکنش.'], 500);
        }
    }
}
