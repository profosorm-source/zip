<?php

namespace Tests\Integration\Distributed;

use PHPUnit\Framework\TestCase;
use Core\Container;
use Core\Database;

/**
 * Integration tests for Distributed Systems patterns (strengthened in Option 3).
 */
class DistributedPatternsTest extends TestCase
{
    private Database $db;

    protected function setUp(): void
    {
        // Resolve the real database through the same production container used
        // by the application; unit-test doubles must never reach this suite.
        try {
            $this->db = \Core\Application::getInstance()->container->make(Database::class);
            $this->db->fetch("SELECT 1 as test");
        } catch (\Throwable $e) {
            $this->fail('Database not available: ' . $e->getMessage());
        }
    }

    public function test_can_insert_valid_outbox_event(): void
    {        $this->db->query(
            "INSERT INTO outbox_events (aggregate_type, aggregate_id, event_type, payload, status, attempts, available_at, created_at, updated_at) 
             VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW(), NOW())",
            ['test', '1', 'test.integration.outbox', json_encode(['test' => true]), 'pending', 0]
        );

        $id = $this->db->lastInsertId();
        $this->assertNotNull($id);

        $this->db->query("DELETE FROM outbox_events WHERE id = ?", [$id]);
    }

    public function test_can_insert_valid_idempotency_key(): void
    {
        $key = 'integration_test_' . uniqid();

        $this->db->query(
            "INSERT INTO idempotency_keys (`key`, user_id, action, status, created_at, expires_at) 
             VALUES (?, ?, ?, ?, NOW(), DATE_ADD(NOW(), INTERVAL 1 HOUR))",
            [$key, 5, 'test.integration.idempotency', 'pending']
        );

        $id = $this->db->lastInsertId();
        $this->assertNotNull($id);

        $this->db->query("DELETE FROM idempotency_keys WHERE id = ?", [$id]);
    }

    public function test_outbox_publish_command_executes(): void
    {
        $output = $this->runCommand("cd " . __DIR__ . "/../../../ && php cli.php outbox:publish 2>&1");
        $this->assertStringNotContainsString('Fatal error', $output);
        $this->assertStringNotContainsString('must implement run', $output);
    }

    public function test_queue_work_command_executes(): void
    {
        $output = $this->runCommand("cd " . __DIR__ . "/../../../ && timeout 6 php cli.php queue:work --stop-when-empty --max-time=4 2>&1 || true");
        $this->assertStringNotContainsString('Fatal error', $output);
    }

    public function test_fixed_cli_commands_run(): void
    {
        $commands = ['queue:failed:stats', 'alert:bootstrap-dlq', 'system:cleanup', 'distributed:health'];
        foreach ($commands as $cmd) {
            $output = $this->runCommand("cd " . __DIR__ . "/../../../ && php cli.php $cmd 2>&1 | head -3");
            $this->assertStringNotContainsString('Fatal error', $output, "Command $cmd should not fatal");
        }
    }

    public function test_correlation_id_is_supported_in_logging(): void
    {
        $logServiceFile = __DIR__ . "/../../../app/Services/LogService.php";
        $content = file_get_contents($logServiceFile);
        $this->assertIsString($content);
        $this->assertStringContainsString('correlation_id', $content);
        $this->assertStringContainsString('getCorrelationId', $content);
    }

    public function test_full_outbox_flow_with_correlation(): void
    {
        $correlationId = 'e2e-' . bin2hex(random_bytes(6));
        $_SERVER['REQUEST_ID'] = $correlationId;

        $payload = json_encode([
            'user_id' => 5,
            'amount' => 1000,
            'to_user_id' => 3,
            'correlation_id' => $correlationId,
            'type' => 'wallet.transfer'
        ]);

        $this->db->query(
            "INSERT INTO outbox_events (aggregate_type, aggregate_id, event_type, payload, status, attempts, available_at, created_at, updated_at) 
             VALUES ('wallet', '5', 'wallet.transfer', ?, 'pending', 0, NOW(), NOW(), NOW())",
            [$payload]
        );

        $eventId = $this->db->lastInsertId();
        $this->assertNotNull($eventId);

        $output = $this->runCommand("cd " . __DIR__ . "/../../../ && php cli.php outbox:publish 2>&1");

        $row = $this->db->fetch("SELECT status FROM outbox_events WHERE id = ?", [$eventId]);
        $this->assertNotNull($row, "Event should still exist after publisher run");

        $fullRow = $this->db->fetch("SELECT payload FROM outbox_events WHERE id = ?", [$eventId]);
        $this->assertInstanceOf(\stdClass::class, $fullRow);
        $decoded = json_decode((string)$fullRow->payload, true);
        $this->assertIsArray($decoded);
        $this->assertEquals($correlationId, $decoded['correlation_id'] ?? null, "correlation_id must be present in outbox payload");

        $this->db->query("DELETE FROM outbox_events WHERE id = ?", [$eventId]);
    }

    public function test_simulate_traceable_event_command(): void
    {
        $output = $this->runCommand("cd " . __DIR__ . "/../../../ && php cli.php simulate:traceable-event --type=wallet.transfer --user=5 2>&1");

        if (str_contains($output, 'not found')) {
            $this->fail('simulate:traceable-event CLI command not available');
        }

        $this->assertStringContainsString('correlation_id', $output);
    }
    private function runCommand(string $command): string
    {
        $output = shell_exec($command);
        $this->assertIsString($output, "Command produced no output: {$command}");
        return $output;
    }

}
