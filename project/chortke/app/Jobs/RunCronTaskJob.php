<?php

declare(strict_types=1);

namespace App\Jobs;

use Core\Scheduler;

/**
 * RunCronTaskJob
 * 
 * این جاب توسط Queue Worker دریافت می‌شود و مسئول اجرای واقعی کلوژرهای کرون‌جاب‌ها در پس‌زمینه است.
 */
class RunCronTaskJob
{
    private Scheduler $scheduler;

    public function __construct(Scheduler $scheduler) {
        $this->scheduler = $scheduler;
    }

    /**
     * @param array $data باید شامل کلید 'task_name' باشد
     */
    /** @param array<string, mixed> $data */
    /** @param array<string, mixed> $data */
public function handle(array $data): void
    {
        $taskNameValue = $data['task_name'] ?? null;
        if (!is_string($taskNameValue) || trim($taskNameValue) === '') {
            logger()->error('run_cron_task_job_failed', ['reason' => 'missing_task_name']);
            return;
        }
        $taskName = trim($taskNameValue);

        try {
            
            // اطمینان از اینکه Kernel بارگذاری شده و وظایف ثبت شده‌اند
            if (class_exists(\App\Console\Kernel::class)) {
                // فلگ اجبار را روشن می‌کنیم تا تسک‌ها با زمان‌بندی (هرچند زمانشان گذشته باشد) در Scheduler رجیستر شوند
                $this->scheduler->forceRegisterJobs(true);
                \App\Console\Kernel::schedule($this->scheduler);
            } else {
                throw new \Core\Exceptions\ApplicationException("کلاس App\\Console\\Kernel یافت نشد");
            }

            // اجرای مستقیم متد متناظر با این تسک (بدون بررسی مجدد interval)
            $this->scheduler->executeJobByName($taskName);

        } catch (\Throwable $e) {
            logger()->error('run_cron_task_job_exception', [
                'task_name' => $taskName,
                'error'     => $e->getMessage(),
                'file'      => $e->getFile(),
                'line'      => $e->getLine()
            ]);
            throw $e; // Throwing it allows the Queue system to handle retries/DLQ
        }
    }
}
