<?php

namespace Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use App\Services\VitrineService;
use App\Jobs\Vitrine\ConfirmVitrineDeliveryJob;
use App\Jobs\Vitrine\CreateVitrineListingJob;
use Mockery as m;

class VitrineSystemTest extends TestCase
{
    protected function tearDown(): void
    {
        m::close();
        parent::tearDown();
    }

    /** @test */
    public function service_and_jobs_can_be_instantiated(): void
    {
        $eventDispatcher = m::mock('Core\EventDispatcher');
        $listing = m::mock('App\Models\VitrineListing');
        $request = m::mock('App\Models\VitrineRequest');
        $flags = m::mock('App\Services\FeatureFlagService');
        $settings = m::mock('App\Services\Settings\AppSettings');
        $userService = m::mock('App\Services\User\UserService');
        $escrow = m::mock('App\Services\EscrowService');
        $financial = m::mock('App\Domain\Financial\Services\FinancialEscrowService');
        $db = m::mock('Core\Database');
        $logger = m::mock('App\Contracts\LoggerInterface');
        $container = m::mock('Core\Container');

        $service = new VitrineService($eventDispatcher, $listing, $request, $flags, $settings, $userService, $escrow, $financial, $db, $container, $logger, null);
        $this->assertInstanceOf(VitrineService::class, $service);

        $confirmJob = new ConfirmVitrineDeliveryJob($listing, $service);
        $this->assertInstanceOf(ConfirmVitrineDeliveryJob::class, $confirmJob);

        $createJob = new CreateVitrineListingJob($listing, $settings, $service, $eventDispatcher);
        $this->assertInstanceOf(CreateVitrineListingJob::class, $createJob);
    }
}
