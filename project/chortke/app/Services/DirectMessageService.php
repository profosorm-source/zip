<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\DirectMessage;
use Core\Redis;
use Core\Database;
use App\Services\Settings\AppSettings;
use App\Services\User\UserSettingsService;
use App\Contracts\LoggerInterface;

/**
 * DirectMessageService - Facade / Orchestrator for Direct Messages
 * Delegates to DirectMessageCommandService and DirectMessageQueryService.
 */
class DirectMessageService
{


    private DirectMessageCommandService $commandService;
    private DirectMessageQueryService $queryService;

    public function __construct(
        Redis $redis,
        Database $db,
        LoggerInterface $logger,
        DirectMessage $directMessageModel,
        AppSettings $appSettings,
        UserSettingsService $userSettingsService
    ) {
        $this->commandService = new DirectMessageCommandService(
            $redis, $db, $logger, $directMessageModel, $appSettings, $userSettingsService
        );
        $this->queryService = new DirectMessageQueryService(
            $redis, $logger, $directMessageModel, $appSettings
        );
    }

    /**
     * @param array<int, array<string, mixed>>|null $attachments
     * @return array<string, mixed>
     */
    public function sendMessage(
        int $senderId,
        int $recipientId,
        string $message,
        ?array $attachments = null,
        ?bool $isEncrypted = false
    ): array {
        return $this->commandService->sendMessage($senderId, $recipientId, $message, $attachments, $isEncrypted);
    }

    /** @return array<int, array<string, mixed>> */
    public function getConversation(
        int $userId,
        int $otherUserId,
        int $limit = 50,
        int $offset = 0
    ): array {
        return $this->queryService->getConversation($userId, $otherUserId, $limit, $offset);
    }

    /** @return array<int, array<string, mixed>> */
    public function getConversations(int $userId, int $limit = 20, int $offset = 0): array
    {
        return $this->queryService->getConversations($userId, $limit, $offset);
    }

    /** Backward-compatible API wrapper used by Api\UserController. */
    /** @return array<string, mixed> */
    public function getUserConversations(int $userId, int $limit = 20, int $offset = 0): array
    {
        $conversations = $this->getConversations($userId, $limit, $offset);
        return [
            'conversations' => $conversations,
            'total' => count($conversations),
        ];
    }

    /** @return array<string, mixed>|null */
    public function getUserInfo(int $userId, ?int $requesterId = null): ?array
    {
        return $this->queryService->getUserInfo($userId, $requesterId);
    }

    public function setTyping(int $userId, int $recipientId, bool $isTyping = true): void
    {
        $this->commandService->setTyping($userId, $recipientId, $isTyping);
    }

    /** @return array<int, int> */
    public function getTypingUsers(int $userId): array
    {
        return $this->queryService->getTypingUsers($userId);
    }

    public function hasConversation(int $userId, int $otherUserId): bool
    {
        return $this->queryService->hasConversation($userId, $otherUserId);
    }

    public function markAsRead(int $userId, int $otherUserId): void
    {
        // اصلاح کلیدی معماری CQRS (CQRS Mutation Guard):
        // هدایت عملیات تغییر وضعیت خوانده‌شدن به سرویس Command به جای Query جهت رعایت اصل تفکیک مسئولیت‌ها و حل باگ فراخوانی متد ناموجود
        $this->commandService->markAsRead($userId, $otherUserId);
    }

    public function deleteMessage(int $messageId, int $userId): bool
    {
        return $this->commandService->deleteMessage($messageId, $userId);
    }

    public function addReaction(int $messageId, int $userId, string $emoji): bool
    {
        return $this->commandService->addReaction($messageId, $userId, $emoji);
    }

    public function getUnreadCount(int $userId, ?int $fromUserId = null): int
    {
        return $this->queryService->getUnreadCount($userId, $fromUserId);
    }
}
