<?php

namespace App\Controllers\User;

use App\Models\KYCVerification;
use App\Services\KYCService;
use App\Services\UploadService;
use App\Policies\RateLimitPolicy;
use App\Controllers\User\BaseUserController;

class KYCController extends BaseUserController
{
    private KYCService      $kycService;
    private KYCVerification $kycModel;
    private RateLimitPolicy $rateLimitPolicy;

    public function __construct(
        KYCVerification $kycModel,
        KYCService $kycService,
        RateLimitPolicy $rateLimitPolicy,
        ?\App\Contracts\LoggerInterface $logger = null
    ) {
        parent::__construct(null, null, null, null, $logger);
        $this->kycModel   = $kycModel;
        $this->kycService = $kycService;
        $this->rateLimitPolicy = $rateLimitPolicy;
    }

    /**
     * صفحه اصلی احراز هویت (داشبورد + وضعیت)
     */
    public function index(): void
    {
        $userId = (int)$this->userId();
        $kyc = null;
        $canSubmit = ['can' => true, 'reason' => ''];

        try {
            $kyc = $this->kycModel->findByUserId($userId);
        } catch (\Throwable $e) {
            $this->logger->warning('kyc.index.lookup_failed', [
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);
        }

        try {
            $canSubmit = $this->kycService->canSubmitKYC($userId);
        } catch (\Throwable $e) {
            $this->logger->warning('kyc.index.can_submit_failed', [
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);
            $canSubmit = [
                'can' => false,
                'reason' => 'در حال حاضر امکان بررسی وضعیت احراز هویت وجود ندارد. لطفاً کمی بعد دوباره تلاش کنید.',
            ];
        }

        $this->view('user/kyc/index', [
            'title'      => 'احراز هویت',
            'kyc'        => $kyc,
            'canSubmit'  => $canSubmit,
            'appName'    => config('app.name', 'سایت'),
            'todayJalali'=> to_jalali(date('Y-m-d')),
        ]);
    }

    /**
     * صفحه آپلود مدارک
     */
    public function upload(): void
    {
        $userId = (int)$this->userId();
        $canSubmit = ['can' => true, 'reason' => ''];

        try {
            $canSubmit = $this->kycService->canSubmitKYC($userId);
        } catch (\Throwable $e) {
            $this->logger->warning('kyc.upload.can_submit_failed', [
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);
            $canSubmit = [
                'can' => false,
                'reason' => 'در حال حاضر امکان ثبت درخواست احراز هویت وجود ندارد. لطفاً کمی بعد دوباره تلاش کنید.',
            ];
        }

        $this->view('user/kyc/upload', [
            'title'      => 'آپلود مدارک احراز هویت',
            'canSubmit'  => (bool)($canSubmit['can'] ?? false),
            'error'      => $canSubmit['reason'] ?? null,
            'errors'     => session()->getFlash('errors') ?? [],
            'appName'    => config('app.name', 'سایت'),
            'todayJalali'=> to_jalali(date('Y-m-d')),
        ]);
    }

    /**
     * ثبت درخواست احراز هویت
     */
    public function submit(): mixed
    {
        $userId = (int)$this->userId();
        $deviceFingerprint = generate_device_fingerprint();

        if (!$this->rateLimitPolicy->check('kyc_submit', $userId)) {
            $this->rateLimitPolicy->tooManyResponse('kyc_submit', $userId, is_ajax());
        }
        if (!$this->rateLimitPolicy->check('kyc_submit', get_client_ip())) {
            $this->rateLimitPolicy->tooManyResponse('kyc_submit', get_client_ip(), is_ajax());
        }
        if (!$this->rateLimitPolicy->check('kyc_submit', $deviceFingerprint)) {
            $this->rateLimitPolicy->tooManyResponse('kyc_submit', $deviceFingerprint, is_ajax());
        }

        $data = $this->request->all();

        $validator = $this->validatorFactory()->make($data, [
            'national_code' => 'required|digits:10',
            'birth_date'    => 'required',
        ]);

        if ($validator->fails()) {
            session()->setFlash('errors', $validator->errors());
            return redirect('/kyc/upload');
        }

        if (empty($_FILES['verification_image'])) {
            session()->setFlash('errors', [
                'verification_image' => ['تصویر احراز هویت الزامی است'],
            ]);
            return redirect('/kyc/upload');
        }

        if (($_FILES['verification_image']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            $uploadError = (int)($_FILES['verification_image']['error'] ?? UPLOAD_ERR_NO_FILE);
            $message = match ($uploadError) {
                UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'حجم فایل انتخاب‌شده بیش از حد مجاز است. لطفاً تصویری کمتر از ۵ مگابایت بارگذاری کنید.',
                UPLOAD_ERR_NO_FILE => 'تصویر احراز هویت الزامی است',
                default => 'خطا در بارگذاری فایل. لطفاً دوباره تلاش کنید.',
            };
            session()->setFlash('errors', [
                'verification_image' => [$message],
            ]);
            return redirect('/kyc/upload');
        }

        try {
            $result = $this->kycService->submitKYC(
                $userId,
                [
                    'national_code' => trim(str_value($data['national_code'] ?? '')),
                    'birth_date'    => $data['birth_date'],
                ],
                ['verification_image' => $_FILES['verification_image']]
            );
        } catch (\Core\Exceptions\BusinessException $e) {
            session()->setFlash('errors', [
                'verification_image' => [$e->getMessage()],
            ]);
            return redirect('/kyc/upload');
        }

        if ($result['success']) {
            $currentUser = $this->userService->find($userId);
            $userName = $currentUser->full_name ?? 'کاربر';

            try {
                notify_admins(
                    'kyc_submitted',
                    'درخواست احراز هویت جدید',
                    'کاربر ' . $userName . ' درخواست احراز هویت ثبت کرده است',
                    url('/admin/kyc/review/' . $result['kyc_id']),
                    ['user_id' => $userId, 'kyc_id' => $result['kyc_id']]
                );
            } catch (\Throwable $e) {
                $this->logger->warning('kyc.notify_admins.failed', [
                    'user_id' => $userId,
                    'kyc_id' => $result['kyc_id'] ?? null,
                    'error' => $e->getMessage(),
                ]);
            }

            session()->setFlash('success', $result['message']);
            return redirect('/kyc');
        }

        session()->setFlash('errors', [
            'verification_image' => [$result['message'] ?? 'خطا در ثبت احراز هویت'],
        ]);
        return redirect('/kyc/upload');
    }

    /**
     * وضعیت KYC (AJAX یا redirect به index)
     */
    public function status(): mixed
    {
        $userId = (int)$this->userId();
        $kyc    = $this->kycModel->findByUserId($userId);

        if (!$kyc) {
            if (is_ajax()) {
                $this->response->json(['success' => false, 'message' => 'درخواستی یافت نشد'], 404);
                return null;
            }
            return redirect(url('/kyc'));
        }

        $statusLabels = [
            'pending'      => 'در انتظار بررسی',
            'under_review' => 'در حال بررسی',
            'verified'     => 'تأیید شده',
            'rejected'     => 'رد شده',
            'expired'      => 'منقضی شده',
        ];

        if (is_ajax()) {
            $this->response->json([
                'success' => true,
                'kyc'     => [
                    'status'           => $kyc->status,
                    'status_label'     => $statusLabels[$kyc->status] ?? $kyc->status,
                    'submitted_at'     => $kyc->submitted_at,
                    'verified_at'      => $kyc->verified_at,
                    'rejection_reason' => $kyc->rejection_reason,
                ],
            ]);
            return null;
        }

        // درخواست معمولی → به index برگردان (اطلاعات در index نمایش داده می‌شود)
        return redirect(url('/kyc'));
    }
    public function show(int $id): void
    {
        $kyc = $this->kycModel->find($id);

        if (!$kyc) {
            $this->response->status(404)->json(['success' => false, 'message' => 'یافت نشد']);
            return;
        }

        // K-08 ⭐ Insecure Direct Object Reference (IDOR) Protection
        if ((int)$kyc->user_id !== (int)$this->userId() && !$this->policyService->isAdminById((int)$this->userId())) {
            $this->logger->warning('kyc.idor_attempt_blocked', ['kyc_id' => $id, 'user_id' => (int)$this->userId(), 'ip' => $this->request->ip()]);
            $this->response->json(['success' => false, 'message' => 'دسترسی غیرمجاز (IDOR)'], 403);
            return;
        }

        $this->response->json(['success' => true, 'kyc' => $kyc]);
    }
}
