<?php

namespace App\Jobs\Notification;

use App\Contracts\JobInterface;
use App\Services\Notification\NotificationTemplateService;

class GetAllTemplatesWithVariablesNotificationJob implements JobInterface
{
    public function __construct(
        private NotificationTemplateService $templateService
    ) {}

    /** @return array<string, mixed> */
public function handle(): array
    {
        return $this->templateService->getAllTemplatesWithVariables();
    }
}
