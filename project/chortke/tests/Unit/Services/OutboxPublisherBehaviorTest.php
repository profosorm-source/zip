<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use Tests\Support\CreatesTypedMockeryMocks;
use App\Contracts\LoggerInterface;
use App\Services\OutboxPublisher;
use Core\Database;
use Core\EventDispatcher;
use Core\Queue;
use Mockery as m;
use PHPUnit\Framework\TestCase;

/** Fast unit complement to the real OutboxQueueDlqRuntimeTest. */
final class OutboxPublisherBehaviorTest extends TestCase
{
    use CreatesTypedMockeryMocks;
    protected function tearDown(): void { m::close(); parent::tearDown(); }

    public function test_empty_batch_commits_and_returns_zeroed_publication_stats(): void
    {
        $db=m::mock(Database::class);
        $db->shouldReceive('beginTransaction')->once();
        $db->shouldReceive('selectOne')->once()->andReturn(null);
        $db->shouldReceive('commit')->once();
        $db->shouldReceive('execute')->andReturn(0);
        $db->shouldReceive('fetchColumn')->andReturn(0);
        $publisher=new OutboxPublisher($db,$this->lenientMock(Queue::class),$this->lenientMock(EventDispatcher::class),$this->lenientMock(LoggerInterface::class));
        $result=$publisher->publishPending(10);
        $this->assertSame(0,$result['published']);
        $this->assertSame(0,$result['failed']);
        $this->assertSame(0,$result['dlq']);
    }
}
