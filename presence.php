<?php
declare(strict_types=1);

/** Run once per minute with cPanel Cron; CLI only. */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

date_default_timezone_set('Asia/Tehran');
require_once __DIR__ . '/src/Schedule.php';
require_once __DIR__ . '/src/AlertState.php';
require_once __DIR__ . '/src/BaleNotifier.php';
require_once __DIR__ . '/src/MizitoSocket.php';

$runtime = __DIR__ . '/runtime';
if (!is_dir($runtime) && !@mkdir($runtime, 0700, true) && !is_dir($runtime)) {
    fwrite(STDERR, "Cannot create private runtime directory\n");
    exit(1);
}

$lock = @fopen($runtime . '/presence.lock', 'c');
if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
    // A previous run is still active; this is not an incident.
    exit(0);
}

$logFile = $runtime . '/presence.log';
$log = static function (string $event) use ($logFile): void {
    clearstatcache(true, $logFile);
    if (is_file($logFile) && filesize($logFile) > 512 * 1024) {
        @rename($logFile, $logFile . '.old');
    }
    @file_put_contents($logFile, date('c') . ' | ' . $event . "\n", FILE_APPEND | LOCK_EX);
};

$configPath = __DIR__ . '/config.php';
$config = is_file($configPath) ? require $configPath : [];
if (!is_array($config)) {
    $config = [];
}

$quietHours = $config['quiet_hours'] ?? [
    ['00:00', '07:00'], ['12:00', '12:30'],
];
$now = new DateTimeImmutable('now', new DateTimeZone('Asia/Tehran'));
try {
    $windowEnd = Schedule::activeUntil($now, $quietHours);
} catch (Throwable $error) {
    $log('Invalid quiet-hours configuration');
    exit(1);
}

// Not connected and no routine Bale alerts during quiet hours.
if ($windowEnd === null) {
    exit(0);
}

$seconds = max(15, min(55, (int)($config['run_seconds'] ?? 52)));
$deadline = min(microtime(true) + $seconds, (float)$windowEnd->getTimestamp() - 2);
if ($deadline - microtime(true) < 16) {
    exit(0); // Avoid starting just before quiet hours.
}

$log('Cron run started');
$token = trim((string)($config['token'] ?? ''));
$notifier = new BaleNotifier(
    (string)($config['bale_bot_token'] ?? ''),
    (string)($config['bale_chat_id'] ?? '')
);
$healthy = false;
$category = 'socket';
$details = 'connection_failed';

if ($token === '' || $token === 'PASTE_PRIVATE_TOKEN_HERE') {
    $category = 'config';
    $details = 'missing_mizito_token';
    $log('Configuration error: Mizito token missing');
} else {
    while (microtime(true) < $deadline - 2) {
        $client = new MizitoSocket($log);
        try {
            $client->connect();
            $client->runUntil($deadline - 1, $token);
            if (!$client->wasAuthSent() || $client->authAgeSeconds() < 12) {
                throw new RuntimeException('AUTH_EVENT_NOT_SENT_OR_TOO_SHORT');
            }
            // Transport is stable; acceptance of the token is NOT verifiable here.
            $healthy = true;
            $log('Connection stable; authentication event sent (NOT verified)');
            break;
        } catch (Throwable $error) {
            // Categorize known errors only. Do NOT write remote error messages or tokens.
            $message = $error->getMessage();
            if ($message === 'MIZITO_SESSION_TERMINATED' ||
                str_contains($message, 'refused connection/authentication')) {
                $category = 'auth';
                $details = 'server_rejected_or_terminated_session';
            } elseif ($category !== 'auth') {
                $category = 'socket';
                $details = $message === 'AUTH_EVENT_NOT_SENT_OR_TOO_SHORT'
                    ? 'connection_did_not_stabilize'
                    : 'websocket_disconnected_or_timeout';
            }
            $log('Connection issue: ' . $category . '/' . $details);
            if (microtime(true) >= $deadline - 17) {
                break;
            }
            sleep(2);
        } finally {
            $client->close();
        }
    }
}

$stateFile = $runtime . '/alert-state.json';
$stored = is_file($stateFile) ? json_decode((string)@file_get_contents($stateFile), true) : null;
if (!is_array($stored)) {
    $stored = AlertState::initial();
}
$nowTimestamp = time();
$threshold = max(1, min(10, (int)($config['alert_after_failures'] ?? 2)));
$repeatSec = 60 * max(5, min(1440, (int)($config['alert_repeat_minutes'] ?? 30)));
[$state, $action] = AlertState::decide(
    $stored, $healthy, $category, $nowTimestamp, $threshold, $repeatSec
);

if ($action !== null) {
    if ($action === 'recovered') {
        $message = "✅ ارتباط WebSocket میزیتو بازیابی شد\n" . date('Y-m-d H:i:s')
            . " (تهران)\nتأیید توکن و وضعیت سبز در حساب دیگر همچنان قابل اثبات نیست.";
    } else {
        $label = match ($category) {
            'auth' => 'احتمال رد شدن یا خاتمه نشست (انقضای توکن اثبات نشده)',
            'config' => 'توکن میزیتو در تنظیمات خالی است',
            default => 'اختلال در اتصال WebSocket میزیتو',
        };
        $message = "🚨 هشدار میزیتو\n" . $label . "\n"
            . 'تلاش‌های ناموفق متوالی: ' . (int)$state['failures'] . "\n"
            . 'زمان: ' . date('Y-m-d H:i:s') . " (تهران)\n"
            . 'این پیام اثبات‌کننده وضعیت آنلاین در حساب سایر اعضا نیست.';
    }
    if ($notifier->isConfigured()) {
        $sent = $notifier->send($message);
        $state = AlertState::complete($state, $action, $sent, $category, $nowTimestamp);
        $log($sent ? 'Bale notice sent: ' . $action : 'Bale send failed: ' . $notifier->lastError());
    } else {
        $state = AlertState::complete($state, $action, false, $category, $nowTimestamp);
        $log('Bale bot token or chat ID missing/invalid');
    }
}

$json = json_encode($state, JSON_UNESCAPED_SLASHES);
if ($json !== false) {
    $temp = $stateFile . '.tmp';
    if (@file_put_contents($temp, $json, LOCK_EX) !== false) {
        @chmod($temp, 0600);
        @rename($temp, $stateFile);
    }
}
$log($healthy ? 'Cron run finished: transport stable (auth unverified)' : 'Cron run finished: failed');
