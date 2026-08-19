<?php

declare(strict_types=1);

$port = isset($argv[1]) ? (int)$argv[1] : 8093;
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
