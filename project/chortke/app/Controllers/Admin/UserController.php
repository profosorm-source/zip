<?php

namespace App\Controllers\Admin;

use App\Services\User\UserService;
use App\Contracts\ValidatorFactoryInterface;
use App\Controllers\Admin\BaseAdminController;

class UserController extends BaseAdminController
{
    private UserService $userService;
    private \App\Services\User\AccountDeletionService $deletionService;
    private ValidatorFactoryInterface $validatorFactory;

    /**
     * ROOT CAUSE FIX helper (standard)
     * Ensures we always have an object, never array from DB layers.
     */
    private function toObject(mixed $data): \stdClass
    {
        if (is_array($data)) {
            return (object)$data;
        }
        if (is_object($data)) {
            /** @var \stdClass $data */
            return $data;
        }
        return (object)[];
    }

    public function __construct(
        UserService $userService,
        \App\Services\User\AccountDeletionService $deletionService,
        ValidatorFactoryInterface $validatorFactory,
        ?\App\Contracts\LoggerInterface $logger = null
    ) {
        parent::__construct(null, null, null, null, $logger);
        $this->userService = $userService;
        $this->deletionService = $deletionService;
        $this->validatorFactory = $validatorFactory;
    }

    /**
     * نمایش لیست کاربران
     */
    public function index(): void
    {
        $search = $this->request->str('search');
        $role = $this->request->str('role');
        $status = $this->request->str('status');
        $page = max(1, $this->request->int('page', 1));
        $perPage = 20;
        $offset = ($page - 1) * $perPage;

        $filters = array_filter([
            'search' => $search,
            'role'   => $role,
            'status' => $status,
        ], fn($v) => $v !== '');

        $users      = $this->userService->searchWithFilters($filters, $perPage, $offset);
        $total      = $this->userService->countWithFilters($filters);
        $totalPages = (int)ceil($total / $perPage);
        $userStats  = $this->userService->getAdminStats();

        $this->view('admin.users.index', [
            'users' => $users,
            'total' => $total,
            'currentPage' => $page,
            'totalPages' => $totalPages,
            'search' => $search,
            'roleFilter' => $role,
            'statusFilter' => $status,
            'userStats' => $userStats
        ]);
    }

    public function create(): void
    {
        $this->view('admin.users.create');
    }

    public function store(): void
    {
        $data = $this->request->body() ?? [];
        $validator = $this->validatorFactory->make($data, [
            'full_name' => 'required|min:3|max:100',
            'email'     => 'required|email',
            'password'  => 'required|min:8',
            'role'      => 'required|in:user,admin,support,super_admin',
            'status'    => 'required|in:active,inactive,suspended,banned',
        ]);
        if ($validator->fails()) {
            $this->response->json(['success' => false, 'errors' => $validator->errors()], 422);
            return;
        }

        $validated = $this->toObject($validator->data());

        $currentAdmin = $this->toObject($this->userService->find((int)$this->userId()));
        if (!isset($currentAdmin->id)) {
            $this->response->json(['success' => false, 'message' => 'دسترسی غیرمجاز'], 403);
            return;
        }

        $hierarchy = ['user' => 0, 'admin' => 1, 'super_admin' => 2];
        $adminRoleLevel = $hierarchy[$currentAdmin->role ?? 'user'] ?? 0;
        $newRole = $validated->role ?? 'user';
        $newRoleLevel = $hierarchy[$newRole] ?? 0;

        if ($newRoleLevel > $adminRoleLevel) {
            $this->response->json([
                'success' => false,
                'message' => 'شما نمی‌توانید کاربر با سطحی بالاتر از خود ایجاد کنید.'
            ], 403);
            return;
        }

        $existingUser = $this->userService->findByEmail($validated->email);
        if ($existingUser) {
            $this->response->json([
                'success' => false,
                'errors' => ['email' => ['این ایمیل قبلاً ثبت شده است']]
            ], 422);
            return;
        }

        if (!empty($validated->mobile)) {
            $existingMobile = $this->userService->findByMobile($validated->mobile);
            if ($existingMobile) {
                $this->response->json([
                    'success' => false,
                    'errors' => ['mobile' => ['این شماره موبایل قبلاً ثبت شده است']]
                ], 422);
                return;
            }
        }

        $userId = $this->userService->register([
            'full_name' => $validated->full_name,
            'email' => $validated->email,
            'password' => $validated->password,
            'role' => $validated->role,
            'status' => $validated->status,
            'email_verified_at' => date('Y-m-d H:i:s'),
        ]);

        if ($userId) {
            $this->response->json([
                'success' => true,
                'message' => 'کاربر با موفقیت ایجاد شد',
                'redirect' => url('/admin/users')
            ]);
        } else {
            $this->response->json([
                'success' => false,
                'message' => 'خطا در ایجاد کاربر'
            ], 500);
        }
    }

