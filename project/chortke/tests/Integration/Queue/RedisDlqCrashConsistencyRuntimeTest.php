<?php

declare(strict_types=1);

namespace Tests\Integration\Queue;

use Core\Application;
use Core\Cache;
use Core\Database;
use Core\Queue;
use Mockery as m;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class RedisDlqCrashConsistencyRuntimeTest extends TestCase
{
    private Database $db;
    private string $marker;

    protected function setUp(): void
    {
        parent::setUp();
        $this->db = Application::getInstance()->container->make(Database::class);
        $this->marker = 'phase20_dlq_' . bin2hex(random_bytes(8));
        $this->expectOutputRegex('/.*/');
    }

    protected function tearDown(): void
    {
        $this->db->execute('DELETE FROM failed_jobs WHERE payload LIKE ?', ['%' . $this->marker . '%']);
        m::close();
        parent::tearDown();
    }

    public function test_retry_after_crash_between_dlq_commit_and_redis_delete_is_idempotent(): void
    {
        $jobId = random_int(100_000, 999_999);
        $queueName = 'phase20.dlq';
        $payload = json_encode([
            'job' => 'App\\Jobs\\LogPerformanceJob',
            'data' => ['marker' => $this->marker],
        ], JSON_THROW_ON_ERROR);
        $metadata = json_encode([
            'id' => $jobId,
            'queue' => $queueName,
            'payload' => $payload,
            'attempts' => 5,
            'available_at' => time(),
            'created_at' => 1_786_000_000,
            'reserved_at' => time(),
        ], JSON_THROW_ON_ERROR);

        $firstRedis = m::mock(\Redis::class);
        $firstRedis->shouldReceive('get')->once()->andReturn($metadata);
        $firstRedis->shouldReceive('zRem')->once()->with(
            "chortke:queue:{$queueName}:pending",
            (string)$jobId
        )->andReturn(1);
        $firstRedis->shouldReceive('zRem')->once()->with(
            "chortke:queue:{$queueName}:reserved",
            (string)$jobId
        )->andThrow(new RuntimeException('process lost Redis connection after DLQ commit'));
        $firstRedis->shouldNotReceive('del');

        try {
            $this->redisQueue($firstRedis)->fail(
                $jobId,
                new RuntimeException('poison attempt one'),
                'permanent',
                'dead_letter'
            );
            $this->fail('The first transfer must report pending Redis cleanup.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('DLQ record committed', $e->getMessage());
        }

        $row = $this->failedRow();
        $this->assertInstanceOf(\stdClass::class, $row);
        $this->assertSame('redis', $row->source_driver);
        $this->assertSame($queueName, $row->queue);
        $this->assertSame(64, strlen($row->source_job_key));
        $this->assertSame(1, $this->failedCount());
        $this->assertFalse($this->db->inTransaction());

        $retryRedis = m::mock(\Redis::class);
        $retryRedis->shouldReceive('get')->once()->andReturn($metadata);
        $retryRedis->shouldReceive('zRem')->twice()->andReturn(0, 1);
        $retryRedis->shouldReceive('del')->once()->with("chortke:queue:job:{$jobId}")->andReturn(1);

        $this->assertTrue($this->redisQueue($retryRedis)->fail(
            $jobId,
            new RuntimeException('poison attempt two'),
            'permanent',
            'dead_letter'
        ));

        $this->assertSame(1, $this->failedCount(), 'Retry must update, not duplicate, the durable DLQ record.');
        $updated = $this->failedRow();
        $this->assertInstanceOf(\stdClass::class, $updated);
        $this->assertStringContainsString('poison attempt two', $updated->exception);
        $this->assertFalse($this->db->inTransaction());
    }

    private function redisQueue(\Redis $redis): Queue
    {
        $cache = m::mock(Cache::class);
        $cache->shouldReceive('redis')->once()->andReturn($redis);

        return new Queue($this->db, $cache, 'redis');
    }

    private function failedCount(): int
    {
        return (int)$this->db->fetchColumn(
            'SELECT COUNT(*) FROM failed_jobs WHERE payload LIKE ?',
            ['%' . $this->marker . '%']
        );
    }

    private function failedRow(): ?\stdClass
    {
        $row = $this->db->fetch(
            'SELECT * FROM failed_jobs WHERE payload LIKE ? ORDER BY id DESC LIMIT 1',
            ['%' . $this->marker . '%']
        );

        return $row instanceof \stdClass ? $row : null;
    }
}
