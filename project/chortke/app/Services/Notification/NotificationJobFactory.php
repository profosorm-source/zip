<?php

declare(strict_types=1);

namespace App\Services\Notification;

use Core\Container;
use App\Jobs\Notification\SendNotificationJob;
use App\Jobs\Notification\DispatchNotificationJob;
use App\Jobs\Notification\SendBulkNotificationJob;

/**
 * NotificationJobFactory
 * وظیفه ساخت و لود کردن جاب‌های مربوط به نوتیفیکیشن را بر عهده دارد.
 * این کار باعث جلوگیری از شلوغ شدن Constructor در NotificationService می‌شود.
 */
class NotificationJobFactory
{
    public function __construct(private Container $container) {}

    /**
     * @template T of object
     * @param class-string<T> $jobClass
     * @return T
     */
    public function make(string $jobClass): object
    {
        $job = $this->container->make($jobClass);
        if (!$job instanceof $jobClass) {
            throw new \RuntimeException("Notification job binding {$jobClass} is invalid");
        }

        return $job;
    }

    public function makeSendJob(): SendNotificationJob
    {
        return $this->make(SendNotificationJob::class);
    }

    public function makeDispatchJob(): DispatchNotificationJob
    {
        return $this->make(DispatchNotificationJob::class);
    }

    public function makeBulkJob(): SendBulkNotificationJob
    {
        return $this->make(SendBulkNotificationJob::class);
    }
}
