<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\DirectMessage;
use Core\Redis;
use App\Services\Settings\AppSettings;
use App\Contracts\LoggerInterface;

/**
 * DirectMessageQueryService - CQRS Query Side
 */
class DirectMessageQueryService
{


    private DirectMessage $directMessageModel;
    private AppSettings $appSettings;
    private Redis $redis;
    private LoggerInterface $logger;

    private const TYPING_PREFIX = 'typing:';
    private const UNREAD_PREFIX = 'unread:';
    // Unused constant removed - no longer needed

    public function __construct(
        Redis $redis,
        LoggerInterface $logger,
        DirectMessage $directMessageModel,
        AppSettings $appSettings
    ) {
        $this->redis = $redis;
        $this->logger = $logger;
        $this->directMessageModel = $directMessageModel;
        $this->appSettings = $appSettings;
    }

    /** @return list<array<string, mixed>> */
    public function getConversation(
        int $userId,
        int $otherUserId,
        int $limit = 50,
        int $offset = 0
    ): array {
        $messages = $this->directMessageModel->getConversation($userId, $otherUserId, $limit, $offset);

        $lastSeenKey = "last_seen:{$userId}:{$otherUserId}";
        $currentTime = time();
        $this->redis->setex($lastSeenKey, 60, (string)$currentTime);

        return array_map(function($msg) {
            $msgContent = $msg->message;
            if ($msg->is_encrypted) {
                try {
                    $msgContent = htmlspecialchars($this->decryptMessage($msg->message), ENT_QUOTES, 'UTF-8');
                } catch (\Throwable $e) {
                    $msgContent = '[رمزگشایی ناموفق]';
                }
            }
            $attachments = !empty($msg->attachments) ? (is_string($msg->attachments) ? (array)(json_decode($msg->attachments, true) ?? []) : $msg->attachments) : [];
            return [
                'id' => $msg->id,
                'sender_id' => $msg->sender_id,
                'sender_name' => $msg->sender_name,
                'message' => $msgContent,
                'is_encrypted' => (bool)$msg->is_encrypted,
                'attachment_count' => $msg->attachment_count,
                // اصلاح کلیدی معماری کلاینت موبایل (Mobile DM Attachment Rendering Guard):
                // ارسال لیست کامل مشخصات و مسیر پیوست‌ها به همراه تعداد آن‌ها جهت رندر بی‌نقص تصاویر و اسناد در محیط گفتگوهای اپلیکیشن
                'attachments' => is_array($attachments) ? $attachments : [],
                'created_at' => $msg->created_at,
                'read_at' => $msg->read_at
            ];
        }, array_reverse($messages));
    }

    /** @return list<array<string, mixed>> */
    public function getConversations(int $userId, int $limit = 20, int $offset = 0): array
    {
        $conversations = $this->directMessageModel->getConversations($userId, $limit, $offset);

        return array_map(function($conv) {
            $lastMessage = $conv->last_message ?? '';
            $isEncrypted = (bool)($conv->is_encrypted ?? false);

            if ($isEncrypted) {
                $lastMessage = '[پیام رمزشده]';
            } else {
                $lastMessage = mb_substr($lastMessage, 0, 50) . (mb_strlen((string)$lastMessage) > 50 ? '...' : '');
            }

            $avatar = $conv->avatar ?? null;
            $avatarThumb = $avatar ? (str_contains($avatar, 'http') ? $avatar : url('uploads/avatars/thumb_' . basename($avatar))) : null;
            $avatarFull = $avatar ? (str_contains($avatar, 'http') ? $avatar : url('uploads/avatars/' . basename($avatar))) : null;

            return [
                'user_id' => $conv->user_id,
                'full_name' => $conv->full_name,
                // اصلاح کلیدی معماری موبایل (Mobile Texture OOM & Image Optimization Guard):
                // ارسال هم‌زمان نسخه بهینه‌شده (Thumbnail) و اصلی آواتار جهت جلوگیری از کرش مموری کارت گرافیک در لیست گفتگوهای موبایل (Flutter/React Native)
                'avatar' => $avatarFull,
                'avatar_thumb' => $avatarThumb,
                'last_message' => $lastMessage,
                'is_encrypted' => $isEncrypted,
                'last_message_at' => $conv->last_message_at,
                'unread_count' => (int)($conv->unread_count ?? 0)
            ];
        }, $conversations);
    }

