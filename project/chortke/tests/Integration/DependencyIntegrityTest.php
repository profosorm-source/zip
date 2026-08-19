<?php

declare(strict_types=1);

namespace App\Tests;

use PHPUnit\Framework\TestCase;
use App\Services\Payment\PaymentService;
use App\Services\Lottery\LotteryService;
use App\Controllers\Api\InfluencerController;
use Core\Container;

/**
 * Smoke Tests for Critical Services/Controllers
 * 
 * Verifies that:
 * 1. All dependencies are properly declared
 * 2. Constructor injection works
 * 3. Classes can be instantiated without errors
 */
/**
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class DependencyIntegrityTest extends TestCase
{
    private Container $container;

    protected function setUp(): void
    {
        $this->container = Container::getInstance();

        // ایزولاسیون: برخی تست‌های دیگر (مثل RouterTest) singleton کانتینر را reset می‌کنند
        // و bindingهای bootstrap از بین می‌رود. InfluencerController از طریق BaseController
        // وابستگی‌های null را از کانتینر resolve می‌کند؛ بنابراین bindingهای پایه را تضمین می‌کنیم
        // تا این تست مستقل از ترتیب اجرای سایر تست‌ها پایدار باشد.
        $ensure = [
            \Core\Session::class,
            \Core\Request::class,
            \Core\Response::class,
            \App\Services\Shared\PolicyService::class,
            \App\Contracts\LoggerInterface::class,
            \Core\CSRF::class,
        ];
        foreach ($ensure as $abstract) {
            if (!$this->container->has($abstract)) {
                $this->container->instance($abstract, $this->createMock($abstract));
            }
        }
    }

    private function makePaymentService(): PaymentService
    {
        $commandService = new \App\Services\Payment\PaymentCommandService(
            $this->createMock(\App\Contracts\LoggerInterface::class),
            $this->createMock(\App\Models\PaymentLog::class),
            $this->createMock(\App\Services\Payment\PaymentGatewayFactory::class),
            $this->createMock(\App\Services\Shared\IdempotencyService::class),
            $this->createMock(\Core\Database::class),
            $this->createMock(\App\Contracts\WalletServiceInterface::class),
            $this->createMock(\App\Services\SagaOrchestrator::class)
        );
        $adminService = new \App\Services\Payment\PaymentAdminService(
            $this->createMock(\App\Contracts\LoggerInterface::class),
            $this->createMock(\App\Models\PaymentLog::class),
            $this->createMock(\Core\Database::class),
            $commandService,
            $this->createMock(\App\Services\Payment\PaymentGatewayFactory::class)
        );
        $depositService = new \App\Services\Payment\PaymentDepositService(
            $this->createMock(\App\Contracts\LoggerInterface::class),
            $this->createMock(\Core\Database::class),
            $this->createMock(\App\Contracts\WalletServiceInterface::class),
            $this->createMock(\App\Services\SagaOrchestrator::class)
        );
        return new PaymentService($commandService, $adminService, $depositService);
    }

    private function makeLotteryService(): LotteryService
    {
        return new LotteryService(
            $this->createMock(\Core\Database::class),
            $this->createMock(\App\Models\LotteryRound::class),
            $this->createMock(\App\Models\LotteryParticipation::class),
            $this->createMock(\App\Models\LotteryDailyNumber::class),
            $this->createMock(\App\Contracts\LoggerInterface::class),
            \Core\Cache::getInstance(),
            $this->createMock(\Core\EventDispatcher::class),
            $this->createMock(\App\Contracts\WalletServiceInterface::class),
            $this->createMock(\App\Services\Shared\IdempotencyService::class),
            $this->createMock(\App\Services\SagaOrchestrator::class)
        );
    }

    private function makeInfluencerController(): InfluencerController
    {
        $session = $this->createMock(\Core\Session::class);
        $request = $this->createMock(\Core\Request::class);
        $response = $this->createMock(\Core\Response::class);

        return new InfluencerController(
            $this->createMock(\App\Services\InfluencerService::class),
            $this->createMock(\App\Services\Shared\DisputeService::class),
            $this->createMock(\App\Services\VerificationService::class),
            $this->createMock(\App\Services\UploadService::class),
            $this->createMock(\App\Models\InfluencerModel::class),
            $this->createMock(\App\Models\StoryOrder::class),
            $this->createMock(\App\Models\Dispute::class),
            $this->createMock(\App\Contracts\LoggerInterface::class)
        );
    }

    /**
     * @test
     * @group critical
     * Verify PaymentService can be instantiated with all dependencies
     */
    public function testPaymentServiceInstantiation(): void
    {
        try {
            $service = $this->makePaymentService();
            $this->assertInstanceOf(PaymentService::class, $service);
            
            // Verify critical methods exist
            $this->assertTrue(method_exists($service, 'create'));
            $this->assertTrue(method_exists($service, 'callback'));
        } catch (\Throwable $e) {
            $this->fail("PaymentService instantiation failed: {$e->getMessage()}");
        }
    }

    /**
     * @test
     * @group critical
     * Verify LotteryService can be instantiated with all dependencies
     */
    public function testLotteryServiceInstantiation(): void
    {
        try {
            $service = $this->makeLotteryService();
            $this->assertInstanceOf(LotteryService::class, $service);
            
            // Verify critical methods exist
            $this->assertTrue(method_exists($service, 'selectWinner'));
            $this->assertTrue(method_exists($service, 'cancelRound'));
        } catch (\Throwable $e) {
            $this->fail("LotteryService instantiation failed: {$e->getMessage()}");
        }
    }

    /**
     * @test
     * @group critical
     * Verify InfluencerController can be instantiated with all dependencies
     */
    public function testInfluencerControllerInstantiation(): void
    {
        try {
            $controller = $this->makeInfluencerController();
            $this->assertInstanceOf(InfluencerController::class, $controller);
            
            // Verify critical methods exist
            $this->assertTrue(method_exists($controller, 'myProfile'));
            $this->assertTrue(method_exists($controller, 'receivedOrders'));
            $this->assertTrue(method_exists($controller, 'getDispute'));
        } catch (\Throwable $e) {
            $this->fail("InfluencerController instantiation failed: {$e->getMessage()}");
        }
    }

    /**
     * @test
     * Verify PaymentService can access all injected dependencies
     */
    public function testPaymentServiceDependencyAccess(): void
    {
        try {
            $service = $this->makePaymentService();
            
            // Use reflection to verify private properties are set
            $reflection = new \ReflectionClass($service);
            $properties = $reflection->getProperties(\ReflectionProperty::IS_PRIVATE);
            
            $requiredProps = ['commandService', 'adminService', 'depositService'];
            $foundProps = [];
            
            foreach ($properties as $prop) {
                if (in_array($prop->name, $requiredProps)) {
                    $prop->setAccessible(true);
                    if ($prop->getValue($service) !== null) {
                        $foundProps[] = $prop->name;
                    }
                }
            }
            
            $this->assertEquals(
                count($requiredProps),
                count($foundProps),
                "Missing injected dependencies in PaymentService: " . 
                implode(', ', array_diff($requiredProps, $foundProps))
            );
        } catch (\Throwable $e) {
            $this->fail("PaymentService dependency access test failed: {$e->getMessage()}");
        }
    }

    /**
     * @test
     * Verify LotteryService can access all injected dependencies
     */
    public function testLotteryServiceDependencyAccess(): void
    {
        try {
            $service = $this->makeLotteryService();
            
            // Use reflection to verify private properties are set
            $reflection = new \ReflectionClass($service);
            $properties = $reflection->getProperties(\ReflectionProperty::IS_PRIVATE);
            
            $requiredProps = ['commandService', 'queryService'];
            $foundProps = [];
            
            foreach ($properties as $prop) {
                if (in_array($prop->name, $requiredProps)) {
                    $prop->setAccessible(true);
                    if ($prop->getValue($service) !== null) {
                        $foundProps[] = $prop->name;
                    }
                }
            }
            
            $this->assertEquals(
                count($requiredProps),
                count($foundProps),
                "Missing injected dependencies in LotteryService: " . implode(', ', array_diff($requiredProps, $foundProps))
            );
        } catch (\Throwable $e) {
            $this->fail("LotteryService dependency access test failed: {$e->getMessage()}");
        }
    }

    /**
     * @test
     * Verify InfluencerController can access all injected models
     */
    public function testInfluencerControllerModelAccess(): void
    {
        try {
            $controller = $this->makeInfluencerController();
            
            // Use reflection to verify private properties are set
            $reflection = new \ReflectionClass($controller);
            $properties = $reflection->getProperties(\ReflectionProperty::IS_PRIVATE);
            
            $requiredProps = ['profileModel', 'orderModel', 'disputeModel'];
            $foundProps = [];
            
            foreach ($properties as $prop) {
                if (in_array($prop->name, $requiredProps)) {
                    $prop->setAccessible(true);
                    if ($prop->getValue($controller) !== null) {
                        $foundProps[] = $prop->name;
                    }
                }
            }
            
            $this->assertEquals(
                count($requiredProps),
                count($foundProps),
                "Missing injected models in InfluencerController: " . 
                implode(', ', array_diff($requiredProps, $foundProps))
            );
        } catch (\Throwable $e) {
            $this->fail("InfluencerController model access test failed: {$e->getMessage()}");
        }
    }
}
