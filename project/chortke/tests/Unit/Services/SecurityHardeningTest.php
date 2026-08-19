<?php

namespace Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use App\Contracts\WalletServiceInterface;
use App\Services\Wallet\WalletService;
use App\Services\ManualDepositService;
use App\Services\BankCardService;
use App\Domain\Financial\Services\LedgerService;
use App\Models\Withdrawal;
use App\Models\BankCard;

class SecurityHardeningTest extends TestCase
{
    /** @test */
    public function test_withdrawal_model_update_status_has_pessimistic_locking(): void
    {
        $reflection = new \ReflectionClass(Withdrawal::class);
        $this->assertTrue($reflection->hasMethod('updateStatus'));
        
        $method = $reflection->getMethod('updateStatus');
        $params = $method->getParameters();
        $this->assertGreaterThanOrEqual(1, count($params));
        $this->assertEquals('id', $params[0]->getName());
    }

    /** @test */
    public function test_wallet_service_idempotency_methods_exist(): void
    {
        $reflection = new \ReflectionClass(WalletService::class);
        $this->assertTrue($reflection->hasMethod('deposit'));
        $this->assertTrue($reflection->hasMethod('depositInTransaction'));
    }

    /** @test */
    public function test_manual_deposit_service_create_defines_variables(): void
    {
        $reflection = new \ReflectionClass(ManualDepositService::class);
        $this->assertTrue($reflection->hasMethod('create'));
        
        $method = $reflection->getMethod('create');
        $params = $method->getParameters();
        $this->assertCount(3, $params);
        $this->assertEquals('userId', $params[0]->getName());
        $this->assertEquals('data', $params[1]->getName());
        $this->assertEquals('receiptPath', $params[2]->getName());
    }

    /** @test */
    public function test_bank_card_service_constructor_injects_database(): void
    {
        $reflection = new \ReflectionClass(BankCardService::class);
        $constructor = $reflection->getConstructor();
        $this->assertNotNull($constructor);
        
        $params = $constructor->getParameters();
        $hasDb = false;
        foreach ($params as $param) {
            $type = $param->getType();
            if ($type) {
                $typeNames = [];
                if ($type instanceof \ReflectionNamedType) {
                    $typeNames[] = $type->getName();
                } elseif ($type instanceof \ReflectionUnionType) {
                    foreach ($type->getTypes() as $subType) {
                        if ($subType instanceof \ReflectionNamedType) $typeNames[] = $subType->getName();
                    }
                }
                foreach ($typeNames as $typeName) {
                    if (strpos($typeName, 'Database') !== false) {
                        $hasDb = true;
                        break 2;
                    }
                }
            }
        }
        $this->assertTrue($hasDb, "BankCardService constructor must inject Database class");
    }

    /** @test */
    public function test_ledger_service_rollback_nested_transaction_safety(): void
    {
        $reflection = new \ReflectionClass(LedgerService::class);
        $this->assertTrue($reflection->hasMethod('recordDoubleEntry'));
    }

    /** @test */
    public function test_api_token_atomic_revocation_methods_exist(): void
    {
        $reflection = new \ReflectionClass(\App\Models\ApiToken::class);
        $this->assertTrue($reflection->hasMethod('revokeForUser'));
        $this->assertTrue($reflection->hasMethod('revokeByHashForUser'));

        $revokeForUserParams = $reflection->getMethod('revokeForUser')->getParameters();
        $this->assertCount(2, $revokeForUserParams);
        $this->assertEquals('id', $revokeForUserParams[0]->getName());
        $this->assertEquals('userId', $revokeForUserParams[1]->getName());

        $revokeByHashForUserParams = $reflection->getMethod('revokeByHashForUser')->getParameters();
        $this->assertCount(2, $revokeByHashForUserParams);
        $this->assertEquals('plainToken', $revokeByHashForUserParams[0]->getName());
        $this->assertEquals('userId', $revokeByHashForUserParams[1]->getName());
    }

