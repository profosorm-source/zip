<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use App\Services\Payment\PaymentCommandService;
use App\Contracts\CircuitBreakerInterface;
use App\Services\Shared\IdempotencyService;
use Core\Exceptions\CircuitBreakerOpenException;
use Mockery as m;

/**
 * CircuitBreakerPaymentTest
 *
 * تست پوشش Circuit Breaker در PaymentCommandService:
 *  1. وقتی circuit باز است → create() پیام مناسب برمی‌گرداند
 *  2. وقتی circuit باز است → callback() پیام مناسب برمی‌گرداند
 *  3. وقتی circuit بسته است → create() ادامه می‌دهد
 *  4. خطای CB از داخل gateway → null برمی‌گرداند با پیام مناسب
 */
class CircuitBreakerPaymentTest extends TestCase
{
    /** @var \App\Contracts\LoggerInterface&\Mockery\MockInterface */
    private \App\Contracts\LoggerInterface $logger;
    /** @var \App\Models\PaymentLog&\Mockery\MockInterface */
    private \App\Models\PaymentLog $log;
    /** @var \App\Services\Payment\PaymentGatewayFactory&\Mockery\MockInterface */
    private \App\Services\Payment\PaymentGatewayFactory $gatewayFactory;
    /** @var IdempotencyService&\Mockery\MockInterface */
    private IdempotencyService $idempotency;
    /** @var \Core\Database&\Mockery\MockInterface */
    private \Core\Database $db;
    /** @var \Core\EventDispatcher&\Mockery\MockInterface */
    private \Core\EventDispatcher $eventDispatcher;
    /** @var \App\Contracts\WalletServiceInterface&\Mockery\MockInterface */
    private \App\Contracts\WalletServiceInterface $walletService;
    /** @var \App\Contracts\NotificationServiceInterface&\Mockery\MockInterface */
    private \App\Contracts\NotificationServiceInterface $notifier;
    /** @var CircuitBreakerInterface&\Mockery\MockInterface */
    private CircuitBreakerInterface $circuitBreaker;

    protected function setUp(): void
    {
        parent::setUp();
        $this->logger = m::mock(\App\Contracts\LoggerInterface::class);
        $this->logger->shouldIgnoreMissing();
        $this->log = m::mock(\App\Models\PaymentLog::class);
        $this->log->shouldIgnoreMissing();
        $this->gatewayFactory  = m::mock(\App\Services\Payment\PaymentGatewayFactory::class);
        $this->idempotency = m::mock(IdempotencyService::class);
        $this->idempotency->shouldIgnoreMissing();
        $this->db = m::mock(\Core\Database::class);
        $this->db->shouldIgnoreMissing();
        $this->eventDispatcher = m::mock(\Core\EventDispatcher::class);
        $this->eventDispatcher->shouldIgnoreMissing();
        $this->walletService = m::mock(\App\Contracts\WalletServiceInterface::class);
        $this->walletService->shouldIgnoreMissing();
        $this->notifier = m::mock(\App\Contracts\NotificationServiceInterface::class);
        $this->notifier->shouldIgnoreMissing();
        $this->circuitBreaker  = m::mock(CircuitBreakerInterface::class);
    }

    protected function tearDown(): void
    {
        m::close();
        parent::tearDown();
    }

    private function makeService(): PaymentCommandService
    {
        $saga = m::mock(\App\Services\SagaOrchestrator::class);
        $saga->shouldIgnoreMissing();
        return new PaymentCommandService(
            $this->logger,
            $this->log,
            $this->gatewayFactory,
            $this->idempotency,
            $this->db,
            $this->walletService,
            $saga,
            $this->circuitBreaker
        );
    }

    // ─── تست ۱: create() با circuit باز ─────────────────────────────────────

    /** @test */
    public function create_returns_unavailable_message_when_circuit_is_open(): void
    {
        // circuit برای zarinpal باز است
        $this->circuitBreaker->allows('isOpen')
            ->with('payment_gateway:zarinpal')
            ->andReturn(true);

        $result = $this->makeService()->create(1, 'zarinpal', '10000', 0, 'idem-key-1');

        $this->assertFalse($result['success']);
        $this->assertIsString($result['message']);
        $this->assertStringContainsString('موقتاً در دسترس نیست', $result['message']);
        $this->assertEquals('GATEWAY_CIRCUIT_OPEN', $result['error_code']);
    }

    // ─── تست ۲: callback() با circuit باز ───────────────────────────────────

