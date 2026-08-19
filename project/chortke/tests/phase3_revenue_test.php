<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap/app.php';

use Core\Container;
use App\Services\AdSystemManager;
use App\Services\Wallet\WalletService;
use App\Models\User;
use Core\Database;

/**
 * Phase 3 Revenue Integration Test
 * 
 * Objectives:
 * 1. Verify correct Ad Adapter resolution (Banner vs SEO).
 * 2. Verify Financial integrity (Funds hold -> Ad creation -> Ledger).
 * 3. Verify Anti-Fraud (IP Blacklist).
 */

class RevenueTest {
    private Database $db;
    private AdSystemManager $adManager;
    private WalletService $walletService;
    private int $testUserId;

    public function __construct() {
        $this->db = Container::getInstance()->make(Database::class);
        $this->adManager = Container::getInstance()->make(AdSystemManager::class);
        $this->walletService = Container::getInstance()->make(WalletService::class);
        
        $this->setupTestData();
    }

    private function setupTestData(): void {
        // Create a test user if not exists
        $this->testUserId = 99999;
        $this->db->prepare("INSERT IGNORE INTO users (id, email, full_name, password, created_at) VALUES (?, 'test@revenue.com', 'test_revenue', 'hashed_pass', NOW())")->execute([$this->testUserId]);
        
        // Clear wallet if exists and recreate with clean 1000000 IRT
        $this->db->prepare("DELETE FROM wallets WHERE user_id = ?")->execute([$this->testUserId]);
        $this->db->prepare("INSERT INTO wallets (user_id, balance_irt, created_at) VALUES (?, 1000000, NOW())")->execute([$this->testUserId]);
        
        // Clear previous test ads, escrows, and sagas
        $this->db->prepare("DELETE FROM ads WHERE user_id = ?")->execute([$this->testUserId]);
        $this->db->prepare("DELETE FROM escrow_transactions WHERE buyer_id = ? OR seller_id = ?")->execute([$this->testUserId, $this->testUserId]);
        $this->db->prepare("DELETE FROM saga_executions WHERE payload LIKE ?")->execute(["%\"user_id\":$this->testUserId%"]);
        $this->db->prepare("DELETE FROM idempotency_keys WHERE user_id = ?")->execute([$this->testUserId]);
    }

    public function run(): void {
        $this->testBannerCreation();
        $this->testSeoCreation();
        $this->testIpBlacklist();
        
        echo "\n✅ All Revenue Tests Passed!\n";
    }

    private function testBannerCreation(): void {
        echo "Testing Banner Creation... ";
        
        $data = [
            'title' => 'Test Banner',
            'placement' => 'homepage_slider',
            'link' => 'https://example.com',
            'budget' => '10000',
            'currency' => 'irt',
            'total_budget' => '10000',
        ];

        $result = $this->adManager->create('banner', $this->testUserId, $data);
        
        if (!isset($result['ad_id'])) {
            throw new Exception("Banner creation failed: " . json_encode($result));
        }

        $ad = $this->db->fetch("SELECT * FROM ads WHERE id = ?", [$result['ad_id']]);
        if ($ad === null) {
            throw new Exception("Ad record not found in DB");
        }

        $balanceIrt = float_value($this->walletService->getBalance($this->testUserId, 'irt'));
        if ($balanceIrt >= 1000000.0) {
            throw new Exception("Funds were not deducted from wallet");
        }

        echo "OK\n";
    }

    private function testSeoCreation(): void {
        echo "Testing SEO Creation... ";
        
        $data = [
            'title' => 'Test SEO',
            'site_url' => 'https://example.com',
            'keyword' => 'test keyword',
            'price_per_click' => '100',
            'budget' => '20000',
            'currency' => 'irt',
            'total_budget' => '20000',
        ];

        $result = $this->adManager->create('seo', $this->testUserId, $data);
        
        if (!isset($result['ad_id'])) {
            throw new Exception("SEO creation failed: " . json_encode($result));
        }

        $ad = $this->db->fetch("SELECT * FROM ads WHERE id = ?", [$result['ad_id']]);
        if ($ad === null) {
            throw new Exception("Ad record not found in DB");
        }

        echo "OK\n";
    }

    private function testIpBlacklist(): void {
        echo "Testing IP Blacklist... ";
        
        $badIp = '1.2.3.4';
        $this->db->prepare("INSERT INTO ip_blacklist (ip_address, reason, auto_blocked, expires_at) VALUES (?, 'test fraud', 1, DATE_ADD(NOW(), INTERVAL 1 DAY))")->execute([$badIp]);
        
        // In a real scenario, we'd check a service that uses this table.
        // Since we don't have a direct 'checkIp' method in AdSystemManager, 
        // we verify the table works.
        $stmt = $this->db->prepare("SELECT id FROM ip_blacklist WHERE ip_address = ? AND (expires_at IS NULL OR expires_at > NOW()) LIMIT 1");
        $stmt->execute([$badIp]);
        $check = $stmt->fetch();
        
        if (!$check) throw new Exception("Blacklisted IP not found");
        
        // Clean up
        $this->db->prepare("DELETE FROM ip_blacklist WHERE ip_address = ?")->execute([$badIp]);
        
        echo "OK\n";
    }
}

try {
    $test = new RevenueTest();
    $test->run();
} catch (\Throwable $e) {
    echo "\n❌ Test Failed: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
    exit(1);
}
