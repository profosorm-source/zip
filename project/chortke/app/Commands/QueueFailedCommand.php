<?php

declare(strict_types=1);

namespace App\Commands;

use Core\Command;
use Core\Database;
use Core\Queue;
use App\Contracts\LoggerInterface;

/**
 * QueueFailedCommand - مدیریت کامل DLQ (Dead Letter Queue)
 * 
 * وظیفه این دستور مانیتورینگ، پاکسازی و اجرای مجدد (Replay) جاب‌های شکست خورده است.
 */
class QueueFailedCommand extends Command
{
    private Database $db;
    private Queue $queue;
    private LoggerInterface $logger;
    public function __construct(
        Database $db,
        Queue $queue,
        LoggerInterface $logger
    ) {        $this->db = $db;
        $this->queue = $queue;
        $this->logger = $logger;
}

    /**
     * لیست کردن آخرین جاب‌های شکست خورده
     */
    public function list(): void
    {
        try {
            $failed = $this->db->fetchAll("SELECT id, queue, failed_at FROM failed_jobs ORDER BY failed_at DESC LIMIT 50");
            
            if (empty($failed)) {
                $this->info("هیچ جابی در صف شکست (DLQ) یافت نشد.");
                return;
            }

            $this->info("تعداد " . count($failed) . " جاب شکست خورده اخیر:");
            foreach ($failed as $job) {
                $this->line("ID: {$job->id} | Queue: {$job->queue} | Failed at: {$job->failed_at}");
            }
        } catch (\Throwable $e) {
            $this->error("خطا در واکشی لیست: " . $e->getMessage());
        }
    }

    /**
     * اجرای مجدد یک جاب خاص بر اساس شناسه
     */
    public function retry(int $id): void
    {
        $this->info("در حال تلاش مجدد برای جاب #{$id}...");

        if ($this->processRetry($id)) {
            $this->info("جاب با موفقیت به صف اصلی بازگشت و از لیست خطاها حذف شد.");
        } else {
            $this->error("عملیات شکست خورد. گزارش‌های سیستم (Logs) را بررسی کنید.");
        }
    }

    /**
     * حذف یک جاب از لیست شکست‌ها (بدون اجرای مجدد)
     */
    public function forget(int $id): void
    {
        try {
            $this->db->prepare("DELETE FROM failed_jobs WHERE id = ?")->execute([$id]);
            $this->info("جاب #{$id} با موفقیت پاکسازی شد.");
        } catch (\Throwable $e) {
            $this->error("خطا در حذف جاب: " . $e->getMessage());
        }
    }

    /**
     * اجرای مجدد تمام جاب‌های شکست خورده به صورت دسته‌ای
     */
    public function replayAll(): void
    {
        try {
            $stats = $this->queue->retryFailedJobsBatch(null, 1000);
            $this->info("عملیات پایان یافت: {$stats['requeued']} موفق، {$stats['errors']} خطا و {$stats['skipped']} نادیده گرفته شد.");
        } catch (\Throwable $e) {
            $this->error("خطای کلی در Replay: " . $e->getMessage());
        }
    }

    /**
     * منطق اتمیک انتقال جاب از DLQ به صف اصلی
     */
    private function processRetry(int $id): bool
    {
        $job = $this->db->query("SELECT * FROM failed_jobs WHERE id = ?", [$id])->fetch(\PDO::FETCH_OBJ);

        if (!is_object($job)) {
            $this->logger->warning('queue.retry.not_found', ['id' => $id]);
            return false;
        }
        $jobData = get_object_vars($job);

        try {
            $this->db->beginTransaction();

            $payloadRaw = $jobData['payload'] ?? null;
            $payload = is_string($payloadRaw) ? json_decode($payloadRaw, true) : null;
            if (!is_array($payload) || empty($payload['job'])) {
                $this->db->rollback();
                $this->logger->warning('queue.retry.invalid_payload', ['id' => $id]);
                return false;
            }

            // درج در سیستم صف واقعی (چه دیتابیس باشد چه ردیس)
            $success = $this->queue->push(
                (string)$payload['job'],
                (array)($payload['data'] ?? []),
                is_string($jobData['queue'] ?? null) ? $jobData['queue'] : 'default'
            );

            if ($success) {
                // حذف از لیست شکست‌ها فقط در صورت موفقیت درج
                $this->db->prepare("DELETE FROM failed_jobs WHERE id = ?")->execute([$id]);
                
                $this->db->commit();
                $this->logger->info('queue.retry.success', ['id' => $id, 'queue' => $jobData['queue'] ?? 'default']);
                return true;
            }

            $this->db->rollback();
            return false;
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollback();
            }
            $this->logger->error('queue.retry.failed', ['id' => $id, 'error' => $e->getMessage()]);
            return false;
        }
    }

    /** @param array<string, mixed> $args */

    public function run(array $args = []): void
    {
        $subAction = $args[2] ?? 'list';

        switch ($subAction) {
            case 'list':
                $this->list();
                break;
            case 'retry':
                $id = (int) ($args[3] ?? 0);
                if ($id <= 0) {
                    $this->error('Usage: php cli.php queue:failed retry <job_id>');
                    return;
                }
                $this->retry($id);
                break;
            case 'forget':
                $id = (int) ($args[3] ?? 0);
                if ($id <= 0) {
                    $this->error('Usage: php cli.php queue:failed forget <job_id>');
                    return;
                }
                $this->forget($id);
                break;
            case 'replay':
                $this->replayAll();
                break;
            default:
                $this->error("Unknown sub-action: {$subAction}");
                $this->line("Available: list, retry <id>, forget <id>, replay");
        }
    }
}