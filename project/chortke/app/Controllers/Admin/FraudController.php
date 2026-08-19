<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Services\AntiFraud\FraudDetectionService;

/**
 * FraudController - مدیریت سیستم تشخیص تقلب
 *
 * توجه: این کنترلر دیگر try/catch محلی برای خطاهای غیرمنتظره ندارد.
 * هرگونه Throwable که از سرویس‌ها بیرون بزند، به GlobalExceptionMiddleware
 * می‌رسد که پاسخ JSON استاندارد و امن (بدون نشت پیام داخلی/انگلیسی) تولید
 * و به‌درستی لاگ می‌کند.
 */
class FraudController extends BaseAdminController
{
    private FraudDetectionService $fraudService;

    public function __construct(FraudDetectionService $fraudService, ?\App\Contracts\LoggerInterface $logger = null) {
        parent::__construct(null, null, null, null, $logger);
        $this->fraudService = $fraudService;
    }

    /**
     * گرفتن گزارش ریسک کاربر
     */
    public function getRiskReport(): void
    {
        $userId = $this->request->int('user_id');

        if (!$userId) {
            $this->response->json([
                'success' => false,
                'message' => 'شناسه کاربر الزامی است.'
            ], 400);
            return;
        }

        $report = $this->fraudService->getRiskReport($userId);

        $this->response->json([
            'success' => true,
            'data' => $report
        ]);
    }

    /**
     * محاسبه مجدد امتیاز تقلب کاربر
     */
    public function recalculateScore(): void
    {
        $userId = $this->request->int('user_id');

        if (!$userId) {
            $this->response->json([
                'success' => false,
                'message' => 'شناسه کاربر الزامی است.'
            ], 400);
            return;
        }

        $score = $this->fraudService->calculateFraudScore($userId);

        $this->response->json([
            'success' => true,
            'data' => [
                'user_id' => $userId,
                'fraud_score' => $score
            ]
        ]);
    }

    /**
     * اجرای اقدامات خودکار بر اساس امتیاز
     */
    public function executeActions(): void
    {
        $userId = $this->request->int('user_id');

        if (!$userId) {
            $this->response->json([
                'success' => false,
                'message' => 'شناسه کاربر الزامی است.'
            ], 400);
            return;
        }

        $actions = $this->fraudService->executeAutomatedActions($userId);

        $this->response->json([
            'success' => true,
            'data' => [
                'user_id' => $userId,
                'executed_actions' => $actions
            ]
        ]);
    }

    /**
     * گرفتن لیست کاربران پر ریسک
     */
    public function getHighRiskUsers(): void
    {
        $minScore = $this->request->int('min_score', 50);
        $limit = min(100, max(1, $this->request->int('limit', 50)));

        $users = $this->fraudService->getHighRiskUsers($minScore, $limit);

        $this->response->json([
            'success' => true,
            'data' => [
                'users' => $users,
                'count' => count($users),
                'min_score' => $minScore
            ]
        ]);
    }

    /**
     * گرفتن لاگ‌های تقلب
     */
    public function getFraudLogs(): void
    {
        $userId = $this->request->int('user_id') ?: null;
        $fraudTypeRaw = $this->request->get('fraud_type');
        $fraudType = $fraudTypeRaw !== null ? str_value($fraudTypeRaw) : null;
        $limit = min(100, max(1, $this->request->int('limit', 100)));

        $logs = $this->fraudService->getFraudLogs($userId, $fraudType, $limit);

        $this->response->json([
            'success' => true,
            'data' => [
                'logs' => $logs,
                'count' => count($logs)
            ]
        ]);
    }

    /**
     * پاک کردن پرچم‌های بررسی
     */
    public function clearFlags(): void
    {
        $userId = $this->request->int('user_id');

        if (!$userId) {
            $this->response->json([
                'success' => false,
                'message' => 'شناسه کاربر الزامی است.'
            ], 400);
            return;
        }

        $this->fraudService->clearUserFlags($userId);

        $this->response->json([
            'success' => true,
            'message' => 'پرچم‌های تقلب با موفقیت پاک شدند.'
        ]);
    }

    /**
     * تعلیق دستی حساب
     */
    public function suspendUser(): void
    {
        $userId = $this->request->int('user_id');
        $reason = trim(str_value($this->request->post('reason') ?? ''));

        if (!$userId) {
            $this->response->json([
                'success' => false,
                'message' => 'شناسه کاربر الزامی است.'
            ], 400);
            return;
        }

        if (!$reason) {
            $this->response->json([
                'success' => false,
                'message' => 'ذکر دلیل تعلیق الزامی است.'
            ], 400);
            return;
        }

        $this->fraudService->suspendUser($userId, $reason);

        $this->response->json([
            'success' => true,
            'message' => 'کاربر با موفقیت تعلیق شد.'
        ]);
    }

    /**
     * رفع تعلیق حساب
     */
    public function unsuspendUser(): void
    {
        $userId = $this->request->int('user_id');

        if (!$userId) {
            $this->response->json([
                'success' => false,
                'message' => 'شناسه کاربر الزامی است.'
            ], 400);
            return;
        }

        $this->fraudService->unsuspendUser($userId);

        $this->response->json([
            'success' => true,
            'message' => 'تعلیق کاربر با موفقیت رفع شد.'
        ]);
    }
}