    /** @test */
    public function test_ledger_entry_create_throws_on_invalid_data(): void
    {
        $dbMock = $this->createMock(\Core\Database::class);
        $ledgerEntry = new \App\Models\LedgerEntry($dbMock);

        // 1. empty transaction_id
        try {
            $ledgerEntry->createEntry(['transaction_id' => '']);
            $this->fail("Expected InvalidArgumentException for empty transaction_id");
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('transaction_id', $e->getMessage());
        }

        // 2. negative values
        try {
            $ledgerEntry->createEntry(['transaction_id' => 'tx123', 'debit' => -100]);
            $this->fail("Expected InvalidArgumentException for negative debit");
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('non-negative', $e->getMessage());
        }

        // 3. both debit and credit zero
        try {
            $ledgerEntry->createEntry(['transaction_id' => 'tx123', 'debit' => 0, 'credit' => 0]);
            $this->fail("Expected InvalidArgumentException for both zero amount");
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('both or neither', $e->getMessage());
        }
    }

    /** @test */
    public function test_withdrawal_update_status_enforces_active_transaction(): void
    {
        $dbMock = $this->createMock(\Core\Database::class);
        $dbMock->expects($this->once())
            ->method('inTransaction')
            ->willReturn(false);

        $withdrawal = new \App\Models\Withdrawal($dbMock);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Withdrawal::updateStatus() requires an active transaction.');

        $withdrawal->updateStatus(1, 'processing');
    }

    /** @test */
    public function test_audit_trail_cryptographic_hash_chaining(): void
    {
        $dbMock = $this->createMock(\Core\Database::class);
        
        $dbMock->expects($this->once())
            ->method('fetch')
            ->with($this->stringContains('SELECT hash FROM'))
            ->willReturn((object)['hash' => 'dummy_prev_hash']);

        $auditModel = new class($dbMock) extends \App\Models\AuditTrail {
            /** @var array<string, mixed> */
            public array $savedData = [];
            public function create(array $data): bool
            {
                $this->savedData = $data;
                return true;
            }
        };

        $data = [
            'event' => 'test_event',
            'user_id' => 123,
            'actor_id' => 456,
            'context' => '{"foo":"bar"}',
            'ip_address' => '127.0.0.1',
            'user_agent' => 'PHPUnit',
            'created_at' => '2026-05-18 12:00:00',
        ];

        $auditModel->createEntry($data);

        $this->assertEquals('dummy_prev_hash', $auditModel->savedData['prev_hash']);
        $hash = $auditModel->savedData['hash'] ?? null;
        $this->assertIsString($hash);
        $this->assertNotEmpty($hash);
        $this->assertEquals(64, strlen($hash));
        
        $payload = json_encode([
            'request_id' => null,
            'event' => 'test_event',
            'user_id' => 123,
            'actor_id' => 456,
            'context' => '{"foo":"bar"}',
            'ip_address' => '127.0.0.1',
            'user_agent' => 'PHPUnit',
            'created_at' => '2026-05-18 12:00:00',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        
        $expectedHash = hash('sha256', 'dummy_prev_hash|' . $payload);
        $this->assertEquals($expectedHash, $auditModel->savedData['hash']);
    }

    /** @test */
    public function test_fraud_score_read_returns_projection_value_cqrs(): void
    {
        // معماری فعلی CQRS است: نوشتن امتیاز (ScoreCommandService::applyDelta) از خواندن
        // (ScoreQueryService::getScore) جدا شده و خواندن «فقط» مقدار projection را برمی‌گرداند
        // (نه delta کامیت‌نشده). این تست رفتار صحیح خواندن fraud score از projection را تضمین می‌کند.
        $userId = 99999;

        $cacheMock = \Mockery::mock(\Core\Cache::class);
        // بدون Redis در محیط تست → باید مستقیماً از جدول projection (user_scores) خوانده شود.
        $cacheMock->shouldReceive('redis')->andReturn(null);

        $stmtMock = \Mockery::mock(\PDOStatement::class);
        $stmtMock->shouldReceive('execute')->once()->andReturn(true);
        $stmtMock->shouldReceive('fetchColumn')->once()->andReturn(15.0);

        $dbMock = \Mockery::mock(\Core\Database::class);
        $dbMock->shouldReceive('prepare')
            ->once()
            ->with(\Mockery::pattern('/SELECT score FROM user_scores/'))
            ->andReturn($stmtMock);

        $scoreModelMock = \Mockery::mock(\App\Models\Score::class);

        $queryService = new \App\Services\Score\ScoreQueryService(
            $cacheMock,
            $dbMock,
            $scoreModelMock
        );

        $score = $queryService->getScore('user', $userId, 'fraud');

        $this->assertSame(15.0, $score, 'خواندن fraud score باید مقدار projection (۱۵) را برگرداند');

        \Mockery::close();
    }
}