    public function edit(int $id): void
    {
        $user = $this->toObject($this->userService->find($id));

        if (!isset($user->id)) {
            $this->response->redirect(url('admin/users'));
            return;
        }

        $this->view('admin.users.edit', ['user' => $user]);
    }

    /**
     * به‌روزرسانی کاربر
     * کاملاً ایمن: تمام دسترسی‌ها بعد از toObject + isset guard
     */
    public function update(int $id): void
    {
        $user = $this->toObject($this->userService->find($id));
        if (!isset($user->id)) {
            $this->response->json([
                'success' => false,
                'message' => 'کاربر یافت نشد'
            ], 404);
            return;
        }

        $data = $this->request->body() ?? [];
        $validatedData = $this->validateRequest(\App\Validators\Requests\UserUpdateRequest::class, $data);
        if (empty($validatedData)) {
            return;
        }
        $validated = $this->toObject($validatedData);

        $currentAdmin = $this->toObject($this->userService->find((int)$this->userId()));
        if (!isset($currentAdmin->id)) {
            $this->response->json(['success' => false, 'message' => 'دسترسی غیرمجاز'], 403);
            return;
        }

        $hierarchy = ['user' => 0, 'admin' => 1, 'super_admin' => 2];
        $adminRoleLevel = $hierarchy[$currentAdmin->role ?? 'user'] ?? 0;

        $userRole = $user->role ?? 'user';
        $targetUserRoleLevel = $hierarchy[$userRole] ?? 0;

        if ($adminRoleLevel < 2 && $targetUserRoleLevel >= 1 && $user->id !== $currentAdmin->id) {
            $this->response->json([
                'success' => false,
                'message' => 'شما مجاز به ویرایش سایر مدیران نیستید.'
            ], 403);
            return;
        }

        $newRole = $validated->role ?? $userRole;
        $newRoleLevel = $hierarchy[$newRole] ?? 0;
        if ($newRoleLevel > $adminRoleLevel) {
            $this->response->json([
                'success' => false,
                'message' => 'شما نمی‌توانید سطحی بالاتر از سطح خود تخصیص دهید.'
            ], 403);
            return;
        }

        if (isset($validated->status)) {
            $userStatus = $user->status ?? 'active';
            if (!$this->validateStatusTransition((string)$userStatus, $validated->status)) {
                $this->response->json([
                    'success' => false,
                    'message' => 'تغییر وضعیت غیرمجاز است.'
                ], 400);
                return;
            }
        }

        $result = $this->userService->updateUser($id, $validatedData);

        if (!empty($result['success'])) {
            \App\Middleware\PermissionMiddleware::clearCache($id);
            $this->response->json([
                'success' => true,
                'message' => $result['message'] ?? 'کاربر با موفقیت به‌روزرسانی شد',
                'redirect' => url('/admin/users')
            ]);
        } else {
            $statusCode = !empty($result['errors']) ? 422 : 500;
            $this->response->json([
                'success' => false,
                'message' => $result['message'] ?? 'خطا در به‌روزرسانی کاربر',
                'errors' => $result['errors'] ?? []
            ], $statusCode);
        }
    }

    /**
     * حذف نرم (Soft Delete)
     */
    public function delete(int $id): void
    {
        $user = $this->toObject($this->userService->find($id));
        if (!isset($user->id)) {
            $this->response->json(['success' => false, 'message' => 'کاربر یافت نشد'], 404);
            return;
        }

        if ($id === (int)$this->userId()) {
            $this->jsonError('شما نمی‌توانید خودتان را حذف کنید', [], 403);
            return;
        }

        $currentAdmin = $this->toObject($this->userService->find((int)$this->userId()));
        $adminRoleLevel = ['user' => 0, 'admin' => 1, 'super_admin' => 2][$currentAdmin->role ?? 'user'] ?? 0;
        $uRole = $user->role ?? 'user';
        $targetUserRoleLevel = ['user' => 0, 'admin' => 1, 'super_admin' => 2][$uRole] ?? 0;
        if ($adminRoleLevel < 2 && $targetUserRoleLevel >= 1) {
            $this->response->json(['success' => false, 'message' => 'شما مجاز به حذف سایر مدیران نیستید.'], 403);
            return;
        }

        $result = $this->deletionService->deleteUserAccount($id, 'Deleted by Admin');

        if ($result) {
            $this->response->json(['success' => true, 'message' => 'کاربر با موفقیت حذف شد']);
        } else {
            $this->response->json(['success' => false, 'message' => 'خطا در حذف کاربر یا کاربر دارای موجودی است'], 500);
        }
    }

