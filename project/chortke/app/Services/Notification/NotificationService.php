<?php

declare(strict_types=1);

namespace App\Services\Notification;

use App\Contracts\LoggerInterface;
use App\Contracts\NotificationServiceInterface;
use App\Services\Settings\AppSettings;
use Core\RateLimiter;
use Core\EventDispatcher;
use App\Models\Notification;

/**
 * NotificationService - Lean Orchestrator
 * 
 * این سرویس پس از ریفکتور، فقط مسئولیت هماهنگی ارسال را دارد.
 * کارهای سنگین رهگیری (Tracking) و آمار (Analytics) به DomainActivityListener منتقل شده است.
 */
/**
 * @phpstan-type NotificationPayload array<string, mixed>
 * @phpstan-type NotificationRows array<string, mixed>
 * @phpstan-type NotificationUserIds list<int>
 */
class NotificationService implements NotificationServiceInterface
{
    private \App\Contracts\LoggerInterface $logger;
    private NotificationPolicyService $policyService;
    private Notification $model;
    private NotificationTracker $tracker;
    private NotificationJobFactory $jobFactory;
    private NotificationPreferenceService $prefService;

    public function __construct(
        \App\Contracts\LoggerInterface $logger,
        NotificationPolicyService $policyService,
        Notification $model,
        NotificationTracker $tracker,
        NotificationJobFactory $jobFactory,
        NotificationPreferenceService $prefService
    ) {        $this->logger = $logger;
        $this->policyService = $policyService;
        $this->model = $model;
        $this->tracker = $tracker;
        $this->jobFactory = $jobFactory;
        $this->prefService = $prefService;
}

    /**
     * ارسال هوشمند نوتیفیکیشن با بررسی ترجیحات و ریت‌لیمیت
     */
    /** @param NotificationPayload|null $data */
    public function send(
        int $userId,
        string $type,
        string $title,
        string $message,
        ?array $data = null,
        ?string $actionUrl = null,
        ?string $actionText = null,
        string $priority = 'normal',
        ?string $expiresAt = null,
        ?string $imageUrl = null,
        ?string $groupKey = null,
        ?string $scheduledAt = null
    ): ?int {
        $job = $this->jobFactory->makeSendJob();
        return $job->handle($userId, $type, $title, $message, $data, $actionUrl, $actionText, $priority, $expiresAt, $imageUrl, $groupKey, $scheduledAt);
    }

    /**
     * ارسال به کانال مشخص نوتیفیکیشن (نام عمومی برای مسیرهای کمکی).
     *
     * @param NotificationPayload|null $data
     */
    public function sendToChannel(
        int $userId,
        string $type,
        string $title,
        string $message,
        ?array $data = null,
        ?string $actionUrl = null,
        ?string $actionText = null,
        string $priority = 'normal',
        ?string $expiresAt = null
    ): ?int {
        return $this->send($userId, $type, $title, $message, $data, $actionUrl, $actionText, $priority, $expiresAt);
    }

    /**
     * Dispatch notification to a specific channel directly (used for fallbacks)
     */
    /** @param NotificationPayload|null $data */
    public function dispatch(
        string $channel,
        int $userId,
        string $title,
        string $message,
        ?array $data = null,
        ?string $imageUrl = null,
        ?string $actionUrl = null,
        ?string $actionText = null,
        string $priority = 'normal'
    ): bool {
        $job = $this->jobFactory->makeDispatchJob();
        return $job->handle($channel, $userId, $title, $message, $data, $imageUrl, $actionUrl, $actionText, $priority);
    }

    /**
     * ارسال انبوه به صورت بهینه
     */
    /**
         * @param NotificationUserIds $userIds
         * @param NotificationPayload $data
         */
    public function sendBulk(array $userIds, string $type, string $title, string $message, array $data = [], ?string $actionUrl = null): int
    {
        $job = $this->jobFactory->makeBulkJob();
        return $job->handle($userIds, $type, $title, $message, $data, $actionUrl);
    }


    /**
     * Handles DND adjustments for specific users.
     */
    private function resolveScheduledTime(int $userId, string $priority, ?string $scheduledAt): ?string
    {
        return $this->policyService->resolveScheduledTime($userId, $priority, $scheduledAt);
    }

