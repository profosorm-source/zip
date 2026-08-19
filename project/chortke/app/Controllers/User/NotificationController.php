<?php

namespace App\Controllers\User;

use App\Controllers\User\BaseUserController;
use App\Services\Notification\NotificationService;
use App\Services\Notification\FcmService;

class NotificationController extends BaseUserController
{
    private NotificationService $notificationService;
    private FcmService $fcmService;
    private \App\Services\Ads\AdsBudgetSettlementService $adsBudgetSettlement;

    public function __construct(
        NotificationService $notificationService,
        \Core\Session $session,
        \Core\Request $request,
        \Core\Response $response,
        \App\Services\Shared\PolicyService $policyService,
        \App\Contracts\LoggerInterface $logger,
        \App\Services\Auth\AuthService $authService,
        \App\Services\User\UserService $userService,
        \App\Services\CaptchaService $captchaService,
        \App\Services\Ads\AdsBudgetSettlementService $adsBudgetSettlement,
        FcmService $fcmService
    ) {
        parent::__construct($session, $request, $response, $policyService, $logger, $authService, $userService, $captchaService);
        $this->adsBudgetSettlement = $adsBudgetSettlement;
        $this->notificationService = $notificationService;
        $this->fcmService = $fcmService;
    }

    // =========================================================================
    // صفحات
    // =========================================================================

    /**
     * لیست نوتیفیکیشن‌ها
     */
    public function index(): void
    {
        $this->requireAuth();

        $userId = (int)$this->userId();
        $page   = max(1, $this->request->int('page', 1));
        $limit  = 20;
        $offset = ($page - 1) * $limit;

        $notifications = $this->notificationService->getUserNotifications($userId, false, $limit, $offset);
        $totalCount    = $this->notificationService->countUserNotifications($userId);
        $unreadCount   = $this->notificationService->getUnreadCount($userId);

        $this->view('user/notifications/index', [
            'notifications' => $notifications,
            'unread_count'  => $unreadCount,
            'total_count'   => $totalCount,
            'current_page'  => $page,
            'per_page'      => $limit,
            'total_pages'   => (int)ceil($totalCount / $limit),
        ]);
    }

    /**
     * صفحه تنظیمات نوتیفیکیشن
     */
    public function preferences(): void
    {
        $this->requireAuth();

        $userId = (int)$this->userId();
        $prefs  = $this->notificationService->getPreferences($userId);

        $this->view('user/notifications/preferences', [
            'preferences' => $prefs,
        ]);
    }

    // =========================================================================
    // Ajax — Long Polling
    // =========================================================================

    /**
     * Long Polling — request باز می‌ماند تا نوتیف جدید بیاید یا timeout
     *
     * Client باید:
     *  1. GET /notifications/poll?last_id=<آخرین ID دیده‌شده>
     *  2. بعد از response → ۱–۲ ثانیه صبر → دوباره connect
     */
    public function poll(): void
    {
        $this->requireAuth();

        $userId = (int)$this->userId();
        $lastId   = $this->request->int('last_id');
        $timeout  = 30;
        $interval = 2;
        $waited   = 0;

        set_time_limit($timeout + 10);
        ignore_user_abort(false);

        $new = $this->notificationService->getNewNotifications($userId, $lastId);
        if (!empty($new['notifications'])) {
            $this->response->json($new);
        }

        while ($waited < $timeout) {
            sleep($interval);
            $waited += $interval;

            if (connection_aborted()) {
                exit;
            }

            $new = $this->notificationService->getNewNotifications($userId, $lastId);
            if (!empty($new['notifications'])) {
                $this->response->json($new);
            }
        }

        $this->response->json([
            'success'       => true,
            'notifications' => [],
            'unread_count'  => $this->notificationService->getUnreadCount($userId),
            'timeout'       => true,
        ]);
    }

    /**
     * دریافت نوتیفیکیشن‌ها (Ajax — بدون long poll)
     */
    public function get(): void
    {
        $this->requireAuth();

        $userId = (int)$this->userId();
        $onlyUnread = $this->request->input('unread') === 'true';
        $limit      = max(1, min(50, $this->request->int('limit', 20)));

        $notifications = $this->notificationService->getUserNotifications($userId, $onlyUnread, $limit);
        $unreadCount   = $this->notificationService->getUnreadCount($userId);

        $this->response->json([
            'success'       => true,
            'notifications' => $notifications,
            'unread_count'  => $unreadCount,
        ]);
    }

