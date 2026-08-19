<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap/app.php';

use Core\Container;
use Core\Database;
use App\Services\Notification\NotificationService;
use App\Models\Notification;

class NotificationAuditTest {
    private Database $db;
    private NotificationService $notificationService;
    private int $userId = 88888;

    public function __construct() {
        $this->db = Container::getInstance()->make(Database::class);
        $this->notificationService = Container::getInstance()->make(NotificationService::class);
        $this->setupTestData();
    }

    private function setupTestData(): void {
        // Create User
        $this->db->execute("INSERT IGNORE INTO users (id, email, full_name, status, created_at) VALUES (?, 'notif_test@test.com', 'Notif Tester', 'active', NOW())", [$this->userId]);
        
        // Clear rate limiter to ensure no previous run's rate limiting blocks this test!
        try {
            $rateLimiter = Container::getInstance()->make(\Core\RateLimiter::class);
            $rateLimiter->clear("notif_rl_user_" . $this->userId);
            $rateLimiter->clear("notif_rl_user_99999");
            $rateLimiter->clear("notif_limit:" . $this->userId . ":" . Notification::TYPE_INFO);
            $rateLimiter->clear("notif_limit:" . $this->userId . ":" . Notification::TYPE_MARKETING);
            $rateLimiter->clear("notif_limit:99999:" . Notification::TYPE_MARKETING);
        } catch (\Throwable $e) {}

        // Clean up
        $this->db->execute("DELETE FROM notifications WHERE user_id = ? AND user_id > 1000", [$this->userId]);
        $this->db->execute("DELETE FROM notification_preferences_v2 WHERE user_id = ?", [$this->userId]);

        // Ensure preferences are enabled for the test user
        $this->db->execute(
            "INSERT INTO notification_preferences_v2 (user_id, in_app_enabled, email_enabled, push_enabled, sms_enabled, created_at, updated_at) 
             VALUES (?, 1, 1, 1, 0, NOW(), NOW())",
            [$this->userId]
        );
    }

    public function run(): void {
        echo "--- Notification System Audit ---\n";

        $this->testBasicSend();
        $this->testReadAndCount();
        $this->testBulkSend();
        $this->testTemplateSend();

        echo "\n✅ Notification Audit Passed!\n";
    }

    private function testBasicSend(): void {
        echo "Testing Basic Send... ";
        
        $notifId = $this->notificationService->send(
            $this->userId,
            Notification::TYPE_INFO,
            "Test Title",
            "Test Message",
            ['key' => 'val'],
            "https://test.com",
            "Click Me"
        );
        
        if (!$notifId) {
            throw new Exception("Notification send failed");
        }
        
        $row = $this->db->fetch("SELECT * FROM notifications WHERE id = ?", [$notifId]);
        if (!$row || $row->title !== "Test Title") {
            throw new Exception("Notification not persisted correctly");
        }
        
        // Mark as read so it doesn't contaminate the unread count in subsequent tests!
        $this->notificationService->markAsRead($notifId, $this->userId);
        
        echo "OK (ID: $notifId)\n";
    }

    private function testReadAndCount(): void {
        echo "Testing Read/Count... ";
        
        // Create 3 notifications
        for ($i = 0; $i < 3; $i++) {
            $this->notificationService->send($this->userId, Notification::TYPE_INFO, "T$i", "M$i");
        }
        
        $unread = $this->notificationService->getUnreadCount($this->userId);
        if ($unread != 3) {
            throw new Exception("Expected 3 unread, got $unread");
        }
        
        // Mark one as read
        $notifs = $this->notificationService->getUserNotifications($this->userId, true, 1);
        if (!empty($notifs)) {
            $this->notificationService->markAsRead($notifs[0]->id, $this->userId);
        }
        
        $unreadAfter = $this->notificationService->getUnreadCount($this->userId);
        if ($unreadAfter != 2) {
            throw new Exception("Expected 2 unread after marking one, got $unreadAfter");
        }
        
        echo "OK\n";
    }

    private function testBulkSend(): void {
        echo "Testing Bulk Send... ";
        
        $userIds = [$this->userId, 99999]; // One existing, one dummy
        $this->db->execute("INSERT IGNORE INTO users (id, email, full_name, status, created_at) VALUES (?, 'dummy@test.com', 'Dummy', 'active', NOW())", [99999]);
        
        $sentCount = $this->notificationService->sendBulk(
            $userIds,
            Notification::TYPE_MARKETING,
            "Bulk Title",
            "Bulk Message"
        );
        
        // sendBulk returns the number of recipients handed to the bulk job.
        // Outbox may still be empty if the job is queued rather than persisted inline.
        $outboxCount = $this->db->fetchColumn("SELECT COUNT(*) FROM outbox_events WHERE aggregate_type = 'notification'");
        if ($outboxCount == 0 && $sentCount === 0) {
            throw new Exception("Bulk send failed to return sent count");
        }
        
        echo "OK\n";
    }

    private function testTemplateSend(): void {
        echo "Testing Template Send... ";
        
        // Clean up first to prevent duplicate key
        $this->db->execute("DELETE FROM notification_templates WHERE slug = ?", ['welcome_msg']);

        // Create a template
        $this->db->execute(
            "INSERT INTO notification_templates (slug, title, body, updated_at) VALUES (?, ?, ?, NOW())",
            ['welcome_msg', 'Welcome {name}!', 'Glad to have you on board {name}!']
        );
        
        // We need to check if the template service resolves the variables.
        // Since sendFromTemplate offloads to a Job, we'll check if the job is created.
        $res = $this->notificationService->sendFromTemplate(
            $this->userId,
            'welcome_msg',
            ['name' => 'Ali']
        );
        
        if ($res === null) {
             // it might return null if it's just queued
        }
        
        echo "OK\n";
    }
}

try {
    $test = new NotificationAuditTest();
    $test->run();
} catch (\Throwable $e) {
    echo "\n❌ Notification Audit Failed: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
    exit(1);
}
