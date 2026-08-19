<?php

namespace App\Controllers\Api;

use App\Models\Notification;

/**
 * API\UserController - پروفایل و اطلاعات کاربر
 *
 * GET  /api/v1/user/profile          → اطلاعات پروفایل
 * GET  /api/v1/user/notifications    → اعلان‌ها
 * POST /api/v1/user/notifications/read → خواندن اعلان
 */
class UserController extends BaseApiController
{
    private Notification $notifModel;
    private \App\Services\TicketService $ticketService;
    private \App\Services\Auth\TwoFactorService $twoFactorService;
    private \App\Services\Auth\SessionService $sessionService;

    public function __construct(
        \App\Models\Notification $notifModel,
        \App\Services\TicketService $ticketService,
        \App\Services\Auth\TwoFactorService $twoFactorService,
        \App\Services\Auth\SessionService $sessionService,
        ?\App\Contracts\LoggerInterface $logger = null
    ) {
        parent::__construct(null, null, null, null, $logger);
        $this->notifModel = $notifModel;
        $this->ticketService = $ticketService;
        $this->twoFactorService = $twoFactorService;
        $this->sessionService = $sessionService;
    }

    /** پروفایل کاربر */
    public function profile(): void
    {
        $user = $this->currentUser();

        if ($user === null) { $this->error('احراز هویت لازم است', 401); return; }

        $avatar = $user->avatar ?? null;
        $avatarThumb = $avatar ? (str_contains($avatar, 'http') ? $avatar : url('uploads/avatars/thumb_' . basename($avatar))) : null;
        $avatarFull = $avatar ? (str_contains($avatar, 'http') ? $avatar : url('uploads/avatars/' . basename($avatar))) : null;

        // اصلاح کلیدی معماری موبایل (Mobile Texture OOM & Image Optimization Guard):
        // ارسال هم‌زمان نسخه بهینه‌شده (Thumbnail) و اصلی آواتار جهت جلوگیری از کرش مموری کارت گرافیک در گوشی‌های موبایل (Flutter/React Native)
        $this->success([
            'id'            => $user->id,
            'full_name'     => $user->full_name,
            'email'         => $user->email,
            'mobile'        => $user->mobile,
            'referral_code' => $user->referral_code,
            'kyc_status'    => $user->kyc_status ?? 'none',
            'level_slug'    => $user->level_slug ?? 'silver',
            'is_verified'   => (bool)($user->email_verified_at ?? false),
            'avatar'        => $avatarFull,
            'avatar_thumb'  => $avatarThumb,
            'created_at'    => $user->created_at,
        ]);
    }

    /** لیست اعلان‌ها */
    public function notifications(): void
    {
        $userId = (int)$this->userId();
        [$page, $perPage, $offset] = $this->paginationParams(20);

        $onlyUnread = (bool)($this->request->get('unread') ?? false);

        $items = $this->notifModel->getUserNotifications($userId, $onlyUnread, $perPage, $offset);
        $total = $this->notifModel->countUserNotifications($userId, $onlyUnread);

        $items = array_map(fn($n) => [
            'id'         => $n->id,
            'title'      => $n->title,
            'message'    => $n->message,
            'type'       => $n->type,
            'is_read'    => (bool)$n->is_read,
            'created_at' => $n->created_at,
        ], $items);

        $this->paginated($items, $total, $page, $perPage);
    }

    /** خواندن اعلان */
    public function markRead(): void
    {
        $userId = (int)$this->userId();
        $id     = $this->request->int('id');

        if (!$id) {
            // خواندن همه
            $this->notifModel->markAllAsRead($userId);
            $this->success(null, 'همه اعلان‌ها خوانده شدند');
        }

        $this->notifModel->markAsRead($id, $userId);
        $this->success(null, 'اعلان خوانده شد');
    }

    /** لیست تیکت‌ها */
    public function ticketsList(): void
    {
        $userId = (int)$this->userId();
        [$page, $perPage] = $this->paginationParams(20);
        $status = $this->request->str('status') !== '' ? $this->request->str('status') : null;
        $result = $this->ticketService->listUserTickets($userId, $status, $page, $perPage);
        $this->paginated($result['tickets'] ?? [], $result['total'] ?? 0, $page, $perPage);
    }