    /**
     * تعداد خوانده‌نشده (برای badge)
     */
    public function unreadCount(): void
    {
        $this->requireAuth();

        $userId = (int)$this->userId();
        $count  = $this->notificationService->getUnreadCount($userId);

        $this->response->json([
            'success' => true,
            'count'   => $count,
        ]);
    }

    // =========================================================================
    // Ajax — Actions
    // =========================================================================

    /**
     * علامت‌گذاری به عنوان خوانده‌شده + پاک‌کردن cache
     */
    public function markAsRead(): void
    {
        $this->requireAuth();

        $notificationId = $this->request->int('notification_id') ?: $this->request->int('id') ?: (int)($this->request->param('id') ?? 0);
        $userId = (int)$this->userId();

        $result = $this->notificationService->markAsRead($notificationId, $userId);

        $this->response->json([
            'success'      => $result,
            'unread_count' => $this->notificationService->getUnreadCount($userId),
            'message'      => $result ? 'علامت‌گذاری شد' : 'خطا در علامت‌گذاری',
        ]);
    }

    /**
     * علامت خواندن همه
     */
    public function markAllAsRead(): void
    {
        $this->requireAuth();

        $userId = (int)$this->userId();

        $count = $this->notificationService->markAllAsReadCount($userId);

        $this->response->json([
            'success'      => true,
            'count'        => $count,
            'unread_count' => 0,
            'message'      => $count > 0 ? "{$count} نوتیفیکیشن خوانده شد" : 'هیچ نوتیفیکیشنی برای خواندن وجود نداشت',
        ]);
    }

    /**
     * ثبت کلیک (analytics) + redirect
     */
    public function click(): void
    {
        $this->requireAuth();

        $notifId = $this->request->int('notification_id');
        $userId = (int)$this->userId();

        $notif = $this->notificationService->findForUser($notifId, $userId);

        if ($notif) {
            $this->notificationService->recordClick($notifId, $userId);
            $this->trackAdNotificationClick($notif, $notifId, $userId);

            if (!$notif->is_read) {
                $this->notificationService->markAsRead($notifId, $userId);
            }

            $isMobile = ($this->session->get('notif_source') === 'mobile') || (str_value($this->request->input('source') ?? $this->request->param('source') ?? '') === 'mobile');
            if ($isMobile) {
                $mobileScheme = str_value(config('app.mobile.scheme', 'chortke'));
                $actionUrl = !empty($notif->action_url) ? $notif->action_url : "{$mobileScheme}://notifications/index";
                $deepLink = str_starts_with($actionUrl, "{$mobileScheme}://") ? $actionUrl : "{$mobileScheme}://notifications/redirect?url=" . urlencode($actionUrl);
                $this->response->redirect($deepLink);
                return;
            }

            if (!empty($notif->action_url)) {
                $this->response->redirect($notif->action_url);
            }
        }

        $this->response->redirect(url('/notifications'));
    }

    /**
     * ثبت رویداد نمایش نوتیف روی گوشی (ارسال‌شده توسط اپ موبایل)
     * POST /notifications/events/shown
     */
    public function eventShown(): void
    {
        $this->requireAuth();
        $notifId = $this->request->int('notification_id');
        $userId  = (int)$this->userId();
        $source  = $this->request->input('source') !== null && is_scalar($this->request->input('source'))
            ? (string)$this->request->input('source') : null;

        $ok = $this->notificationService->recordShown($notifId, $userId, $source);
        $this->settleAdNotificationReward($notifId, $userId, 'delivery');
        $this->response->json(['success' => $ok, 'notification_id' => $notifId]);
    }

    /**
     * ثبت رویداد باز شدن / شروع خواندن
     * POST /notifications/events/opened
     */
    public function eventOpened(): void
    {
        $this->requireAuth();
        $notifId = $this->request->int('notification_id');
        $userId  = (int)$this->userId();
        $source  = $this->request->input('source') !== null && is_scalar($this->request->input('source'))
            ? (string)$this->request->input('source') : null;

        $ok = $this->notificationService->recordOpened($notifId, $userId, $source);
        $this->settleAdNotificationReward($notifId, $userId, 'delivery');
        $this->response->json(['success' => $ok, 'notification_id' => $notifId]);
    }

    /**
     * ثبت رویداد بسته شدن + مدت خواندن (ثانیه)
     * POST /notifications/events/closed
     */
    public function eventClosed(): void
    {
        $this->requireAuth();
        $notifId   = $this->request->int('notification_id');
        $userId    = (int)$this->userId();
        $duration  = $this->request->input('duration_sec');
        $durationSec = is_numeric($duration) ? (int)$duration : null;

        $ok = $this->notificationService->recordClosed($notifId, $userId, $durationSec);
        $this->response->json(['success' => $ok, 'notification_id' => $notifId]);
    }

