<?php

namespace App\Controllers\User;

use App\Contracts\WalletServiceInterface;
use App\Services\Withdrawal\WithdrawalUserService;
use App\Services\Withdrawal\WithdrawalQueryService;
use App\Services\BankCardService;
use App\Services\User\UserService;
use App\Services\AntiFraud\RiskDecisionService;
use App\Validators\Requests\CreateWithdrawalRequest;
use App\Services\Settings\AppSettings;
use Core\Logger;
use App\Controllers\User\BaseUserController;

class WithdrawalController extends BaseUserController
{
    private BankCardService $bankCardService;
    private WalletServiceInterface $walletService;
    private WithdrawalUserService $withdrawalUserService;
    private WithdrawalQueryService $withdrawalQueryService;
    private AppSettings $appSettings;

    public function __construct(
        BankCardService $bankCardService,
        WalletServiceInterface $walletService,
        WithdrawalUserService $withdrawalUserService,
        WithdrawalQueryService $withdrawalQueryService,
        UserService $userService,
        Logger $logger,
        AppSettings $appSettings
    ) {
        parent::__construct(null, null, null, null, $logger);
        $this->bankCardService = $bankCardService;
        $this->walletService = $walletService;
        $this->withdrawalUserService = $withdrawalUserService;
        $this->withdrawalQueryService = $withdrawalQueryService;
        $this->userService = $userService;
        $this->logger = $logger;
        $this->appSettings = $appSettings;
    }

    /**
     * فرم برداشت وجه
     */
    public function create(): void
    {
        $userId = (int)$this->userId();

        try {
            $user = $this->userService->find($userId);

            if (!$user || !$this->userService->isKycVerified($userId)) {
                $this->session->setFlash('error', 'برای برداشت وجه ابتدا باید احراز هویت کنید');
                $this->response->redirect(url('kyc'));
                return;
            }

            if ($this->withdrawalQueryService->hasPendingWithdrawal($userId)) {
                $this->session->setFlash('error', 'شما یک درخواست برداشت در انتظار دارید');
                $this->response->redirect(url('wallet'));
                return;
            }

            $summary = $this->walletService->getWalletSummary($userId);
            if (!$summary->can_withdraw_today) {
                $this->session->setFlash('error', 'شما امروز یکبار برداشت کرده‌اید');
                $this->response->redirect(url('wallet'));
                return;
            }

            $siteCurrency = config('site_currency', 'irt');
            $cards = [];
            if ($siteCurrency === 'irt') {
                $cards = $this->bankCardService->getUserCards($userId, 'verified');
                if (empty($cards)) {
                    $this->session->setFlash('error', 'ابتدا باید کارت بانکی خود را ثبت و تأیید کنید');
                    $this->response->redirect(url('bank-cards/create'));
                    return;
                }
            }

            $minWithdrawal = $siteCurrency === 'usdt'
                ? str_value(config('min_withdrawal_usdt', '10'))
                : str_value(config('min_withdrawal_irt', '50000'));

            $this->view('user/withdrawal/create', [
                'summary' => $summary,
                'cards' => $cards,
                'siteCurrency' => $siteCurrency,
                'minWithdrawal' => $minWithdrawal,
                'pageTitle' => 'برداشت وجه'
            ]);

        } catch (\Throwable $e) {
            $this->logger->error('withdrawal.create.failed', [
                'channel' => 'withdrawal',
                'user_id' => $userId,
                'error' => $e->getMessage(),
                'exception' => get_class($e),
            ]);

            \App\Services\Sentry\SentryExceptionHandler::captureException($e, $userId, [
                'operation' => 'withdrawal.user.create',
            ]);
            $this->session->setFlash('error', 'خطا در بارگذاری صفحه برداشت');
            $this->response->redirect(url('wallet'));
        }
    }

