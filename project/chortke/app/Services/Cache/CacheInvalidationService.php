<?php

declare(strict_types=1);

namespace App\Services\Cache;

use App\Contracts\CacheInterface;
use App\Contracts\LoggerInterface;

/**
 * CacheInvalidationService - مرکز مدیریت باطل‌سازی کش‌های سیستم
 */
class CacheInvalidationService
{
    private CacheInterface $cache;
    private LoggerInterface $logger;
    public function __construct(
        CacheInterface $cache,
        LoggerInterface $logger
    ) {        $this->cache = $cache;
        $this->logger = $logger;
}

    /** @return array<string, mixed> */
    /**
     * @param object|array<string, mixed> $event
     * @return array<string, mixed>
     */
    private function eventData(object|array $event): array
    {
        $data = $event instanceof \Core\Event ? $event->getData() : $event;
        return is_array($data) ? $data : [];
    }

    /** @param array<string, mixed> $data */
    private function eventId(array $data, string $key): int
    {
        $value = $data[$key] ?? null;
        return is_int($value) || (is_string($value) && ctype_digit($value))
            ? max(0, (int)$value)
            : 0;
    }

    /**
     * ثبت متدهای باطل‌سازی به عنوان شنونده رویدادها (Event Subscriber)
     */
    public function invalidate(string $key): void
    {
        $this->cache->forget($key);
    }

    public function invalidateByPattern(string $pattern): void
    {
        // pattern invalidation logic
    }

    public function invalidateByTag(string $tag): void
    {
        $this->cache->tags([$tag])->flush();
    }
    public function subscribe(\Core\EventDispatcher $dispatcher): void
    {
        $dispatcher->listen('wallet.updated', [$this, 'onWalletUpdated']);
        $dispatcher->listen(\App\Events\ScoreUpdatedEvent::class, [$this, 'onScoreUpdated']);
        $dispatcher->listen('search.invalidated', [$this, 'onSearchInvalidated']);
        $dispatcher->listen('module.search.invalidated', [$this, 'onModuleSearchInvalidated']);
        $dispatcher->listen('payment.status_changed', [$this, 'onPaymentUpdated']);
        $dispatcher->listen('user.profile_updated', [$this, 'onUserProfileUpdated']);
        $dispatcher->listen('user.kyc_updated', [$this, 'onUserKycUpdated']);
    }

    /** @param object|array<string, mixed> $event */
    public function onPaymentUpdated(object|array $event): void
    {
        $data = $this->eventData($event);
        if (!empty($data['payment_id'])) {
            $this->invalidatePayment($this->eventId($data, 'payment_id'));
        }
    }

    /** @param object|array<string, mixed> $event */
    public function onUserProfileUpdated(object|array $event): void
    {
        $data = $this->eventData($event);
        if (!empty($data['user_id'])) {
            $this->invalidateUser($this->eventId($data, 'user_id'));
        }
    }

    /** @param object|array<string, mixed> $event */
    public function onUserKycUpdated(object|array $event): void
    {
        $data = $this->eventData($event);
        if (!empty($data['user_id'])) {
            $this->invalidateUser($this->eventId($data, 'user_id'));
        }
    }

    /** @param object|array<string, mixed> $event */
    public function onWalletUpdated(object|array $event): void
    {
        $data = $this->eventData($event);
        if (!empty($data['user_id'])) {
            $this->invalidateWallet($this->eventId($data, 'user_id'));
        }
    }

    /** @param object|array<string, mixed> $event */
    public function onScoreUpdated(object|array $event): void
    {
        $data = $this->eventData($event);
        if (!empty($data['user_id']) && !empty($data['domain'])) {
            $domain = is_scalar($data['domain']) ? (string)$data['domain'] : '';
            if ($domain !== '') {
                $this->invalidateScore($this->eventId($data, 'user_id'), $domain);
            }
        }
    }

    /** @param object|array<string, mixed> $event */
    public function onSearchInvalidated(object|array $event): void
    {
        $this->invalidateSearch();
    }

    /** @param object|array<string, mixed> $event */
    public function onModuleSearchInvalidated(object|array $event): void
    {
        $data = $this->eventData($event);
        if (!empty($data['module'])) {
            $module = is_scalar($data['module']) ? (string)$data['module'] : '';
            if ($module !== '') {
                $this->invalidateModuleSearch($module);
            }
        }
    }

