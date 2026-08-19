<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Wallet;

use PHPUnit\Framework\TestCase;
use Mockery as m;
use App\Services\Wallet\WalletMutationService;
use App\Models\Wallet;
use App\Models\Transaction;
use App\Domain\Financial\Services\LedgerService;
use Core\Database;
use App\Services\Settings\AppSettings;
use App\Contracts\LoggerInterface;

/**
 * تست‌های رگرسیون H-01: ادعای اتمیک reverseTransaction.
 *
 * پیش‌تر تغییر وضعیت completed -> reversed فقط در انتهای کار انجام می‌شد،
 * پس دو فراخوان هم‌زمان/retry می‌توانستند کاربر را دوبار credit کنند.
 * حالا claimForReversal() یک compare-and-swap است و فراخوان بازنده نباید موجودی را تغییر دهد.
 *
 * تست‌های رگرسیون H-02: reverse عمومی نباید تراکنش تحت مدیریت escrow را برگرداند
 * (hold ممکن است قبلاً در payout/refund مصرف شده باشد؛ اعتباردهی مجدد invariant را می‌شکند).
 */
final class WalletMutationReverseTest extends TestCase
{
    protected function tearDown(): void
    {
        m::close();
        parent::tearDown();
    }

    /**
     * @param Database&\Mockery\MockInterface $db
     * @param Wallet&\Mockery\MockInterface $walletModel
     * @param Transaction&\Mockery\MockInterface $transactionModel
     * @param LedgerService&\Mockery\MockInterface $ledger
     */
    private function makeService(Database $db, Wallet $walletModel, Transaction $transactionModel, LedgerService $ledger): WalletMutationService
    {
        $appSettings = m::mock(AppSettings::class);
        $appSettings->shouldReceive('get')->with('wallet_supported_currencies')->andReturn(null);

        $logger = m::mock(LoggerInterface::class);
        $logger->shouldIgnoreMissing();

        return new WalletMutationService($db, $walletModel, $transactionModel, $ledger, $appSettings, $logger);
    }

    /** @test */
    public function reverse_is_rejected_and_does_not_credit_when_already_claimed(): void
    {
        $tx = (object)[
            'transaction_id' => 'TX1',
            'user_id'        => 5,
            'currency'       => 'irt',
            'amount'         => '1000',
            'type'           => 'withdraw',
            'status'         => 'completed',
        ];

        $transactionModel = m::mock(Transaction::class);
        $transactionModel->shouldReceive('isEscrowManaged')->byDefault()->andReturn(false);
        $transactionModel->shouldReceive('findByTransactionId')->with('TX1')->andReturn($tx);
        // فراخوان دوم/هم‌زمان: claim شکست می‌خورد
        $transactionModel->shouldReceive('claimForReversal')->once()->with('TX1', 5, 9)->andReturn(false);
        $transactionModel->shouldNotReceive('createTransaction');

        $walletModel = m::mock(Wallet::class);
        $walletModel->shouldNotReceive('updateBalance');

        $ledger = m::mock(LedgerService::class);
        $ledger->shouldNotReceive('recordDoubleEntry');

        $db = m::mock(Database::class);
        $db->shouldReceive('inTransaction')->andReturn(false, true);
        $db->shouldReceive('beginTransaction')->once();
        $db->shouldReceive('rollback')->once();
        $db->shouldNotReceive('commit');

        $service = $this->makeService($db, $walletModel, $transactionModel, $ledger);

        $this->assertFalse($service->reverseTransaction('TX1', 9, 'duplicate'));
    }

    /** @test */
    public function reverse_credits_once_and_commits_when_claim_succeeds(): void
    {
        $tx = (object)[
            'transaction_id' => 'TX2',
            'user_id'        => 5,
            'currency'       => 'irt',
            'amount'         => '1000',
            'type'           => 'withdraw',
            'status'         => 'completed',
        ];

        $transactionModel = m::mock(Transaction::class);
        $transactionModel->shouldReceive('isEscrowManaged')->byDefault()->andReturn(false);
        $transactionModel->shouldReceive('findByTransactionId')->with('TX2')->andReturn($tx);
        $transactionModel->shouldReceive('claimForReversal')->once()->with('TX2', 5, null)->andReturn(true);
        $transactionModel->shouldReceive('createTransaction')->once()->with(m::type('array'))->andReturn((object)['transaction_id' => 'REV2']);

        $walletModel = m::mock(Wallet::class);
        $walletModel->shouldReceive('getBalance')->with(5, 'irt')->andReturn('1000', '2000');
        // دقیقاً یک بار credit
        $walletModel->shouldReceive('updateBalance')->once()->with(5, m::type('string'), 'irt')->andReturn(true);

        $ledger = m::mock(LedgerService::class);
        $ledger->shouldReceive('recordDoubleEntry')->once()->andReturn(true);

        $db = m::mock(Database::class);
        $db->shouldReceive('inTransaction')->andReturn(false);
        $db->shouldReceive('beginTransaction')->once();
        $db->shouldReceive('commit')->once();
        $db->shouldNotReceive('rollback');

        $service = $this->makeService($db, $walletModel, $transactionModel, $ledger);

        $this->assertTrue($service->reverseTransaction('TX2', null, 'refund'));
    }

