<?php

namespace App\Controllers\Admin;

use App\Models\Notification;
use App\Services\Notification\NotificationService;
use App\Services\Notification\NotificationAnalyticsService;
use App\Controllers\Admin\BaseAdminController;
use App\Validators\Requests\SendNotificationRequest;
use App\Validators\Requests\SaveNotificationTemplateRequest;
use App\Validators\Requests\DeleteNotificationTemplateRequest;

class NotificationController extends BaseAdminController
{
    private Notification         $model;
    private NotificationService  $notificationService;
    private NotificationAnalyticsService $analyticsService;

    public function __construct(
        Notification        $model,
        NotificationService $notificationService,
        NotificationAnalyticsService $analyticsService
    , ?\App\Contracts\LoggerInterface $logger = null) {
        parent::__construct(null, null, null, null, $logger);
        $this->model              = $model;
        $this->notificationService = $notificationService;
        $this->analyticsService   = $analyticsService;
    }

    // =========================================================================
    // صفحات اصلی
    // =========================================================================

    /**
     * لیست اعلان‌های ادمین
     */
    public function index(): void
    {
        $adminId       = $this->requireAdminId();
        $notifications = $this->notificationService->latest($adminId, 50);
        $unreadCount   = $this->notificationService->getUnreadCount($adminId);

        view('admin/notifications/index', [
            'title'         => 'اعلان‌ها',
            'notifications' => $notifications,
            'unread_count'  => $unreadCount,
        ]);
    }

    /**
     * صفحه ارسال اعلان — با segment و زمان‌بندی
     */
    public function showSend(): void
    {
        view('admin/notifications/send', [
            'title'            => 'ارسال اعلان به کاربران',
            'segments'         => $this->notificationService->getAvailableSegments(),
            'notification_types' => [
                Notification::TYPE_SYSTEM     => 'سیستمی',
                Notification::TYPE_INFO       => 'اطلاعیه',
                Notification::TYPE_MARKETING  => 'تبلیغاتی',
                Notification::TYPE_TASK       => 'تسک',
                Notification::TYPE_SECURITY   => 'امنیتی',
            ],
        ]);
    }

    /**
     * پردازش ارسال اعلان دستی
     */
    public function send(): void
    {
        $target      = trim($this->request->str('target', 'all'));
        $segment     = trim($this->request->str('segment', 'all'));
        $type        = trim($this->request->str('type', 'info'));
        $title       = trim($this->request->str('title', ''));
        $message     = trim($this->request->str('message', ''));
        $userId      = $this->request->int('user_id');
        $priority    = trim($this->request->str('priority', 'normal'));
        $scheduledAt = trim($this->request->str('scheduled_at', ''));
        $actionUrl   = trim($this->request->str('action_url', ''));
        $actionText  = trim($this->request->str('action_text', ''));

        $formRequest = new SendNotificationRequest([
            'target'       => $target,
            'segment'      => $segment,
            'type'         => $type,
            'title'        => $title,
            'message'      => $message,
            'user_id'      => $userId,
            'priority'     => $priority,
            'scheduled_at' => $scheduledAt,
            'action_url'   => $actionUrl,
            'action_text'  => $actionText,
        ]);
        if (!$formRequest->validate()) {
            $errors = $formRequest->errors();
            $firstError = is_array($errors) && $errors !== [] ? reset($errors) : '';
            if (is_array($firstError)) {
                $firstError = reset($firstError);
            }
            $firstError = str_value($firstError);
            $this->session->setFlash('error', $firstError ?: 'اطلاعات ورودی نامعتبر است.');
            redirect('/admin/notifications/send');
        }
        $validated = $formRequest->validated();
        $target      = str_value($validated['target'] ?? $target);
        $segment     = str_value($validated['segment'] ?? $segment);
        $type        = str_value($validated['type'] ?? $type);
        $title       = str_value($validated['title'] ?? $title);
        $message     = str_value($validated['message'] ?? $message);
        $userId      = int_value($validated['user_id'] ?? $userId);
        $priority    = str_value($validated['priority'] ?? $priority);
        $scheduledAt = str_value($validated['scheduled_at'] ?? $scheduledAt);
        $actionUrl   = str_value($validated['action_url'] ?? $actionUrl);
        $actionText  = str_value($validated['action_text'] ?? $actionText);

        $scheduledAt = !empty($scheduledAt) ? $scheduledAt : null;
        $actionUrl   = !empty($actionUrl)   ? $actionUrl   : null;
        $actionText  = !empty($actionText)  ? $actionText  : null;

        $sent = 0;

        if ($target === 'all' || $target === 'segment') {
            $seg    = ($target === 'all') ? 'all' : $segment;
            $result = $this->notificationService->sendToSegment(
                $seg, $title, $message, $type,
                $actionUrl, $actionText, $priority, null, $scheduledAt
            );
            $sent = $result['sent'] ?? 0;

        } elseif ($target === 'user' && $userId > 0) {
            $notifId = $this->notificationService->send(
                $userId, $type, $title, $message,
                null, $actionUrl, $actionText, $priority,
                null, null, null, $scheduledAt
            );
            $sent = $notifId ? 1 : 0;

        } else {
            $this->session->setFlash('error', 'هدف ارسال نامعتبر است.');
            redirect('/admin/notifications/send');
        }

        $sent = int_value($sent);
        $msg = $scheduledAt
            ? "اعلان برای {$sent} کاربر زمان‌بندی شد."
            : "اعلان با موفقیت به {$sent} کاربر ارسال شد.";

       $this->logger->activity('admin_notification_sent', $msg, user_id(), []);
        $this->session->setFlash('success', $msg);
        redirect('/admin/notifications');
    }

