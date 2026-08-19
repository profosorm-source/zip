<?php

declare(strict_types=1);

if ($argc !== 3) exit(64);
$queueName = $argv[1];
$reservedFile = $argv[2];
require dirname(__DIR__, 2) . '/bootstrap/testing.php';

$queue = \Core\Application::getInstance()->container->make(\Core\Queue::class);
$job = $queue->pop($queueName);
if (!$job) {
    file_put_contents($reservedFile, json_encode(['ok' => false, 'message' => 'no job']));
    exit(1);
}
file_put_contents($reservedFile, json_encode(['ok' => true, 'job' => $job], JSON_THROW_ON_ERROR));
// Parent sends SIGKILL here. No delete/release/finally hook can run.
sleep(30);
exit(2);
