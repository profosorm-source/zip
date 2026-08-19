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
 * A consumed advertising budget must leave the buyer's locked balance; it is
 * not a refund. This guards against locked -> balance money reappearance.
 */
final class EscrowBudgetConsumptionInvariantTest extends TestCase
{
    use ResetsConfiguredRedis;
    private Database $db;
    private WalletServiceInterface $wallet;
    private FinancialEscrowService $financialEscrow;
    private int $buyerId = 1;
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
            $this->financialEscrow = $container->make(FinancialEscrowService::class);
            $this->db->getPdo();$this->db->query("DELETE FROM score_events WHERE entity_type='user' AND domain='fraud' AND entity_id IN (1,2,3,4)");$this->db->query("UPDATE users SET fraud_score=0,is_blacklisted=0,status='active' WHERE id IN (1,2,3,4)");$this->db->query("UPDATE user_scores SET score=0 WHERE domain='fraud' AND user_id IN (1,2,3,4)");$this->resetConfiguredRedis([1,2,3,4]);
        } catch (\Throwable $exception) {
            $this->fail('Financial integration database is unavailable: ' . $exception->getMessage());
        }

        $this->db->beginTransaction();
        $this->db->query('INSERT INTO wallets (user_id, balance_irt, locked_irt, is_frozen) VALUES (?, ?, 0, 0) ON DUPLICATE KEY UPDATE balance_irt = VALUES(balance_irt), locked_irt = 0, is_frozen = 0', [$this->buyerId, '50000000.0000']);
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
    public function consumed_budget_reduces_locked_funds_without_refunding_buyer_balance(): void
    {
        $escrowId = $this->createFundedBudgetEscrow('20000.0000');

        $result = $this->financialEscrow->consumeHeldBudget(
            $escrowId,
            $this->buyerId,
            '8000.0000',
            'irt',
            'integration ad delivery consumption'
        );
        $this->assertTrue((bool) ($result['ok'] ?? false));

        $wallet = $this->db->fetch('SELECT balance_irt, locked_irt FROM wallets WHERE user_id = ? FOR UPDATE', [$this->buyerId]);
        $this->assertNotNull($wallet);
        $this->assertSame('49980000.00000000', (string) $wallet->balance_irt);
        $this->assertSame('12000.00000000', (string) $wallet->locked_irt);

        $escrow = $this->db->fetch('SELECT status, amount, partial_released FROM escrow_transactions WHERE id = ? FOR UPDATE', [$escrowId]);
        $this->assertNotNull($escrow);
        $this->assertSame('partial', (string) $escrow->status);
        $this->assertSame('12000.00000000', (string) $escrow->amount);
        $this->assertSame('8000.00000000', (string) $escrow->partial_released);
    }

    private function createFundedBudgetEscrow(string $amount): int
    {
        $orderId = 'test-budget-' . bin2hex(random_bytes(8));
        $hold = $this->wallet->withdraw($this->buyerId, $amount, 'irt', [
            'type' => 'test_budget_hold',
            'ref_id' => $orderId,
            'idempotency_key' => 'test-budget-hold-' . bin2hex(random_bytes(10)),
        ]);
        $this->assertTrue((bool) ($hold['success'] ?? false));

        $this->db->query(
            "INSERT INTO escrow_transactions
             (order_id, order_type, buyer_id, seller_id, amount, currency, status, held_at, expires_at)
             VALUES (?, 'test_budget_consumption', ?, 2, ?, 'irt', 'in_escrow', NOW(), DATE_ADD(NOW(), INTERVAL 1 DAY))",
            [$orderId, $this->buyerId, $amount]
        );
        return (int) $this->db->lastInsertId();
    }
}