    /**
     * Handles database archiving and unread counter resets.
     */
    /** @param NotificationPayload|null $data */
    private function persistInAppNotification(
        int $userId, string $type, string $title, string $message, ?array $data,
        ?string $actionUrl, ?string $actionText, string $priority, ?string $expiresAt,
        ?string $imageUrl, ?string $groupKey, ?string $scheduledAt
    ): ?int {
        try {
            if (!$this->policyService->canSendInApp($userId, $type)) {
                return null;
            }

            $dataArr = is_array($data) ? $data : [];
            $adId = int_value($dataArr['ad_id'] ?? 0);
            $campaignId = int_value($dataArr['campaign_id'] ?? 0);

            $notifId = $this->model->create([
                'user_id'      => $userId,
                'type'         => $type,
                'title'        => $title,
                'message'      => $message,
                'data'         => $data,
                'action_url'   => $actionUrl,
                'action_text'  => $actionText,
                'priority'     => $priority,
                'expires_at'   => $expiresAt,
                'image_url'    => $imageUrl,
                'group_key'    => $groupKey ?? $type,
                'channel'      => Notification::CHANNEL_IN_APP,
                'scheduled_at' => $scheduledAt,
                'ad_id'        => $adId > 0 ? $adId : null,
                'campaign_id'  => $campaignId > 0 ? $campaignId : null,
            ]) ?: null;

            if ($notifId && $scheduledAt === null) {
                $this->tracker->invalidateUnreadCache($userId);
            }

            return $notifId;
        } catch (\Throwable $e) {
            $this->logger->error('notif.in_app_failed', ['user_id' => $userId, 'error' => $e->getMessage()]);
            \App\Services\Sentry\SentryExceptionHandler::captureException($e, $userId, [
                'operation' => 'notification.sendInApp',
            ]);
            throw $e;
        }
    }

    public function getPreferences(int $userId): object
    {
        return $this->prefService->getPreferences($userId);
    }

    /** @param NotificationPayload $data */
    public function updatePreferences(int $userId, array $data): bool
    {
        return $this->prefService->updatePreferences($userId, $data);
    }

    private function checkRateLimit(int $userId): bool
    {
        return $this->policyService->checkRateLimit($userId);
    }

    /** @param NotificationPayload $vars */
    public function sendFromTemplate(
        int    $userId,
        string $templateKey,
        array  $vars       = [],
        string $priority   = Notification::PRIORITY_NORMAL,
        ?string $actionUrl = null,
        ?string $actionText= null,
        ?string $groupKey  = null,
        ?string $scheduledAt = null
    ): ?int {
        $job = $this->jobFactory->make(\App\Jobs\Notification\SendFromTemplateNotificationJob::class);
        return $job->handle($userId, $templateKey, $vars, $priority, $actionUrl, $actionText, $groupKey, $scheduledAt);
    }

    /**
         * @param NotificationPayload|null $data
         * @return NotificationPayload
         */
    public function sendToAll(
        string  $title,
        string  $message,
        string  $type       = Notification::TYPE_SYSTEM,
        ?string $actionUrl  = null,
        ?string $actionText = null,
        string  $priority   = Notification::PRIORITY_NORMAL,
        ?array  $data       = null,
        ?string $scheduledAt = null
    ): array {
        $job = $this->jobFactory->make(\App\Jobs\Notification\SendToAllNotificationJob::class);
        return $job->handle($title, $message, $type, $actionUrl, $actionText, $priority, $data, $scheduledAt);
    }


    /**
         * @param NotificationPayload|null $data
         * @param NotificationPayload $filters
         * @return NotificationPayload
         */
    public function sendToSegment(
        string  $segment,
        string  $title,
        string  $message,
        string  $type        = Notification::TYPE_SYSTEM,
        ?string $actionUrl   = null,
        ?string $actionText  = null,
        string  $priority    = Notification::PRIORITY_NORMAL,
        ?array  $data        = null,
        ?string $scheduledAt = null,
        array   $filters     = []
    ): array {
        $job = $this->jobFactory->make(\App\Jobs\Notification\SendToSegmentNotificationJob::class);
        return $job->handle($segment, $title, $message, $type, $actionUrl, $actionText, $priority, $data, $scheduledAt, $filters);
    }


