<?php

declare(strict_types=1);

namespace App\Services\Notification;

use App\Adapters\Notification\FcmNotificationAdapter;
use App\Contracts\LoggerInterface;
use Core\Database;
use Core\Cache;

class FcmService
{
    private FcmNotificationAdapter $adapter;
    private Database $db;
    private Cache $cache;
    private LoggerInterface $logger;

    private ?\App\Services\Cache\CacheInvalidationService $cacheInvalidation;

    public function __construct(
        FcmNotificationAdapter $adapter,
        Database $db,
        Cache $cache,
        LoggerInterface $logger,
        ?\App\Services\Cache\CacheInvalidationService $cacheInvalidation = null
    ) {
        $this->adapter = $adapter;
        $this->db = $db;
        $this->cache = $cache;
        $this->logger = $logger;
        $this->cacheInvalidation = $cacheInvalidation;
    }

    /** @param array<string, mixed> $data */
    public function sendToUser(int $userId, string $title, string $body, array $data = [], ?string $imageUrl = null, ?string $clickUrl = null): bool
    {
        $token = $this->getUserToken($userId);
        if ($token === null) {
            return false;
        }
        return $this->adapter->sendToToken($token, $title, $body, $data, $imageUrl, $clickUrl);
    }

    /**
     * @param list<string> $tokens
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function sendToTokens(array $tokens, string $title, string $body, array $data = [], ?string $imageUrl = null, ?string $clickUrl = null): array
    {
        return $this->adapter->sendToTokens($tokens, $title, $body, $data, $imageUrl, $clickUrl);
    }

    public function saveUserToken(int $userId, string $token, string $platform = 'web'): bool
    {
        $token = trim($token);
        $platform = strtolower(trim($platform));
        if ($userId <= 0
            || strlen($token) < 20
            || strlen($token) > 4096
            || preg_match('/[\x00-\x1F\x7F]/', $token) === 1
            || !in_array($platform, ['web','android','ios'], true)) {
            $this->logger->warning('fcm.invalid_token_registration', ['user_id'=>$userId,'platform'=>$platform]);
            return false;
        }

        try {
            $this->db->query(
                "INSERT INTO user_devices (user_id, fcm_token, platform, last_activity, created_at, updated_at)
                 VALUES (?, ?, ?, NOW(), NOW(), NOW())
                 ON DUPLICATE KEY UPDATE fcm_token=VALUES(fcm_token), last_activity=NOW(), updated_at=NOW()",
                [$userId, $token, $platform]
            );

            $key = "fcm_token:user:{$userId}:{$platform}";
            $this->cache->put($key, $token, 60 * 24 * 30);

            return true;
        } catch (\Throwable $e) {
            \App\Services\Sentry\SentryExceptionHandler::captureException($e, null, ['operation' => 'app.Services.Notification.FcmService.saveUserToken']);
            $this->logger->error('fcm.save_token_failed', ['error' => $e->getMessage()]);
            return false;
        }
    }

    public function getUserToken(int $userId, string $platform = 'web'): ?string
    {
        $key = "fcm_token:user:{$userId}:{$platform}";
        $cached = $this->cache->get($key);
        if (is_string($cached) && $cached !== '') return $cached;

        $dbToken = $this->db->fetchColumn("SELECT fcm_token FROM user_devices WHERE user_id = ? AND platform = ? LIMIT 1", [$userId, $platform]);
        if ($dbToken) {
            $this->cache->put($key, $dbToken, 60 * 24 * 30);
            return (string) $dbToken;
        }

        return null;
    }

    public function removeUserToken(int $userId, string $platform = 'web'): void
    {
        try {
            $this->db->query("DELETE FROM user_devices WHERE user_id = ? AND platform = ?", [$userId, $platform]);
            if ($this->cacheInvalidation) {
                $this->cacheInvalidation->invalidateFcmToken($userId, $platform);
            } else {
                $this->cache->forget("fcm_token:user:{$userId}:{$platform}");
            }
        } catch (\Throwable $e) {
            \App\Services\Sentry\SentryExceptionHandler::captureException($e, null, ['operation' => 'app.Services.Notification.FcmService.removeUserToken']);
            $this->logger->error('fcm.remove_token_failed', ['error' => $e->getMessage()]);
        }
    }

    public function isConfigured(): bool
    {
        return $this->adapter->isConfigured();
    }
}
