<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}
date_default_timezone_set('Asia/Tehran');
require_once dirname(__DIR__) . '/src/BaleNotifier.php';

$root = dirname(__DIR__);
$runtime = $root . '/runtime';
if (!is_dir($runtime) && !mkdir($runtime, 0700, true) && !is_dir($runtime)) {
    exit("Cannot create runtime directory\n");
}
$done = $runtime . '/bale-test.done';
if (file_exists($done)) {
    exit("Bale test was already attempted. Remove runtime/bale-test.done to retry.\n");
}
// One shot: a forgotten temporary Cron won't spam the chat.
file_put_contents($done, date('c') . "\n", LOCK_EX);
@chmod($done, 0600);
$config = is_file($root . '/config.php') ? require $root . '/config.php' : [];
$config = is_array($config) ? $config : [];
$notifier = new BaleNotifier(
    (string)($config['bale_bot_token'] ?? ''),
    (string)($config['bale_chat_id'] ?? '')
);
$sent = $notifier->send("✅ تست هشدار میزیتو\nربات بله از هاست پاسخ داد.\n" . date('Y-m-d H:i:s') . ' (تهران)');
echo $sent ? "PASS: Bale accepted the test message\n" : "FAIL: " . $notifier->lastError() . "\n";
exit($sent ? 0 : 1);