    /**
     * 🚀 UPG-03: متد کمکی برای ثبت تک‌نوتیفیکیشن در پس‌زمینه با ارزیابی ریت‌لیمیت و زمان‌بندی (توسط Job فراخوانی می‌شود)
     */
    /** @param NotificationPayload|null $data */
    public function processSinglePersist(
        int $uid, string $type, string $title, string $message,
        ?array $data, ?string $actionUrl, ?string $actionText, string $priority, ?string $scheduledAt
    ): bool {
        $job = $this->jobFactory->make(\App\Jobs\Notification\ProcessSinglePersistNotificationJob::class);
        return $job->handle($uid, $type, $title, $message, $data, $actionUrl, $actionText, $priority, $scheduledAt);
    }

    /**
     * Public wrapper for single-user notification persistence.
     * Used by ProcessSinglePersistNotificationJob.
     * Delegates to the 3 private persistence methods.
     */
    /** @param NotificationPayload|null $data */
    public function persistSingleNotification(
        int $uid, string $type, string $title, string $message,
        ?array $data, ?string $actionUrl, ?string $actionText, string $priority, ?string $scheduledAt
    ): bool {
        if (!$this->checkRateLimit($uid)) {
            return false;
        }
        $resTime = $this->resolveScheduledTime($uid, $priority, $scheduledAt);
        return (bool) $this->persistInAppNotification(
            $uid, $type, $title, $message, $data,
            $actionUrl, $actionText, $priority, null, null, null, $resTime
        );
    }


    // --- Proxy Calls to Tracking Service ---

    /** @return list<\stdClass> */
    public function latest(int $userId, int $limit = 10): array
    {
        $job = $this->jobFactory->make(\App\Jobs\Notification\LatestNotificationJob::class);
        return $job->handle($userId, $limit);
    }

    /** @return list<\stdClass> */
    public function getUserNotifications(int $userId, bool $onlyUnread = false, int $limit = 20, int $offset = 0): array
    {
        $job = $this->jobFactory->make(\App\Jobs\Notification\GetUserNotificationsNotificationJob::class);
        return $job->handle($userId, $onlyUnread, $limit, $offset);
    }

    public function countUserNotifications(int $userId, bool $onlyUnread = false): int
    {
        $job = $this->jobFactory->make(\App\Jobs\Notification\CountUserNotificationsNotificationJob::class);
        return $job->handle($userId, $onlyUnread);
    }

    public function getUnreadCount(int $userId): int
    {
        $job = $this->jobFactory->make(\App\Jobs\Notification\GetUnreadCountNotificationJob::class);
        return $job->handle($userId);
    }

    public function markAsRead(int $notificationId, int $userId): bool
    {
        $job = $this->jobFactory->make(\App\Jobs\Notification\MarkAsReadNotificationJob::class);
        return $job->handle($notificationId, $userId);
    }

    public function markAllAsRead(int $userId): bool
    {
        $job = $this->jobFactory->make(\App\Jobs\Notification\MarkAllAsReadNotificationJob::class);
        return $job->handle($userId);
    }

    public function markAllAsReadCount(int $userId): int
    {
        $job = $this->jobFactory->make(\App\Jobs\Notification\MarkAllAsReadCountNotificationJob::class);
        return $job->handle($userId);
    }

    public function recordClick(int $notificationId, int $userId): bool
    {
        $job = $this->jobFactory->make(\App\Jobs\Notification\RecordClickNotificationJob::class);
        return $job->handle($notificationId, $userId);
    }

    public function archive(int $notificationId, int $userId): bool
    {
        $job = $this->jobFactory->make(\App\Jobs\Notification\ArchiveNotificationJob::class);
        return $job->handle($notificationId, $userId);
    }

    public function softDelete(int $notificationId, int $userId): bool
    {
        $job = $this->jobFactory->make(\App\Jobs\Notification\SoftDeleteNotificationJob::class);
        return $job->handle($notificationId, $userId);
    }

    public function invalidateUnreadCache(int $userId): void
    {
        $job = $this->jobFactory->make(\App\Jobs\Notification\InvalidateUnreadCacheNotificationJob::class);
        $job->handle($userId);
    }

    /** @return NotificationRows */
    /** @return array{success: bool, notifications: list<\stdClass>, unread_count: int, last_id?: int} */
    public function getNewNotifications(int $userId, int $lastId, int $limit = 20): array
    {
        $job = $this->jobFactory->make(\App\Jobs\Notification\GetNewNotificationsNotificationJob::class);
        return $job->handle($userId, $lastId, $limit);
    }

    // متدهای مدیریت ترجیحات (Preferences) به NotificationPreferenceService منتقل شدند.