    /** ایجاد تیکت جدید */
    public function ticketsStore(): void
    {
        $userId = (int)$this->userId();
        $data = (array)$this->request->body();
        try {
            $result = $this->ticketService->create($userId, $data);
            if (!empty($result['success'])) {
                $this->success($result, 'تیکت با موفقیت ایجاد شد', 201);
            }
            $this->error(str_value($result['message'] ?? 'خطا در ایجاد تیکت'), 422);
        } catch (\Throwable $e) {
            $this->error($e->getMessage(), 422);
        }
    }

    /** نمایش جزئیات تیکت */
    public function ticketsShow(): void
    {
        $userId = (int)$this->userId();
        $id = $this->request->int('id');
        $ticketModel = $this->container->make(\App\Models\Ticket::class);
        $messageModel = $this->container->make(\App\Models\TicketMessage::class);
        $ticket = $ticketModel->findForUser($id, $userId);
        if (!$ticket) {
            $this->error('تیکت یافت نشد', 404, 'TICKET_NOT_FOUND');
        }
        $messages = $messageModel->getByTicketId($id);
        $this->success(['ticket' => $ticket, 'messages' => $messages]);
    }

    /** ارسال پاسخ به تیکت */
    public function ticketsReply(): void
    {
        $userId = (int)$this->userId();
        $id = $this->request->int('id');
        $data = (array)$this->request->body();
        $message = trim(str_value($data['message'] ?? ''));
        $attachments = is_array($data['attachments'] ?? null) ? $data['attachments'] : [];
        try {
            $result = $this->ticketService->reply($id, $userId, $message, false, $attachments);
            if (!empty($result['success'])) {
                $this->success($result, 'پاسخ با موفقیت ارسال شد', 201);
            }
            $this->error(str_value($result['message'] ?? 'خطا در ارسال پاسخ'), 422);
        } catch (\Throwable $e) {
            $this->error($e->getMessage(), 422);
        }
    }

    /** بستن تیکت */
    public function ticketsClose(): void
    {
        $userId = (int)$this->userId();
        $id = $this->request->int('id');
        try {
            $result = $this->ticketService->close($id, $userId, false);
            if (!empty($result['success'])) {
                $this->success(null, 'تیکت بسته شد');
            }
            $this->error(str_value($result['message'] ?? 'خطا در بستن تیکت'), 400);
        } catch (\Throwable $e) {
            $this->error($e->getMessage(), 422);
        }
    }

    /** دسته‌بندی‌های تیکت */
    public function ticketsCategories(): void
    {
        $categories = $this->ticketService->getCategories();
        $this->success($categories);
    }

    /** وضعیت 2FA */
    public function twoFactorStatus(): void
    {
        $user = $this->currentUser();
        if ($user === null) { $this->error('احراز هویت لازم است', 401); return; }
        $isEnabled = ($user->two_factor_enabled ?? 0) == 1;
        // 🛡️ Security Fix (Issue #6): Never expose TOTP secret / QR code in read-only status
        $this->success([
            'is_enabled' => $isEnabled,
            'has_secret' => !empty($user->two_factor_secret),
        ]);
    }

    /**
     * L-23 Fix: تولید/بازگرداندن secret در یک مسیر نوشتن (POST, scope user.write).
     * نیاز به تایید رمز عبور فعلی جهت جلوگیری از سوءاستفاده از توکن‌های API دارد.
     */
    public function twoFactorSetup(): void
    {
        $user = $this->currentUser();
        if ($user === null) { $this->error('احراز هویت لازم است', 401); return; }
        if (($user->two_factor_enabled ?? 0) == 1) {
            $this->error('احراز هویت دو مرحله‌ای قبلاً فعال شده است', 422); return;
        }

        $data = (array)$this->request->body();
        $password = trim(str_value($data['password'] ?? $data['current_password'] ?? ''));
        if ($password === '') {
            $this->error('جهت راه‌اندازی ۲FA، وارد کردن رمز عبور فعلی الزامی است.', 422);
            return;
        }

        $pwService = $this->container->make(\App\Services\Auth\PasswordRecoveryService::class);
        if (!$pwService->verifyPassword($password, $user->password ?? '', (int)$user->id)) {
            $this->error('رمز عبور فعلی نادرست است.', 422);
            return;
        }

        if (empty($user->two_factor_secret)) {
            $secret = $this->twoFactorService->generateSecret();
            $encryptedSecret = $this->twoFactorService->encryptSecret($secret);
            $userModel = $this->container->make(\App\Models\User::class);
            $userModel->update($user->id, ['two_factor_secret' => $encryptedSecret]);
            $user->two_factor_secret = $encryptedSecret;
        }
        $this->success([
            'qr_code_url' => $this->twoFactorService->getQRCodeUrl($user->username ?? $user->email, $user->two_factor_secret),
        ]);
    }

