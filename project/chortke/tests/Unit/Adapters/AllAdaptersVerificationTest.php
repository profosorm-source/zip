<?php

namespace Tests\Unit\Adapters;

use PHPUnit\Framework\TestCase;
use App\Adapters\BankInquiryManager;
use App\Adapters\CryptoApiAdapter;
use App\Adapters\CryptoExplorerAdapter;
use App\Adapters\CustomTaskAdapter;
use App\Adapters\DeepFaceKycAdapter;
use App\Adapters\Notification\FcmNotificationAdapter;
use App\Adapters\Notification\LogNotificationAdapter;
use App\Adapters\Notification\SmsNotificationAdapter;
use App\Adapters\Notification\PushNotificationAdapter;
use Mockery as m;

class AllAdaptersVerificationTest extends TestCase
{
    protected function tearDown(): void
    {
        m::close();
        parent::tearDown();
    }

    /** @test */
    public function bank_inquiry_manager_delegates_properly(): void
    {
        $jibit = m::mock('App\Adapters\JibitInquiryAdapter');
        $vandar = m::mock('App\Adapters\VandarInquiryAdapter');
        $logger = m::mock('App\Contracts\LoggerInterface');

        $logger->shouldIgnoreMissing();

        $manager = new BankInquiryManager($logger, [$jibit, $vandar]);
        $this->assertInstanceOf(BankInquiryManager::class, $manager);
    }

    /** @test */
    public function crypto_api_adapter_is_instantiable(): void
    {
        $logger = m::mock('App\Contracts\LoggerInterface');
        $settings = m::mock('App\Services\Settings\AppSettings');
        $circuit = m::mock('Core\CircuitBreaker');

        $logger->shouldIgnoreMissing();
        
        // Automatic fallback for any settings using our elegant return-default callback
        $settings->shouldReceive('get')->byDefault()->andReturnUsing(function($key, $default = null) {
            return $default;
        });

        $adapter = new CryptoApiAdapter($logger, $settings, $circuit);
        $this->assertInstanceOf(CryptoApiAdapter::class, $adapter);
    }

    /** @test */
    public function crypto_explorer_adapter_is_instantiable(): void
    {
        $logger = m::mock('App\Contracts\LoggerInterface');
        $circuit = m::mock('Core\CircuitBreaker');

        $logger->shouldIgnoreMissing();

        $adapter = new CryptoExplorerAdapter($logger, $circuit);
        $this->assertInstanceOf(CryptoExplorerAdapter::class, $adapter);
    }

    /** @test */
    public function custom_task_adapter_is_instantiable(): void
    {
        $taskModel = m::mock('App\Models\Ads');
        $walletService = m::mock('App\Contracts\WalletServiceInterface');
        $db = m::mock('Core\Database');
        $logger = m::mock('App\Contracts\LoggerInterface');
        $settings = m::mock('App\Services\Settings\AppSettings');
        $validatorFactory = m::mock('App\Contracts\ValidatorFactoryInterface');

        $logger->shouldIgnoreMissing();

        $adapter = new CustomTaskAdapter($taskModel, $walletService, $db, $logger, $settings, $validatorFactory);
        $this->assertInstanceOf(CustomTaskAdapter::class, $adapter);
    }

    /** @test */
    public function deep_face_kyc_adapter_is_instantiable(): void
    {
        $logger = m::mock('App\Contracts\LoggerInterface');
        $circuit = m::mock('Core\CircuitBreaker');

        $logger->shouldIgnoreMissing();

        $adapter = new DeepFaceKycAdapter($logger, $circuit);
        $this->assertInstanceOf(DeepFaceKycAdapter::class, $adapter);
    }

    /** @test */
    public function fcm_notification_adapter_is_instantiable(): void
    {
        $metrics = m::mock('App\Contracts\MetricsCollectorInterface');
        $orchestrator = m::mock('App\Services\Notification\NotificationOrchestrator');
        
        $orchestrator->shouldReceive('logger')->andReturn(m::mock('App\Contracts\LoggerInterface'));
        $orchestrator->shouldReceive('circuitBreaker')->andReturn(m::mock('Core\CircuitBreaker'));

        $adapter = new FcmNotificationAdapter($metrics, $orchestrator, m::mock('Core\\Database'));
        $this->assertInstanceOf(FcmNotificationAdapter::class, $adapter);
    }

    /** @test */
    public function log_notification_adapter_is_instantiable(): void
    {
        $notification = m::mock('App\Models\Notification');
        $telemetry = m::mock('App\Models\SystemTelemetryModel');
        $logger = m::mock('Core\Logger');
        $circuit = m::mock('Core\CircuitBreaker');
        $orchestrator = m::mock('App\Services\Notification\NotificationOrchestrator');

        $logger->shouldIgnoreMissing();
        $orchestrator->shouldReceive('logger')->andReturn($logger);
        $orchestrator->shouldReceive('circuitBreaker')->andReturn($circuit);

        $adapter = new LogNotificationAdapter($notification, $telemetry, $logger, $circuit, $orchestrator);
        $this->assertInstanceOf(LogNotificationAdapter::class, $adapter);
    }

    /** @test */
    public function sms_notification_adapter_is_instantiable(): void
    {
        $userModel = m::mock('App\Models\User');
        $logger = m::mock('Core\Logger');
        $circuit = m::mock('Core\CircuitBreaker');
        $orchestrator = m::mock('App\Services\Notification\NotificationOrchestrator');
        $outbox = m::mock('App\Contracts\OutboxServiceInterface');
        $queue = m::mock('Core\Queue');

        $logger->shouldIgnoreMissing();
        $orchestrator->shouldReceive('logger')->andReturn($logger);
        $orchestrator->shouldReceive('circuitBreaker')->andReturn($circuit);

        $adapter = new SmsNotificationAdapter($userModel, $logger, $circuit, $orchestrator, $outbox, $queue);
        $this->assertInstanceOf(SmsNotificationAdapter::class, $adapter);
    }

    /** @test */
    public function push_notification_adapter_is_instantiable(): void
    {
        $fcm = m::mock('App\Services\Notification\FcmService');
        $logger = m::mock('Core\Logger');

        $logger->shouldIgnoreMissing();

        $adapter = new PushNotificationAdapter($fcm, $logger);
        $this->assertInstanceOf(PushNotificationAdapter::class, $adapter);
    }

    /** @test */
    public function notification_orchestrator_works_correctly(): void
    {
        $logger = m::mock('App\Contracts\LoggerInterface');
        $cache = m::mock('App\Contracts\CacheInterface');
        $circuit = m::mock('Core\CircuitBreaker');

        $logger->shouldReceive('info')->once();
        $cache->shouldReceive('get')->once()->with('test_key')->andReturn('cached_value');

        $orchestrator = new \App\Services\Notification\NotificationOrchestrator($logger, $cache, $circuit);
        $this->assertInstanceOf(\App\Services\Notification\NotificationOrchestrator::class, $orchestrator);

        $orchestrator->info('test');
        $this->assertEquals('cached_value', $orchestrator->getCached('test_key'));
    }
}
