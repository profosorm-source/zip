<?php

namespace Tests\Integration\ContainerBacked;

use PHPUnit\Framework\TestCase;
use App\Services\AdSystemManager;
use App\Services\SagaOrchestrator;
use App\Services\EscrowService;
use App\Contracts\AdsRepositoryInterface;
use App\Contracts\LoggerInterface;
use App\Adapters\CustomTaskAdapter;
use Core\Database;
use Core\Container;
use App\Services\Ads\AdsBudgetSettlementService;
use Mockery;

/**
 * SagaSystemTest - سناریوی تستی واقعی برای تایید یکپارچگی ساگا
 */
/**
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class SagaSystemTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        ini_set('error_log', sys_get_temp_dir() . '/chortke-phpunit-error.log');
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /**
     * @test
     * سناریو: ثبت موفق تبلیغ و رزرو وجه در اکسرو
     */
    public function test_successful_ad_creation_flow(): void
    {
        // 1. آماده‌سازی Mockها
        $db = Mockery::mock(Database::class);
        $db->shouldReceive('beginTransaction')->andReturn(true)->byDefault();
        $db->shouldReceive('commit')->andReturn(true)->byDefault();
        $db->shouldReceive('rollBack')->andReturn(true)->byDefault();
        $db->shouldReceive('inTransaction')->andReturn(false)->byDefault();
        $db->shouldReceive('transactional')->andReturnUsing(function($cb) use ($db) { return $cb($db); })->byDefault();
        // SagaOrchestrator به‌صورت داخلی روی جدول saga_executions کوئری می‌زند (setSaga/saveState/updateStatus)
        $sagaStmt = Mockery::mock(\PDOStatement::class);
        $sagaStmt->shouldReceive('execute')->andReturn(true)->byDefault();
        $db->shouldReceive('prepare')->andReturn($sagaStmt)->byDefault();
        $db->shouldReceive('query')->andReturn($sagaStmt)->byDefault();
        $logger = Mockery::mock(LoggerInterface::class);
        $logger->shouldIgnoreMissing();
        $repo = Mockery::mock(AdsRepositoryInterface::class);
        $escrow = Mockery::mock(EscrowService::class);
        $adapter = Mockery::mock(CustomTaskAdapter::class);
        $walletMock = Mockery::mock(\App\Contracts\WalletServiceInterface::class);
        $walletMock->shouldReceive('withdraw')->andReturn(['success' => True, 'tx_id' => 1001])->byDefault();
        $walletMock->shouldReceive('deposit')->andReturn(['success' => True])->byDefault();
        $adsBudgetSettlement = Mockery::mock(AdsBudgetSettlementService::class);
        $fraudGuardMock = Mockery::mock(\App\Contracts\AntiFraud\FraudGuardInterface::class);
        $fraudGuardMock->shouldReceive('checkAction')->andReturn(['allowed' => true, 'score' => 0])->byDefault();
        Container::getInstance()->instance(\App\Contracts\AntiFraud\FraudGuardInterface::class, $fraudGuardMock);
        Container::getInstance()->instance(\App\Contracts\WalletServiceInterface::class, $walletMock);
        
        $saga = new SagaOrchestrator($db, $logger);
        $manager = new AdSystemManager($db, $logger, ['custom_task' => $adapter], $repo, $escrow, $saga, $walletMock, $adsBudgetSettlement);

        $userId = 1;
        $adData = [
            'ad_type' => 'custom_task',
            'title' => 'تبلیغ تست',
            'total_budget' => '1000',
            'currency' => 'irt'
        ];

        // 2. تعریف انتظارات (Expectations)
        
        // گام اول: محاسبه هزینه و رزرو در اکسرو
        $adapter->shouldReceive('calculateCost')->andReturn('100'); // 10% fee
        // امضای واقعی holdFunds شش آرگومان دارد: (orderId, orderType, buyerId, sellerId, amount, currency)
        // توجه: saga execution_id توسط SagaOrchestrator به صورت تصادفی تولید می‌شود (bin2hex(random_bytes(16)))
        $escrow->shouldReceive('holdFunds')
            ->with(\Mockery::any(), 'ad_creation_custom_task', $userId, $userId, '1100', 'irt')
            ->once()
            ->andReturn(['ok' => true, 'escrow_id' => 500]);

        // WalletService mock برای assert_fraud_allowed که fail-open است
        // و walletService->withdraw که در step فراخوانی می‌شود
        $walletMock = Mockery::mock(\App\Contracts\WalletServiceInterface::class);
        $walletMock->shouldReceive('withdraw')->andReturn(['success' => true, 'tx_id' => 1001])->byDefault();
        $walletMock->shouldReceive('deposit')->andReturn(['success' => true])->byDefault();
        $fraudGuardMock = Mockery::mock(\App\Contracts\AntiFraud\FraudGuardInterface::class);
        $fraudGuardMock->shouldReceive('checkAction')->andReturn(['allowed' => true, 'score' => 0])->byDefault();
        Container::getInstance()->instance(\App\Contracts\AntiFraud\FraudGuardInterface::class, $fraudGuardMock);

        // گام دوم: ایجاد رکورد در دیتابیس از طریق آداپتر
        $adapter->shouldReceive('create')
            ->once()
            ->andReturn(['success' => true, 'data' => ['id' => 123]]);

        // 3. اجرا
        $result = $manager->create('custom_task', $userId, $adData);

        // 4. بررسی نتایج
        $this->assertEquals(123, $result['ad_id']);
        $this->assertEquals(1100, $result['total_amount']);
    }

    /**
     * @test
     * سناریو: شکست در ثبت آگهی و بازگشت خودکار وجه (Compensation)
     */
    public function test_ad_creation_failure_triggers_rollback(): void
    {
        $this->expectOutputRegex('/.*/');
        $db = Mockery::mock(Database::class);
        $db->shouldReceive('beginTransaction')->andReturn(true)->byDefault();
        $db->shouldReceive('commit')->andReturn(true)->byDefault();
        $db->shouldReceive('rollBack')->andReturn(true)->byDefault();
        $db->shouldReceive('inTransaction')->andReturn(false)->byDefault();
        $db->shouldReceive('transactional')->andReturnUsing(function($cb) use ($db) { return $cb($db); })->byDefault();
        // SagaOrchestrator به‌صورت داخلی روی جدول saga_executions کوئری می‌زند (setSaga/saveState/updateStatus)
        $sagaStmt = Mockery::mock(\PDOStatement::class);
        $sagaStmt->shouldReceive('execute')->andReturn(true)->byDefault();
        $db->shouldReceive('prepare')->andReturn($sagaStmt)->byDefault();
        $logger = Mockery::mock(LoggerInterface::class);
        $logger->shouldIgnoreMissing();
        $repo = Mockery::mock(AdsRepositoryInterface::class);
        $escrow = Mockery::mock(EscrowService::class);
        $adapter = Mockery::mock(CustomTaskAdapter::class);
        $walletMock = Mockery::mock(\App\Contracts\WalletServiceInterface::class);
        $walletMock->shouldReceive('withdraw')->andReturn(['success' => True, 'tx_id' => 1001])->byDefault();
        $walletMock->shouldReceive('deposit')->andReturn(['success' => True])->byDefault();
        $adsBudgetSettlement = Mockery::mock(AdsBudgetSettlementService::class);
        $fraudGuardMock = Mockery::mock(\App\Contracts\AntiFraud\FraudGuardInterface::class);
        $fraudGuardMock->shouldReceive('checkAction')->andReturn(['allowed' => true, 'score' => 0])->byDefault();
        Container::getInstance()->instance(\App\Contracts\AntiFraud\FraudGuardInterface::class, $fraudGuardMock);
        Container::getInstance()->instance(\App\Contracts\WalletServiceInterface::class, $walletMock);
        
        $saga = new SagaOrchestrator($db, $logger);
        $manager = new AdSystemManager($db, $logger, ['custom_task' => $adapter], $repo, $escrow, $saga, $walletMock, $adsBudgetSettlement);

        // WalletService mock for assert_fraud_allowed
        $walletMock = Mockery::mock(\App\Contracts\WalletServiceInterface::class);
        $walletMock->shouldReceive('withdraw')->andReturn(['success' => true, 'tx_id' => 1001])->byDefault();
        $walletMock->shouldReceive('deposit')->andReturn(['success' => true])->byDefault();
        $fraudGuardMock = Mockery::mock(\App\Contracts\AntiFraud\FraudGuardInterface::class);
        $fraudGuardMock->shouldReceive('checkAction')->andReturn(['allowed' => true, 'score' => 0])->byDefault();
        Container::getInstance()->instance(\App\Contracts\AntiFraud\FraudGuardInterface::class, $fraudGuardMock);

        // انتظارات:
        $adapter->shouldReceive('calculateCost')->andReturn('0');
        
        // وجه رزرو می‌شود
        $escrow->shouldReceive('holdFunds')->andReturn(['ok' => true, 'escrow_id' => 500]);

        // اما ثبت آگهی با خطا مواجه می‌شود
        $adapter->shouldReceive('create')->andReturn(['success' => false, 'message' => 'خطای دیتابیس']);

        // کل Saga داخل transaction root اجرا می‌شود؛ compensation نباید wallet
        // deposit یا state-only refund انجام دهد، چون rollback همهٔ mutationها را
        // اتمیک برمی‌گرداند.
        $escrow->shouldNotReceive('refundFunds');
        $walletMock->shouldNotReceive('deposit');

        // اجرا و انتظار خطا
        $this->expectException(\Exception::class);
        $manager->create('custom_task', 1, ['ad_type' => 'custom_task', 'total_budget' => '1000']);
    }
}
