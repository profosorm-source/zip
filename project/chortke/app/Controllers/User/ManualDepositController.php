<?php

namespace App\Controllers\User;

use App\Services\ManualDepositService;
use App\Services\BankCardService;
use App\Services\UploadService;
use App\Policies\RateLimitPolicy;
use App\Controllers\User\BaseUserController;

class ManualDepositController extends BaseUserController
{
    private ManualDepositService $depositService;
    private BankCardService $cardService;
    private UploadService $uploadService;
    private ?\App\Models\ManualDeposit $depositModel;

    public function __construct(
        ManualDepositService $depositService,
        BankCardService $cardService,
        \App\Services\UploadService $uploadService,
        ?\App\Models\ManualDeposit $depositModel = null,
        ?\App\Contracts\LoggerInterface $logger = null) {
        parent::__construct(null, null, null, null, $logger);
        $this->depositService = $depositService;
        $this->cardService = $cardService;
        $this->uploadService = $uploadService;
        $this->depositModel = $depositModel;
    }

    /**
     * فرم واریز دستی
     */
    public function create(): void
    {
        $userId = (int)$this->userId();

        try {
            // دریافت کارت‌های تأییدشده
            $cards = $this->cardService->getUserCards($userId, 'verified');

            if (empty($cards)) {
                $this->session->setFlash('error', 'ابتدا باید کارت بانکی خود را ثبت و تأیید کنید');
                redirect('/bank-cards/create');
            }

            // دریافت اطلاعات بانکی سایت از system_settings
            $siteCardNumber    = setting('site_irt_card_number');
            $siteAccountNumber = setting('site_irt_account_number');
            $siteSheba         = setting('site_irt_sheba');
            $siteBankName      = setting('site_irt_bank_name');

            if (!$siteCardNumber) {
                $this->session->setFlash('error', 'اطلاعات بانکی سایت تنظیم نشده است. لطفاً با پشتیبانی تماس بگیرید');
                redirect('/wallet');
            }

            $this->view('user/manual-deposit/create', [
                'cards'             => $cards,
                'siteCardNumber'    => $siteCardNumber,
                'siteAccountNumber' => $siteAccountNumber,
                'siteSheba'         => $siteSheba,
                'siteBankName'      => $siteBankName,
                'pageTitle'         => 'واریز دستی'
            ]);

        } catch (\Core\Exceptions\HttpResponseException $e) {
            throw $e;
        } catch (\Exception $e) {
    $this->logger->error('manual_deposit.create.failed', [
        'channel' => 'manual_deposit',
        'user_id' => $userId,
        'error' => $e->getMessage(),
        'exception' => get_class($e),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
    ]);

            $this->session->setFlash('error', 'خطا در بارگذاری صفحه');
            redirect('/wallet');
        }
    }

