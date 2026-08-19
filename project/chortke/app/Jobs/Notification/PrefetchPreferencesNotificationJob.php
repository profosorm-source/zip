<?php

namespace App\Jobs\Notification;

use App\Contracts\JobInterface;
use App\Services\Notification\NotificationPolicyService;

class PrefetchPreferencesNotificationJob implements JobInterface
{
    public function __construct(
        private NotificationPolicyService $policyService
    ) {}

    /** @param list<int> $userIds */
    /** @param list<int> $userIds */
public function handle(array $userIds): void
    {
        $this->policyService->prefetchPreferences($userIds);
    }
}
