<?php

namespace App\Jobs\Notification;

use App\Contracts\JobInterface;
use App\Services\Notification\NotificationTemplateService;

class SaveTemplateOverrideNotificationJob implements JobInterface
{
    public function __construct(
        private NotificationTemplateService $templateService
    ) {}

    public function handle(string $key, string $title, string $message): bool
    {
        return $this->templateService->saveTemplateOverride($key, $title, $message);
    }
}
