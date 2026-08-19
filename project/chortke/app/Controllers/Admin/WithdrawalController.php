<?php

namespace App\Controllers\Admin;

use App\Contracts\WalletServiceInterface;
use App\Services\User\UserService;
use App\Services\BankCardService;
use App\Services\Withdrawal\WithdrawalAdminService;
use App\Services\Withdrawal\WithdrawalQueryService;
use App\Services\ReconciliationService;
use App\Controllers\Admin\BaseAdminController;

class WithdrawalController extends BaseAdminController
{
    protected UserService $userService;
    // $userService inherited from parent
    private BankCardService $cardService;
    private WithdrawalAdminService $withdrawalAdminService;
    private WithdrawalQueryService $withdrawalQueryService;

    public function __construct(
        BankCardService $bankCardService,
        UserService $userService,
        WithdrawalAdminService $withdrawalAdminService,
        WithdrawalQueryService $withdrawalQueryService,
        \Core\Logger $logger
    ) {
        parent::__construct(null, null, null, null, $logger);
        $this->userService = $userService;
        $this->cardService = $bankCardService;
        $this->withdrawalAdminService = $withdrawalAdminService;
        $this->withdrawalQueryService = $withdrawalQueryService;
    }


    /**
     * لیست درخواست‌های برداشت
     */
    public function index(): void
    {
                
        $page = max(1, $this->request->int('page', 1));
        $status = $this->request->str('status') !== '' ? $this->request->str('status') : null;
        $currency = $this->request->str('currency') !== '' ? $this->request->str('currency') : null;
        $limit = 20;
        $offset = ($page - 1) * $limit;

        try {
            if ($status || $currency) {
                $withdrawals = $this->withdrawalQueryService->getAll($status, $currency, $limit, $offset);
                $total = $this->withdrawalQueryService->countAll($status, $currency);
            } else {
                $withdrawals = $this->withdrawalQueryService->getPendingWithdrawals($limit, $offset);
                $total = $this->withdrawalQueryService->countPendingWithdrawals();
            }

            $totalPages = (int)\ceil($total / $limit);

            // آمار خلاصه
            $summary = $this->withdrawalQueryService->getSummaryStats();

            view('admin.withdrawals.index', [
                'withdrawals' => $withdrawals,
                'currentPage' => $page,
                'totalPages'  => $totalPages,
                'total'       => $total,
                'status'      => $status,
                'currency'    => $currency,
                'summary'     => $summary,
                'pageTitle'   => 'درخواست‌های برداشت'
            ]);

        } catch (\Exception $e) {
    $this->logger->error('admin.withdrawals.index.failed', [
        'channel' => 'admin',
        'error' => $e->getMessage(),
        'exception' => get_class($e),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
    ]);

            \App\Services\Sentry\SentryExceptionHandler::captureException($e, null, [
                'operation' => 'withdrawal.admin.index',
            ]);
            $this->session->setFlash('error', 'خطا در دریافت لیست');
            redirect('/admin/dashboard');
        }
    }

