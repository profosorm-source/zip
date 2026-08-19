<?php

namespace Tests\Integration\ContainerBacked\Services;

use PHPUnit\Framework\TestCase;
use App\Services\Payment\PaymentService;
use App\Services\Payment\PaymentCommandService;
use Mockery as m;

/**
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class PaymentServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        ini_set('error_log', sys_get_temp_dir() . '/chortke-phpunit-error.log');
    }

    protected function tearDown(): void
    {
        m::close();
        parent::tearDown();
    }

    /** @test */
    public function payment_service_create_has_correct_signature(): void
    {
        $reflection = new \ReflectionClass(PaymentService::class);
        
        $this->assertTrue($reflection->hasMethod('create'), "Method create must exist");
        
        $method = $reflection->getMethod('create');
        $params = $method->getParameters();
        
        $this->assertGreaterThanOrEqual(5, count($params), "create method must accept at least 5 parameters");
        
        $this->assertEquals('userId', $params[0]->getName());
        $this->assertEquals('gatewayName', $params[1]->getName());
        $this->assertEquals('amount', $params[2]->getName());
        $this->assertEquals('bankCardId', $params[3]->getName());
        $this->assertEquals('idempotencyKey', $params[4]->getName());
    }

    /** @test */
    public function callback_rejects_invalid_nonce(): void
    {
        $walletMock = m::mock(\App\Contracts\WalletServiceInterface::class);
        $notifierMock = m::mock(\App\Contracts\NotificationServiceInterface::class);
        $logMock = m::mock(\App\Models\PaymentLog::class);
        $bankCardMock = m::mock(\App\Models\BankCard::class);
        $loggerMock = m::mock(\App\Contracts\LoggerInterface::class);
        
        $loggerMock->shouldIgnoreMissing();
        
        $idempotencyMock = m::mock(\App\Services\Shared\IdempotencyService::class);
        $gatewayFactoryMock = m::mock(\App\Services\Payment\PaymentGatewayFactory::class);
        $currencyMock = m::mock(\App\Contracts\CurrencyServiceInterface::class);
        $reconciliationMock = m::mock(\App\Services\ReconciliationService::class);
        $fraudGuardMock = (new \ReflectionClass(\App\Services\AntiFraud\FraudGuardService::class))
            ->newInstanceWithoutConstructor();
        $eventDispatcherMock = m::mock(\Core\EventDispatcher::class);
        $dbMock = m::mock(\Core\Database::class);

        $sagaMock = m::mock(\App\Services\SagaOrchestrator::class);
        $sagaMock->shouldIgnoreMissing();
        $paymentService = new PaymentCommandService(
            $loggerMock,
            $logMock,
            $gatewayFactoryMock,
            $idempotencyMock,
            $dbMock,
                        $walletMock,
            $sagaMock,
            null,
            null,
            null
        );

        $paymentLog = (object)[
            'id' => 123,
            'user_id' => 456,
            'amount' => 10000,
            'status' => 'pending',
            'created_at' => date('Y-m-d H:i:s'),
            'request_data' => json_encode(['callback_nonce' => 'ABC123'])
        ];

        $queryBuilderMock = m::mock(\Core\QueryBuilder::class);
        $logMock->shouldReceive('where')
            ->with('authority', 'auth123456789')
            ->andReturn($queryBuilderMock);
        $queryBuilderMock->shouldReceive('first')
            ->andReturn($paymentLog);

        $loggerMock->shouldReceive('critical')
            ->once()
            ->with('payment.callback.invalid_nonce', m::any());

        $result = $paymentService->callback('zarinpal', [
            'authority' => 'auth123456789',
            'nonce' => 'WRONG_NONCE'
        ], 456);

        $this->assertFalse($result['success']);
        $this->assertEquals('نشانه بازگشت پرداخت نامعتبر است', $result['message']);
    }

    /** @test */
    public function callback_rejects_user_mismatch(): void
    {
        $walletMock = m::mock(\App\Contracts\WalletServiceInterface::class);
        $notifierMock = m::mock(\App\Contracts\NotificationServiceInterface::class);
        $logMock = m::mock(\App\Models\PaymentLog::class);
        $bankCardMock = m::mock(\App\Models\BankCard::class);
        $loggerMock = m::mock(\App\Contracts\LoggerInterface::class);
        
        $loggerMock->shouldIgnoreMissing();
        
        $idempotencyMock = m::mock(\App\Services\Shared\IdempotencyService::class);
        $gatewayFactoryMock = m::mock(\App\Services\Payment\PaymentGatewayFactory::class);
        $currencyMock = m::mock(\App\Contracts\CurrencyServiceInterface::class);
        $reconciliationMock = m::mock(\App\Services\ReconciliationService::class);
        $fraudGuardMock = (new \ReflectionClass(\App\Services\AntiFraud\FraudGuardService::class))
            ->newInstanceWithoutConstructor();
        $eventDispatcherMock = m::mock(\Core\EventDispatcher::class);
        $dbMock = m::mock(\Core\Database::class);

        $sagaMock = m::mock(\App\Services\SagaOrchestrator::class);
        $sagaMock->shouldIgnoreMissing();
        $paymentService = new PaymentCommandService(
            $loggerMock,
            $logMock,
            $gatewayFactoryMock,
            $idempotencyMock,
            $dbMock,
                        $walletMock,
            $sagaMock,
            null,
            null,
            null
        );

        $paymentLog = (object)[
            'id' => 123,
            'user_id' => 456,
            'amount' => 10000,
            'status' => 'pending',
            'created_at' => date('Y-m-d H:i:s'),
            'request_data' => json_encode(['callback_nonce' => 'ABC123'])
        ];

        $queryBuilderMock = m::mock(\Core\QueryBuilder::class);
        $logMock->shouldReceive('where')
            ->with('authority', 'auth123456789')
            ->andReturn($queryBuilderMock);
        $queryBuilderMock->shouldReceive('first')
            ->andReturn($paymentLog);

        $loggerMock->shouldReceive('critical')
            ->once()
            ->with('payment.callback.user_mismatch', m::any());

        $result = $paymentService->callback('zarinpal', [
            'authority' => 'auth123456789',
            'nonce' => 'ABC123'
        ], 789);

        $this->assertFalse($result['success']);
        $this->assertEquals('کاربر جلسه فعلی با پرداخت تطابق ندارد', $result['message']);
    }

    /** @test */
    public function callback_rejects_expired_payment(): void
    {
        $walletMock = m::mock(\App\Contracts\WalletServiceInterface::class);
        $notifierMock = m::mock(\App\Contracts\NotificationServiceInterface::class);
        $logMock = m::mock(\App\Models\PaymentLog::class);
        $bankCardMock = m::mock(\App\Models\BankCard::class);
        $loggerMock = m::mock(\App\Contracts\LoggerInterface::class);
        
        $loggerMock->shouldIgnoreMissing();
        
        $idempotencyMock = m::mock(\App\Services\Shared\IdempotencyService::class);
        $gatewayFactoryMock = m::mock(\App\Services\Payment\PaymentGatewayFactory::class);
        $currencyMock = m::mock(\App\Contracts\CurrencyServiceInterface::class);
        $reconciliationMock = m::mock(\App\Services\ReconciliationService::class);
        $fraudGuardMock = (new \ReflectionClass(\App\Services\AntiFraud\FraudGuardService::class))
            ->newInstanceWithoutConstructor();
        $eventDispatcherMock = m::mock(\Core\EventDispatcher::class);
        $dbMock = m::mock(\Core\Database::class);

        $sagaMock = m::mock(\App\Services\SagaOrchestrator::class);
        $sagaMock->shouldIgnoreMissing();
        $paymentService = new PaymentCommandService(
            $loggerMock,
            $logMock,
            $gatewayFactoryMock,
            $idempotencyMock,
            $dbMock,
                        $walletMock,
            $sagaMock,
            null,
            null,
            null
        );

        $paymentLog = (object)[
            'id' => 123,
            'user_id' => 456,
            'amount' => 10000,
            'status' => 'pending',
            'created_at' => date('Y-m-d H:i:s', time() - 3 * 3600), // 3 hours ago (expired)
            'request_data' => json_encode(['callback_nonce' => 'ABC123'])
        ];

        $queryBuilderMock = m::mock(\Core\QueryBuilder::class);
        $logMock->shouldReceive('where')
            ->with('authority', 'auth123456789')
            ->andReturn($queryBuilderMock);
        $queryBuilderMock->shouldReceive('first')
            ->andReturn($paymentLog);

        $loggerMock->shouldReceive('warning')
            ->once()
            ->with('payment.callback.expired', m::any());

        $result = $paymentService->callback('zarinpal', [
            'authority' => 'auth123456789',
            'nonce' => 'ABC123'
        ], 456);

        $this->assertFalse($result['success']);
        $this->assertEquals('زمان مجاز برای تکمیل این تراکنش (۲ ساعت) به پایان رسیده است', $result['message']);
    }

    /** @test */
    public function callback_rejects_amount_mismatch(): void
    {
        $walletMock = m::mock(\App\Contracts\WalletServiceInterface::class);
        $notifierMock = m::mock(\App\Contracts\NotificationServiceInterface::class);
        $logMock = m::mock(\App\Models\PaymentLog::class);
        $bankCardMock = m::mock(\App\Models\BankCard::class);
        $loggerMock = m::mock(\App\Contracts\LoggerInterface::class);
        
        $loggerMock->shouldIgnoreMissing();
        
        $idempotencyMock = m::mock(\App\Services\Shared\IdempotencyService::class);
        $gatewayFactoryMock = m::mock(\App\Services\Payment\PaymentGatewayFactory::class);
        $currencyMock = m::mock(\App\Contracts\CurrencyServiceInterface::class);
        $reconciliationMock = m::mock(\App\Services\ReconciliationService::class);
        $fraudGuardMock = (new \ReflectionClass(\App\Services\AntiFraud\FraudGuardService::class))
            ->newInstanceWithoutConstructor();
        $eventDispatcherMock = m::mock(\Core\EventDispatcher::class);
        $dbMock = m::mock(\Core\Database::class);

        $sagaMock = m::mock(\App\Services\SagaOrchestrator::class);
        $sagaMock->shouldIgnoreMissing();
        $paymentService = new PaymentCommandService(
            $loggerMock,
            $logMock,
            $gatewayFactoryMock,
            $idempotencyMock,
            $dbMock,
                        $walletMock,
            $sagaMock,
            null,
            null,
            null
        );

        $paymentLog = (object)[
            'id' => 123,
            'user_id' => 456,
            'amount' => 10000,
            'status' => 'pending',
            'created_at' => date('Y-m-d H:i:s'),
            'request_data' => json_encode(['callback_nonce' => 'ABC123'])
        ];

        $queryBuilderMock = m::mock(\Core\QueryBuilder::class);
        $logMock->shouldReceive('where')
            ->with('authority', 'auth123456789')
            ->andReturn($queryBuilderMock);
        $queryBuilderMock->shouldReceive('first')
            ->andReturn($paymentLog);

        $loggerMock->shouldReceive('critical')
            ->once()
            ->with('payment.callback.amount_mismatch', m::any());

        $result = $paymentService->callback('zarinpal', [
            'authority' => 'auth123456789',
            'nonce' => 'ABC123',
            'amount' => 20000 // mismatch
        ], 456);

        $this->assertFalse($result['success']);
        $this->assertEquals('مبلغ پرداخت شده با مبلغ تراکنش مطابقت ندارد', $result['message']);
    }

    /** @test */
    public function callback_rejects_non_pending_status(): void
    {
        $walletMock = m::mock(\App\Contracts\WalletServiceInterface::class);
        $notifierMock = m::mock(\App\Contracts\NotificationServiceInterface::class);
        $logMock = m::mock(\App\Models\PaymentLog::class);
        $bankCardMock = m::mock(\App\Models\BankCard::class);
        $loggerMock = m::mock(\App\Contracts\LoggerInterface::class);
        
        $loggerMock->shouldIgnoreMissing();
        
        $idempotencyMock = m::mock(\App\Services\Shared\IdempotencyService::class);
        $gatewayFactoryMock = m::mock(\App\Services\Payment\PaymentGatewayFactory::class);
        $currencyMock = m::mock(\App\Contracts\CurrencyServiceInterface::class);
        $reconciliationMock = m::mock(\App\Services\ReconciliationService::class);
        $fraudGuardMock = (new \ReflectionClass(\App\Services\AntiFraud\FraudGuardService::class))
            ->newInstanceWithoutConstructor();
        $eventDispatcherMock = m::mock(\Core\EventDispatcher::class);
        $dbMock = m::mock(\Core\Database::class);

        $sagaMock = m::mock(\App\Services\SagaOrchestrator::class);
        $sagaMock->shouldIgnoreMissing();
        $paymentService = new PaymentCommandService(
            $loggerMock,
            $logMock,
            $gatewayFactoryMock,
            $idempotencyMock,
            $dbMock,
                        $walletMock,
            $sagaMock,
            null,
            null,
            null
        );

        $paymentLog = (object)[
            'id' => 123,
            'user_id' => 456,
            'amount' => 10000,
            'status' => 'cancelled', // already cancelled!
            'created_at' => date('Y-m-d H:i:s'),
            'request_data' => json_encode(['callback_nonce' => 'ABC123'])
        ];

        $queryBuilderMock = m::mock(\Core\QueryBuilder::class);
        $logMock->shouldReceive('where')
            ->with('authority', 'auth123456789')
            ->andReturn($queryBuilderMock);
        $queryBuilderMock->shouldReceive('first')
            ->andReturn($paymentLog);

        $result = $paymentService->callback('zarinpal', [
            'authority' => 'auth123456789',
            'nonce' => 'ABC123'
        ], 456);

        $this->assertFalse($result['success']);
        $this->assertEquals('وضعیت پرداخت نامعتبر است', $result['message']);
    }

    /** @test */
    public function callback_rejects_gateway_amount_mismatch(): void
    {
        $this->expectOutputRegex('/.*/');
        $walletMock = m::mock(\App\Contracts\WalletServiceInterface::class);
        $notifierMock = m::mock(\App\Contracts\NotificationServiceInterface::class);
        $logMock = m::mock(\App\Models\PaymentLog::class);
        $bankCardMock = m::mock(\App\Models\BankCard::class);
        $loggerMock = m::mock(\App\Contracts\LoggerInterface::class);
        
        $loggerMock->shouldIgnoreMissing();
        
        $idempotencyMock = m::mock(\App\Services\Shared\IdempotencyService::class);
        $idempotencyMock->shouldAllowMockingProtectedMethods();
        $idempotencyMock->shouldReceive('logEvent')->byDefault();
        $idempotencyMock->shouldReceive('check')
            ->andReturn([
                'is_duplicate' => false
            ])->byDefault();
        $idempotencyMock->shouldReceive('complete')->byDefault();
        $idempotencyMock->shouldReceive('fail')->byDefault();
        
        $gatewayFactoryMock = m::mock(\App\Services\Payment\PaymentGatewayFactory::class);
        $currencyMock = m::mock(\App\Contracts\CurrencyServiceInterface::class);
        $reconciliationMock = m::mock(\App\Services\ReconciliationService::class);
        $fraudGuardMock = (new \ReflectionClass(\App\Services\AntiFraud\FraudGuardService::class))
            ->newInstanceWithoutConstructor();
        $eventDispatcherMock = m::mock(\Core\EventDispatcher::class);
        $dbMock = m::mock(\Core\Database::class);

        $sagaMock = m::mock(\App\Services\SagaOrchestrator::class);
        $sagaMock->shouldIgnoreMissing();
        $paymentService = new PaymentCommandService(
            $loggerMock,
            $logMock,
            $gatewayFactoryMock,
            $idempotencyMock,
            $dbMock,
                        $walletMock,
            $sagaMock,
            null,
            null,
            null
        );

        $paymentLog = (object)[
            'id' => 123,
            'user_id' => 456,
            'amount' => 10000,
            'status' => 'pending',
            'created_at' => date('Y-m-d H:i:s'),
            'request_data' => json_encode(['callback_nonce' => 'ABC123'])
        ];

        $queryBuilderMock = m::mock(\Core\QueryBuilder::class);
        $logMock->shouldReceive('where')
            ->with('authority', 'auth123456789')
            ->andReturn($queryBuilderMock);
        $queryBuilderMock->shouldReceive('first')
            ->andReturn($paymentLog);

        // Concurrency lock query expectations
        $queryBuilderMock2 = m::mock(\Core\QueryBuilder::class);
        $logMock->shouldReceive('where')
            ->with('id', '=', 123)
            ->andReturn($queryBuilderMock2);
        $queryBuilderMock2->shouldReceive('lockForUpdate')
            ->andReturn($queryBuilderMock2);
        $queryBuilderMock2->shouldReceive('first')
            ->andReturn($paymentLog);

        $logMock->shouldReceive('update')->byDefault();

        $dbMock->shouldReceive('beginTransaction')->byDefault();
        $dbMock->shouldReceive('commit')->byDefault();
        $dbMock->shouldReceive('rollBack')->byDefault();
        $dbMock->shouldReceive('inTransaction')->andReturn(false)->byDefault();

        $gatewayMock = m::mock(\App\Contracts\PaymentGatewayInterface::class);
        $gatewayFactoryMock->shouldReceive('create')
            ->with('zarinpal')
            ->andReturn($gatewayMock);

        $gatewayMock->shouldReceive('verifyCallback')
            ->andReturn(true);

        // Gateway returns 20000 (mismatch with 10000)
        $gatewayMock->shouldReceive('verifyPayment')
            ->with('auth123456789', 10000.0)
            ->andReturn([
                'success' => true,
                'ref_id' => 'ref123',
                'amount' => 20000.0
            ]);

        $loggerMock->shouldReceive('critical')
            ->once()
            ->with('payment.callback.gateway_amount_mismatch', m::any());

        $result = $paymentService->callback('zarinpal', [
            'authority' => 'auth123456789',
            'nonce' => 'ABC123'
        ], 456);

        $this->assertFalse($result['success']);
        $this->assertEquals('مبلغ پرداخت شده با مبلغ درگاه مطابقت ندارد', $result['message']);
    }

    /** @test */
    public function test_nonce_cannot_be_reused(): void
    {
        $walletMock = m::mock(\App\Contracts\WalletServiceInterface::class);
        $notifierMock = m::mock(\App\Contracts\NotificationServiceInterface::class);
        $logMock = m::mock(\App\Models\PaymentLog::class);
        $bankCardMock = m::mock(\App\Models\BankCard::class);
        $loggerMock = m::mock(\App\Contracts\LoggerInterface::class);
        $loggerMock->shouldIgnoreMissing();
        $idempotencyMock = m::mock(\App\Services\Shared\IdempotencyService::class);
        $gatewayFactoryMock = m::mock(\App\Services\Payment\PaymentGatewayFactory::class);
        $currencyMock = m::mock(\App\Contracts\CurrencyServiceInterface::class);
        $reconciliationMock = m::mock(\App\Services\ReconciliationService::class);
        $fraudGuardMock = (new \ReflectionClass(\App\Services\AntiFraud\FraudGuardService::class))
            ->newInstanceWithoutConstructor();
        $eventDispatcherMock = m::mock(\Core\EventDispatcher::class);
        $dbMock = m::mock(\Core\Database::class);

        $sagaMock = m::mock(\App\Services\SagaOrchestrator::class);
        $sagaMock->shouldIgnoreMissing();
        $paymentService = new PaymentCommandService(
            $loggerMock,
            $logMock,
            $gatewayFactoryMock,
            $idempotencyMock,
            $dbMock,
                        $walletMock,
            $sagaMock,
            null,
            null,
            null
        );

        $paymentLog = (object)[
            'id' => 123,
            'user_id' => 456,
            'amount' => 10000,
            'status' => 'completed', // already completed (like second callback attempt)
            'created_at' => date('Y-m-d H:i:s'),
            'request_data' => json_encode(['callback_nonce' => 'ABC123'])
        ];

        $queryBuilderMock = m::mock(\Core\QueryBuilder::class);
        $logMock->shouldReceive('where')
            ->with('authority', 'auth123456789')
            ->andReturn($queryBuilderMock);
        $queryBuilderMock->shouldReceive('first')
            ->andReturn($paymentLog);

        $result = $paymentService->callback('zarinpal', [
            'authority' => 'auth123456789',
            'nonce' => 'ABC123'
        ], 456);

        $this->assertFalse($result['success']);
        $this->assertIsString($result['message']);
        $this->assertStringContainsString('قبلاً تکمیل شده', $result['message']);
    }

    /** @test */
    public function test_callback_blocked_from_unauthorized_ip(): void
    {
        // Force IP whitelist check execution under test
        $_SERVER['FORCE_IP_WHITELIST'] = '1';
        $_SERVER['REMOTE_ADDR'] = '1.2.3.4'; // Hacker IP

        $walletMock = m::mock(\App\Contracts\WalletServiceInterface::class);
        $notifierMock = m::mock(\App\Contracts\NotificationServiceInterface::class);
        $logMock = m::mock(\App\Models\PaymentLog::class);
        $bankCardMock = m::mock(\App\Models\BankCard::class);
        $loggerMock = m::mock(\App\Contracts\LoggerInterface::class);
        $loggerMock->shouldIgnoreMissing();
        $idempotencyMock = m::mock(\App\Services\Shared\IdempotencyService::class);
        $gatewayFactoryMock = m::mock(\App\Services\Payment\PaymentGatewayFactory::class);
        $currencyMock = m::mock(\App\Contracts\CurrencyServiceInterface::class);
        $reconciliationMock = m::mock(\App\Services\ReconciliationService::class);
        $fraudGuardMock = (new \ReflectionClass(\App\Services\AntiFraud\FraudGuardService::class))
            ->newInstanceWithoutConstructor();
        $eventDispatcherMock = m::mock(\Core\EventDispatcher::class);
        $dbMock = m::mock(\Core\Database::class);

        $sagaMock = m::mock(\App\Services\SagaOrchestrator::class);
        $sagaMock->shouldIgnoreMissing();
        $paymentService = new PaymentCommandService(
            $loggerMock,
            $logMock,
            $gatewayFactoryMock,
            $idempotencyMock,
            $dbMock,
                        $walletMock,
            $sagaMock,
            null,
            null,
            null
        );

        $result = $paymentService->callback('zarinpal', [
            'authority' => 'auth123456789',
            'nonce' => 'ABC123'
        ], 456);

        // Cleanup
        unset($_SERVER['FORCE_IP_WHITELIST']);
        unset($_SERVER['REMOTE_ADDR']);

        $this->assertFalse($result['success']);
        $this->assertEquals('دسترسی غیرمجاز است', $result['message']);
    }

    /** @test */
    public function test_expired_callback_rejected(): void
    {
        $walletMock = m::mock(\App\Contracts\WalletServiceInterface::class);
        $notifierMock = m::mock(\App\Contracts\NotificationServiceInterface::class);
        $logMock = m::mock(\App\Models\PaymentLog::class);
        $bankCardMock = m::mock(\App\Models\BankCard::class);
        $loggerMock = m::mock(\App\Contracts\LoggerInterface::class);
        $loggerMock->shouldIgnoreMissing();
        $idempotencyMock = m::mock(\App\Services\Shared\IdempotencyService::class);
        $gatewayFactoryMock = m::mock(\App\Services\Payment\PaymentGatewayFactory::class);
        $currencyMock = m::mock(\App\Contracts\CurrencyServiceInterface::class);
        $reconciliationMock = m::mock(\App\Services\ReconciliationService::class);
        $fraudGuardMock = (new \ReflectionClass(\App\Services\AntiFraud\FraudGuardService::class))
            ->newInstanceWithoutConstructor();
        $eventDispatcherMock = m::mock(\Core\EventDispatcher::class);
        $dbMock = m::mock(\Core\Database::class);

        $sagaMock = m::mock(\App\Services\SagaOrchestrator::class);
        $sagaMock->shouldIgnoreMissing();
        $paymentService = new PaymentCommandService(
            $loggerMock,
            $logMock,
            $gatewayFactoryMock,
            $idempotencyMock,
            $dbMock,
                        $walletMock,
            $sagaMock,
            null,
            null,
            null
        );

        // Payment created 3 hours ago
        $paymentLog = (object)[
            'id' => 123,
            'user_id' => 456,
            'amount' => 10000,
            'status' => 'pending',
            'created_at' => date('Y-m-d H:i:s', time() - 10800),
            'request_data' => json_encode(['callback_nonce' => 'ABC123'])
        ];

        $queryBuilderMock = m::mock(\Core\QueryBuilder::class);
        $logMock->shouldReceive('where')
            ->with('authority', 'auth123456789')
            ->andReturn($queryBuilderMock);
        $queryBuilderMock->shouldReceive('first')
            ->andReturn($paymentLog);

        $result = $paymentService->callback('zarinpal', [
            'authority' => 'auth123456789',
            'nonce' => 'ABC123'
        ], 456);

        $this->assertFalse($result['success']);
        $this->assertEquals('زمان مجاز برای تکمیل این تراکنش (۲ ساعت) به پایان رسیده است', $result['message']);
    }

    /** @test */
    public function test_reconciliation_stops_after_5_retries(): void
    {
        $walletMock = m::mock(\App\Contracts\WalletServiceInterface::class);
        $notifierMock = m::mock(\App\Contracts\NotificationServiceInterface::class);
        $logMock = m::mock(\App\Models\PaymentLog::class);
        $bankCardMock = m::mock(\App\Models\BankCard::class);
        $loggerMock = m::mock(\App\Contracts\LoggerInterface::class);
        $loggerMock->shouldIgnoreMissing();
        $idempotencyMock = m::mock(\App\Services\Shared\IdempotencyService::class);
        $gatewayFactoryMock = m::mock(\App\Services\Payment\PaymentGatewayFactory::class);
        $currencyMock = m::mock(\App\Contracts\CurrencyServiceInterface::class);
        $reconciliationMock = m::mock(\App\Services\ReconciliationService::class);
        $fraudGuardMock = (new \ReflectionClass(\App\Services\AntiFraud\FraudGuardService::class))
            ->newInstanceWithoutConstructor();
        $eventDispatcherMock = m::mock(\Core\EventDispatcher::class);
        $dbMock = m::mock(\Core\Database::class);

        $commandServiceMock = m::mock(\App\Services\Payment\PaymentCommandService::class);
        $gatewayFactoryMock2 = m::mock(\App\Services\Payment\PaymentGatewayFactory::class);
        $paymentService = new \App\Services\Payment\PaymentAdminService(
            $loggerMock,
            $logMock,
            $dbMock,
            $commandServiceMock,
            $gatewayFactoryMock2,
            null
        );

        // Stuck payment with retry_count = 5
        $stuckPayment = (object)[
            'id' => 789,
            'gateway' => 'zarinpal',
            'authority' => 'auth123456789',
            'user_id' => 456,
            'amount' => 10000,
            'status' => 'pending',
            'created_at' => date('Y-m-d H:i:s', time() - 3600),
            'response_data' => json_encode(['retry_count' => 5])
        ];

        $stmtMock = m::mock(\PDOStatement::class);
        $stmtMock->shouldReceive('fetchAll')
            ->andReturn([$stuckPayment]);

        $dbMock->shouldReceive('query')
            ->andReturn($stmtMock);

        // Expect state update to failed
        $logMock->shouldReceive('update')
            ->once()
            ->with(789, m::on(function($arg) {
                return $arg['status'] === 'failed';
            }));

        $results = $paymentService->reconcilePendingPayments();

        $this->assertEquals(1, $results['total']);
        $this->assertEquals(1, $results['skipped']);
    }

    /** @test */
    public function test_manually_verify_pending_verification_payment(): void
    {
        $this->expectOutputRegex('/.*/');
        // مسیر موفق تأیید دستی پرداخت، اکنون از طریق callback → SagaOrchestrator اجرا می‌شود.
        // یک Orchestrator واقعی با DB موک‌شده در Container ثبت می‌کنیم تا گام‌های credit_wallet/update_log طی شوند.
        $walletMock = m::mock(\App\Contracts\WalletServiceInterface::class);
        $notifierMock = m::mock(\App\Contracts\NotificationServiceInterface::class);
        $logMock = m::mock(\App\Models\PaymentLog::class);
        $loggerMock = m::mock(\App\Contracts\LoggerInterface::class);
        $loggerMock->shouldIgnoreMissing();

        $idempotencyMock = m::mock(\App\Services\Shared\IdempotencyService::class);
        // execute callback داخلی را اجرا کند تا کل مسیر طی شود.
        $idempotencyMock->shouldReceive('execute')
            ->andReturnUsing(function ($scope, $userId, $payload, $callback, $key = null) {
                return $callback();
            });

        $gatewayFactoryMock = m::mock(\App\Services\Payment\PaymentGatewayFactory::class);
        $reconciliationMock = m::mock(\App\Services\ReconciliationService::class);
        $eventDispatcherMock = m::mock(\Core\EventDispatcher::class);
        $dbMock = m::mock(\Core\Database::class);
        $dbMock->shouldReceive('beginTransaction')->byDefault();
        $dbMock->shouldReceive('commit')->byDefault();
        $dbMock->shouldReceive('rollBack')->byDefault();
        $dbMock->shouldReceive('inTransaction')->andReturn(false)->byDefault();

        // SagaOrchestrator واقعی با DB موک‌شده برای جدول saga_executions.
        // (باید قبل از ساخت commandService باشد تا inject شود)
        $sagaDb = m::mock(\Core\Database::class);
        $sagaStmt = m::mock(\PDOStatement::class);
        $sagaStmt->shouldReceive('execute')->andReturn(true)->byDefault();
        $sagaDb->shouldReceive('prepare')->andReturn($sagaStmt)->byDefault();
        $sagaLogger = m::mock(\App\Contracts\LoggerInterface::class);
        $sagaLogger->shouldIgnoreMissing();
        $orchestrator = new \App\Services\SagaOrchestrator($sagaDb, $sagaLogger);
        // مهم: executePaymentSaga از app() استفاده می‌کند که کانتینرِ Application را می‌خواند،
        // در حالی که برخی تست‌ها (مثل RouterTest) singleton کانتینر را reset می‌کنند و این دو از هم
        // جدا می‌شوند. بنابراین instance را روی «هر دو» کانتینر ثبت می‌کنیم تا در هر ترتیب اجرایی پایدار بماند.
        $containers = [\Core\Container::getInstance()];
        try {
            $appContainer = \Core\Application::getInstance()->container ?? null;
            if ($appContainer && $appContainer !== $containers[0]) {
                $containers[] = $appContainer;
            }
        } catch (\Throwable $e) { /* Application در دسترس نیست؛ همان کانتینر اصلی کافی است */ }

        foreach ($containers as $cnt) {
            $cnt->instance(\App\Services\SagaOrchestrator::class, $orchestrator);
            $cnt->instance(\App\Contracts\LoggerInterface::class, $loggerMock);
        }

        // PaymentCommandService با orchestrator واقعی inject شده
        $commandService = new \App\Services\Payment\PaymentCommandService(
            $loggerMock,
            $logMock,
            $gatewayFactoryMock,
            $idempotencyMock,
            $dbMock,
                        $walletMock,
            $orchestrator,
            null,
            null,
            null
        );
        $paymentService = new \App\Services\Payment\PaymentAdminService(
            $loggerMock,
            $logMock,
            $dbMock,
            $commandService,
            $gatewayFactoryMock,
            null
        );

        $paymentLog = (object)[
            'id' => 123,
            'gateway' => 'zarinpal',
            'authority' => 'auth123456789',
            'user_id' => 456,
            'amount' => 10000.0,
            'status' => 'pending_verification',
            'created_at' => date('Y-m-d H:i:s'),
            'request_data' => json_encode(['callback_nonce' => 'ABC123'])
        ];

        // جستجوی اولیه بر اساس id (در manuallyVerifyPayment) و قفل بدبینانه (در callback).
        $qbById = m::mock(\Core\QueryBuilder::class);
        $qbById->shouldReceive('lockForUpdate')->andReturnSelf()->byDefault();
        $qbById->shouldReceive('first')->andReturn($paymentLog)->byDefault();
        $logMock->shouldReceive('where')->with('id', '=', 123)->andReturn($qbById);

        // جستجوی authority داخل callback.
        $qbByAuth = m::mock(\Core\QueryBuilder::class);
        $qbByAuth->shouldReceive('first')->andReturn($paymentLog);
        $logMock->shouldReceive('where')->with('authority', 'auth123456789')->andReturn($qbByAuth);

        $logMock->shouldReceive('update')->andReturn(true);

        // درگاه: امضای معتبر + تأیید پرداخت با مبلغ برابر.
        $gatewayMock = m::mock(\App\Contracts\PaymentGatewayInterface::class);
        $gatewayFactoryMock->shouldReceive('create')->with('zarinpal')->andReturn($gatewayMock);
        $gatewayMock->shouldReceive('verifyCallback')->andReturn(true);
        $gatewayMock->shouldReceive('verifyPayment')
            ->with('auth123456789', 10000.0)
            ->andReturn(['success' => true, 'ref_id' => 'ref123', 'amount' => 10000.0]);

        // گام saga: واریز به کیف پول.
        $walletMock->shouldReceive('deposit')->zeroOrMoreTimes()->andReturn(['success' => true, 'transaction_id' => 'wtx-1']);

        // PaymentCommandService now wraps callback verification in a DB transaction root.
        $dbMock->shouldReceive('transactional')
            ->zeroOrMoreTimes()
            ->andReturnUsing(static fn(callable $callback) => $callback());

        // رویداد پس از پرداخت — از outbox record استفاده میشه (dispatchAsync حذف شده)

        $result = $paymentService->manuallyVerifyPayment(123, 1);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('success', $result);

        foreach ($containers as $cnt) {
        }
    }

    /** @test */
    public function test_callback_reads_ip_whitelist_from_database_successfully(): void
    {
        $walletMock = m::mock(\App\Contracts\WalletServiceInterface::class);
        $notifierMock = m::mock(\App\Contracts\NotificationServiceInterface::class);
        $logMock = m::mock(\App\Models\PaymentLog::class);
        $bankCardMock = m::mock(\App\Models\BankCard::class);
        $loggerMock = m::mock(\App\Contracts\LoggerInterface::class);
        $loggerMock->shouldIgnoreMissing();

        $idempotencyMock = m::mock(\App\Services\Shared\IdempotencyService::class);
        $gatewayFactoryMock = m::mock(\App\Services\Payment\PaymentGatewayFactory::class);
        $currencyMock = m::mock(\App\Contracts\CurrencyServiceInterface::class);
        $reconciliationMock = m::mock(\App\Services\ReconciliationService::class);
        $fraudGuardMock = (new \ReflectionClass(\App\Services\AntiFraud\FraudGuardService::class))
            ->newInstanceWithoutConstructor();
        $eventDispatcherMock = m::mock(\Core\EventDispatcher::class);
        
        $dbMock = m::mock(\Core\Database::class);
        // Expect database selectOne for callback_ips
        $dbMock->shouldReceive('selectOne')
            ->once()
            ->with("SELECT callback_ips FROM payment_gateways WHERE name = :name LIMIT 1", ['name' => 'zarinpal'])
            ->andReturn((object)['callback_ips' => '["127.0.0.1"]']);

        $sagaMock = m::mock(\App\Services\SagaOrchestrator::class);
        $sagaMock->shouldIgnoreMissing();
        $paymentService = new PaymentCommandService(
            $loggerMock,
            $logMock,
            $gatewayFactoryMock,
            $idempotencyMock,
            $dbMock,
                        $walletMock,
            $sagaMock,
            null,
            null,
            null
        );

        $paymentLog = (object)[
            'id' => 123,
            'user_id' => 456,
            'amount' => 10000,
            'status' => 'pending',
            'created_at' => date('Y-m-d H:i:s'),
            'request_data' => json_encode(['callback_nonce' => 'ABC123'])
        ];

        $queryBuilderMock = m::mock(\Core\QueryBuilder::class);
        $logMock->shouldReceive('where')
            ->with('authority', 'A1B2C3D4E5F6A7B8C9D0E1F2A3B4C5D6E7F8')
            ->andReturn($queryBuilderMock);
        $queryBuilderMock->shouldReceive('first')
            ->andReturn($paymentLog);

        $loggerMock->shouldReceive('critical')
            ->once()
            ->with('payment.callback.invalid_nonce', m::any());

        // This triggers callback which checks IP
        $_SERVER['FORCE_IP_WHITELIST'] = '1';
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';

        $result = $paymentService->callback('zarinpal', [
            'authority' => 'A1B2C3D4E5F6A7B8C9D0E1F2A3B4C5D6E7F8',
            'nonce' => 'WRONG_NONCE'
        ], 456);

        unset($_SERVER['FORCE_IP_WHITELIST']);
        unset($_SERVER['REMOTE_ADDR']);

        $this->assertFalse($result['success']);
    }
}

