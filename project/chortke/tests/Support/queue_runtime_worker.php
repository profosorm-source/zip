<?php

declare(strict_types=1);

if ($argc !== 3) {
    exit(64);
}

[$script, $queueName, $resultFile] = $argv;
require dirname(__DIR__, 2) . '/bootstrap/testing.php';

try {
    $worker = \Core\Application::getInstance()->container->make(\App\Services\QueueWorker::class);
    $result = $worker->work($queueName, 1, [\Tests\Fixtures\RuntimeProbeJob::class]);
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
