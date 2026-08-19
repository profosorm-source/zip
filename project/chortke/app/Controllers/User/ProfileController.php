<?php

namespace App\Controllers\User;

use App\Services\User\UserService;
use App\Services\UploadService;
use App\Controllers\User\BaseUserController;

class ProfileController extends BaseUserController
{
    private UploadService $uploadService;
    // $userService inherited from parent
    private \App\Services\User\ProfileService $profileService;
    private \App\Services\Auth\SessionService $sessionService;

    public function __construct(
        UserService $userService,
        \App\Services\UploadService $uploadService,
        \App\Services\User\ProfileService $profileService,
        \App\Services\Auth\SessionService $sessionService
    , ?\App\Contracts\LoggerInterface $logger = null) {
        parent::__construct(null, null, null, null, $logger);
        $this->userService = $userService;
        $this->uploadService = $uploadService;
        $this->profileService = $profileService;
        $this->sessionService = $sessionService;
    }

    public function index(): void
    {
        // SEC-2 fix (BUGFIX-FALLBACK-USER-2026-06): no more hardcoded demo
        // user — if the session is stale or the DB lookup fails, redirect to
        // login instead of leaking PII of user #1.
        $userObj = $this->loadCurrentUserOrRedirect();
        $userId  = (int) $userObj->id;
        // Defensive: views expect these optional fields to exist as keys
        // even when not yet set in the DB. Fill in null defaults rather
        // than re-introducing fake values.
        foreach (['avatar', 'mobile', 'national_id', 'birth_date', 'gender', 'address', 'bio'] as $field) {
            if (!isset($userObj->{$field})) {
                $userObj->{$field} = null;
            }
        }
        
        $this->view('user/profile', ['user' => $userObj]);
    }

    public function update(): void
    {
        $userId = (int)user_id();

        // Rate Limiting
        try {
            rate_limit('content', 'update', "user_{$userId}");
        } catch (\Core\Exceptions\RateLimitExceededException $e) {
            $this->session->setFlash('error', $e->getMessage());
            redirect('profile');
        }

        // Get data from request
        $data = [
            'full_name'   => $this->request->input('full_name'),
            'mobile'      => $this->request->input('mobile'),
            'national_id' => $this->request->input('national_id'),
            'birth_date'  => $this->request->input('birth_date'),
            'gender'      => $this->request->input('gender'),
            'address'     => $this->request->input('address'),
            'bio'         => $this->request->input('bio'),
        ];

        // Use service for validation and update
        $result = $this->profileService->updateProfileWithValidation($userId, $data);

        if ($result['success']) {
            $this->session->setFlash('success', $result['message']);
        } else {
            $errors = $result['errors'] ?? [];
            $errorMessage = is_array($errors) 
                ? implode('<br>', $errors)
                : $errors;
            $this->session->setFlash('error', $errorMessage);
        }

        redirect('profile');
    }

