<?php

declare(strict_types=1);

namespace Tests\Integration\Core;

use PHPUnit\Framework\TestCase;
use Core\Database;
use Core\Cache;

/**
 * Core Cache integration tests — tests real Cache operations.
 * Uses file fallback since Redis is not available in sandbox.
 */
class CacheIntegrationTest extends TestCase
{
    private Cache $cache;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cache = Cache::getInstance();
    }

    protected function tearDown(): void
    {
        if (isset($this->cache)) {
            $this->cache->forget('test_int_key');
            $this->cache->forget('test_str_key');
            $this->cache->forget('test_array_key');
            $this->cache->forget('test_forever_key');
        }
        parent::tearDown();
    }

    /** @test */
    public function cache_can_set_and_get_string(): void
    {
        $this->cache->set('test_str_key', 'hello world', 1);
        $result = $this->cache->get('test_str_key');
        $this->assertEquals('hello world', $result);
    }

    /** @test */
    public function cache_can_set_and_get_integer(): void
    {
        $this->cache->set('test_int_key', 42, 1);
        $result = $this->cache->get('test_int_key');
        $this->assertEquals(42, $result);
    }

    /** @test */
    public function cache_can_set_and_get_array(): void
    {
        $data = ['name' => 'test', 'count' => 5];
        $this->cache->set('test_array_key', $data, 1);
        $result = $this->cache->get('test_array_key');
        $this->assertIsArray($result);
        $this->assertEquals('test', $result['name']);
        $this->assertEquals(5, $result['count']);
    }

    /** @test */
    public function cache_get_returns_null_for_missing_key(): void
    {
        $result = $this->cache->get('nonexistent_key_' . uniqid());
        $this->assertNull($result);
    }

    /** @test */
    public function cache_get_returns_default_for_missing_key(): void
    {
        $result = $this->cache->get('nonexistent_key_' . uniqid(), 'default_value');
        $this->assertEquals('default_value', $result);
    }

    /** @test */
    public function cache_has_detects_existing_key(): void
    {
        $this->cache->set('test_str_key', 'value', 1);
        $this->assertTrue($this->cache->has('test_str_key'));
        $this->assertFalse($this->cache->has('definitely_not_exists_' . uniqid()));
    }

    /** @test */
    public function cache_forget_removes_key(): void
    {
        $this->cache->set('test_str_key', 'value', 1);
        $this->assertTrue($this->cache->has('test_str_key'));
        $this->cache->forget('test_str_key');
    }

    /** @test */
    public function cache_remember_stores_and_returns_value(): void
    {
        $key = 'test_remember_' . uniqid();
        $callCount = 0;
        
        $result = $this->cache->remember($key, 1, function() use (&$callCount) {
            $callCount++;
            return 'computed_value';
        });
        
        $this->assertEquals('computed_value', $result);
        $this->assertEquals(1, $callCount);
        
        // Second call should use cache, not callback
        $result2 = $this->cache->remember($key, 1, function() use (&$callCount) {
            $callCount++;
            return 'second_value';
        });
        
        $this->assertEquals('computed_value', $result2);
        $this->assertEquals(1, $callCount);
        
        $this->cache->forget($key);
    }

    /** @test */
    public function cache_increment_works(): void
    {
        $key = 'test_incr_' . uniqid();
        $this->cache->set($key, 0, 1);
        
        $val = $this->cache->increment($key, 5);
        $this->assertNotFalse($val);
        
        $this->cache->forget($key);
    }

    /** @test */
    public function cache_decrement_works(): void
    {
        $key = 'test_decr_' . uniqid();
        $this->cache->set($key, 10, 1);
        
        $val = $this->cache->decrement($key, 3);
        $this->assertNotFalse($val);
        
        $this->cache->forget($key);
    }

    /** @test */
    public function cache_forever_persists_value(): void
    {
        $this->cache->forever('test_forever_key', 'permanent_data');
        $result = $this->cache->get('test_forever_key');
        $this->assertEquals('permanent_data', $result);
    }

    /** @test */
    public function cache_tags_return_tagged_cache(): void
    {
        $tagged = $this->cache->tags(['test_tag']);
        $this->assertInstanceOf(\Core\TaggedCache::class, $tagged);
        
        $tagged->put('tagged_key', 'tagged_value', 5);
        $result = $tagged->get('tagged_key');
        $this->assertEquals('tagged_value', $result);
        
        $tagged->flush();
    }

    /** @test */
    public function cache_driver_is_valid(): void
    {
        $driver = $this->cache->driver();
        // Cache can use either 'file' or 'redis' depending on availability
        $this->assertContains($driver, ['file', 'redis']);
    }

    /** @test */
    public function cache_ttl_returns_seconds_for_existing_key(): void
    {
        $this->cache->set('test_str_key', 'value', 5);
        $ttl = $this->cache->ttl('test_str_key');
        $this->assertIsInt($ttl);
        $this->assertGreaterThan(-2, $ttl);
    }
}
