<?php

declare(strict_types=1);

namespace App\Commands;

use Core\Database;
use App\Contracts\LoggerInterface;
use App\Services\QueueWorker;
use App\Models\FailedJob;

/**
 * DLQRetryCommand
 *
 * Fetches failed jobs from the dead-letter queue (failed_jobs table)
 * and requeues them or purges them permanently.
 *
 * Usage:
 *   php console.php dlq:retry [queue_name] [--limit=50] [--exclude-fatal] [--exception=SomeClass]
 *   php console.php dlq:purge [older_than_days]
 */
class DLQRetryCommand
{
    private Database $db;
    private LoggerInterface $logger;
    private ?FailedJob $failedJobModel;

    public function __construct(?Database $db = null, ?LoggerInterface $logger = null, ?FailedJob $failedJobModel = null) {
        $this->db = $db ?? \Core\Database::getInstance();
        $this->logger = $logger ?? throw new \InvalidArgumentException('LoggerInterface is required');
        $this->failedJobModel = $failedJobModel ?? new FailedJob($this->db);
    }

    /** @param array<int|string, mixed> $args */

    public function execute(array $args): void
    {
        $action = $args[0] ?? 'retry';

        if ($action === 'purge') {
            $this->purge($args);
            return;
        }

        $queueName = $args[1] ?? 'default';
        $limit = 50;
        $excludeFatal = false;
        $exceptionFilter = '';

        foreach ($args as $rawArg) {
            if (!is_string($rawArg)) {
                continue;
            }
            $arg = $rawArg;
            if (str_starts_with($arg, '--limit=')) {
                $limit = (int)str_replace('--limit=', '', $arg);
            }
            if ($arg === '--exclude-fatal') {
                $excludeFatal = true;
            }
            if (str_starts_with($arg, '--exception=')) {
                $exceptionFilter = str_replace('--exception=', '', $arg);
            }
        }

        echo "Fetching up to {$limit} failed jobs for queue '{$queueName}'...\n";

        $failedJobs = $this->failedJobModel
            ? $this->failedJobModel->fetchByQueue($queueName, $limit)
            : $this->db->fetchAll("SELECT * FROM failed_jobs WHERE queue = ? ORDER BY id ASC LIMIT ?", [$queueName, $limit]);

        if (empty($failedJobs)) {
            echo "No failed jobs found.\n";
            return;
        }

        $retriedCount = 0;
        $skippedCount = 0;
        foreach ($failedJobs as $job) {
            if ($excludeFatal) {
                $exceptionStr = (string)$job->exception;
                if (
                    str_contains($exceptionStr, 'ValidationException') ||
                    str_contains($exceptionStr, 'BusinessException') ||
                    str_contains($exceptionStr, 'InvalidArgumentException') ||
                    str_contains($exceptionStr, 'TypeError')
                ) {
                    $skippedCount++;
                    continue;
                }
            }

            try {
                $this->db->beginTransaction();

                // Re-insert into main jobs table
                $this->db->execute(
                    "INSERT INTO jobs (queue, payload, attempts, reserved_at, available_at, created_at)
                     VALUES (?, ?, 0, NULL, UNIX_TIMESTAMP(), UNIX_TIMESTAMP())",
                    [$job->queue, $job->payload]
                );

                // Remove from failed_jobs
                $this->db->execute("DELETE FROM failed_jobs WHERE id = ?", [$job->id]);

                $this->db->commit();
                $retriedCount++;
                
                $this->logger->info("dlq.job_retried", ['failed_job_id' => $job->id]);
            } catch (\Exception $e) {
                $this->db->rollback();
                $this->logger->error("dlq.retry_failed", ['failed_job_id' => $job->id, 'error' => $e->getMessage()]);
                echo "Failed to retry job {$job->id}: {$e->getMessage()}\n";
            }
        }

        echo "Successfully requeued {$retriedCount} jobs.\n";
        if ($skippedCount > 0) {
            echo "Skipped {$skippedCount} fatal errors.\n";
        }
    }

    /**
     * CLI entry-point required by CliDispatcher.
     * Delegates to execute() with parsed arguments.
     */
    /** @param array<string, mixed> $args */
    public function run(array $args = []): void
    {
        // $args[0] = "dlq:retry", $args[1] = queue_name (optional), etc.
        $argv = array_slice($args, 1); // Remove the command name
        if (empty($argv)) {
            $argv = ['retry', 'default'];
        }
        $this->execute($argv);
    }

    /** @param array<int|string, mixed> $args */
    private function purge(array $args): void
    {
        $daysValue = $args[1] ?? 30;
        $days = is_numeric($daysValue) ? (int)$daysValue : 30;
        if ($days < 1) {
            echo "Days must be at least 1.\n";
            return;
        }

        echo "Purging failed jobs older than {$days} days...\n";

        $timestamp = strtotime("-{$days} days");
        $dateThreshold = date('Y-m-d H:i:s', $timestamp === false ? time() : $timestamp);

        $count = $this->failedJobModel
            ? $this->failedJobModel->purge($days)
            : (int) $this->db->execute("DELETE FROM failed_jobs WHERE failed_at < ?", [$dateThreshold]);

        echo "Purged {$count} failed jobs permanently.\n";
        $this->logger->info("dlq.purged", ['count' => $count, 'older_than_days' => $days]);
    }
}