    /**
     * باطل‌سازی تمام کش‌های مرتبط با کیف پول کاربر
     * رفع نقص: تجمیع کلیدهای پراکنده (Balance, Limits, History)
     */
    public function invalidateWallet(int $userId): void
    {
        $keys = [
            "wallet:balance:{$userId}:irt",
            "wallet:balance:{$userId}:usdt",
            "wallet:limits:{$userId}",
            "wallet:summary:{$userId}",
            "user:financial_status:{$userId}"
        ];

        foreach ($keys as $key) {
            $this->cache->forget($key);
        }

        // استفاده از تگ برای پاکسازی لیست تراکنش‌ها (اگر درایور پشتیبانی کند)
        $this->cache->tags(["wallet_tx_{$userId}"])->flush();

        $this->logger->info('cache.wallet_invalidated', ['user_id' => $userId]);
    }

    public function invalidateModuleSearch(string $module): void
    {
        $this->cache->tags([$module, "search:{$module}", "search:domain:{$module}"])->flush();
    }

    /**
     * 🛡️ M-1 Fix: تصحیح کلید کش امتیاز
     *            
     * قبلی: score:user:{userId}:{domain}  ← اشتباه (String key)
     * جدید: HDEL score:user:{userId} {domain}  ← صحیح (Hash field)
     * 
     * ScoreService از Redis Hash با کلید score:user:123 و فیلد domain استفاده می‌کند
     * پس بجای DEL روی کلید اشتباه، باید HDEL روی هدر فیلد خاص انجام شود
     */
    public function invalidateScore(int $userId, string $domain): void
    {
        try {
            $redis = $this->cache->redis();
            if ($redis) {
                $cacheKey = "score:user:{$userId}";
                $redis->hDel($cacheKey, $domain);
                $redis->expire($cacheKey, 3600);
            }
        } catch (\Throwable $e) {
            // Non-critical: fallback to generic delete
            $this->cache->forget("score:user:{$userId}:{$domain}");
        }
    }

    public function invalidateSearch(): void
    {
        $this->cache->tags(['search'])->flush();
    }

    public function invalidatePayment(int $paymentId): void
    {
        $this->cache->forget("payment:status:{$paymentId}");
        $this->cache->forget("payment:details:{$paymentId}");
        $this->cache->tags(["payment_{$paymentId}"])->flush();
        $this->logger->info('cache.payment_invalidated', ['payment_id' => $paymentId]);
    }

    public function invalidateUser(int $userId): void
    {
        $this->cache->forget("user_settings:{$userId}");
        $this->cache->forget("profile:{$userId}");
        $this->cache->tags(["user_{$userId}"])->flush();
        $this->logger->info('cache.user_invalidated', ['user_id' => $userId]);
    }

    public function invalidateFeatureFlag(?string $featureName = null): void
    {
        if ($featureName) {
            $this->cache->tags(['feature_flag'])->forget($featureName);
        } else {
            $this->cache->tags(['feature_flag'])->flush();
        }
        $this->logger->info('cache.feature_flag_invalidated', ['feature' => $featureName ?? 'all']);
    }

    /**
     * invalidate کش چند کاربر — مثلاً بعد ارسال bulk notification
     */
    /** @param list<int> $userIds */
    public function invalidateUsers(array $userIds): void
    {
        foreach ($userIds as $userId) {
            $this->invalidateUser((int)$userId);
        }
    }

    /**
     * invalidate کش template notification
     */
    public function invalidateNotificationTemplate(string $key): void
    {
        $this->cache->forget("notification_template:{$key}");
        $this->logger->info('cache.notification_template_invalidated', ['key' => $key]);
    }

    /**
     * invalidate کش FCM token
     */
    public function invalidateFcmToken(int $userId, string $platform): void
    {
        $this->cache->forget("fcm_token:user:{$userId}:{$platform}");
        $this->logger->info('cache.fcm_token_invalidated', ['user_id' => $userId, 'platform' => $platform]);
    }

    /**
     * invalidate کش unread count notification
     */
    public function invalidateUnreadCount(int $userId): void
    {
        $this->cache->forget("unread_notifications:{$userId}");
        $this->logger->info('cache.unread_count_invalidated', ['user_id' => $userId]);
    }
}