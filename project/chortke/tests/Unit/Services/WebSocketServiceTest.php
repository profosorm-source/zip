<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use Mockery as m;
use Core\Redis;
use Core\Database;
use App\Contracts\LoggerInterface;
use App\Services\WebSocketService;

class WebSocketServiceTest extends TestCase
{
    protected function tearDown(): void
    {
        m::close();
        parent::tearDown();
    }

    private function makeService(?Redis $redis = null, ?Database $db = null, ?LoggerInterface $logger = null): WebSocketService
    {
        return new WebSocketService(
            $redis ?? m::mock(Redis::class),
            $db ?? m::mock(Database::class),
            $logger ?? m::mock(LoggerInterface::class)
        );
    }

    /** @test */
    public function can_be_instantiated(): void
    {
        $this->assertInstanceOf(WebSocketService::class, $this->makeService());
    }

    /** @test */
    public function join_room_adds_to_members_and_reverse_index(): void
    {
        $redis = m::mock(Redis::class);
        $redis->shouldReceive('sAdd')->with('room:general:members', '7')->once();
        $redis->shouldReceive('sAdd')->with('user:7:rooms', 'general')->once();
        $redis->shouldReceive('expire')->twice();

        $logger = m::mock(LoggerInterface::class);
        $logger->shouldReceive('debug')->once();

        $service = $this->makeService($redis, null, $logger);
        $this->assertTrue($service->joinRoom(7, 'general'));
    }

    /** @test */
    public function join_room_returns_false_on_exception(): void
    {
        $redis = m::mock(Redis::class);
        $redis->shouldReceive('sAdd')->andThrow(new \Exception('redis down'));

        $logger = m::mock(LoggerInterface::class);
        $logger->shouldReceive('error')->once();

        $service = $this->makeService($redis, null, $logger);
        $this->assertFalse($service->joinRoom(7, 'general'));
    }

    /** @test */
    public function leave_room_removes_membership(): void
    {
        $redis = m::mock(Redis::class);
        $redis->shouldReceive('sRem')->with('room:general:members', '7')->once();
        $redis->shouldReceive('sRem')->with('user:7:rooms', 'general')->once();

        $logger = m::mock(LoggerInterface::class);
        $logger->shouldReceive('debug')->once();

        $service = $this->makeService($redis, null, $logger);
        $this->assertTrue($service->leaveRoom(7, 'general'));
    }

    /** @test */
    public function get_room_members_returns_int_list(): void
    {
        $redis = m::mock(Redis::class);
        $redis->shouldReceive('sMembers')->with('room:general:members')->once()->andReturn(['1', '2', '3']);

        $service = $this->makeService($redis);
        $this->assertSame([1, 2, 3], $service->getRoomMembers('general'));
    }

    /** @test */
    public function is_online_checks_presence_key(): void
    {
        $redis = m::mock(Redis::class);
        $redis->shouldReceive('exists')->with('presence:7')->once()->andReturn(1);

        $service = $this->makeService($redis);
        $this->assertTrue($service->isOnline(7));
    }

    /** @test */
    public function get_online_in_room_filters_online_members(): void
    {
        $redis = m::mock(Redis::class);
        $redis->shouldReceive('sMembers')->with('room:general:members')->once()->andReturn(['1', '2']);
        $redis->shouldReceive('exists')->with('presence:1')->once()->andReturn(1);
        $redis->shouldReceive('exists')->with('presence:2')->once()->andReturn(0);

        $service = $this->makeService($redis);
        $this->assertSame([1], $service->getOnlineInRoom('general'));
    }
}
