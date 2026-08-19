<?php

declare(strict_types=1);

namespace App\Commands;

use App\Contracts\CommandInterface;
use Core\Container;
use Core\Database;

/**
 * Simulate a traceable distributed event (for testing tracing + outbox flow).
 * Usage: php cli.php simulate:traceable-event --type=wallet.transfer --user=5
 */
class SimulateTraceableEventCommand implements CommandInterface
{
    private Database $db;

    private Container $container;

    public function __construct(Container $container)
    {
        $this->container = $container;
    }

    private function getDb(): Database
    {
        if (!isset($this->db)) {
            $resolved = $this->container->make(Database::class);
            if (!$resolved instanceof Database) {
                throw new \RuntimeException("Invalid database instance");
            }
            $this->db = $resolved;
            if (!$this->db instanceof Database) {
                throw new \RuntimeException("Failed to resolve Database");
            }
            if (!$this->db instanceof Database) {
                throw new \RuntimeException('Failed to resolve Database from container');
            }
        }
        return $this->db;
    }

    /** @param array<string, mixed> $args */

    public function run(array $args = []): void
    {
        $typeValue = $args['type'] ?? 'test.event';
        $type = is_string($typeValue) ? $typeValue : 'test.event';
        $userValue = $args['user'] ?? 5;
        $userId = is_numeric($userValue) ? (int)$userValue : 5;

        $correlationId = 'sim-' . bin2hex(random_bytes(8));
        // CLI Command — trace ID already set in Application context

        echo "Simulating traceable event...\n";
        echo "correlation_id: $correlationId\n";
        echo "event_type: $type\n";
        echo "user_id: $userId\n\n";

        // Insert into outbox (this is what real code does)
        $this->getDb()->query(
            "INSERT INTO outbox_events (aggregate_type, aggregate_id, event_type, payload, status, attempts, available_at, created_at, updated_at) 
             VALUES (?, ?, ?, ?, 'pending', 0, NOW(), NOW(), NOW())",
            ['user', (string)$userId, $type, json_encode([
                'user_id' => $userId,
                'correlation_id' => $correlationId,
                'simulated_at' => date('c'),
            ])]
        );

        $id = $this->getDb()->lastInsertId();
        echo "✓ Event inserted into outbox_events (id=$id)\n";
        echo "Now run: php cli.php outbox:publish   (or queue:work if using real queue)\n";
        echo "Then check: php cli.php distributed:health\n";
    }
}