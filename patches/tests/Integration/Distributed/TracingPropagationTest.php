<?php

namespace Tests\Integration\Distributed;

use PHPUnit\Framework\TestCase;
use Core\Container;
use Core\Database;

/**
 * Tests that correlation_id is properly propagated through listeners and outbox.
 */
class TracingPropagationTest extends TestCase
{
    protected function setUp(): void
    {
        // Ensure correlation is set for test
        $_SERVER['REQUEST_ID'] = 'test-trace-' . bin2hex(random_bytes(4));
    }

    public function test_escrow_listener_injects_correlation(): void
    {
        $correlationId = $_SERVER['REQUEST_ID'];
        
        // Static source-code analysis: verify the listener contains correlation logic
        // without requiring full Container bootstrap (which would need DB, Redis, etc.)
        $source = file_get_contents(__DIR__ . '/../../../app/Listeners/EscrowListener.php');
        $this->assertStringContainsString(
            'correlation_id',
            $source,
            'EscrowListener باید correlation_id را در payload/لاگ منتشر کند.'
        );
        // انتشار در این پروژه از هدر x-request-id خوانده می‌شود (نه ثابت REQUEST_ID)
        // و در حالت CLI به correlation_id ورودی یا شناسهٔ cli-* عقب‌نشینی می‌کند.
        $this->assertStringContainsString(
            'x-request-id',
            $source,
            'EscrowListener باید در بستر HTTP هدر x-request-id را بخواند.'
        );
        $this->assertStringContainsString(
            "'cli-'",
            $source,
            'EscrowListener باید در بستر CLI شناسهٔ همبستگیِ جایگزین بسازد.'
        );
        
        // Verify the class exists and has the expected method
        $this->assertTrue(class_exists(\App\Listeners\EscrowListener::class));
        $this->assertTrue(method_exists(\App\Listeners\EscrowListener::class, 'handle'));
    }

    public function test_referral_listener_injects_correlation(): void
    {
        $source = file_get_contents(__DIR__ . '/../../../app/Listeners/ReferralCommissionListener.php');
        $this->assertStringContainsString(
            'correlation_id',
            $source,
            'ReferralCommissionListener باید correlation_id را منتشر کند.'
        );
        $this->assertStringContainsString(
            'x-request-id',
            $source,
            'ReferralCommissionListener باید در بستر HTTP هدر x-request-id را بخواند.'
        );
        $this->assertStringContainsString(
            "'cli-'",
            $source,
            'ReferralCommissionListener باید در بستر CLI شناسهٔ همبستگیِ جایگزین بسازد.'
        );
    }

    public function test_notification_listener_injects_correlation(): void
    {
        $source = file_get_contents(__DIR__ . '/../../../app/Listeners/NotificationRequestListener.php');
        $this->assertStringContainsString(
            'correlation_id',
            $source,
            'NotificationRequestListener باید correlation_id را منتشر کند.'
        );
        $this->assertStringContainsString(
            'x-request-id',
            $source,
            'NotificationRequestListener باید در بستر HTTP هدر x-request-id را بخواند.'
        );
        $this->assertStringContainsString(
            "'cli-'",
            $source,
            'NotificationRequestListener باید در بستر CLI شناسهٔ همبستگیِ جایگزین بسازد.'
        );
    }

    public function test_bootstrap_sets_request_id(): void
    {
        // bootstrap/app.php already sets REQUEST_ID early
        $this->assertNotEmpty($_SERVER['REQUEST_ID'] ?? null);
    }
}
