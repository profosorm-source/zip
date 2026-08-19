<?php

namespace App\Jobs\Notification;

use App\Contracts\JobInterface;

class DispatchNotificationJob implements JobInterface
{
    private \App\Services\Notification\NotificationDispatcher $dispatcher;

    public function __construct(\App\Services\Notification\NotificationDispatcher $dispatcher)
    {
        $this->dispatcher = $dispatcher;
    }

    /** @param array<string, mixed> $data */
    /** @param array<string, mixed> $data */
public function handle(
        string $channel,
        int $userId,
        string $title,
        string $message,
        ?array $data = null,
        ?string $imageUrl = null,
        ?string $actionUrl = null,
        ?string $actionText = null,
        string $priority = 'normal'
    ): bool
    {

        return $this->dispatcher->dispatch($channel, $userId, $title, $message, $data, $imageUrl, $actionUrl, $actionText, $priority);
    
    }
}
