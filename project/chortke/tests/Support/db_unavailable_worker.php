<?php

declare(strict_types=1);

if ($argc !== 3) exit(64);
$port = $argv[1];
$resultFile = $argv[2];
putenv('CHORTKE_TEST_DB_HOST=127.0.0.1');
putenv('CHORTKE_TEST_DB_PORT=' . $port);
putenv('CHORTKE_TEST_DB_NAME=unreachable_runtime_database');
putenv('CHORTKE_TEST_DB_USER=unreachable_user');
putenv('CHORTKE_TEST_DB_PASS=unreachable_password');

$started = microtime(true);
require dirname(__DIR__, 2) . '/bootstrap/testing.php';

try {
    $database = \Core\Application::getInstance()->container->make(\Core\Database::class);
    $database->getPdo();
    file_put_contents($resultFile, json_encode(['failed_as_expected' => false, 'elapsed' => microtime(true) - $started]));
    exit(1);
} catch (\Throwable $exception) {
    file_put_contents($resultFile, json_encode([
        'failed_as_expected' => true,
        'elapsed' => microtime(true) - $started,
        'exception' => get_class($exception),
        'message' => $exception->getMessage(),
    ], JSON_THROW_ON_ERROR));
    exit(0);
}
