<?php

declare(strict_types=1);

namespace App\Services\Notification;

use App\Adapters\Notification\PushNotificationAdapter;
use App\Adapters\Notification\SmsNotificationAdapter;
use App\Adapters\Notification\FcmNotificationAdapter;
use App\Adapters\Notification\LogNotificationAdapter;
use App\Contracts\LoggerInterface;
use Core\EventDispatcher;
use Core\Queue;
use App\Events\NotificationChannelRequestedEvent;

/**
 * NotificationDispatcher — Channel Router برای ارسال نوتیفیکیشن‌ها
 */
class NotificationDispatcher
{
    /**
     * @var array<string, \App\Contracts\NotificationChannelInterface>
     */
    private array $channels = [];

    private \App\Contracts\LoggerInterface $logger;
    private \App\Services\Notification\Channels\PushChannel $pushChannel;
    private \App\Services\Notification\Channels\SmsChannel $smsChannel;
    private \App\Services\Notification\Channels\FcmChannel $fcmChannel;
    private \App\Services\Notification\Channels\LogChannel $logChannel;
    private NotificationPreferenceService $prefService;
    private NotificationRetryPolicy $retryPolicy;
    private \Core\Cache $cache;

    public function __construct(
        \App\Contracts\LoggerInterface $logger,
        \App\Services\Notification\Channels\PushChannel $pushChannel,
        \App\Services\Notification\Channels\SmsChannel $smsChannel,
        \App\Services\Notification\Channels\FcmChannel $fcmChannel,
        \App\Services\Notification\Channels\LogChannel $logChannel,
        NotificationRetryPolicy $retryPolicy,
        NotificationPreferenceService $prefService,
        \Core\Cache $cache
    ) {
        $this->logger = $logger;
        $this->pushChannel = $pushChannel;
        $this->smsChannel = $smsChannel;
        $this->fcmChannel = $fcmChannel;
        $this->logChannel = $logChannel;
        $this->retryPolicy = $retryPolicy;
        $this->prefService = $prefService;
        $this->cache = $cache;

        $this->registerChannel($this->pushChannel);
        $this->registerChannel($this->smsChannel);
        $this->registerChannel($this->fcmChannel);
        $this->registerChannel($this->logChannel);
    }

    /**
     * Allows registering new, custom adapters dynamically without touching the dispatcher core code.
     */
    public function registerChannel(\App\Contracts\NotificationChannelInterface $channel): void
    {
        $this->channels[strtolower(trim($channel->getName()))] = $channel;
    }

    /**
     * ارسال نوتیفیکیشن به کانال مشخص با اجرای Strategy منطبق
     */
    /** @param array<string, mixed>|null $data */
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
        $result = $this->sendToChannel(
            $channel,
            $userId,
            $title,
            $message,
            $data,
            $imageUrl,
            $actionUrl,
            $actionText
        );

