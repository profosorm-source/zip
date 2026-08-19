<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use Mockery as m;

/**
 * @group architecture
 */
class EmailDeliveryStoreTest extends TestCase
{
    protected function tearDown(): void { m::close(); parent::tearDown(); }

    // ─── Architecture ───────────────────────────────────────────


    /** @test */
    public function has_storage_methods(): void
    {
        $ref = new \ReflectionClass(\App\Services\EmailDeliveryStore::class);
        $methods = array_map(fn($m) => $m->getName(), $ref->getMethods(\ReflectionMethod::IS_PUBLIC));

        $this->assertContains('save', $methods);
        $this->assertContains('findAndLock', $methods);
        $this->assertContains('markAsSent', $methods);
        $this->assertContains('markAsFailed', $methods);
        $this->assertContains('getPending', $methods);
        $this->assertContains('getStats', $methods);
        $this->assertContains('cleanup', $methods);
    }






    // ─── Behavioral ─────────────────────────────────────────────

    /** @test */
    public function save_stores_to_database(): void
    {
        $cache = m::mock('Core\\Cache'); $cache->shouldIgnoreMissing();
        $cache->shouldReceive('driver')->andReturn('array');
        $cache->shouldReceive('redis')->andReturn(null);

        $db = m::mock('Core\\Database'); $db->shouldIgnoreMissing();
        $logger = m::mock('App\\Contracts\\LoggerInterface'); $logger->shouldIgnoreMissing();

        $stmt = m::mock(\PDOStatement::class);
        $stmt->shouldReceive('execute')->once()->andReturn(true);
        $db->shouldReceive('prepare')->once()->andReturn($stmt);
        $db->shouldReceive('lastInsertId')->once()->andReturn('42');

        $store = new \App\Services\EmailDeliveryStore($cache, $db, $logger, new \Core\PathResolver(dirname(__DIR__, 3)));
        $result = $store->save([
            'to' => 'test@test.com',
            'subject' => 'Test',
            'body' => '<p>Hello</p>',
            'user_id' => 1,
        ]);

        $this->assertNotFalse($result);
    }

    /** @test */
    public function mark_as_sent_updates_status(): void
    {
        $cache = m::mock('Core\\Cache'); $cache->shouldIgnoreMissing();
        $cache->shouldReceive('driver')->andReturn('array');
        $cache->shouldReceive('redis')->andReturn(null);

        $db = m::mock('Core\\Database');
        $db->shouldReceive('execute')->with(m::pattern('/UPDATE email_queue SET status/'), m::type('array'))->once();

        $logger = m::mock('App\\Contracts\\LoggerInterface'); $logger->shouldIgnoreMissing();

        $store = new \App\Services\EmailDeliveryStore($cache, $db, $logger, new \Core\PathResolver(dirname(__DIR__, 3)));
        $this->assertTrue($store->markAsSent('42'));
    }
}
