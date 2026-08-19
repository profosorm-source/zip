<?php

declare(strict_types=1);

namespace App\Controllers\User;

use App\Services\Auth\SessionService;
use App\Controllers\User\BaseUserController;
use Core\Encryption;
use Core\Session;
use Core\Cache;
use Core\Database;

/**
 * SessionController — مدیریت جلسات کاربران و ارزیابی رمزنگاری سیستمی
 */
class SessionController extends BaseUserController
{
    private SessionService $sessionService;

    public function __construct(
        SessionService $sessionService,
        ?\App\Contracts\LoggerInterface $logger = null
    ) {
        parent::__construct(null, null, null, null, $logger);
        $this->sessionService = $sessionService;
    }

    /**
     * صفحه نشست‌های فعال
     */
    public function index(): void
    {
        $userId = (int) user_id();
        $sessions = $this->sessionService->getActiveSessions($userId);

        // PRG & CSP Rendering Fix: استفاده از $this->view جهت انتساب صحیح خروجی به شیء Response
        $this->view('user/sessions/index', [
            'sessions' => $sessions,
            'currentSessionId' => \session_id()
        ]);
    }

    /**
     * حذف نشست (Action-based → JSON)
     */
    public function terminate(int $id): void
    {
        $this->validateCsrf();

        $userId = (int) user_id();

        $result = $this->sessionService->terminateSession($id, $userId);

        $this->response->json([
            'success' => $result['success'],
            'message' => $result['message']
        ], $result['success'] ? 200 : 400);
    }

    /**
     * اجرای ممیزی جامع و مرورگرمحور لایه رمزنگاری و نشست‌ها (Section 7.5 Verification)
     */
    
}