   public function uploadAvatar(): void
{
        $userId = (int)user_id();

        // Rate Limiting - محدودیت آپلود آواتار
        try {
            rate_limit('upload', 'avatar', "user_{$userId}");
        } catch (\Core\Exceptions\RateLimitExceededException $e) {
            $this->response->json(['success' => false, 'message' => $e->getMessage()], 429);
            return;
        }

    if (!isset($_FILES['avatar']) || ($_FILES['avatar']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        $this->response->json(['success' => false, 'message' => 'لطفاً یک تصویر انتخاب کنید'], 400);
        return;
    }

    $uploadService = $this->uploadService;
    $filename = '';
    $oldAvatar = null;

    try {
        $upload = $uploadService->upload(
            $_FILES['avatar'],
            'avatars',
            ['image/jpeg', 'image/png', 'image/jpg', 'image/gif', 'image/webp'],
            2097152
        );

        if (!is_array($upload) || empty($upload['success']) || empty($upload['filename']) || empty($upload['path'])) {
            $this->response->json(['success' => false, 'message' => 'خطا در آپلود تصویر'], 400);
            return;
        }

        $filename = (string) $upload['filename'];
        $oldUser = $this->userService->findById((int)$userId);
        if ($oldUser && !empty($oldUser->avatar) && $oldUser->avatar !== 'default-avatar.png') {
            $oldAvatar = $oldUser->avatar;
        }

        $result = $this->profileService->updateProfile($userId, [
            'avatar' => $filename,
        ]);

        if (!$result) {
            if (method_exists($uploadService, 'delete')) {
                $uploadService->delete('avatars/' . $filename);
            }
            $this->logger->info('Avatar update failed', ['user_id' => $userId, 'filename' => $filename]);
            $this->response->json(['success' => false, 'message' => 'خطا در ذخیره‌سازی آواتار در دیتابیس'], 500);
            return;
        }

        if ($oldAvatar && method_exists($uploadService, 'delete')) {
            $uploadService->delete('avatars/' . $oldAvatar);
        }

        $this->logger->info('Avatar uploaded', ['user_id' => $userId]);

        $this->response->json([
            'success' => true,
            'message' => 'تصویر پروفایل با موفقیت بروزرسانی شد',
            'avatar_url' => asset('uploads/' . ltrim((string)$upload['path'], '/'))
        ]);
        return;
    } catch (\Core\Exceptions\HttpResponseException $response) {
        throw $response;
    } catch (\Core\Exceptions\BusinessException $validationError) {
        if ($filename !== '' && method_exists($uploadService, 'delete')) {
            $uploadService->delete('avatars/' . $filename);
        }
        $this->logger->warning('Avatar upload rejected', [
            'user_id' => $userId,
            'reason' => $validationError->getMessage(),
        ]);
        $this->response->json(['success' => false, 'message' => $validationError->getMessage()], 422);
        return;
    } catch (\Throwable $e) {
        if ($filename !== '' && method_exists($uploadService, 'delete')) {
            $uploadService->delete('avatars/' . $filename);
        }
        $this->logger->error('Avatar upload failed', ['error' => $e->getMessage()]);
        $this->response->json(['success' => false, 'message' => 'خطای سرور در آپلود آواتار'], 500);
        return;
    }
}

    public function deleteAvatar(): void
    {
        $userId = (int)user_id();

        $user = $this->userService->findById((int)$userId);

        if (!$user) {
            $this->response->json(['success' => false, 'message' => 'کاربر یافت نشد'], 404);
            return;
        }

        if (empty($user->avatar) || $user->avatar === 'default-avatar.png') {
            $this->response->json(['success' => false, 'message' => 'تصویر پیش‌فرض قابل حذف نیست'], 400);
            return;
        }

        $uploadService = $this->uploadService;

        if (method_exists($uploadService, 'delete')) {
            $uploadService->delete('avatars/' . $user->avatar);
        }

        $result = $this->profileService->updateProfile($userId, [
            'avatar' => 'default-avatar.png',
        ]);

        if (!$result) {
            $this->response->json(['success' => false, 'message' => 'خطا در بروزرسانی دیتابیس'], 500);
            return;
        }

        $this->logger->info('Avatar deleted', ['user_id' => $userId]);

        $this->response->json([
            'success'    => true,
            'message'    => 'تصویر پروفایل حذف شد',
            'avatar_url' => asset('uploads/avatars/default-avatar.png')
        ]);
    }

    public function changePassword(): void
    {
        $this->validateCsrf();
        
        $lastAuthTime = $this->session->get('last_auth_time');
        if (!$lastAuthTime || (\time() - $lastAuthTime > 300)) {
            $this->session->setFlash('error', 'جهت حفظ امنیت حساب کاربری خود، برای تغییر رمز عبور باید در ۵ دقیقه گذشته وارد سیستم شده باشید. لطفاً مجدداً وارد شوید.');
            \redirect('login');
        }

        $userId = user_id();
        
        $currentPassword = str_value($this->request->input('current_password'));
        $newPassword = str_value($this->request->input('new_password'));
        $confirmPassword = str_value($this->request->input('new_password_confirmation'));
        
        if (!$currentPassword || !$newPassword || !$confirmPassword) {
            $this->session->setFlash('error', 'لطفاً تمام فیلدها را پر کنید');
            redirect('profile');
        }
        
        // MED-05 Fix: Enforce complex password policy (Uppercase, Lowercase, Digits, Symbols)
        $complexityPattern = '/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[@$!%*?&])[A-Za-z\d@$!%*?&]{8,}$/';
        if (!preg_match($complexityPattern, $newPassword)) {
            $this->session->setFlash('error', 'رمز عبور باید حداقل ۸ کاراکتر و شامل حروف بزرگ، حروف کوچک، عدد و نماد باشد.');
            redirect('profile');
        }
        
        if ($newPassword !== $confirmPassword) {
            $this->session->setFlash('error', 'رمز عبور جدید و تکرار آن یکسان نیستند');
            redirect('profile');
        }
        
        $user = $this->userService->findById((int)$userId);
        if (!$user || !isset($user->password) || !verify_password($currentPassword, $user->password)) {
            $this->session->setFlash('error', 'رمز عبور فعلی اشتباه است');
            redirect('profile');
        }
        
        $result = $this->userService->update((int)$userId, [
            'password' => hash_password($newPassword),
            'updated_at' => date('Y-m-d H:i:s')
        ]);
        
        if ($result) {
            // ✅ اصلاح logger
            $this->logger->info('Password changed', ['user_id' => $userId]);
            
            // ✅ Invalidate other sessions after password change
            $sessionId = session_id();
            $this->sessionService->invalidateAllUserSessions((int)$userId, is_string($sessionId) ? $sessionId : null);

            // ✅ حالا Session ID فعلی را regenerate کن
            session_regenerate_id(true);

            // ✅ Regenerate CSRF after sensitive action
            $this->csrf->regenerate();

            $this->session->setFlash('success', 'رمز عبور با موفقیت تغییر یافت');
        } else {
            $this->session->setFlash('error', 'خطا در تغییر رمز عبور');
        }
        
        redirect('profile');
    }
}
