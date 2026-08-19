<?php

namespace Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use App\Services\Payment\PaymentGatewayFactory;
use App\Services\Payment\ZarinPalGateway;
use App\Services\Payment\IDPayGateway;
use App\Services\Payment\NextPayGateway;
use App\Services\Payment\DgPayGateway;
use App\Exceptions\PaymentGatewayException;
use Mockery as m;

class PaymentGatewaysTest extends TestCase
{
    protected function tearDown(): void
    {
        m::close();
        parent::tearDown();
    }

    /** @test */
    public function factory_throws_exception_on_unregistered_gateway(): void
    {
        $this->expectException(PaymentGatewayException::class);
        $this->expectExceptionMessage('درگاه پرداخت پشتیبانی نمی‌شود');

        $logger = m::mock('App\Contracts\LoggerInterface');
        $logger->shouldIgnoreMissing();

        // Resolver که هیچ درگاهی را نمی‌شناسد
        $resolver = fn($gateway) => throw new PaymentGatewayException("درگاه پرداخت پشتیبانی نمی‌شود: {$gateway}");
        
        $factory = new PaymentGatewayFactory($logger, $resolver);
        $factory->create('invalid_gateway');
    }

    /** @test */
    public function factory_resolves_registered_gateways_correctly(): void
    {
        $logger = m::mock('App\Contracts\LoggerInterface');
        $logger->shouldIgnoreMissing();

        $zarinpalMock = m::mock('App\Contracts\PaymentGatewayInterface');

        // Resolver که فقط zarinpal را می‌شناسد
        $resolver = function ($gateway) use ($zarinpalMock) {
            if ($gateway === 'zarinpal') {
                return $zarinpalMock;
            }
            throw new PaymentGatewayException("درگاه پرداخت پشتیبانی نمی‌شود: {$gateway}");
        };

        $factory = new PaymentGatewayFactory($logger, $resolver);

        $resolved = $factory->create('ZarinPal '); // Tests case-insensitivity and trimming
        $this->assertSame($zarinpalMock, $resolved);
        
        // تست caching — دومین بار باید همان instance قبلی برگردد
        $resolvedAgain = $factory->create('zarinpal');
        $this->assertSame($zarinpalMock, $resolvedAgain);
    }

    /** @test */
    public function factory_returns_list_of_available_gateways(): void
    {
        $gateways = PaymentGatewayFactory::getAvailableGateways();
        
        $this->assertArrayHasKey('zarinpal', $gateways);
        $this->assertArrayHasKey('nextpay', $gateways);
        $this->assertArrayHasKey('idpay', $gateways);
        $this->assertArrayHasKey('dgpay', $gateways);
        
        $zarinpal = $gateways['zarinpal'];
        $this->assertIsArray($zarinpal);
        $this->assertEquals('زرین‌پال', $zarinpal['name']);
    }

    /** @test */
    public function factory_caches_gateway_instances(): void
    {
        $logger = m::mock('App\Contracts\LoggerInterface');
        $logger->shouldIgnoreMissing();

        $gatewayMock = m::mock('App\Contracts\PaymentGatewayInterface');
        $resolveCount = 0;
        
        $resolver = function ($gateway) use ($gatewayMock, &$resolveCount) {
            $resolveCount++;
            return $gatewayMock;
        };

        $factory = new PaymentGatewayFactory($logger, $resolver);

        // سه بار resolve — فقط بار اول باید resolver صدا بخورد
        $factory->create('zarinpal');
        $factory->create('zarinpal');
        $factory->create('zarinpal');

        $this->assertEquals(1, $resolveCount, 'Gateway should be resolved only once due to caching');
    }

    /** @test */
    public function factory_validates_gateway_name(): void
    {
        $logger = m::mock('App\Contracts\LoggerInterface');
        $logger->shouldIgnoreMissing();

        $resolver = fn($g) => throw new \RuntimeException('should not reach here');
        $factory = new PaymentGatewayFactory($logger, $resolver);

        // تست نام خالی
        try {
            $factory->create('');
            $this->fail('Should throw for empty name');
        } catch (PaymentGatewayException $e) {
            $this->assertStringContainsString('نامعتبر', $e->getMessage());
        }

        // تست نام خیلی طولانی
        try {
            $factory->create(str_repeat('a', 51));
            $this->fail('Should throw for too long name');
        } catch (PaymentGatewayException $e) {
            $this->assertStringContainsString('بیش‌ازحد طولانی', $e->getMessage());
        }
    }

    /** @test */
    public function factory_rejects_non_interface_instance(): void
    {
        $logger = m::mock('App\Contracts\LoggerInterface');
        $logger->shouldIgnoreMissing();

        // Resolver که object اشتباه برمی‌گرداند
        $resolver = fn($g) => new \stdClass();
        $factory = new PaymentGatewayFactory($logger, $resolver);

        $this->expectException(PaymentGatewayException::class);
        $this->expectExceptionMessage('PaymentGatewayInterface');

        $factory->create('zarinpal');
    }

