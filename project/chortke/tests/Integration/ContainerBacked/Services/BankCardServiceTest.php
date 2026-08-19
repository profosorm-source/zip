<?php

namespace Tests\Integration\ContainerBacked\Services;

use PHPUnit\Framework\TestCase;
use App\Services\BankCardService;
use Mockery as m;

/**
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class BankCardServiceTest extends TestCase
{
    /** @var \Core\Database&\Mockery\MockInterface */
    private \Core\Database $db;
    /** @var \App\Contracts\LoggerInterface&\Mockery\MockInterface */
    private \App\Contracts\LoggerInterface $logger;
    /** @var \App\Models\BankCard&\Mockery\MockInterface */
    private \App\Models\BankCard $model;
    /** @var \App\Models\User&\Mockery\MockInterface */
    private \App\Models\User $userModel;
    /** @var \Core\Encryption&\Mockery\MockInterface */
    private \Core\Encryption $encryption;
    /** @var \App\Contracts\ValidatorFactoryInterface&\Mockery\MockInterface */
    private \App\Contracts\ValidatorFactoryInterface $validatorFactory;
    /** @var \App\Services\Shared\IdempotencyService&\Mockery\MockInterface */
    private \App\Services\Shared\IdempotencyService $idempotencyService;
    private BankCardService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->db = m::mock(\Core\Database::class);
        $this->logger = m::mock(\App\Contracts\LoggerInterface::class);
        $this->model = m::mock(\App\Models\BankCard::class);
        $this->userModel = m::mock(\App\Models\User::class);
        $this->encryption = m::mock(\Core\Encryption::class);
        $this->validatorFactory = m::mock(\App\Contracts\ValidatorFactoryInterface::class);
        $this->idempotencyService = m::mock(\App\Services\Shared\IdempotencyService::class);

        $this->logger->shouldIgnoreMissing();
        $this->db->shouldReceive('inTransaction')->byDefault()->andReturn(false);
        $this->db->shouldReceive('beginTransaction')->byDefault();
        $this->db->shouldReceive('commit')->byDefault();
        $this->db->shouldReceive('rollBack')->byDefault();

        // Register Database mock inside Container
        \Core\Container::getInstance()->instance(\Core\Database::class, $this->db);

        $this->service = new BankCardService(
            $this->db,
            $this->logger,
            $this->model,
            $this->userModel,
            $this->encryption,
            $this->validatorFactory,
            $this->idempotencyService
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
        $this->assertInstanceOf(BankCardService::class, $this->service);
    }

    /** @test */
    public function create_bank_card_fails_if_limit_exceeded(): void
    {
        $userId = 1;
        $data = [
            'card_number' => '6037991234567890', // valid Luhn is not strictly checked if mock handles validation, but we can use real Luhn to be safe
            'card_holder' => 'علیرضا رضاپور',
            'iban' => ''
        ];

        // Validation success mock
        $validator = m::mock(\Core\Validator::class);
        $validator->shouldReceive('custom')->twice();
        $validator->shouldReceive('result')->once()->andReturn([
            'valid' => true,
            'errors' => []
        ]);
        $this->validatorFactory->shouldReceive('make')->once()->andReturn($validator);

        // User lookup mock
        $userMock = (object)[
            'id' => $userId,
            'full_name' => 'علیرضا رضاپور'
        ];
        $this->userModel->shouldReceive('find')->with($userId)->once()->andReturn($userMock);

        // Mock Idempotency
        $this->idempotencyService->shouldReceive('executeWithTransaction')
            ->once()
            ->andReturnUsing(function($scope, $actorId, $payload, $callback, $key = null) {
                return $callback();
            });

        // Set active cards to 4 (exceeded)
        $this->model->shouldReceive('countUserCards')->with($userId)->once()->andReturn(4);

        $result = $this->service->create($userId, $data);

        $this->assertFalse($result['success']);
        $this->assertEquals('حداکثر ۴ کارت بانکی مجاز است', $result['message']);
    }

    /** @test */
    public function create_bank_card_success(): void
    {
        $userId = 1;
        $data = [
            'card_number' => '6037991823157540', // valid Luhn
            'card_holder' => 'علیرضا رضاپور',
            'iban' => ''
        ];

        $validator = m::mock(\Core\Validator::class);
        $validator->shouldReceive('custom')->twice();
        $validator->shouldReceive('result')->once()->andReturn([
            'valid' => true,
            'errors' => []
        ]);
        $this->validatorFactory->shouldReceive('make')->once()->andReturn($validator);

        $userMock = (object)[
            'id' => $userId,
            'full_name' => 'علیرضا رضاپور'
        ];
        $this->userModel->shouldReceive('find')->with($userId)->once()->andReturn($userMock);

        $this->idempotencyService->shouldReceive('executeWithTransaction')
            ->once()
            ->andReturnUsing(function($scope, $actorId, $payload, $callback, $key = null) {
                return $callback();
            });

        // No cards yet
        $this->model->shouldReceive('countUserCards')->with($userId)->once()->andReturn(0);

        // Encryption mock
        $this->encryption->shouldReceive('encrypt')->andReturn('encrypted_value');

        // Check duplicated card
        $stmt = m::mock(\PDOStatement::class);
        $stmt->shouldReceive('execute')->once();
        $stmt->shouldReceive('fetch')->once()->andReturn(false); // not duplicate

        $this->db->shouldReceive('prepare')->once()->andReturn($stmt);

        // Create card mock
        $this->model->shouldReceive('createBankCard')->once()->andReturn((object)['id' => 456]);

        $result = $this->service->create($userId, $data);

        $this->assertTrue($result['success']);
        $this->assertEquals('کارت ثبت شد و در انتظار تأیید است', $result['message']);
        $this->assertEquals(456, $result['card_id']);
    }

    /** @test */
    public function admin_verify_approves_card_for_verified_user(): void
    {
        $adminId = 99;
        $cardId = 123;

        // DB Lock mock
        $stmt = m::mock(\PDOStatement::class);
        $stmt->shouldReceive('fetch')->once()->andReturn((object)[
            'id' => $cardId,
            'user_id' => 1,
            'status' => 'pending',
            'owner_name' => 'encrypted_name'
        ]);
        $this->db->shouldReceive('query')->once()->andReturn($stmt);

        // User is verified (KYC status verified)
        $userMock = (object)[
            'id' => 1,
            'kyc_status' => 'verified',
            'full_name' => 'علیرضا رضاپور'
        ];
        $this->userModel->shouldReceive('find')->with(1)->once()->andReturn($userMock);

        // Decryption of name mock
        $this->encryption->shouldReceive('decrypt')->with('encrypted_name')->once()->andReturn('علیرضا رضاپور');

        // Update status in DB
        $this->model->shouldReceive('updateStatus')
            ->with($cardId, 'verified', null, $adminId)
            ->once()
            ->andReturn(true);

        $result = $this->service->adminVerify($adminId, $cardId, true);

        $this->assertTrue($result['success']);
        $this->assertEquals('کارت تأیید شد', $result['message']);
    }

    /** @test */
    public function admin_verify_rejects_card_properly(): void
    {
        $adminId = 99;
        $cardId = 123;

        // DB Lock mock
        $stmt = m::mock(\PDOStatement::class);
        $stmt->shouldReceive('fetch')->once()->andReturn((object)[
            'id' => $cardId,
            'user_id' => 1,
            'status' => 'pending'
        ]);
        $this->db->shouldReceive('query')->once()->andReturn($stmt);

        // Update status in DB to rejected
        $this->model->shouldReceive('updateStatus')
            ->with($cardId, 'rejected', 'Incorrect holder name', $adminId)
            ->once()
            ->andReturn(true);

        $result = $this->service->adminVerify($adminId, $cardId, false, 'Incorrect holder name');

        $this->assertTrue($result['success']);
        $this->assertEquals('کارت رد شد', $result['message']);
    }
}
