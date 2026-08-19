<?php

namespace App\Controllers\Admin;
use App\Models\BankCard;
use App\Services\User\UserService;

use App\Models\ManualDeposit;
use App\Contracts\WalletServiceInterface;
use App\Controllers\Admin\BaseAdminController;
use App\Services\ManualDepositService;
use Core\Logger;

class ManualDepositController extends BaseAdminController
{
    protected UserService $userService;
    // $userService inherited from parent
	private ManualDepositService $manualDepositService;

    public function __construct(
    UserService $userService,
    ManualDepositService $manualDepositService,
    Logger $logger
    ) {
        parent::__construct(null, null, null, null, $logger);
        $this->userService = $userService;
        $this->manualDepositService = $manualDepositService;
}

    /**
     * لیست واریزهای دستی در انتظار
     */
    public function index(): void
    {
                
        $page = max(1, $this->request->int('page', 1));
        $status = $this->request->str('status', '');
        $limit = 20;
        $offset = ($page - 1) * $limit;

        try {
            if ($status) {
                $result = $this->manualDepositService->listByStatus($status, $limit, $offset);
                $deposits = $result['deposits'] ?? [];
                $total = $result['total'] ?? 0;
            } else {
                $result = $this->manualDepositService->listPending($limit, $offset);
                $deposits = $result['deposits'] ?? [];
                $total = $result['total'] ?? 0;
            }

            $totalPages = (int)\ceil($total / $limit);

            view('admin.manual-deposits.index', [
                'deposits' => $deposits,
                'currentPage' => $page,
                'totalPages' => $totalPages,
                'total' => $total,
                'status' => $status,
                'pageTitle' => 'واریزهای دستی'
            ]);

        } catch (\Exception $e) {
    $this->logger->error('admin.manual_deposits.index.failed', [
        'channel' => 'admin',
        'error' => $e->getMessage(),
        'exception' => get_class($e),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
    ]);

            $this->session->setFlash('error', 'خطا در دریافت لیست');
            redirect('/admin/dashboard');
        }
    }

    /**
     * صفحه بررسی واریز دستی
     */
    public function review(): void
    {
                $depositId = $this->request->int('id');

        try {
            $deposit = $this->manualDepositService->getDeposit($depositId);

            if (!$deposit) {
                $this->session->setFlash('error', 'واریز یافت نشد');
                redirect('/admin/manual-deposits');
            }

            // دریافت اطلاعات کاربر
            $user = $this->userService->find($deposit->user_id);

            // دریافت اطلاعات کارت
            $card = $this->manualDepositService->getCard($deposit->card_id);

            view('admin.manual-deposits.review', [
                'deposit' => $deposit,
                'user' => $user,
                'card' => $card,
                'pageTitle' => 'بررسی واریز دستی'
            ]);

        } catch (\Exception $e) {
    $this->logger->error('admin.manual_deposit.review.failed', [
        'channel' => 'admin',
        'deposit_id' => $depositId,
        'error' => $e->getMessage(),
        'exception' => get_class($e),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
    ]);

            $this->session->setFlash('error', 'خطا در دریافت اطلاعات');
            redirect('/admin/manual-deposits');
        }
    }

   /**
 * تأیید واریز دستی
 */
public function verify(): void
{
    $depositId = 0;
    try {
        $adminId = (int) user_id();

        $depositId = $this->request->int('deposit_id');
        if ($depositId <= 0) {
            $this->response->json([
                'success' => false,
                'message' => 'شناسه واریز نامعتبر است'
            ], 422);
            return;
        }

        $note = $this->request->str('admin_note');

        $result = $this->manualDepositService->approve($adminId, $depositId, $note);

        $this->response->json([
            'success' => (bool)$result,
            'message' => $result ? 'واریز با موفقیت تایید شد' : 'خطا در تایید واریز'
        ], $result ? 200 : 422);
        return;
    } catch (\Core\Exceptions\HttpResponseException $e) {
        throw $e;
    } catch (\Throwable $e) {
        $this->logger->error('admin.manual_deposit.verify.failed', [
            'channel' => 'admin',
            'admin_id' => user_id(),
            'deposit_id' => $depositId,
            'error' => $e->getMessage(),
            'exception' => get_class($e),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
        ]);

        $this->response->json([
            'success' => false,
            'message' => 'خطای سرور در تایید واریز'
        ], 500);
    }
}

    /**
     * رد واریز دستی
     */
   public function reject(): void
{
    $depositId = 0;
    try {
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

        $result = $this->manualDepositService->reject($adminId, $depositId, $reason);

        $this->response->json([
            'success' => (bool)$result,
            'message' => $result ? 'درخواست واریز رد شد' : 'خطا در رد درخواست'
        ], $result ? 200 : 422);
        return;
    } catch (\Core\Exceptions\HttpResponseException $e) {
        throw $e;
    } catch (\Throwable $e) {
        $this->logger->error('admin.manual_deposit.reject.failed', [
            'channel' => 'admin',
            'admin_id' => user_id(),
            'deposit_id' => $depositId,
            'error' => $e->getMessage(),
            'exception' => get_class($e),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
        ]);

        $this->response->json([
            'success' => false,
            'message' => 'خطای سرور در رد واریز'
        ], 500);
    }
}
}
