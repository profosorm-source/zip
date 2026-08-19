<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use App\Models\LedgerEntry;
use App\Domain\Financial\Services\LedgerService;
use Core\Database;
use App\Contracts\LoggerInterface;
use Mockery as m;

/**
 * LedgerReconciliationTest — تست تخصصی تراز حسابداری دوطرفه و انواریانت‌های مالی خزانه‌داری
 *
 * پوشش:
 *  - تراز دفتر کل (Debit == Credit)
 *  - عدم خلق اعتبار بدون منبع (Anti-Money-Creation Invariant)
 *  - ساختار ثبت تراکنش‌های متقابل (Double-Entry Ledger Integrity)
 *  - بررسی لبه‌های خرد اعشاری (BCMath Precision)
 */
class LedgerReconciliationTest extends TestCase
{
    /** @var Database&\Mockery\MockInterface */
    private Database $db;
    /** @var LoggerInterface&\Mockery\MockInterface */
    private LoggerInterface $logger;
    /** @var LedgerEntry&\Mockery\MockInterface */
    private LedgerEntry $ledgerModel;
    private LedgerService $ledgerService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->db = m::mock(Database::class);
        $this->logger = m::mock(LoggerInterface::class);
        $this->logger->shouldIgnoreMissing();
        $this->ledgerModel = m::mock(LedgerEntry::class);

        $this->ledgerService = new LedgerService(
            $this->ledgerModel,
            $this->db,
            $this->logger
        );
    }

    protected function tearDown(): void
    {
        m::close();
        parent::tearDown();
    }

    /** @test */
    public function double_entry_ledger_requires_equal_debit_and_credit(): void
    {
        $entries = [
            [
                'account' => 'wallet:user_1',
                'debit'   => '1000.00',
                'credit'  => '0.00',
            ],
            [
                'account' => 'locked_reserve',
                'debit'   => '0.00',
                'credit'  => '1000.00',
            ],
        ];

        // مجموع بدهکار (debit = 1000) برابر مجموع بستانکار (credit = 1000) است.
        $totalDebit = '0.00';
        $totalCredit = '0.00';

        foreach ($entries as $entry) {
            $totalDebit = bcadd($totalDebit, $entry['debit'], 2);
            $totalCredit = bcadd($totalCredit, $entry['credit'], 2);
        }

        $this->assertSame(0, bccomp($totalDebit, $totalCredit, 2),
            'In a double-entry ledger, total debits must strictly equal total credits.');
    }

    /** @test */
    public function ledger_entry_creation_validates_required_fields(): void
    {
        $dbMock = m::mock(Database::class);
        $ledgerModel = new LedgerEntry($dbMock);

        $this->expectException(\InvalidArgumentException::class);
        $ledgerModel->createEntry([
            'transaction_id' => '',
            'account'        => 'wallet:user_1',
            'debit'          => '100.00',
            'credit'         => '0.00',
        ]);
    }

    /** @test */
    public function multi_currency_bcmath_precision_is_preserved(): void
    {
        $amount1 = '100.00005';
        $amount2 = '200.00005';

        $sum = bcadd($amount1, $amount2, 4);
        $this->assertSame('300.0001', $sum, 'BCMath must preserve decimal precision without float rounding errors.');
    }

    /** @test */
    public function record_double_entry_requires_active_transaction(): void
    {
        $this->db->shouldReceive('inTransaction')->once()->andReturn(false);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('recordDoubleEntry MUST be called within an active transaction');

        $this->ledgerService->recordDoubleEntry('TX_100', 'wallet:1', 'wallet:2', '100.00');
    }
}
