<?php

namespace Tests\Integration\Distributed;

use PHPUnit\Framework\TestCase;

/**
 * Smoke tests for the background worker commands (queue/outbox/dlq).
 * These run the actual CLI commands and assert they don't fatal.
 */
class WorkerLifecycleTest extends TestCase
{
    public function test_queue_work_command_starts_without_fatal(): void
    {
        $output = $this->runCommand("cd " . __DIR__ . "/../../../ && timeout 4 php cli.php queue:work --stop-when-empty --max-time=3 2>&1 || true");
        $this->assertStringNotContainsString('Fatal error', $output);
        $this->assertStringNotContainsString('must implement run', $output);
    }

    public function test_outbox_publish_command_runs(): void
    {
        $output = $this->runCommand("cd " . __DIR__ . "/../../../ && php cli.php outbox:publish --limit=5 2>&1");
        $this->assertStringNotContainsString('Fatal error', $output);
        $this->assertStringNotContainsString('must implement run', $output);
    }

    public function test_dlq_work_command_runs(): void
    {
        $output = $this->runCommand("cd " . __DIR__ . "/../../../ && php cli.php dlq:work --limit=5 2>&1 || true");
        $this->assertStringNotContainsString('Fatal error', $output);
    }

    public function test_distributed_health_command_runs(): void
    {
        $output = $this->runCommand("cd " . __DIR__ . "/../../../ && php cli.php distributed:health 2>&1");
        if (str_contains($output, 'not found')) {
            $this->fail('distributed:health CLI command not available');
        }
        $this->assertStringNotContainsString('Fatal error', $output);
        $this->assertStringContainsString('Outbox', $output);
    }
    private function runCommand(string $command): string
    {
        $output = shell_exec($command);
        $this->assertIsString($output, "Command produced no output: {$command}");
        return $output;
    }

}
