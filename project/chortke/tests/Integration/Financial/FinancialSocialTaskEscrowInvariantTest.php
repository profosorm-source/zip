<?php

declare(strict_types=1);

namespace Tests\Integration\Financial;

use Tests\Support\ResetsConfiguredRedis;
use App\Contracts\WalletServiceInterface;
use App\Domain\Financial\Services\FinancialEscrowService;
use App\Services\SocialTask\SocialTaskService;
use Core\Container;
use Core\Database;
use PHPUnit\Framework\TestCase;

/**
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 *
 * End-to-end financial invariants for the social-task escrow wrapper.
 * It exercises the real wallet hold, escrow state transition and settlement.
 */
final class FinancialSocialTaskEscrowInvariantTest extends TestCase
{
    use ResetsConfiguredRedis;
    private Database $db;
    private WalletServiceInterface $wallet;
    private FinancialEscrowService $financialEscrow;
    private SocialTaskService $socialTasks;
    private int $advertiserId = 1;
    private int $executorId = 2;
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
            $this->socialTasks = $container->make(SocialTaskService::class);
            $this->db->getPdo();$this->db->query("DELETE FROM score_events WHERE entity_type='user' AND domain='fraud' AND entity_id IN (1,2,3,4)");$this->db->query("UPDATE users SET fraud_score=0,is_blacklisted=0,status='active' WHERE id IN (1,2,3,4)");$this->db->query("UPDATE user_scores SET score=0 WHERE domain='fraud' AND user_id IN (1,2,3,4)");$this->resetConfiguredRedis([1,2,3,4]);
        } catch (\Throwable $exception) {
            $this->fail('Financial integration database is unavailable: ' . $exception->getMessage());
        }

        $this->db->beginTransaction();
        $this->setWallet($this->advertiserId, '50000000.0000', '0.0000');
        $this->setWallet($this->executorId, '0.0000', '0.0000');
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
    public function social_task_creation_uses_one_central_hold_and_replays_idempotently(): void
    {
        $key = 'integration-social-create-' . bin2hex(random_bytes(10));
        $payload = [
            'platform' => 'instagram',
            'task_type' => 'follow',
            'target_url' => 'https://instagram.com/example',
            'reward_amount' => '1000.0000',
            'total_quantity' => 10,
            'currency' => 'irt',
            'idempotency_key' => $key,
        ];

        $first = $this->socialTasks->createTask($this->advertiserId, $payload);
        $this->assertTrue((bool) ($first['success'] ?? false), (json_encode($first) ?: ''));
        $adId = (int) ($first['ad_id'] ?? 0);
        $this->assertGreaterThan(0, $adId);

        $replay = $this->socialTasks->createTask($this->advertiserId, $payload);
        $this->assertTrue((bool) ($replay['success'] ?? false), (json_encode($replay) ?: ''));
        $this->assertSame($adId, (int) ($replay['ad_id'] ?? 0));

        $ad = $this->db->fetch(
            'SELECT total_budget, remaining_budget, price_per_task FROM ads WHERE id = ? FOR UPDATE',
            [$adId]
        );
        $this->assertNotNull($ad);
        $this->assertSame('10000.0000', (string) $ad->total_budget);
        $this->assertSame('10000.0000', (string) $ad->remaining_budget);
        $this->assertSame('1000.0000', (string) $ad->price_per_task);

        $escrow = $this->db->fetch(
            "SELECT id, amount, status FROM escrow_transactions
             WHERE order_id = ? AND order_type = 'social_task_budget' FOR UPDATE",
            [(string) $adId]
        );
        $this->assertNotNull($escrow);
        $this->assertSame('pending', (string) $escrow->status);
        $this->assertSame(
            1,
            (int) $this->db->fetchColumn(
                "SELECT COUNT(*) FROM escrow_transactions
                 WHERE order_id = ? AND order_type = 'social_task_budget'",
                [(string) $adId]
            )
        );
        $this->assertWallet(
            $this->advertiserId,
            bcsub('50000000.0000', (string) $escrow->amount, 8),
            (string) $escrow->amount
        );
    }

    /** @test */
    public function release_consumes_advertiser_lock_and_credits_executor_exactly_once(): void
    {
        [$orderId, $escrowId] = $this->createSocialTaskEscrow('20000.0000');

        $result = $this->financialEscrow->releaseSocialTaskFunds(
            $orderId,
            $this->executorId,
            $this->advertiserId,
            '20000.0000',
            'test-social-release-' . bin2hex(random_bytes(8))
        );
        $this->assertTrue((bool) ($result['ok'] ?? false), (json_encode($result) ?: ''));

        $this->assertWallet($this->advertiserId, '49980000.00000000', '0.00000000');
        $this->assertWallet($this->executorId, '20000.00000000', '0.00000000');
        $this->assertSame('released', (string) $this->db->fetchColumn('SELECT status FROM escrow_transactions WHERE id = ?', [$escrowId]));
    }

    /** @test */
    public function refund_unlocks_advertiser_funds_without_an_extra_deposit(): void
    {
        [$orderId, $escrowId] = $this->createSocialTaskEscrow('20000.0000');

        $result = $this->financialEscrow->refundSocialTaskFunds(
            $orderId,
            $this->advertiserId,
            'integration refund',
            'test-social-refund-' . bin2hex(random_bytes(8))
        );
        $this->assertTrue((bool) ($result['ok'] ?? false), (json_encode($result) ?: ''));

        $this->assertWallet($this->advertiserId, '50000000.00000000', '0.00000000');
        $this->assertWallet($this->executorId, '0.00000000', '0.00000000');
        $this->assertSame('refunded', (string) $this->db->fetchColumn('SELECT status FROM escrow_transactions WHERE id = ?', [$escrowId]));
    }

    /** @return array{int,int} orderId, escrowId */
    private function createSocialTaskEscrow(string $amount): array
    {
        $orderId = random_int(100000000, 999999999);
        $hold = $this->wallet->withdraw($this->advertiserId, $amount, 'irt', [
            'type' => 'social_task_escrow',
            'execution_id' => $orderId,
            'ref_id' => $orderId,
            'ref_type' => 'social_task_execution',
            'idempotency_key' => 'test-social-hold-' . bin2hex(random_bytes(10)),
        ]);
        $this->assertTrue((bool) ($hold['success'] ?? false));

        $this->db->query(
            "INSERT INTO escrow_transactions
             (order_id, order_type, buyer_id, seller_id, amount, currency, status, held_at, expires_at)
             VALUES (?, 'social_task_execution', ?, ?, ?, 'irt', 'in_escrow', NOW(), DATE_ADD(NOW(), INTERVAL 1 DAY))",
            [$orderId, $this->advertiserId, $this->executorId, $amount]
        );
        return [$orderId, (int) $this->db->lastInsertId()];
    }

    private function setWallet(int $userId, string $balance, string $locked): void
    {
        $this->db->query(
            'INSERT INTO wallets (user_id, balance_irt, locked_irt, is_frozen) VALUES (?, ?, ?, 0) ON DUPLICATE KEY UPDATE balance_irt = VALUES(balance_irt), locked_irt = VALUES(locked_irt), is_frozen = 0',
            [$userId, $balance, $locked]
        );
    }

    private function assertWallet(int $userId, string $balance, string $locked): void
    {
        $wallet = $this->db->fetch('SELECT balance_irt, locked_irt FROM wallets WHERE user_id = ? FOR UPDATE', [$userId]);
        $this->assertNotNull($wallet);
        $this->assertSame($balance, (string) $wallet->balance_irt);
        $this->assertSame($locked, (string) $wallet->locked_irt);
    }
}
