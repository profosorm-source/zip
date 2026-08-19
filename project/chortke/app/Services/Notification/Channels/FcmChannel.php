<?php

declare(strict_types=1);

namespace App\Services\Notification\Channels;

use App\Contracts\NotificationChannelInterface;
use App\Services\Notification\FcmService;

class FcmChannel implements NotificationChannelInterface
{
    private FcmService $fcmService;

    public function __construct(FcmService $fcmService) {
        $this->fcmService = $fcmService;
    }

    public function getName(): string
    {
        return 'fcm';
    }

    public function send(
        int $userId,
        string $title,
        string $message,
        ?array $data = null,
        ?string $imageUrl = null,
        ?string $actionUrl = null
    ): bool {
        return $this->fcmService->sendToUser($userId, $title, $message, $data ?? [], $imageUrl, $actionUrl);
    }
}