    /** @test */
    public function factory_handles_resolver_exception_gracefully(): void
    {
        $logger = m::mock('App\Contracts\LoggerInterface');
        $logger->shouldIgnoreMissing();
        $logger->shouldReceive('error')->once();

        // Resolver که exception می‌اندازد
        $resolver = fn($g) => throw new \RuntimeException('DB connection failed');
        $factory = new PaymentGatewayFactory($logger, $resolver);

        $this->expectException(PaymentGatewayException::class);
        $this->expectExceptionMessage('خطا در راه‌اندازی درگاه پرداخت');

        $factory->create('zarinpal');
    }

    /** @test */
    public function zarinpal_gateway_returns_correct_metadata(): void
    {
        $model = m::mock('App\Models\PaymentGateway');
        $model->shouldReceive('getActiveGateway')->with('zarinpal')->once()->andReturn((object)['merchant_id' => '1234']);

        $settings = m::mock('App\Services\Settings\AppSettings');
        $circuitBreaker = m::mock('Core\CircuitBreaker');
        $logger = m::mock('App\Contracts\LoggerInterface');

        $gateway = new ZarinPalGateway($model, $circuitBreaker, $logger);

        $this->assertEquals('zarinpal', $gateway->getName());
        $this->assertEquals('zarinpal', $gateway->getGatewayName());
        $this->assertTrue($gateway->isActive());
    }

    /** @test */
    public function zarinpal_verify_reads_code_ref_id_and_amount_from_nested_payload(): void
    {
        $model = m::mock('App\\Models\\PaymentGateway');
        $model->shouldReceive('getActiveGateway')->with('zarinpal')->once()->andReturn((object)[
            'merchant_id' => 'merchant-test',
            'is_test_mode' => false,
        ]);
        $settings = m::mock('App\\Services\\Settings\\AppSettings');
        $circuitBreaker = m::mock('Core\\CircuitBreaker');
        $logger = m::mock('App\\Contracts\\LoggerInterface');
        $logger->shouldIgnoreMissing();

        $gateway = new class($model, $circuitBreaker, $logger) extends ZarinPalGateway {
            /** @var array<string, mixed> */
            public array $fixtureResponse = [];

            protected function executeWithCircuitBreaker(
                string $url,
                array $data = [],
                string $method = 'POST',
                array $headers = [],
                string $contentType = 'json'
            ): array {
                return $this->fixtureResponse;
            }
        };

        foreach ([100, 101] as $code) {
            $gateway->fixtureResponse = [
                'success' => true,
                'http_code' => 200,
                'data' => [
                    'data' => [
                        'code' => $code,
                        'ref_id' => 'REF-' . $code,
                        'amount' => '250000',
                    ],
                ],
            ];

            $result = $gateway->verifyPayment('AUTHORITY-TEST', '250000');
            $this->assertTrue($result['success']);
            $this->assertSame('REF-' . $code, $result['ref_id']);
            $this->assertSame('250000', $result['amount']);
        }
    }

    /** @test */
    public function nextpay_gateway_returns_correct_metadata(): void
    {
        $model = m::mock('App\Models\PaymentGateway');
        $model->shouldReceive('getActiveGateway')->with('nextpay')->once()->andReturn(null);

        $settings = m::mock('App\Services\Settings\AppSettings');
        $circuitBreaker = m::mock('Core\CircuitBreaker');
        $logger = m::mock('App\Contracts\LoggerInterface');

        $gateway = new NextPayGateway($model, $circuitBreaker, $logger);

        $this->assertEquals('nextpay', $gateway->getName());
        $this->assertEquals('nextpay', $gateway->getGatewayName());
        $this->assertFalse($gateway->isActive());
    }

    /** @test */
    public function idpay_gateway_returns_correct_metadata(): void
    {
        $model = m::mock('App\Models\PaymentGateway');
        $model->shouldReceive('getActiveGateway')->with('idpay')->once()->andReturn((object)['merchant_id' => 'idpay_merchant']);

        $settings = m::mock('App\Services\Settings\AppSettings');
        $circuitBreaker = m::mock('Core\CircuitBreaker');
        $logger = m::mock('App\Contracts\LoggerInterface');

        $gateway = new IDPayGateway($model, $circuitBreaker, $logger);

        $this->assertEquals('idpay', $gateway->getName());
        $this->assertEquals('idpay', $gateway->getGatewayName());
        $this->assertTrue($gateway->isActive());
    }

    /** @test */
    public function dgpay_gateway_returns_correct_metadata(): void
    {
        $model = m::mock('App\Models\PaymentGateway');
        $model->shouldReceive('getActiveGateway')->with('dgpay')->once()->andReturn(null);

        $settings = m::mock('App\Services\Settings\AppSettings');
        $circuitBreaker = m::mock('Core\CircuitBreaker');
        $logger = m::mock('App\Contracts\LoggerInterface');

        $gateway = new DgPayGateway($model, $circuitBreaker, $logger);

        $this->assertEquals('dgpay', $gateway->getName());
        $this->assertEquals('dgpay', $gateway->getGatewayName());
        $this->assertFalse($gateway->isActive());
    }
}
