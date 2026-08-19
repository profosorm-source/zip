<?php

namespace App\Jobs\Notification;

use App\Contracts\JobInterface;
use App\Services\Notification\NotificationTemplateService;

class GetTemplateNotificationJob implements JobInterface
{
    public function __construct(
        private NotificationTemplateService $templateService
    ) {}

    /** @return array<string, mixed> */
public function handle(string $templateKey): array
    {
        return $this->templateService->getTemplate($templateKey);
    }
}
