<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use Tests\Support\CreatesTypedMockeryMocks;
use App\Contracts\LoggerInterface;
use App\Services\DistributedLockService;
use App\Services\Settings\AppSettings;
use App\Services\Shared\IdempotencyService;
use App\Services\Wallet\WalletMutationService;
use App\Services\Wallet\WalletQueryService;
use App\Services\Wallet\WalletService;
use Core\Database;
use Core\EventDispatcher;
use Mockery as m;
use PHPUnit\Framework\TestCase;

final class WalletServiceBehaviorTest extends TestCase
{
    use CreatesTypedMockeryMocks;
    protected function tearDown(): void { m::close(); parent::tearDown(); }

    public function test_balance_queries_delegate_and_has_balance_uses_decimal_precision(): void
    {
        $query=m::mock(WalletQueryService::class);
        $query->shouldReceive('getBalance')->with(7,'usdt')->times(3)->andReturn('10.00000001','10.00000001','10.00000001');
        $service=$this->service($query);
        $this->assertSame('10.00000001',$service->getBalance(7,'usdt'));
        $this->assertTrue($service->hasBalance(7,'10.00000000','usdt'));
        $this->assertFalse($service->hasBalance(7,'10.00000002','usdt'));
    }

    public function test_public_mutations_reject_unsupported_currency_before_dependencies_are_called(): void
    {
        $query=m::mock(WalletQueryService::class);
        $mutation=m::mock(WalletMutationService::class);$mutation->shouldNotReceive('processDeposit');
        $service=$this->service($query,$mutation);
        $this->expectException(\InvalidArgumentException::class);
        $service->deposit(7,'10','btc');
    }

    public function test_public_mutations_reject_zero_amount_before_lock_or_idempotency(): void
    {
        $query=m::mock(WalletQueryService::class);
        $lock=m::mock(DistributedLockService::class);$lock->shouldNotReceive('synchronized');
        $service=$this->service($query,null,$lock);
        $this->expectException(\InvalidArgumentException::class);
        $service->withdraw(7,'0','irt');
    }

    private function service(WalletQueryService $query,?WalletMutationService $mutation=null,?DistributedLockService $lock=null): WalletService
    {
        $settings=m::mock(AppSettings::class);$settings->shouldReceive('get')->andReturn(null);
        return new WalletService(
            $this->lenientMock(EventDispatcher::class),$this->lenientMock(Database::class),
            $this->lenientMock(LoggerInterface::class),$this->lenientMock(IdempotencyService::class),
            $lock??$this->lenientMock(DistributedLockService::class),$query,
            $mutation??$this->lenientMock(WalletMutationService::class),$settings
        );
    }
}
