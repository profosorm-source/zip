<?php

namespace App\Jobs\Notification;

use App\Contracts\JobInterface;
use App\Services\Notification\NotificationTemplateService;

class DeleteTemplateOverrideNotificationJob implements JobInterface
{
    public function __construct(
        private NotificationTemplateService $templateService
    ) {}

    public function handle(string $key): bool
    {
        return $this->templateService->deleteTemplateOverride($key);
    }
}
