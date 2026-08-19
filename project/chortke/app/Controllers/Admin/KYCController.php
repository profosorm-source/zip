<?php

namespace App\Controllers\Admin;
use App\Services\User\UserService;
use App\Contracts\ValidatorFactoryInterface;

use App\Services\KYCService;
use App\Controllers\Admin\BaseAdminController;

class KYCController extends BaseAdminController
{
    protected UserService $userService;
    private KYCService $kycService;
    private ValidatorFactoryInterface $validatorFactory;

    public function __construct(
        UserService $userService,
        KYCService $kycService,
        ValidatorFactoryInterface $validatorFactory, ?\App\Contracts\LoggerInterface $logger = null) {
        parent::__construct(null, null, null, null, $logger);
        $this->userService = $userService;
        $this->kycService = $kycService;
        $this->validatorFactory = $validatorFactory;
    }

    public function index(): void
    {
        $status = $this->request->get('status', '');
        $search = $this->request->get('search', '');
        $page = max(1, $this->request->int('page', 1));
        $perPage = 20;
        $offset = ($page - 1) * $perPage;

        $filters = [];
        if ($status !== '') $filters['status'] = $status;
        if ($search !== '') $filters['search'] = $search;

        try {
            $kycs = $this->kycService->getAll($filters, $perPage, $offset, true);
        } catch (\Throwable $e) {
            $this->logger->error('admin.kyc.get_all.failed', [
                'status' => $status,
                'search' => $search,
                'error' => $e->getMessage()
            ]);
            $kycs = [];
        }

        $total = $this->kycService->count($filters);
        $totalPages = (int)\ceil($total / $perPage);

        // ✅ استفاده از یک کوئری جامع با GROUP BY به جای 4 کوئری جداگانه
        $stats = $this->kycService->getStatsByStatus();

        echo view('admin.kyc.index', [
            'kycs' => $kycs,
            'total' => $total,
            'currentPage' => $page,
            'totalPages' => $totalPages,
            'statusFilter' => $status,
            'search' => $search,
            'stats' => $stats,
        ]);
    }

    public function review(int $id): void
    {
        try {
            $kyc = $this->kycService->find($id, false);
        } catch (\Throwable $e) {
            $this->logger->error('admin.kyc.find.failed', [
                'id' => $id,
                'error' => $e->getMessage()
            ]);
            $this->session->setFlash('error', 'خطا در بازیابی اطلاعات احراز هویت');
            redirect('/admin/kyc');
        }

        if (!$kyc) {
            $this->session->setFlash('error', 'درخواست KYC یافت نشد');
            redirect('/admin/kyc');
        }

        // BUGFIX-CTRL-RAW-SQL-2026-06: lock query moved into KYCVerification model.
        // Concurrency Lock for Review (H-2)
        $adminId = $this->requireAdminId();
        if (!$this->kycService->lockForReview($id, $adminId, 30)) {
            $this->session->setFlash('error', 'این درخواست در حال بررسی توسط ادمین دیگری است');
            redirect('/admin/kyc');
        }

        // Refetch record with updated lock columns
        try {
            $kyc = $this->kycService->find($id, false);
        } catch (\Throwable $e) {
            $this->session->setFlash('error', 'خطا در بازیابی اطلاعات احراز هویت');
            redirect('/admin/kyc');
        }

        if ($kyc === null) {
            $this->session->setFlash('error', 'رکورد احراز هویت یافت نشد');
            redirect('/admin/kyc');
        }
        $user = $this->userService->find(int_value($kyc->user_id ?? 0));

        // Photoshop check (اگر فایل موجود باشد)
        $photoshopCheck = ['suspicious' => false, 'reasons' => []];
        if (!empty($kyc->verification_image) && $kyc->verification_image !== '[DELETED]') {
            // Whitelist approach
            $filename = preg_replace('/[^a-zA-Z0-9_\-\.]/', '', basename($kyc->verification_image));
            $uploadPath = base_path('storage/uploads/kyc/' . $filename);

            // Verify it's inside the intended directory (Issue #25 Fix)
            $realPath = realpath($uploadPath);
            $allowedDir = realpath(base_path('storage/uploads/kyc/'));

            if ($realPath !== false && $allowedDir !== false) {
                $allowedDirFormatted = rtrim($allowedDir, '/\\') . DIRECTORY_SEPARATOR;
                if ((str_starts_with($realPath, $allowedDirFormatted) || $realPath === rtrim($allowedDir, '/\\')) && file_exists($realPath) && is_file($realPath)) {
                    $photoshopCheck = $this->kycService->detectPhotoshop($realPath);
                }
            }
        }

        echo view('admin.kyc.review', [
            'kyc' => $kyc,
            'user' => $user,
            'photoshopCheck' => $photoshopCheck,
        ]);
    }

