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
 */
final class InfluencerEscrowCommissionInvariantTest extends TestCase
{
    use ResetsConfiguredRedis;
    private Database $db;
    private WalletServiceInterface $wallet;
    private FinancialEscrowService $financialEscrow;
    private int $buyerId = 1;
    private int $sellerId = 2;
    private int $outputBufferLevel;

    protected function setUp(): void
    {
        parent::setUp();ini_set('error_log', sys_get_temp_dir() . '/chortke-financial-integration.log');config_set('app.key', 'testing-app-key-32-characters-long!!');
        $this->outputBufferLevel = ob_get_level(); ob_start();
        try {
            $c = Container::getInstance();
            $this->db = $c->make(Database::class);
            $this->wallet = $c->make(WalletServiceInterface::class);
            $this->financialEscrow = $c->make(FinancialEscrowService::class);
            $this->db->getPdo();$this->db->query("DELETE FROM score_events WHERE entity_type='user' AND domain='fraud' AND entity_id IN (1,2,3,4)");$this->db->query("UPDATE users SET fraud_score=0,is_blacklisted=0,status='active' WHERE id IN (1,2,3,4)");$this->db->query("UPDATE user_scores SET score=0 WHERE domain='fraud' AND user_id IN (1,2,3,4)");$this->resetConfiguredRedis([1,2,3,4]);
        } catch (\Throwable $e) { $this->fail('Financial DB unavailable: ' . $e->getMessage()); }
        $this->db->beginTransaction();
        $this->setWallet($this->buyerId, '50000000.0000', '0.0000');
        $this->setWallet($this->sellerId, '0.0000', '0.0000');
    }
    protected function tearDown(): void
    {
        if (isset($this->db) && $this->db->inTransaction()) $this->db->rollback();
        while (isset($this->outputBufferLevel) && ob_get_level() > $this->outputBufferLevel) ob_end_clean();
        parent::tearDown();
    }

    /** @test */
    public function release_splits_locked_buyer_funds_between_seller_and_platform_without_refund(): void
    {
        [$orderId, $escrowId, $holdTx] = $this->createEscrow('20000.0000');
        $result = $this->financialEscrow->releaseInfluencerOrderFunds(
            $orderId, $this->sellerId, '15000.0000', 'test-influencer-release-' . bin2hex(random_bytes(8))
        );
        $this->assertTrue((bool)($result['ok'] ?? false), (json_encode($result) ?: ''));
        $this->assertWallet($this->buyerId, '49980000.00000000', '0.00000000');
        $this->assertWallet($this->sellerId, '15000.00000000', '0.00000000');
        $this->assertSame('released', (string)$this->db->fetchColumn('SELECT status FROM escrow_transactions WHERE id = ?', [$escrowId]));
        $this->assertSame('completed', (string)$this->db->fetchColumn('SELECT status FROM transactions WHERE transaction_id = ?', [$holdTx]));

        $platformCredit = (string)$this->db->fetchColumn(
            "SELECT COALESCE(SUM(credit) - SUM(debit), 0) FROM ledger_entries WHERE account = 'platform_revenue' AND currency = 'irt'"
        );
        $this->assertSame('5000.00000000', $platformCredit);
        $escrowPayoutNet = (string)$this->db->fetchColumn(
            "SELECT COALESCE(SUM(debit) - SUM(credit), 0) FROM ledger_entries WHERE account = 'escrow_payout' AND currency = 'irt'"
        );
        $this->assertSame('0.00000000', $escrowPayoutNet);
    }

    /** @return array{int,int,string} */
    private function createEscrow(string $amount): array
    {
        $orderId = random_int(100000000, 999999999);
        $hold = $this->wallet->withdraw($this->buyerId, $amount, 'irt', [
            'type' => 'influencer_escrow', 'order_id' => $orderId, 'ref_id' => $orderId,
            'ref_type' => 'influencer_order', 'idempotency_key' => 'test-inf-hold-' . bin2hex(random_bytes(8)),
        ]);
        $this->assertTrue((bool)($hold['success'] ?? false));
        $this->db->query("INSERT INTO escrow_transactions (order_id,order_type,buyer_id,seller_id,amount,currency,status,held_at,expires_at) VALUES (?, 'influencer_order', ?, ?, ?, 'irt','in_escrow',NOW(),DATE_ADD(NOW(),INTERVAL 1 DAY))", [$orderId,$this->buyerId,$this->sellerId,$amount]);
        return [$orderId, (int)$this->db->lastInsertId(), str_value($hold['transaction_id'] ?? '')];
    }
    private function setWallet(int $id,string $b,string $l):void {$this->db->query('INSERT INTO wallets (user_id, balance_irt, locked_irt, is_frozen) VALUES (?, ?, ?, 0) ON DUPLICATE KEY UPDATE balance_irt = VALUES(balance_irt), locked_irt = VALUES(locked_irt), is_frozen = 0',[$id,$b,$l]);}
    private function assertWallet(int $id,string $b,string $l):void {$w=$this->db->fetch('SELECT balance_irt,locked_irt FROM wallets WHERE user_id=? FOR UPDATE',[$id]);$this->assertInstanceOf(\stdClass::class,$w);$this->assertSame($b,(string)$w->balance_irt);$this->assertSame($l,(string)$w->locked_irt);}
}