    // --- Proxy Calls to Template Service ---

    /** @return NotificationPayload */
    public function getTemplate(string $templateKey): array
    {
        $job = $this->jobFactory->make(\App\Jobs\Notification\GetTemplateNotificationJob::class);
        return $job->handle($templateKey);
    }

    /**
         * @param NotificationPayload $vars
         * @return NotificationPayload
         */
    public function renderTemplate(string $templateKey, array $vars = []): array
    {
        $job = $this->jobFactory->make(\App\Jobs\Notification\RenderTemplateNotificationJob::class);
        return $job->handle($templateKey, $vars);
    }

    /** @return NotificationRows */
    public function getAllTemplatesWithVariables(): array
    {
        $job = $this->jobFactory->make(\App\Jobs\Notification\GetAllTemplatesWithVariablesNotificationJob::class);
        return $job->handle();
    }

    public function saveTemplateOverride(string $key, string $title, string $message): bool
    {
        $job = $this->jobFactory->make(\App\Jobs\Notification\SaveTemplateOverrideNotificationJob::class);
        return $job->handle($key, $title, $message);
    }

    public function deleteTemplateOverride(string $key): bool
    {
        $job = $this->jobFactory->make(\App\Jobs\Notification\DeleteTemplateOverrideNotificationJob::class);
        return $job->handle($key);
    }

    // متدهای مربوط به آمار (Analytics) به NotificationAnalyticsService منتقل شدند.

    // --- Common/Shortcut Methods ---

    // متد saveUserToken به FcmService منتقل شد.

    public function findForUser(int $notificationId, int $userId): ?\stdClass
    {
        $job = $this->jobFactory->make(\App\Jobs\Notification\FindForUserNotificationJob::class);
        return $job->handle($notificationId, $userId);
    }

    /**
         * @param NotificationPayload $filters
         * @return NotificationRows
         */
    public function getUsersBySegment(string $segment, array $filters = []): array
    {
        $job = $this->jobFactory->make(\App\Jobs\Notification\GetUsersBySegmentNotificationJob::class);
        return $job->handle($segment, $filters);
    }

    /** @return array<string, mixed> */
    public function getAvailableSegments(): array
    {
        $job = $this->jobFactory->make(\App\Jobs\Notification\GetAvailableSegmentsNotificationJob::class);
        return $job->handle();
    }

    // --- Helpers & Specialized Send Handlers ---

    public function depositSuccess(int $userId, string $amount, string $currency): ?int
    {
        $job = $this->jobFactory->make(\App\Jobs\Notification\DepositSuccessNotificationJob::class);
        return $job->handle($userId, (float)$amount, $currency);
    }

    public function withdrawalApproved(int $userId, string $amount, string $currency): ?int
    {
        $job = $this->jobFactory->make(\App\Jobs\Notification\WithdrawalApprovedNotificationJob::class);
        return $job->handle($userId, $amount, $currency);
    }

    public function withdrawalRejected(int $userId, string $amount, string $reason): ?int
    {
        $job = $this->jobFactory->make(\App\Jobs\Notification\WithdrawalRejectedNotificationJob::class);
        return $job->handle($userId, $amount, $reason);
    }

    public function kycVerified(int $userId): ?int
    {
        $job = $this->jobFactory->make(\App\Jobs\Notification\KycVerifiedNotificationJob::class);
        return $job->handle($userId);
    }

    public function kycRejected(int $userId, string $reason): ?int
    {
        $job = $this->jobFactory->make(\App\Jobs\Notification\KycRejectedNotificationJob::class);
        return $job->handle($userId, $reason);
    }

    public function securityAlert(int $userId, string $message, string $ip): ?int
    {
        $job = $this->jobFactory->make(\App\Jobs\Notification\SecurityAlertNotificationJob::class);
        return $job->handle($userId, $message, $ip);
    }

    /** @param NotificationPayload|null $data */
    public function sendToAdmins(string $type, string $title, string $message, ?array $data = null, string $priority = 'normal'): int
    {
        $job = $this->jobFactory->make(\App\Jobs\Notification\SendToAdminsNotificationJob::class);
        return $job->handle($type, $title, $message, $data, $priority);
    }


    public function newTaskAvailable(int $userId, string $taskTitle): ?int
    {
        $job = $this->jobFactory->make(\App\Jobs\Notification\NewTaskAvailableNotificationJob::class);
        return $job->handle($userId, $taskTitle);
    }

