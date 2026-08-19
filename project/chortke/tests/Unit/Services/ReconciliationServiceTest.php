<?php

namespace Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use App\Services\ReconciliationService;
use App\Models\Transaction;
use App\Models\LedgerEntry;
use App\Models\Wallet;
use Core\Database;
use App\Contracts\LoggerInterface;
use App\Contracts\WalletServiceInterface;
use App\Services\Wallet\WalletService;
use App\Domain\Financial\Services\LedgerService;
use App\Services\AuditTrail;
use Mockery as m;

/**
 * @phpstan-type ReconciliationMocks array{
 *   transactionModel?: Transaction&\Mockery\MockInterface,
 *   ledgerModel?: LedgerEntry&\Mockery\MockInterface,
 *   walletModel?: Wallet&\Mockery\MockInterface,
 *   db?: Database&\Mockery\MockInterface,
 *   logger?: LoggerInterface&\Mockery\MockInterface,
 *   walletService?: WalletService&\Mockery\MockInterface,
 *   ledgerService?: LedgerService&\Mockery\MockInterface,
 *   auditTrail?: AuditTrail&\Mockery\MockInterface
 * }
 * @phpstan-type ReconciliationDeps array{
 *   service: ReconciliationService,
 *   transactionModel: Transaction&\Mockery\MockInterface,
 *   ledgerModel: LedgerEntry&\Mockery\MockInterface,
 *   walletModel: Wallet&\Mockery\MockInterface,
 *   db: Database&\Mockery\MockInterface,
 *   logger: LoggerInterface&\Mockery\MockInterface,
 *   walletService: WalletService&\Mockery\MockInterface,
 *   ledgerService: LedgerService&\Mockery\MockInterface,
 *   auditTrail: AuditTrail&\Mockery\MockInterface
 * }
 */
class ReconciliationServiceTest extends TestCase
{
    protected function tearDown(): void
    {
        m::close();
        parent::tearDown();
    }

    /** @param ReconciliationMocks $mocks
     *  @return ReconciliationDeps */
    private function createService(array $mocks = []): array
    {
        // متد reconcilePayment نیازمند یک webhook secret پیکربندی‌شده است (اعتبارسنجی HMAC).
        // در حالت internal، امضا با همین secret به‌صورت خودکار ساخته می‌شود.
        if (function_exists('config_set')) {
            config_set('webhook.secret', 'test_webhook_secret_0123456789');
        }

        $transactionModel = $mocks['transactionModel'] ?? m::mock(Transaction::class);
        $ledgerModel      = $mocks['ledgerModel']      ?? m::mock(LedgerEntry::class);
        $walletModel      = $mocks['walletModel']      ?? m::mock(Wallet::class);
        $db               = $mocks['db']               ?? m::mock(Database::class);
        $logger           = $mocks['logger']           ?? m::mock(LoggerInterface::class);
        $walletService    = $mocks['walletService']    ?? m::mock(WalletService::class);
        $ledgerService    = $mocks['ledgerService']    ?? m::mock(LedgerService::class);
        $auditTrail       = $mocks['auditTrail']       ?? m::mock(AuditTrail::class);

        $logger->shouldIgnoreMissing();
        $auditTrail->shouldIgnoreMissing();
        $db->shouldReceive('inTransaction')->andReturn(true)->byDefault();
        $db->shouldReceive('rollBack')->byDefault();
        $db->shouldReceive('commit')->byDefault();

        // ترتیب صحیح سازنده‌ی واقعی:
        // (db, logger, transactionModel, ledgerModel, walletModel, walletService, ledgerService, auditTrail, outbox?)
        $service = new ReconciliationService(
            $db,
            $logger,
            $transactionModel,
            $ledgerModel,
            $walletModel,
            $walletService,
            $ledgerService,
            $auditTrail,
            null
        );

        return [
            'service'          => $service,
            'transactionModel' => $transactionModel,
            'ledgerModel'      => $ledgerModel,
            'walletModel'      => $walletModel,
            'db'               => $db,
            'logger'           => $logger,
            'walletService'    => $walletService,
            'ledgerService'    => $ledgerService,
            'auditTrail'       => $auditTrail,
        ];
    }

    /** @test */
    public function test_reconcile_payment_returns_success_if_already_completed(): void
    {
        $deps = $this->createService();

        $deps['db']->shouldReceive('beginTransaction')->once();
        
        $transactionRow = (object)[
            'id' => 123,
            'user_id' => 456,
            'status' => 'completed', // Already completed
            'amount' => '10000',
            'currency' => 'irt',
            'type' => 'payment',
            'metadata' => null
        ];

        // Database::fetch is the typed stdClass boundary for transaction lock,
        // wallet lock and transaction re-fetch.
        $deps['db']->shouldReceive('fetch')
            ->andReturn($transactionRow, (object)['id' => 789], $transactionRow);

        $deps['db']->shouldReceive('rollBack')->once();

        $result = $deps['service']->reconcilePayment([
            'transaction_id' => 'tx123',
            'status' => 'success',
            'amount' => '10000',
            'currency' => 'irt'
        ], true);

        $this->assertTrue($result['success']);
        $this->assertEquals('این تراکنش قبلاً پردازش و نهایی شده بود', $result['message']);
    }

