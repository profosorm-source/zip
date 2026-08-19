<?php

namespace Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use App\Services\DataExportService;
use Mockery as m;

class DataExportServiceTest extends TestCase
{
    /** @var \App\Contracts\LoggerInterface&\Mockery\MockInterface */
    private \App\Contracts\LoggerInterface $logger;
    /** @var \App\Models\DataExport&\Mockery\MockInterface */
    private \App\Models\DataExport $exportModel;
    /** @var \App\Models\User&\Mockery\MockInterface */
    private \App\Models\User $userModel;
    /** @var \App\Models\Transaction&\Mockery\MockInterface */
    private \App\Models\Transaction $transactionModel;
    /** @var \App\Models\Wallet&\Mockery\MockInterface */
    private \App\Models\Wallet $walletModel;
    /** @var \App\Models\KYCVerification&\Mockery\MockInterface */
    private \App\Models\KYCVerification $kycVerificationModel;
    /** @var \App\Models\UserSetting&\Mockery\MockInterface */
    private \App\Models\UserSetting $userSettingModel;
    /** @var \Core\Database&\Mockery\MockInterface */
    private \Core\Database $db;
    private DataExportService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->logger = m::mock('App\Contracts\LoggerInterface');
        $this->exportModel = m::mock('App\Models\DataExport');
        $this->userModel = m::mock('App\Models\User');
        $this->transactionModel = m::mock('App\Models\Transaction');
        $this->walletModel = m::mock('App\Models\Wallet');
        $this->kycVerificationModel = m::mock('App\Models\KYCVerification');
        $this->userSettingModel = m::mock('App\Models\UserSetting');

        $this->db = m::mock('Core\\Database');
        $this->db->shouldReceive('fetchAll')->andReturn([]);

        $this->logger->shouldIgnoreMissing();

        $this->service = new DataExportService(
            $this->logger,
            $this->exportModel,
            $this->userModel,
            $this->transactionModel,
            $this->walletModel,
            $this->kycVerificationModel,
            $this->userSettingModel,
            $this->db
        );
    }

    protected function tearDown(): void
    {
        m::close();
        parent::tearDown();
    }

    /** @test */
    public function service_can_be_instantiated(): void
    {
        $this->assertInstanceOf(DataExportService::class, $this->service);
    }

    /** @test */
    public function request_export_registers_request_correctly(): void
    {
        $userId = 12;
        $this->exportModel->shouldReceive('createExport')
            ->with($userId, 'json')
            ->once()
            ->andReturn(45);

        $result = $this->service->requestExport($userId, 'json');

        $this->assertEquals(45, $result);
    }

    /** @test */
    public function request_export_rejects_invalid_format(): void
    {
        $result = $this->service->requestExport(12, 'invalid_format');
        $this->assertNull($result);
    }

    /** @test */
    public function export_json_compiles_data_successfully(): void
    {
        $userId = 12;
        $userMock = (object)[
            'id' => $userId,
            'username' => 'alireza',
            'full_name' => 'علیرضا',
            'email' => 'alireza@example.com',
            'mobile' => '09123456789',
            'kyc_status' => 'verified',
            'created_at' => '2026-06-03 12:00:00'
        ];

        $this->userModel->shouldReceive('findById')->with($userId)->once()->andReturn($userMock);
        
        // Mock sub models
        $this->transactionModel->shouldReceive('getRecentByUserId')->with($userId, 100)->once()->andReturn([]);
        $this->walletModel->shouldReceive('findByUserId')->with($userId)->once()->andReturn(null);
        $this->kycVerificationModel->shouldReceive('findByUserId')->with($userId)->once()->andReturn(null);
        $this->userSettingModel->shouldReceive('getUserSettings')->with($userId)->once()->andReturn([]);

        $json = $this->service->exportJSON($userId);

        $this->assertNotNull($json);
        $this->assertStringContainsString('alireza', $json);
        $this->assertStringContainsString('علیرضا', $json);
    }

    /** @test */
    public function export_csv_compiles_csv_successfully(): void
    {
        $userId = 12;
        $userMock = (object)[
            'id' => $userId,
            'username' => 'alireza',
            'full_name' => 'علیرضا',
            'email' => 'alireza@example.com',
            'mobile' => '09123456789',
            'kyc_status' => 'verified',
            'created_at' => '2026-06-03 12:00:00'
        ];

        $this->userModel->shouldReceive('findById')->with($userId)->once()->andReturn($userMock);
        
        $this->transactionModel->shouldReceive('getRecentByUserId')->with($userId, 100)->once()->andReturn([]);
        $this->walletModel->shouldReceive('findByUserId')->with($userId)->once()->andReturn(null);

        $csv = $this->service->exportCSV($userId);

        $this->assertNotNull($csv);
        $this->assertStringContainsString('نام کامل,"علیرضا"', $csv);
        $this->assertStringContainsString('ایمیل,"alireza@example.com"', $csv);
    }
}