    // ✅ Verify: Form-based → Redirect + Flash
public function verify(int $id): void
{
    // لاگ اجرای متد
    $this->logger->info('admin.kyc.verify.hit', [
        'channel' => 'admin_kyc',
        'kyc_id' => $id,
        'admin_id' => $this->requireAdminId(),
    ]);

    $result = $this->kycService->verifyKYC($id, $this->requireAdminId());

    $this->response->json([
        'success' => (bool)($result['success'] ?? false),
        'message' => $result['message'] ?? 'نتیجه بررسی',
        'redirect' => url('/admin/kyc')
    ], ($result['success'] ?? false) ? 200 : 400);
}

    // ✅ Reject: Ajax JSON
    public function reject(int $id): void
    {
        $data = $this->request->json();
        if (!$data) {
            $data = $this->request->body();
        }
        if (!$data) {
            $this->response->json(['success' => false, 'message' => 'داده نامعتبر'], 400);
            return;
        }

        $validator = $this->validatorFactory->make($data, [
            'reason' => 'required|min:10'
        ]);

        if ($validator->fails()) {
            $this->response->json(['success' => false, 'errors' => $validator->errors()], 422);
            return;
        }

        $result = $this->kycService->rejectKYC(
            $id,
            $this->requireAdminId(),
            trim(str_value($data['reason'] ?? ''))
        );

        $this->response->json([
            'success' => (bool)($result['success'] ?? false),
            'message' => $result['message'] ?? 'خطا',
            'redirect' => url('/admin/kyc')
        ], ($result['success'] ?? false) ? 200 : 400);
    }

    public function deleteImage(int $id): void
    {
        $kyc = $this->kycService->find($id, false);
        if (!$kyc) {
            $this->response->json(['success' => false, 'message' => 'درخواست KYC یافت نشد'], 404);
            return;
        }

        $deletedFile = false;
        $image = (string)($kyc->verification_image ?? '');
        if ($image !== '' && $image !== '[DELETED]') {
            $filename = basename($image);
            $candidates = [
                base_path('storage/uploads/kyc/' . $filename),
                base_path('storage/uploads/kyc-verification/' . $filename),
                base_path('storage/uploads/' . ltrim($image, '/')),
            ];
            foreach ($candidates as $path) {
                $real = realpath($path);
                $uploads = realpath(base_path('storage/uploads'));
                if ($real && $uploads && str_starts_with($real, $uploads . DIRECTORY_SEPARATOR) && is_file($real)) {
                    @unlink($real);
                    $deletedFile = true;
                }
            }
        }

        $ok = $this->kycService->deleteVerificationImage($id);
        // BUGFIX-CTRL-RAW-SQL-2026-06: kyc_documents cleanup encapsulated in model.
        $this->kycService->deleteDocuments($id);

        $this->response->json([
            'success' => (bool)$ok,
            'message' => $ok ? 'تصویر KYC حذف شد' : 'خطا در حذف تصویر',
            'file_deleted' => $deletedFile,
        ], $ok ? 200 : 500);
    }

    public function markAsReviewing(int $id): void
    {
        $adminId = (int)$this->requireAdminId();
        $ok = $this->kycService->lockForReview($id, $adminId, 30);
        $this->response->json([
            'success' => (bool)$ok,
            'message' => $ok ? 'درخواست در وضعیت بررسی قرار گرفت' : 'این درخواست در حال بررسی توسط ادمین دیگری است',
            'redirect' => url('/admin/kyc/review/' . $id),
        ], $ok ? 200 : 409);
    }

}