    /** @test */
    public function test_process_successful_payment_stops_if_already_in_ledger(): void
    {
        $deps = $this->createService();

        $deps['db']->shouldReceive('beginTransaction')->once();

        $transactionRow = (object)[
            'id' => 123,
            'transaction_id' => 'tx123',
            'user_id' => 456,
            'status' => 'pending',
            'amount' => '10000',
            'currency' => 'irt',
            'type' => 'payment',
            'metadata' => null
        ];

        // Typed fetches: transaction lock, wallet lock, transaction re-fetch,
        // then the ledger aggregate used by consistency verification.
        $deps['db']->shouldReceive('fetch')
            ->andReturn(
                $transactionRow,
                (object)['id' => 789],
                $transactionRow,
                (object)['total_debit' => '10000.0000', 'total_credit' => '0.0000']
            );

        // Ledger check returns pre-existing ledger entry (already processed double entry)
        $deps['ledgerModel']->shouldReceive('getByTransactionId')
            ->with('tx123')
            ->once()
            ->andReturn(['id' => 999]);

        // Mock findByUserId for consistency check
        $walletRow = (object)['balance_irt' => '10000.0000'];
        $deps['walletModel']->shouldReceive('findByUserId')
            ->andReturn($walletRow);

        // Expect commit since it is already processed and consistency is verified
        $deps['db']->shouldReceive('commit')->once();

        $result = $deps['service']->reconcilePayment([
            'transaction_id' => 'tx123',
            'status' => 'success',
            'amount' => '10000',
            'currency' => 'irt'
        ], true);

        $this->assertTrue($result['success']);
        $this->assertEquals('تطبیق تراکنش با موفقیت انجام شد', $result['message']);
    }

    /** @test */
    public function test_reconciliation_rolls_back_and_fails_on_amount_mismatch(): void
    {
        $deps = $this->createService();

        $deps['db']->shouldReceive('beginTransaction')->once();

        $transactionRow = (object)[
            'id' => 123,
            'transaction_id' => 'tx123',
            'user_id' => 456,
            'status' => 'pending',
            'amount' => '10000.0000',
            'currency' => 'irt',
            'type' => 'payment',
            'metadata' => null
        ];

        $deps['db']->shouldReceive('fetch')
            ->andReturn($transactionRow, (object)['id' => 789], $transactionRow);

        $deps['ledgerModel']->shouldReceive('getByTransactionId')->andReturn([]);

        // Expected rollback
        $deps['db']->shouldReceive('rollBack')->once();

        $result = $deps['service']->reconcilePayment([
            'transaction_id' => 'tx123',
            'status' => 'success',
            'amount' => '5000', // Mismatch!
            'currency' => 'irt'
        ], true);

        $this->assertFalse($result['success']);
        $this->assertEquals('مبلغ تراکنش با مبلغ پرداخت شده مطابقت ندارد', $result['message']);
    }

    /** @test */
    public function test_reconciliation_detects_financial_consistency_drift(): void
    {
        $deps = $this->createService();

        $deps['db']->shouldReceive('beginTransaction')->once();

        $transactionRow = (object)[
            'id' => 123,
            'transaction_id' => 'tx123',
            'user_id' => 456,
            'status' => 'pending',
            'amount' => '10000.0000',
            'currency' => 'irt',
            'type' => 'payment',
            'metadata' => null
        ];

        $deps['db']->shouldReceive('fetch')
            ->andReturn(
                $transactionRow,
                (object)['id' => 789],
                $transactionRow,
                (object)['total_debit' => '10000.0000', 'total_credit' => '0.0000']
            );

        $deps['ledgerModel']->shouldReceive('getByTransactionId')->andReturn([]);

        // Transaction status update: sets to completed
        $deps['db']->shouldReceive('execute')
            ->once()
            ->andReturn(1);

        // Wallet deposit service mock
        $deps['walletService']->shouldReceive('depositInTransaction')
            ->once()
            ->andReturn(['success' => true]);

        // Consistency check
        $walletRow = (object)['balance_irt' => '50000.0000'];
        $deps['walletModel']->shouldReceive('findByUserId')
            ->andReturn($walletRow);

        // Expect Exception and Transaction Rollback due to financial drift
        $deps['db']->shouldReceive('rollBack')->once();

        $result = $deps['service']->reconcilePayment([
            'transaction_id' => 'tx123',
            'status' => 'success',
            'amount' => '10000',
            'currency' => 'irt'
        ], true);

        $this->assertFalse($result['success']);
        $this->assertEquals('خطای سیستمی در فرآیند تطبیق تراکنش رخ داد', $result['message']);
    }
}
