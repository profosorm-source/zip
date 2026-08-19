<?php

declare(strict_types=1);

namespace App\Adapters\Notification;

use Core\Logger;

class PushNotificationAdapter
{
    private \App\Services\Notification\FcmService $fcmService;
    private Logger $logger;

    public function __construct(\App\Services\Notification\FcmService $fcmService, Logger $logger) {
        $this->fcmService = $fcmService;
        $this->logger = $logger;
    }

    /** @param array<string, mixed> $data */
    public function sendToUser(int $userId, string $title, string $body, array $data = [], ?string $imageUrl = null, ?string $clickUrl = null): bool
    {
        try {
            return $this->fcmService->sendToUser($userId, $title, $body, $data, $imageUrl, $clickUrl);
        } catch (\Throwable $e) {
            $this->logger->error('push.sendToUser.failed', ['user_id' => $userId, 'error' => $e->getMessage()]);
            return false;
        }
    }
    
    /**
     * @param list<string> $tokens
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function sendToTokens(array $tokens, string $title, string $body, array $data = [], ?string $imageUrl = null, ?string $clickUrl = null): array
    {
        try {
            return $this->fcmService->sendToTokens($tokens, $title, $body, $data, $imageUrl, $clickUrl);
        } catch (\Throwable $e) {
            $this->logger->error('push.sendToTokens.failed', ['tokens_count' => count($tokens), 'error' => $e->getMessage()]);
            return ['success' => 0, 'failure' => count($tokens)];
        }
    }
}


