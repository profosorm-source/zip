<?php

declare(strict_types=1);

namespace Tests\Integration\ContainerBacked\Services;

use PHPUnit\Framework\TestCase;
use App\Services\Withdrawal\WithdrawalAdminService;
use Core\Database;
use App\Contracts\WalletServiceInterface;
use App\Models\Withdrawal as WithdrawalModel;
use App\Contracts\LoggerInterface;
use Mockery as m;

/**
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class WithdrawalAdminServiceTest extends TestCase
{
    protected function tearDown(): void
    {
        m::close();
        parent::tearDown();
    }

    public function test_adminApprove_executes_flow_and_returns_success(): void
    {
        $db = m::mock(Database::class);
        $wallet = m::mock(WalletServiceInterface::class);
        $model = m::mock(WithdrawalModel::class);
        $logger = m::mock(LoggerInterface::class);
        $appSettings = m::mock(\App\Services\Settings\AppSettings::class);

        $db->shouldIgnoreMissing();
        $logger->shouldIgnoreMissing();
        $appSettings->shouldIgnoreMissing();

        $withdrawalRow = (object)[
            'id' => 1, 'user_id' => 77, 'amount' => '25000',
            'currency' => 'irt', 'transaction_id' => 'tx-approve-1', 'status' => 'pending',
        ];

        $model->shouldReceive('find')->with(1)->andReturn($withdrawalRow);
        $model->shouldReceive('lockForUpdate')->with(1)->andReturn($withdrawalRow);
        $model->shouldReceive('updateStatus')->andReturn(true);
        $wallet->shouldReceive('completeWithdrawal')->andReturn(true);

        // SagaOrchestrator setup
        $sagaDb = m::mock(Database::class);
        $sagaStmt = m::mock(\PDOStatement::class);
        $sagaStmt->shouldReceive('execute')->andReturn(true)->byDefault();
        $sagaDb->shouldReceive('prepare')->andReturn($sagaStmt)->byDefault();
        $sagaLogger = m::mock(LoggerInterface::class);
        $sagaLogger->shouldIgnoreMissing();
        
        $orchestrator = new \App\Services\SagaOrchestrator($sagaDb, $sagaLogger);
        \Core\Container::getInstance()->instance(\App\Services\SagaOrchestrator::class, $orchestrator);

        $notificationService = m::mock(\App\Services\Notification\NotificationService::class);
        $notificationService->shouldIgnoreMissing();
        $svc = new WithdrawalAdminService($db, $wallet, $model, $logger, $appSettings, $notificationService, $orchestrator);

        $res = $svc->adminApprove(1, 99);

        $this->assertIsArray($res);
        $this->assertSame(77, $res['user_id'] ?? null);
        $this->assertSame(1, $res['withdrawal_id'] ?? null);
        $this->assertSame(99, $res['admin_id'] ?? null);

    }

    public function test_adminReject_executes_flow_and_returns_success(): void
    {
        $db = m::mock(Database::class);
        $wallet = m::mock(WalletServiceInterface::class);
        $model = m::mock(WithdrawalModel::class);
        $logger = m::mock(LoggerInterface::class);
        $appSettings = m::mock(\App\Services\Settings\AppSettings::class);

        $db->shouldIgnoreMissing();
        $logger->shouldIgnoreMissing();
        $appSettings->shouldIgnoreMissing();

        $withdrawalRow = (object)[
            'id' => 2, 'user_id' => 55, 'amount' => '10000',
            'currency' => 'irt', 'transaction_id' => 'tx-2', 'status' => 'pending',
        ];

        $model->shouldReceive('find')->with(2)->andReturn($withdrawalRow);
        $model->shouldReceive('updateStatus')->andReturn(true);
        $wallet->shouldReceive('cancelWithdrawal')->andReturn(true);

        // SagaOrchestrator setup
        $sagaDb = m::mock(Database::class);
        $sagaStmt = m::mock(\PDOStatement::class);
        $sagaStmt->shouldReceive('execute')->andReturn(true)->byDefault();
        $sagaDb->shouldReceive('prepare')->andReturn($sagaStmt)->byDefault();
        $sagaLogger = m::mock(LoggerInterface::class);
        $sagaLogger->shouldIgnoreMissing();

        $orchestrator = new \App\Services\SagaOrchestrator($sagaDb, $sagaLogger);
        \Core\Container::getInstance()->instance(\App\Services\SagaOrchestrator::class, $orchestrator);

        $notificationService = m::mock(\App\Services\Notification\NotificationService::class);
        $notificationService->shouldIgnoreMissing();
        $svc = new WithdrawalAdminService($db, $wallet, $model, $logger, $appSettings, $notificationService, $orchestrator);

        $res = $svc->adminReject(2, 99, 'test reason');
        $this->assertIsArray($res);
        $this->assertSame(55, $res['user_id'] ?? null);
        $this->assertSame(2, $res['withdrawal_id'] ?? null);
        $this->assertSame(99, $res['admin_id'] ?? null);

    }
}
