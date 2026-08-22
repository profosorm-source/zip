<?php

declare(strict_types=1);

// پورت باید صریحاً از سوی تست داده شود؛ پیش‌فرضِ ثابت باعث برخورد با
// شنونده‌های باقی‌مانده و شکست‌های تصادفی می‌شد.
$port = isset($argv[1]) ? (int)$argv[1] : 0;
if ($port <= 0) {
    fwrite(STDERR, "usage: hanging_redis_server.php <port>\n");
    exit(2);
}
$server = stream_socket_server("tcp://0.0.0.0:{$port}", $errno, $error);
if (!$server) {
    fwrite(STDERR, "server failed: {$error}\n");
    exit(1);
}
while ($connection = @stream_socket_accept($server, 10)) {
    fwrite(STDERR, "accepted " . microtime(true) . "\n");
    // Accept TCP but never answer the Redis protocol. This simulates a node
    // whose socket is reachable while the event loop is stalled.
    usleep(5_000_000);
    fclose($connection);
}
fclose($server);
