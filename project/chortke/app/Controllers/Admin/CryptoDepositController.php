<?php

namespace App\Controllers\Admin;
use App\Services\User\UserService;
use App\Models\CryptoDeposit;
use App\Contracts\WalletServiceInterface;
use App\Controllers\Admin\BaseAdminController;
use App\Services\CryptoDeposit\CryptoDepositService;
use Core\Logger;

class CryptoDepositController extends BaseAdminController
{
    protected UserService $userService;
    // $userService inherited from parent
    private CryptoDeposit $depositModel;
	private \App\Services\CryptoDeposit\CryptoDepositService $cryptoDepositService;
	// $logger inherited from parent

    public function __construct(
    UserService $userService,
    \App\Models\CryptoDeposit $depositModel,
    \App\Services\CryptoDeposit\CryptoDepositService $cryptoDepositService,
    Logger $logger
    ) {
    parent::__construct(null, null, null, null, $logger);
    $this->userService = $userService;
    $this->depositModel = $depositModel;
    $this->cryptoDepositService = $cryptoDepositService;
	$this->logger = $logger;
}

    /**
     * لیست واریزهای کریپتو نیازمند بررسی دستی
     */
    public function index(): void
    {
                
        $page = max(1, $this->request->int('page', 1));
        $status = $this->request->str('status') !== '' ? $this->request->str('status') : null;
        $network = $this->request->str('network') !== '' ? $this->request->str('network') : null;
        $limit = 20;
        $offset = ($page - 1) * $limit;

        try {
            if ($status || $network) {
                $deposits = $this->depositModel->getAll($status, $network, $limit, $offset);
                $total = $this->depositModel->countAll($status, $network);
            } else {
                $deposits = $this->depositModel->getManualReviewDeposits($limit, $offset);
                $total = $this->depositModel->countManualReview();
            }

            $totalPages = (int)\ceil($total / $limit);

            view('admin.crypto-deposits.index', [
                'deposits' => $deposits,
                'currentPage' => $page,
                'totalPages' => $totalPages,
                'total' => $total,
                'status' => $status,
                'network' => $network,
                'pageTitle' => 'واریزهای کریپتو'
            ]);

        } catch (\Core\Exceptions\HttpResponseException $e) {
            throw $e;
        } catch (\Exception $e) {
    $this->logger->error('admin.crypto_deposits.index.failed', [
        'channel' => 'admin',
        'error' => $e->getMessage(),
        'exception' => get_class($e),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
    ]);

            $this->session->setFlash('error', 'خطا در دریافت لیست');

view('admin.crypto-deposits.index', [
    'deposits' => [],
    'currentPage' => 1,
    'totalPages' => 1,
    'total' => 0,
    'status' => $status,
    'network' => $network,
    'pageTitle' => 'واریزهای کریپتو'
]);
return;
        }
    }

    /**
     * صفحه بررسی واریز کریپتو
     */
   public function review(): void
{
    $depositId = $this->request->int('id');

    try {
        $deposit = $this->depositModel->find($depositId);

        if (!$deposit) {
            $this->session->setFlash('error', 'واریز یافت نشد');
            redirect('/admin/crypto-deposits');
        }

        $user = $this->userService->find($deposit->user_id);

        $network = strtoupper(trim((string)$deposit->network));
        $baseUrl = match ($network) {
            'TRC20' => 'https://tronscan.org/#/transaction/',
            'BNB20' => 'https://bscscan.com/tx/',
            'TON'   => 'https://tonscan.org/tx/',
            'SOL'   => 'https://explorer.solana.com/tx/',
            default => null,
        };
        $explorerUrl = $baseUrl !== null ? $baseUrl . rawurlencode((string)$deposit->tx_hash) : null;

        view('admin.crypto-deposits.review', [
            'deposit' => $deposit,
            'user' => $user,
            'explorerUrl' => $explorerUrl,
            'pageTitle' => 'بررسی واریز کریپتو'
        ]);
    } catch (\Core\Exceptions\HttpResponseException $e) {
        throw $e;
    } catch (\Exception $e) {
        $this->logger->error('admin.crypto_deposit.review.failed', [
            'channel' => 'admin',
            'deposit_id' => $depositId,
            'error' => $e->getMessage(),
            'exception' => get_class($e),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
        ]);

        $this->session->setFlash('error', 'خطا در دریافت اطلاعات');
        redirect('/admin/crypto-deposits');
    }
}
    /**
     * تأیید واریز کریپتو
     */
    public function verify(): void
{
    try {
        $this->validateCsrf();

        $adminId = (int) user_id();

        $depositId = $this->request->int('deposit_id');
        if ($depositId <= 0) {
            $this->response->json([
                'success' => false,
                'message' => 'شناسه واریز نامعتبر است'
            ], 422);
            return;
        }

        $result = $this->cryptoDepositService->approve($adminId, $depositId);

        $this->response->json([
            'success' => (bool) ($result['success'] ?? false),
            'message' => $result['message'] ?? 'خطا'
        ], ($result['success'] ?? false) ? 200 : 422);
        return;
    } catch (\Core\Exceptions\HttpResponseException $e) {
        throw $e;
    } catch (\Throwable $e) {
        $this->logger->error('admin.crypto_deposit.verify.failed', [
            'channel' => 'admin',
            'admin_id' => user_id(),
            'deposit_id' => $depositId ?? null,
            'error' => $e->getMessage(),
            'exception' => get_class($e),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
        ]);

        $this->response->json([
            'success' => false,
            'message' => 'خطای سرور در تایید واریز کریپتو'
        ], 500);
    }
}
    /**
     * رد واریز کریپتو
     */
   public function reject(): void
{
    try {
        $this->validateCsrf();

        $adminId = (int) user_id();

        $depositId = $this->request->int('deposit_id');
        $reason = trim($this->request->str('rejection_reason'));

        if ($depositId <= 0) {
            $this->response->json([
                'success' => false,
                'message' => 'شناسه واریز نامعتبر است'
            ], 422);
            return;
        }

        if ($reason === '') {
            $this->response->json([
                'success' => false,
                'message' => 'دلیل رد الزامی است'
            ], 422);
            return;
        }

        $result = $this->cryptoDepositService->reject($adminId, $depositId, $reason);

        $this->response->json([
            'success' => (bool) ($result['success'] ?? false),
            'message' => $result['message'] ?? 'خطا'
        ], ($result['success'] ?? false) ? 200 : 422);
        return;
    } catch (\Core\Exceptions\HttpResponseException $e) {
        throw $e;
    } catch (\Throwable $e) {
        $this->logger->error('admin.crypto_deposit.reject.failed', [
            'channel' => 'admin',
            'admin_id' => user_id(),
            'deposit_id' => $depositId ?? null,
            'error' => $e->getMessage(),
            'exception' => get_class($e),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
        ]);

        $this->response->json([
            'success' => false,
            'message' => 'خطای سرور در رد واریز کریپتو'
        ], 500);
    }
}
}