    /** @return array<string, mixed>|null */
    public function getUserInfo(int $userId, ?int $requesterId = null): ?array
    {
        $user = $this->directMessageModel->getUserInfo($userId);

        usleep(random_int(10000, 50000));

        if (!$user) {
            return null;
        }

        $isOnline = false;
        if ($requesterId && $requesterId !== $userId) {
            if ($this->directMessageModel->hasConversation($requesterId, $userId)) {
                $isOnline = (bool)($user->is_online ?? false);
            }
        } elseif ($requesterId === $userId) {
            $isOnline = (bool)($user->is_online ?? false);
        }

        $isBlocked = false;
        if ($requesterId !== null) {
            $isBlocked = $this->isBlocked($userId, $requesterId) || $this->isBlocked($requesterId, $userId);
        }

        return [
            'id' => $user->id,
            'username' => $user->username,
            'full_name' => $user->full_name,
            'avatar' => $user->avatar,
            'is_online' => $isOnline,
            'is_blocked' => $isBlocked
        ];
    }

    /** @return list<int> */
    public function getTypingUsers(int $userId): array
    {
        $pattern = self::TYPING_PREFIX . $userId . ':*';
        $keys = $this->redis->scanKeys($pattern, 10, 10);

        $typingUsers = [];
        foreach ($keys as $key) {
            $otherUserId = (int)explode(':', $key)[2];
            
            if ($this->redis->get($key) === '1') {
                $hasConversation = $this->directMessageModel->hasConversation($userId, $otherUserId);
                if ($hasConversation) {
                    $typingUsers[] = $otherUserId;
                }
            }
        }

        return $typingUsers;
    }

    public function hasConversation(int $userId, int $otherUserId): bool
    {
        return $this->directMessageModel->hasConversation($userId, $otherUserId);
    }

    public function getUnreadCount(int $userId, ?int $fromUserId = null): int
    {
        $redisAvailable = false;
        try {
            $redisAvailable = $this->redis->isAvailable();
        } catch (\Throwable $e) {
            $this->logger->warning('directmessagequery.operation_failed', ['error' => $e->getMessage()]);
        }

        if ($fromUserId) {
            if ($redisAvailable) {
                try {
                    $key = self::UNREAD_PREFIX . $userId . ':' . $fromUserId;
                    return int_value($this->redis->get($key) ?? 0);
                } catch (\Exception $e) {
                    $this->logger->warning('unread.redis.failed', ['error' => $e->getMessage()]);
                }
            }
            return $this->directMessageModel->countUnread($userId, $fromUserId);
        }

        static $cache = [];
        if (isset($cache[$userId])) {
            return $cache[$userId];
        }
        
        if ($redisAvailable) {
            $pattern = self::UNREAD_PREFIX . $userId . ':*';
            try {
                $keys = $this->redis->scanKeys($pattern, 100, 50);
                
                $total = 0;
                foreach ($keys as $key) {
                    $total += int_value($this->redis->get($key) ?? 0);
                }
                
                $cache[$userId] = $total;
                return $total;
            } catch (\Exception $e) {
                $this->logger->warning('unread.redis.failed', ['error' => $e->getMessage()]);
            }
        }
        
        $count = $this->directMessageModel->countUnread($userId);
        $cache[$userId] = $count;
        return $count;
    }

    private function decryptMessage(string $encrypted): string
    {
        try {
            $encryptionKey = $this->appSettings->get('dm_encryption_key');
            if (!$encryptionKey) {
                $this->logger->critical('dm.encryption.key.missing', ['error' => 'Settings key dm_encryption_key is missing']);
                throw new \RuntimeException('تنظیمات کلید رمزنگاری پیام‌ها یافت نشد.');
            }
            
            $decoded = base64_decode($encrypted);
            if ($decoded === false) {
                return '[خطا در دیکریپت]';
            }
            $nonce = mb_substr($decoded, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES, '8bit');
            $ciphertext = mb_substr($decoded, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES, null, '8bit');
            
            if (!is_string($encryptionKey)) return '[خطا در دیکریپت]';
            $decodedKey = base64_decode($encryptionKey, true);
            if ($decodedKey === false || strlen($decodedKey) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) return '[خطا در دیکریپت]';
            $decrypted = sodium_crypto_secretbox_open($ciphertext, $nonce, $decodedKey);
            return $decrypted !== false ? $decrypted : '[خطا در دیکریپت]';
        } catch (\Exception $e) {
            $this->logger->error('message.decrypt.failed', ['error' => $e->getMessage()]);
            return '[خطا در دیکریپت]';
        }
    }

    private function isBlocked(int $userId, int $blockedUserId): bool
    {
        return $this->directMessageModel->isBlocked($userId, $blockedUserId);
    }
}
