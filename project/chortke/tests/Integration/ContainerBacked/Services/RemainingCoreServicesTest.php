<?php

namespace Tests\Integration\ContainerBacked\Services;

use PHPUnit\Framework\TestCase;
use App\Services\AdSystemManager;
use App\Services\ApiRateLimiter;
use App\Services\AuditTrail;
use App\Services\SagaOrchestrator;
use App\Services\Ads\AdsBudgetSettlementService;
use App\Contracts\WalletServiceInterface;
use Mockery as m;

/**
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class RemainingCoreServicesTest extends TestCase
{
    protected function tearDown(): void
    {
        // Reset EventDispatcher singleton
        $ref = new \ReflectionClass(\Core\EventDispatcher::class);
        $prop = $ref->getProperty('instance');
        $prop->setAccessible(true);
        $prop->setValue(null, null);

        m::close();
        parent::tearDown();
    }

    /** @test */
    public function ad_system_manager_is_instantiable(): void
    {
        $db = m::mock('Core\Database');
        $logger = m::mock('App\Contracts\LoggerInterface');
        $repository = m::mock('App\Contracts\AdsRepositoryInterface');
        $escrow = m::mock('App\Services\EscrowService');

        $logger->shouldIgnoreMissing();

        $saga = m::mock(SagaOrchestrator::class);
        $wallet = m::mock(WalletServiceInterface::class);
        $budgetSettlement = m::mock(AdsBudgetSettlementService::class);
        $manager = new AdSystemManager($db, $logger, [], $repository, $escrow, $saga, $wallet, $budgetSettlement);
        $this->assertInstanceOf(AdSystemManager::class, $manager);
    }

    /** @test */
    public function cancelling_an_ad_uses_the_canonical_budget_refund_path(): void
    {
        $db = m::mock('Core\Database');
        $logger = m::mock('App\Contracts\LoggerInterface');
        $repository = m::mock('App\Contracts\AdsRepositoryInterface');
        $escrow = m::mock('App\Services\EscrowService');
        $saga = m::mock(SagaOrchestrator::class);
        $wallet = m::mock(WalletServiceInterface::class);
        $budgetSettlement = m::mock(AdsBudgetSettlementService::class);
        $logger->shouldIgnoreMissing();
        $budgetSettlement->shouldReceive('refundRemainingBudget')
            ->once()
            ->with(42, 'cancelled', 'درخواست کاربر', 'user_7', 7)
            ->andReturn(['success' => true, 'message' => 'کمپین بسته شد.', 'refund_amount' => '15.00000000']);

        $manager = new AdSystemManager($db, $logger, [], $repository, $escrow, $saga, $wallet, $budgetSettlement);
        $result = $manager->cancelAd(42, 7, 'درخواست کاربر');

        $this->assertTrue($result['success']);
        $refundAmount = $result['refund_amount'] ?? null;
        $this->assertIsString($refundAmount);
        $this->assertSame('15.00000000', $refundAmount);
    }

    /** @test */
    public function api_rate_limiter_class_has_been_removed(): void
    {
        // ApiRateLimiter از پروژه حذف شده — تأیید میکنیم که دیگه وجود نداره
        $this->assertFalse(
            class_exists('App\\Services\\ApiRateLimiter', false),
            'ApiRateLimiter باید از پروژه حذف شده باشه'
        );
    }

    /** @test */
    public function audit_trail_records_events_via_event_dispatcher(): void
    {
        $logger = m::mock('App\Contracts\LoggerInterface');
        $model = m::mock('App\Models\AuditTrail');

        $logger->shouldIgnoreMissing();

        $dispatcher = m::mock('Core\EventDispatcher');
        $dispatcher->shouldReceive('dispatch')->once();
        
        // Register mock inside Container and reset static property
        \Core\Container::getInstance()->instance(\Core\EventDispatcher::class, $dispatcher);
        
        $ref = new \ReflectionClass(\Core\EventDispatcher::class);
        $prop = $ref->getProperty('instance');
        $prop->setAccessible(true);
        $prop->setValue(null, $dispatcher);

        $audit = new AuditTrail($model, new \Core\PathResolver(dirname(__DIR__, 4)), $logger);

        $result = $audit->record('user.login_attempt', 12, ['ip' => '1.1.1.1']);
        $this->assertTrue($result);
    }
}
