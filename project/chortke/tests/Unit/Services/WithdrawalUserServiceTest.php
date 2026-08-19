<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use App\Services\Withdrawal\WithdrawalUserService;
use Core\Database;
use App\Services\KYCService;
use App\Contracts\WalletServiceInterface;
use App\Services\AntiFraud\FraudGuardService;
use App\Services\AntiFraud\RiskDecisionService;
use App\Services\AntiFraud\FraudDetectionService;
use App\Services\AntiFraud\FraudStrategyResolver;
use App\Services\BankCardService;
use App\Services\Withdrawal\WithdrawalQueryService;
use App\Models\Withdrawal as WithdrawalModel;
use App\Contracts\LoggerInterface;
use App\Exceptions\BusinessException;
use App\Services\Shared\IdempotencyService;

class WithdrawalUserServiceTest extends TestCase
{
    public function test_guardCanCreateWithdrawal_allows_valid_request(): void
    {
        $db = $this->createMock(Database::class);
        $kyc = new class extends \App\Services\KYCService {
            public function __construct() {}
            public function isApproved(int $userId): bool { return true; }
        };
        $wallet = $this->createMock(WalletServiceInterface::class);
        $bankCard = $this->createMock(BankCardService::class);
        $query = $this->createMock(WithdrawalQueryService::class);
        $model = $this->createMock(WithdrawalModel::class);
        $logger = $this->createMock(LoggerInterface::class);
        $idemp = $this->createMock(IdempotencyService::class);

        // instantiate without constructor and inject dependencies to avoid mocking final classes
        $ref = new \ReflectionClass(WithdrawalUserService::class);
        $svc = $ref->newInstanceWithoutConstructor();

        $riskDecision = $this->createMock(RiskDecisionService::class);
        $fraudDetectionSvc = $this->createMock(FraudDetectionService::class);
        $strategyResolver = $this->createMock(FraudStrategyResolver::class);

        $fraudReal = new FraudGuardService($logger, $riskDecision, $fraudDetectionSvc, $strategyResolver);

        $props = [
            'db' => $db,
            'kycService' => $kyc,
            'wallet' => $wallet,
            'fraudGuard' => $fraudReal,
            'bankCardService' => $bankCard,
            'queryService' => $query,
            'model' => $model,
            'idempotencyService' => $idemp,
        ];

        foreach ($props as $name => $val) {
            $p = $ref->getProperty($name);
            $p->setAccessible(true);
            $p->setValue($svc, $val);
        }

        $riskDecision->method('decide')->willReturn([
            'decision' => 'allow',
            'reason' => 'ok',
            'fraud_score' => 0,
            'kyc_status' => 'verified',
            'action' => 'withdrawal.create',
        ]);
        $fraudDetectionSvc->method('calculateFraudScore')->with(10)->willReturn(0);
        $strategyResolver->method('resolve')->willReturn(null);
        $query->method('hasPendingWithdrawal')->with(10, true)->willReturn(false);
        $query->method('getLimitsForUser')->with(10, 'irt')->willReturn([
            'used_today' => '0',
            'daily_limit' => '10000000',
            'used_week' => '0',
            'weekly_limit' => '0',
            'used_month' => '0',
            'monthly_limit' => '0',
        ]);
        $bankCard->method('findVerifiedCardForUser')->with(10, 5)->willReturn((object)['id' => 5]);

        // should not throw
        $svc->guardCanCreateWithdrawal(10, ['amount' => '100000', 'bank_card_id' => 5]);
        $this->assertTrue(true);
    }

    public function test_guardCanCreateWithdrawal_rejects_zero_amount(): void
    {
        $db = $this->createMock(Database::class);
        $kyc = new class extends \App\Services\KYCService {
            public function __construct() {}
            public function isApproved(int $userId): bool { return true; }
        };
        $wallet = $this->createMock(WalletServiceInterface::class);
        $bankCard = $this->createMock(BankCardService::class);
        $query = $this->createMock(WithdrawalQueryService::class);
        $model = $this->createMock(WithdrawalModel::class);
        $logger = $this->createMock(LoggerInterface::class);
        $idemp = $this->createMock(IdempotencyService::class);

        // construct without constructor, inject dependencies
        $ref = new \ReflectionClass(WithdrawalUserService::class);
        $svc = $ref->newInstanceWithoutConstructor();
        $riskDecision = $this->createMock(RiskDecisionService::class);
        $fraudDetectionSvc = $this->createMock(FraudDetectionService::class);
        $strategyResolver = $this->createMock(FraudStrategyResolver::class);
        $fraudReal = new FraudGuardService($logger, $riskDecision, $fraudDetectionSvc, $strategyResolver);

        $props = [
            'db' => $db,
            'kycService' => $kyc,
            'wallet' => $wallet,
            'fraudGuard' => $fraudReal,
            'bankCardService' => $bankCard,
            'queryService' => $query,
            'model' => $model,
            'idempotencyService' => $idemp,
        ];
        foreach ($props as $name => $val) {
            $p = $ref->getProperty($name);
            $p->setAccessible(true);
            $p->setValue($svc, $val);
        }

        $this->expectException(BusinessException::class);
        $svc->guardCanCreateWithdrawal(10, ['amount' => '0']);
    }
}