    public function lotteryWinner(int $userId, float $amount): ?int
    {
        $job = $this->jobFactory->make(\App\Jobs\Notification\LotteryWinnerNotificationJob::class);
        return $job->handle($userId, $amount);
    }

    public function referralEarning(int $userId, float $amount, string $referredUserName): ?int
    {
        $job = $this->jobFactory->make(\App\Jobs\Notification\ReferralEarningNotificationJob::class);
        return $job->handle($userId, $amount, $referredUserName);
    }

    public function investmentCompleted(int $userId, float $profit, float $total): ?int
    {
        $job = $this->jobFactory->make(\App\Jobs\Notification\InvestmentCompletedNotificationJob::class);
        return $job->handle($userId, $profit, $total);
    }

    /** @param NotificationUserIds $userIds */
    public function prefetchPreferences(array $userIds): void
    {
        $job = $this->jobFactory->make(\App\Jobs\Notification\PrefetchPreferencesNotificationJob::class);
        $job->handle($userIds);
    }

    /**
     * آمار کلی notification analytics
     * تفویض به NotificationAnalyticsService که سرویس تخصصی analytics است.
     * @return array<string, mixed>
     */
    public function getAnalyticsOverview(int $days = 30): array
    {
        try {
            /** @var \App\Services\Notification\NotificationAnalyticsService $analytics */
            $analytics = app(\App\Services\Notification\NotificationAnalyticsService::class);
            return $analytics->getAnalyticsOverview($days);
        } catch (\Throwable $e) {
            \App\Services\Sentry\SentryExceptionHandler::captureException($e, null, ['operation' => 'app.Services.Notification.NotificationService.getAnalyticsOverview']);
            $this->logger->warning('notification.analytics_overview.failed', ['error' => $e->getMessage()]);
            return [];
        }
    }

    /**
     * آمار funnel نوتیفیکیشن‌ها
     * @return array<string, mixed>
     */
    public function getAnalyticsFunnelStats(int $days = 30): array
    {
        try {
            /** @var \App\Services\Notification\NotificationAnalyticsService $analytics */
            $analytics = app(\App\Services\Notification\NotificationAnalyticsService::class);
            return $analytics->getAnalyticsFunnelStats($days);
        } catch (\Throwable $e) {
            \App\Services\Sentry\SentryExceptionHandler::captureException($e, null, ['operation' => 'app.Services.Notification.NotificationService.getAnalyticsFunnelStats']);
            $this->logger->warning('notification.analytics_funnel.failed', ['error' => $e->getMessage()]);
            return [];
        }
    }

    /**
     * ثبت رویداد نمایش نوتیف روی گوشی (از سمت اپ)
     */
    public function recordShown(int $notificationId, int $userId, ?string $source = null): bool
    {
        return $this->model->recordShown($notificationId, $userId, $source);
    }

    /**
     * ثبت رویداد باز شدن / شروع خواندن
     */
    public function recordOpened(int $notificationId, int $userId, ?string $source = null): bool
    {
        return $this->model->recordOpened($notificationId, $userId, $source);
    }

    /**
     * ثبت رویداد بسته شدن + مدت خواندن
     */
    public function recordClosed(int $notificationId, int $userId, ?int $durationSec = null): bool
    {
        return $this->model->recordClosed($notificationId, $userId, $durationSec);
    }

    /**
     * ثبت رویداد بسته شدن بدون تعامل (dismiss)
     */
    public function recordDismissed(int $notificationId, int $userId): bool
    {
        return $this->model->recordDismissed($notificationId, $userId);
    }

    /**
     * کرون ساعتیِ batch aggregation آمار نوتیفیکیشن (job: notification_analytics).
     * نتیجه در جدول notification_analytics ذخیره می‌شود و توسط پنل ادمین مصرف می‌شود.
     * @return array<string, int>
     */
    public function runBatchAggregation(): array
    {
        try {
            $result = $this->model->aggregateDailyAnalytics();
            $this->logger->info('notification.analytics.aggregated', $result);
            return $result;
        } catch (\Throwable $e) {
            $this->logger->error('notification.analytics.aggregation_failed', ['error' => $e->getMessage()]);
            \App\Services\Sentry\SentryExceptionHandler::captureException($e, null, [
                'operation' => 'notification.runBatchAggregation',
            ]);
            return ['sent' => 0, 'updated' => 0];
        }
    }
}
