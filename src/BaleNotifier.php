<?php
declare(strict_types=1);

/** Sends a short message with Bale Bot API. Never logs the request URL or response body. */
final class BaleNotifier {
    private string $token;
    private string $chatId;
    private string $lastError = '';

    public function __construct(string $token, string $chatId) {
        $this->token = trim($token);
        $this->chatId = trim($chatId);
    }

    public function isConfigured(): bool {
        return (bool)preg_match('/^[A-Za-z0-9:_-]{12,256}$/', $this->token)
            && (bool)preg_match('/^(?:-?\d+|@[A-Za-z0-9_]{4,})$/', $this->chatId);
    }

    public function lastError(): string { return $this->lastError; }

    public function send(string $text): bool {
        $this->lastError = '';
        if (!$this->isConfigured()) {
            $this->lastError = 'Bale configuration is missing/invalid';
            return false;
        }
        $url = 'https://tapi.bale.ai/bot' . $this->token . '/sendMessage';
        $body = json_encode(['chat_id' => $this->chatId, 'text' => substr($text, 0, 3500)], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $status = 0;
        $response = false;

        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            if ($ch === false) { $this->lastError = 'cURL could not initialize'; return false; }
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $body,
                CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_TIMEOUT => 8,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
            ]);
            $response = curl_exec($ch);
            $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
        } else {
            $ctx = stream_context_create([
                'http' => [
                    'method' => 'POST', 'header' => "Content-Type: application/json\r\n",
                    'content' => $body, 'timeout' => 8, 'ignore_errors' => true,
                ],
                'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
            ]);
            $response = @file_get_contents($url, false, $ctx);
            $headers = $http_response_header ?? [];
            if (isset($headers[0]) && preg_match('~^HTTP/\S+\s+(\d{3})~', $headers[0], $m)) {
                $status = (int)$m[1];
            }
        }

        if ($status < 200 || $status >= 300 || $response === false) {
            $this->lastError = $status ? 'Bale HTTP status ' . $status : 'Bale connection failed';
            return false;
        }
        $json = json_decode((string)$response, true);
        if (!is_array($json) || ($json['ok'] ?? false) !== true) {
            $this->lastError = 'Bale API did not acknowledge message';
            return false;
        }
        return true;
    }
}
