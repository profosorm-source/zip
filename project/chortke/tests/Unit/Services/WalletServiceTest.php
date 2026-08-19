<?php

namespace Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use App\Contracts\WalletServiceInterface;
use Mockery as m;

class WalletServiceTest extends TestCase
{
    protected function tearDown(): void
    {
        m::close();
        parent::tearDown();
    }

    /** @test */
    public function wallet_service_has_modified_methods(): void
    {
        // Verification of methods availability via reflection
        $reflection = new \ReflectionClass(\App\Services\Wallet\WalletService::class);
        
        $this->assertTrue($reflection->hasMethod('completeWithdrawal'), "Method completeWithdrawal must exist");
        $this->assertTrue($reflection->hasMethod('cancelWithdrawal'), "Method cancelWithdrawal must exist");
        $this->assertTrue($reflection->hasMethod('reverseTransaction'), "Method reverseTransaction must exist");
        
        // Check completeWithdrawal parameters
        $method = $reflection->getMethod('completeWithdrawal');
        $params = $method->getParameters();
        $this->assertCount(4, $params);
        $this->assertEquals('userId', $params[0]->getName());
        
        // Check cancelWithdrawal parameters
        $method = $reflection->getMethod('cancelWithdrawal');
        $params = $method->getParameters();
        $this->assertCount(4, $params);
        $this->assertEquals('userId', $params[0]->getName());

        // Check reverseTransaction parameters
        $method = $reflection->getMethod('reverseTransaction');
        $params = $method->getParameters();
        $this->assertCount(3, $params);
        $this->assertEquals('transactionId', $params[0]->getName());
        $this->assertEquals('adminId', $params[1]->getName());
        $this->assertEquals('reason', $params[2]->getName());
    }

    /** @test */
    public function complete_withdrawal_delegates_to_mutation_service(): void
    {
        $eventDispatcher = m::mock(\Core\EventDispatcher::class);
        $db = m::mock(\Core\Database::class);
        $logger = m::mock(\App\Contracts\LoggerInterface::class);
        $idempotencyService = m::mock(\App\Services\Shared\IdempotencyService::class);
        $lockService = m::mock(\App\Services\DistributedLockService::class);
        $queryService = m::mock(\App\Services\Wallet\WalletQueryService::class);
        $mutationService = m::mock(\App\Services\Wallet\WalletMutationService::class);
        $appSettings = m::mock(\App\Services\Settings\AppSettings::class);
        $outbox = m::mock(\App\Services\OutboxService::class);

        $eventDispatcher->shouldReceive('dispatchSync')->andReturnNull();
        $appSettings->shouldReceive('get')->with('wallet_supported_currencies')->andReturn(['irt', 'usdt']);
        $mutationService->shouldReceive('completeWithdrawal')->with('tx-123', 42)->andReturnTrue();

        $service = new \App\Services\Wallet\WalletService(
            $eventDispatcher,
            $db,
            $logger,
            $idempotencyService,
            $lockService,
            $queryService,
            $mutationService,
            $appSettings,
            $outbox
        );

        $this->assertTrue($service->completeWithdrawal(42, '1000', 'irt', 'tx-123'));
    }

    /** @test */
    public function cancel_withdrawal_delegates_to_mutation_service(): void
    {
        $eventDispatcher = m::mock(\Core\EventDispatcher::class);
        $db = m::mock(\Core\Database::class);
        $logger = m::mock(\App\Contracts\LoggerInterface::class);
        $idempotencyService = m::mock(\App\Services\Shared\IdempotencyService::class);
        $lockService = m::mock(\App\Services\DistributedLockService::class);
        $queryService = m::mock(\App\Services\Wallet\WalletQueryService::class);
        $mutationService = m::mock(\App\Services\Wallet\WalletMutationService::class);
        $appSettings = m::mock(\App\Services\Settings\AppSettings::class);
        $outbox = m::mock(\App\Services\OutboxService::class);

        $eventDispatcher->shouldReceive('dispatchSync')->andReturnNull();
        $appSettings->shouldReceive('get')->with('wallet_supported_currencies')->andReturn(['irt', 'usdt']);
        $mutationService->shouldReceive('cancelWithdrawal')->with('tx-456', 42)->andReturnTrue();

        $service = new \App\Services\Wallet\WalletService(
            $eventDispatcher,
            $db,
            $logger,
            $idempotencyService,
            $lockService,
            $queryService,
            $mutationService,
            $appSettings,
            $outbox
        );

        $this->assertTrue($service->cancelWithdrawal(42, '1000', 'irt', 'tx-456'));
    }

    /** @test */
    public function reverse_transaction_delegates_to_mutation_service_with_correct_parameters(): void
    {
        $eventDispatcher = m::mock(\Core\EventDispatcher::class);
        $db = m::mock(\Core\Database::class);
        $logger = m::mock(\App\Contracts\LoggerInterface::class);
        $idempotencyService = m::mock(\App\Services\Shared\IdempotencyService::class);
        $lockService = m::mock(\App\Services\DistributedLockService::class);
        $queryService = m::mock(\App\Services\Wallet\WalletQueryService::class);
        $mutationService = m::mock(\App\Services\Wallet\WalletMutationService::class);
        $appSettings = m::mock(\App\Services\Settings\AppSettings::class);
        $outbox = m::mock(\App\Services\OutboxService::class);

        $eventDispatcher->shouldReceive('dispatchSync')->andReturnNull();
        $appSettings->shouldReceive('get')->with('wallet_supported_currencies')->andReturn(['irt', 'usdt']);
        $mutationService->shouldReceive('reverseTransaction')->with('tx-789', 7, 'test reason')->andReturnTrue();

        $service = new \App\Services\Wallet\WalletService(
            $eventDispatcher,
            $db,
            $logger,
            $idempotencyService,
            $lockService,
            $queryService,
            $mutationService,
            $appSettings,
            $outbox
        );

        $this->assertTrue($service->reverseTransaction('tx-789', 7, 'test reason'));
    }
}
