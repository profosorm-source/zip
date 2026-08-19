#!/usr/bin/env php
<?php

declare(strict_types=1);


require_once __DIR__ . '/project_zip/extracted/chortke/bootstrap/app.php';

use Core\Container;
use Core\Database;
use App\Services\Payment\PaymentService;
use App\Services\Notification\NotificationService;
use App\Services\SagaOrchestrator;
use App\Models\User;
use App\Models\Wallet;
use App\Models\Notification;
use App\Services\ManualDepositService;
use App\Services\Shared\ReferralService;
use App\Services\Score\ScoreCommandService;
use App\Services\Sentry\ErrorMonitoring\SentryErrorMonitor;

class ComprehensiveSystemTest {
    private Database $db;
    private int $userId;
    private int $referrerId;

    public function __construct() {
        $this->db = Container::getInstance()->make(Database::class);
        
        // Clear Redis cache to reset Rate Limits for E2E test
        try {
            $cache = Container::getInstance()->make(\Core\Cache::class);
            $cache->flush();
        } catch (\Throwable $e) {
            // Non-critical
        }
    }

    public function run(): void {
        echo "🚀 Starting Comprehensive End-to-End System Test...\n";
        echo "--------------------------------------------------\n";

        try {
            $this->step1_IdentityAndSecurity();
            $this->step2_OnlinePaymentFlow();
            $this->step3_ManualDepositFlow();
            $this->step4_ReferralAndGamification();
            $this->step5_NotificationIntegrity();
            $this->step6_FinancialAudit();
            $this->step7_SentryAndMonitoring();

            echo "--------------------------------------------------\n";
            echo "✅ ALL SYSTEMS INTEGRATED SUCCESSFULLY!\n";
        } catch (\Throwable $e) {
            echo "\n❌ SYSTEM INTEGRATION FAILED!\n";
            echo "Error: " . $e->getMessage() . "\n";
            echo "Trace: " . $e->getTraceAsString() . "\n";
            exit(1);
        }
    }

    private function step1_IdentityAndSecurity(): void {
        echo "Step 1: Identity & Security... ";
        
        // Create User
        $this->userId = 99999;
        $this->referrerId = 88888;
        
        $this->db->execute("INSERT IGNORE INTO users (id, email, full_name, status, created_at) VALUES (?, 'test_e2e@test.com', 'E2E Tester', 'active', NOW())", [$this->userId]);
        $this->db->execute("INSERT IGNORE INTO users (id, email, full_name, status, created_at) VALUES (?, 'ref_e2e@test.com', 'Referrer', 'active', NOW())", [$this->referrerId]);
        
        // Ensure Wallets exist
        $this->db->execute("INSERT IGNORE INTO wallets (user_id, balance_irt, balance_usdt, created_at) VALUES (?, 0, 0, NOW())", [$this->userId]);
        $this->db->execute("INSERT IGNORE INTO wallets (user_id, balance_irt, balance_usdt, created_at) VALUES (?, 0, 0, NOW())", [$this->referrerId]);
        
        // KYC Verification
        $this->db->execute("INSERT IGNORE INTO kyc_verifications (user_id, status, verified_at) VALUES (?, 'verified', NOW())", [$this->userId]);
        
        echo "OK\n";
    }

    private function step2_OnlinePaymentFlow(): void {
        echo "Step 2: Online Payment Flow (Saga)... ";
        
        $paymentService = Container::getInstance()->make(PaymentService::class);
        
        // 1. Create Payment
        $amount = 100000.0;
        $authority = "AUTH_" . uniqid();
        $now = date('Y-m-d H:i:s');
        
        // We simulate the record creation in payment_logs
        $this->db->execute("INSERT INTO payment_logs (user_id, gateway, amount, authority, status, created_at) VALUES (?, 'mock', ?, ?, 'pending', ?)", 
            [$this->userId, $amount, $authority, $now]);

        // 2. Simulate Callback
        $callbackData = [
            'authority' => $authority,
            'Status' => 'OK',
            'amount' => $amount,
            'ref_id' => 'REF_' . uniqid()
        ];

        // To debug, we call the internal logic or wrap it to catch the real exception
        try {
            $result = $paymentService->callback('mock', $callbackData, $this->userId);
            if (!$result['success']) {
                throw new \Exception("Online payment callback failed: " . $result['message']);
            }
        } catch (\Throwable $e) {
            throw $e;
        }

        echo "OK\n";
    }

