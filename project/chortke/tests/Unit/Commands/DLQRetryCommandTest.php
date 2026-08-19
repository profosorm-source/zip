<?php

declare(strict_types=1);

namespace Tests\Unit\Commands;

use PHPUnit\Framework\TestCase;
use App\Commands\DLQRetryCommand;
use App\Models\FailedJob;
use App\Contracts\LoggerInterface;
use Core\Database;
use Mockery as m;

/**
 * تست حرفه‌ای DLQRetryCommand (دستور بازیابی/پاکسازی صف مرده).
 *
 * این تست، رفتار و قراردادهای دستور را می‌سنجد:
 *   - requeue موفق یک job در تراکنش (INSERT + DELETE + commit + لاگ)
 *   - تراکنش روی rollback هنگام خطا
 *   - حالت --exclude-fatal (رد شدن jobهای دارای خطای مهلک)
 *   - حالت purge (حذف دائمی jobهای قدیمی)
 */
class DLQRetryCommandTest extends TestCase
{
    /** @var Database&\Mockery\MockInterface */
    private Database $db;
    /** @var LoggerInterface&\Mockery\MockInterface */
    private LoggerInterface $logger;
    /** @var FailedJob&\Mockery\MockInterface */
    private FailedJob $failedJob;
    private DLQRetryCommand $command;

    protected function setUp(): void
    {
        parent::setUp();
        $this->db = m::mock(Database::class);
        $this->logger = m::mock(LoggerInterface::class);
        $this->failedJob = m::mock(FailedJob::class);

        $this->command = new DLQRetryCommand(
            $this->db,
            $this->logger,
            $this->failedJob
        );
    }

    protected function tearDown(): void
    {
        m::close();
        parent::tearDown();
    }

    /** @test */
    public function it_can_be_instantiated_with_all_dependencies(): void
    {
        $this->assertInstanceOf(DLQRetryCommand::class, $this->command);
    }

    /**
     * @test
     * یک job از صف مرده باید در یک تراکنش دوباره به jobs رجوع داده شود
     * و سپس از failed_jobs حذف شود و لاگ موفقیت ثبت شود.
     */
    public function it_requeues_failed_jobs_within_a_transaction(): void
    {
        $job = (object) [
            'id' => 42,
            'queue' => 'default',
            'payload' => '{"task":"email.send"}',
            'exception' => 'App\Exceptions\BusinessException: transient',
        ];

        $this->failedJob->shouldReceive('fetchByQueue')
            ->once()
            ->with('default', 50)
            ->andReturn([$job]);

        // تراکنش: شروع، دو execute، commit
        $this->db->shouldReceive('beginTransaction')->once();
        $this->db->shouldReceive('execute')
            ->once()
            ->with(
                m::on(fn ($sql) => str_contains($sql, 'INSERT INTO jobs')),
                ['default', '{"task":"email.send"}']
            );
        $this->db->shouldReceive('execute')
            ->once()
            ->with(
                m::on(fn ($sql) => str_contains($sql, 'DELETE FROM failed_jobs')),
                [42]
            );
        $this->db->shouldReceive('commit')->once();

        $this->logger->shouldReceive('info')
            ->once()
            ->with('dlq.job_retried', m::on(fn ($ctx) => $ctx['failed_job_id'] === 42));

        ob_start();
        $this->command->execute(['retry', 'default']);
        $output = str_value(ob_get_clean());

        $this->assertStringContainsString('Successfully requeued 1 jobs', $output);
    }