    /**
     * ثبت درخواست برداشت - با Request Validation + Idempotency
     */
    public function store(): void
    {
        $userId = (int) user_id();

        try {
            $request = new CreateWithdrawalRequest($this->request->all());
            $request->setAppSettings($this->appSettings);
            
            if (!$request->validate()) {
                $this->response->json([
                    'success' => false,
                    'message' => 'خطای اعتبارسنجی',
                    'errors'  => $request->errors()
                ], 422);
                return;
            }

            $validated = $request->validated();
            $idempotencyKey = str_value($validated['idempotency_key'] ?? '');
            $requestIdHeader = $this->request->header('X-Request-ID');
            $requestId = is_string($requestIdHeader) && trim($requestIdHeader) !== ''
                ? trim($requestIdHeader)
                : 'withdrawal-' . substr(hash('sha256', $idempotencyKey), 0, 32);
            $payload = array_merge($validated, [
                // Retries carrying the same idempotency key must produce the same
                // payload fingerprint; a random server request ID caused false
                // idempotency collisions after the first committed withdrawal.
                'request_id'   => $requestId,
                'ip'           => get_client_ip(),
                'user_agent'   => get_user_agent(),
                'fingerprint'  => generate_device_fingerprint(),
            ]);

            $result = $this->withdrawalUserService->requestFromUser($userId, $payload);

            $this->response->json([
                'success' => (bool)($result['success'] ?? false),
                'message' => $result['message'] ?? 'خطا',
                'data'    => $result['data'] ?? null,
            ], !empty($result['success']) ? 200 : 422);

        } catch (\Core\Exceptions\HttpResponseException $e) {
            throw $e;
        } catch (\App\Exceptions\BusinessException $e) {
            \App\Services\Sentry\SentryExceptionHandler::captureException($e, $userId, [
                'operation' => 'withdrawal.user.store.business_exception',
            ]);
            $this->response->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 422);
        } catch (\RuntimeException $e) {
            \App\Services\Sentry\SentryExceptionHandler::captureException($e, $userId, [
                'operation' => 'withdrawal.user.store.runtime_exception',
            ]);
            // Saga failures (insufficient balance, frozen wallet, etc.)
            $this->logger->warning('withdrawal.business_rule.failed', [
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);
            $this->response->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 422);
        } catch (\Throwable $e) {
            $this->logger->error('withdrawal.request.controller.failed', [
                'channel'   => 'withdrawal',
                'user_id'   => $userId,
                'error'     => $e->getMessage(),
                'exception' => get_class($e),
            ]);

            \App\Services\Sentry\SentryExceptionHandler::captureException($e, $userId, [
                'operation' => 'withdrawal.user.store',
            ]);
            $this->response->json([
                'success' => false,
                'message' => 'خطای سرور'
            ], 500);
        }
    }

    public function cancel(): void
    {
        $userId = (int) user_id();
        $withdrawalId = (int)($this->request->param('id') ?? 0);

        if ($withdrawalId <= 0) {
            $this->response->json([
                'success' => false,
                'message' => 'شناسه برداشت نامعتبر است',
            ], 422);
            return;
        }

        try {
            $result = $this->withdrawalUserService->cancelPendingWithdrawal($userId, $withdrawalId);

            $this->response->json([
                'success' => (bool)($result['success'] ?? false),
                'message' => $result['message'] ?? 'خطا در لغو برداشت',
                'data' => ['withdrawal_id' => $result['withdrawal_id'] ?? $withdrawalId],
            ], !empty($result['success']) ? 200 : 422);
        } catch (\Core\Exceptions\HttpResponseException $e) {
            throw $e;
        } catch (\Throwable $e) {
            $this->logger->error('withdrawal.cancel.controller.failed', [
                'channel' => 'withdrawal',
                'user_id' => $userId,
                'withdrawal_id' => $withdrawalId,
                'error' => $e->getMessage(),
                'exception' => get_class($e),
            ]);

            \App\Services\Sentry\SentryExceptionHandler::captureException($e, $userId, [
                'operation' => 'withdrawal.user.cancel',
            ]);
            $this->response->json([
                'success' => false,
                'message' => 'خطای سرور در لغو برداشت'
            ], 500);
        }
    }

    public function index(): void
    {
        $userId = (int)$this->userId();

        try {
            $withdrawals = $this->withdrawalQueryService->getUserWithdrawals($userId);

            $this->view('user/withdrawal/index', [
                'withdrawals' => $withdrawals,
                'pageTitle' => 'درخواست‌های برداشت'
            ]);

        } catch (\Throwable $e) {
            $this->logger->error('withdrawal.index.failed', [
                'channel' => 'withdrawal',
                'user_id' => $userId,
                'error' => $e->getMessage(),
                'exception' => get_class($e),
            ]);

            \App\Services\Sentry\SentryExceptionHandler::captureException($e, $userId, [
                'operation' => 'withdrawal.user.index',
            ]);
            $this->session->setFlash('error', 'خطا در دریافت لیست برداشت‌ها');
            $this->response->redirect(url('wallet'));
        }
    }

    public function limitsInfo(): void
    {
        $userId   = (int)user_id();
        $currency = strtoupper(str_value($this->request->get('currency', 'IRT')));
        
        if (!in_array($currency, ['IRT', 'USDT'], true)) {
            $currency = 'IRT';
        }

        $info = $this->withdrawalQueryService->getLimitsForUser($userId, $currency);

        $this->response->json([
            'success' => true,
            'limits'  => $info,
        ]);
    }

    // متدهای challenge (OTP) فعلاً بدون تغییر نگه داشته می‌شوند
    public function requestWithdrawalChallenge(): void { /* ... */ }
    public function verifyWithdrawalChallenge(): void { /* ... */ }
}
