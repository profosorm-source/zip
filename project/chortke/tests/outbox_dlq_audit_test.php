<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap/app.php';

use Core\Container;
use Core\Database;
use App\Services\OutboxPublisher;
use App\Services\DlqWorker;
use App\Services\OutboxService;

class OutboxDlqAuditTest {
    private Database $db;
    private OutboxPublisher $publisher;
    private DlqWorker $dlqWorker;
    private OutboxService $outboxService;

    public function __construct() {
        $this->db = Container::getInstance()->make(Database::class);
        $this->publisher = Container::getInstance()->make(OutboxPublisher::class);
        $this->dlqWorker = Container::getInstance()->make(DlqWorker::class);
        $this->outboxService = Container::getInstance()->make(OutboxService::class);
    }

    public function run(): void {
        echo "--- Outbox & DLQ Infrastructure Audit ---\n";
        
        $this->testOutboxFlow();
        $this->testDlqFlow();
        
        echo "\n✅ Outbox & DLQ Audit Passed!\n";
    }

    private function testOutboxFlow(): void {
        echo "Testing Outbox Flow... ";
        
        // Clear outbox
        $this->db->execute("DELETE FROM outbox_events");
        
        // Record an event with a job (which should be pushed to queue)
        $this->outboxService->record('wallet', '123', 'wallet.deposit', [
            'job' => 'App\\Jobs\\SendEmailJob',
            'data' => ['depositId' => 1, 'adminId' => 1]
        ]);
        
        // Publish it
        $res = $this->publisher->publishPending(10);
        
        $status = $this->db->fetchColumn("SELECT status FROM outbox_events LIMIT 1");
        if (!in_array($status, ['published', 'failed', 'dlq'])) {
            throw new Exception("Outbox status should have changed from 'pending'. Got: $status");
        }
        
        echo "OK (Status: $status)\n";
    }

    private function testDlqFlow(): void {
        echo "Testing DLQ Flow... ";
        
        // Clear failed jobs
        $this->db->execute("DELETE FROM failed_jobs");
        $this->db->execute(
            "INSERT INTO failed_jobs (queue, payload, exception, failed_at) VALUES (?, ?, ?, NOW())",
            ['default', json_encode(['job' => 'NonExistentJob', 'data' => []]), 'Some Error']
        );
        
        // Run DLQ worker
        $res = $this->dlqWorker->work(10);
        
        // Failed job should be popped from failed_jobs
        $count = $this->db->fetchColumn("SELECT COUNT(*) FROM failed_jobs");
        if ($count != 0) {
            throw new Exception("Failed job should have been popped and processed by DLQ worker");
        }
        
        echo "OK\n";
    }
}

try {
    $test = new OutboxDlqAuditTest();
    $test->run();
} catch (\Throwable $e) {
    echo "\n❌ Outbox & DLQ Audit Failed: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
    exit(1);
}
