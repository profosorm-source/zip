<?php

declare(strict_types=1);

namespace Tests\Integration\Financial;

use Tests\Support\ResetsConfiguredRedis;
use App\Contracts\WalletServiceInterface;
use App\Domain\Financial\Services\FinancialEscrowService;
use Core\Container;
use Core\Database;
use PHPUnit\Framework\TestCase;

/**
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 *
 * Generic user-to-user custom deal: hold, release and refund must preserve
 * available+locked money and never rely on the controller for wallet mutation.
 */
final class FinancialCustomDealEscrowInvariantTest extends TestCase
{
    use ResetsConfiguredRedis;
    private Database $db;
    private FinancialEscrowService $financialEscrow;
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
            $this->financialEscrow = $container->make(FinancialEscrowService::class);
            $this->db->getPdo();$this->db->query("DELETE FROM score_events WHERE entity_type='user' AND domain='fraud' AND entity_id IN (1,2,3,4)");$this->db->query("UPDATE users SET fraud_score=0,is_blacklisted=0,status='active' WHERE id IN (1,2,3,4)");$this->db->query("UPDATE user_scores SET score=0 WHERE domain='fraud' AND user_id IN (1,2,3,4)");$this->resetConfiguredRedis([1,2,3,4]);
        } catch (\Throwable $exception) {
            $this->fail('Financial integration database is unavailable: ' . $exception->getMessage());
        }
        $this->db->beginTransaction();
        $this->setWallet($this->buyerId, '50000000.0000', '0.0000');
        $this->setWallet($this->sellerId, '0.0000', '0.0000');
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
    public function generic_hold_and_release_preserve_money_and_settle_locked_funds(): void
    {
        $orderId = random_int(100000000, 999999999);
        $amount = '20000.0000';
        $hold = $this->financialEscrow->holdCustomDealFunds(
            $orderId, $this->buyerId, $this->sellerId, $amount, 'test-custom-hold-' . bin2hex(random_bytes(8))
        );
        $this->assertTrue((bool) ($hold['ok'] ?? false), (json_encode($hold) ?: ''));
        $escrowId = int_value($hold['escrow_id'] ?? 0);
        $this->assertGreaterThan(0, $escrowId);

        $this->assertWallet($this->buyerId, '49980000.00000000', '20000.00000000');
        $this->assertWallet($this->sellerId, '0.00000000', '0.00000000');
        $escrow = $this->db->fetch('SELECT buyer_id, seller_id, status, amount FROM escrow_transactions WHERE id = ?', [$escrowId]);
        $this->assertInstanceOf(\stdClass::class, $escrow);
        $this->assertSame($this->buyerId, (int) $escrow->buyer_id);
        $this->assertSame($this->sellerId, (int) $escrow->seller_id);
        $this->assertSame('in_escrow', (string) $escrow->status);

        $release = $this->financialEscrow->releaseCustomDealFunds(
            $escrowId, $this->buyerId, 'test-custom-release-' . bin2hex(random_bytes(8))
        );
        $this->assertTrue((bool) ($release['ok'] ?? false), (json_encode($release) ?: ''));
        $this->assertWallet($this->buyerId, '49980000.00000000', '0.00000000');
        $this->assertWallet($this->sellerId, '20000.00000000', '0.00000000');
        $this->assertSame('released', (string) $this->db->fetchColumn('SELECT status FROM escrow_transactions WHERE id = ?', [$escrowId]));
    }

    /** @test */
    public function generic_refund_unlocks_buyer_funds_without_creating_money(): void
    {
        $orderId = random_int(100000000, 999999999);
        $amount = '20000.0000';
        $hold = $this->financialEscrow->holdCustomDealFunds(
            $orderId, $this->buyerId, $this->sellerId, $amount, 'test-custom-hold-' . bin2hex(random_bytes(8))
        );
        $escrowId = int_value($hold['escrow_id'] ?? 0);
        $this->assertGreaterThan(0, $escrowId);

        $refund = $this->financialEscrow->refundEscrowToBuyer(
            $escrowId, $this->buyerId, 'integration cancel', 'test', 'test-custom-refund-' . bin2hex(random_bytes(8))
        );
        $this->assertTrue((bool) ($refund['ok'] ?? false), (json_encode($refund) ?: ''));
        $this->assertWallet($this->buyerId, '50000000.00000000', '0.00000000');
        $this->assertWallet($this->sellerId, '0.00000000', '0.00000000');
        $this->assertSame('refunded', (string) $this->db->fetchColumn('SELECT status FROM escrow_transactions WHERE id = ?', [$escrowId]));
    }

    /** @test */
    public function expired_pending_custom_deal_refunds_once_using_order_row_and_locked_hold(): void
    {
        $orderId = random_int(100000000, 999999999);
        $amount = '20000.0000';
        $hold = $this->financialEscrow->holdCustomDealFunds(
            $orderId, $this->buyerId, $this->sellerId, $amount, 'test-expired-custom-hold-' . bin2hex(random_bytes(8))
        );
        $escrowId = int_value($hold['escrow_id'] ?? 0);
        $this->assertGreaterThan(0, $escrowId);
        $this->assertWallet($this->buyerId, '49980000.00000000', '20000.00000000');

        // Simulate an unconfirmed hold older than the configured default expiry.
        // The real MariaDB transaction is rolled back in tearDown.
        $this->db->query(
            "UPDATE escrow_transactions
             SET status = 'pending', held_at = DATE_SUB(NOW(), INTERVAL 8761 HOUR)
             WHERE id = ?",
            [$escrowId]
        );

        $candidate = $this->db->fetch(
            "SELECT id, status, held_at FROM escrow_transactions WHERE id = ?",
            [$escrowId]
        );
        $this->assertInstanceOf(\stdClass::class, $candidate);
        $this->assertSame('pending', (string) $candidate->status);
        $this->assertLessThanOrEqual(strtotime('-48 hours'), strtotime((string) $candidate->held_at));
        $this->assertSame(1, (int) $this->db->fetchColumn(
            "SELECT COUNT(*) FROM escrow_transactions WHERE id = ? AND status = 'pending' AND held_at < DATE_SUB(NOW(), INTERVAL 1 HOUR)",
            [$escrowId]
        ));
        $this->assertSame(1, $this->financialEscrow->releaseExpiredHolds());
        $this->assertWallet($this->buyerId, '50000000.00000000', '0.00000000');
        $this->assertSame('refunded', (string) $this->db->fetchColumn(
            'SELECT status FROM escrow_transactions WHERE id = ?',
            [$escrowId]
        ));

        // A repeated cron pass must not unlock the already-refunded hold again.
        $this->assertSame(0, $this->financialEscrow->releaseExpiredHolds());
        $this->assertWallet($this->buyerId, '50000000.00000000', '0.00000000');
    }

    private function setWallet(int $userId, string $balance, string $locked): void
    {
        $this->db->query('INSERT INTO wallets (user_id, balance_irt, locked_irt, is_frozen) VALUES (?, ?, ?, 0) ON DUPLICATE KEY UPDATE balance_irt = VALUES(balance_irt), locked_irt = VALUES(locked_irt), is_frozen = 0', [$userId, $balance, $locked]);
    }

    private function assertWallet(int $userId, string $balance, string $locked): void
    {
        $wallet = $this->db->fetch('SELECT balance_irt, locked_irt FROM wallets WHERE user_id = ? FOR UPDATE', [$userId]);
        $this->assertInstanceOf(\stdClass::class, $wallet);
        $this->assertSame($balance, (string) $wallet->balance_irt);
        $this->assertSame($locked, (string) $wallet->locked_irt);
    }
}
