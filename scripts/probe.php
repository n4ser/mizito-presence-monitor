<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}
require_once dirname(__DIR__) . '/src/MizitoSocket.php';
$client = new MizitoSocket(static function (string $line): void { echo $line . "\n"; });
try {
    $client->connect();
    $client->runUntil(microtime(true) + 8, null);
    echo "PASS: secure WebSocket handshake completed (no credentials used)\n";
} catch (Throwable $error) {
    echo "FAIL: connection/handshake did not complete; check hosting network and TLS\n";
    exit(1);
} finally {
    $client->close();
}
