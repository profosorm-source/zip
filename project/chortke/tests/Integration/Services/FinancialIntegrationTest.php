<?php

declare(strict_types=1);

namespace Tests\Integration\Services;

use PHPUnit\Framework\TestCase;
use Core\Database;

/**
 * Real integration tests for financial services.
 * Tests wallet operations, transactions, against actual MariaDB.
 */
class FinancialIntegrationTest extends TestCase
{
    private Database $db;

    protected function setUp(): void
    {
        parent::setUp();
        $this->db = Database::getInstance();
        $this->db->ensureConnected();
    }

    /** @test */
    public function transactions_table_has_expected_schema(): void
    {
        // Get a seed user to create a test transaction
        $user = $this->db->fetch("SELECT id FROM users LIMIT 1");
        $this->assertNotNull($user, 'No seed users found');

        // Create a test transaction to validate schema, then clean up
        $txId = 'SCHEMA_TEST_' . uniqid();
        $this->db->beginTransaction();
        $this->db->execute(
            "INSERT INTO transactions (user_id, transaction_id, type, amount, currency, status, created_at, updated_at) 
             VALUES (?, ?, 'test', 0, 'IRT', 'pending', NOW(), NOW())",
            [(int)$user->id, $txId]
        );
        $testId = $this->db->lastInsertId();

        $row = $this->db->fetch("SELECT * FROM transactions WHERE id = ?", [$testId]);
        $this->assertNotNull($row, 'Failed to insert test transaction');

        $expected = ['id', 'user_id', 'transaction_id', 'type', 'amount', 'currency', 'status', 'created_at'];
        foreach ($expected as $col) {
            $this->assertTrue(property_exists($row, $col), "Missing column in transactions: $col");
        }

        $this->db->rollback();
    }

    /** @test */
    public function wallets_table_has_expected_schema(): void
    {
        // Try to insert a test wallet to verify schema
        $user = $this->db->fetch("SELECT id FROM users LIMIT 1");
        $this->assertNotNull($user, 'No seed users found');

        // Check if wallet exists
        $wallet = $this->db->fetch("SELECT * FROM wallets WHERE user_id = ?", [(int)$user->id]);

        if ($wallet) {
            $expected = ['user_id', 'balance_irt', 'balance_usdt', 'locked_irt'];
            foreach ($expected as $col) {
                $this->assertTrue(property_exists($wallet, $col), "Missing column in wallets: $col");
            }
        }
    }

    /** @test */
    public function referral_commissions_table_is_queryable(): void
    {
        $count = $this->db->fetchColumn("SELECT COUNT(*) FROM referral_commissions");
        $this->assertIsNumeric($count);
    }

    /** @test */
    public function payment_logs_table_is_queryable(): void
    {
        $count = $this->db->fetchColumn("SELECT COUNT(*) FROM payment_logs");
        $this->assertIsNumeric($count);
    }

    /** @test */
    public function bank_cards_table_is_queryable(): void
    {
        $count = $this->db->fetchColumn("SELECT COUNT(*) FROM bank_cards");
        $this->assertIsNumeric($count);
    }

    /** @test */
    public function escrow_table_is_queryable(): void
    {
        $count = $this->db->fetchColumn("SELECT COUNT(*) FROM escrow_transactions");
        $this->assertIsNumeric($count);
    }

    /** @test */
    public function withdrawals_table_is_queryable(): void
    {
        $count = $this->db->fetchColumn("SELECT COUNT(*) FROM withdrawals");
        $this->assertIsNumeric($count);
    }

    /** @test */
    public function deposits_table_is_queryable(): void
    {
        try {
            $count = $this->db->fetchColumn("SELECT COUNT(*) FROM manual_deposits");
            $this->assertIsNumeric($count);
        } catch (\PDOException $e) {
            $this->fail('manual_deposits table does not exist: ' . $e->getMessage());
        }
    }

    /** @test */
    public function transaction_create_and_rollback_works(): void
    {
        $user = $this->db->fetch("SELECT id FROM users LIMIT 1");
        $this->assertNotNull($user);

        try {
            $this->db->beginTransaction();

            $txId = 'TEST_' . uniqid();
            $this->db->execute(
                "INSERT INTO transactions (user_id, transaction_id, type, amount, currency, status, created_at, updated_at) 
                 VALUES (?, ?, 'test', 0, 'IRT', 'pending', NOW(), NOW())",
                [(int)$user->id, $txId]
            );

            $testId = $this->db->lastInsertId();
            $this->assertGreaterThan(0, $testId);

            $this->db->rollback();

            $result = $this->db->fetch("SELECT * FROM transactions WHERE id = ?", [$testId]);
            $this->assertNull($result, 'Rollback failed — test transaction still exists');
        } catch (\PDOException $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollback();
            }
            $this->fail('transactions table schema mismatch: ' . $e->getMessage());
        }
    }

    /** @test */
    public function can_sum_transactions_by_type(): void
    {
        try {
            $result = $this->db->fetch(
                "SELECT 
                    COALESCE(SUM(CASE WHEN type = 'deposit' THEN amount ELSE 0 END), 0) as total_deposits,
                    COALESCE(SUM(CASE WHEN type = 'withdraw' THEN amount ELSE 0 END), 0) as total_withdrawals,
                    COUNT(*) as total_count
                 FROM transactions"
            );

            $this->assertNotNull($result);
            $this->assertTrue(property_exists($result, 'total_deposits'));
            $this->assertTrue(property_exists($result, 'total_withdrawals'));
            $this->assertTrue(property_exists($result, 'total_count'));
        } catch (\PDOException $e) {
            $this->fail('transactions table schema mismatch: ' . $e->getMessage());
        }
    }
}
