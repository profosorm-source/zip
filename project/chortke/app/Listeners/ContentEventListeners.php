<?php

declare(strict_types=1);

namespace App\Listeners;

use Core\Event;
use App\Contracts\LoggerInterface;
use App\Services\Gamification\XpService;
use App\Services\Shared\ReferralService;
use App\Services\Notification\NotificationService;
use App\Services\Cache\CacheInvalidationService;
use App\Enums\ModuleContext;
use App\Contracts\OutboxServiceInterface;

/**
 * Content Event Listeners
 *
 * مجموعه‌ای از Listener‌های محتوایی برای پاسخ‌دهی به رویدادهای مختلف سیکل حیات محتوا
 * شامل: تایید، رد، انتشار، ثبت درآمد و پرداخت
 *
 * یکی از خروجی‌های Event-Driven Decoupling است که وابستگی‌های ContentService را کاهش می‌دهد
 *
 * @package App\Listeners
 */
class ContentEventListeners
{
    protected LoggerInterface $logger;
    protected XpService $xpService;
    protected ReferralService $referralService;
    protected NotificationService $notificationService;
    protected CacheInvalidationService $cacheInvalidationService;
    protected ?OutboxServiceInterface $outbox;
    public function __construct(
        LoggerInterface $logger,
        XpService $xpService,
        ReferralService $referralService,
        NotificationService $notificationService,
        CacheInvalidationService $cacheInvalidationService,
        ?OutboxServiceInterface $outbox = null
    ) {
        $this->logger = $logger;
        $this->xpService = $xpService;
        $this->referralService = $referralService;
        $this->notificationService = $notificationService;
        $this->cacheInvalidationService = $cacheInvalidationService;
        $this->outbox = $outbox;
    }

    /**
     * ContentApprovedListener
     *
     * وقتی محتوا تایید می‌شود:
     * - XP به کاربر اعطا می‌شود
     * - بونوس Referral بررسی می‌شود
     * - کاربر مطلع می‌شود
     */
    public function handleContentApproved(Event $event): void
    {
        try {
            $data = $event->getData();
            $submissionId = $data['submission_id'] ?? null;
            $userId = $data['user_id'] ?? null;

            if (!$submissionId || !$userId) {
                $this->logger->warning('content.approved.invalid_data', ['data' => $data]);
                return;
            }

            $this->logger->info('content.approved.started', [
                'submission_id' => $submissionId,
                'user_id' => $userId
            ]);

            // 1. اعطای XP به کاربر
            $this->awardContentApprovalXp(int_value($userId), int_value($submissionId));

            // 2. بررسی بونوس Referral
            $this->processReferralBonus(int_value($userId), int_value($submissionId));

            // 3. اطلاع‌رسانی به کاربر
            $this->notifyContentApproved(int_value($userId), int_value($submissionId));

            // 4. باطل‌سازی کش جستجو
            $this->invalidateContentCache();

            $this->logger->info('content.approved.completed', [
                'submission_id' => $submissionId,
                'user_id' => $userId
            ]);

        } catch (\Throwable $e) {
            $this->logger->error('content.approved.failed', [
                'error' => $e->getMessage(),
                'exception' => get_class($e),
                'trace' => $e->getTraceAsString()
            ]);
            \App\Services\Sentry\SentryExceptionHandler::captureException($e, null, ['operation' => 'content_listener.handleContentApproved']);
        }
    }

    /**
     * ContentRejectedListener
     *
     * وقتی محتوا رد می‌شود:
     * - کاربر مطلع می‌شود
     */
    public function handleContentRejected(Event $event): void
    {
        try {
            $data = $event->getData();
            $submissionId = $data['submission_id'] ?? null;
            $userId = $data['user_id'] ?? null;
            $reason = $data['reason'] ?? '';

            if (!$submissionId || !$userId) {
                $this->logger->warning('content.rejected.invalid_data', ['data' => $data]);
                return;
            }

            $this->logger->info('content.rejected.started', [
                'submission_id' => $submissionId,
                'user_id' => $userId
            ]);

            // اطلاع‌رسانی به کاربر
            $this->notifyContentRejected(int_value($userId), int_value($submissionId), str_value($reason));

            $this->logger->info('content.rejected.completed', [
                'submission_id' => $submissionId,
                'user_id' => $userId
            ]);

        } catch (\Throwable $e) {
            $this->logger->error('content.rejected.failed', [
                'error' => $e->getMessage(),
                'exception' => get_class($e),
                'trace' => $e->getTraceAsString()
            ]);
            \App\Services\Sentry\SentryExceptionHandler::captureException($e, null, ['operation' => 'content_listener.handleContentRejected']);
        }
    }

