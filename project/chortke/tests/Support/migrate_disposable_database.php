<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/bootstrap/testing.php';

try {
    $container = \Core\Application::getInstance()->container;
    $migration = $container->make(\App\Services\MigrationService::class);
    $result = $migration->runMigrations();
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), "\n";
    exit(!empty($result['success']) || empty($result['errors']) ? 0 : 1);
} catch (\Throwable $exception) {
    fwrite(STDERR, get_class($exception) . ': ' . $exception->getMessage() . "\n");
    exit(1);
}