    /**
     * ثبت درخواست واریز دستی
     */
    public function store(): void
    {
        $userId = (int)$this->userId();
        RateLimitPolicy::enforce('manual_deposit', (int)user_id(), is_ajax());

        $requestId         = get_request_id();
        $ipAddress         = get_client_ip();
        $deviceFingerprint = generate_device_fingerprint();

        $data = [
            'bank_card_id'   => $this->request->post('bank_card_id') ?? $this->request->post('card_id'),
            'amount'         => $this->request->post('amount'),
            'tracking_code'  => $this->request->post('tracking_code'),
            'user_description' => $this->request->post('description') ?? $this->request->post('user_description'),
        ];

        $idempotencyKey = $this->request->input('idempotency_key');

        $validator = $this->validatorFactory()->make($data, [
            'bank_card_id'   => 'required|numeric',
            'amount'         => 'required|numeric|min:10000',
            'tracking_code'  => 'required|min:5|max:50',
        ], [
            'bank_card_id.required'   => 'انتخاب کارت الزامی است',
            'amount.required'         => 'مبلغ الزامی است',
            'amount.numeric'          => 'مبلغ باید عددی باشد',
            'amount.min'              => 'حداقل مبلغ واریز 10,000 تومان است',
            'tracking_code.required'  => 'شماره پیگیری الزامی است',
        ]);

        if ($validator->fails()) {
            $errors = $validator->errors();
            $firstError = 'خطای اعتبارسنجی';
            foreach ($errors as $messages) {
                if (is_array($messages) && !empty($messages)) {
                    $firstError = $messages[0];
                }
                break;
            }
            $this->session->setFlash('error', $firstError);
            $this->session->setFlash('old', $data);
            redirect('/wallet/deposit/manual');
        }

        try {
            // بررسی کارت با استفاده از Service
            $card = $this->cardService->findVerifiedCardForUser($userId, int_value($data['bank_card_id'] ?? 0));
            if (!$card) {
                throw new \RuntimeException('کارت نامعتبر است');
            }

            $receiptPath = null;
            $receiptFile = $this->request->file('receipt_image');

            if ($receiptFile && $receiptFile['error'] === UPLOAD_ERR_OK) {
                $uploadResult = $this->uploadService->upload(
                    $receiptFile,
                    'receipts',
                    ['image/jpeg', 'image/png'],
                    2 * 1024 * 1024
                );

                if ($uploadResult['success']) {
                    $receiptPath = $uploadResult['path'];
                } else {
                    throw new \RuntimeException(is_string($uploadResult['message'] ?? null) ? $uploadResult['message'] : 'خطا در آپلود رسید');
                }
            }

            // تولید کلید قطعی در صورت عدم ارسال
            $effectiveIdempotencyKey = $idempotencyKey ?: null;

            // پاس دادن کلید مستقیم به لایه خدمات (Service Layer) جهت اعمال متمرکز Idempotency
            $result = $this->depositService->create($userId, [
                'bank_card_id' => int_value($data['bank_card_id'] ?? 0),
                'amount' => str_value($data['amount'] ?? ''),
                'tracking_code' => str_value($data['tracking_code'] ?? ''),
                'user_description' => str_value($data['user_description'] ?? ''),
                'idempotency_key' => $effectiveIdempotencyKey,
            ], $receiptPath);

            if (!($result['success'] ?? false)) {
                throw new \RuntimeException(is_string($result['message'] ?? null) ? $result['message'] : 'خطا در ثبت درخواست');
            }

            $this->logger->activity('manual_deposit_requested', "درخواست واریز دستی " . str_value($data['amount'] ?? '') . " تومان", $userId, [
                    'deposit_id'    => $result['deposit_id'] ?? null,
                    'tracking_code' => $data['tracking_code'],
                    'request_id'    => $requestId,
                    'ip'            => $ipAddress,
                ]);

            $this->session->setFlash('success', 'درخواست واریز شما ثبت شد و در انتظار بررسی است');
            redirect('/wallet');
        } catch (\Core\Exceptions\HttpResponseException $e) {
            throw $e;
        } catch (\RuntimeException $e) {
    // RuntimeException (و زیرکلاس‌های Core\Exceptions\AppException) در همین متد
    // و سرویس‌های فراخوانی‌شده با پیام فارسی کاربرپسند ساخته می‌شوند؛ سایر
    // خطاهای غیرمنتظره (مثلاً TypeError) به GlobalExceptionMiddleware می‌روند.
    $this->logger->error('manual_deposit.store.failed', [
        'channel' => 'manual_deposit',
        'request_id' => $requestId,
        'user_id' => $userId,
        'amount' => $data['amount'] ?? 0,
        'tracking_code' => $data['tracking_code'] ?? null,
        'ip' => $ipAddress,
        'error' => $e->getMessage(),
        'exception' => get_class($e),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
    ]);

            $this->session->setFlash('error', $e->getMessage());
            $this->session->setFlash('old', $data);
            redirect('/wallet/deposit/manual');
        }
    }

    /**
     * لیست درخواست‌های واریز دستی کاربر
     */
    public function index(): void
    {
        $userId = (int)$this->userId();

        try {
            // دریافت Model از طریق Container برای نمایش لیست
            $deposits = ($this->depositModel ?? throw new \RuntimeException("Deposit model not available"))->where('user_id', $userId)->orderBy('created_at', 'DESC')->get();

            $this->view('user/manual-deposit/index', [
                'deposits'  => $deposits,
                'pageTitle' => 'درخواست‌های واریز دستی'
            ]);

        } catch (\Exception $e) {
    $this->logger->error('manual_deposit.index.failed', [
        'channel' => 'manual_deposit',
        'user_id' => $userId,
        'error' => $e->getMessage(),
        'exception' => get_class($e),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
    ]);

            $this->session->setFlash('error', 'خطا در دریافت لیست');
            redirect('/wallet');
        }
    }
}
