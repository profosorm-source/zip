<?php

namespace App\Jobs\Notification;

use App\Contracts\JobInterface;
use App\Services\Notification\NotificationTemplateService;

class RenderTemplateNotificationJob implements JobInterface
{
    public function __construct(
        private NotificationTemplateService $templateService
    ) {}

/**
 * @param array<string, mixed> $vars
 * @return array<string, mixed>
 */
public function handle(string $templateKey, array $vars = []): array
    {
        return $this->templateService->renderTemplate($templateKey, $vars);
    }
}
