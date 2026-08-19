<?php
/**
 * Professional Database Migration Runner
 * Uses the App\Services\MigrationService to track and execute SQL patches.
 */

declare(strict_types=1);

// Bootstrap the application environment
require_once __DIR__ . '/bootstrap/app.php';

use Core\Container;
use App\Services\MigrationService;

echo "\n=== Chortke Migration Runner ===\n\n";

try {
    $container = Container::getInstance();
    
    // Dynamically build/get MigrationService using current Redis and Database connection
    $manager = new MigrationService(
        $container->make(\Core\Redis::class),
        $container->make(\Core\Database::class),
        $container->make(\App\Contracts\LoggerInterface::class)
    );
    
    echo "Scanning for pending migrations...\n";
    
    // Check if we should show report or run migrations
    if (in_array('--report', $argv)) {
        echo "Report feature migrated to Service dashboard.\n";
        exit(0);
    }

    // Execute the runner
    $result = $manager->runMigrations();
    if (!is_array($result)) throw new \UnexpectedValueException('MigrationService must return an array.');
    $executed = int_value($result['executed'] ?? 0);
    $errors = $result['errors'] ?? [];
    if (!is_array($errors)) throw new \UnexpectedValueException('Migration errors must be an array.');
    $message = str_value($result['message'] ?? 'Migration completed.');
    
    if (!empty($result['errors']) || empty($result['success'])) {
        echo "\n❌ MIGRATION FAILED AFTER EXECUTING {$executed} MIGRATIONS:\n";
        foreach ($errors as $errValue) {
            echo "  - " . str_value($errValue) . "\n";
        }
        exit(1);
    }

    if ($executed > 0) {
        echo "✅ Success: {$message}\n";
    } else {
        echo "ℹ️ Notice: {$message}\n";
    }

    echo "\nDone.\n";

} catch (\Throwable $e) {
    echo "\n❌ FATAL SYSTEM CRASH:\n";
    echo $e->getMessage() . "\n";
    echo $e->getFile() . " line " . $e->getLine() . "\n";
    exit(1);
}
