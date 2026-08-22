<?php

declare(strict_types=1);

namespace Tests\Integration\Queue;

use App\Services\QueueWorker;
use Core\Application;
use Core\Cache;
use Core\Database;
use Core\Queue;
use PHPUnit\Framework\TestCase;
use Tests\Fixtures\RuntimeBusinessFailureJob;

/**
 * طبقه‌بندی خطاهای تجاری در QueueWorker روی MariaDB واقعی.
 *
 * رگرسیون: پیش‌تر QueueWorker فقط \App\Exceptions\BusinessException را بررسی
 * می‌کرد، در حالی که کد واقعی عمدتاً \Core\Exceptions\BusinessException را
 * پرتاب می‌کند و App فرزندِ Core است. در نتیجه خطای تجاریِ قطعی، fatal شناخته
 * نمی‌شد (تا سقف تلاش‌ها retry می‌شد) و به‌جای business/quarantined با برچسب
 * unknown/pending_analysis در failed_jobs ثبت می‌گردید.
 */
final class BusinessExceptionClassificationRuntimeTest extends TestCase
{
    private Database $db;
    private Cache $cache;
    private Queue $queue;
    private QueueWorker $queueWorker;
    private string $marker;
    private string $queueName;
    private int $outputBufferLevel;

    protected function setUp(): void
    {
        parent::setUp();
        $this->outputBufferLevel = ob_get_level();
        ob_start();
        ini_set('error_log', sys_get_temp_dir() . '/chortke-queue-business-test.log');

        $container = Application::getInstance()->container;
        $this->db = $container->make(Database::class);
        $this->cache = $container->make(Cache::class);
        $this->queue = $container->make(Queue::class);
        $this->queueWorker = $container->make(QueueWorker::class);
        $this->marker = 'phpunit_business_' . bin2hex(random_bytes(8));
        $this->queueName = 'runtime_business';

        $this->assertSame(
            'database',
            config('queue.driver', 'database'),
            'Runtime queue tests require the real database queue driver.'
        );
    }

    protected function tearDown(): void
    {
        if (isset($this->marker)) {
            $like = '%' . $this->marker . '%';
            $this->db->query('DELETE FROM failed_jobs WHERE payload LIKE ?', [$like]);
            $this->db->query('DELETE FROM queues WHERE payload LIKE ?', [$like]);
        }
        if (isset($this->cache)) {
            $this->cache->forget('queue_size_cache:' . $this->queueName);
        }
        while (isset($this->outputBufferLevel) && ob_get_level() > $this->outputBufferLevel) {
            ob_end_clean();
        }
        parent::tearDown();
    }

    /** @return list<array{0:string}> */
    public static function businessExceptionKindProvider(): array
    {
        return [
            'Core\\BusinessException' => ['core'],
            'App\\BusinessException' => ['app'],
            'Core\\DomainException' => ['domain'],
            'Core\\InsufficientBalanceException' => ['insufficient_balance'],
            'Core\\InvalidStateException' => ['invalid_state'],
        ];
    }

    /**
     * @dataProvider businessExceptionKindProvider
     */
    public function test_business_exception_is_quarantined_without_retry(string $kind): void
    {
        $this->assertTrue($this->queue->push(
            RuntimeBusinessFailureJob::class,
            ['marker' => $this->marker, 'business_kind' => $kind],
            $this->queueName
        ));

        $result = $this->queueWorker->work($this->queueName, 1, [RuntimeBusinessFailureJob::class]);

        $this->assertSame(0, int_value($result['processed_jobs'] ?? 0));
        $this->assertSame(1, int_value($result['failed_jobs'] ?? 0));

        $failed = $this->db->fetch(
            'SELECT * FROM failed_jobs WHERE payload LIKE ? ORDER BY id DESC LIMIT 1',
            ['%' . $this->marker . '%']
        );
        $this->assertInstanceOf(\stdClass::class, $failed);

        // طبقه‌بندی باید «تجاری» باشد، نه unknown/pending_analysis.
        $this->assertSame('business', (string) $failed->error_classification);
        $this->assertSame('quarantined', (string) $failed->status);

        // خطای تجاری قطعی است: نباید هیچ تلاش مجددی در صف باقی مانده باشد.
        $this->assertSame(
            0,
            (int) $this->db->fetchColumn(
                'SELECT COUNT(*) FROM queues WHERE payload LIKE ?',
                ['%' . $this->marker . '%']
            ),
            'A deterministic business failure must not be left in the queue for retry.'
        );
    }

    /**
     * خطای تجاری باید بلافاصله fatal تلقی شود؛ یعنی حتی در اولین تلاش
     * (attempts = 1) مستقیماً به failed_jobs برود و release/retry نشود.
     */
    public function test_business_failure_does_not_consume_retry_attempts(): void
    {
        $this->assertGreaterThan(
            1,
            $this->queue->getMaxAttempts(),
            'This regression is only meaningful when the queue allows more than one attempt.'
        );

        $this->assertTrue($this->queue->push(
            RuntimeBusinessFailureJob::class,
            ['marker' => $this->marker, 'business_kind' => 'core'],
            $this->queueName
        ));

        $this->queueWorker->work($this->queueName, 1, [RuntimeBusinessFailureJob::class]);

        $failed = $this->db->fetch(
            'SELECT * FROM failed_jobs WHERE payload LIKE ? ORDER BY id DESC LIMIT 1',
            ['%' . $this->marker . '%']
        );
        $this->assertInstanceOf(\stdClass::class, $failed);

        // Core\Queue::persistFailedJob رکورد را همواره با retry_count=0 درج می‌کند،
        // پس تمایز واقعی «قرنطینه فوری» در برابر «تلاش مجدد» در ستون status است:
        // مسیر business باید quarantined باشد، نه pending_analysis (رفتار باگ قبلی).
        $this->assertSame(
            'quarantined',
            (string) $failed->status,
            'A fatal business failure must be quarantined on the first attempt, not queued for re-analysis/retry.'
        );
        $this->assertSame('business', (string) $failed->error_classification);

        // و هیچ نسخه‌ای از شغل نباید برای تلاش دوباره در صف باقی مانده باشد.
        $this->assertSame(
            0,
            (int) $this->db->fetchColumn(
                'SELECT COUNT(*) FROM queues WHERE payload LIKE ?',
                ['%' . $this->marker . '%']
            ),
            'No copy of a fatal business job may remain queued for another attempt.'
        );
    }

    /**
     * محافظ رگرسیون در سطح نوع: سلسله‌مراتب واقعی پروژه باید طوری باشد که
     * بررسیِ نوع پایه، لایه App را نیز پوشش دهد.
     */
    public function test_app_business_exception_is_a_core_business_exception(): void
    {
        $this->assertInstanceOf(
            \Core\Exceptions\BusinessException::class,
            new \App\Exceptions\BusinessException('probe'),
            'App\\Exceptions\\BusinessException must extend the Core base type.'
        );
    }
}
