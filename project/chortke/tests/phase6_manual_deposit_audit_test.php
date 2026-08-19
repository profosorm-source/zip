<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap/app.php';

use Core\Container;
use Core\Database;
use App\Services\ManualDepositService;
use App\Services\Payment\PaymentService;
use App\Models\User;
use App\Models\Wallet;

class ManualDepositAuditTest {
    private Database $db;
    private ManualDepositService $depositService;
    private int $userId = 99999;
    private int $adminId = 1;

    public function __construct() {
        $this->db = Container::getInstance()->make(Database::class);
        $this->depositService = Container::getInstance()->make(ManualDepositService::class);
        $this->setupTestData();
    }

    private function setupTestData(): void {
        // Create User
        $this->db->execute("INSERT IGNORE INTO users (id, email, full_name, status, created_at) VALUES (?, 'deposit_test@test.com', 'Deposit Tester', 'active', NOW())", [$this->userId]);
        
        // Create Wallet
        $this->db->execute("INSERT IGNORE INTO wallets (user_id, balance_irt) VALUES (?, 0)", [$this->userId]);
        
        // Create Bank Card
        $this->db->execute("INSERT IGNORE INTO bank_cards (user_id, card_number, bank_name) VALUES (?, '1234567890', 'Test Bank')", [$this->userId]);
        
        // Clean up previous deposits
        $this->db->execute("DELETE FROM manual_deposits WHERE user_id = ?", [$this->userId]);
    }

    public function run(): void {
        echo "--- Phase 6: Manual Deposit & Payment Queue Audit ---\n";

        $this->testCreateDeposit();
        $this->testApproveDeposit();
        $this->testDoubleApproval();
        $this->testRejectDeposit();

        echo "\n✅ Manual Deposit Audit Passed!\n";
    }

    private function testCreateDeposit(): int {
        echo "Testing Create Deposit... ";
        
        $data = [
            'bank_card_id' => 1, 
            'amount' => '100000',
            'tracking_code' => 'TRK' . uniqid(),
            'user_description' => 'Test Deposit',
            'idempotency_key' => 'uk_' . uniqid(),
        ];
        
        // Ensure card 1 exists for the user
        $this->db->execute("INSERT INTO bank_cards (id, user_id, card_number, bank_name) VALUES (1, ?, '1234567890', 'Test Bank') ON DUPLICATE KEY UPDATE user_id=?", [$this->userId, $this->userId]);

        $res = $this->depositService->create($this->userId, $data);
        
        if (!$res['success']) {
            throw new Exception("Deposit creation failed: " . ($res['message'] ?? 'No message'));
        }
        
        $depositId = int_value($res['deposit_id'] ?? 0);
        if ($depositId <= 0) {
            throw new Exception("Deposit creation did not return a deposit_id");
        }
        $row = $this->db->fetch("SELECT status FROM manual_deposits WHERE id = ?", [$depositId]);
        
        if (!$row || $row->status !== 'pending') {
            throw new Exception("Deposit status should be 'pending'");
        }
        
        echo "OK (ID: $depositId)\n";
        return $depositId;
    }

    private function testApproveDeposit(): void {
        echo "Testing Approve Deposit... ";
        
        // Setup: Create a deposit
        $depositId = $this->createDummyDeposit('200000', 'TRK_APP');
        $initialBalance = $this->getWalletBalance();
        
        $success = $this->depositService->approve($this->adminId, $depositId, 'Approved by auditor');
        
        if (!$success) {
            throw new Exception("Deposit approval failed");
        }
        
        // Verify balance
        $finalBalance = $this->getWalletBalance();
        $expectedBalance = bcadd($initialBalance, '200000', 4);
        if (bccomp($finalBalance, $expectedBalance, 4) !== 0) {
            throw new Exception("Wallet balance did not increase correctly. Expected: {$expectedBalance}, Got: {$finalBalance}");
        }
        
        // Verify status
        $status = $this->db->fetchColumn("SELECT status FROM manual_deposits WHERE id = ?", [$depositId]);
        if ($status !== 'approved') {
            throw new Exception("Deposit status should be 'approved'. Got: $status");
        }
        
        echo "OK\n";
    }

    private function testDoubleApproval(): void {
        echo "Testing Double Approval... ";
        
        $depositId = $this->createDummyDeposit('300000', 'TRK_DBL');
        
        // First approval
        $this->depositService->approve($this->adminId, $depositId, 'First time');
        
        // Second approval - should fail
        $success = $this->depositService->approve($this->adminId, $depositId, 'Second time');
        
        if ($success) {
            throw new Exception("Double approval should have failed");
        }
        
        echo "OK\n";
    }

    private function testRejectDeposit(): void {
        echo "Testing Reject Deposit... ";
        
        $depositId = $this->createDummyDeposit('400000', 'TRK_REJ');
        $initialBalance = $this->getWalletBalance();
        
        $success = $this->depositService->reject($this->adminId, $depositId, 'Invalid receipt');
        
        if (!$success) {
            throw new Exception("Deposit rejection failed");
        }
        
        // Balance should not change
        $finalBalance = $this->getWalletBalance();
        if ($finalBalance !== $initialBalance) {
            throw new Exception("Wallet balance should not change on rejection");
        }
        
        // Status check
        $status = $this->db->fetchColumn("SELECT status FROM manual_deposits WHERE id = ?", [$depositId]);
        if ($status !== 'rejected') {
            throw new Exception("Deposit status should be 'rejected'. Got: $status");
        }
        
        echo "OK\n";
    }

    private function createDummyDeposit(string $amount, string $tracking): int {
        $this->db->execute(
            "INSERT INTO manual_deposits (user_id, bank_card_id, amount, tracking_code, status, created_at) VALUES (?, 1, ?, ?, 'pending', NOW())",
            [$this->userId, $amount, $tracking]
        );
        return (int)$this->db->lastInsertId();
    }

    private function getWalletBalance(): string {
        $bal = $this->db->fetchColumn("SELECT balance_irt FROM wallets WHERE user_id = ?", [$this->userId]);
        return (string)($bal ?? '0');
    }
}

try {
    $test = new ManualDepositAuditTest();
    $test->run();
} catch (\Throwable $e) {
    echo "\n❌ Manual Deposit Audit Failed: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
    exit(1);
}
