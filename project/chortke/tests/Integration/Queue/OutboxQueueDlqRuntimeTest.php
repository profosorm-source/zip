<?php

declare(strict_types=1);

namespace Tests\Integration\Queue;

use App\Services\DlqWorker;
use App\Services\OutboxPublisher;
use App\Services\OutboxService;
use App\Services\QueueWorker;
use Core\Application;
use Core\Cache;
use Core\Database;
use Core\EventDispatcher;
use Core\Queue;
use PHPUnit\Framework\TestCase;
use Tests\Fixtures\RuntimePoisonJob;
use Tests\Fixtures\RuntimeProbeJob;

/**
 * Real MariaDB queue/outbox/DLQ tests, including multi-process reservation.
 */
final class OutboxQueueDlqRuntimeTest extends TestCase
{
    private Database $db;
    private Cache $cache;
    private Queue $queue;
    private QueueWorker $queueWorker;
    private OutboxPublisher $publisher;
    private OutboxService $outbox;
    private DlqWorker $dlqWorker;
    private EventDispatcher $events;
    private string $marker;
    private string $queueName;
    private int $outputBufferLevel;

    protected function setUp(): void
    {
        parent::setUp();
        $this->outputBufferLevel = ob_get_level();
        ob_start();
        ini_set('error_log', sys_get_temp_dir() . '/chortke-queue-runtime-test.log');

        $container = Application::getInstance()->container;
        $this->db = $container->make(Database::class);
        $this->cache = $container->make(Cache::class);
        $this->queue = $container->make(Queue::class);
        $this->queueWorker = $container->make(QueueWorker::class);
        $this->publisher = $container->make(OutboxPublisher::class);
        $this->outbox = $container->make(OutboxService::class);
        $this->dlqWorker = $container->make(DlqWorker::class);
        $this->events = $container->make(EventDispatcher::class);
        $this->marker = 'phpunit_phase4_' . bin2hex(random_bytes(8));
        $this->queueName = 'runtime_phase4';

        $this->assertSame('database', config('queue.driver', 'database'), 'Runtime queue tests require the real database queue driver.');
    }

    protected function tearDown(): void
    {
        if (isset($this->marker)) {
            $like = '%' . $this->marker . '%';
            $this->db->query('DELETE FROM failed_jobs WHERE payload LIKE ?', [$like]);
            $this->db->query('DELETE FROM queues WHERE payload LIKE ?', [$like]);
            $this->db->query('DELETE FROM outbox_events WHERE aggregate_id = ? OR payload LIKE ?', [$this->marker, $like]);
            $this->db->query('DELETE FROM event_failures WHERE payload LIKE ?', [$like]);
            $this->db->query('DELETE FROM system_settings WHERE `key` = ?', [$this->marker]);
        }
        if (isset($this->cache)) {
            $this->cache->forget('queue_size_cache:' . $this->queueName);
            $this->cache->forget('queue_size_cache:default');
        }
        while (isset($this->outputBufferLevel) && ob_get_level() > $this->outputBufferLevel) {
            ob_end_clean();
        }
        parent::tearDown();
    }

    public function test_generic_outbox_event_is_dispatched_and_marked_published(): void
    {
        $eventType = 'phpunit.runtime.event.' . bin2hex(random_bytes(5));
        $received = [];
        $this->events->listen($eventType, static function (\Core\Event $event) use (&$received): void {
            $received[] = $event->getData();
        });

        $this->assertTrue($this->outbox->record('phpunit', $this->marker, $eventType, ['marker' => $this->marker]));
        $this->db->query("UPDATE outbox_events SET created_at='2000-01-01 00:00:00',available_at=NOW() WHERE aggregate_id=?", [$this->marker]);
        $result = $this->publisher->publishPending(1);

        $this->assertSame(1, int_value($result['published'] ?? 0));
        $this->assertSame(0, int_value($result['failed'] ?? 0));
        $this->assertCount(1, $received);
        $this->assertSame($this->marker, $received[0]['marker'] ?? null);

        $row = $this->outboxRow();
        $this->assertSame('published', (string) $row->status);
        $this->assertSame(1, (int) $row->attempts);
        $this->assertNotNull($row->published_at);
    }

