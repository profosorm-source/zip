<?php

declare(strict_types=1);

namespace App\Commands;

use App\Contracts\LoggerInterface;
use App\Services\Shared\IdempotencyService;

/**
 * CLI wrapper برای مدیریت جدول idempotency_keys (Section 8.2).
 *
 * MIGRATION: از Core\IdempotencyKey به App\Services\Shared\IdempotencyService
 *
 * Usage:
 *   php cli.php idempotency:stats
 *   php cli.php idempotency:cleanup [--dry-run]
 */
class IdempotencyCommand
{
    private IdempotencyService $idempotency;
    private LoggerInterface $logger;

    public function __construct(
        IdempotencyService $idempotency,
        LoggerInterface $logger
    ) {
        $this->idempotency = $idempotency;
        $this->logger      = $logger;
    }

    /** @param array<string, mixed> $argv */

    public function run(array $argv): void
    {
        $command = $argv[1] ?? 'idempotency:stats';
        $opts    = $this->parseOptions($argv);

        switch ($command) {
            case 'idempotency:stats':
                $this->stats();
                return;
            case 'idempotency:cleanup':
                $this->cleanup((bool)($opts['dry-run'] ?? false));
                return;
            default:
                throw new \InvalidArgumentException('Unsupported idempotency command: ' . $command);
        }
    }

    private function stats(): void
    {
        $stats = $this->idempotency->getStats();
        if (isset($stats['error'])) {
            fwrite(STDERR, "[idempotency:stats] error: " . str_value($stats['error']) . "\n");
            exit(1);
        }
        echo "Total idempotency keys: " . int_value($stats['total'] ?? 0) . "\n";
        $byStatus = $stats['by_status'] ?? [];
        if (!empty($byStatus)) {
            echo "By status:\n";
            foreach ((array)$byStatus as $status => $rowValue) {
                if (!is_array($rowValue)) throw new \UnexpectedValueException('Idempotency status statistics must be arrays.');
                $row = $rowValue;
                echo sprintf(
                    "  %-12s count=%-8d  last_hour=%-6d  last_24h=%-6d\n",
                    (string)$status,
                    int_value($row['count'] ?? 0),
                    int_value($row['last_hour'] ?? 0),
                    int_value($row['last_24h'] ?? 0)
                );
            }
        }
    }

    private function cleanup(bool $dryRun): void
    {
        $count = $this->idempotency->cleanup($dryRun);
        echo $dryRun
            ? "[idempotency:cleanup] dry-run: {$count} rows would be deleted.\n"
            : "[idempotency:cleanup] deleted={$count}\n";
        $this->logger->info('idempotency.cli.cleanup', [
            'dry_run' => $dryRun,
            'count'   => $count,
        ]);
    }

    /**
     * @param array<int|string, mixed> $argv
     * @return array<string, mixed>
     */
    private function parseOptions(array $argv): array
    {
        $opts  = [];
        $count = count($argv);
        for ($i = 2; $i < $count; $i++) {
            $arg = is_scalar($argv[$i]) ? (string)$argv[$i] : '';
            if (!str_starts_with($arg, '--')) {
                continue;
            }
            $arg = substr($arg, 2);
            if (str_contains($arg, '=')) {
                [$k, $v] = explode('=', $arg, 2);
                $opts[$k] = trim($v, "\"' ");
            } else {
                $opts[$arg] = true;
            }
        }
        return $opts;
    }
}