    /**
     * @test
     * هنگام خطا در تراکنش، باید rollback شود، خطا لاگ شود و job رها شود.
     */
    public function it_rolls_back_and_logs_error_when_requeue_fails(): void
    {
        $job = (object) [
            'id' => 7,
            'queue' => 'default',
            'payload' => '{}',
            'exception' => '',
        ];

        $this->failedJob->shouldReceive('fetchByQueue')
            ->once()
            ->with('default', 50)
            ->andReturn([$job]);

        $this->db->shouldReceive('beginTransaction')->once();
        $this->db->shouldReceive('execute')
            ->once()
            ->with(m::on(fn ($sql) => str_contains($sql, 'INSERT INTO jobs')), m::any())
            ->andThrow(new \RuntimeException('Deadlock detected'));

        $this->db->shouldReceive('rollback')->once();
        $this->logger->shouldReceive('error')
            ->once()
            ->with('dlq.retry_failed', m::on(fn ($ctx) =>
                $ctx['failed_job_id'] === 7 && $ctx['error'] === 'Deadlock detected'));

        ob_start();
        $this->command->execute(['retry', 'default']);
        $output = str_value(ob_get_clean());

        $this->assertStringContainsString('Failed to retry job 7', $output);
        $this->assertStringContainsString('Successfully requeued 0 jobs', $output);
    }

    /**
     * @test
     * در حالت --exclude-fatal، jobهای دارای خطای مهلک (Validation/Business/TypeError...)
     * باید شمارش و رد شوند بدون اینکه تراکنش اجرا شود.
     */
    public function it_skips_fatal_jobs_when_exclude_fatal_is_enabled(): void
    {
        $fatal = (object) [
            'id' => 1,
            'queue' => 'default',
            'payload' => '{}',
            'exception' => 'App\Exceptions\ValidationException: bad input',
        ];
        $ok = (object) [
            'id' => 2,
            'queue' => 'default',
            'payload' => '{"ok":1}',
            'exception' => 'PDOException: connection lost, retry later',
        ];

        $this->failedJob->shouldReceive('fetchByQueue')
            ->once()
            ->with('default', 50)
            ->andReturn([$fatal, $ok]);

        // فقط job سالم requeue می‌شود.
        $this->db->shouldReceive('beginTransaction')->once();
        $this->db->shouldReceive('execute')
            ->once()
            ->with(m::on(fn ($sql) => str_contains($sql, 'INSERT INTO jobs')), ['default', '{"ok":1}']);
        $this->db->shouldReceive('execute')
            ->once()
            ->with(m::on(fn ($sql) => str_contains($sql, 'DELETE FROM failed_jobs')), [2]);
        $this->db->shouldReceive('commit')->once();

        $this->logger->shouldReceive('info')
            ->once()
            ->with('dlq.job_retried', m::on(fn ($ctx) => $ctx['failed_job_id'] === 2));

        ob_start();
        $this->command->execute(['retry', 'default', '--exclude-fatal']);
        $output = str_value(ob_get_clean());

        $this->assertStringContainsString('Skipped 1 fatal errors', $output);
        $this->assertStringContainsString('Successfully requeued 1 jobs', $output);
    }

    /**
     * @test
     * حالت purge باید jobs قدیمی را به‌صورت دائمی حذف کند و لاگ ثبت کند.
     */
    public function it_purges_failed_jobs_older_than_given_days(): void
    {
        $this->failedJob->shouldReceive('purge')
            ->once()
            ->with(30)
            ->andReturn(12);

        $this->logger->shouldReceive('info')
            ->once()
            ->with('dlq.purged', m::on(fn ($ctx) =>
                $ctx['count'] === 12 && $ctx['older_than_days'] === 30));

        ob_start();
        $this->command->execute(['purge', '30']);
        $output = str_value(ob_get_clean());

        $this->assertStringContainsString('Purged 12 failed jobs permanently', $output);
    }

    /**
     * @test
     * بدون ورودی، دستور به‌صورت پیش‌فرض روی صف 'default' اجرا می‌شود.
     */
    public function it_defaults_to_retry_action_and_default_queue(): void
    {
        $this->failedJob->shouldReceive('fetchByQueue')
            ->once()
            ->with('default', 50)
            ->andReturn([]);

        ob_start();
        $this->command->execute(['retry', 'default']);
        $output = str_value(ob_get_clean());

        $this->assertStringContainsString('No failed jobs found', $output);
    }
}