    /** فعال‌سازی 2FA */
    public function twoFactorEnable(): void
    {
        $user = $this->currentUser();
        if ($user === null) { $this->error('احراز هویت لازم است', 401); return; }
        $userId = (int)$user->id;
        $data = (array)$this->request->body();
        $code = trim(str_value($data['code'] ?? ''));
        $password = trim(str_value($data['password'] ?? $data['current_password'] ?? ''));

        if ($password === '') {
            $this->error('جهت فعال‌سازی ۲FA، وارد کردن رمز عبور فعلی الزامی است.', 422);
            return;
        }

        $pwService = $this->container->make(\App\Services\Auth\PasswordRecoveryService::class);
        if (!$pwService->verifyPassword($password, $user->password ?? '', $userId)) {
            $this->error('رمز عبور فعلی نادرست است.', 422);
            return;
        }

        $result = $this->twoFactorService->enable($userId, $code);
        if (!empty($result['success'])) {
            $this->success($result, 'احراز هویت دو مرحله‌ای فعال شد');
            return;
        }
        $this->error(str_value($result['message'] ?? 'خطا در فعال‌سازی'), 422);
    }

    /** غیرفعال‌سازی 2FA */
    public function twoFactorDisable(): void
    {
        $userId = (int)$this->userId();
        $data = (array)$this->request->body();
        $password = str_value($data['password'] ?? '');
        $result = $this->twoFactorService->disable($userId, $password);
        if (!empty($result['success'])) {
            $this->success($result, 'احراز هویت دو مرحله‌ای غیرفعال شد');
        }
            $this->error(str_value($result['message'] ?? 'خطا در غیرفعال‌سازی'), 422);
    }

    /** لیست نشست‌های فعال */
    public function sessionsList(): void
    {
        $userId = (int)$this->userId();
        $sessions = $this->sessionService->getActiveSessions($userId);
        $this->success($sessions);
    }

    /** حذف نشست فعال */
    public function sessionsRevoke(): void
    {
        $userId = (int)$this->userId();
        $id = int_value($this->request->param('id') ?? $this->request->get('id') ?? 0);
        $result = $this->sessionService->terminateSession($id, $userId);
        if (!empty($result['success'])) {
            $this->success(null, 'نشست با موفقیت حذف شد');
        }
            $this->error(str_value($result['message'] ?? 'خطا در حذف نشست'), 400);
    }

    /** دریافت تنظیمات عمومی و حریم خصوصی */
    public function settingsGet(): void
    {
        $userId = (int)$this->userId();
        $settingsService = $this->container->make(\App\Services\User\UserSettingsService::class);
        $general = $settingsService->getSettings($userId, 'general');
        $privacy = $settingsService->getSettings($userId, 'privacy');
        $this->success(['general' => $general, 'privacy' => $privacy]);
    }

    /** بروزرسانی تنظیمات عمومی */
    public function settingsGeneralUpdate(): void
    {
        $userId = (int)$this->userId();
        $data = (array)$this->request->body();
        $settingsService = $this->container->make(\App\Services\User\UserSettingsService::class);
        foreach ((array)$data as $key => $val) {
            $settingsService->set($userId, (string)$key, $val);
        }
        $this->success(null, 'تنظیمات عمومی بروزرسانی شد');
    }

