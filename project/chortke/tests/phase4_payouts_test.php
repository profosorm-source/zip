<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap/app.php';

use Core\Container;
use Core\Database;
use App\Models\SocialTaskModel;
use App\Models\SocialTaskExecutionModel;
use App\Jobs\SocialTask\ApproveSocialTaskExecutionJob;
use App\Listeners\SocialTaskEventListeners;
use Core\Event;

class PayoutIntegrationTest {
    private Database $db;
    private SocialTaskModel $taskModel;
    private SocialTaskExecutionModel $execModel;
    private int $advertiserId = 88888;
    private int $executorId = 77777;

    public function __construct() {
        $this->db = Container::getInstance()->make(Database::class);
        // SocialTaskModel requires ExecutionModel and AnalyticsModel
        $this->execModel = Container::getInstance()->make(SocialTaskExecutionModel::class);
        $analyticsModel = Container::getInstance()->make(\App\Models\SocialTaskAnalyticsModel::class);
        $this->taskModel = new SocialTaskModel($this->db, $this->execModel, $analyticsModel);
        
        $this->setupTestData();
    }

    private function setupTestData(): void {
        // 1. Create Users
        $this->db->prepare("INSERT IGNORE INTO users (id, email, full_name, password, created_at) VALUES (?, 'adv@test.com', 'Advertiser', 'pass', NOW())")->execute([$this->advertiserId]);
        $this->db->prepare("INSERT IGNORE INTO users (id, email, full_name, password, created_at) VALUES (?, 'exe@test.com', 'Executor', 'pass', NOW())")->execute([$this->executorId]);
        
        // 2. Create Wallets
        $this->db->prepare("INSERT IGNORE INTO wallets (user_id, balance_irt, created_at) VALUES (?, 1000000, NOW())")->execute([$this->advertiserId]);
        $this->db->prepare("INSERT IGNORE INTO wallets (user_id, balance_irt, created_at) VALUES (?, 0, NOW())")->execute([$this->executorId]);
        
        // 3. Create a Social Task Ad
        $this->db->prepare("DELETE FROM ads WHERE id = 999")->execute();
        $this->db->prepare("INSERT INTO ads (id, user_id, type, title, platform, task_type, price_per_task, total_count, completed_count, status, created_at) VALUES (?, ?, 'social', 'Test Task', 'youtube', 'subscribe', 5000, 10, 0, 'active', NOW())")->execute([999, $this->advertiserId]);

        
        // 4. Clear old executions
        $this->db->prepare("DELETE FROM social_task_executions WHERE executor_id = ?")->execute([$this->executorId]);
    }

    public function run(): void {
        echo "--- Phase 4: Social Task Payout Integration Test ---\n";

        // Step 1: Create Execution
        echo "Creating execution... ";
        $execId = $this->execModel->createExecution([
            'ad_id' => 999,
            'executor_id' => $this->executorId,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'TestAgent',
            'expected_time' => 60
        ]);
        echo "Created ID: $execId\n";
        $taskAd = $this->taskModel->getAdById(999);
        if ($taskAd === null) {
            throw new Exception("Social task ad 999 was not loaded through SocialTaskModel");
        }

        // Step 2: Move to 'submitted' status (pending -> started -> submitted)
        echo "Updating execution status... ";
        $this->execModel->updateExecutionStatus($execId, 'started');
        $this->execModel->updateExecutionStatus($execId, 'submitted');
        echo "OK\n";

        // Step 3: Approve Execution using Job
        echo "Approving execution... ";
        $job = Container::getInstance()->make(ApproveSocialTaskExecutionJob::class);
        $result = $job->handle($this->advertiserId, $execId);
        if (!$result['success']) {
            throw new Exception("Approval failed: " . $result['message']);
        }
        echo "OK\n";

        // Step 4: Simulate Outbox/Event Dispatch for Payout
        echo "Triggering payout listener... ";
        $listener = Container::getInstance()->make(SocialTaskEventListeners::class);
        
        // The event data as it would be produced by ApproveSocialTaskExecutionJob
        $eventData = [
            'user_id' => $this->executorId,
            'reward_amount' => 5000,
            'currency' => 'irt',
            'execution_id' => $execId,
            'ad_id' => 999,
            'decision' => 'approved'
        ];
        
        // Create a concrete Event class since Core\Event is abstract
        $event = new class($eventData) extends \Core\Event {
            public function __construct($data) { parent::__construct($data); }
        };

        
        $listener->handleRewardApproved($event);
        echo "OK\n";

        // Step 5: Verify Wallet Balance
        echo "Verifying wallet balance... ";
        $wallet = $this->db->fetch("SELECT balance_irt FROM wallets WHERE user_id = ?", [$this->executorId]);
        if (!$wallet || (float)$wallet->balance_irt < 5000) {
            throw new Exception("Payment not received. Balance: " . ($wallet->balance_irt ?? 'null'));
        }
        echo "Balance: {$wallet->balance_irt} (OK)\n";

        // Step 6: Verify Ledger Entries
        echo "Verifying ledger... ";
        $ledgerCount = $this->db->fetchColumn("SELECT COUNT(*) FROM ledger_entries WHERE account = ? AND currency = 'irt'", ["wallet:{$this->executorId}"]);
        if ((int)$ledgerCount === 0) {
            throw new Exception("No ledger entry found for the payout");
        }
        echo "Found $ledgerCount entries (OK)\n";

        echo "\n✅ Payout Integration Test Passed!\n";
    }
}

try {
    $test = new PayoutIntegrationTest();
    $test->run();
} catch (\Throwable $e) {
    echo "\n❌ Test Failed: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
    exit(1);
}
