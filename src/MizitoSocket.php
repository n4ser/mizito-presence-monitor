<?php
declare(strict_types=1);

/** Minimal Engine.IO v4 + Socket.IO v5 websocket client for Mizito.
 *  No third-party packages. Does not print tokens or server messages.
 */
final class MizitoSocket {
    private $socket = null;
    private string $buffer = '';
    private bool $namespaceConnected = false;
    private bool $authSent = false;
    private float $authSentAt = 0.0;
    private float $lastPacket = 0;
    private int $pingIntervalMs = 25000;
    private int $pingTimeoutMs = 20000;
    private $logger;

    public function __construct(callable $logger) { $this->logger = $logger; }
    private function log(string $message): void { ($this->logger)($message); }

    public function connect(): void {
        $this->close();
        $ctx = stream_context_create(['ssl' => [
            'verify_peer' => true, 'verify_peer_name' => true,
            'peer_name' => 'service.mizito.ir', 'SNI_enabled' => true,
        ]]);
        $errno = 0; $errstr = '';
        $sock = @stream_socket_client(
            'tls://service.mizito.ir:443', $errno, $errstr, 12,
            STREAM_CLIENT_CONNECT, $ctx
        );
        if ($sock === false) {
            throw new RuntimeException('TLS connection failed (' . $errno . '): ' . $errstr);
        }
        $this->socket = $sock;
        stream_set_timeout($sock, 12);
        $key = base64_encode(random_bytes(16));
        $tabId = 'cp_' . bin2hex(random_bytes(8));
        $request = "GET /socket.io/?EIO=4&transport=websocket&tab_id={$tabId} HTTP/1.1\r\n"
          . "Host: service.mizito.ir\r\n"
          . "Upgrade: websocket\r\nConnection: Upgrade\r\n"
          . "Sec-WebSocket-Key: {$key}\r\nSec-WebSocket-Version: 13\r\n"
          . "Origin: https://office.mizito.ir\r\n\r\n";
        $this->writeAll($request);
        $status = fgets($sock);
        if ($status === false || !preg_match('/^HTTP\/1\.[01] 101\b/', $status)) {
            throw new RuntimeException('WebSocket upgrade rejected: ' . trim((string)$status));
        }
        $headers = [];
        while (($line = fgets($sock)) !== false) {
            if (trim($line) === '') break;
            $pos = strpos($line, ':');
            if ($pos !== false) {
                $headers[strtolower(trim(substr($line, 0, $pos)))] = trim(substr($line, $pos + 1));
            }
        }
        $expected = base64_encode(sha1($key . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11', true));
        if (!hash_equals($expected, (string)($headers['sec-websocket-accept'] ?? ''))) {
            throw new RuntimeException('Invalid WebSocket upgrade response');
        }
        stream_set_timeout($sock, 3);
        $this->lastPacket = microtime(true);
        $this->log('TLS/WSS upgrade successful');
    }

    private function writeAll(string $bytes): void {
        while (strlen($bytes) > 0) {
            $n = @fwrite($this->socket, $bytes);
            if ($n === false || $n === 0) throw new RuntimeException('Socket write failed');
            $bytes = substr($bytes, $n);
        }
    }

    /** RFC 6455 frames written by clients MUST be masked. */
    private function sendFrame(int $opcode, string $payload = ''): void {
        $len = strlen($payload);
        $header = chr(0x80 | $opcode);
        if ($len < 126) $header .= chr(0x80 | $len);
        elseif ($len <= 65535) $header .= chr(0x80 | 126) . pack('n', $len);
        else $header .= chr(0x80 | 127) . pack('NN', 0, $len);
        $mask = random_bytes(4);
        for ($i = 0; $i < $len; $i++) $payload[$i] = $payload[$i] ^ $mask[$i % 4];
        $this->writeAll($header . $mask . $payload);
    }

    /** @return array{0:int,1:string}|null */
    private function takeFrame(): ?array {
        if (strlen($this->buffer) < 2) return null;
        $a = ord($this->buffer[0]); $b = ord($this->buffer[1]);
        if (($a & 0x70) !== 0) throw new RuntimeException('Unexpected WebSocket RSV bits');
        $opcode = $a & 15;
        $masked = ($b & 0x80) !== 0;
        $len = $b & 127; $offset = 2;
        if ($len === 126) {
            if (strlen($this->buffer) < 4) return null;
            $len = unpack('n', substr($this->buffer, 2, 2))[1]; $offset = 4;
        } elseif ($len === 127) {
            if (strlen($this->buffer) < 10) return null;
            $parts = unpack('N2', substr($this->buffer, 2, 8));
            if ($parts[1] !== 0) throw new RuntimeException('Oversized WebSocket frame');
            $len = $parts[2]; $offset = 10;
        }
        if ($len > 2 * 1024 * 1024) throw new RuntimeException('WebSocket frame too large');
        if ($masked && strlen($this->buffer) < $offset + 4) return null;
        $mask = $masked ? substr($this->buffer, $offset, 4) : '';
        if ($masked) $offset += 4;
        if (strlen($this->buffer) < $offset + $len) return null;
        $payload = substr($this->buffer, $offset, $len);
        $this->buffer = substr($this->buffer, $offset + $len);
        if ($masked) for ($i = 0; $i < $len; $i++) $payload[$i] = $payload[$i] ^ $mask[$i % 4];
        return [$opcode, $payload];
    }

    /** @return array{0:int,1:string}|null */
    private function readFrame(): ?array {
        $frame = $this->takeFrame();
        if ($frame !== null) return $frame;
        $read = [$this->socket]; $write = null; $except = null;
        $n = @stream_select($read, $write, $except, 1);
        if ($n === false) throw new RuntimeException('Socket select failed');
        if ($n === 0) return null;
        $data = @fread($this->socket, 16384);
        if ($data === false || ($data === '' && feof($this->socket))) {
            throw new RuntimeException('WebSocket connection closed');
        }
        $this->buffer .= $data;
        return $this->takeFrame();
    }

    /**
     * @param ?string $token null makes a connection-only probe and never authenticates.
     */
    public function runUntil(float $deadline, ?string $token): void {
        $opened = false;
        while (microtime(true) < $deadline) {
            $frame = $this->readFrame();
            if ($frame === null) {
                if (microtime(true) - $this->lastPacket > (($this->pingIntervalMs + $this->pingTimeoutMs) / 1000) + 10) {
                    throw new RuntimeException('Heartbeat timeout');
                }
                continue;
            }
            [$opcode, $data] = $frame;
            $this->lastPacket = microtime(true);
            if ($opcode === 8) throw new RuntimeException('Server closed WebSocket');
            if ($opcode === 9) { $this->sendFrame(10, $data); continue; }
            if ($opcode === 10) continue;
            if ($opcode !== 1) continue;

            if (str_starts_with($data, '0') && !$opened) {
                $handshake = json_decode(substr($data, 1), true);
                if (!is_array($handshake) || !isset($handshake['sid'])) {
                    throw new RuntimeException('Unexpected Engine.IO handshake');
                }
                $this->pingIntervalMs = (int)($handshake['pingInterval'] ?? 25000);
                $this->pingTimeoutMs = (int)($handshake['pingTimeout'] ?? 20000);
                $opened = true;
                $this->log('Engine.IO handshake received');
                if ($token === null) return; // probe stops before authentication
                $this->sendFrame(1, '40'); // Socket.IO namespace CONNECT
                continue;
            }
            if ($data === '2') { // Engine.IO server PING -> PONG
                $this->sendFrame(1, '3');
                continue;
            }
            if (str_starts_with($data, '40') && !$this->namespaceConnected) {
                $this->namespaceConnected = true;
                if ($token !== null) {
                    $msg = '42' . json_encode(['authenticate', ['token' => $token]], JSON_UNESCAPED_SLASHES);
                    $this->sendFrame(1, $msg);
                    $this->authSent = true;
                    $this->authSentAt = microtime(true);
                    $this->log('Socket.IO namespace connected; authentication sent (not yet verified)');
                }
                continue;
            }
            // Only inspect the event name. Do not store the message body or user data.
            if (str_starts_with($data, '42')) {
                $event = json_decode(substr($data, 2), true);
                if (is_array($event) && ($event[0] ?? null) === 'terminated') {
                    throw new RuntimeException('MIZITO_SESSION_TERMINATED');
                }
            }
            if (str_starts_with($data, '44')) {
                throw new RuntimeException('Socket.IO refused connection/authentication');
            }
            if ($data === '41' || $data === '1') {
                throw new RuntimeException('Socket.IO/Engine.IO disconnected');
            }
            // Do not log any user messages, notifications, IDs or tokens.
        }
        if ($token === null && !$opened) {
            throw new RuntimeException('No Engine.IO handshake received before timeout');
        }
    }

    /** An authenticate event was sent; the server has NOT necessarily accepted it. */
    public function wasAuthSent(): bool { return $this->authSent; }
    public function authAgeSeconds(): float {
        return $this->authSentAt > 0 ? microtime(true) - $this->authSentAt : 0.0;
    }

    public function close(): void {
        if (is_resource($this->socket)) @fclose($this->socket);
        $this->socket = null;
        $this->buffer = '';
        $this->namespaceConnected = false;
        $this->authSent = false;
        $this->authSentAt = 0.0;
    }
    public function __destruct() { $this->close(); }
}
