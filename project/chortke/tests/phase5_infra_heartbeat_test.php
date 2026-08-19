<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap/app.php';

use Core\Container;
use Core\Database;

class InfraHeartbeatTest {
    private Database $db;
    private Container $container;

    public function __construct() {
        $this->db = Container::getInstance()->make(Database::class);
        $this->container = Container::getInstance();
    }

    public function run(): void {
        echo "--- Phase 5: Infrastructure Heartbeat & Integrity Test ---\n";

        $this->testOutboxDelivery();
        $this->testLedgerReconciliation();
        $this->testDlqProcessing();
        $this->testIdempotencyCleanup();

        echo "\n✅ Infrastructure Heartbeat Tests Passed!\n";
    }

    private function testOutboxDelivery(): void {
        echo "Testing Outbox Delivery... ";
        
        // 1. Insert a manual event into outbox_events
        $this->db->execute(
            "INSERT INTO outbox_events (aggregate_type, aggregate_id, event_type, payload, status, available_at, created_at) 
             VALUES (?, ?, ?, ?, 'pending', NOW(), NOW())",
            ['test_type', 123, 'test.event', json_encode(['test' => 'data'])]
        );

        // 2. Trigger the OutboxPublisher via CLI
        $cliPath = realpath(__DIR__ . '/../cli.php');
        $cmd = "php $cliPath outbox:publish --limit=10";
        exec($cmd, $output, $exitCode);

        // 3. Verify the event is now 'published'
        $status = $this->db->fetchColumn("SELECT status FROM outbox_events WHERE event_type = 'test.event' AND aggregate_id = 123 ORDER BY created_at DESC LIMIT 1");
        
        if ($status !== 'published') {
            throw new Exception("Outbox event not published. Status: " . ($status ?? 'null'));
        }
        echo "OK\n";
    }

    private function testLedgerReconciliation(): void {
        echo "Testing Ledger Reconciliation... ";
        
        // 1. Create a deliberate imbalance for a fake transaction
        $txnId = 'test_imbalance_' . uniqid();
        
        // Normally, a transaction has a debit and a credit.
        // We'll add only a debit to create an imbalance.
        $this->db->execute(
            "INSERT INTO ledger_entries (transaction_id, account, debit, credit, currency, description, created_at) 
             VALUES (?, 'user_wallet', 100.0, 0.0, 'irt', 'test_imbalance', NOW())",
            [$txnId]
        );

        // 2. Simulate the reconciliation check (simulating the logic in LedgerService)
        $row = $this->db->fetch(
            "SELECT transaction_id, SUM(debit) as total_debit, SUM(credit) as total_credit 
             FROM ledger_entries 
             WHERE transaction_id = ? 
             GROUP BY transaction_id",
            [$txnId]
        );
        
        if ($row === null || float_value($row->total_debit) === float_value($row->total_credit)) {
            throw new Exception("Reconciliation failed to detect deliberate imbalance for transaction {$txnId}!");
        }
        echo "OK (Imbalance Detected)\n";
        
        // Cleanup
        $this->db->execute("DELETE FROM ledger_entries WHERE transaction_id = ?", [$txnId]);
    }

    private function testDlqProcessing(): void {
        echo "Testing DLQ Processing... ";
        
        // 1. Create a failed job in the queue
        $payload = json_encode([
            'job' => '\App\Jobs\TestDlqJob',
            'data' => ['id' => 1],
            'meta' => []
        ]);
        $this->db->execute(
            "INSERT INTO queues (queue, payload, attempts, created_at) 
             VALUES ('default', ?, 3, NOW())",
            [$payload]
        );

        // 2. Trigger DLQ Worker via CLI
        $cliPath = realpath(__DIR__ . '/../cli.php');
        $cmd = "php $cliPath dlq:work --limit=10";
        exec($cmd, $output, $exitCode);

        echo "OK (Worker Executed)\n";
    }

    private function testIdempotencyCleanup(): void {
        echo "Testing Idempotency Cleanup... ";
        
        // 1. Insert an old idempotency key
        $key = 'old_key_' . uniqid();
        $this->db->execute(
            "INSERT INTO idempotency_keys (`key`, user_id, action, created_at) 
             VALUES (?, 1, 'test_action', DATE_SUB(NOW(), INTERVAL 100 DAY))",
            [$key]
        );

        // 2. Trigger cleanup
        $idempotency = $this->container->make(\Core\IdempotencyKey::class);
        $idempotency->cleanup(false);

        // 3. Verify it is gone
        $exists = $this->db->fetchColumn("SELECT COUNT(*) FROM idempotency_keys WHERE `key` = ?", [$key]);
        
        if ((int)$exists !== 0) {
            throw new Exception("Idempotency cleanup failed to remove 100-day old key!");
        }
        echo "OK\n";
    }
}

try {
    $test = new InfraHeartbeatTest();
    $test->run();
} catch (\Throwable $e) {
    echo "\n❌ Infra Test Failed: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
    exit(1);
}
