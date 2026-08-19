<?php

namespace App\Jobs\Notification;

use App\Contracts\JobInterface;
use App\Services\Notification\NotificationTemplateService;

class SendFromTemplateNotificationJob implements JobInterface
{
    public function __construct(
        private NotificationTemplateService $templateService,
        private SendNotificationJob $sender
    ) {}

    /** @param array<string, mixed> $vars */
    /** @param array<string, mixed> $vars */
public function handle(
        int    $userId,
        string $templateKey,
        array  $vars       = [],
        string $priority   = 'normal',
        ?string $actionUrl = null,
        ?string $actionText= null,
        ?string $groupKey  = null,
        ?string $scheduledAt = null
    ): ?int
    {
        
        $rendered = $this->templateService->renderTemplate($templateKey, $vars);
        $prefix = strtolower((string)preg_replace('/[^A-Za-z0-9_]/', '', explode('_', $templateKey)[0]));
        
        $allowedTypes = [
            'deposit' => 'deposit',
            'withdrawal' => 'withdrawal',
            'task' => 'task',
            'kyc' => 'kyc',
            'lottery' => 'lottery',
            'referral' => 'referral',
            'security' => 'security',
            'investment' => 'investment',
            'info' => 'info',
            'marketing' => 'marketing',
        ];

        $type = $allowedTypes[$prefix] ?? 'system';
        $title = $rendered['title'] ?? null;
        $message = $rendered['message'] ?? null;
        if (!is_string($title) || !is_string($message)) {
            throw new \UnexpectedValueException('Notification template must render string title and message.');
        }

        return $this->sender->handle(
            $userId,
            $type,
            $title,
            $message,
            $vars,
            $actionUrl,
            $actionText,
            $priority,
            null,
            null,
            $groupKey ?? $templateKey,
            $scheduledAt
        );
    }
}