    /**
     * بن/فعال‌سازی کاربر
     */
    public function ban(int $id): void
    {
        $currentAdminId = (int)$this->userId();
        if ($id === $currentAdminId) {
            $this->jsonError('شما نمی‌توانید خودتان را مسدود کنید', [], 403);
            return;
        }

        $user = $this->toObject($this->userService->find($id));
        if (!isset($user->id)) {
            $this->response->json(['success' => false, 'message' => 'کاربر یافت نشد'], 404);
            return;
        }

        if (!empty($user->deleted_at)) {
            $this->response->json(['success' => false, 'message' => 'این کاربر حذف شده است'], 400);
            return;
        }

        $currentAdmin = $this->toObject($this->userService->find($currentAdminId));
        $adminRoleLevel = ['user' => 0, 'admin' => 1, 'super_admin' => 2][$currentAdmin->role ?? 'user'] ?? 0;
        $uRole = $user->role ?? 'user';
        $targetUserRoleLevel = ['user' => 0, 'admin' => 1, 'super_admin' => 2][$uRole] ?? 0;
        if ($adminRoleLevel < 2 && $targetUserRoleLevel >= 1) {
            $this->response->json(['success' => false, 'message' => 'شما مجاز به تغییر وضعیت سایر مدیران نیستید.'], 403);
            return;
        }

        $newStatus = ($user->status === 'banned') ? 'active' : 'banned';

        if (!$this->validateStatusTransition($user->status ?? 'active', $newStatus)) {
            $this->response->json(['success' => false, 'message' => 'تغییر وضعیت به مسدود غیرمجاز است.'], 400);
            return;
        }

        $ok = ($newStatus === 'active')
            ? $this->userService->unbanUser($id)
            : $this->userService->banUser($id, 'Suspended by Admin');

        if ($ok) {
            $this->auditLog(
                'user.ban.toggle',
                'user',
                $id,
                ['status' => $user->status ?? 'active'],
                ['status' => $newStatus]
            );

            $this->response->json([
                'success' => true,
                'message' => $newStatus === 'banned' ? 'کاربر با موفقیت بن شد' : 'کاربر از حالت بن خارج شد',
                'newStatus' => $newStatus
            ]);
        } else {
            $this->response->json(['success' => false, 'message' => 'خطا در تغییر وضعیت کاربر'], 500);
        }
    }

    /**
     * L-04: تأیید دستیِ ایمیلِ کاربر توسط پشتیبان/ادمین.
     * مجوز لازم: user.manage.verify_email (در لحظهٔ درخواست توسط AdminPermissionGuard بررسی می‌شود).
     */
    public function verifyEmail(int $id): void
    {
        $user = $this->toObject($this->userService->find($id));
        if (!isset($user->id)) {
            $this->response->json(['success' => false, 'message' => 'کاربر یافت نشد'], 404);
            return;
        }

        if (!empty($user->email_verified_at)) {
            $this->response->json(['success' => false, 'message' => 'ایمیل این کاربر قبلاً تأیید شده است'], 400);
            return;
        }

        $ok = $this->userService->verifyEmail($id);

        if ($ok) {
            $this->auditLog('user.email.verify_manual', 'user', $id, ['email_verified_at' => null], ['email_verified_at' => date('Y-m-d H:i:s')]);
            $this->response->json(['success' => true, 'message' => 'ایمیل کاربر با موفقیت تأیید شد']);
        } else {
            $this->response->json(['success' => false, 'message' => 'خطا در تأیید ایمیل کاربر'], 500);
        }
    }

    /**
     * L-04: ارسالِ مجددِ ایمیلِ تأیید برای کاربر (الگوی توکن مطابق جریان ثبت‌نام).
     * مجوز لازم: user.manage.verify_email.
     */
    public function resendVerificationEmail(int $id): void
    {
        $user = $this->toObject($this->userService->find($id));
        if (!isset($user->id)) {
            $this->response->json(['success' => false, 'message' => 'کاربر یافت نشد'], 404);
            return;
        }

        if (!empty($user->email_verified_at)) {
            $this->response->json(['success' => false, 'message' => 'ایمیل این کاربر قبلاً تأیید شده است'], 400);
            return;
        }

        $plainToken  = bin2hex(random_bytes(32));
        $hashedToken = hash_hmac('sha256', $plainToken, secure_key());

        $stored = $this->userService->update($id, ['email_verification_token' => $hashedToken]);
        if (!$stored) {
            $this->response->json(['success' => false, 'message' => 'خطا در ایجاد توکن تأیید'], 500);
            return;
        }

        $sent = app(\App\Services\EmailService::class)->sendVerificationEmail((int)$user->id, $plainToken);

        if ($sent) {
            $this->auditLog('user.email.resend_verification', 'user', $id, [], ['email' => $user->email ?? null]);
            $this->response->json(['success' => true, 'message' => 'ایمیل تأیید برای کاربر ارسال شد']);
        } else {
            $this->response->json(['success' => false, 'message' => 'خطا در ارسال ایمیل تأیید'], 500);
        }
    }