    private function step3_ManualDepositFlow(): void {
        echo "Step 3: Manual Deposit Flow (Saga)... ";
        
        $manualService = Container::getInstance()->make(ManualDepositService::class);
        
        // 1. Create Manual Deposit Request
        $this->db->execute("INSERT INTO manual_deposits (user_id, amount, currency, status, created_at) VALUES (?, ?, 'irt', 'pending', NOW())", 
            [$this->userId, 50000.0]);
        $depositId = (int)$this->db->lastInsertId();

        // 2. Admin Approval
        $adminId = 1;
        $result = $manualService->approve($adminId, $depositId, 'Verified by E2E Test');

        if (!$result) {
            throw new \Exception("Manual deposit approval failed");
        }

        echo "OK\n";
    }

    private function step4_ReferralAndGamification(): void {
        echo "Step 4: Referral & Gamification... ";
        
        $referralService = Container::getInstance()->make(ReferralService::class);
        $scoreService = Container::getInstance()->make(ScoreCommandService::class);

        // 1. Process Referral Commission (Rewarding the referrer for the deposits)
        $referralService->processCommission($this->referrerId, '150000', 'irt', [
            'user_id' => $this->userId,
            'type' => 'first_deposits_bundle'
        ]);

        // 2. Award XP for activity
        $scoreService->applyDelta('user', $this->userId, 'activity', 100.0, 'first_deposit');

        echo "OK\n";
    }

    private function step5_NotificationIntegrity(): void {
        echo "Step 5: Notification Integrity... ";
        
        // 1. Force events to be available immediately for the test
        $this->db->execute("UPDATE outbox_events SET available_at = NOW() WHERE status = 'pending'");
        
        // 2. Process pending outbox events to ensure notifications are delivered
        $publisher = Container::getInstance()->make(\App\Services\OutboxPublisher::class);
        $publisher->publishPending(100);
        
        $notifService = Container::getInstance()->make(NotificationService::class);
        
        // Check if any notifications were created for this user during the journey
        $count = $this->db->fetchColumn("SELECT COUNT(*) FROM notifications WHERE user_id = ?", [$this->userId]);
        
        if ($count == 0) {
            throw new \Exception("No notifications were generated during the user journey!");
        }

        echo "OK (Count: $count)\n";
    }

    private function step6_FinancialAudit(): void {
        echo "Step 6: Financial Audit (Wallet vs Ledger)... ";
        
        // Wallet Balance
        $walletBalance = (float)$this->db->fetchColumn("SELECT balance_irt FROM wallets WHERE user_id = ?", [$this->userId]);
        
        // Ledger Sum
        $ledgerSum = (float)$this->db->fetchColumn("SELECT SUM(credit - debit) FROM ledger_entries WHERE account = ?", ["wallet:{$this->userId}"]);

        
        // We expected: 100,000 (online) + 50,000 (manual) = 150,000
        if (abs($walletBalance - $ledgerSum) > 0.01) {
            throw new \Exception("Financial mismatch! Wallet: $walletBalance, Ledger: $ledgerSum");
        }

        echo "OK (Balance: $walletBalance)\n";
    }

    private function step7_SentryAndMonitoring(): void {
        echo "Step 7: Sentry & Monitoring... ";
        
        $sentry = Container::getInstance()->make(SentryErrorMonitor::class);
        
        // Trigger a manual anomaly
        $sentry->captureAnomaly('e2e_test_anomaly', 'Testing Sentry integration in E2E flow', [
            'user_id' => $this->userId,
            'test' => 'comprehensive'
        ]);

        $issueCount = (int)$this->db->fetchColumn("SELECT COUNT(*) FROM sentry_issues");
        if ($issueCount == 0) {
            throw new \Exception("Sentry failed to record the anomaly!");
        }

        echo "OK\n";
    }
}

$test = new ComprehensiveSystemTest();
$test->run();
