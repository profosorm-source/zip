<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap/app.php';

use Core\Container;
use Core\Database;
use App\Services\Sentry\SentryExceptionHandler;
use App\Services\Sentry\ErrorMonitoring\SentryErrorMonitor;

class SentryMonitoringAuditTest {
    private Database $db;
    private SentryErrorMonitor $errorMonitor;

    public function __construct() {
        $this->db = Container::getInstance()->make(Database::class);
        $this->errorMonitor = Container::getInstance()->make(SentryErrorMonitor::class);
    }

    public function run(): void {
        echo "--- Sentry & Error Monitoring Audit ---\n";
        
        $this->testCaptureException();
        $this->testCaptureMessage();
        $this->testIssueGrouping();
        
        echo "\n✅ Sentry Monitoring Audit Passed!\n";
    }

    private function testCaptureException(): void {
        echo "Testing Capture Exception... ";
        
        $this->db->execute("DELETE FROM sentry_events");
        $this->db->execute("DELETE FROM sentry_issues");
        
        try {
            throw new \RuntimeException("Test Sentry Exception");
        } catch (\RuntimeException $e) {
            $eventId = $this->errorMonitor->captureException($e, [], 'error', 999);
            if (!$eventId) {
                throw new Exception("Failed to capture exception");
            }
        }
        
        $event = $this->db->fetch("SELECT * FROM sentry_events WHERE event_id = ?", [$eventId]);
        if (!$event) {
            throw new Exception("Event not found in database");
        }
        
        if ($event->message !== "Test Sentry Exception") {
            throw new Exception("Incorrect event message. Got: " . $event->message);
        }
        
        echo "OK\n";
    }

    private function testCaptureMessage(): void {
        echo "Testing Capture Message... ";
        
        $eventId = $this->errorMonitor->captureMessage("Test Sentry Message", "info", ['meta' => 'data'], 999);
        if (!$eventId) {
            throw new Exception("Failed to capture message");
        }
        
        $event = $this->db->fetch("SELECT * FROM sentry_events WHERE event_id = ?", [$eventId]);
        if (!$event || $event->message !== "Test Sentry Message") {
            throw new Exception("Message not captured correctly");
        }
        
        echo "OK\n";
    }

    private function testIssueGrouping(): void {
        echo "Testing Issue Grouping... ";
        
        $this->db->execute("DELETE FROM sentry_events");
        $this->db->execute("DELETE FROM sentry_issues");
        
        // Capture the same exception twice
        for ($i = 0; $i < 2; $i++) {
            try {
                throw new \InvalidArgumentException("Same Error");
            } catch (\InvalidArgumentException $e) {
                $this->errorMonitor->captureException($e, [], 'error', 999);
            }
        }
        
        $issueCount = $this->db->fetchColumn("SELECT COUNT(*) FROM sentry_issues");
        if ($issueCount != 1) {
            throw new Exception("Same exceptions should be grouped into one issue. Got: $issueCount");
        }
        
        $eventCount = $this->db->fetchColumn("SELECT COUNT(*) FROM sentry_events");
        if ($eventCount != 2) {
            throw new Exception("Should have 2 event records for the same issue. Got: $eventCount");
        }
        
        echo "OK\n";
    }
}

try {
    $test = new SentryMonitoringAuditTest();
    $test->run();
} catch (\Throwable $e) {
    echo "\n❌ Sentry Monitoring Audit Failed: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
    exit(1);
}