    public function test_failed_outbox_event_is_retried_then_moved_to_dlq_at_threshold(): void
    {
        $eventType = 'phpunit.runtime.failure.' . bin2hex(random_bytes(5));
        $this->events->listen($eventType, static function (): void {
            throw new \RuntimeException('deterministic outbox listener failure');
        });

        $this->insertOutbox($eventType, ['marker' => $this->marker], 0);
        $first = $this->publisher->publishPending(1);
        $this->assertSame(1, int_value($first['failed'] ?? 0));
        $this->assertSame(0, int_value($first['dlq'] ?? 0));
        $row = $this->outboxRow();
        $this->assertSame('pending', (string) $row->status);
        $this->assertSame(1, (int) $row->attempts);
        $this->assertStringContainsString('deterministic outbox listener failure', (string) $row->last_error);
        $this->assertGreaterThan(time(), strtotime((string) $row->available_at));

        $this->db->query("UPDATE outbox_events SET attempts=2,available_at=NOW(),status='pending' WHERE id=?", [(int) $row->id]);
        $second = $this->publisher->publishPending(1);
        $this->assertSame(1, int_value($second['failed'] ?? 0));
        $this->assertSame(1, int_value($second['dlq'] ?? 0));
        $row = $this->outboxRow();
        $this->assertSame('dlq', (string) $row->status);
        $this->assertSame(3, (int) $row->attempts);
    }

    public function test_two_outbox_publishers_reserve_one_event_and_enqueue_one_job(): void
    {
        $this->insertOutbox('phpunit.runtime.enqueue', [
            'job' => RuntimeProbeJob::class,
            'data' => ['marker' => $this->marker],
            'queue' => $this->queueName,
        ], 0);

        $results = $this->runConcurrentProcesses(base_path('tests/Support/outbox_runtime_worker.php'), []);
        foreach ($results as $result) {
            $this->assertTrue((bool) ($result['ok'] ?? false), (json_encode($result) ?: ''));
        }

        $row = $this->outboxRow();
        $this->assertSame('published', (string) $row->status);
        $this->assertSame(1, (int) $row->attempts);
        $this->assertSame(1, $this->queueRowsForMarker());
    }

    public function test_queue_worker_executes_real_job_once_and_deletes_reservation(): void
    {
        $this->assertTrue($this->queue->push(RuntimeProbeJob::class, ['marker' => $this->marker], $this->queueName));

        $result = $this->queueWorker->work($this->queueName, 1, [RuntimeProbeJob::class]);

        $this->assertSame(1, int_value($result['processed_jobs'] ?? 0));
        $this->assertSame(0, int_value($result['failed_jobs'] ?? 0));
        $this->assertSame('1', (string) $this->db->fetchColumn('SELECT `value` FROM system_settings WHERE `key`=?', [$this->marker]));
        $this->assertSame(0, $this->queueRowsForMarker());
    }

    public function test_two_queue_workers_reserve_and_execute_one_job_exactly_once(): void
    {
        $this->assertTrue($this->queue->push(RuntimeProbeJob::class, ['marker' => $this->marker], $this->queueName));

        $results = $this->runConcurrentProcesses(base_path('tests/Support/queue_runtime_worker.php'), [$this->queueName]);
        foreach ($results as $result) {
            $this->assertTrue((bool) ($result['ok'] ?? false), (json_encode($result) ?: ''));
        }

        $processed = array_sum(array_map(static fn(array $r): int => (int) ($r['result']['processed_jobs'] ?? 0), $results));
        $this->assertSame(1, $processed);
        $this->assertSame('1', (string) $this->db->fetchColumn('SELECT `value` FROM system_settings WHERE `key`=?', [$this->marker]));
        $this->assertSame(0, $this->queueRowsForMarker());
    }

