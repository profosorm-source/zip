<?php

namespace Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use Mockery as m;

class EscrowServiceTest extends TestCase
{
    protected function tearDown(): void
    {
        m::close();
        parent::tearDown();
    }

    /** @test */
    public function holdFunds_is_idempotent_and_creates_escrow(): void
    {
        $escrowModel = m::mock(\App\Models\Escrow::class);
        $db = m::mock(\Core\Database::class);
        $logger = m::mock(\App\Contracts\LoggerInterface::class);
        $idempotency = m::mock(\App\Services\Shared\IdempotencyService::class);
        $ledger = m::mock(\App\Domain\Financial\Services\LedgerService::class);
        $stateMachine = m::mock(\App\Services\StateMachineService::class);
        $eventDispatcher = m::mock(\Core\EventDispatcher::class);

        $logger->shouldIgnoreMissing();
        $eventDispatcher->shouldIgnoreMissing();

        $escrowModel->shouldReceive('findByOrderId')
            ->with(123, 'order_type_x', 'refunded')
            ->andReturn(null);

        $escrowModel->shouldReceive('createEscrow')
            ->with(123, 'order_type_x', 999, 1000, '10.5', 'USDT')
            ->once()
            ->andReturn(555);

        // امضای واقعی: execute(scope, actorId, payload, callback, explicitKey?)
        $idempotency->shouldReceive('execute')
            ->once()
            ->with(
                'escrow.holdFunds',
                999,
                m::type('array'),
                m::type('callable'),
                m::any()
            )
            ->andReturnUsing(function ($scope, $actorId, $payload, $callback, $key = null) {
                return $callback();
            });

        // EscrowService is state/audit only; wallet/ledger settlement lives in FinancialEscrowService.
        $service = new \App\Services\EscrowService(
            $db,
            $logger,
            $escrowModel,
            $idempotency,
            $stateMachine,
            null
        );

        $result = $service->holdFunds(123, 'order_type_x', 999, 1000, '10.5', 'USDT');

        $this->assertIsArray($result);
        $this->assertArrayHasKey('ok', $result);
        $this->assertTrue($result['ok']);
        $escrowId = $result['escrow_id'] ?? null;
        $this->assertIsInt($escrowId);
        $this->assertSame(555, $escrowId);
    }
}