    /**
     * تعلیق کاربر
     */
    public function suspend(int $id): void
    {
        if ($id === (int)$this->userId()) {
            $this->jsonError('شما نمی‌توانید خودتان را تعلیق کنید', [], 403);
            return;
        }

        $user = $this->toObject($this->userService->find($id));
        if (!isset($user->id)) {
            $this->response->json(['success' => false, 'message' => 'کاربر یافت نشد'], 404);
            return;
        }

        if (!empty($user->deleted_at)) {
            $this->response->json(['success' => false, 'message' => 'این کاربر حذف شده است'], 400);
            return;
        }

        if (($user->status ?? '') === 'banned') {
            $this->response->json(['success' => false, 'message' => 'کاربر بن است؛ ابتدا از بن خارج کنید'], 400);
            return;
        }

        $currentAdmin = $this->toObject($this->userService->find((int)$this->userId()));
        $adminRoleLevel = ['user' => 0, 'admin' => 1, 'super_admin' => 2][$currentAdmin->role ?? 'user'] ?? 0;
        $userRole = $user->role ?? 'user';
        $targetUserRoleLevel = ['user' => 0, 'admin' => 1, 'super_admin' => 2][$userRole] ?? 0;
        if ($adminRoleLevel < 2 && $targetUserRoleLevel >= 1) {
            $this->response->json(['success' => false, 'message' => 'شما مجاز به تعلیق سایر مدیران نیستید.'], 403);
            return;
        }

        $newStatus = ($user->status === 'suspended') ? 'active' : 'suspended';

        if (!$this->validateStatusTransition($user->status ?? 'active', $newStatus)) {
            $this->response->json(['success' => false, 'message' => 'تغییر وضعیت به تعلیق غیرمجاز است.'], 400);
            return;
        }

        $ok = $this->userService->update($id, [
            'status' => $newStatus,
            'updated_at' => date('Y-m-d H:i:s')
        ]);

        if ($ok) {
            $this->auditLog(
                'user.suspend.toggle',
                'user',
                $id,
                ['status' => $user->status ?? 'active'],
                ['status' => $newStatus]
            );

            $this->response->json([
                'success' => true,
                'message' => $newStatus === 'suspended' ? 'کاربر تعلیق شد' : 'تعلیق برداشته شد',
                'newStatus' => $newStatus
            ]);
        } else {
            $this->response->json(['success' => false, 'message' => 'خطا در تغییر وضعیت'], 500);
        }
    }

    public function unban(int $id): void
    {
        $user = $this->toObject($this->userService->find($id));
        if (!isset($user->id)) {
            $this->response->json(['success' => false, 'message' => 'کاربر یافت نشد'], 404);
            return;
        }
        if (($user->status ?? '') !== 'banned') {
            $this->response->json(['success' => true, 'message' => 'کاربر در وضعیت بن نیست', 'newStatus' => $user->status ?? 'active']);
            return;
        }
        $ok = $this->userService->unbanUser($id);
        $this->response->json([
            'success' => (bool)$ok,
            'message' => $ok ? 'کاربر از حالت بن خارج شد' : 'خطا در تغییر وضعیت',
            'newStatus' => 'active'
        ], $ok ? 200 : 500);
    }

    public function unsuspend(int $id): void
    {
        $user = $this->toObject($this->userService->find($id));
        if (!isset($user->id)) {
            $this->response->json(['success' => false, 'message' => 'کاربر یافت نشد'], 404);
            return;
        }
        if (($user->status ?? '') !== 'suspended') {
            $this->response->json(['success' => true, 'message' => 'کاربر تعلیق نیست', 'newStatus' => $user->status ?? 'active']);
            return;
        }
        $ok = $this->userService->update($id, ['status' => 'active', 'updated_at' => date('Y-m-d H:i:s')]);
        $this->response->json([
            'success' => (bool)$ok,
            'message' => $ok ? 'تعلیق برداشته شد' : 'خطا در تغییر وضعیت',
            'newStatus' => 'active'
        ], $ok ? 200 : 500);
    }

}