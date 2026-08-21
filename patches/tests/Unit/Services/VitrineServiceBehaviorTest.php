<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use Mockery as m;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use App\Services\VitrineService;
use App\Contracts\OutboxServiceInterface;

/**
 * @group architecture
 */
class VitrineServiceBehaviorTest extends TestCase
{
    // انتظاراتِ Mockery را به ادعای واقعی PHPUnit تبدیل می‌کند،
    // تا ادعای تهی برای دفعِ هشدارِ risky لازم نباشد.
    use MockeryPHPUnitIntegration;

    protected function tearDown(): void { m::close(); parent::tearDown(); }

    /**
     * @return array{
     *   svc: VitrineService,
     *   listing: \App\Models\VitrineListing&\Mockery\MockInterface,
     *   request: \App\Models\VitrineRequest&\Mockery\MockInterface,
     *   flags: \App\Services\FeatureFlagService&\Mockery\MockInterface,
     *   settings: \App\Services\Settings\AppSettings&\Mockery\MockInterface,
     *   outbox: OutboxServiceInterface|null,
     *   ob: OutboxServiceInterface&\Mockery\MockInterface
     * }
     */
    private function make(?OutboxServiceInterface $outbox = null): array
    {
        $ed = m::mock('Core\\EventDispatcher'); $ed->shouldIgnoreMissing();
        $listing = m::mock('App\\Models\\VitrineListing'); $listing->shouldIgnoreMissing();
        $request = m::mock('App\\Models\\VitrineRequest'); $request->shouldIgnoreMissing();
        $flags = m::mock('App\\Services\\FeatureFlagService'); $flags->shouldIgnoreMissing();
        $settings = m::mock('App\\Services\\Settings\\AppSettings'); $settings->shouldIgnoreMissing();
        $userService = m::mock('App\\Services\\User\\UserService'); $userService->shouldIgnoreMissing();
        $escrow = m::mock('App\\Services\\EscrowService'); $escrow->shouldIgnoreMissing();
        $financial = m::mock('App\\Domain\\Financial\\Services\\FinancialEscrowService'); $financial->shouldIgnoreMissing();
        $db = m::mock('Core\\Database'); $db->shouldIgnoreMissing();
        $logger = m::mock('App\\Contracts\\LoggerInterface'); $logger->shouldIgnoreMissing();
        $container = m::mock('Core\Container');
        if ($outbox === null) {
            $ob = m::mock(OutboxServiceInterface::class);
            $ob->shouldIgnoreMissing();
        } else {
            /** @var OutboxServiceInterface&\Mockery\MockInterface $ob */
            $ob = $outbox;
        }

        $svc = new VitrineService($ed, $listing, $request, $flags, $settings, $userService, $escrow, $financial, $db, $container, $logger, $ob);
        return compact('svc', 'listing', 'request', 'flags', 'settings', 'outbox') + ['ob' => $ob];
    }

    /** @test */
    public function is_enabled_checks_feature_flag(): void
    {
        $c = $this->make();
        $c['flags']->shouldReceive('isEnabled')->with('vitrine_enabled')->once()->andReturn(true);
        $this->assertTrue($c['svc']->isEnabled());
    }

    /** @test */
    public function is_enabled_returns_false_when_disabled(): void
    {
        $c = $this->make();
        $c['flags']->shouldReceive('isEnabled')->with('vitrine_enabled')->once()->andReturn(false);
        $this->assertFalse($c['svc']->isEnabled());
    }

    /** @test */
    public function notify_similar_listing_records_outbox(): void
    {
        $outbox = m::mock('App\\Contracts\\OutboxServiceInterface');
        $c = $this->make($outbox);

        $newListing = (object)['id' => 1, 'category' => 'digital', 'platform' => 'web', 'seller_id' => 5, 'title' => 'Test', 'status' => 'active', 'price_usdt' => '1.00000000'];
        $c['listing']->shouldReceive('getCategoryAlertUsers')->andReturn([10, 20, 5]);

        // seller_id=5 skip شده، 10 و 20 outbox بگیرن
        $outbox->shouldReceive('record')->twice()->andReturn(true);

        $c['svc']->notifySimilarListing($newListing);
    }

    /** @test */
    public function notify_listing_approved_records_outbox(): void
    {
        $outbox = m::mock('App\\Contracts\\OutboxServiceInterface');
        $c = $this->make($outbox);

        $listing = (object)['id' => 1, 'title' => 'Test Listing', 'category' => 'digital', 'platform' => 'web', 'seller_id' => 10, 'status' => 'active', 'price_usdt' => '1.00000000'];
        $outbox->shouldReceive('record')->with('notification', 10, 'notification.requested', m::type('array'))->once()->andReturn(true);

        $c['svc']->notifyListingApproved(10, $listing);
    }


}
