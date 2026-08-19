<?php

declare(strict_types=1);

namespace Tests\Integration\Queue;

use Core\Application;
use Core\Cache;
use Core\Database;
use Core\Queue;
use PHPUnit\Framework\TestCase;

final class RedisQueueRuntimeTest extends TestCase
{
    public function test_real_redis_queue_push_pop_delete_lifecycle_never_uses_database_queue(): void
    {
        $container = Application::getInstance()->container;
        $cache = $container->make(Cache::class);
        $db = $container->make(Database::class);
        $redis = $cache->redis();

        $this->assertInstanceOf(\Redis::class, $redis);
        $queueName = 'phase20.redis.' . bin2hex(random_bytes(6));
        $prefix = 'chortke:queue';
        $jobId = null;
        $queue = new Queue($db, $cache, 'redis');

        try {
            $this->assertSame(0, (int)$db->fetchColumn(
                'SELECT COUNT(*) FROM queues WHERE queue = ?',
                [$queueName]
            ));

            $this->assertTrue($queue->push(
                'App\\Jobs\\LogPerformanceJob',
                ['probe' => 'phase20'],
                $queueName
            ));
            $this->assertSame(1, $queue->size($queueName));

            $job = $queue->pop($queueName);
            $this->assertNotNull($job);
            $jobId = int_value($job['id'] ?? 0);
            $this->assertGreaterThan(0, $jobId);
            $this->assertSame('App\\Jobs\\LogPerformanceJob', $job['job']);
            $this->assertSame(['probe' => 'phase20'], $job['data']);
            $this->assertSame(1, $job['attempts']);

            $this->assertSame(0, (int)$db->fetchColumn(
                'SELECT COUNT(*) FROM queues WHERE queue = ?',
                [$queueName]
            ));
            $this->assertTrue($queue->delete($jobId));
            $this->assertSame(0, $queue->size($queueName));
        } finally {
            $members = $redis->zRange($prefix . ':' . $queueName . ':pending', 0, -1);
            if (is_array($members)) {
                foreach ($members as $member) {
                    $redis->del($prefix . ':job:' . $member);
                }
            }
            $members = $redis->zRange($prefix . ':' . $queueName . ':reserved', 0, -1);
            if (is_array($members)) {
                foreach ($members as $member) {
                    $redis->del($prefix . ':job:' . $member);
                }
            }
            if ($jobId !== null) {
                $redis->del($prefix . ':job:' . $jobId);
            }
            $redis->del($prefix . ':' . $queueName . ':pending');
            $redis->del($prefix . ':' . $queueName . ':reserved');
            $redis->del($prefix . ':job_id_counter');
            $cache->forget('queue_size_cache:' . $queueName);
        }
    }
}