    public function test_poison_job_moves_to_failed_jobs_and_dlq_worker_archives_it(): void
    {
        $this->assertTrue($this->queue->push(RuntimePoisonJob::class, ['marker' => $this->marker], $this->queueName));
        $result = $this->queueWorker->work($this->queueName, 1, [RuntimePoisonJob::class]);

        $this->assertSame(0, int_value($result['processed_jobs'] ?? 0));
        $this->assertSame(1, int_value($result['failed_jobs'] ?? 0));
        $failed = $this->db->fetch('SELECT * FROM failed_jobs WHERE payload LIKE ? ORDER BY id DESC LIMIT 1', ['%' . $this->marker . '%']);
        $this->assertInstanceOf(\stdClass::class, $failed);
        $this->assertSame('database', (string) $failed->source_driver);
        $this->assertSame(64, strlen((string) $failed->source_job_key));
        $this->assertSame('permanent', (string) $failed->error_classification);
        $this->assertSame('dead_letter', (string) $failed->status);
        $this->assertStringContainsString('TypeError', (string) $failed->exception);
        $this->assertSame(0, $this->queueRowsForMarker());

        $dlq = $this->dlqWorker->work(1);
        $this->assertSame(1, int_value($dlq['processed'] ?? 0));
        $this->assertSame(1, int_value($dlq['archived'] ?? 0));
        $this->assertSame(0, (int) $this->db->fetchColumn('SELECT COUNT(*) FROM failed_jobs WHERE payload LIKE ?', ['%' . $this->marker . '%']));
    }

    public function test_stale_queue_reservation_is_recovered_and_processed_once(): void
    {
        $payload = json_encode([
            'job' => RuntimeProbeJob::class,
            'data' => ['marker' => $this->marker],
            'meta' => [],
        ], JSON_THROW_ON_ERROR);
        $this->db->query(
            "INSERT INTO queues (queue,payload,attempts,reserved_at,available_at,created_at)"
            . " VALUES (?, ?, 1, DATE_SUB(NOW(), INTERVAL 10 MINUTE), NOW(), NOW())",
            [$this->queueName, $payload]
        );
        $jobId = (int) $this->db->lastInsertId();

        $result = $this->queueWorker->work($this->queueName, 1, [RuntimeProbeJob::class]);

        $this->assertSame(1, int_value($result['processed_jobs'] ?? 0));
        $this->assertSame(0, int_value($result['failed_jobs'] ?? 0));
        $this->assertSame(0, (int) $this->db->fetchColumn('SELECT COUNT(*) FROM queues WHERE id=?', [$jobId]));
        $this->assertSame('1', (string) $this->db->fetchColumn('SELECT `value` FROM system_settings WHERE `key`=?', [$this->marker]));
    }

    public function test_zombie_outbox_processing_state_is_recovered_before_publish(): void
    {
        $eventType = 'phpunit.runtime.zombie.' . bin2hex(random_bytes(5));
        $received = 0;
        $this->events->listen($eventType, static function () use (&$received): void {
            $received++;
        });
        $this->db->query(
            "INSERT INTO outbox_events (aggregate_type,aggregate_id,event_type,payload,status,attempts,available_at,created_at,updated_at)"
            . " VALUES ('phpunit', ?, ?, ?, 'processing', 0, NOW(), '2000-01-01 00:00:00', DATE_SUB(NOW(), INTERVAL 10 MINUTE))",
            [$this->marker, $eventType, json_encode(['marker' => $this->marker], JSON_THROW_ON_ERROR)]
        );

        $result = $this->publisher->publishPending(1);

        $this->assertSame(1, int_value($result['published'] ?? 0));
        $this->assertSame(1, $received);
        $row = $this->outboxRow();
        $this->assertSame('published', (string) $row->status);
        $this->assertSame(1, (int) $row->attempts);
    }