    /** @test */
    public function reverse_is_blocked_for_escrow_managed_transaction(): void
    {
        // H-02: تراکنش تحت مدیریت escrow نباید از مسیر reverse عمومی برگردانده شود.
        $tx = (object)[
            'transaction_id' => 'ESCROW-TX',
            'user_id'        => 5,
            'currency'       => 'irt',
            'amount'         => '1000',
            'type'           => 'withdraw',
            'status'         => 'completed',
            'ref_type'       => 'escrow',
        ];

        $transactionModel = m::mock(Transaction::class);
        $transactionModel->shouldReceive('findByTransactionId')->with('ESCROW-TX')->andReturn($tx);
        $transactionModel->shouldReceive('isEscrowManaged')->once()->with($tx)->andReturn(true);
        // گارد پیش از هر نوشتنی برمی‌گردد: نه claim، نه ثبت تراکنش
        $transactionModel->shouldNotReceive('claimForReversal');
        $transactionModel->shouldNotReceive('createTransaction');

        $walletModel = m::mock(Wallet::class);
        $walletModel->shouldNotReceive('updateBalance');

        $ledger = m::mock(LedgerService::class);
        $ledger->shouldNotReceive('recordDoubleEntry');

        $db = m::mock(Database::class);
        // گارد قبل از شروع تراکنش است؛ هیچ عملیات تراکنشی نباید رخ دهد.
        $db->shouldNotReceive('beginTransaction');
        $db->shouldNotReceive('commit');
        $db->shouldNotReceive('rollback');

        $service = $this->makeService($db, $walletModel, $transactionModel, $ledger);

        $this->assertFalse($service->reverseTransaction('ESCROW-TX', 9, 'attempted escrow reverse'));
    }

    /** @test */
    public function is_escrow_managed_detects_escrow_transactions(): void
    {
        $db = m::mock(Database::class);
        $db->shouldIgnoreMissing();
        $logger = m::mock(LoggerInterface::class);
        $logger->shouldIgnoreMissing();
        $model = new Transaction($db, $logger);

        // ref_type='escrow' مستقیماً escrow-managed است
        $this->assertTrue($model->isEscrowManaged((object)['ref_type' => 'escrow', 'metadata' => null]));

        // انواع metadata.type مربوط به hold و leg های تسویه/حل‌اختلاف
        $escrowTypes = [
            'custom_deal_escrow', 'social_task_escrow', 'influencer_escrow', 'vitrine_escrow',
            'escrow_hold', 'escrow_refund', 'escrow_dispute_payout', 'influencer_escrow_seller_spend',
        ];
        foreach ($escrowTypes as $t) {
            $this->assertTrue(
                $model->isEscrowManaged((object)['ref_type' => 'other', 'metadata' => json_encode(['type' => $t])]),
                "type={$t} باید escrow-managed تشخیص داده شود"
            );
        }

        // metadata به‌صورت آرایه نیز پشتیبانی می‌شود
        $this->assertTrue($model->isEscrowManaged((object)['ref_type' => null, 'metadata' => ['type' => 'vitrine_escrow']]));

        // موارد غیرescrow نباید مثبت شوند
        $this->assertFalse($model->isEscrowManaged((object)['ref_type' => 'gateway', 'metadata' => json_encode(['type' => 'deposit'])]));
        $this->assertFalse($model->isEscrowManaged((object)['ref_type' => 'dispute', 'metadata' => null]));
        $this->assertFalse($model->isEscrowManaged((object)['ref_type' => '', 'metadata' => 'not-json']));
    }
}
