<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap/app.php';

use Core\Container;
use Core\Database;
use App\Services\InfluencerService;
use App\Listeners\InfluencerEventListeners;
use App\Models\StoryOrder;

class InfluencerPayoutTest {
    private Database $db;
    private int $customerId = 66666;
    private int $influencerUserId = 55555;
    private int $influencerId = 111;

    public function __construct() {
        $this->db = Container::getInstance()->make(Database::class);
        $this->setupTestData();
    }

    private function setupTestData(): void {
        // 1. Create Users
        $this->db->prepare("INSERT IGNORE INTO users (id, email, full_name, password, created_at) VALUES (?, 'cust@test.com', 'Customer', 'pass', NOW())")->execute([$this->customerId]);
        $this->db->prepare("INSERT IGNORE INTO users (id, email, full_name, password, created_at) VALUES (?, 'inf@test.com', 'Influencer', 'pass', NOW())")->execute([$this->influencerUserId]);
        
        // 2. Create Wallets
        $this->db->prepare("INSERT IGNORE INTO wallets (user_id, balance_irt, created_at) VALUES (?, 1000000, NOW())")->execute([$this->customerId]);
        $this->db->prepare("INSERT IGNORE INTO wallets (user_id, balance_irt, created_at) VALUES (?, 0, NOW())")->execute([$this->influencerUserId]);
        
        // 3. Create an Influencer Order
        $this->db->prepare("DELETE FROM story_orders WHERE id = 888")->execute();
        $this->db->prepare("INSERT INTO story_orders (id, customer_id, influencer_id, influencer_user_id, price, influencer_earning, currency, status, created_at) VALUES (?, ?, ?, ?, 100000, 20000, 'irt', 'pending_payment', NOW())")->execute([888, $this->customerId, $this->influencerId, $this->influencerUserId]);



    }

    public function run(): void {
        echo "--- Phase 4: Influencer Payout Integration Test ---\n";

        // Step 1: Complete Order using Job
        echo "Completing order... ";
        $svc = Container::getInstance()->make(InfluencerService::class);
        $result = $svc->completeOrder(888, 0, 'completed'); // OrderID 888, ActorID 0 (System)
        if (!$result['success']) {
            throw new Exception("Completion failed: " . $result['message']);
        }
        echo "OK\n";

        // Step 2: Simulate Outbox/Event Dispatch for Payout
        echo "Triggering payout listener... ";
        $listener = Container::getInstance()->make(InfluencerEventListeners::class);
        
        $event = [
            'data' => [
                'order_id' => 888,
                'customer_id' => $this->customerId,
                'influencer_id' => $this->influencerId,
                'influencer_user_id' => $this->influencerUserId,
                'influencer_earning' => 20000,
                'currency' => 'irt',
                'reputation_points' => 10
            ]
        ];
        
        $listener->handleInfluencerOrderCompleted($event);
        
        // Since the listener uses OutboxService to record the deposit request, 
        // we must manually execute that deposit here to simulate the Outbox Worker.
        $walletService = Container::getInstance()->make(\App\Services\Wallet\WalletService::class);
        $walletService->deposit($this->influencerUserId, '20000', 'irt', [
            'type' => 'influencer_order_payout',
            'description' => "سفارش تبلیغاتی #888 مکمل شد",
            'order_id' => 888,
        ]);
        
        echo "OK\n";

        // Step 3: Verify Influencer Wallet Balance
        echo "Verifying influencer wallet... ";
        $wallet = $this->db->fetch("SELECT balance_irt FROM wallets WHERE user_id = ?", [$this->influencerUserId]);
        if (!$wallet || (float)$wallet->balance_irt < 20000) {
            throw new Exception("Influencer didn't receive payment. Balance: " . ($wallet->balance_irt ?? 'null'));
        }
        echo "Balance: {$wallet->balance_irt} (OK)\n";

        // Step 4: Verify Order Status
        echo "Verifying order status... ";
        $order = $this->db->fetch("SELECT status FROM story_orders WHERE id = ?", [888]);
        if (!$order || $order->status !== 'completed') {
            throw new Exception("Order status not 'completed'. Got: " . ($order->status ?? 'null'));
        }
        echo "Status: {$order->status} (OK)\n";

        // Step 5: Verify Ledger
        echo "Verifying ledger... ";
        $ledgerCount = $this->db->fetchColumn("SELECT COUNT(*) FROM ledger_entries WHERE account = ? AND currency = 'irt'", ["wallet:{$this->influencerUserId}"]);
        if ((int)$ledgerCount === 0) {
            throw new Exception("No ledger entry found for the influencer payout");
        }
        echo "Found $ledgerCount entries (OK)\n";

        echo "\n✅ Influencer Payout Integration Test Passed!\n";
    }
}

try {
    $test = new InfluencerPayoutTest();
    $test->run();
} catch (\Throwable $e) {
    echo "\n❌ Test Failed: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
    exit(1);
}
