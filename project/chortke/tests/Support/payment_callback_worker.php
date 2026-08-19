<?php

declare(strict_types=1);

if ($argc !== 5) {
    fwrite(STDERR, "Usage: payment_callback_worker.php <authority> <nonce> <user-id> <result-file>\n");
    exit(64);
}

[$script, $authority, $nonce, $userIdRaw, $resultFile] = $argv;
$userId = (int) $userIdRaw;

require dirname(__DIR__, 2) . '/bootstrap/testing.php';

try {
    $service = \Tests\Fixtures\PaymentRuntimeFactory::make();
    $result = $service->callback('runtime-test', [
        'authority' => $authority,
        'nonce' => $nonce,
        'amount' => '12500.00000000',
        'status' => 'ok',
        'signature' => 'deterministic-signature',
    ], $userId, '127.0.0.1', 'Payment-Concurrent-Worker/1.0');

    file_put_contents($resultFile, json_encode(['ok' => true, 'result' => $result], JSON_THROW_ON_ERROR));
    exit(0);
} catch (\Throwable $exception) {
    file_put_contents($resultFile, json_encode([
        'ok' => false,
        'exception' => get_class($exception),
        'message' => $exception->getMessage(),
        'code' => $exception->getCode(),
    ], JSON_THROW_ON_ERROR));
    exit(1);
}
