<?php

declare(strict_types=1);

if ($argc !== 3) exit(64);
$port = $argv[1];
$resultFile = $argv[2];
putenv('CHORTKE_TEST_REDIS_HOST=127.0.0.1');
putenv('CHORTKE_TEST_REDIS_PORT=' . $port);
putenv('CHORTKE_TEST_REDIS_PASSWORD=');
putenv('CHORTKE_TEST_REDIS_TIMEOUT=0.5');

$started = microtime(true);
require dirname(__DIR__, 2) . '/bootstrap/testing.php';
$redis = \Core\Application::getInstance()->container->make(\Core\Redis::class);
file_put_contents($resultFile, json_encode([
    'available' => $redis->isAvailable(),
    'elapsed' => microtime(true) - $started,
], JSON_THROW_ON_ERROR));
exit(0);