    // =========================================================================
    // Analytics
    // =========================================================================

    /**
     * داشبورد آمار کامل
     */
    public function stats(): void
    {
        $days      = max(7, min(90, $this->request->int('days', 30)));
        $dashboard = $this->analyticsService->getAnalyticsOverview($days);

        view('admin/notifications/stats', [
            'title'     => 'آمار اعلان‌ها',
            'dashboard' => $dashboard,
            'days'      => $days,
        ]);
    }

    /**
     * Ajax — داشبورد JSON
     */
    public function statsFetch(): void
    {
        $days = max(7, min(90, $this->request->int('days', 30)));

        $this->response->json([
            'success'   => true,
            'dashboard' => $this->analyticsService->getAnalyticsOverview($days),
        ]);
    }

    // =========================================================================
    // Template Management
    // =========================================================================

    /**
     * لیست template‌ها
     */
    public function templates(): void
    {
        view('admin/notifications/templates', [
            'title'     => 'قالب‌های نوتیفیکیشن',
            'templates' => $this->notificationService->getAllTemplatesWithVariables(),
        ]);
    }

    /**
     * ذخیره override template
     */
    public function saveTemplate(): void
    {
        $formRequest = new SaveNotificationTemplateRequest([
            'template_key' => trim($this->request->str('template_key', '')),
            'title'        => trim($this->request->str('title', '')),
            'message'      => trim($this->request->str('message', '')),
        ]);
        if (!$formRequest->validate()) {
            $errors = $formRequest->errors();
            $firstError = is_array($errors) && $errors !== [] ? reset($errors) : '';
            if (is_array($firstError)) {
                $firstError = reset($firstError);
            }
            $firstError = str_value($firstError);
            $this->response->json(['success' => false, 'error' => $firstError ?: 'اطلاعات ورودی نامعتبر است'], 400);
            return;
        }
        $validated = $formRequest->validated();
        $key     = str_value($validated['template_key'] ?? '');
        $title   = str_value($validated['title'] ?? '');
        $message = str_value($validated['message'] ?? '');

        $result = $this->notificationService->saveTemplateOverride($key, $title, $message);

        if ($result) {
            $this->logger->activity('notif_template_saved', "ذخیره template: {$key}", user_id(), []);
        }

        $this->response->json(['success' => $result], $result ? 200 : 422);
    }

    /**
     * حذف override (بازگشت به default)
     */
    public function deleteTemplate(): void
    {
        $formRequest = new DeleteNotificationTemplateRequest([
            'template_key' => trim($this->request->str('template_key', '')),
        ]);
        if (!$formRequest->validate()) {
            $errors = $formRequest->errors();
            $firstError = is_array($errors) && $errors !== [] ? reset($errors) : '';
            if (is_array($firstError)) {
                $firstError = reset($firstError);
            }
            $firstError = str_value($firstError);
            $this->response->json(['success' => false, 'error' => $firstError ?: 'اطلاعات ورودی نامعتبر است'], 400);
            return;
        }
        $validated = $formRequest->validated();
        $key = str_value($validated['template_key'] ?? '');

        $this->notificationService->deleteTemplateOverride($key);
       $this->logger->activity('notif_template_deleted', "حذف override template: {$key}", user_id(), []);

        $this->response->json(['success' => true, 'message' => 'بازگشت به template پیش‌فرض']);
    }

    // =========================================================================
    // Ajax — کنترل نوتیف ادمین
    // =========================================================================

    public function fetch(): void
    {
        $adminId = $this->requireAdminId();
        $items  = $this->notificationService->latest($adminId, 10);
        $unread = $this->notificationService->getUnreadCount($adminId);

        $this->response->json([
            'success'       => true,
            'notifications' => $items,
            'unread_count'  => $unread,
        ]);
    }

    public function unreadCount(): void
    {
        $adminId = $this->requireAdminId();
        $this->response->json([
            'success' => true,
            'count'   => $this->notificationService->getUnreadCount($adminId),
        ]);
    }

    public function markAsRead(int $id): void
    {
        $adminId = $this->requireAdminId();
        $ok = $this->model->markAsRead($id, $adminId);

        if ($ok) {
            $this->notificationService->invalidateUnreadCache($adminId);
        }

        $this->response->json([
            'success' => $ok,
            'message' => $ok ? 'خوانده شد' : 'عملیات ناموفق بود',
        ], $ok ? 200 : 400);
    }

    public function markAllAsRead(): void
    {
        $adminId = $this->requireAdminId();
        $ok = $this->model->markAllAsRead($adminId);

        if ($ok) {
            $this->notificationService->invalidateUnreadCache($adminId);
        }

        $this->response->json([
            'success' => $ok,
            'message' => $ok ? 'همه خوانده شدند' : 'عملیات ناموفق بود',
        ], $ok ? 200 : 400);
    }
}
