<?php

declare(strict_types=1);

namespace Tests\Integration\Session;

use Core\Application;
use Core\Cache;
use Core\RedisSessionHandler;
use PHPUnit\Framework\TestCase;

final class RedisSessionHandlerRuntimeTest extends TestCase
{
    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_real_redis_session_lifecycle_uses_one_connection_state(): void
    {
        $cache = Application::getInstance()->container->make(Cache::class);
        $this->assertSame('redis', $cache->driver());

        $handler = new RedisSessionHandler($cache, 'redis');
        $sessionId = 'phase20-' . bin2hex(random_bytes(12));
        $redis = $cache->redis();
        $this->assertInstanceOf(\Redis::class, $redis);

        try {
            $this->assertSame('redis', $handler->driver());
            $this->assertTrue($handler->open('', 'CHORTKE_PHASE20'));
            $this->assertTrue($handler->write($sessionId, 'user_id|i:42;'));
            $this->assertSame('user_id|i:42;', $handler->read($sessionId));
            $this->assertTrue((bool)$redis->exists('chortke:session:' . $sessionId));
            $this->assertTrue($handler->destroy($sessionId));
            $this->assertSame('', $handler->read($sessionId));
        } finally {
            $redis->del('chortke:session:' . $sessionId);
            $redis->del('chortke:session:' . $sessionId . ':created');
        }
    }

    public function test_explicit_file_cache_composition_uses_file_session_fallback(): void
    {
        $cacheDirectory = sys_get_temp_dir() . '/chortke-phase20-session-cache-' . bin2hex(random_bytes(6));
        $cache = new Cache(null, $cacheDirectory);
        $handler = new RedisSessionHandler($cache, 'file');
        $sessionId = 'phase20-file-' . bin2hex(random_bytes(10));
        $sessionFile = dirname(__DIR__, 3) . '/storage/sessions/sess_' . $sessionId;

        try {
            $this->assertSame('file', $cache->driver());
            $this->assertSame('file', $handler->driver());
            $this->assertTrue($handler->open('', 'CHORTKE_PHASE20'));
            $this->assertTrue($handler->write($sessionId, 'role|s:4:"user";'));
            $this->assertSame('role|s:4:"user";', $handler->read($sessionId));
            $this->assertTrue($handler->destroy($sessionId));
            $this->assertSame('', $handler->read($sessionId));
        } finally {
            @unlink($sessionFile);
            @rmdir($cacheDirectory);
        }
    }
}