    /** @test */
    public function callback_returns_unavailable_message_when_circuit_is_open(): void
    {
        // circuit باز است
        $this->circuitBreaker->allows('isOpen')
            ->with('payment_gateway:zarinpal')
            ->andReturn(true);

        // payment record پیدا می‌شود
        $pay = (object)[
            'id'           => 5,
            'gateway'      => 'zarinpal',
            'authority'    => 'AUTH12345678901234567890123456789012',
            'user_id'      => 1,
            'amount'       => 10000.0,
            'status'       => 'pending',
            'created_at'   => date('Y-m-d H:i:s'),
            'request_data' => json_encode(['callback_nonce' => 'TESTNONCE123']),
        ];
        $qb = m::mock(\Core\QueryBuilder::class);
        $qb->allows('first')->andReturn($pay);
        $this->log->allows('where')->with('authority', 'AUTH12345678901234567890123456789012')->andReturn($qb);

        $result = $this->makeService()->callback('zarinpal', [
            'authority' => 'AUTH12345678901234567890123456789012',
            'nonce'     => 'TESTNONCE123',
            'Status'    => 'OK',
        ]);

        $this->assertFalse($result['success']);
        $this->assertIsString($result['message']);
        $this->assertStringContainsString('موقتاً در دسترس نیست', $result['message']);
        $this->assertEquals('GATEWAY_CIRCUIT_OPEN', $result['error_code']);
    }

    // ─── تست ۳: circuit بسته → به gatewayFactory می‌رود ─────────────────────

    /** @test */
    public function create_proceeds_to_gateway_when_circuit_is_closed(): void
    {
        $this->circuitBreaker->allows('isOpen')
            ->with('payment_gateway:zarinpal')
            ->andReturn(false);

        // gateway ساخته می‌شود اما exception می‌اندازد (برای قطع flow)
        $this->gatewayFactory->allows('create')
            ->with('zarinpal')
            ->andThrow(new \RuntimeException('gateway_created_ok_but_test_stops_here'));

        $result = $this->makeService()->create(1, 'zarinpal', '10000', 0, 'idem-key');

        // چون gateway ساخته نشد (exception)، success=false اما دیگر CB_OPEN نیست
        $this->assertFalse($result['success']);
        $this->assertArrayNotHasKey('error_code', $result,
            'Non-CB error should not set GATEWAY_CIRCUIT_OPEN error_code');
    }

    // ─── تست ۴: CircuitBreakerOpenException از داخل gateway ────────────────

    /** @test */
    public function gateway_creation_catches_circuit_breaker_exception_from_factory(): void
    {
        // circuit بسته به نظر می‌رسد
        $this->circuitBreaker->allows('isOpen')
            ->with('payment_gateway:zarinpal')
            ->andReturn(false);

        // اما factory یک CircuitBreakerOpenException می‌اندازد (race condition)
        $this->gatewayFactory->allows('create')
            ->with('zarinpal')
            ->andThrow(new CircuitBreakerOpenException('payment_gateway:zarinpal'));

        $result = $this->makeService()->create(1, 'zarinpal', '10000', 0, 'idem-key');

        $this->assertFalse($result['success']);
        // پیام باید در مورد عدم دسترسی باشد (چون null برگشت و isOpen دوباره چک می‌شود)
        $this->assertNotEmpty($result['message']);
    }

    // ─── تست ۵: بدون Circuit Breaker inject شده → عادی کار می‌کند ──────────

    /** @test */
    public function service_works_without_circuit_breaker(): void
    {
        // سرویس بدون CB ساخته می‌شود
        $saga = m::mock(\App\Services\SagaOrchestrator::class);
        $saga->shouldIgnoreMissing();
        $service = new PaymentCommandService(
            $this->logger,
            $this->log,
            $this->gatewayFactory,
            $this->idempotency,
            $this->db,
            $this->walletService,
            $saga,
            null   // بدون CB
        );

        // gateway باید مستقیم ساخته شود
        $this->gatewayFactory->allows('create')
            ->with('zarinpal')
            ->andThrow(new \RuntimeException('no_cb_test'));

        $result = $service->create(1, 'zarinpal', '10000', 0, 'idem-key');

        $this->assertFalse($result['success']);
        // نباید error_code=GATEWAY_CIRCUIT_OPEN باشد
        $this->assertArrayNotHasKey('error_code', $result);
    }

    // ─── تست ۶: تایید config keys صحیح هستند ────────────────────────────────

    /**
     * @test
     * @group architecture
     */
    public function circuit_breaker_config_keys_are_present_for_all_gateways(): void
    {
        $config = config('circuit_breaker', []);
        $this->assertIsArray($config);

        $gateways = ['zarinpal', 'idpay', 'nextpay', 'dgpay'];
        foreach ($gateways as $gw) {
            $key = "payment_gateway:{$gw}";
            $this->assertArrayHasKey($key, $config,
                "Circuit breaker config missing for gateway: {$gw} (key: {$key})");
            $gatewayConfig = $config[$key];
            $this->assertIsArray($gatewayConfig);
            $this->assertArrayHasKey('threshold', $gatewayConfig,
                "threshold missing in circuit breaker config for: {$gw}");
            $this->assertArrayHasKey('timeout', $gatewayConfig,
                "timeout missing in circuit breaker config for: {$gw}");
            $this->assertGreaterThan(0, $gatewayConfig['threshold']);
            $this->assertGreaterThan(0, $gatewayConfig['timeout']);
        }
    }
}
