<?php

declare(strict_types=1);

if ($argc !== 2) {
    exit(64);
}

$resultFile = $argv[1];
require dirname(__DIR__, 2) . '/bootstrap/testing.php';

try {
    $publisher = \Core\Application::getInstance()->container->make(\App\Services\OutboxPublisher::class);
    $result = $publisher->publishPending(1);
    file_put_contents($resultFile, json_encode(['ok' => true, 'result' => $result], JSON_THROW_ON_ERROR));
    exit(0);
} catch (\Throwable $exception) {
    file_put_contents($resultFile, json_encode([
        'ok' => false,
        'exception' => get_class($exception),
        'message' => $exception->getMessage(),
    ], JSON_THROW_ON_ERROR));
    exit(1);
}
