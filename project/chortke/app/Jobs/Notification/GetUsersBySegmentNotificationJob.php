<?php

namespace App\Jobs\Notification;

use App\Contracts\JobInterface;

class GetUsersBySegmentNotificationJob implements JobInterface
{
    private object $model;

    public function __construct(object $model)
    {
        $this->model = $model;
    }

/**
 * @param array<string, mixed> $filters
 * @return array<string, mixed>
 */
public function handle(string $segment, array $filters = []): array
    {
        if (method_exists($this->model, 'getUsersBySegment')) {
            return $this->model->getUsersBySegment($segment, $filters);
        }
        return [];
    }
}
