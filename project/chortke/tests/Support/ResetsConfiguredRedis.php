<?php

declare(strict_types=1);

namespace Tests\Support;

trait ResetsConfiguredRedis
{
    /** @param list<int> $userIds */
    private function resetConfiguredRedis(array $userIds = []): void
    {
        $config = config('redis', []);
        if (!is_array($config)) {
            throw new \UnexpectedValueException('Redis test configuration must be an array.');
        }
        $host = str_value($config['host'] ?? '127.0.0.1');
        $port = int_value($config['port'] ?? 6379);
        $password = str_value($config['password'] ?? '');
        $database = int_value($config['database'] ?? 0);
        $prefix = str_value($config['prefix'] ?? '');

        $redis = new \Redis();
        $redis->connect($host, $port, 2.0);
        if ($password !== '') $redis->auth($password);
        $redis->select($database);
        $redis->flushDB();
        foreach ($userIds as $userId) {
            $redis->del("score:user:{$userId}");
            if ($prefix !== '') $redis->del($prefix . "score:user:{$userId}");
        }
        $redis->close();
    }
}
