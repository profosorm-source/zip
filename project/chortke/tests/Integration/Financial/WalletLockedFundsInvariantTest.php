<?php

declare(strict_types=1);

namespace Tests\Integration\Financial;

use Tests\Support\ResetsConfiguredRedis;
use App\Contracts\WalletServiceInterface;
use Core\Container;
use Core\Database;
use PHPUnit\Framework\TestCase;

/**
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 *
 * Real MariaDB regression tests for the two distinct locked-funds semantics:
 * - spendLockedFunds: locked -> settlement, never credits owner balance;
 * - releaseLockedFunds: locked -> owner balance (refund only).
 *
 * Every test is wrapped in an outer transaction and rolled back, so no
 * financial fixture rows survive test execution.
 */
final class WalletLockedFundsInvariantTest extends TestCase
{
    use ResetsConfiguredRedis;
    private Database $db;
    private WalletServiceInterface $wallet;
    private int $buyerId = 1;
    private int $outputBufferLevel;

    protected function setUp(): void
    {
        parent::setUp();ini_set('error_log', sys_get_temp_dir() . '/chortke-financial-integration.log');config_set('app.key', 'testing-app-key-32-characters-long!!');
        // Application logging is intentionally verbose in this legacy suite;
        // capture it so PHPUnit's strict no-output rule remains meaningful.
        $this->outputBufferLevel = ob_get_level();
        ob_start();

        try {
            $container = Container::getInstance();
            $this->db = $container->make(Database::class);
            $this->wallet = $container->make(WalletServiceInterface::class);
            $this->db->getPdo();$this->db->query("DELETE FROM score_events WHERE entity_type='user' AND domain='fraud' AND entity_id IN (1,2,3,4)");$this->db->query("UPDATE users SET fraud_score=0,is_blacklisted=0,status='active' WHERE id IN (1,2,3,4)");$this->db->query("UPDATE user_scores SET score=0 WHERE domain='fraud' AND user_id IN (1,2,3,4)");$this->resetConfiguredRedis([1,2,3,4]);
        } catch (\Throwable $exception) {
            $this->fail('Financial integration database is unavailable: ' . $exception->getMessage());
        }

        $this->db->beginTransaction();
        $this->db->query(
            'INSERT INTO wallets (user_id, balance_irt, locked_irt, is_frozen) VALUES (?, ?, 0, 0) ON DUPLICATE KEY UPDATE balance_irt = VALUES(balance_irt), locked_irt = 0, is_frozen = 0',
            [$this->buyerId, '50000000.0000']
        );
    }

    protected function tearDown(): void
    {
        if (isset($this->db) && $this->db->inTransaction()) {
            $this->db->rollback();
        }

        while (isset($this->outputBufferLevel) && ob_get_level() > $this->outputBufferLevel) {
            ob_end_clean();
        }

        parent::tearDown();
    }

    /** @test */
    public function spending_locked_funds_never_refunds_the_owner_and_is_idempotent(): void
    {
        $amount = '20000.0000';
        $holdKey = 'test-hold-' . bin2hex(random_bytes(10));
        $spendKey = 'test-spend-' . bin2hex(random_bytes(10));

        $hold = $this->wallet->withdraw($this->buyerId, $amount, 'irt', [
            'type' => 'test_escrow_hold',
            'ref_id' => 'wallet-locked-invariant',
            'idempotency_key' => $holdKey,
        ]);
        $this->assertTrue((bool) ($hold['success'] ?? false));

        $afterHold = $this->walletRow();
        $this->assertSame('49980000.00000000', (string) $afterHold->balance_irt);
        $this->assertSame('20000.00000000', (string) $afterHold->locked_irt);

        $spend = $this->wallet->spendLockedFunds($this->buyerId, $amount, 'irt', [
            'type' => 'test_escrow_spend',
            'ref_id' => 'wallet-locked-invariant',
            'ref_type' => 'test',
            'description' => 'integration-test locked spend',
            'idempotency_key' => $spendKey,
        ]);
        $this->assertTrue((bool) ($spend['success'] ?? false));
        $this->assertFalse((bool) ($spend['idempotent_replay'] ?? true));

        $afterSpend = $this->walletRow();
        // This is the critical anti-money-creation invariant: balance remains
        // unchanged; only reserved funds leave the buyer's wallet.
        $this->assertSame('49980000.00000000', (string) $afterSpend->balance_irt);
        $this->assertSame('0.00000000', (string) $afterSpend->locked_irt);

        $ledger = $this->db->fetchAll(
            'SELECT account, debit, credit FROM ledger_entries WHERE transaction_id = ? ORDER BY id',
            [str_value($spend['transaction_id'] ?? '')]
        );
        $this->assertCount(2, $ledger);
        $this->assertSame('locked_reserve', (string) $ledger[0]->account);
        $this->assertSame('escrow_payout', (string) $ledger[1]->account);
        $this->assertSame('20000.00000000', (string) $ledger[0]->debit);
        $this->assertSame('20000.00000000', (string) $ledger[1]->credit);

        $replay = $this->wallet->spendLockedFunds($this->buyerId, $amount, 'irt', [
            'type' => 'test_escrow_spend',
            'ref_id' => 'wallet-locked-invariant',
            'ref_type' => 'test',
            'description' => 'integration-test locked spend',
            'idempotency_key' => $spendKey,
        ]);
        $this->assertTrue((bool) ($replay['success'] ?? false));

        $afterReplay = $this->walletRow();
        $this->assertSame('49980000.00000000', (string) $afterReplay->balance_irt);
        $this->assertSame('0.00000000', (string) $afterReplay->locked_irt);
        $spendRows = (int) $this->db->fetchColumn(
            "SELECT COUNT(*) FROM transactions WHERE user_id = ? AND type = 'test_escrow_spend'",
            [$this->buyerId]
        );
        $this->assertSame(1, $spendRows, 'A replay must not create a second spend transaction.');
    }

    private function walletRow(): \stdClass
    {
        $row = $this->db->fetch(
            'SELECT balance_irt, locked_irt FROM wallets WHERE user_id = ? FOR UPDATE',
            [$this->buyerId]
        );
        $this->assertInstanceOf(\stdClass::class, $row);
        return $row;
    }
}
