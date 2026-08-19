<?php

namespace Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use App\Services\User\ProfileService;
use Mockery as m;

class ProfileServiceTest extends TestCase
{
    /** @var \App\Models\User&\Mockery\MockInterface */
    private \App\Models\User $userModel;
    /** @var \App\Contracts\LoggerInterface&\Mockery\MockInterface */
    private \App\Contracts\LoggerInterface $logger;
    /** @var \Core\TransactionWrapper&\Mockery\MockInterface */
    private \Core\TransactionWrapper $transactionWrapper;
    private ProfileService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->userModel = m::mock('App\Models\User');
        $this->logger = m::mock('App\Contracts\LoggerInterface');
        
        // ترتیب صحیح سازنده‌ی واقعی: (transactionWrapper, logger, userModel, cacheInvalidation?)
        $this->transactionWrapper = m::mock('Core\TransactionWrapper');
        $this->service = new ProfileService(
            $this->transactionWrapper,
            $this->logger,
            $this->userModel,
            null
        );
    }

    protected function tearDown(): void
    {
        m::close();
    }

    /** @test */
    public function service_can_be_instantiated(): void
    {
        $this->assertInstanceOf(ProfileService::class, $this->service);
    }

    /** @test */
    public function service_has_validation_method(): void
    {
        $this->assertTrue(method_exists($this->service, 'validateProfileUpdate'));
    }

    /** @test */
    public function service_has_update_method(): void
    {
        $this->assertTrue(method_exists($this->service, 'updateProfileWithValidation'));
    }

    /** @test */
    public function get_settings_reads_stdclass_rows_from_user_model(): void
    {
        $this->userModel->shouldReceive('getUserSettings')->once()->with(10)->andReturn([
            (object)['setting_key' => 'theme', 'setting_value' => 'dark'],
            (object)['setting_key' => 'notify', 'setting_value' => '1'],
            (object)['setting_key' => '', 'setting_value' => 'ignored'],
        ]);

        $settings = $this->service->getSettings(10);

        $this->assertSame('dark', $settings['theme']);
        $this->assertTrue($settings['notify']);
        $this->assertArrayNotHasKey('', $settings);
    }
}
