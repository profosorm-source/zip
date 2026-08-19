<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use Mockery as m;
use App\Contracts\OutboxServiceInterface;

/**
 * @group architecture
 */
class EscrowServiceBehaviorTest extends TestCase
{
    protected function tearDown(): void { m::close(); parent::tearDown(); }

    /** @return array{svc:\App\Services\EscrowService,db:\Core\Database&\Mockery\MockInterface,escrowModel:\App\Models\Escrow&\Mockery\MockInterface,idem:\App\Services\Shared\IdempotencyService&\Mockery\MockInterface,logger:\App\Contracts\LoggerInterface&\Mockery\MockInterface,outbox:OutboxServiceInterface|null} */
    private function make(?OutboxServiceInterface $outbox = null): array
    {
        $ed = m::mock('Core\\EventDispatcher'); $ed->shouldIgnoreMissing();
        $db = m::mock('Core\\Database'); $db->shouldIgnoreMissing();
        $logger = m::mock('App\\Contracts\\LoggerInterface'); $logger->shouldIgnoreMissing();
        $escrowModel = m::mock('App\\Models\\Escrow'); $escrowModel->shouldIgnoreMissing();
        $idem = m::mock('App\\Services\\Shared\\IdempotencyService'); $idem->shouldIgnoreMissing();
        $sm = m::mock('App\\Services\\StateMachineService'); $sm->shouldIgnoreMissing();

        $svc = new \App\Services\EscrowService($db, $logger, $escrowModel, $idem, $sm, null, $outbox);
        return compact('svc', 'db', 'escrowModel', 'idem', 'logger', 'outbox');
    }

    /** @test */
    public function get_status_returns_null_for_nonexistent(): void
    {
        $c = $this->make();
        $c['db']->shouldReceive('selectOne')->andReturn(null);

        $this->assertNull($c['svc']->getStatus(999));
    }

    /** @test */
    public function get_status_returns_object_for_existing(): void
    {
        $c = $this->make();
        $c['escrowModel']->shouldReceive('getStatus')->with(1)->andReturn((object)['id' => 1, 'status' => 'pending']);

        $result = $c['svc']->getStatus(1);
        $this->assertNotNull($result);
        $this->assertEquals('pending', $result->status);
    }

    /** @test */
    public function get_by_order_returns_null_when_not_found(): void
    {
        $c = $this->make();
        $c['escrowModel']->shouldReceive('findByOrderId')->andReturn(null);

        $this->assertNull($c['svc']->getByOrder(999, 'order'));
    }


}