        return $result['success'];
    }

    public function handleChannelRequest(NotificationChannelRequestedEvent $event): bool
    {
        $data = is_array($event->data) ? $event->data : null;
        $result = $this->sendToChannel(
            $event->channel,
            $event->userId,
            $event->title,
            $event->message,
            $data,
            $event->imageUrl,
            $event->actionUrl,
            $event->actionText
        );

        return $result['success'];
    }

    /**
     * @param array<string, mixed>|null $data
     * @return array{success: bool, delivered: bool}
     */
    private function sendToChannel(
        string $channel,
        int $userId,
        string $title,
        string $message,
        ?array $data = null,
        ?string $imageUrl = null,
        ?string $actionUrl = null,
        ?string $actionText = null
    ): array {
        $channelName = strtolower(trim((string)$channel));

        if (!isset($this->channels[$channelName])) {
            $this->logger->warning('notif.unknown_channel', ['channel' => $channel]);
            return ['success' => false, 'delivered' => false];
        }

        // L-14 Fix: گارد dedup متمرکز روی مسیر اصلی ارسال (نه فقط bulk).
        // کلید بر پایهٔ event_id + channel + recipient ساخته می‌شود تا retry یا رویداد
        // تکراری باعث ارسال اعلان مضاعف نشود.
        $cache = $this->cache;
        $dedupId = null;
        if (is_array($data)) {
            $rawDedupId = $data['event_id'] ?? ($data['notif_id'] ?? ($data['message_id'] ?? null));
            if (is_string($rawDedupId) || is_int($rawDedupId)) {
                $dedupId = (string)$rawDedupId;
            }
        }
        $dedupKey = null;
        if ($dedupId !== null && $dedupId !== '') {
            $dedupKey = "notif_sent:{$channelName}:{$dedupId}:{$userId}";
            try {
                if ($cache->get($dedupKey)) {
                    // قبلاً تحویل شده — موفق تلقی می‌شود تا از retry مجدد جلوگیری شود.
                    $this->logger->info('notif.dedup_skip', [
                        'channel' => $channelName,
                        'user_id' => $userId,
                        'dedup_id' => $dedupId,
                    ]);
                    return ['success' => true, 'delivered' => false];
                }
            } catch (\Throwable $e) {
                // در خطای خواندن کش، مسیر ارسال را ادامه می‌دهیم (دسترس‌پذیری).
            }
        }

        try {
            // Execute standard strategy routine with channel-specific retry/circuit policy
            $handler = $this->channels[$channelName];
            $result = $this->retryPolicy->execute($channelName, function () use (
                $handler,
                $userId,
                $title,
                $message,
                $data,
                $imageUrl,
                $actionUrl
            ) {
                return $handler->send($userId, $title, $message, $data, $imageUrl, $actionUrl);
            });

            // L-14 Fix: پس از ارسال موفق، کلید dedup را با TTL یک روزه ثبت می‌کنیم.
            if ($result && $dedupKey !== null) {
                try {
                    $cache->putSeconds($dedupKey, '1', 86400);
                } catch (\Throwable $e) {
                    // Notification delivery already succeeded; a dedup-store outage
                    // is observable through subsequent duplicate-delivery metrics.
                }
            }

            return ['success' => $result, 'delivered' => $result];
        } catch (\Throwable $e) {
            \App\Services\Sentry\SentryExceptionHandler::captureException($e, null, ['operation' => 'app.Services.Notification.NotificationDispatcher.sendToChannel']);
            $this->logger->error('notif.dispatch_failed', [
                'channel' => $channel,
                'user_id' => $userId,
                'error' => $e->getMessage()
            ]);
            return ['success' => false, 'delivered' => false];
        }
    }

    /**
     * ارسال bulk به کانال مشخص
     * @param list<int> $userIds
     * @param array<string, mixed>|null $data
     * @return array<string, mixed>
     */
    public function dispatchBulk(
        string $channel,
        array $userIds,
        string $title,
        string $message,
        ?array $data = null,
        ?string $imageUrl = null,
        ?string $actionUrl = null
    ): array {
        if (empty($userIds)) {
            return ['success' => true, 'processed' => 0];
        }

        // 🚀 Prefetch preferences to avoid N+1 database queries
        try {
            $this->prefService->prefetchPreferences($userIds);
        } catch (\Throwable $e) {
            \App\Services\Sentry\SentryExceptionHandler::captureException($e, null, ['operation' => 'app.Services.Notification.NotificationDispatcher.dispatchBulk']);
            // Safe fallback if service is not resolvable
        }

        $processed = 0;

        foreach ($userIds as $userId) {
            $delivery = $this->sendToChannel(
                $channel,
                (int)$userId,
                $title,
                $message,
                $data,
                $imageUrl,
                $actionUrl
            );

            if ($delivery['delivered']) {
                $processed++;
            }
        }

        $this->logger->info('notif.bulk_dispatched_directly', [
            'channel' => $channel,
            'total_users' => count($userIds),
            'processed' => $processed
        ]);

        return ['success' => true, 'processed' => $processed];
    }
}