    /**
     * ContentPublishedListener
     *
     * وقتی محتوا منتشر می‌شود:
     * - کش جستجو باطل می‌شود
     * - اطلاع‌رسانی صورت می‌گیرد
     */
    public function handleContentPublished(Event $event): void
    {
        try {
            $data = $event->getData();
            $submissionId = $data['submission_id'] ?? null;
            $userId = $data['user_id'] ?? null;

            if (!$submissionId || !$userId) {
                $this->logger->warning('content.published.invalid_data', ['data' => $data]);
                return;
            }

            $this->logger->info('content.published.started', [
                'submission_id' => $submissionId,
                'user_id' => $userId
            ]);

            // باطل‌سازی کش جستجو
            $this->invalidateContentCache();

            // اطلاع‌رسانی
            $this->notifyContentPublished(int_value($userId), int_value($submissionId));

            $this->logger->info('content.published.completed', [
                'submission_id' => $submissionId,
                'user_id' => $userId
            ]);

        } catch (\Throwable $e) {
            $this->logger->error('content.published.failed', [
                'error' => $e->getMessage(),
                'exception' => get_class($e),
                'trace' => $e->getTraceAsString()
            ]);
            \App\Services\Sentry\SentryExceptionHandler::captureException($e, null, ['operation' => 'content_listener.handleContentPublished']);
        }
    }

    /**
     * ContentRevenueRecordedListener
     *
     * وقتی درآمد محتوا ثبت می‌شود:
     * - Audit trail ثبت می‌شود
     */
    public function handleContentRevenueRecorded(Event $event): void
    {
        try {
            $data = $event->getData();
            $submissionId = $data['submission_id'] ?? null;
            $userId = $data['user_id'] ?? null;
            $revenueId = $data['revenue_id'] ?? null;
            $period = $data['period'] ?? null;

            if (!$submissionId || !$userId || !$revenueId) {
                $this->logger->warning('content.revenue_recorded.invalid_data', ['data' => $data]);
                return;
            }

            $this->logger->info('content.revenue_recorded.completed', [
                'submission_id' => $submissionId,
                'revenue_id' => $revenueId,
                'user_id' => $userId,
                'period' => $period
            ]);

        } catch (\Throwable $e) {
            $this->logger->error('content.revenue_recorded.failed', [
                'error' => $e->getMessage(),
                'exception' => get_class($e),
                'trace' => $e->getTraceAsString()
            ]);
            \App\Services\Sentry\SentryExceptionHandler::captureException($e, null, ['operation' => 'content_listener.handleContentRevenueRecorded']);
        }
    }