    public function test_transient_dlq_job_is_requeued_with_incremented_attempt_and_delay(): void
    {
        $payload = json_encode([
            'job' => RuntimeProbeJob::class,
            'data' => ['marker' => $this->marker, 'attempts' => 1],
            'meta' => [],
        ], JSON_THROW_ON_ERROR);
        $this->db->query(
            "INSERT INTO failed_jobs (connection,queue,payload,exception,error_classification,status,retry_count,failed_at)"
            . " VALUES ('database', ?, ?, 'RuntimeException: temporary outage', 'transient', 'retrying', 1, NOW())",
            [$this->queueName, $payload]
        );

        $result = $this->dlqWorker->work(1);

        $this->assertSame(1, int_value($result['processed'] ?? 0));
        $this->assertSame(0, int_value($result['archived'] ?? 0));
        $this->assertSame(0, (int) $this->db->fetchColumn('SELECT COUNT(*) FROM failed_jobs WHERE payload LIKE ?', ['%' . $this->marker . '%']));
        $queued = $this->db->fetch('SELECT * FROM queues WHERE payload LIKE ? ORDER BY id DESC LIMIT 1', ['%' . $this->marker . '%']);
        $this->assertInstanceOf(\stdClass::class, $queued);
        $decoded = $this->decodeArray((string)$queued->payload);
        $data = $this->requireArray($decoded['data'] ?? null);
        $this->assertSame(2, int_value($data['attempts'] ?? 0));
        $this->assertGreaterThan(time(), strtotime((string) $queued->available_at));
    }

    /** @param array<string,mixed> $payload */
    private function insertOutbox(string $eventType, array $payload, int $attempts): void
    {
        $this->db->query(
            "INSERT INTO outbox_events (aggregate_type,aggregate_id,event_type,payload,status,attempts,available_at,created_at,updated_at)"
            . " VALUES ('phpunit', ?, ?, ?, 'pending', ?, NOW(), '2000-01-01 00:00:00', NOW())",
            [$this->marker, $eventType, json_encode($payload, JSON_THROW_ON_ERROR), $attempts]
        );
    }

    private function outboxRow(): \stdClass
    {
        $row = $this->db->fetch('SELECT * FROM outbox_events WHERE aggregate_id=? ORDER BY id DESC LIMIT 1', [$this->marker]);
        $this->assertInstanceOf(\stdClass::class, $row);
        return $row;
    }

    private function queueRowsForMarker(): int
    {
        return (int) $this->db->fetchColumn('SELECT COUNT(*) FROM queues WHERE payload LIKE ?', ['%' . $this->marker . '%']);
    }

    private function tempFile(string $prefix): string
    {
        $file = tempnam(sys_get_temp_dir(), $prefix);
        if (!is_string($file)) $this->fail('Unable to allocate queue worker file.');
        return $file;
    }

    /** @return array<int|string,mixed> */
    private function decodeArray(string $json): array
    {
        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsArray($decoded);
        return $decoded;
    }

    /** @return array<int|string,mixed> */
    private function requireArray(mixed $value): array
    {
        $this->assertIsArray($value);
        return $value;
    }

    /**
     * @param list<string> $arguments
     * @return list<array<int|string,mixed>>
     */
    private function runConcurrentProcesses(string $script, array $arguments): array
    {
        $resultFiles = [$this->tempFile('phase4-result-a-'), $this->tempFile('phase4-result-b-')];
        $logFiles = [$this->tempFile('phase4-log-a-'), $this->tempFile('phase4-log-b-')];

        $processes = [];
        foreach ([0, 1] as $index) {
            $command = array_merge([PHP_BINARY, $script], $arguments, [$resultFiles[$index]]);
            $process = proc_open($command, [
                0 => ['file', '/dev/null', 'r'],
                1 => ['file', $logFiles[$index], 'a'],
                2 => ['file', $logFiles[$index], 'a'],
            ], $pipes, base_path());
            if (!is_resource($process)) $this->fail('Unable to start queue worker.');
            $processes[] = $process;
        }

        $exitCodes = array_map(static fn($process): int => proc_close($process), $processes);
        try {
            $this->assertSame([0, 0], $exitCodes, implode("\n", array_map(static fn(string $f): string => (string) file_get_contents($f), $logFiles)));
            return array_map(
                fn(string $file): array => $this->decodeArray(str_value(file_get_contents($file))),
                $resultFiles
            );
        } finally {
            foreach (array_merge($resultFiles, $logFiles) as $file) {
                if (is_string($file) && is_file($file)) unlink($file);
            }
        }
    }
}
