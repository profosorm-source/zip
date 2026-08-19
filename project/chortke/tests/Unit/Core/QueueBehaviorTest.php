<?php

declare(strict_types=1);

namespace Tests\Unit\Core;

use Core\Cache;
use Core\Database;
use Core\QueryBuilder;
use Core\Queue;
use Mockery as m;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class QueueBehaviorTest extends TestCase
{
    protected function tearDown(): void
    {
        m::close();
        parent::tearDown();
    }

    public function test_database_driver_maps_jobs_to_prioritized_queue(): void
    {
        $db = m::mock(Database::class);
        $table = m::mock(QueryBuilder::class);
        $db->shouldReceive('table')->with('queues')->once()->andReturn($table);
        $table->shouldReceive('insert')->with(m::on(
            static fn(array $row): bool => $row['queue'] === 'notifications'
        ))->once()->andReturn(true);

        $queue = $this->databaseQueue($db);

        $this->assertSame(5, $queue->getMaxAttempts());
        $this->assertTrue($queue->push('App\\Jobs\\SendEmailJob', ['email' => 'test@example.com']));
    }

    public function test_database_driver_generates_status_report_from_database_only(): void
    {
        $db = m::mock(Database::class);
        $db->shouldReceive('selectOne')->andReturn((object)['c' => 5]);

        $report = $this->databaseQueue($db)->getQueueStatusReport();

        $high = $report['high_priority'] ?? null;
        $meta = $report['meta'] ?? null;
        $this->assertIsArray($high);
        $this->assertIsArray($meta);
        $this->assertSame(5, $high['total_jobs']);
        $this->assertSame(5, $meta['total_failed_dlq']);
    }

    public function test_redis_driver_pushes_without_touching_database_queue(): void
    {
        $redis = m::mock(\Redis::class);
        $redis->shouldReceive('incr')->once()->with('chortke:queue:job_id_counter')->andReturn(42);
        $redis->shouldReceive('setEx')->once()->with(
            'chortke:queue:job:42',
            86400 * 7,
            m::on(static function (string $payload): bool {
                $data = json_decode($payload, true);
                return is_array($data) && $data['id'] === 42 && $data['queue'] === 'notifications';
            })
        )->andReturn(true);
        $redis->shouldReceive('zAdd')->once()->with(
            'chortke:queue:notifications:pending',
            m::any(),
            '42'
        )->andReturn(1);

        $db = m::mock(Database::class);
        $db->shouldNotReceive('table');

        $result = $this->redisQueue($db, $redis)->push(
            'App\\Jobs\\SendEmailJob',
            ['email' => 'test@example.com']
        );

        $this->assertTrue($result);
    }

    public function test_redis_driver_deletes_job_without_cross_driver_fallback(): void
    {
        $redis = m::mock(\Redis::class);
        $redis->shouldReceive('get')->once()->andReturn(json_encode(['queue' => 'default']));
        $redis->shouldReceive('zRem')->twice()->andReturn(1);
        $redis->shouldReceive('del')->once()->andReturn(1);
        $db = m::mock(Database::class);
        $db->shouldNotReceive('table');

        $this->assertTrue($this->redisQueue($db, $redis)->delete(42));
    }

    public function test_redis_driver_releases_job_without_cross_driver_fallback(): void
    {
        $redis = m::mock(\Redis::class);
        $redis->shouldReceive('get')->once()->andReturn(json_encode([
            'queue' => 'default',
            'attempts' => 2,
        ]));
        $redis->shouldReceive('setEx')->once()->andReturn(true);
        $redis->shouldReceive('zRem')->once()->andReturn(1);
        $redis->shouldReceive('zAdd')->once()->andReturn(1);
        $db = m::mock(Database::class);
        $db->shouldNotReceive('table');

        $this->assertTrue($this->redisQueue($db, $redis)->release(42, 60));
    }

    public function test_ambiguous_redis_pop_failure_never_reserves_database_job(): void
    {
        $this->expectOutputRegex('/.*/');
        $redis = m::mock(\Redis::class);
        $cache = m::mock(Cache::class);
        $cache->shouldReceive('redis')->once()->andReturn($redis);
        $db = m::mock(Database::class);
        $db->shouldNotReceive('beginTransaction');
        $db->shouldNotReceive('selectOne');

        $queue = new class($db, $cache, 'redis') extends Queue {
            private int $evalCalls = 0;

            protected function runRedisEval(string $script, array $args, int $numKeys): mixed
            {
                $this->evalCalls++;
                if ($this->evalCalls === 1) {
                    return 0;
                }

                throw new RuntimeException('connection lost after reservation');
            }
        };

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('ambiguous reservation outcome');

        $queue->pop('default');
    }

    public function test_ambiguous_redis_push_failure_never_inserts_database_duplicate(): void
    {
        $this->expectOutputRegex('/.*/');
        $redis = m::mock(\Redis::class);
        $redis->shouldReceive('incr')->once()->andReturn(42);
        $redis->shouldReceive('setEx')->once()->andReturn(true);
        $redis->shouldReceive('zAdd')->once()->andThrow(new RuntimeException('connection lost after write'));

        $db = m::mock(Database::class);
        $db->shouldNotReceive('table');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('ambiguous outcome');

        $this->redisQueue($db, $redis)->push('App\\Jobs\\LogPerformanceJob', ['key' => 'value']);
    }

    public function test_redis_driver_fails_during_composition_without_active_connection(): void
    {
        $cache = m::mock(Cache::class);
        $cache->shouldReceive('redis')->once()->andReturn(null);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('requires an active Redis connection');

        new Queue(m::mock(Database::class), $cache, 'redis');
    }

    /**
     * @dataProvider invalidQueueNameProvider
     */
    public function test_invalid_queue_name_is_rejected_before_storage_access(string $queueName): void
    {
        $db = m::mock(Database::class);
        $db->shouldNotReceive('table');

        $this->expectException(\InvalidArgumentException::class);

        $this->databaseQueue($db)->push('App\\Jobs\\LogPerformanceJob', [], $queueName);
    }

    /** @return array<string, array{0: string}> */
    public function invalidQueueNameProvider(): array
    {
        return [
            'whitespace' => ['critical queue'],
            'path separator' => ['critical/queue'],
            'control character' => ["critical\nqueue"],
            'too long' => [str_repeat('a', 129)],
        ];
    }

    private function databaseQueue(Database $db): Queue
    {
        $cache = m::mock(Cache::class);
        $cache->shouldNotReceive('redis');
        $cache->shouldReceive('rememberSeconds')->andReturn(0)->byDefault();

        return new Queue($db, $cache, 'database');
    }

    private function redisQueue(Database $db, \Redis $redis): Queue
    {
        $cache = m::mock(Cache::class);
        $cache->shouldReceive('redis')->once()->andReturn($redis);
        $cache->shouldReceive('rememberSeconds')->andReturn(0)->byDefault();

        return new Queue($db, $cache, 'redis');
    }
}
