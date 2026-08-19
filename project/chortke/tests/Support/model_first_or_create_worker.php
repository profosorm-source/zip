<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/bootstrap/testing.php';

use Core\Application;
use Core\Database;
use Tests\Fixtures\RuntimeRaceModel;

[$script, $workerId, $readyDirectory, $releaseFile, $resultFile, $email] = $argv + array_fill(0, 6, '');

try {
    $database = Application::getInstance()->container->make(Database::class);
    $model = new RuntimeRaceModel($database);

    file_put_contents($readyDirectory . '/ready-' . $workerId, 'ready', LOCK_EX);
    $deadline = microtime(true) + 15.0;
    while (!is_file($releaseFile)) {
        if (microtime(true) >= $deadline) {
            throw new RuntimeException('Timed out waiting for model race release barrier.');
        }
        usleep(1_000);
    }

    $row = $model->firstOrCreate(
        ['email' => $email],
        ['name' => 'worker-' . $workerId]
    );
    $rowData = get_object_vars($row);
    $line = json_encode(['ok' => true, 'id' => int_value($rowData['id'] ?? 0)], JSON_THROW_ON_ERROR);
} catch (Throwable $exception) {
    $line = json_encode([
        'ok' => false,
        'class' => get_class($exception),
        'message' => $exception->getMessage(),
    ], JSON_THROW_ON_ERROR);
}

file_put_contents($resultFile, $line . PHP_EOL, FILE_APPEND | LOCK_EX);
