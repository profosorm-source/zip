<?php

declare(strict_types=1);

namespace Tests\Integration\Financial;

use Tests\Support\ResetsConfiguredRedis;
use App\Contracts\WalletServiceInterface;
use App\Services\EscrowService;
use Core\Container;
use Core\Database;
use PHPUnit\Framework\TestCase;

/**
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 *
 * Financial invariant tests for partial escrow settlement.
 * Uses real MariaDB/Redis services and rolls all fixtures back after each test.
 */
final class EscrowPartialSettlementInvariantTest extends TestCase
{
    use ResetsConfiguredRedis;
    private Database $db;
    private WalletServiceInterface $wallet;
    private EscrowService $escrowService;
    private int $buyerId = 1;
    private int $sellerId = 2;
    private int $outputBufferLevel;

    protected function setUp(): void
    {
        parent::setUp();ini_set('error_log', sys_get_temp_dir() . '/chortke-financial-integration.log');config_set('app.key', 'testing-app-key-32-characters-long!!');
        $this->outputBufferLevel = ob_get_level();
        ob_start();

        try {
            $container = Container::getInstance();
            $this->db = $container->make(Database::class);
            $this->wallet = $container->make(WalletServiceInterface::class);
            $this->escrowService = $container->make(EscrowService::class);
            $this->db->getPdo();$this->db->query("DELETE FROM score_events WHERE entity_type='user' AND domain='fraud' AND entity_id IN (1,2,3,4)");$this->db->query("UPDATE users SET fraud_score=0,is_blacklisted=0,status='active' WHERE id IN (1,2,3,4)");$this->db->query("UPDATE user_scores SET score=0 WHERE domain='fraud' AND user_id IN (1,2,3,4)");$this->resetConfiguredRedis([1,2,3,4]);
        } catch (\Throwable $exception) {
            $this->fail('Financial integration database is unavailable: ' . $exception->getMessage());
        }

        $this->db->beginTransaction();
        $this->db->query('INSERT INTO wallets (user_id, balance_irt, locked_irt, is_frozen) VALUES (?, ?, 0, 0) ON DUPLICATE KEY UPDATE balance_irt = VALUES(balance_irt), locked_irt = 0, is_frozen = 0', [$this->buyerId, '50000000.0000']);
        $this->db->query('INSERT INTO wallets (user_id, balance_irt, locked_irt, is_frozen) VALUES (?, ?, 0, 0) ON DUPLICATE KEY UPDATE balance_irt = VALUES(balance_irt), locked_irt = 0, is_frozen = 0', [$this->sellerId, '0.0000']);
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
    public function partial_payout_spends_locked_funds_credits_seller_once_and_preserves_money(): void
    {
        $escrowId = $this->createFundedEscrow('20000.0000');
        $firstKey = 'test-partial-settlement-' . bin2hex(random_bytes(10));

        $first = $this->escrowService->partialRelease(
            $escrowId,
            $this->sellerId,
            '8000.0000',
            'integration partial payout',
            $firstKey
        );
        $this->assertTrue((bool) ($first['ok'] ?? false));

        $this->assertWallet($this->buyerId, '49980000.00000000', '12000.00000000');
        $this->assertWallet($this->sellerId, '8000.00000000', '0.00000000');
        $this->assertEscrow($escrowId, 'partial', '12000.00000000', '8000.00000000');

        // A replay with the same key may return a cached response, but it must
        // never create a second payout or consume another locked amount.
        $replay = $this->escrowService->partialRelease(
            $escrowId,
            $this->sellerId,
            '8000.0000',
            'integration partial payout',
            $firstKey
        );
        $this->assertTrue((bool) ($replay['ok'] ?? false));
        $this->assertWallet($this->buyerId, '49980000.00000000', '12000.00000000');
        $this->assertWallet($this->sellerId, '8000.00000000', '0.00000000');
        $this->assertEscrow($escrowId, 'partial', '12000.00000000', '8000.00000000');

        $final = $this->escrowService->partialRelease(
            $escrowId,
            $this->sellerId,
            '12000.0000',
            'integration final payout',
            'test-final-settlement-' . bin2hex(random_bytes(10))
        );
        $this->assertTrue((bool) ($final['ok'] ?? false));
        $this->assertWallet($this->buyerId, '49980000.00000000', '0.00000000');
        $this->assertWallet($this->sellerId, '20000.00000000', '0.00000000');
        $this->assertEscrow($escrowId, 'released', '0.00000000', '20000.00000000');

        $this->assertSame(
            0,
            (int) $this->db->fetchColumn(
                "SELECT COUNT(*) FROM (
                    SELECT transaction_id, currency, SUM(debit) AS debit_total, SUM(credit) AS credit_total
                    FROM ledger_entries
                    GROUP BY transaction_id, currency
                    HAVING ABS(SUM(debit) - SUM(credit)) > 0.00000001
                ) AS imbalanced"
            ),
            'Every ledger transaction created by settlement must balance.'
        );
    }

    private function createFundedEscrow(string $amount): int
    {
        $orderId = 'test-partial-' . bin2hex(random_bytes(8));
        $hold = $this->wallet->withdraw($this->buyerId, $amount, 'irt', [
            'type' => 'test_escrow_hold',
            'ref_id' => $orderId,
            'idempotency_key' => 'test-hold-' . bin2hex(random_bytes(10)),
        ]);
        $this->assertTrue((bool) ($hold['success'] ?? false));

        $this->db->query(
            "INSERT INTO escrow_transactions
             (order_id, order_type, buyer_id, seller_id, amount, currency, status, held_at, expires_at)
             VALUES (?, 'test_partial_settlement', ?, ?, ?, 'irt', 'in_escrow', NOW(), DATE_ADD(NOW(), INTERVAL 1 DAY))",
            [$orderId, $this->buyerId, $this->sellerId, $amount]
        );

        return (int) $this->db->lastInsertId();
    }

    private function assertWallet(int $userId, string $expectedBalance, string $expectedLocked): void
    {
        $wallet = $this->db->fetch('SELECT balance_irt, locked_irt FROM wallets WHERE user_id = ? FOR UPDATE', [$userId]);
        $this->assertNotNull($wallet);
        $this->assertSame($expectedBalance, (string) $wallet->balance_irt);
        $this->assertSame($expectedLocked, (string) $wallet->locked_irt);
    }

    private function assertEscrow(int $escrowId, string $expectedStatus, string $expectedAmount, string $expectedReleased): void
    {
        $escrow = $this->db->fetch('SELECT status, amount, partial_released FROM escrow_transactions WHERE id = ? FOR UPDATE', [$escrowId]);
        $this->assertNotNull($escrow);
        $this->assertSame($expectedStatus, (string) $escrow->status);
        $this->assertSame($expectedAmount, (string) $escrow->amount);
        $this->assertSame($expectedReleased, (string) $escrow->partial_released);
    }
}
