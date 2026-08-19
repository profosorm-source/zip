<?php

declare(strict_types=1);

if ($argc !== 6) exit(64);
[$script, $firstIdRaw, $secondIdRaw, $workerName, $barrierDir, $resultFile] = $argv;
$firstId = (int)$firstIdRaw;
$secondId = (int)$secondIdRaw;
require dirname(__DIR__, 2) . '/bootstrap/testing.php';

$attempts = 0;
$started = microtime(true);
try {
    $container = \Core\Application::getInstance()->container;
    $wrapper = new \Core\TransactionWrapper($container->make(\Core\Database::class));
    $result = $wrapper->runWithRetry(function (\Core\Database $db) use ($firstId, $secondId, $workerName, $barrierDir, &$attempts): string {
        $attempts++;
        $db->fetch('SELECT id FROM chaos_deadlock_rows WHERE id=? FOR UPDATE', [$firstId]);

        if ($attempts === 1) {
            file_put_contents($barrierDir . '/ready-' . $workerName, '1');
            $deadline = microtime(true) + 5.0;
            while (count(glob($barrierDir . '/ready-*') ?: []) < 2) {
                if (microtime(true) >= $deadline) {
                    throw new \RuntimeException('Deadlock barrier timeout');
                }
                usleep(10_000);
            }
            usleep(100_000);
        }

        $db->execute('UPDATE chaos_deadlock_rows SET value=value+1 WHERE id=?', [$secondId]);
        $db->execute('UPDATE chaos_deadlock_rows SET value=value+1 WHERE id=?', [$firstId]);
        return 'committed';
    }, 3);

    file_put_contents($resultFile, json_encode([
        'ok' => true,
        'result' => $result,
        'attempts' => $attempts,
        'elapsed' => microtime(true) - $started,
    ], JSON_THROW_ON_ERROR));
    exit(0);
} catch (\Throwable $exception) {
    file_put_contents($resultFile, json_encode([
        'ok' => false,
        'attempts' => $attempts,
        'exception' => get_class($exception),
        'message' => $exception->getMessage(),
    ], JSON_THROW_ON_ERROR));
    exit(1);
}