    /** بروزرسانی تنظیمات حریم خصوصی */
    public function settingsPrivacyUpdate(): void
    {
        $userId = (int)$this->userId();
        $data = (array)$this->request->body();
        $settingsService = $this->container->make(\App\Services\User\UserSettingsService::class);
        foreach ((array)$data as $key => $val) {
            $settingsService->set($userId, (string)$key, $val);
        }
        $this->success(null, 'تنظیمات حریم خصوصی بروزرسانی شد');
    }

    /** درخواست حذف حساب کاربری */
    public function accountDeletionRequest(): void
    {
        $userId = (int)$this->userId();
        $data = (array)$this->request->body();
        $reason = trim(str_value($data['reason'] ?? ''));
        $deletionService = $this->container->make(\App\Services\User\AccountDeletionService::class);
        $result = $deletionService->requestDeletion($userId, $reason);
        if (!empty($result['success'])) {
            $this->success($result, 'درخواست حذف اکانت ثبت شد و پس از ۳۰ روز انجام می‌شود');
        }
            $this->error(str_value($result['message'] ?? 'خطا در ثبت درخواست حذف اکانت'), 422);
    }

    /** وضعیت احراز هویت (KYC) */
    public function kycStatus(): void
    {
        $userId = (int)$this->userId();
        $kycService = $this->container->make(\App\Services\KYCService::class);
        $status = [
            'is_approved' => $kycService->isApproved($userId),
            'can_submit'  => $kycService->canSubmitKYC($userId),
        ];
        $this->success($status);
    }

    /** ارسال مدارک احراز هویت (KYC) */
    public function kycSubmit(): void
    {
        $userId = (int)$this->userId();
        $data  = $this->request->body();
        $files = $_FILES;
        $kycService = $this->container->make(\App\Services\KYCService::class);
        try {
            $result = $kycService->submitKYC($userId, $data, $files);
            if (!empty($result['success'])) {
                $this->success($result, 'مدارک احراز هویت با موفقیت ارسال شد و در انتظار بررسی است', 201);
            }
            $this->error(str_value($result['message'] ?? 'خطا در ارسال مدارک'), 422);
        } catch (\Throwable $e) {
            $this->error($e->getMessage(), 422);
        }
    }

    /** لیست پیام‌های خصوصی */
    public function directMessagesList(): void
    {
        $userId = (int)$this->userId();
        [$page, $perPage, $offset] = $this->paginationParams(20);
        $dmService = $this->container->make(\App\Services\DirectMessageService::class);
        $result = $dmService->getUserConversations($userId, $perPage, $offset);
        $this->paginated(is_array($result['conversations'] ?? null) ? $result['conversations'] : [], int_value($result['total'] ?? 0), $page, $perPage);
    }

    /** ارسال پیام خصوصی */
    public function directMessageSend(): void
    {
        $userId = (int)$this->userId();
        $data = (array)$this->request->body();
        $recipientId = int_value($data['recipient_id'] ?? 0);
        $content = trim(str_value($data['content'] ?? ''));
        $dmService = $this->container->make(\App\Services\DirectMessageService::class);
        try {
            $result = $dmService->sendMessage($userId, $recipientId, $content);
            if (!empty($result['success'])) {
                $this->success($result, 'پیام با موفقیت ارسال شد', 201);
            }
            $this->error(str_value($result['message'] ?? 'خطا در ارسال پیام'), 422);
        } catch (\Throwable $e) {
            $this->error($e->getMessage(), 422);
        }
    }

    /** ثبت گزارش باگ */
    public function bugReportStore(): void
    {
        $userId = (int)$this->userId();
        $data  = $this->request->body();
        try {
            $result = $this->ticketService->submitBugReport($userId, $data);
            if (!empty($result['success'])) {
                $this->success(['bug_id' => $result['id'] ?? null], str_value($result['message'] ?? 'گزارش باگ با موفقیت ثبت شد'), 201);
            }
            $this->error(str_value($result['message'] ?? 'خطا در ثبت گزارش'), 422);
        } catch (\Throwable $e) {
            $this->error($e->getMessage(), 422);
        }
    }
}