    /**
     * ContentRevenuePaymentRecordedListener (PRIMARY / NOTIFICATION_ONLY)
     *
     * پرداخت درآمد محتوا از فاز ۲ به بعد داخل ContentService::payRevenue به‌صورت مستقیم،
     * اتمیک و idempotent انجام می‌شود. بنابراین Listener فقط side-effect غیرمالی
     * مثل اطلاع‌رسانی را انجام می‌دهد و هیچ واریزی به کیف پول نمی‌زند.
     */
    public function handleContentRevenuePaymentRecorded(Event $event): void
    {
        try {
            $data = $event->getData();
            $revenueId = int_value($data['revenue_id'] ?? 0);
            $userId = int_value($data['user_id'] ?? 0);
            $amount = float_value($data['amount'] ?? 0);

            if ($revenueId <= 0 || $userId <= 0 || $amount <= 0) {
                $this->logger->warning('content.revenue_payment_recorded.invalid_data', ['data' => $data]);
                return;
            }

            $this->notifyContentRevenuePaid($userId, $amount);
            $this->logger->info('content.revenue_payment_recorded.notification_sent', [
                'revenue_id' => $revenueId,
                'user_id' => $userId,
                'amount' => $amount,
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('content.revenue_payment_recorded.failed', [
                'error' => $e->getMessage(),
                'exception' => get_class($e),
                'trace' => $e->getTraceAsString(),
            ]);
            \App\Services\Sentry\SentryExceptionHandler::captureException($e, null, ['operation' => 'content_listener.handleRevenuePaymentRecorded']);
        }
    }

    /**
     * DEPRECATED_REMOVE / LEGACY_EVENT
     *
     * نام قدیمی event پرداخت درآمد محتوا. این handler عمداً هیچ واریزی انجام نمی‌دهد
     * تا اگر event قدیمی دوباره ثبت/dispatch شد، باعث double deposit نشود.
     * مسیر مالی معتبر فقط ContentService::payRevenue است.
     */
    public function handleContentRevenuePaid(Event $event): void
    {
        $data = $event->getData();
        $this->logger->warning('content.revenue_paid.legacy_event_financial_side_effect_skipped', [
            'revenue_id' => $data['revenue_id'] ?? null,
            'user_id' => $data['user_id'] ?? null,
            'amount' => $data['amount'] ?? null,
            'replacement_event' => 'content.revenue.payment_recorded',
            'payment_authority' => 'ContentService::payRevenue',
        ]);

        $this->handleContentRevenuePaymentRecorded($event);
    }

    // ──────────────────────────────────────────────────────────
    // HELPER METHODS
    // ──────────────────────────────────────────────────────────

    /**
     * اعطای XP برای تایید محتوا
     */
    private function awardContentApprovalXp(int $userId, int $submissionId): void
    {
        try {
            // 50 XP برای تایید محتوا
            $baseXp = 50.0;
            $idempotencyKey = "content_approved_{$submissionId}";

            $success = $this->xpService->award(
                userId: $userId,
                context: ModuleContext::CONTENT,
                baseXp: $baseXp,
                idempotencyKey: $idempotencyKey
            );

            if ($success) {
                $this->logger->info('content.xp_awarded', [
                    'user_id' => $userId,
                    'xp' => $baseXp,
                    'submission_id' => $submissionId
                ]);
            } else {
                $this->logger->warning('content.xp_award_failed', [
                    'user_id' => $userId,
                    'submission_id' => $submissionId
                ]);
            }
        } catch (\Throwable $e) {
            $this->logger->error('content.xp_award_exception', [
                'user_id' => $userId,
                'submission_id' => $submissionId,
                'error' => $e->getMessage()
            ]);
            \App\Services\Sentry\SentryExceptionHandler::captureException($e, $userId, ['operation' => 'content_listener.awardXp']);
        }
    }

    /**
     * پردازش بونوس Referral
     */
    private function processReferralBonus(int $userId, int $submissionId): void
    {
        try {
            $referralResult = $this->referralService->checkAndAwardBonus(
                userId: $userId,
                context: 'content_approval',
                referenceId: $submissionId
            );

            if ($referralResult) {
                $this->logger->info('content.referral_bonus_awarded', [
                    'user_id' => $userId,
                    'submission_id' => $submissionId
                ]);
            }
        } catch (\Throwable $e) {
            $this->logger->error('content.referral_bonus_exception', [
                'user_id' => $userId,
                'submission_id' => $submissionId,
                'error' => $e->getMessage()
            ]);
            \App\Services\Sentry\SentryExceptionHandler::captureException($e, $userId, ['operation' => 'content_listener.referralBonus']);
        }
    }

    /**
     * اطلاع‌رسانی تایید محتوا
     */
    private function notifyContentApproved(int $userId, int $submissionId): void
    {
        try {
            $this->notificationService->send(
                userId: $userId,
                type: 'content_approved',
                title: 'محتوای شما تایید شد',
                message: 'محتوای ارسالی شما توسط تیم مدیریت تایید شده است.',
                data: [
                    'submission_id' => $submissionId,
                    'action_url' => "/content/{$submissionId}"
                ]
            );

            $this->logger->info('content.approved_notification_sent', [
                'user_id' => $userId,
                'submission_id' => $submissionId
            ]);
        } catch (\Throwable $e) {
            \App\Services\Sentry\SentryExceptionHandler::captureException($e, null, ['operation' => 'app.Listeners.ContentEventListeners.notifyContentApproved']);
            $this->logger->error('content.approved_notification_exception', [
                'user_id' => $userId,
                'submission_id' => $submissionId,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * اطلاع‌رسانی رد محتوا
     */
    private function notifyContentRejected(int $userId, int $submissionId, string $reason): void
    {
        try {
            $this->notificationService->send(
                userId: $userId,
                type: 'content_rejected',
                title: 'محتوای شما رد شد',
                message: "دلیل رد: {$reason}",
                data: [
                    'submission_id' => $submissionId,
                    'reason' => $reason
                ]
            );

            $this->logger->info('content.rejected_notification_sent', [
                'user_id' => $userId,
                'submission_id' => $submissionId
            ]);
        } catch (\Throwable $e) {
            \App\Services\Sentry\SentryExceptionHandler::captureException($e, null, ['operation' => 'app.Listeners.ContentEventListeners.notifyContentRejected']);
            $this->logger->error('content.rejected_notification_exception', [
                'user_id' => $userId,
                'submission_id' => $submissionId,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * اطلاع‌رسانی انتشار محتوا
     */
    private function notifyContentPublished(int $userId, int $submissionId): void
    {
        try {
            $this->notificationService->send(
                userId: $userId,
                type: 'content_published',
                title: 'محتوای شما منتشر شد',
                message: 'محتوای شما در کانال‌های پلتفرم منتشر شده است.',
                data: [
                    'submission_id' => $submissionId,
                    'action_url' => "/content/{$submissionId}"
                ]
            );

            $this->logger->info('content.published_notification_sent', [
                'user_id' => $userId,
                'submission_id' => $submissionId
            ]);
        } catch (\Throwable $e) {
            \App\Services\Sentry\SentryExceptionHandler::captureException($e, null, ['operation' => 'app.Listeners.ContentEventListeners.notifyContentPublished']);
            $this->logger->error('content.published_notification_exception', [
                'user_id' => $userId,
                'submission_id' => $submissionId,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * اطلاع‌رسانی پرداخت درآمد
     */
    private function notifyContentRevenuePaid(int $userId, float $amount): void
    {
        try {
            $this->notificationService->send(
                userId: $userId,
                type: 'content_revenue_paid',
                title: 'درآمد محتوای شما پرداخت شد',
                message: "مبلغ {$amount} تومان به کیف پول شما واریز شده است.",
                data: [
                    'amount' => $amount,
                    'action_url' => '/wallet'
                ]
            );

            $this->logger->info('content.revenue_paid_notification_sent', [
                'user_id' => $userId,
                'amount' => $amount
            ]);
        } catch (\Throwable $e) {
            \App\Services\Sentry\SentryExceptionHandler::captureException($e, null, ['operation' => 'app.Listeners.ContentEventListeners.notifyContentRevenuePaid']);
            $this->logger->error('content.revenue_paid_notification_exception', [
                'user_id' => $userId,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * باطل‌سازی کش محتوا
     */
    private function invalidateContentCache(): void
    {
        try {
            $this->cacheInvalidationService->invalidateModuleSearch('content');
            $this->logger->info('content.cache_invalidated');
        } catch (\Throwable $e) {
            \App\Services\Sentry\SentryExceptionHandler::captureException($e, null, ['operation' => 'app.Listeners.ContentEventListeners.invalidateContentCache']);
            $this->logger->error('content.cache_invalidation_exception', [
                'error' => $e->getMessage()
            ]);
        }
    }

}
