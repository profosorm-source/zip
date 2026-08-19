<?php

namespace Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use App\Services\ScheduledPaymentService;
use Mockery as m;

class ScheduledPaymentServiceTest extends TestCase
{
    /** @var \Core\TransactionWrapper&\Mockery\MockInterface */
    private \Core\TransactionWrapper $transactionWrapper;
    /** @var \App\Contracts\LoggerInterface&\Mockery\MockInterface */
    private \App\Contracts\LoggerInterface $logger;
    /** @var \App\Models\ScheduledPayment&\Mockery\MockInterface */
    private \App\Models\ScheduledPayment $scheduledPaymentModel;
    /** @var \App\Contracts\WalletServiceInterface&\Mockery\MockInterface */
    private \App\Contracts\WalletServiceInterface $walletService;
    /** @var \App\Services\ReconciliationService&\Mockery\MockInterface */
    private \App\Services\ReconciliationService $reconciliationService;
    /** @var \App\Contracts\ValidatorFactoryInterface&\Mockery\MockInterface */
    private \App\Contracts\ValidatorFactoryInterface $validatorFactory;
    /** @var \App\Services\Shared\IdempotencyService&\Mockery\MockInterface */
    private \App\Services\Shared\IdempotencyService $idempotencyService;
    private ScheduledPaymentService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->transactionWrapper = m::mock('Core\TransactionWrapper');
        $this->logger = m::mock('App\Contracts\LoggerInterface');
        $this->scheduledPaymentModel = m::mock('App\Models\ScheduledPayment');
        $this->walletService = m::mock('App\Contracts\WalletServiceInterface');
        $this->reconciliationService = m::mock('App\Services\ReconciliationService');
        $this->validatorFactory = m::mock('App\Contracts\ValidatorFactoryInterface');
        $this->idempotencyService = m::mock('App\Services\Shared\IdempotencyService');

        $this->logger->shouldIgnoreMissing();

        $this->service = new ScheduledPaymentService(
            $this->transactionWrapper,
            $this->logger,
            $this->scheduledPaymentModel,
            $this->walletService,
            $this->reconciliationService,
            $this->validatorFactory,
            $this->idempotencyService
        );
    }

    protected function tearDown(): void
    {
        m::close();
        parent::tearDown();
    }

    /** @test */
    public function service_can_be_instantiated(): void
    {
        $this->assertInstanceOf(ScheduledPaymentService::class, $this->service);
    }

    /** @test */
    public function create_schedule_validates_and_executes_idempotent_creation(): void
    {
        $data = [
            'user_id' => 12,
            'amount' => '150.00',
            'next_run_at' => '2026-06-04 12:00:00'
        ];

        $validator = m::mock(\Core\Validator::class);
        $validator->shouldReceive('fails')->once()->andReturn(false);
        $this->validatorFactory->shouldReceive('make')->once()->andReturn($validator);

        // Idempotency mock
        $this->idempotencyService->shouldReceive('executeWithTransaction')
            ->once()
            ->andReturnUsing(function($scope, $actorId, $payload, $callback, $key = null) {
                return $callback();
            });

        $this->scheduledPaymentModel->shouldReceive('createSchedule')
            ->with($data)
            ->once()
            ->andReturn((object)['id' => 55]);

        $result = $this->service->createSchedule($data);

        $this->assertInstanceOf(\stdClass::class, $result);
        $this->assertEquals(55, $result->id);
    }

    /** @test */
    public function process_due_payments_triggers_transaction_wrapper_retry(): void
    {
        // Wrapper retry mock
        $this->transactionWrapper->shouldReceive('runWithRetry')
            ->once()
            ->andReturnUsing(function($callback) {
                return $callback(m::mock('Core\Database'));
            });

        $this->scheduledPaymentModel->shouldReceive('getDuePayments')
            ->with(50)
            ->once()
            ->andReturn([]); // No due payments for simple test

        $result = $this->service->processDuePayments();

        $this->assertEquals(0, $result['processed']);
        $this->assertEquals(0, $result['failed']);
    }
}