    /**
     * صفحه بررسی درخواست برداشت
     */
    public function review(): void
    {
                $withdrawalId = $this->request->int('id');

        try {
            $withdrawal = $this->withdrawalQueryService->findById($withdrawalId);

            if (!$withdrawal) {
                $this->session->setFlash('error', 'درخواست یافت نشد');
                redirect('/admin/withdrawals');
            }

            // دریافت اطلاعات کاربر
            $user = $this->userService->findById($withdrawal->user_id);

            // دریافت اطلاعات کارت (برای IRT)
            $card = null;
            if ($withdrawal->card_id) {
                $card = $this->cardService->findById($withdrawal->card_id);
            }

            view('admin.withdrawals.review', [
                'withdrawal' => $withdrawal,
                'user' => $user,
                'card' => $card,
                'pageTitle' => 'بررسی درخواست برداشت'
            ]);

        } catch (\Exception $e) {
    $this->logger->error('admin.withdrawal.review.failed', [
        'channel' => 'admin',
        'withdrawal_id' => $withdrawalId,
        'error' => $e->getMessage(),
        'exception' => get_class($e),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
    ]);

            \App\Services\Sentry\SentryExceptionHandler::captureException($e, null, [
                'operation' => 'withdrawal.admin.review',
            ]);
            $this->session->setFlash('error', 'خطا در دریافت اطلاعات');
            redirect('/admin/withdrawals');
        }
    }

 
    /**
     * تأیید و پرداخت درخواست برداشت
     */
    public function process(): void
    {
        $withdrawalId = 0;
        $adminId = $this->requireAdminId();
        
        // ✅ دریافت metadata
        $requestId = get_request_id();
        $ipAddress = get_client_ip();

        $data = [
            'withdrawal_id' => $this->request->input('withdrawal_id'),
            'payment_reference' => $this->request->input('payment_reference'), // شماره پیگیری پرداخت
        ];

        // اعتبارسنجی
        $validator = $this->validatorFactory()->make($data, [
            'withdrawal_id' => 'required|numeric',
            'payment_reference' => 'required|min:5|max:100',
        ], [
            'withdrawal_id.required' => 'شناسه برداشت الزامی است',
            'payment_reference.required' => 'شماره پیگیری پرداخت الزامی است',
            'payment_reference.min' => 'شماره پیگیری باید حداقل 5 کاراکتر باشد',
        ]);

        if ($validator->fails()) {
            $this->response->json([
                'success' => false,
                'message' => (function($e) { foreach ($e as $m) { return is_array($m) ? (is_string($m[0] ?? null) ? $m[0] : 'خطا') : (is_string($m) ? $m : 'خطا'); } })($validator->errors())
            ]);
            return;
        }

        try {
            $withdrawalId = int_value($data['withdrawal_id']);
            $paymentRef = str_value($data['payment_reference']);

            $result = $this->withdrawalAdminService->adminApprove($withdrawalId, $adminId, $paymentRef);

            if (!empty($result['success'])) {
                // ✅ ثبت لاگ فعالیت در سطح کنترلر (لاگ مانیتورینگ)
                $this->logger->activity(
                    'withdrawal_approved',
                    "تأیید برداشت به شناسه {$withdrawalId} توسط ادمین {$adminId}",
                    $adminId,
                    [
                        'channel' => 'admin',
                        'request_id' => $requestId,
                        'admin_ip' => $ipAddress,
                        'withdrawal_id' => $withdrawalId,
                    ]
                );

                $this->response->json([
                    'success' => true,
                    'message' => $result['message'] ?? 'عملیات با موفقیت انجام شد'
                ]);
            } else {
                $this->response->json([
                    'success' => false,
                    'message' => $result['message'] ?? 'عملیات تأیید ناموفق بود'
                ]);
            }

        } catch (\Exception $e) {
            $this->logger->error('admin.withdrawal.approve.exception', [
                'channel' => 'admin',
                'withdrawal_id' => $withdrawalId,
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]);

            \App\Services\Sentry\SentryExceptionHandler::captureException($e, $adminId, [
                'operation' => 'withdrawal.admin.process',
            ]);
            $this->response->json([
                'success' => false,
                'message' => 'بروز خطای سیستمی در پردازش درخواست'
            ], 500);
        }
    }

    /**
     * رد درخواست برداشت - با Event Sourcing
     */
    public function reject(): void
    {
        $withdrawalId = 0;
        $adminId = $this->requireAdminId();
        
        // ✅ دریافت metadata
        $requestId = get_request_id();
        $ipAddress = get_client_ip();

        $data = [
            'withdrawal_id' => $this->request->input('withdrawal_id'),
            'rejection_reason' => $this->request->input('rejection_reason'),
        ];

        // اعتبارسنجی
        $validator = $this->validatorFactory()->make($data, [
            'withdrawal_id' => 'required|numeric',
            'rejection_reason' => 'required|min:10|max:500',
        ], [
            'rejection_reason.required' => 'دلیل رد الزامی است',
            'rejection_reason.min' => 'دلیل رد باید حداقل 10 کاراکتر باشد',
        ]);

        if ($validator->fails()) {
            $this->response->json([
                'success' => false,
                'message' => (function($e) { foreach ($e as $m) { return is_array($m) ? (is_string($m[0] ?? null) ? $m[0] : 'خطا') : (is_string($m) ? $m : 'خطا'); } })($validator->errors())
            ]);
            return;
        }

        try {
            $withdrawalId = int_value($data['withdrawal_id']);
            $result = $this->withdrawalAdminService->adminReject($withdrawalId, $adminId, trim($this->request->str('rejection_reason')));

            if (!empty($result['success'])) {
                // ✅ ثبت لاگ
                $this->logger->info('withdrawal.reject.completed', [
                    'channel' => 'withdrawal',
                    'request_id' => $requestId,
                    'withdrawal_id' => $withdrawalId,
                    'reason' => trim($this->request->str('rejection_reason')),
                ]);

                $this->response->json([
                    'success' => true,
                    'message' => $result['message'] ?? 'برداشت رد شد و موجودی به کاربر بازگردانده شد'
                ]);
            } else {
                $this->response->json([
                    'success' => false,
                    'message' => $result['message'] ?? 'خطا در رد برداشت'
                ]);
            }

        } catch (\Exception $e) {
            $this->logger->error('withdrawal.reject.failed', [
                'channel' => 'withdrawal',
                'request_id' => $requestId,
                'withdrawal_id' => $withdrawalId,
                'admin_id' => $adminId,
                'error' => $e->getMessage(),
                'exception' => get_class($e),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            \App\Services\Sentry\SentryExceptionHandler::captureException($e, $adminId, [
                'operation' => 'withdrawal.admin.reject',
            ]);
            $this->response->json([
                'success' => false,
                'message' => 'خطا در رد برداشت'
            ]);
        }
    }
}