    /**
     * ثبت رویداد بسته شدن بدون تعامل (swipe away)
     * POST /notifications/events/dismissed
     */
    public function eventDismissed(): void
    {
        $this->requireAuth();
        $notifId = $this->request->int('notification_id');
        $userId  = (int)$this->userId();

        $ok = $this->notificationService->recordDismissed($notifId, $userId);
        $this->response->json(['success' => $ok, 'notification_id' => $notifId]);
    }

    /**
     * تسویه‌ی پاداشِ دیده‌شدن نوتیفیکیشن تبلیغاتی برای کاربرِ بیننده (اگر نوتیف به تبلیغ تعلق داشته باشد).
     */
    private function settleAdNotificationReward(int $notificationId, int $userId, string $eventType): void
    {
        try {
            if ($notificationId <= 0) {
                return;
            }
            $notif = $this->notificationService->findForUser($notificationId, $userId);
            if (!$notif) {
                return;
            }
            $data = [];
            if (!empty($notif->data)) {
                $decoded = is_array($notif->data) ? $notif->data : json_decode((string)$notif->data, true);
                $data = is_array($decoded) ? $decoded : [];
            }
            $adId = (int)($data['ad_id'] ?? 0);
            if ($adId <= 0) {
                return;
            }
            $this->adsBudgetSettlement->settleNotificationView($adId, $userId, $eventType, $notificationId);
        } catch (\Throwable $e) {
            $this->logger->warning('notification_ad.view_reward_failed', [
                'notification_id' => $notificationId,
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function trackAdNotificationClick(object $notification, int $notificationId, int $userId): void
    {
        try {
            $data = [];
            if (!empty($notification->data)) {
                $decoded = is_array($notification->data) ? $notification->data : json_decode((string)$notification->data, true);
                $data = is_array($decoded) ? $decoded : [];
            }
            $adId = (int)($data['ad_id'] ?? 0);
            if ($adId <= 0) {
                return;
            }
            $finance = $this->adsBudgetSettlement;
            // تسویه‌ی کلیک: هم بودجه‌ی تبلیغ‌دهنده مصرف و هم پاداش به بیننده واریز می‌شود
            $finance->settleNotificationView($adId, $userId, 'click', $notificationId);
        } catch (\Throwable $e) {
            $this->logger->warning('notification_ad.click_budget_failed', [
                'notification_id' => $notificationId,
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * آرشیو کردن
     */
    public function archive(): void
    {
        $this->requireAuth();

        $notificationId = $this->request->int('notification_id');
        $userId = (int)$this->userId();

        $result = $this->notificationService->archive($notificationId, $userId);

        $this->response->json([
            'success' => $result,
            'message' => $result ? 'آرشیو شد' : 'خطا در آرشیو',
        ]);
    }

    /**
     * حذف منطقی (soft delete)
     */
    public function delete(): void
    {
        $this->requireAuth();

        $notificationId = $this->request->int('notification_id');
        $userId = (int)$this->userId();

        $result = $this->notificationService->softDelete($notificationId, $userId);

        $this->response->json([
            'success' => $result,
            'message' => $result ? 'حذف شد' : 'خطا در حذف',
        ]);
    }

    /**
     * ذخیره FCM token (از service worker مرورگر)
     */
    public function saveFcmToken(): void
    {
        $this->requireAuth();

        $token    = $this->request->str('token');
        $platform = $this->request->str('platform', 'web');
        $userId = (int)$this->userId();

        if (empty($token)) {
            $this->response->json(['success' => false, 'message' => 'token الزامی است'], 400);
        }

        $saved = $this->fcmService->saveUserToken($userId, $token, $platform);
        $this->response->json(
            ['success'=>$saved,'message'=>$saved ? 'token ذخیره شد' : 'token یا platform نامعتبر است'],
            $saved ? 200 : 422
        );
    }

    /**
     * ذخیره تنظیمات
     */
    public function updatePreferences(): void
    {
        $this->requireAuth();

        $userId = (int)$this->userId();
        $data   = $this->request->all();

        $result = $this->notificationService->updatePreferences($userId, $data);

        $this->response->json([
            'success' => $result,
            'message' => $result ? 'تنظیمات ذخیره شد' : 'خطا در ذخیره تنظیمات',
        ]);
    }

}
